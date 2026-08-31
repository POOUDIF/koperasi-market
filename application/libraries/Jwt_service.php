<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Klaim identik dengan versi Go agar token lama tetap valid saat migrasi
 * bertahap: {user_id, email, iat, exp, iss:"koperasi-digital"}, HS256. (§10.5)
 */
class Jwt_service {

    private $secret;
    private $ttl;
    private $issuer;

    public function __construct() {
        $CI =& get_instance();
        $this->secret = (string) $CI->config->item('jwt_secret');
        $this->ttl    = ((int) $CI->config->item('jwt_ttl_hours')) * 3600;
        $this->issuer = $CI->config->item('jwt_issuer');

        if (strlen($this->secret) < 32) {
            log_message('error', '[jwt] JWT_SECRET kurang dari 32 karakter — token mudah dipalsukan');
        }
    }

    public function issue($user_id, $email) {
        $now = time();
        return JWT::encode(array(
            'user_id' => (int) $user_id,
            'email'   => $email,
            'iat'     => $now,
            'exp'     => $now + $this->ttl,
            'iss'     => $this->issuer,
        ), $this->secret, 'HS256');
    }

    /**
     * @return array|null klaim, atau NULL bila tidak valid.
     * Key() mengunci algoritma ke HS256 — serangan `alg: none` dan pergantian
     * ke RS256 tertutup, setara pengecekan *jwt.SigningMethodHMAC di Go.
     */
    public function verify($token) {
        try {
            return (array) JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (Throwable $e) {
            return NULL;
        }
    }

    public function ttl_seconds() { return $this->ttl; }

    /**
     * Kunci blocklist logout. Perbaikan CACAT-10: pakai sha256, bukan token utuh
     * — key Redis tetap pendek dan token tidak tersimpan mentah.
     */
    public function revoke_key($token) { return 'jwt_revoked:' . hash('sha256', $token); }
}
