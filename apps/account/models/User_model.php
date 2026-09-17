<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_model extends MY_Model {

    const COLS = 'id, name, email, password_hash, email_verified_at, status, is_platform_admin,
                  password_changed_at, created_at, updated_at';

    public static function normalize_email($email) {
        return strtolower(trim((string) $email));
    }

    public function find_by_id($id) {
        return $this->row("SELECT " . self::COLS . " FROM users WHERE id = ? LIMIT 1", array((int) $id));
    }

    public function find_by_email($email) {
        return $this->row("SELECT " . self::COLS . " FROM users WHERE email = ? LIMIT 1",
            array(self::normalize_email($email)));
    }

    /** @throws Api_exception 409 bila email sudah terdaftar */
    public function insert($name, $email, $password_hash) {
        $ok = $this->db->query(
            "INSERT INTO users (name, email, password_hash, password_changed_at) VALUES (?, ?, ?, NOW())",
            array($name, self::normalize_email($email), $password_hash));

        if ($ok === FALSE) {
            if ($this->is_unique_violation()) { throw Api_exception::emailExists(); }
            log_message('error', '[user_model] insert gagal: ' . json_encode($this->db->error()));
            throw Api_exception::server();
        }
        return $this->find_by_id((int) $this->db->insert_id());
    }

    public function mark_email_verified($id) {
        $this->q("UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?", array((int) $id));
    }

    public function update_password($id, $hash) {
        $this->q("UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?", array($hash, (int) $id));
    }

    /** Rehash diam-diam saat login bila cost bcrypt dinaikkan — tidak mengubah password_changed_at. */
    public function rehash_password($id, $hash) {
        $this->q("UPDATE users SET password_hash = ? WHERE id = ?", array($hash, (int) $id));
    }

    public function update_name($id, $name) {
        $this->q("UPDATE users SET name = ? WHERE id = ?", array($name, (int) $id));
    }

    public function set_status($id, $status) {
        $this->q("UPDATE users SET status = ? WHERE id = ?", array($status, (int) $id));
    }

    public function search_paged($q, $limit, $offset) {
        $where = '';
        $binds = array();
        if ($q !== '') {
            $where = 'WHERE email LIKE ? OR name LIKE ?';
            $like  = '%' . $this->db->escape_like_str($q) . '%';
            $binds = array($like, $like);
        }
        $total = (int) $this->row("SELECT COUNT(*) AS c FROM users {$where}", $binds)['c'];
        $rows  = $this->q("SELECT id, name, email, email_verified_at, status, is_platform_admin, created_at
                             FROM users {$where} ORDER BY id DESC LIMIT ? OFFSET ?",
            array_merge($binds, array((int) $limit, (int) $offset)))->result_array();

        return array('items' => $rows, 'total' => $total);
    }
}
