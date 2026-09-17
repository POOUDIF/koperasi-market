<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once SHAREDPATH . 'core/Sso_bff_controller.php';

/**
 * Login marketplace lewat JDC Account (§3). Pengguna yang sudah masuk di
 * layanan JDC lain tidak mengisi kata sandi lagi (§3.3).
 */
class Sso extends Sso_bff_controller {

    protected function provision_user(array $claims) {
        if (empty($claims['email_verified'])) {
            throw new Api_exception('EMAIL_NOT_VERIFIED', 'email belum diverifikasi', 403);
        }
        $this->load->model('Customer_model');
        return $this->Customer_model->upsert_from_sso($claims);
    }
}
