<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Koperasi Pay — tagihan dari layanan lain (marketplace) yang dibayar dari
 * saldo simpanan anggota (§4.3).
 *
 * Aturan buku besar yang sama dengan modul lain berlaku mutlak:
 *   - saldo hanya berubah di dalam transaction yang memegang FOR UPDATE
 *   - validasi SETELAH baris terkunci
 *   - rekening dikunci berurutan menurut id (mencegah deadlock antar request)
 *   - perubahan status + event webhook (outbox) dalam satu transaction
 */
class Payment_model extends MY_Model {

    const COLS = 'id, public_id, client_id, idempotency_key, request_hash, merchant_ref, payer_user_id, payee_user_id,
                  amount, description, return_url, status, source_account_id, payee_account_id,
                  expires_at, held_at, settled_at, refunded_at, cancelled_at, created_at, updated_at';

    public function __construct() {
        parent::__construct();
        $this->load->model('Saving_model');
    }

    /* ---------------------------------------------------------- pembuatan */

    /**
     * Idempoten per (client_id, idempotency_key): permintaan ulang dengan isi
     * sama mengembalikan intent yang sama; isi berbeda → 409.
     * @return array [intent, created(bool)]
     */
    public function create(array $in) {
        $public_id = 'pi_' . bin2hex(random_bytes(12));

        $ok = $this->db->query(
            "INSERT INTO payment_intents
               (public_id, client_id, idempotency_key, request_hash, merchant_ref, payer_user_id, payee_user_id,
                amount, description, return_url, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)",
            array($public_id, $in['client_id'], $in['idempotency_key'], $in['request_hash'], $in['merchant_ref'],
                  (int) $in['payer_user_id'], (int) $in['payee_user_id'], $in['amount'], $in['description'],
                  $in['return_url'], (int) $in['ttl']));

        if ($ok === FALSE) {
            if ( ! $this->is_unique_violation()) {
                log_message('error', '[payment] insert gagal: ' . json_encode($this->db->error()));
                throw Api_exception::server();
            }
            $existing = $this->row("SELECT " . self::COLS . " FROM payment_intents WHERE client_id = ? AND idempotency_key = ?",
                array($in['client_id'], $in['idempotency_key']));
            if ($existing === NULL) { throw Api_exception::server(); }
            if ( ! hash_equals($existing['request_hash'], $in['request_hash'])) {
                throw Api_exception::idempotencyConflict();
            }
            return array($existing, FALSE);
        }

        return array($this->find_by_public_id($public_id), TRUE);
    }

    public function find_by_public_id($public_id) {
        $this->expire_stale();
        return $this->row("SELECT " . self::COLS . " FROM payment_intents WHERE public_id = ? LIMIT 1", array((string) $public_id));
    }

    /** Tandai intent yang lewat batas waktu konfirmasi. Tidak menyentuh uang. */
    public function expire_stale() {
        $rows = $this->q("SELECT id, client_id FROM payment_intents
                           WHERE status = 'requires_confirmation' AND expires_at <= NOW() LIMIT 100")->result_array();
        foreach ($rows as $r) {
            $this->atomic(function () use ($r) {
                $this->q("UPDATE payment_intents SET status = 'expired' WHERE id = ? AND status = 'requires_confirmation'", array($r['id']));
                if ($this->db->affected_rows() > 0) {
                    $this->enqueue_event((int) $r['id'], $r['client_id'], 'payment.expired');
                }
            });
        }
    }

    /* ------------------------------------------------------ transisi uang */

    /**
     * Anggota mengonfirmasi: debet rekening pembeli → kredit rekening penampung.
     */
    public function confirm($public_id, $payer_user_id, $account_id, $escrow_account_id) {
        $this->expire_stale();

        return $this->atomic(function () use ($public_id, $payer_user_id, $account_id, $escrow_account_id) {
            $pi = $this->lock_intent($public_id);
            if ($pi === NULL || (int) $pi['payer_user_id'] !== (int) $payer_user_id) {
                throw Api_exception::paymentNotFound();
            }
            if ($pi['status'] !== 'requires_confirmation') {
                throw Api_exception::paymentNotConfirmable();
            }
            if ((int) $account_id === (int) $escrow_account_id) {
                throw Api_exception::paymentAccountIneligible();
            }

            $locked = $this->lock_accounts_in_order(array((int) $account_id, (int) $escrow_account_id));
            $src = $locked[(int) $account_id];

            // Kepemilikan dicek setelah terkunci; rekening orang lain → 404 (§2.1 aturan 4).
            if ((int) $src['user_id'] !== (int) $payer_user_id) {
                throw Api_exception::savingsAccountNotFound();
            }
            if ( ! $this->is_spendable_product((int) $src['savings_product_id'])) {
                throw Api_exception::paymentAccountIneligible();
            }
            if (Money::lt($src['balance'], $pi['amount'])) {
                throw Api_exception::insufficientBalance();
            }

            $ref = 'market_pay_' . $pi['id'];
            $this->Saving_model->debit((int) $account_id, $pi['amount']);
            $this->Saving_model->ledger((int) $account_id, 'withdraw', $pi['amount'], $ref);
            $this->Saving_model->credit((int) $escrow_account_id, $pi['amount']);
            $this->Saving_model->ledger((int) $escrow_account_id, 'deposit', $pi['amount'], $ref);

            $this->q("UPDATE payment_intents SET status = 'held', source_account_id = ?, held_at = NOW() WHERE id = ?",
                array((int) $account_id, $pi['id']));
            $this->enqueue_event((int) $pi['id'], $pi['client_id'], 'payment.held');

            return (int) $pi['id'];
        });
    }

    /**
     * Pesanan selesai: rekening penampung → rekening Simpanan Sukarela penjual.
     * Idempoten: intent yang sudah 'settled' dikembalikan apa adanya.
     */
    public function settle($public_id, $client_id, $escrow_account_id, $payee_product_id) {
        return $this->atomic(function () use ($public_id, $client_id, $escrow_account_id, $payee_product_id) {
            $pi = $this->lock_intent($public_id);
            if ($pi === NULL || $pi['client_id'] !== $client_id) { throw Api_exception::paymentNotFound(); }
            if ($pi['status'] === 'settled') { return (int) $pi['id']; }
            if ($pi['status'] !== 'held')    { throw Api_exception::paymentInvalidTransition($pi['status'], 'settled'); }

            $payee_acc = $this->row(
                "SELECT id FROM savings_accounts WHERE user_id = ? AND savings_product_id = ? AND status = 'active'
                  ORDER BY id LIMIT 1", array((int) $pi['payee_user_id'], (int) $payee_product_id));
            if ($payee_acc === NULL) {
                // Penjual anggota aktif tapi belum punya Simpanan Sukarela: buka otomatis.
                $payee_acc = $this->Saving_model->create_account((int) $pi['payee_user_id'], (int) $payee_product_id);
            }
            $payee_account_id = (int) $payee_acc['id'];

            $this->lock_accounts_in_order(array((int) $escrow_account_id, $payee_account_id));

            $ref = 'market_settle_' . $pi['id'];
            $this->Saving_model->debit((int) $escrow_account_id, $pi['amount']);
            $this->Saving_model->ledger((int) $escrow_account_id, 'withdraw', $pi['amount'], $ref);
            $this->Saving_model->credit($payee_account_id, $pi['amount']);
            $this->Saving_model->ledger($payee_account_id, 'deposit', $pi['amount'], $ref);

            $this->q("UPDATE payment_intents SET status = 'settled', payee_account_id = ?, settled_at = NOW() WHERE id = ?",
                array($payee_account_id, $pi['id']));
            $this->enqueue_event((int) $pi['id'], $pi['client_id'], 'payment.settled');

            return (int) $pi['id'];
        });
    }

    /** Pesanan batal setelah dibayar: rekening penampung → rekening asal pembeli. */
    public function refund($public_id, $client_id, $escrow_account_id) {
        return $this->atomic(function () use ($public_id, $client_id, $escrow_account_id) {
            $pi = $this->lock_intent($public_id);
            if ($pi === NULL || $pi['client_id'] !== $client_id) { throw Api_exception::paymentNotFound(); }
            if ($pi['status'] === 'refunded') { return (int) $pi['id']; }
            if ($pi['status'] !== 'held')     { throw Api_exception::paymentInvalidTransition($pi['status'], 'refunded'); }

            $src = (int) $pi['source_account_id'];
            $this->lock_accounts_in_order(array((int) $escrow_account_id, $src));

            $ref = 'market_refund_' . $pi['id'];
            $this->Saving_model->debit((int) $escrow_account_id, $pi['amount']);
            $this->Saving_model->ledger((int) $escrow_account_id, 'withdraw', $pi['amount'], $ref);
            $this->Saving_model->credit($src, $pi['amount']);
            $this->Saving_model->ledger($src, 'deposit', $pi['amount'], $ref);

            $this->q("UPDATE payment_intents SET status = 'refunded', refunded_at = NOW() WHERE id = ?", array($pi['id']));
            $this->enqueue_event((int) $pi['id'], $pi['client_id'], 'payment.refunded');

            return (int) $pi['id'];
        });
    }

    /** Batalkan tagihan yang belum dibayar (oleh layanan asal atau oleh pembeli). */
    public function cancel($public_id, $client_id = NULL, $payer_user_id = NULL) {
        $this->expire_stale();

        return $this->atomic(function () use ($public_id, $client_id, $payer_user_id) {
            $pi = $this->lock_intent($public_id);
            if ($pi === NULL
                || ($client_id !== NULL && $pi['client_id'] !== $client_id)
                || ($payer_user_id !== NULL && (int) $pi['payer_user_id'] !== (int) $payer_user_id)) {
                throw Api_exception::paymentNotFound();
            }
            if (in_array($pi['status'], array('cancelled', 'expired'), TRUE)) { return (int) $pi['id']; }
            if ($pi['status'] !== 'requires_confirmation') {
                throw Api_exception::paymentInvalidTransition($pi['status'], 'cancelled');
            }

            $this->q("UPDATE payment_intents SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?", array($pi['id']));
            $this->enqueue_event((int) $pi['id'], $pi['client_id'], 'payment.cancelled');
            return (int) $pi['id'];
        });
    }

    /* ------------------------------------------------------------ bantuan */

    public function find_escrow_account_id($user_email, $product_name) {
        $r = $this->row(
            "SELECT a.id FROM savings_accounts a
               JOIN users u ON u.id = a.user_id
               JOIN savings_products p ON p.id = a.savings_product_id
              WHERE u.email = ? AND p.name = ? AND p.is_system = 1 LIMIT 1", array($user_email, $product_name));
        return $r ? (int) $r['id'] : NULL;
    }

    public function find_product_id_by_name($name) {
        $r = $this->row("SELECT id FROM savings_products WHERE name = ? AND is_system = 0 LIMIT 1", array($name));
        return $r ? (int) $r['id'] : NULL;
    }

    /** Rekening milik user yang boleh dipakai membayar: bukan simpanan wajib/pokok, bukan sistem. */
    public function spendable_accounts($user_id) {
        $rows = $this->q(
            "SELECT a.id, a.balance, a.status, p.name AS product_name
               FROM savings_accounts a
               JOIN savings_products p ON p.id = a.savings_product_id
              WHERE a.user_id = ? AND a.status = 'active' AND p.is_mandatory = 0 AND p.is_system = 0
              ORDER BY a.id", array((int) $user_id))->result_array();

        return array_map(function ($a) {
            return array('id' => (int) $a['id'], 'product_name' => $a['product_name'], 'balance' => Money::out($a['balance']));
        }, $rows);
    }

    public function escrow_held_total() {
        $r = $this->row("SELECT CAST(COALESCE(SUM(amount), 0) AS CHAR) AS total FROM payment_intents WHERE status = 'held'");
        return $r['total'];
    }

    public function to_public(array $pi) {
        return array(
            'id'           => $pi['public_id'],
            'merchant_ref' => $pi['merchant_ref'],
            'amount'       => Money::out($pi['amount']),
            'description'  => $pi['description'],
            'status'       => $pi['status'],
            'expires_at'   => $pi['expires_at'],
            'held_at'      => $pi['held_at'],
            'settled_at'   => $pi['settled_at'],
            'refunded_at'  => $pi['refunded_at'],
            'cancelled_at' => $pi['cancelled_at'],
            'created_at'   => $pi['created_at'],
        );
    }

    private function lock_intent($public_id) {
        return $this->row("SELECT " . self::COLS . " FROM payment_intents WHERE public_id = ? FOR UPDATE", array((string) $public_id));
    }

    /** @return array id => baris rekening terkunci */
    private function lock_accounts_in_order(array $ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        $out = array();
        foreach ($ids as $id) {
            $acc = $this->row(
                "SELECT id, user_id, savings_product_id, balance, status FROM savings_accounts WHERE id = ? FOR UPDATE",
                array($id));
            if ($acc === NULL)              { throw Api_exception::savingsAccountNotFound(); }
            if ($acc['status'] !== 'active') { throw Api_exception::accountNotActive(); }
            $out[$id] = $acc;
        }
        return $out;
    }

    private function is_spendable_product($product_id) {
        $p = $this->row("SELECT is_mandatory, is_system FROM savings_products WHERE id = ?", array($product_id));
        return $p !== NULL && (int) $p['is_mandatory'] === 0 && (int) $p['is_system'] === 0;
    }

    private function enqueue_event($intent_id, $client_id, $type) {
        $pi = $this->row("SELECT " . self::COLS . " FROM payment_intents WHERE id = ?", array($intent_id));
        $event_id = 'evt_' . bin2hex(random_bytes(12));

        $payload = json_encode(array(
            'id'      => $event_id,
            'type'    => $type,
            'created' => time(),
            'data'    => array('payment_intent' => $this->to_public($pi)),
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->q("INSERT INTO webhook_deliveries (event_id, intent_id, client_id, event_type, payload) VALUES (?, ?, ?, ?, ?)",
            array($event_id, $intent_id, $client_id, $type, $payload));
    }
}
