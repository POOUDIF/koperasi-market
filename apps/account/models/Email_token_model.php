<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * OTP verifikasi email & token reset password.
 * Token baru untuk tujuan yang sama otomatis membatalkan token sebelumnya.
 */
class Email_token_model extends MY_Model {

    /** Jalankan $fn($this) dalam satu transaction — untuk alur yang butuh FOR UPDATE. */
    public function atomic_public(callable $fn) {
        return $this->atomic(function () use ($fn) { return $fn($this); });
    }

    public function replace($user_id, $purpose, $token_hash, $ttl) {
        return $this->atomic(function () use ($user_id, $purpose, $token_hash, $ttl) {
            $this->q("UPDATE email_tokens SET consumed_at = NOW()
                       WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL",
                array((int) $user_id, $purpose));
            $this->q("INSERT INTO email_tokens (user_id, purpose, token_hash, expires_at)
                      VALUES (?, ?, ?, NOW() + INTERVAL ? SECOND)",
                array((int) $user_id, $purpose, $token_hash, (int) $ttl));
        });
    }

    /** Token aktif terbaru untuk user & tujuan tertentu. WAJIB di dalam transaction. */
    public function lock_latest($user_id, $purpose) {
        return $this->row(
            "SELECT id, token_hash, attempts FROM email_tokens
              WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > NOW()
              ORDER BY id DESC LIMIT 1 FOR UPDATE",
            array((int) $user_id, $purpose));
    }

    public function find_active_by_hash($token_hash, $purpose) {
        return $this->row(
            "SELECT id, user_id FROM email_tokens
              WHERE token_hash = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > NOW() LIMIT 1",
            array($token_hash, $purpose));
    }

    public function increment_attempts($id) {
        $this->q("UPDATE email_tokens SET attempts = attempts + 1 WHERE id = ?", array((int) $id));
    }

    public function consume($id) {
        $this->q("UPDATE email_tokens SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL", array((int) $id));
        return $this->db->affected_rows() > 0;
    }
}
