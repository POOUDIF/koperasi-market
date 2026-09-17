<?php
/**
 * Helper lintas layanan yang dibutuhkan SEBELUM CodeIgniter berjalan
 * (front controller, config.php).
 */

if ( ! function_exists('env')) {
    /** Ambil variabel lingkungan; string kosong diperlakukan sebagai tidak diset. */
    function env($key, $default = NULL) {
        $v = $_ENV[$key] ?? ($_SERVER[$key] ?? getenv($key));
        return ($v === FALSE || $v === '' || $v === NULL) ? $default : $v;
    }
}

if ( ! function_exists('env_bool')) {
    function env_bool($key, $default = FALSE) {
        $v = env($key);
        if ($v === NULL) { return $default; }
        return in_array(strtolower((string) $v), array('1', 'true', 'yes', 'on'), TRUE);
    }
}

if ( ! function_exists('base64url_encode')) {
    function base64url_encode($bin) {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}

if ( ! function_exists('base64url_decode')) {
    function base64url_decode($str) {
        $pad = strlen($str) % 4;
        if ($pad) { $str .= str_repeat('=', 4 - $pad); }
        return base64_decode(strtr($str, '-_', '+/'), TRUE);
    }
}

if ( ! function_exists('random_token')) {
    /** Token acak URL-safe dari CSPRNG. 32 byte = 256 bit entropi. */
    function random_token($bytes = 32) {
        return base64url_encode(random_bytes($bytes));
    }
}

if ( ! function_exists('e')) {
    /** Escape HTML untuk view server-rendered. */
    function e($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
