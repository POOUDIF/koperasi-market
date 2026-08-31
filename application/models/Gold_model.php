<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Repository emas digital (§15, §16.3).
 */
class Gold_model extends MY_Model {

    const COLS = 'id, user_id, type, gram_amount, price_per_gram, total_rupiah,
                  tx_hash, status, created_at, updated_at';

    /* -------------------------------------------------------------- harga */

    /** Baris terbaru ditentukan updated_at, BUKAN id (§3.2). */
    public function latest_price() {
        $p = $this->row(
            "SELECT id, buy_price_per_gram, sell_price_per_gram, updated_at
               FROM gold_prices ORDER BY updated_at DESC, id DESC LIMIT 1");

        if ($p === NULL) { return NULL; }

        return array(
            'id'                  => (int) $p['id'],
            'buy_price_per_gram'  => Money::out($p['buy_price_per_gram']),
            'sell_price_per_gram' => Money::out($p['sell_price_per_gram']),
            'updated_at'          => $p['updated_at'],
        );
    }

    /** Perbaikan CACAT-08: admin bisa memperbarui harga tanpa INSERT manual di DB. */
    public function insert_price($buy, $sell) {
        $ok = $this->db->query(
            "INSERT INTO gold_prices (buy_price_per_gram, sell_price_per_gram, updated_at)
             VALUES (?, ?, NOW())", array($buy, $sell));

        if ($ok === FALSE) {
            log_message('error', '[gold_model] insert_price gagal: ' . json_encode($this->db->error()));
            throw Api_exception::server();
        }
        return $this->latest_price();
    }

    /* --------------------------------------------------------- transaksi */

    public function find_by_id($id) {
        $t = $this->row("SELECT " . self::COLS . " FROM gold_transactions WHERE id = ? LIMIT 1", array($id));
        return $t === NULL ? NULL : $this->shape($t);
    }

    public function find_by_status($status) {
        return $this->q(
            "SELECT id, user_id, gram_amount, tx_hash, status FROM gold_transactions
              WHERE status = ? ORDER BY created_at ASC", array($status))->result_array();
    }

    public function find_processing_with_hash() {
        return $this->q(
            "SELECT id, user_id, gram_amount, tx_hash FROM gold_transactions
              WHERE status = 'processing' AND tx_hash IS NOT NULL ORDER BY created_at ASC")->result_array();
    }

    public function get_by_user_paged($user_id, $limit, $offset) {
        $rows = $this->q(
            "SELECT " . self::COLS . " FROM gold_transactions
              WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            array($user_id, (int) $limit, (int) $offset))->result_array();

        return array_map(array($this, 'shape'), $rows);
    }

    public function get_all_paged($limit, $offset) {
        $rows = $this->q(
            "SELECT " . self::COLS . " FROM gold_transactions
              ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            array((int) $limit, (int) $offset))->result_array();

        return array_map(array($this, 'shape'), $rows);
    }

    public function count_all() {
        $r = $this->row("SELECT COUNT(*) AS c FROM gold_transactions");
        return (int) $r['c'];
    }

    public function update_status($id, $status) {
        $this->q("UPDATE gold_transactions SET status = ?, updated_at = NOW() WHERE id = ?", array($status, $id));
    }

    public function update_status_and_hash($id, $status, $hash) {
        $this->q("UPDATE gold_transactions SET status = ?, tx_hash = ?, updated_at = NOW() WHERE id = ?",
            array($status, $hash, $id));
    }

    /**
     * Kepemilikan emas bersih = gram beli sukses - gram jual sukses.
     *
     * Transaksi `buy` berstatus pending/processing SENGAJA tidak dihitung:
     * emasnya belum benar-benar ada sampai mint on-chain berhasil, dan
     * transaksi itu masih bisa berakhir refund.
     *
     * @param bool $for_update kunci baris-baris terkait bila dipanggil di
     *                         dalam transaction penjualan.
     */
    public function net_holding($user_id, $for_update = FALSE) {
        $sql = "SELECT COALESCE(SUM(CASE WHEN type = 'buy'  AND status = 'success' THEN gram_amount ELSE 0 END), 0)
                     - COALESCE(SUM(CASE WHEN type = 'sell' AND status = 'success' THEN gram_amount ELSE 0 END), 0)
                       AS net_gram
                  FROM gold_transactions
                 WHERE user_id = ? AND status = 'success'";

        if ($for_update) { $sql .= ' FOR UPDATE'; }

        $r = $this->row($sql, array($user_id));
        return Money::norm($r === NULL ? '0' : $r['net_gram']);
    }

    /* ---------------------------------------------------- beli / jual */

    /**
     * Beli emas: debit saldo + catat transaksi emas + catat ledger — atomik.
     *
     * reference_id ledger WAJIB berformat 'gold_buy_{id}': proses refund
     * memakainya untuk menemukan rekening mana yang harus dikembalikan
     * uangnya. Mengubah format ini akan merusak refund (§3.2).
     */
    public function buy_with_debit($user_id, $account_id, $gram, $price_per_gram, $total) {
        return $this->atomic(function () use ($user_id, $account_id, $gram, $price_per_gram, $total) {

            $this->load->model('Saving_model');

            $acc = $this->Saving_model->lock_account($account_id, $user_id);
            if (Money::lt($acc['balance'], $total)) {
                throw Api_exception::insufficientBalance();
            }

            $this->Saving_model->debit($account_id, $total);

            $ok = $this->db->query(
                "INSERT INTO gold_transactions
                   (user_id, type, gram_amount, price_per_gram, total_rupiah, status)
                 VALUES (?, 'buy', ?, ?, ?, 'pending')",
                array($user_id, $gram, $price_per_gram, $total));

            if ($ok === FALSE) {
                log_message('error', '[gold_model] insert buy gagal: ' . json_encode($this->db->error()));
                throw Api_exception::server();
            }
            $gold_tx_id = (int) $this->db->insert_id();

            $this->Saving_model->ledger($account_id, 'withdraw', $total, 'gold_buy_' . $gold_tx_id);

            return $gold_tx_id;
        });
    }

    /**
     * Jual emas: kredit saldo + catat transaksi (langsung `success`,
     * murni off-chain).
     *
     * Perbaikan CACAT-01 (KRITIS): versi Go hanya memvalidasi rekening
     * simpanan dan TIDAK pernah memeriksa apakah anggota benar-benar punya
     * emasnya — anggota baru bersaldo 0 bisa "menjual" 100 gram dan
     * saldonya bertambah ratusan juta. Di sini kepemilikan dihitung di dalam
     * transaction yang sama, setelah baris rekening terkunci.
     */
    public function sell_with_credit($user_id, $account_id, $gram, $price_per_gram, $total) {
        return $this->atomic(function () use ($user_id, $account_id, $gram, $price_per_gram, $total) {

            $this->load->model('Saving_model');
            $this->Saving_model->lock_account($account_id, $user_id);

            $holding = $this->net_holding($user_id, TRUE);
            if (Money::lt($holding, $gram)) {
                throw Api_exception::goldInsufficientHolding();
            }

            $ok = $this->db->query(
                "INSERT INTO gold_transactions
                   (user_id, type, gram_amount, price_per_gram, total_rupiah, status)
                 VALUES (?, 'sell', ?, ?, ?, 'success')",
                array($user_id, $gram, $price_per_gram, $total));

            if ($ok === FALSE) {
                log_message('error', '[gold_model] insert sell gagal: ' . json_encode($this->db->error()));
                throw Api_exception::server();
            }
            $gold_tx_id = (int) $this->db->insert_id();

            $this->Saving_model->credit($account_id, $total);
            $this->Saving_model->ledger($account_id, 'deposit', $total, 'gold_sell_' . $gold_tx_id);

            return $gold_tx_id;
        });
    }

    /**
     * Kembalikan uang anggota saat pembelian emas gagal (§16.3).
     *
     * Idempoten: hanya transaksi berstatus pending/processing yang di-refund,
     * sehingga aman dipanggil dua kali oleh worker maupun proses recovery.
     */
    public function refund_failed_transaction($gold_tx_id) {
        return $this->atomic(function () use ($gold_tx_id) {

            $g = $this->row(
                "SELECT user_id, total_rupiah, status FROM gold_transactions WHERE id = ? FOR UPDATE",
                array($gold_tx_id));

            if ($g === NULL) {
                throw new RuntimeException("transaksi emas ID={$gold_tx_id} tidak ditemukan");
            }

            if ( ! in_array($g['status'], array('pending', 'processing'), TRUE)) {
                log_message('info', "[refund] ID={$gold_tx_id} sudah final ({$g['status']}), dilewati");
                return FALSE;
            }

            // Inilah gunanya konvensi reference_id: menemukan rekening asal debit.
            $ref = 'gold_buy_' . $gold_tx_id;
            $log = $this->row(
                "SELECT savings_account_id FROM savings_transactions
                  WHERE reference_id = ? ORDER BY id ASC LIMIT 1", array($ref));

            if ($log === NULL) {
                throw new RuntimeException("ledger untuk {$ref} tidak ditemukan");
            }
            $account_id = (int) $log['savings_account_id'];

            $this->load->model('Saving_model');
            $this->Saving_model->credit($account_id, $g['total_rupiah']);
            $this->Saving_model->ledger($account_id, 'deposit', $g['total_rupiah'], 'gold_refund_' . $gold_tx_id);

            $this->q("UPDATE gold_transactions SET status = 'failed', updated_at = NOW() WHERE id = ?",
                array($gold_tx_id));

            log_message('info', "[refund] berhasil ID={$gold_tx_id} akun={$account_id} nominal={$g['total_rupiah']}");
            return TRUE;
        });
    }

    private function shape(array $t) {
        $t['id']             = (int) $t['id'];
        $t['user_id']        = (int) $t['user_id'];
        $t['gram_amount']    = (float) $t['gram_amount'];
        $t['price_per_gram'] = Money::out($t['price_per_gram']);
        $t['total_rupiah']   = Money::out($t['total_rupiah']);
        return $t;
    }
}
