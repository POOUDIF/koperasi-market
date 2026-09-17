<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Siklus hidup pesanan & integrasinya dengan Koperasi Pay (§4.3).
 *
 *   pending_payment ──(payment.held)──▶ paid ──ship──▶ shipped ──complete──▶ completed (+settle)
 *         │                              │
 *         └────────── cancel ────────────┴──▶ cancelled (+refund bila sudah dibayar)
 *
 * Konsistensi lintas layanan: status lokal ditulis DULU (outbox kecil di
 * kolom payout_*), baru API koperasi dipanggil. Gagal jaringan → tetap
 * 'pending' dan diulang cli/orders maintenance. Koperasi idempoten untuk
 * settle/refund, jadi pengulangan aman.
 */
class Order_service {

    const MAX_PAYOUT_ERRORS = 20;

    private $CI;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->model(array('Order_model', 'Store_model', 'Customer_model'));
        $this->CI->load->library('Koperasi_client');
    }

    /* ------------------------------------------------------------ checkout */

    public function checkout(array $buyer, $store_id, array $shipping) {
        $store = $this->CI->Store_model->find($store_id);
        if ($store === NULL)                                        { throw Api_exception::storeNotFound(); }
        if ($store['status'] !== 'active')                          { throw Api_exception::storeSuspended(); }
        if ((int) $store['owner_customer_id'] === (int) $buyer['id']) { throw Api_exception::ownProduct(); }

        $this->assert_can_pay($buyer['sso_sub'], $this->CI->Store_model->owner_sub($store_id));

        $order_id = $this->CI->Order_model->atomic_public(function (Order_model $m) use ($buyer, $store_id, $shipping) {
            return $m->create_from_cart($buyer['id'], $store_id, $shipping);
        });

        $order = $this->CI->Order_model->find($order_id);
        try {
            $payment_url = $this->start_payment($order, $buyer);
        } catch (Api_exception $e) {
            // Pesanan & stok tetap tersimpan; pembeli bisa menekan "Bayar" lagi.
            log_message('error', "[checkout] order {$order_id} dibuat tapi tagihan gagal: " . $e->getMessage());
            $payment_url = NULL;
        }

        return array('order' => $this->detail($order_id), 'payment_url' => $payment_url);
    }

    /**
     * Buat (atau pakai ulang) tagihan Koperasi Pay untuk pesanan.
     * Idempotency-Key per percobaan: klik ganda menghasilkan tagihan yang sama.
     * @return string URL halaman konfirmasi di koperasi
     */
    public function start_payment(array $order, array $buyer) {
        if ($order['status'] !== 'pending_payment') {
            throw Api_exception::orderInvalidState('dibayar');
        }

        if ($order['payment_intent_id'] !== NULL) {
            try {
                $pi = $this->CI->koperasi_client->get_intent($order['payment_intent_id']);
                if ($pi['status'] === 'requires_confirmation') { return $pi['payment_url']; }
                if ($pi['status'] === 'held') {
                    $this->CI->Order_model->mark_paid_by_intent($pi['id']);
                    throw Api_exception::orderInvalidState('dibayar ulang');
                }
            } catch (Koperasi_error $e) {
                log_message('error', "[payment] intent lama {$order['payment_intent_id']} tidak terbaca: " . $e->getMessage());
            }
        }

        $attempt = (int) $order['payment_attempt'] + 1;
        $store   = $this->CI->Store_model->find($order['store_id']);

        try {
            $pi = $this->CI->koperasi_client->create_intent(array(
                'merchant_ref' => $order['order_number'],
                'payer_sub'    => (string) $buyer['sso_sub'],
                'payee_sub'    => (string) $this->CI->Store_model->owner_sub($order['store_id']),
                'amount'       => Money::norm($order['total']),
                'description'  => 'Pesanan ' . $order['order_number'] . ' — ' . $store['name'],
                'return_url'   => $this->CI->config->item('app_url') . '/market/orders/' . $order['id'],
            ), 'order-' . $order['id'] . '-attempt-' . $attempt);
        } catch (Koperasi_error $e) {
            if ($e->code_name === 'PAYER_NOT_MEMBER') { throw Api_exception::buyerNotMember(); }
            if ($e->code_name === 'PAYEE_NOT_MEMBER') { throw Api_exception::sellerUnavailable(); }
            log_message('error', '[payment] tagihan ditolak koperasi: ' . $e->code_name . ' ' . $e->getMessage());
            throw Api_exception::paymentUnavailable();
        }

        $this->CI->Order_model->set_payment_intent($order['id'], $pi['id'], $attempt);
        return $pi['payment_url'];
    }

    /** Rekonsiliasi saat halaman pesanan dibuka — menutup celah webhook yang hilang. */
    public function sync_payment(array $order) {
        if ($order['status'] !== 'pending_payment' || $order['payment_intent_id'] === NULL) { return; }
        try {
            $this->apply_intent_status($this->CI->koperasi_client->get_intent($order['payment_intent_id']));
        } catch (Throwable $e) {
            log_message('error', '[payment] sinkron status gagal: ' . $e->getMessage());
        }
    }

    /* ---------------------------------------------------- aksi pembeli/penjual */

    public function cancel_by_buyer(array $order, $reason) {
        if ( ! in_array($order['status'], array('pending_payment', 'paid'), TRUE)) {
            throw Api_exception::orderInvalidState('dibatalkan');
        }
        $this->release_unpaid_intent($order);
        if ( ! $this->CI->Order_model->cancel($order['id'], array('pending_payment', 'paid'), 'buyer', $reason)) {
            throw Api_exception::orderInvalidState('dibatalkan');
        }
        $this->process_payout($this->CI->Order_model->find($order['id']));
    }

    public function cancel_by_seller(array $order, $reason, $by = 'seller') {
        if ( ! in_array($order['status'], array('pending_payment', 'paid'), TRUE)) {
            throw Api_exception::orderInvalidState('dibatalkan');
        }
        $this->release_unpaid_intent($order);
        if ( ! $this->CI->Order_model->cancel($order['id'], array('pending_payment', 'paid'), $by, $reason)) {
            throw Api_exception::orderInvalidState('dibatalkan');
        }
        $this->process_payout($this->CI->Order_model->find($order['id']));
    }

    public function complete(array $order) {
        if ( ! $this->CI->Order_model->mark_completed($order['id'])) {
            throw Api_exception::orderInvalidState('diselesaikan');
        }
        $this->process_payout($this->CI->Order_model->find($order['id']));
    }

    /** Eksekusi settle/refund yang tercatat 'pending'. Tidak melempar. */
    public function process_payout(array $order) {
        if ($order['payout_status'] !== 'pending' || $order['payment_intent_id'] === NULL) { return; }

        try {
            if ($order['payout_action'] === 'settle') {
                $this->CI->koperasi_client->settle($order['payment_intent_id']);
            } else {
                $this->CI->koperasi_client->refund($order['payment_intent_id']);
            }
            $this->CI->Order_model->payout_done($order['id']);
        } catch (Koperasi_error $e) {
            // 409 bisa berarti sudah dieksekusi sebelumnya → cek status sebenarnya.
            try {
                $pi = $this->CI->koperasi_client->get_intent($order['payment_intent_id']);
                $target = $order['payout_action'] === 'settle' ? 'settled' : 'refunded';
                if ($pi['status'] === $target) {
                    $this->CI->Order_model->payout_done($order['id']);
                    return;
                }
            } catch (Throwable $ignored) {}
            log_message('error', "[payout] order {$order['id']} ditolak koperasi: {$e->code_name}");
            $this->CI->Order_model->payout_error($order['id'], $e->code_name . ': ' . $e->getMessage(), TRUE);
        } catch (Throwable $e) {
            log_message('error', "[payout] order {$order['id']} tertunda: " . $e->getMessage());
            $this->CI->Order_model->payout_error($order['id'], $e->getMessage(), FALSE);
        }
    }

    /* ------------------------------------------------------------- webhook */

    public function handle_webhook(array $event) {
        $intent = $event['data']['payment_intent'] ?? NULL;
        if ( ! is_array($intent) || empty($intent['id'])) { return; }
        $this->apply_intent_status($intent);
    }

    private function apply_intent_status(array $pi) {
        switch ($pi['status']) {
            case 'held':
                if ( ! $this->CI->Order_model->mark_paid_by_intent($pi['id'])
                    && $this->CI->Order_model->schedule_refund_if_cancelled($pi['id'])) {
                    // Pesanan sudah dibatalkan duluan tapi pembayaran tetap masuk → kembalikan.
                    $this->process_payout($this->CI->Order_model->find_by_intent($pi['id']));
                }
                break;
            case 'cancelled':
            case 'expired':
                $o = $this->CI->Order_model->find_by_intent($pi['id']);
                if ($o !== NULL) { $this->CI->Order_model->clear_payment_intent($o['id'], $pi['id']); }
                break;
        }
    }

    /* ---------------------------------------------------------- pemeliharaan */

    /** @return array ringkasan */
    public function maintenance() {
        $stat = array('synced' => 0, 'expired' => 0, 'auto_completed' => 0, 'payouts' => 0);

        foreach ($this->CI->Order_model->pending_with_intent() as $o) {
            $this->sync_payment($o);
            $stat['synced']++;
        }
        foreach ($this->CI->Order_model->stale_unpaid($this->CI->config->item('unpaid_order_ttl_hours')) as $o) {
            try {
                $this->cancel_by_seller($o, 'tidak dibayar dalam ' . $this->CI->config->item('unpaid_order_ttl_hours') . ' jam', 'system');
                $stat['expired']++;
            } catch (Throwable $e) {
                log_message('error', "[maintenance] batal otomatis order {$o['id']} gagal: " . $e->getMessage());
            }
        }
        foreach ($this->CI->Order_model->overdue_shipped($this->CI->config->item('auto_complete_after_days')) as $o) {
            try {
                $this->complete($o);
                $stat['auto_completed']++;
            } catch (Throwable $e) {
                log_message('error', "[maintenance] selesai otomatis order {$o['id']} gagal: " . $e->getMessage());
            }
        }
        foreach ($this->CI->Order_model->pending_payouts() as $o) {
            $this->process_payout($o);
            $stat['payouts']++;
        }
        return $stat;
    }

    /* --------------------------------------------------------------- bantuan */

    public function detail($order_id) {
        $o = $this->CI->Order_model->shape($this->CI->Order_model->find($order_id));
        $store = $this->CI->Store_model->find($o['store_id']);
        $o['store'] = array('id' => $store['id'], 'name' => $store['name'], 'slug' => $store['slug']);
        $o['items'] = $this->CI->Order_model->items($order_id);
        return $o;
    }

    public function assert_can_pay($buyer_sub, $seller_sub) {
        try {
            $buyer = $this->CI->koperasi_client->member($buyer_sub, TRUE);
        } catch (Koperasi_error $e) {
            throw Api_exception::paymentUnavailable();
        }
        if (empty($buyer['is_member'])) { throw Api_exception::buyerNotMember(); }

        try {
            $seller = $this->CI->koperasi_client->member($seller_sub);
        } catch (Koperasi_error $e) {
            throw Api_exception::paymentUnavailable();
        }
        if (empty($seller['is_member'])) { throw Api_exception::sellerUnavailable(); }
    }

    /**
     * Sebelum membatalkan pesanan yang belum dibayar, batalkan tagihannya di
     * koperasi. Bila ternyata SUDAH dibayar (balapan), tandai paid dulu agar
     * pembatalan menjadwalkan refund.
     */
    private function release_unpaid_intent(array $order) {
        if ($order['status'] !== 'pending_payment' || $order['payment_intent_id'] === NULL) { return; }

        try {
            $this->CI->koperasi_client->cancel_intent($order['payment_intent_id']);
        } catch (Koperasi_error $e) {
            if ($e->code_name === 'PAYMENT_INVALID_TRANSITION') {
                $this->CI->Order_model->mark_paid_by_intent($order['payment_intent_id']);
            }
        }
    }
}
