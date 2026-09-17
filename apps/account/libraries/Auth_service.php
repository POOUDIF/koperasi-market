<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Registrasi, verifikasi email, login, dan pemulihan kata sandi.
 * Diport dari koperasi (User_service) dengan penguatan: batas percobaan OTP,
 * penguncian login per email, token disimpan sebagai HMAC, dan pencabutan
 * semua sesi saat kata sandi berubah.
 */
class Auth_service {

    /** Hash bcrypt dummy — waktu respons login seragam untuk email tak terdaftar. */
    const DUMMY_HASH = '$2y$12$usesomesillystringforeedeoR0/e2m0F6PZgSs3wZjxLpxHFqW/Ni';

    const COMMON_PASSWORDS = array('password', 'password1', 'password123', '12345678', '123456789',
        '1234567890', 'qwerty123', 'qwertyuiop', '11111111', '00000000', 'iloveyou', 'admin123',
        'koperasi', 'koperasi123', 'bismillah', 'indonesia', 'rahasia123', 'abcd1234', 'passw0rd');

    private $CI;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->model(array('User_model', 'Email_token_model', 'Audit_model'));
        $this->CI->load->library(array('Email_service'));
    }

    /* -------------------------------------------------------- registrasi */

    public function register($name, $email, $password) {
        $email = User_model::normalize_email($email);
        $this->assert_password_policy($password, $email);
        $hash = $this->hash_password($password);

        $existing = $this->CI->User_model->find_by_email($email);
        if ($existing !== NULL) {
            if ($existing['email_verified_at'] !== NULL) {
                throw Api_exception::emailExists();
            }
            // Email terdaftar tapi belum terbukti dimiliki: pendaftar terakhir yang
            // lolos OTP-lah yang menentukan password. Tanpa ini, penyerang bisa
            // "memesan" email korban dengan password miliknya.
            $this->CI->User_model->update_name($existing['id'], $name);
            $this->CI->User_model->update_password($existing['id'], $hash);
            $user = $this->CI->User_model->find_by_id($existing['id']);
        } else {
            $user = $this->CI->User_model->insert($name, $email, $hash);
        }

        $this->CI->Audit_model->log('register', array('user_id' => $user['id']));
        $this->send_verification($user);
        return $user;
    }

    public function send_verification(array $user) {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->CI->Email_token_model->replace($user['id'], 'verify_email',
            $this->token_hash('verify_email', $otp, $user['id']), $this->CI->config->item('otp_ttl'));
        $this->CI->email_service->send_otp($user['email'], $user['name'], $otp);
    }

    /** Kirim ulang OTP — diam untuk email tak dikenal / sudah terverifikasi (anti-enumerasi). */
    public function resend_verification($email) {
        $user = $this->CI->User_model->find_by_email($email);
        if ($user === NULL || $user['email_verified_at'] !== NULL || $user['status'] !== 'active') {
            return;
        }
        $this->send_verification($user);
    }

    /** @return array user yang sudah terverifikasi */
    public function verify_email($email, $otp) {
        $user = $this->CI->User_model->find_by_email($email);
        if ($user === NULL || $user['email_verified_at'] !== NULL || ! preg_match('/^\d{6}$/', (string) $otp)) {
            throw Api_exception::otpInvalid();
        }

        $max = (int) $this->CI->config->item('otp_max_attempts');

        // Hasil ditentukan di dalam transaction, exception dilempar SETELAH
        // commit — kalau tidak, increment percobaan ikut ter-rollback dan
        // batas percobaan tidak pernah tercapai.
        $ok = $this->CI->Email_token_model->atomic_public(function ($m) use ($user, $otp, $max) {
            $t = $m->lock_latest($user['id'], 'verify_email');
            if ($t === NULL) { return FALSE; }

            if ((int) $t['attempts'] >= $max) {
                $m->consume($t['id']);
                return FALSE;
            }
            if ( ! hash_equals($t['token_hash'], $this->token_hash('verify_email', $otp, $user['id']))) {
                $m->increment_attempts($t['id']);
                return FALSE;
            }
            $m->consume($t['id']);
            return TRUE;
        });

        if ( ! $ok) {
            $this->CI->Audit_model->log('verify_email_failed', array('user_id' => $user['id']));
            throw Api_exception::otpInvalid();
        }

        $this->CI->User_model->mark_email_verified($user['id']);
        $this->CI->Audit_model->log('email_verified', array('user_id' => $user['id']));
        return $this->CI->User_model->find_by_id($user['id']);
    }

    /* ------------------------------------------------------------- login */

    /**
     * Urutan pemeriksaan disengaja: password diverifikasi SEBELUM status
     * verifikasi email dan status akun, agar tanpa password yang benar
     * penyerang tidak bisa menyimpulkan apa pun tentang sebuah email.
     *
     * @return array user (bisa belum terverifikasi — controller yang menangani)
     */
    public function login($email, $password) {
        $email   = User_model::normalize_email($email);
        $subject = hash('sha256', $email);

        $failures = $this->CI->Audit_model->count_recent('login_failed', $subject,
            $this->CI->config->item('login_failure_window'));
        if ($failures >= (int) $this->CI->config->item('login_max_failures')) {
            throw Api_exception::loginLocked();
        }

        $user = $this->CI->User_model->find_by_email($email);
        $hash = $user === NULL ? self::DUMMY_HASH : $user['password_hash'];

        if ( ! password_verify((string) $password, $hash) || $user === NULL) {
            $this->CI->Audit_model->log('login_failed', array(
                'subject_hash' => $subject, 'user_id' => $user['id'] ?? NULL));
            throw Api_exception::invalidCredentials();
        }
        if ($user['status'] !== 'active') {
            throw Api_exception::accountSuspended();
        }

        $cost = array('cost' => (int) $this->CI->config->item('bcrypt_cost'));
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, $cost)) {
            $this->CI->User_model->rehash_password($user['id'], password_hash($password, PASSWORD_BCRYPT, $cost));
        }
        return $user;
    }

    /* ---------------------------------------------------- lupa password */

    public function forgot_password($email) {
        $user = $this->CI->User_model->find_by_email($email);
        if ($user === NULL || $user['status'] !== 'active') {
            return;   // balasan controller tetap generik
        }

        $token = random_token(32);
        $this->CI->Email_token_model->replace($user['id'], 'reset_password',
            $this->token_hash('reset_password', $token), $this->CI->config->item('reset_token_ttl'));

        $url = $this->CI->config->item('issuer') . '/reset-password?token=' . rawurlencode($token);
        $this->CI->email_service->send_reset_link($user['email'], $user['name'], $url);
        $this->CI->Audit_model->log('password_reset_requested', array('user_id' => $user['id']));
    }

    public function reset_token_valid($token) {
        return is_string($token) && $token !== ''
            && $this->CI->Email_token_model->find_active_by_hash($this->token_hash('reset_password', $token), 'reset_password') !== NULL;
    }

    /** @return array user */
    public function reset_password($token, $password) {
        $row = is_string($token) && $token !== ''
            ? $this->CI->Email_token_model->find_active_by_hash($this->token_hash('reset_password', $token), 'reset_password')
            : NULL;
        if ($row === NULL) { throw Api_exception::resetTokenInvalid(); }

        $user = $this->CI->User_model->find_by_id($row['user_id']);
        $this->assert_password_policy($password, $user['email']);

        // consume() atomik (WHERE consumed_at IS NULL) — dua submit bersamaan
        // tidak bisa sama-sama mengganti password.
        if ( ! $this->CI->Email_token_model->consume($row['id'])) {
            throw Api_exception::resetTokenInvalid();
        }

        $this->CI->User_model->update_password($user['id'], $this->hash_password($password));
        // Tautan reset membuktikan kepemilikan email.
        $this->CI->User_model->mark_email_verified($user['id']);

        $this->CI->load->library('Idp_session');
        $this->CI->idp_session->revoke_all_for_user($user['id'], 'password_reset');

        $this->CI->Audit_model->log('password_reset', array('user_id' => $user['id']));
        $this->CI->email_service->send_password_changed($user['email'], $user['name']);
        return $user;
    }

    public function change_password(array $user, $current, $new, $keep_session_id) {
        if ( ! password_verify((string) $current, $user['password_hash'])) {
            throw Api_exception::badRequest('Kata sandi saat ini salah.');
        }
        $this->assert_password_policy($new, $user['email']);

        $this->CI->User_model->update_password($user['id'], $this->hash_password($new));
        $this->CI->load->library('Idp_session');
        $this->CI->idp_session->revoke_all_for_user($user['id'], 'password_changed', $keep_session_id);

        $this->CI->Audit_model->log('password_changed', array('user_id' => $user['id']));
        $this->CI->email_service->send_password_changed($user['email'], $user['name']);
    }

    /* ---------------------------------------------------------- internal */

    public function assert_password_policy($password, $email) {
        $password = (string) $password;
        $min = (int) $this->CI->config->item('password_min');

        if (mb_strlen($password) < $min) {
            throw Api_exception::weakPassword("Kata sandi minimal {$min} karakter.");
        }
        // bcrypt hanya membaca 72 byte pertama — sisanya diam-diam diabaikan.
        if (strlen($password) > 72) {
            throw Api_exception::weakPassword('Kata sandi maksimal 72 karakter.');
        }
        $lower = strtolower($password);
        if (in_array($lower, self::COMMON_PASSWORDS, TRUE)) {
            throw Api_exception::weakPassword('Kata sandi terlalu umum. Gunakan kombinasi yang lebih sulit ditebak.');
        }
        $local = strtolower((string) strstr((string) $email, '@', TRUE));
        if ($local !== '' && strpos($lower, $local) !== FALSE && strlen($local) >= 4) {
            throw Api_exception::weakPassword('Kata sandi tidak boleh memuat alamat email Anda.');
        }
    }

    private function hash_password($password) {
        return password_hash((string) $password, PASSWORD_BCRYPT, array('cost' => (int) $this->CI->config->item('bcrypt_cost')));
    }

    private function token_hash($purpose, $token, $user_id = '') {
        return hash_hmac('sha256', $purpose . '|' . $user_id . '|' . $token, (string) $this->CI->config->item('token_pepper'));
    }
}
