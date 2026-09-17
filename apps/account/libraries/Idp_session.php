<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Sesi SSO di IdP — inilah yang membuat login kedua (layanan lain) tidak
 * perlu mengisi password lagi (§3.3).
 *
 * Cookie: <__Secure->jdc_idp, Path=/account, HttpOnly, SameSite=Lax.
 * Nilai cookie = token 256 bit; DB hanya menyimpan SHA-256-nya.
 */
class Idp_session {

    const TOUCH_INTERVAL = 60;

    private $CI;
    private $current = FALSE;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->model(array('Session_model', 'User_model', 'Oauth_model', 'Audit_model'));
        $this->CI->load->library('Backchannel_service');
    }

    private function cookie_name() {
        return ($this->CI->config->item('cookie_secure') ? '__Secure-' : '') . 'jdc_idp';
    }

    private function set_cookie($value, $expires) {
        setcookie($this->cookie_name(), $value, array(
            'expires'  => $expires,
            'path'     => $this->CI->config->item('base_path'),
            'secure'   => (bool) $this->CI->config->item('cookie_secure'),
            'httponly' => TRUE,
            'samesite' => 'Lax',
        ));
    }

    /**
     * @return array|null ['id','sid','user_id','auth_time','remember','user' => row]
     */
    public function current() {
        if ($this->current !== FALSE) { return $this->current; }

        $token = $_COOKIE[$this->cookie_name()] ?? NULL;
        if ( ! is_string($token) || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return $this->current = NULL;
        }

        $s = $this->CI->Session_model->find_active_by_token_hash(
            hash('sha256', $token), $this->CI->config->item('session_idle_ttl'));
        if ($s === NULL) {
            $this->set_cookie('', 1);
            return $this->current = NULL;
        }

        if (time() - (int) $s['last_seen_at'] >= self::TOUCH_INTERVAL) {
            $this->CI->Session_model->touch($s['id']);
        }

        $s['user'] = $this->CI->User_model->find_by_id($s['user_id']);
        return $this->current = $s;
    }

    /**
     * Login sukses. Bila browser sudah punya sesi milik user yang sama
     * (re-autentikasi prompt=login / step-up), sesi itu dipertahankan dan
     * hanya auth_time yang diperbarui — client lain tidak ikut ter-logout.
     */
    public function login(array $user, $remember) {
        $now = time();
        $existing = $this->current();

        if ($existing !== NULL && (int) $existing['user_id'] === (int) $user['id']) {
            $this->CI->Session_model->set_auth_time($existing['id'], $now);
            $existing['auth_time'] = $now;
            $this->CI->Audit_model->log('login_reauth', array('user_id' => $user['id']));
            return $this->current = $existing;
        }
        if ($existing !== NULL) {
            // User lain login di browser yang sama: sesi lama diakhiri penuh.
            $this->revoke($existing, 'switched_user');
        }

        $token    = random_token(32);
        $lifetime = $remember
            ? (int) $this->CI->config->item('session_remember_ttl')
            : (int) $this->CI->config->item('session_absolute_ttl');

        $sid = bin2hex(random_bytes(16));
        $id  = $this->CI->Session_model->insert(array(
            'sid'        => $sid,
            'token_hash' => hash('sha256', $token),
            'user_id'    => $user['id'],
            'auth_time'  => $now,
            'remember'   => (bool) $remember,
            'ip'         => $this->CI->input->ip_address(),
            'user_agent' => $this->CI->input->user_agent(),
            'expires_at' => $now + $lifetime,
        ));

        // Tanpa "Ingat saya" cookie berakhir saat browser ditutup.
        $this->set_cookie($token, $remember ? $now + $lifetime : 0);
        $this->CI->Audit_model->log('login_success', array('user_id' => $user['id'], 'meta' => array('sid' => $sid)));

        return $this->current = array(
            'id' => $id, 'sid' => $sid, 'user_id' => (int) $user['id'], 'auth_time' => $now,
            'remember' => $remember ? 1 : 0, 'user' => $user,
        );
    }

    /** Logout dari browser ini (tombol Keluar / end_session). */
    public function logout($reason = 'logout') {
        $s = $this->current();
        $this->set_cookie('', 1);
        $this->current = NULL;
        if ($s !== NULL) {
            $this->revoke($s, $reason);
        }
        return $s;
    }

    /**
     * Cabut satu sesi: refresh token-nya mati, lalu setiap client yang pernah
     * dimasuki sesi itu dikirimi back-channel logout.
     */
    public function revoke(array $s, $reason) {
        if ( ! $this->CI->Session_model->revoke($s['id'], $reason)) {
            return;   // sudah dicabut sebelumnya
        }
        $this->CI->Oauth_model->revoke_by_session($s['id']);
        $this->CI->Audit_model->log('session_revoked', array(
            'user_id' => $s['user_id'], 'meta' => array('sid' => $s['sid'], 'reason' => $reason)));

        $this->CI->backchannel_service->notify_session($s['id'], $s['sid'], $s['user_id']);
    }

    /** Cabut semua sesi user (ban, reset password), opsional kecuali sesi ini. */
    public function revoke_all_for_user($user_id, $reason, $except_session_id = NULL) {
        foreach ($this->CI->Session_model->active_for_user($user_id) as $row) {
            if ($except_session_id !== NULL && (int) $row['id'] === (int) $except_session_id) { continue; }
            $this->revoke(array('id' => $row['id'], 'sid' => $row['sid'], 'user_id' => $user_id), $reason);
        }
    }
}
