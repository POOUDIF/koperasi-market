<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CSRF untuk form HTML IdP (login, daftar, ganti password, logout, admin).
 *
 * Double-submit cookie: token acak di cookie HttpOnly + SameSite=Strict yang
 * juga dirender ke hidden field. Penyerang lintas situs tidak bisa membaca
 * cookie maupun halaman, dan cookie Strict tidak ikut terkirim dari situs lain.
 * Ditambah pemeriksaan Origin/Sec-Fetch-Site bila browser mengirimkannya.
 */
class Csrf {

    const FIELD = '_csrf';

    private $CI;
    private $token;

    public function __construct() {
        $this->CI =& get_instance();
    }

    private function cookie_name() {
        return ($this->CI->config->item('cookie_secure') ? '__Secure-' : '') . 'jdc_csrf';
    }

    public function token() {
        if ($this->token !== NULL) { return $this->token; }

        $existing = $_COOKIE[$this->cookie_name()] ?? '';
        if (is_string($existing) && preg_match('/^[A-Za-z0-9_-]{43}$/', $existing)) {
            return $this->token = $existing;
        }

        $this->token = random_token(32);
        setcookie($this->cookie_name(), $this->token, array(
            'path'     => $this->CI->config->item('base_path'),
            'secure'   => (bool) $this->CI->config->item('cookie_secure'),
            'httponly' => TRUE,
            'samesite' => 'Strict',
        ));
        return $this->token;
    }

    public function field() {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . e($this->token()) . '">';
    }

    public function valid() {
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
        if ($site !== '' && ! in_array($site, array('same-origin', 'none'), TRUE)) {
            return FALSE;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && $origin !== 'null') {
            $app = parse_url($this->CI->config->item('app_url'));
            $expected = $app['scheme'] . '://' . $app['host'] . (isset($app['port']) ? ':' . $app['port'] : '');
            if (strcasecmp($origin, $expected) !== 0) {
                return FALSE;
            }
        }

        $cookie = $_COOKIE[$this->cookie_name()] ?? '';
        $posted = $this->CI->input->post(self::FIELD);
        return is_string($cookie) && is_string($posted) && $cookie !== '' && hash_equals($cookie, $posted);
    }
}
