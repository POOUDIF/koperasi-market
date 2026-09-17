<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PIN transaksi — faktor kedua untuk memindahkan uang lewat Koperasi Pay (§8).
 */
class Security extends Auth_Controller {

    protected $allow_non_member = TRUE;

    public function __construct() {
        parent::__construct();
        $this->load->library('Payment_service');
    }

    /** GET /koperasi/api/v1/security/pin */
    public function pin_status() {
        $this->run(function () {
            $s = $this->User_model->get_pin_state($this->user_id);
            return $this->ok(array(
                'has_pin'      => $s['pin_hash'] !== NULL,
                'locked_until' => (int) $s['locked'] === 1 ? $s['pin_locked_until'] : NULL,
            ), 200);
        });
    }

    /**
     * PUT /koperasi/api/v1/security/pin  {pin}
     * Butuh login ulang ≤ 10 menit (step-up) — sesi yang dicuri tidak cukup
     * untuk mengganti PIN lalu menguras saldo.
     */
    public function set_pin() {
        $this->require_recent_auth();

        $this->run(function () {
            $this->ratelimit->check('pin_set', 5, 60);
            $in = $this->validator->check($this->body, array(
                'pin' => array('required', 'len:6', 'digits'),
            ));
            $this->payment_service->set_pin($this->user_id, $in['pin']);
            return $this->ok(array('message' => 'PIN transaksi berhasil disimpan'), 200);
        });
    }
}
