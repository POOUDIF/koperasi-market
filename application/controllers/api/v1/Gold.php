<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Emas digital (§15).
 *
 * Basisnya API_Controller karena /gold/price bersifat publik; buy, sell, dan
 * holding memasang guard-nya sendiri lewat require_member().
 */
class Gold extends API_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('Gold_service');
    }

    /** GET /api/v1/gold/price — publik, tanpa JWT, tanpa rate limit. */
    public function price() {
        $this->run(function () {
            return $this->ok($this->gold_service->get_current_price(), 200);
        });
    }

    /** POST /api/v1/gold/buy */
    public function buy() {
        $this->require_member();

        $this->run(function () {
            $in = $this->validator->check($this->body, array(
                'gram_amount'        => array('required', 'num_gte:0.0001'),
                'savings_account_id' => array('required', 'int_gt:0'),
            ));

            return $this->ok($this->gold_service->buy($this->user_id, $in), 201);
        });
    }

    /** POST /api/v1/gold/sell */
    public function sell() {
        $this->require_member();

        $this->run(function () {
            $in = $this->validator->check($this->body, array(
                'gram_amount'        => array('required', 'num_gte:0.0001'),
                'savings_account_id' => array('required', 'int_gt:0'),
            ));

            return $this->ok($this->gold_service->sell($this->user_id, $in), 201);
        });
    }

    /** GET /api/v1/gold/holding — kepemilikan emas bersih anggota. */
    public function holding() {
        $this->require_member();

        $this->run(function () {
            $this->load->model('Gold_model');
            $pg = $this->paging();

            return $this->ok(array(
                'net_gram'     => (float) $this->gold_service->holding($this->user_id),
                'transactions' => $this->Gold_model->get_by_user_paged(
                    $this->user_id, $pg['per_page'], $pg['offset']),
                'page'     => $pg['page'],
                'per_page' => $pg['per_page'],
            ), 200);
        });
    }
}
