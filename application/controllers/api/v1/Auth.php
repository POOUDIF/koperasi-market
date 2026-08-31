<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Registrasi, verifikasi email, login (§11.1 - §11.3).
 * Basis API_Controller: tanpa JWT, tapi ber-rate-limit.
 */
class Auth extends API_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('User_service', 'Saving_service'));
    }

    /** POST /api/v1/register */
    public function register() {
        $this->run(function () {
            $this->ratelimit->check('auth');

            $in = $this->validator->check($this->body, array(
                'nama_lengkap' => array('required', 'min:3', 'max:150'),
                'email'        => array('required', 'email', 'max:255'),
                'password'     => array('required', 'min:8'),
            ));

            $user = $this->user_service->register($in);

            // Rekening wajib: kegagalannya tidak boleh membatalkan registrasi
            // yang sudah ter-commit, tapi HARUS terlihat di log (di versi Go
            // baris log-nya justru dikomentari — CACAT-06).
            try {
                $this->saving_service->open_mandatory_accounts((int) $user['id']);
            } catch (Throwable $e) {
                log_message('error', '[register] gagal membuat rekening wajib user_id='
                    . $user['id'] . ': ' . $e->getMessage());
            }

            return $this->ok(array(
                'message' => 'Registrasi berhasil, OTP telah dikirim ke email Anda. Silakan verifikasi untuk login.',
                'user_id' => (int) $user['id'],
            ), 201);
        });
    }

    /** POST /api/v1/verify-email — OTP benar berarti auto-login. */
    public function verify_email() {
        $this->run(function () {
            $this->ratelimit->check('auth');

            $in = $this->validator->check($this->body, array(
                'email' => array('required', 'email'),
                'otp'   => array('required', 'len:6'),
            ));

            $user = $this->user_service->verify_email($in['email'], $in['otp']);

            return $this->ok(array(
                'token' => $this->jwt_service->issue($user['id'], $user['email']),
                'user'  => $this->User_model->to_public($user),
            ), 200);
        });
    }

    /**
     * POST /api/v1/resend-otp — usulan perbaikan CACAT-06 (README), belum ada
     * di blueprint asli. Balasan SELALU generik: tidak membocorkan apakah
     * email terdaftar atau sudah terverifikasi (lihat User_service::resend_otp).
     */
    public function resend_otp() {
        $this->run(function () {
            $this->ratelimit->check('auth');

            $in = $this->validator->check($this->body, array(
                'email' => array('required', 'email'),
            ));

            $this->user_service->resend_otp($in['email']);

            return $this->ok(array(
                'message' => 'jika email terdaftar dan belum diverifikasi, OTP baru telah dikirim',
            ), 200);
        });
    }

    /** POST /api/v1/login */
    public function login() {
        $this->run(function () {
            $this->ratelimit->check('auth');

            $in = $this->validator->check($this->body, array(
                'email'    => array('required', 'email'),
                'password' => array('required'),
            ));

            $user = $this->user_service->login($in['email'], $in['password']);

            return $this->ok(array(
                'token' => $this->jwt_service->issue($user['id'], $user['email']),
                'user'  => $this->User_model->to_public($user),
            ), 200);
        });
    }

    /**
     * POST /api/v1/logout
     * Butuh JWT + akun aktif, jadi guard-nya dipasang per method — kelas ini
     * berbasis API_Controller karena tiga endpoint lainnya publik.
     */
    public function logout() {
        $this->require_member();

        $this->run(function () {
            $this->user_service->logout($this->raw_token);
            return $this->ok(array('message' => 'logout berhasil'), 200);
        });
    }
}
