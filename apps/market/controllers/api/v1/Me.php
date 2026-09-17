<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Me extends Customer_Controller {

    /** GET /market/api/v1/me — profil, status keanggotaan koperasi, toko, isi keranjang. */
    public function index() {
        $this->run(function () {
            $this->load->model(array('Store_model', 'Cart_model'));
            $this->load->library('Koperasi_client');

            try {
                $m = $this->koperasi_client->member($this->customer['sso_sub']);
                $membership = array(
                    'available'     => TRUE,
                    'is_member'     => ! empty($m['is_member']),
                    'kyc_completed' => ! empty($m['kyc_completed']),
                );
            } catch (Throwable $e) {
                $membership = array('available' => FALSE, 'is_member' => FALSE, 'kyc_completed' => FALSE);
            }

            return $this->ok(array(
                'id'         => (int) $this->customer['id'],
                'name'       => $this->customer['name'],
                'email'      => $this->customer['email'],
                'role'       => $this->customer['role'],
                'membership' => $membership,
                'store'      => $this->Store_model->find_by_owner($this->customer['id']),
                'cart_count' => $this->Cart_model->count($this->customer['id']),
            ), 200);
        });
    }
}
