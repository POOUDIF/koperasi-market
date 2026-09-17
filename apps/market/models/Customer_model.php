<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Customer_model extends MY_Model {

    public function find($id) {
        return $this->row("SELECT id, sso_sub, name, email, role, status, created_at FROM customers WHERE id = ? LIMIT 1", array((int) $id));
    }

    /** JIT provisioning dari klaim ID token — penautan HANYA lewat sso_sub. */
    public function upsert_from_sso(array $claims) {
        $sub   = (string) $claims['sub'];
        $name  = trim((string) ($claims['name'] ?? ''));
        $email = strtolower(trim((string) ($claims['email'] ?? '')));

        $this->q(
            "INSERT INTO customers (sso_sub, name, email) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email)",
            array($sub, $name !== '' ? $name : $email, $email));

        return (int) $this->row("SELECT id FROM customers WHERE sso_sub = ?", array($sub))['id'];
    }

    public function promote_admin($email) {
        $this->q("UPDATE customers SET role = 'admin' WHERE email = ?", array(strtolower(trim($email))));
        return $this->db->affected_rows();
    }
}
