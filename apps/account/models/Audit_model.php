<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Jejak audit keamanan (login sukses/gagal, logout, ganti password, ban,
 * reuse refresh token) + outbox back-channel logout.
 */
class Audit_model extends MY_Model {

    public function log($event, array $ctx = array()) {
        try {
            $CI =& get_instance();
            $this->db->query(
                "INSERT INTO audit_logs (event, user_id, subject_hash, client_id, ip, user_agent, meta)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                array($event,
                      isset($ctx['user_id']) ? (int) $ctx['user_id'] : NULL,
                      $ctx['subject_hash'] ?? NULL,
                      $ctx['client_id'] ?? NULL,
                      PHP_SAPI === 'cli' ? 'cli' : substr((string) $CI->input->ip_address(), 0, 45),
                      substr((string) $CI->input->user_agent(), 0, 255),
                      isset($ctx['meta']) ? json_encode($ctx['meta'], JSON_UNESCAPED_SLASHES) : NULL));
        } catch (Throwable $e) {
            // Audit tidak boleh mematikan login — tapi harus terlihat di log.
            log_message('error', '[audit] gagal mencatat ' . $event . ': ' . $e->getMessage());
        }
    }

    public function count_recent($event, $subject_hash, $window_seconds) {
        $r = $this->row(
            "SELECT COUNT(*) AS c FROM audit_logs
              WHERE event = ? AND subject_hash = ? AND created_at > NOW() - INTERVAL ? SECOND",
            array($event, $subject_hash, (int) $window_seconds));
        return (int) $r['c'];
    }

    /* ------------------------------------------------ back-channel outbox */

    public function enqueue_backchannel($client_id, $sid, $sub) {
        $this->q("INSERT INTO backchannel_jobs (client_id, sid, sub) VALUES (?, ?, ?)",
            array($client_id, $sid, (string) $sub));
        return (int) $this->db->insert_id();
    }

    public function due_backchannel_jobs($limit = 50, array $ids = NULL) {
        $sql = "SELECT id, client_id, sid, sub, attempts FROM backchannel_jobs
                 WHERE delivered_at IS NULL AND failed_at IS NULL AND next_attempt_at <= NOW()";
        $binds = array();
        if ($ids !== NULL) {
            if (empty($ids)) { return array(); }
            $sql  .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $binds = array_map('intval', $ids);
        }
        $binds[] = (int) $limit;
        return $this->q($sql . " ORDER BY id LIMIT ?", $binds)->result_array();
    }

    public function backchannel_delivered($id) {
        $this->q("UPDATE backchannel_jobs SET delivered_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = ?", array((int) $id));
    }

    public function backchannel_failed($id, $attempts, $error, $give_up) {
        // Backoff eksponensial: 30 dtk, 1, 2, 4, ... menit, maksimal 1 jam.
        $delay = min(3600, 30 * (1 << min(10, (int) $attempts)));
        $this->q(
            "UPDATE backchannel_jobs
                SET attempts = attempts + 1, last_error = ?, next_attempt_at = NOW() + INTERVAL ? SECOND,
                    failed_at = IF(?, NOW(), NULL)
              WHERE id = ?",
            array(substr($error, 0, 500), $delay, $give_up ? 1 : 0, (int) $id));
    }
}
