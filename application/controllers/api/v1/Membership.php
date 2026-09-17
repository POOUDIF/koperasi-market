<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Aktivasi keanggotaan koperasi (§3.6).
 *
 * Akun JDC dibuat di IdP (bisa untuk belanja saja). Menjadi ANGGOTA koperasi
 * adalah langkah terpisah: KYC wajib lengkap, lalu rekening simpanan wajib
 * (pokok & wajib) dibuka — dulu ini terjadi otomatis saat registrasi.
 */
class Membership extends Auth_Controller {

    protected $allow_non_member = TRUE;

    public function __construct() {
        parent::__construct();
        $this->load->model(array('User_profile_model', 'Notification_model'));
        $this->load->library('Saving_service');
    }

    /** GET /koperasi/api/v1/membership */
    public function index() {
        $this->run(function () {
            $u = $this->User_model->find_by_id($this->user_id);
            return $this->ok(array(
                'is_member'     => $u['member_since'] !== NULL,
                'member_since'  => $u['member_since'],
                'kyc_completed' => $this->User_profile_model->find($this->user_id) !== NULL,
            ), 200);
        });
    }

    /** POST /koperasi/api/v1/membership/activate */
    public function activate() {
        $this->run(function () {
            if ($this->User_profile_model->find($this->user_id) === NULL) {
                throw Api_exception::kycRequired();
            }

            $opened = $this->User_model->activate_membership($this->user_id, function ($user_id) {
                return $this->saving_service->open_mandatory_accounts($user_id);
            });

            try {
                $this->Notification_model->insert($this->user_id, 'sistem', 'Selamat datang, Anggota!',
                    'Keanggotaan koperasi Anda aktif. Rekening Simpanan Pokok dan Simpanan Wajib telah dibuka.');
            } catch (Throwable $e) {
                log_message('error', '[membership] notifikasi gagal: ' . $e->getMessage());
            }

            $u = $this->User_model->find_by_id($this->user_id);
            return $this->ok(array(
                'message'          => 'keanggotaan koperasi aktif',
                'member_since'     => $u['member_since'],
                'accounts_opened'  => (int) $opened,
            ), 201);
        });
    }
}
