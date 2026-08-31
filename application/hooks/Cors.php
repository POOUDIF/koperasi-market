<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Meniru gin-contrib/cors: satu origin eksplisit + credentials + preflight cache 12 jam.
 * Wildcard '*' tidak dipakai karena tidak kompatibel dengan Allow-Credentials. (§8.4)
 */
class Cors {

    public function handle() {
        $allowed = env('FRONTEND_URL', 'http://localhost:3000');
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origin !== '' && $origin === $allowed) {
            header('Access-Control-Allow-Origin: ' . $allowed);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Expose-Headers: Content-Length');
        header('Access-Control-Max-Age: 43200');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            header('HTTP/1.1 204 No Content');
            exit;
        }
    }
}
