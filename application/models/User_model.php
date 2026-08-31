<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Repository users (§11.5).
 */
class User_model extends MY_Model {

    const COLS = 'id, nama_lengkap, email, password_hash, role, wallet_address,
                  status, is_email_verified, created_at, updated_at';

    /**
     * @throws Api_exception 409 bila email sudah terdaftar.
     * MySQL tidak punya RETURNING (§9.4), jadi insert lalu baca ulang by id.
     */
    public function insert($nama, $email, $hash) {
        $ok = $this->db->query(
            "INSERT INTO users (nama_lengkap, email, password_hash) VALUES (?, ?, ?)",
            array($nama, $email, $hash));

        if ($ok === FALSE) {
            if ($this->is_unique_violation()) { throw Api_exception::emailExists(); }
            log_message('error', '[user_model] insert gagal: ' . json_encode($this->db->error()));
            throw Api_exception::server();
        }

        $id   = (int) $this->db->insert_id();
        $user = $this->find_by_id($id);
        if ($user === NULL) { throw Api_exception::server(); }

        return $user;
    }

    public function find_by_email($email) {
        return $this->row("SELECT " . self::COLS . " FROM users WHERE email = ? LIMIT 1", array($email));
    }

    public function find_by_id($id) {
        return $this->row("SELECT " . self::COLS . " FROM users WHERE id = ? LIMIT 1", array($id));
    }

    /** Query super-ringan untuk middleware — dijalankan setiap request ber-JWT. */
    public function get_status($id) {
        $r = $this->row("SELECT status FROM users WHERE id = ? LIMIT 1", array($id));
        return $r ? $r['status'] : NULL;
    }

    public function get_role($id) {
        $r = $this->row("SELECT role FROM users WHERE id = ? LIMIT 1", array($id));
        return $r ? $r['role'] : NULL;
    }

    public function get_wallet_address($id) {
        $r = $this->row("SELECT wallet_address FROM users WHERE id = ? LIMIT 1", array($id));
        return $r ? $r['wallet_address'] : NULL;
    }

    public function mark_email_verified($id) {
        $this->q("UPDATE users SET is_email_verified = 1, updated_at = NOW() WHERE id = ?", array($id));
    }

    /** Daftar anggota untuk dashboard admin, berpaginasi (CACAT-09). */
    public function get_all_paged($limit, $offset) {
        $rows = $this->q(
            "SELECT " . self::COLS . " FROM users ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            array((int) $limit, (int) $offset))->result_array();

        return array_map(array($this, 'to_public'), $rows);
    }

    public function count_all() {
        $r = $this->row("SELECT COUNT(*) AS c FROM users");
        return (int) $r['c'];
    }

    /**
     * Buang password_hash + normalisasi tipe. Padanan tag json:"-" di Go.
     * WAJIB dipanggil pada setiap payload user yang keluar ke frontend —
     * tanpa ini, hash bcrypt seluruh anggota terkirim lewat /admin/users.
     */
    public function to_public(array $u) {
        unset($u['password_hash']);
        $u['id']                = (int) $u['id'];
        $u['is_email_verified'] = $this->truthy($u['is_email_verified']);
        return $u;
    }
}
