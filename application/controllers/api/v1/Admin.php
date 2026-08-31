<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard & aksi admin (§13.4, §14.3, §17.1).
 *
 * Admin_Controller mewajibkan JWT + akun aktif + role pengurus/admin/
 * super_admin. Pengecekan status akun adalah perbaikan CACAT-03: di versi Go,
 * grup /admin melewatkan RequireActiveUserDB sehingga admin yang di-banned
 * masih bisa meng-approve selama tokennya belum kedaluwarsa.
 *
 * Semua endpoint daftar BERPAGINASI sejak awal (CACAT-09) — tanpa itu, satu
 * request pada 100.000 baris transaksi menarik seluruh tabel ke memori PHP.
 */
class Admin extends Admin_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('Saving_service', 'Financing_service', 'Gold_service'));
        $this->load->model(array('Saving_model', 'Deposit_request_model',
                                 'Financing_model', 'Gold_model'));
    }

    /* -------------------------------------------------------------- aksi */

    /** PUT /api/v1/admin/financing/:id/review */
    public function review_financing($id = NULL) {
        $this->run(function () use ($id) {
            $financing_id = $this->param_id($id, 'financing_id');

            $in = $this->validator->check($this->body, array(
                'action' => array('required', 'in:approve,reject'),
            ));

            $financing = $this->financing_service->review($this->user_id, $financing_id, $in['action']);

            return $this->ok(array(
                'message'      => 'review pembiayaan berhasil disimpan',
                'financing'    => $financing,
                'installments' => $this->Financing_model->get_installments($financing_id),
            ), 200);
        });
    }

    /** PUT /api/v1/admin/savings/deposit-requests/:id/review */
    public function review_deposit($id = NULL) {
        $this->run(function () use ($id) {
            $request_id = $this->param_id($id, 'request_id');

            $in = $this->validator->check($this->body, array(
                'action' => array('required', 'in:approve,reject'),
            ));

            $this->saving_service->review_deposit($this->user_id, $request_id, $in['action']);

            return $this->ok(array(
                'message'         => 'review setoran berhasil disimpan',
                'deposit_request' => $this->Deposit_request_model->find($request_id),
            ), 200);
        });
    }

    /**
     * POST /api/v1/admin/gold/price — perbaikan CACAT-08.
     * Versi Go hanya mengisi gold_prices lewat seed migrasi, dan cache Redis
     * 15 menit tidak pernah diinvalidasi.
     */
    public function set_gold_price() {
        $this->run(function () {
            $in = $this->validator->check($this->body, array(
                'buy_price_per_gram'  => array('required', 'num_gt:0'),
                'sell_price_per_gram' => array('required', 'num_gt:0'),
            ));

            // Harga jual anggota di bawah harga beli anggota — kalau terbalik,
            // anggota bisa beli lalu langsung jual dengan untung tanpa risiko.
            if (Money::gt($in['sell_price_per_gram'], $in['buy_price_per_gram'])) {
                throw Api_exception::badRequest(
                    'sell_price_per_gram tidak boleh lebih besar dari buy_price_per_gram');
            }

            $price = $this->gold_service->update_price(
                $in['buy_price_per_gram'], $in['sell_price_per_gram']);

            return $this->ok(array(
                'message' => 'harga emas berhasil diperbarui',
                'price'   => $price,
            ), 201);
        });
    }

    /* ------------------------------------------------------- read-only */

    /** GET /api/v1/admin/users */
    public function users() {
        $this->run(function () {
            $pg = $this->paging();

            // to_public() dipanggil di dalam model — tanpa itu, hash bcrypt
            // seluruh anggota terkirim ke frontend.
            return $this->ok(array(
                'users'    => $this->User_model->get_all_paged($pg['per_page'], $pg['offset']),
                'page'     => $pg['page'],
                'per_page' => $pg['per_page'],
                'total'    => $this->User_model->count_all(),
            ), 200);
        });
    }

    /** GET /api/v1/admin/savings/deposit-requests?status=pending */
    public function deposit_requests() {
        $this->run(function () {
            $pg     = $this->paging();
            $status = $this->input->get('status');

            if ($status !== NULL && $status !== ''
                && ! in_array($status, array('pending', 'approved', 'rejected'), TRUE)) {
                throw Api_exception::badRequest("parameter 'status' tidak valid");
            }

            return $this->ok(array(
                'deposit_requests' => $this->Deposit_request_model->get_all_paged(
                    $pg['per_page'], $pg['offset'], $status),
                'page'     => $pg['page'],
                'per_page' => $pg['per_page'],
                'total'    => $this->Deposit_request_model->count_all($status),
            ), 200);
        });
    }

    /** GET /api/v1/admin/transactions/financing */
    public function tx_financing() {
        $this->run(function () {
            $pg = $this->paging();

            return $this->ok(array(
                'financings' => $this->Financing_model->get_all_paged($pg['per_page'], $pg['offset']),
                'page'       => $pg['page'],
                'per_page'   => $pg['per_page'],
                'total'      => $this->Financing_model->count_all(),
            ), 200);
        });
    }

    /** GET /api/v1/admin/transactions/gold */
    public function tx_gold() {
        $this->run(function () {
            $pg = $this->paging();

            return $this->ok(array(
                'transactions' => $this->Gold_model->get_all_paged($pg['per_page'], $pg['offset']),
                'page'         => $pg['page'],
                'per_page'     => $pg['per_page'],
                'total'        => $this->Gold_model->count_all(),
            ), 200);
        });
    }

    /** GET /api/v1/admin/transactions/saving */
    public function tx_saving() {
        $this->run(function () {
            $pg = $this->paging();

            return $this->ok(array(
                'transactions' => $this->Saving_model->get_transactions_paged($pg['per_page'], $pg['offset']),
                'page'         => $pg['page'],
                'per_page'     => $pg['per_page'],
                'total'        => $this->Saving_model->count_transactions(),
            ), 200);
        });
    }
}
