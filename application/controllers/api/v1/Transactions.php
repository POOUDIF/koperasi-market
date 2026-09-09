<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Riwayat transaksi gabungan anggota — fitur baru di luar blueprint 24
 * endpoint. Lihat Transaction_model untuk alasan kenapa satu tabel
 * savings_transactions cukup untuk merangkum simpanan, cicilan pinjaman, dan
 * emas dalam satu daftar dengan saldo berjalan yang akurat.
 */
class Transactions extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Transaction_model');
    }

    /** GET /api/v1/transactions?jenis=semua|simpanan|pinjaman|emas&date=YYYY-MM-DD */
    public function index() {
        $this->run(function () {
            $pg    = $this->paging();
            $jenis = (string) ($this->input->get('jenis') ?: 'semua');
            $date  = (string) ($this->input->get('date') ?: '');

            if ( ! in_array($jenis, Transaction_model::VALID_JENIS, TRUE)) {
                throw Api_exception::badRequest("parameter 'jenis' tidak valid");
            }
            if ($date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw Api_exception::badRequest("parameter 'date' harus berformat YYYY-MM-DD");
            }

            return $this->ok(array(
                'transactions' => $this->Transaction_model->get_history_paged(
                    $this->user_id, $jenis, $date, $pg['per_page'], $pg['offset']),
                'page'     => $pg['page'],
                'per_page' => $pg['per_page'],
                'total'    => $this->Transaction_model->count_history($this->user_id, $jenis, $date),
            ), 200);
        });
    }
}
