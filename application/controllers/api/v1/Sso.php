<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once SHAREDPATH . 'core/Sso_bff_controller.php';

/**
 * Login koperasi lewat JDC Account (§3). Alur ada di Sso_bff_controller;
 * di sini hanya pemetaan klaim → tabel users koperasi.
 */
class Sso extends Sso_bff_controller {

    protected function provision_user(array $claims) {
        if (empty($claims['email_verified'])) {
            // IdP hanya menerbitkan sesi untuk email terverifikasi; ini jaring pengaman.
            throw new Api_exception('EMAIL_NOT_VERIFIED', 'email belum diverifikasi', 403);
        }
        $this->load->model('User_model');
        return $this->User_model->upsert_from_sso($claims);
    }
}
