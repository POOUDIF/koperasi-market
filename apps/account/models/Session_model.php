<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Sesi SSO IdP + daftar client yang dimasuki tiap sesi.
 */
class Session_model extends MY_Model {

    public function insert(array $s) {
        $this->q(
            "INSERT INTO idp_sessions (sid, token_hash, user_id, auth_time, remember, ip, user_agent, last_seen_at, expires_at)
             VALUES (?, ?, ?, FROM_UNIXTIME(?), ?, ?, ?, NOW(), FROM_UNIXTIME(?))",
            array($s['sid'], $s['token_hash'], (int) $s['user_id'], (int) $s['auth_time'], $s['remember'] ? 1 : 0,
                  substr((string) $s['ip'], 0, 45), substr((string) $s['user_agent'], 0, 255), (int) $s['expires_at']));

        return (int) $this->db->insert_id();
    }

    /**
     * Sesi aktif berdasarkan hash cookie. Idle timeout dihitung dari
     * last_seen_at; sesi "Ingat saya" hanya dibatasi expires_at.
     */
    public function find_active_by_token_hash($hash, $idle_ttl) {
        return $this->row(
            "SELECT s.id, s.sid, s.user_id, UNIX_TIMESTAMP(s.auth_time) AS auth_time, s.remember,
                    UNIX_TIMESTAMP(s.last_seen_at) AS last_seen_at, UNIX_TIMESTAMP(s.expires_at) AS expires_at
               FROM idp_sessions s
               JOIN users u ON u.id = s.user_id AND u.status = 'active'
              WHERE s.token_hash = ?
                AND s.revoked_at IS NULL
                AND s.expires_at > NOW()
                AND (s.remember = 1 OR s.last_seen_at > NOW() - INTERVAL ? SECOND)
              LIMIT 1",
            array($hash, (int) $idle_ttl));
    }

    /** Dipakai grant refresh_token: sesi harus tetap hidup agar token boleh dirotasi. */
    public function find_active_by_id($id, $idle_ttl) {
        return $this->row(
            "SELECT s.id, s.sid, s.user_id, UNIX_TIMESTAMP(s.auth_time) AS auth_time
               FROM idp_sessions s
              WHERE s.id = ?
                AND s.revoked_at IS NULL
                AND s.expires_at > NOW()
                AND (s.remember = 1 OR s.last_seen_at > NOW() - INTERVAL ? SECOND)
              LIMIT 1",
            array((int) $id, (int) $idle_ttl));
    }

    public function find_by_sid($sid) {
        return $this->row("SELECT id, sid, user_id, revoked_at FROM idp_sessions WHERE sid = ? LIMIT 1", array($sid));
    }

    public function touch($id) {
        $this->q("UPDATE idp_sessions SET last_seen_at = NOW() WHERE id = ?", array((int) $id));
    }

    public function set_auth_time($id, $ts) {
        $this->q("UPDATE idp_sessions SET auth_time = FROM_UNIXTIME(?), last_seen_at = NOW() WHERE id = ?",
            array((int) $ts, (int) $id));
    }

    /** @return bool TRUE bila baris benar-benar berubah (belum dicabut sebelumnya). */
    public function revoke($id, $reason) {
        $this->q("UPDATE idp_sessions SET revoked_at = NOW(), revoke_reason = ? WHERE id = ? AND revoked_at IS NULL",
            array($reason, (int) $id));
        return $this->db->affected_rows() > 0;
    }

    /** @return array sesi aktif user (untuk dicabut / ditampilkan) */
    public function active_for_user($user_id) {
        return $this->q(
            "SELECT id, sid, ip, user_agent, auth_time, last_seen_at, created_at, remember
               FROM idp_sessions
              WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
              ORDER BY last_seen_at DESC", array((int) $user_id))->result_array();
    }

    public function link_client($session_id, $client_id) {
        $this->q(
            "INSERT INTO idp_session_clients (session_id, client_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE last_login_at = NOW()",
            array((int) $session_id, $client_id));
    }

    public function clients_of($session_id) {
        return array_column($this->q(
            "SELECT client_id FROM idp_session_clients WHERE session_id = ?",
            array((int) $session_id))->result_array(), 'client_id');
    }
}
