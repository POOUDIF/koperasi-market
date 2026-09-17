<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Meniru gin-contrib/cors: satu origin eksplisit + credentials + preflight cache 12 jam.
 * Wildcard '*' tidak dipakai karena tidak kompatibel dengan Allow-Credentials. (§8.4)
 */
class Cors {

    public function handle() {
        // Sejak SSO, SPA & API satu origin (jdc.shfopis.com/koperasi) dan auth
        // memakai cookie — CORS ber-credentials ke origin lain HANYA untuk dev
        // server Vite terpisah. Di produksi tidak ada origin lain yang diizinkan.
        if (ENVIRONMENT !== 'development') {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
                header('HTTP/1.1 204 No Content');
                exit;
            }
            return;
        }
        $allowed = env('FRONTEND_URL', 'http://localhost:5173');
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origin !== '' && $origin === $allowed) {
            header('Access-Control-Allow-Origin: ' . $allowed);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
        header('Access-Control-Expose-Headers: Content-Length');
        header('Access-Control-Max-Age: 43200');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            header('HTTP/1.1 204 No Content');
            exit;
        }
    }
}
