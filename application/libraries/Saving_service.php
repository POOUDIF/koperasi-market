<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Logika bisnis simpanan syariah (§13).
 */
class Saving_service {

    private $CI;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->model(array('Saving_model', 'Deposit_request_model'));
    }

    /**
     * Padanan OpenMandatoryAccounts — dipanggil sekali saat registrasi.
     * Kegagalan di sini tidak boleh membatalkan registrasi (§11.1 langkah 8).
     */
    public function open_mandatory_accounts($user_id) {
        $opened = 0;
        foreach ($this->CI->Saving_model->get_mandatory_products() as $p) {
            $this->CI->Saving_model->create_account($user_id, (int) $p['id']);
            $opened++;
        }
        return $opened;
    }

    /** FLOW-08 Buka rekening (§13.1). */
    public function open_account($user_id, $product_id) {
        if ($this->CI->Saving_model->find_product($product_id) === NULL) {
            throw Api_exception::savingsProductNotFound();
        }
        return $this->CI->Saving_model->create_account($user_id, $product_id);
    }

    /**
     * FLOW-10 Ajukan setoran (§13.3).
     * Endpoint ini TIDAK menambah saldo — hanya membuat permohonan `pending`
     * yang menunggu verifikasi admin.
     */
    public function request_deposit($user_id, array $in) {
        $acc = $this->CI->Saving_model->get_account_by_id((int) $in['account_id']);

        if ($acc === NULL)                              throw Api_exception::savingsAccountNotFound();
        // 404, bukan 403 — jangan biarkan ID rekening orang lain dienumerasi.
        if ((int) $acc['user_id'] !== (int) $user_id)   throw Api_exception::savingsAccountNotFound();

        // Versi Go baru memeriksa status rekening saat approve, sehingga anggota
        // bisa mengajukan setoran ke rekening beku dan baru ditolak berhari-hari
        // kemudian. Lebih baik ditolak sekarang.
        if ($acc['status'] !== 'active')                throw Api_exception::accountNotActive();

        $product = $this->CI->Saving_model->find_product((int) $acc['savings_product_id']);
        if ($product === NULL)                          throw Api_exception::savingsProductNotFound();

        if (Money::lt($in['amount'], $product['min_deposit'])) {
            throw Api_exception::depositBelowMinimum($product['min_deposit']);
        }

        return $this->CI->Deposit_request_model->insert(array(
            'user_id'            => $user_id,
            'savings_account_id' => (int) $in['account_id'],
            'amount'             => $in['amount'],
            'payment_method'     => $in['payment_method'],
            'proof_image_url'    => $in['proof_image_url'],
            'reference_id'       => $in['reference_id'],
        ));
    }

    /** FLOW-11 Verifikasi setoran oleh admin (§13.4) — satu transaction. */
    public function review_deposit($admin_id, $request_id, $action) {
        return $this->CI->Deposit_request_model->review($admin_id, $request_id, $action);
    }
}
