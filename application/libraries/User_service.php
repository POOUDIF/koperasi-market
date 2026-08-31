<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Logika bisnis akun & autentikasi (§11).
 * Tidak menyentuh $this->db langsung — semua SQL ada di User_model.
 */
class User_service {

    private $CI;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->model(array('User_model', 'User_profile_model'));
        $this->CI->load->library('Email_service');
    }

    /**
     * FLOW-01 Registrasi (§11.1).
     * Rekening wajib TIDAK dibuka di sini — itu tanggung jawab Saving_service,
     * dipanggil controller setelah user tersimpan.
     */
    public function register(array $in) {
        // bcrypt cost 12, sama dengan versi Go.
        $hash = password_hash($in['password'], PASSWORD_BCRYPT, array(
            'cost' => (int) $this->CI->config->item('bcrypt_cost'),
        ));

        $user = $this->CI->User_model->insert($in['nama_lengkap'], $in['email'], $hash);

        $otp = $this->generate_otp();

        // OTP wajib tersimpan: tanpa itu anggota tidak punya jalan untuk
        // memverifikasi email, sementara barisnya sudah ada di DB.
        try {
            $this->CI->redisx->setex('otp:' . $in['email'],
                (int) $this->CI->config->item('otp_ttl_seconds'), $otp);
        } catch (Throwable $e) {
            log_message('error', '[register] gagal menyimpan OTP: ' . $e->getMessage());
            throw Api_exception::server();
        }

        // Kirim email — non-fatal, persis seperti kode Go.
        $this->CI->email_service->send_otp($in['email'], $otp);

        return $user;
    }

    /**
     * FLOW-02 Verifikasi email (§11.2). OTP sekali pakai, lalu auto-login.
     */
    public function verify_email($email, $otp) {
        $key = 'otp:' . $email;

        try {
            $stored = $this->CI->redisx->get($key);
        } catch (Throwable $e) {
            log_message('error', '[verify] Redis get gagal: ' . $e->getMessage());
            throw Api_exception::server();
        }

        if ($stored === NULL) {
            throw Api_exception::badRequest('kode OTP sudah kedaluwarsa atau tidak valid');
        }
        // hash_equals mencegah timing attack — perbaikan atas perbandingan '!=' di Go.
        if ( ! hash_equals((string) $stored, (string) $otp)) {
            throw Api_exception::badRequest('kode OTP salah');
        }

        try { $this->CI->redisx->del($key); } catch (Throwable $e) {
            log_message('error', '[verify] gagal menghapus OTP: ' . $e->getMessage());
        }

        $user = $this->CI->User_model->find_by_email($email);
        if ($user === NULL) { throw Api_exception::userNotFound(); }

        $this->CI->User_model->mark_email_verified((int) $user['id']);
        $user['is_email_verified'] = 1;

        return $user;
    }

    /**
     * FLOW-03 Login (§11.3).
     *
     * Urutan pemeriksaan disengaja dan wajib dipertahankan: password
     * diverifikasi SEBELUM status verifikasi email dan status akun. Tanpa
     * password yang benar, penyerang tidak boleh bisa menyimpulkan apakah
     * suatu email terdaftar, sudah terverifikasi, atau diblokir.
     */
    public function login($email, $password) {
        $user = $this->CI->User_model->find_by_email($email);

        if ($user === NULL) {
            // Tetap jalankan hash dummy agar waktu respons untuk email yang
            // tidak terdaftar sama dengan yang terdaftar.
            password_verify($password, '$2y$12$usesomesillystringforeedeoR0/e2m0F6PZgSs3wZjxLpxHFqW/Ni');
            throw Api_exception::invalidCredentials();
        }
        if ( ! password_verify($password, $user['password_hash'])) {
            throw Api_exception::invalidCredentials();
        }
        if ( ! $this->CI->User_model->truthy($user['is_email_verified'])) {
            throw Api_exception::emailNotVerified();
        }
        if ($user['status'] !== 'active') {
            throw Api_exception::accountSuspended();
        }

        return $user;
    }

    /** FLOW-04 Logout — blocklist token sampai masa berlakunya habis (§11.4). */
    public function logout($raw_token) {
        try {
            $this->CI->redisx->setex(
                $this->CI->jwt_service->revoke_key($raw_token),
                $this->CI->jwt_service->ttl_seconds(),
                'revoked');
        } catch (Throwable $e) {
            // Redis mati = token tidak bisa dicabut. Ini harus terlihat jelas
            // oleh pemanggil, bukan dilaporkan sebagai "logout berhasil".
            log_message('error', '[logout] gagal menulis blocklist: ' . $e->getMessage());
            throw Api_exception::server();
        }
    }

    /** 6 digit dari CSPRNG, zero-padded — '000123' adalah OTP yang sah (§19.9). */
    private function generate_otp() {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
