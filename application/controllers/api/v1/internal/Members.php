<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * GET /koperasi/api/v1/internal/members/:sub  (scope koperasi.members.read)
 *
 * Status keanggotaan untuk layanan lain — harga anggota di marketplace,
 * syarat membuka toko, ketersediaan metode Koperasi Pay. Sengaja minim data:
 * tidak ada NIK, saldo, atau data KYC mentah.
 */
class Members extends Internal_Controller {

    public function show($sub) {
        $this->require_scope('koperasi.members.read');

        $this->run(function () use ($sub) {
            if ( ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $sub)) {
                throw Api_exception::badRequest('sub tidak valid');
            }
            $this->load->library('Payment_service');
            return $this->ok($this->payment_service->member_summary($sub), 200);
        });
    }
}
