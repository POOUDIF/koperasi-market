<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Repository users (§11.5).
 *
 * Sejak SSO, baris di sini adalah proyeksi lokal akun JDC (ditautkan lewat
 * sso_sub) plus data milik koperasi: role, status lokal, keanggotaan, PIN.
 */
class User_model extends MY_Model {

    const COLS = 'id, sso_sub, nama_lengkap, email, password_hash, role, wallet_address,
                  status, member_since, is_email_verified, created_at, updated_at';

    /**
     * Jalur registrasi lama (AUTH_MODE=legacy).
     * @throws Api_exception 409 bila email sudah terdaftar.
     */
    public function insert($nama, $email, $hash) {
        $ok = $this->db->query(
            "INSERT INTO users (nama_lengkap, email, password_hash, member_since) VALUES (?, ?, ?, NOW())",
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

    public function find_by_sub($sub) {
        return $this->row("SELECT " . self::COLS . " FROM users WHERE sso_sub = ? LIMIT 1", array((string) $sub));
    }

    /**
     * JIT provisioning dari klaim ID token (§3.6).
     *
     * Penautan HANYA lewat sso_sub. Baris lama dengan email sama tapi belum
     * ditautkan TIDAK pernah diambil alih otomatis — itu jalan pintas klasik
     * pembajakan akun; migrasi resmi menautkan lewat id (cli/sso link_users).
     *
     * @return int id user lokal
     */
    public function upsert_from_sso(array $claims) {
        $sub      = (string) $claims['sub'];
        $name     = trim((string) ($claims['name'] ?? ''));
        $email    = strtolower(trim((string) ($claims['email'] ?? '')));
        $verified = ! empty($claims['email_verified']) ? 1 : 0;

        $existing = $this->find_by_sub($sub);

        if ($existing !== NULL) {
            if ($name !== '' && $name !== $existing['nama_lengkap']) {
                $this->q("UPDATE users SET nama_lengkap = ? WHERE id = ?", array($name, $existing['id']));
            }
            if ($email !== '' && $email !== $existing['email']) {
                $ok = $this->db->query("UPDATE users SET email = ?, is_email_verified = ? WHERE id = ?",
                    array($email, $verified, $existing['id']));
                if ($ok === FALSE) {
                    log_message('error', "[sso] sinkron email sub={$sub} gagal (bentrok?): " . json_encode($this->db->error()));
                }
            }
            return (int) $existing['id'];
        }

        $ok = $this->db->query(
            "INSERT INTO users (sso_sub, nama_lengkap, email, password_hash, is_email_verified, role, status)
             VALUES (?, ?, ?, NULL, ?, 'anggota', 'active')",
            array($sub, $name !== '' ? $name : $email, $email, $verified));

        if ($ok === FALSE) {
            if ($this->is_unique_violation()) {
                // Balapan dua callback bersamaan untuk sub yang sama → ambil ulang.
                $again = $this->find_by_sub($sub);
                if ($again !== NULL) { return (int) $again['id']; }
                throw Api_exception::accountLinkConflict();
            }
            log_message('error', '[user_model] provisioning gagal: ' . json_encode($this->db->error()));
            throw Api_exception::server();
        }
        return (int) $this->db->insert_id();
    }

    /** Query super-ringan untuk middleware — dijalankan setiap request terautentikasi. */
    public function get_auth_state($id) {
        return $this->row("SELECT status, member_since FROM users WHERE id = ? LIMIT 1", array($id));
    }

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

    /* ------------------------------------------------------- keanggotaan */

    /**
     * Aktivasi keanggotaan: kunci baris user, buka rekening wajib, set
     * member_since — semua dalam satu transaction supaya dua klik bersamaan
     * tidak membuka rekening wajib dua kali.
     */
    public function activate_membership($user_id, callable $open_accounts) {
        return $this->atomic(function () use ($user_id, $open_accounts) {
            $u = $this->row("SELECT id, member_since, status FROM users WHERE id = ? FOR UPDATE", array($user_id));
            if ($u === NULL)                  { throw Api_exception::userNotFound(); }
            if ($u['member_since'] !== NULL)  { throw Api_exception::alreadyMember(); }

            $opened = $open_accounts((int) $user_id);
            $this->q("UPDATE users SET member_since = NOW() WHERE id = ?", array($user_id));
            return $opened;
        });
    }

    /* ------------------------------------------------------ PIN transaksi */

    public function get_pin_state($id) {
        return $this->row(
            "SELECT pin_hash, pin_failed_attempts, pin_locked_until, (pin_locked_until IS NOT NULL AND pin_locked_until > NOW()) AS locked
               FROM users WHERE id = ? LIMIT 1", array($id));
    }

    public function set_pin($id, $hash) {
        $this->q("UPDATE users SET pin_hash = ?, pin_failed_attempts = 0, pin_locked_until = NULL WHERE id = ?", array($hash, $id));
    }

    /**
     * Catat PIN salah secara atomik. MySQL mengevaluasi SET dari kiri ke kanan
     * memakai nilai yang SUDAH diperbarui, jadi ekspresi kedua membaca
     * pin_failed_attempts hasil increment.
     */
    public function register_pin_failure($id, $max_attempts, $lock_minutes) {
        $this->q(
            "UPDATE users
                SET pin_failed_attempts = pin_failed_attempts + 1,
                    pin_locked_until = IF(pin_failed_attempts >= ?, NOW() + INTERVAL ? MINUTE, pin_locked_until)
              WHERE id = ?", array((int) $max_attempts, (int) $lock_minutes, $id));
    }

    public function reset_pin_failures($id) {
        $this->q("UPDATE users SET pin_failed_attempts = 0, pin_locked_until = NULL WHERE id = ? AND pin_failed_attempts > 0", array($id));
    }

    /* ------------------------------------------------------------- admin */

    /** Daftar anggota untuk dashboard admin, berpaginasi (CACAT-09). */
    public function get_all_paged($limit, $offset) {
        $rows = $this->q(
            "SELECT " . self::COLS . " FROM users WHERE email NOT LIKE '%@system.internal'
              ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            array((int) $limit, (int) $offset))->result_array();

        return array_map(array($this, 'to_public'), $rows);
    }

    public function count_all() {
        $r = $this->row("SELECT COUNT(*) AS c FROM users WHERE email NOT LIKE '%@system.internal'");
        return (int) $r['c'];
    }

    /**
     * Buang password_hash + normalisasi tipe. Padanan tag json:"-" di Go.
     * WAJIB dipanggil pada setiap payload user yang keluar ke frontend —
     * tanpa ini, hash bcrypt seluruh anggota terkirim lewat /admin/users.
     */
    public function to_public(array $u) {
        unset($u['password_hash'], $u['pin_hash']);
        $u['id']                = (int) $u['id'];
        $u['is_email_verified'] = $this->truthy($u['is_email_verified']);
        $u['is_member']         = isset($u['member_since']) && $u['member_since'] !== NULL;
        return $u;
    }
}
