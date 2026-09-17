<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pemeriksaan request lintas layanan yang tidak bergantung pada framework.
 */
class Request_guard {

    /**
     * Proteksi CSRF untuk API ber-cookie (§8 checklist).
     *
     * Header kustom X-Requested-With tidak bisa dikirim lintas origin tanpa
     * preflight CORS — dan preflight ditolak karena CORS hanya mengizinkan
     * origin frontend. Sec-Fetch-Site (dikirim semua browser modern) menolak
     * request lintas situs secara eksplisit sebagai lapis kedua.
     */
    public static function is_same_origin_xhr() {
        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (strcasecmp($xrw, 'XMLHttpRequest') !== 0) {
            return FALSE;
        }
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
        return $site === '' || $site === 'same-origin' || $site === 'none';
    }

    public static function is_unsafe_method() {
        return ! in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), array('GET', 'HEAD', 'OPTIONS'), TRUE);
    }

    /**
     * return_to hanya boleh path relatif di bawah prefix layanan — mencegah
     * open redirect (§8: "return_to hanya path relatif").
     */
    public static function safe_return_to($value, $base_path, $default) {
        if ( ! is_string($value) || $value === '' || strlen($value) > 2000) { return $default; }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $value)) { return $default; }
        if (strpos($value, '//') === 0 || $value[0] !== '/') { return $default; }

        $parts = parse_url($value);
        if ($parts === FALSE || isset($parts['scheme']) || isset($parts['host'])) { return $default; }

        $base = rtrim($base_path, '/');
        $path = $parts['path'] ?? '';
        if ($path !== $base && strpos($path, $base . '/') !== 0) { return $default; }
        if (strpos($path, '/../') !== FALSE || substr($path, -3) === '/..') { return $default; }

        return $value;
    }

    /** Header keamanan dasar untuk respons API JSON. */
    public static function api_security_headers() {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        if (env_bool('COOKIE_SECURE', TRUE)) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
