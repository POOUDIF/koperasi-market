<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pembiayaan murabahah — sisi anggota (§14.1, §14.2, §14.4, §14.5).
 */
class Financing extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('Financing_service');
        $this->load->model('Financing_model');
    }

    /** POST /api/v1/financing/apply */
    public function apply() {
        $this->run(function () {
            $in = $this->validator->check($this->body, array(
                'principal_amount' => array('required', 'num_gt:0'),
                'duration_months'  => array('required', 'int_between:1,360'),
            ));

            $financing = $this->financing_service->apply_murabahah($this->user_id, $in);

            return $this->ok($financing, 201);
        });
    }

    /** GET /api/v1/financing */
    public function index() {
        $this->run(function () {
            $pg = $this->paging();

            return $this->ok(array(
                'financings' => $this->Financing_model->get_by_user_paged(
                    $this->user_id, $pg['per_page'], $pg['offset']),
                'page'     => $pg['page'],
                'per_page' => $pg['per_page'],
                'total'    => $this->Financing_model->count_by_user($this->user_id),
            ), 200);
        });
    }

    /** GET /api/v1/financing/:id/installments */
    public function installments($id = NULL) {
        $this->run(function () use ($id) {
            $financing_id = $this->param_id($id, 'financing_id');

            return $this->ok(array(
                'installments' => $this->financing_service->get_installments($this->user_id, $financing_id),
            ), 200);
        });
    }

    /** POST /api/v1/financing/installments/:id/pay */
    public function pay($id = NULL) {
        $this->run(function () use ($id) {
            $installment_id = $this->param_id($id, 'installment_id');

            $in = $this->validator->check($this->body, array(
                'savings_account_id' => array('required', 'int_gt:0'),
            ));

            $result = $this->financing_service->pay_installment(
                $this->user_id, $installment_id, $in['savings_account_id']);

            return $this->ok(array(
                'message'           => 'pembayaran cicilan berhasil',
                'remaining_unpaid'  => $result['remaining_unpaid'],
                'financing_settled' => $result['financing_settled'],
            ), 200);
        });
    }
}
