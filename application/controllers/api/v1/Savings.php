<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Simpanan syariah — sisi anggota (§13.1 - §13.3, §13.5).
 */
class Savings extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('Saving_service');
        $this->load->model(array('Saving_model', 'Deposit_request_model', 'Withdraw_request_model'));
    }

    /** GET /api/v1/savings/products — katalog produk untuk form pembukaan rekening. */
    public function products() {
        $this->run(function () {
            return $this->ok(array('products' => $this->Saving_model->get_products()), 200);
        });
    }

    /** POST /api/v1/savings/accounts */
    public function open_account() {
        $this->run(function () {
            $in = $this->validator->check($this->body, array(
                'savings_product_id' => array('required', 'int_gt:0'),
            ));

            $account = $this->saving_service->open_account($this->user_id, $in['savings_product_id']);

            return $this->ok($account, 201);
        });
    }

    /** GET /api/v1/savings/accounts — array kosong, bukan null, bila belum ada. */
    public function accounts() {
        $this->run(function () {
            return $this->ok(array(
                'accounts' => $this->Saving_model->get_accounts_by_user($this->user_id),
            ), 200);
        });
    }

    /**
     * POST /api/v1/savings/deposit
     * Membuat permohonan `pending`; saldo TIDAK berubah di sini.
     */
    public function deposit() {
        $this->run(function () {
            $in = $this->validator->check($this->body, array(
                'account_id'      => array('required', 'int_gt:0'),
                'amount'          => array('required', 'num_gt:0'),
                'payment_method'  => array('required', 'max:50'),
                'proof_image_url' => array('max:255'),
                'reference_id'    => array('max:100'),
            ));

            $request = $this->saving_service->request_deposit($this->user_id, $in);

            return $this->ok($request, 201);
        });
    }

    /** GET /api/v1/savings/deposit-requests */
    public function deposit_requests() {
        $this->run(function () {
            $pg = $this->paging();

            return $this->ok(array(
                'deposit_requests' => $this->Deposit_request_model->get_by_user_paged(
                    $this->user_id, $pg['per_page'], $pg['offset']),
                'page'     => $pg['page'],
                'per_page' => $pg['per_page'],
                'total'    => $this->Deposit_request_model->count_by_user($this->user_id),
            ), 200);
        });
    }

    /**
     * POST /api/v1/savings/withdraw — perbaikan CACAT-12.
     * Membuat permohonan `pending`; saldo TIDAK berubah di sini, sama seperti
     * alur setoran.
     */
    public function withdraw() {
        $this->run(function () {
            $in = $this->validator->check($this->body, array(
                'account_id'          => array('required', 'int_gt:0'),
                'amount'              => array('required', 'num_gt:0'),
                'destination_account' => array('required', 'max:100'),
                'reference_id'        => array('max:100'),
            ));

            $request = $this->saving_service->request_withdraw($this->user_id, $in);

            return $this->ok($request, 201);
        });
    }

    /** GET /api/v1/savings/withdraw-requests */
    public function withdraw_requests() {
        $this->run(function () {
            $pg = $this->paging();

            return $this->ok(array(
                'withdraw_requests' => $this->Withdraw_request_model->get_by_user_paged(
                    $this->user_id, $pg['per_page'], $pg['offset']),
                'page'     => $pg['page'],
                'per_page' => $pg['per_page'],
                'total'    => $this->Withdraw_request_model->count_by_user($this->user_id),
            ), 200);
        });
    }
}
