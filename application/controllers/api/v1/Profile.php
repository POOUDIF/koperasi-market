<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Profil pengguna & KYC (§11.5, §12).
 */
class Profile extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('User_profile_model');
    }

    /** GET /api/v1/profile */
    public function index() {
        $this->run(function () {
            $user = $this->User_model->find_by_id($this->user_id);
            if ($user === NULL) { throw Api_exception::userNotFound(); }

            return $this->ok($this->User_model->to_public($user), 200);
        });
    }

    /**
     * GET /api/v1/profile/kyc
     *
     * Bila profil belum pernah diisi: 200 dengan objek kosong, BUKAN 404.
     * Frontend memakai response ini untuk merender form kosong — perilaku ini
     * wajib dipertahankan (§12.2).
     */
    public function get_kyc() {
        $this->run(function () {
            $p = $this->User_profile_model->find($this->user_id);

            if ($p === NULL) {
                return $this->ok(array(
                    'user_id'                 => 0,
                    'nik'                     => '',
                    'phone_number'            => '',
                    'address'                 => '',
                    'job_title'               => '',
                    'monthly_income'          => 0,
                    'emergency_contact_name'  => '',
                    'emergency_contact_phone' => '',
                    'created_at'              => NULL,
                    'updated_at'              => NULL,
                ), 200);
            }

            return $this->ok($p, 200);
        });
    }

    /** PUT /api/v1/profile/kyc — upsert (§12.1). */
    public function update_kyc() {
        $this->run(function () {
            $in = $this->validator->check($this->body, array(
                'nik'                     => array('required', 'len:16', 'digits'),
                'phone_number'            => array('required', 'min:10', 'max:15'),
                'address'                 => array('required'),
                'job_title'               => array('required', 'max:100'),
                'monthly_income'          => array('required', 'num_gte:0'),
                'emergency_contact_name'  => array('required', 'max:150'),
                'emergency_contact_phone' => array('required', 'min:10', 'max:15'),
            ));

            $this->User_profile_model->upsert($this->user_id, $in);

            return $this->ok(array(
                'message' => 'profil KYC berhasil disimpan',
                'profile' => $this->User_profile_model->find($this->user_id),
            ), 200);
        });
    }
}
