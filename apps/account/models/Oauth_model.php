<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Client, authorization code, dan refresh token OAuth.
 * Semua kredensial disimpan sebagai SHA-256 — nilai mentah hanya pernah
 * ada di respons HTTP ke client yang berhak.
 */
class Oauth_model extends MY_Model {

    /** Jalankan $fn($this) dalam satu transaction — untuk alur yang butuh FOR UPDATE. */
    public function atomic_public(callable $fn) {
        return $this->atomic(function () use ($fn) { return $fn($this); });
    }

    /* ------------------------------------------------------------ clients */

    public function find_client($client_id) {
        $c = $this->row("SELECT * FROM oauth_clients WHERE client_id = ? AND is_active = 1 LIMIT 1", array((string) $client_id));
        if ($c === NULL) { return NULL; }

        foreach (array('redirect_uris', 'post_logout_redirect_uris', 'grant_types', 'scopes') as $f) {
            $c[$f] = json_decode($c[$f], TRUE) ?: array();
        }
        return $c;
    }

    public function all_clients() {
        return $this->q("SELECT client_id, name, backchannel_logout_uri, is_active FROM oauth_clients ORDER BY client_id")->result_array();
    }

    public function upsert_client($client_id, array $c, $secret_hash) {
        $this->q(
            "INSERT INTO oauth_clients (client_id, name, secret_hash, redirect_uris, post_logout_redirect_uris,
                                        backchannel_logout_uri, grant_types, scopes, audience, is_first_party, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
             ON DUPLICATE KEY UPDATE
               name = VALUES(name), secret_hash = VALUES(secret_hash), redirect_uris = VALUES(redirect_uris),
               post_logout_redirect_uris = VALUES(post_logout_redirect_uris),
               backchannel_logout_uri = VALUES(backchannel_logout_uri), grant_types = VALUES(grant_types),
               scopes = VALUES(scopes), audience = VALUES(audience), is_active = 1",
            array($client_id, $c['name'], $secret_hash, json_encode($c['redirect_uris'], JSON_UNESCAPED_SLASHES),
                  json_encode($c['post_logout_redirect_uris'], JSON_UNESCAPED_SLASHES), $c['backchannel_logout_uri'],
                  json_encode($c['grant_types']), json_encode($c['scopes']), $c['audience']));
    }

    /* -------------------------------------------------------------- codes */

    public function insert_code(array $c) {
        $this->q(
            "INSERT INTO oauth_auth_codes (code_hash, client_id, user_id, session_id, redirect_uri, scope, nonce, code_challenge, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)",
            array($c['code_hash'], $c['client_id'], (int) $c['user_id'], (int) $c['session_id'], $c['redirect_uri'],
                  $c['scope'], $c['nonce'], $c['code_challenge'], (int) $c['ttl']));
    }

    /** WAJIB di dalam transaction. */
    public function lock_code($code_hash) {
        return $this->row(
            "SELECT id, client_id, user_id, session_id, redirect_uri, scope, nonce, code_challenge, used_at,
                    (expires_at <= NOW()) AS expired
               FROM oauth_auth_codes WHERE code_hash = ? FOR UPDATE", array($code_hash));
    }

    public function mark_code_used($id) {
        $this->q("UPDATE oauth_auth_codes SET used_at = NOW() WHERE id = ?", array((int) $id));
    }

    /* ----------------------------------------------------- refresh tokens */

    public function insert_refresh(array $r) {
        $this->q(
            "INSERT INTO oauth_refresh_tokens (token_hash, family_id, client_id, user_id, session_id, auth_code_id, scope, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)",
            array($r['token_hash'], $r['family_id'], $r['client_id'], (int) $r['user_id'], (int) $r['session_id'],
                  $r['auth_code_id'], $r['scope'], (int) $r['ttl']));
    }

    /** WAJIB di dalam transaction. */
    public function lock_refresh($token_hash) {
        return $this->row(
            "SELECT id, family_id, client_id, user_id, session_id, scope, revoked_at,
                    UNIX_TIMESTAMP(used_at) AS used_at, (expires_at <= NOW()) AS expired
               FROM oauth_refresh_tokens WHERE token_hash = ? FOR UPDATE", array($token_hash));
    }

    public function mark_refresh_used($id) {
        $this->q("UPDATE oauth_refresh_tokens SET used_at = NOW() WHERE id = ?", array((int) $id));
    }

    public function revoke_family($family_id) {
        $this->q("UPDATE oauth_refresh_tokens SET revoked_at = NOW() WHERE family_id = ? AND revoked_at IS NULL", array($family_id));
    }

    public function revoke_by_code($auth_code_id) {
        $this->q(
            "UPDATE oauth_refresh_tokens t
               JOIN (SELECT DISTINCT family_id FROM oauth_refresh_tokens WHERE auth_code_id = ?) f ON f.family_id = t.family_id
                SET t.revoked_at = NOW()
              WHERE t.revoked_at IS NULL", array((int) $auth_code_id));
    }

    public function revoke_by_session($session_id) {
        $this->q("UPDATE oauth_refresh_tokens SET revoked_at = NOW() WHERE session_id = ? AND revoked_at IS NULL", array((int) $session_id));
    }

    public function find_refresh_for_revoke($token_hash) {
        return $this->row("SELECT family_id, client_id FROM oauth_refresh_tokens WHERE token_hash = ? LIMIT 1", array($token_hash));
    }

    /** Pembersihan rutin (cron): hapus code & token kedaluwarsa > 7 hari. */
    public function purge_expired() {
        $this->q("DELETE FROM oauth_auth_codes WHERE expires_at < NOW() - INTERVAL 7 DAY");
        $this->q("DELETE FROM oauth_refresh_tokens WHERE expires_at < NOW() - INTERVAL 7 DAY");
    }
}
