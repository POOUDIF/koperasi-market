<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Halaman konfirmasi Koperasi Pay dari sisi anggota (pembeli).
 */
class Payments extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('Payment_service');
    }

    /** GET /koperasi/api/v1/payments/:public_id */
    public function show($public_id) {
        $this->run(function () use ($public_id) {
            return $this->ok($this->payment_service->view_for_payer($public_id, $this->user_id), 200);
        });
    }

    /** POST /koperasi/api/v1/payments/:public_id/confirm  {account_id, pin} */
    public function confirm($public_id) {
        $this->run(function () use ($public_id) {
            $this->ratelimit->check('pay_confirm', 5, 10);
            $in = $this->validator->check($this->body, array(
                'account_id' => array('required', 'int_gt:0'),
                'pin'        => array('required', 'len:6', 'digits'),
            ));
            return $this->ok($this->payment_service->confirm($public_id, $this->user_id, $in['account_id'], $in['pin']), 200);
        });
    }

    /** POST /koperasi/api/v1/payments/:public_id/cancel */
    public function cancel($public_id) {
        $this->run(function () use ($public_id) {
            return $this->ok($this->payment_service->cancel_by_payer($public_id, $this->user_id), 200);
        });
    }
}
