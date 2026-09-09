<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Notifikasi anggota — diisi otomatis saat admin mereview setoran/penarikan/
 * pembiayaan (Saving_service, Financing_service). Fitur baru di luar
 * blueprint 24 endpoint, mengikuti pola model lain di aplikasi ini.
 */
class Notification_model extends MY_Model {

    const COLS = 'id, user_id, category, title, message, is_read, created_at';

    public function insert($user_id, $category, $title, $message) {
        $this->q(
            "INSERT INTO notifications (user_id, category, title, message, is_read)
             VALUES (?, ?, ?, ?, 0)",
            array($user_id, $category, $title, $message));

        return (int) $this->db->insert_id();
    }

    public function get_by_user_paged($user_id, $limit, $offset) {
        $rows = $this->q(
            "SELECT " . self::COLS . " FROM notifications
              WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            array($user_id, (int) $limit, (int) $offset))->result_array();

        return array_map(array($this, 'shape'), $rows);
    }

    public function count_by_user($user_id) {
        $r = $this->row("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ?", array($user_id));
        return (int) $r['c'];
    }

    public function count_unread($user_id) {
        $r = $this->row(
            "SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0", array($user_id));
        return (int) $r['c'];
    }

    /** Kepemilikan diperiksa lewat affected_rows, bukan SELECT terpisah — sekali query. */
    public function mark_read($user_id, $id) {
        $this->q(
            "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?", array($id, $user_id));

        if ($this->db->affected_rows() === 0) {
            throw Api_exception::notificationNotFound();
        }
    }

    public function mark_all_read($user_id) {
        $this->q("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0", array($user_id));
        return $this->db->affected_rows();
    }

    private function shape(array $r) {
        $r['id']      = (int) $r['id'];
        $r['user_id'] = (int) $r['user_id'];
        $r['is_read'] = $this->truthy($r['is_read']);
        return $r;
    }
}
