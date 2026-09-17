<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pesanan dari sisi pembeli.
 */
class Orders extends Customer_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('Order_service');
    }

    /** GET /market/api/v1/orders?status=&page= */
    public function index() {
        $this->run(function () {
            $pg = $this->paging();
            $status = $this->status_filter();
            $f = array('buyer_customer_id' => $this->customer['id']);

            return $this->ok(array(
                'orders'   => $this->Order_model->list_paged($f, $status, $pg['per_page'], $pg['offset']),
                'page'     => $pg['page'],
                'per_page' => $pg['per_page'],
                'total'    => $this->Order_model->count($f, $status),
            ), 200);
        });
    }

    /** GET /market/api/v1/orders/:id — sekaligus rekonsiliasi status pembayaran. */
    public function show($id) {
        $this->run(function () use ($id) {
            $o = $this->own_order($id);
            $this->order_service->sync_payment($o);
            return $this->ok($this->order_service->detail($o['id']), 200);
        });
    }

    /** POST /market/api/v1/orders/:id/pay → {payment_url} */
    public function pay($id) {
        $this->run(function () use ($id) {
            $this->ratelimit->check('pay', 5, 10);
            $o = $this->own_order($id);
            $this->order_service->assert_can_pay($this->customer['sso_sub'], $this->Store_model->owner_sub($o['store_id']));
            return $this->ok(array('payment_url' => $this->order_service->start_payment($o, $this->customer)), 200);
        });
    }

    /** POST /market/api/v1/orders/:id/cancel {reason?} */
    public function cancel($id) {
        $this->run(function () use ($id) {
            $o = $this->own_order($id);
            $reason = trim((string) ($this->body['reason'] ?? 'dibatalkan pembeli'));
            $this->order_service->cancel_by_buyer($o, $reason !== '' ? $reason : 'dibatalkan pembeli');
            return $this->ok($this->order_service->detail($o['id']), 200);
        });
    }

    /** POST /market/api/v1/orders/:id/complete — pesanan diterima → dana diteruskan ke penjual. */
    public function complete($id) {
        $this->run(function () use ($id) {
            $o = $this->own_order($id);
            $this->order_service->complete($o);
            return $this->ok($this->order_service->detail($o['id']), 200);
        });
    }

    private function own_order($id) {
        $o = $this->Order_model->find((int) $id);
        if ($o === NULL || (int) $o['buyer_customer_id'] !== (int) $this->customer['id']) {
            throw Api_exception::orderNotFound();
        }
        return $o;
    }

    private function status_filter() {
        $s = (string) $this->input->get('status');
        return in_array($s, array('pending_payment', 'paid', 'shipped', 'completed', 'cancelled'), TRUE) ? $s : '';
    }
}
