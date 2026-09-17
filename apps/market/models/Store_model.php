<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Store_model extends MY_Model {

    const COLS = 's.id, s.owner_customer_id, s.name, s.slug, s.description, s.city, s.status, s.created_at';

    public function find($id) {
        return $this->shape($this->row("SELECT " . self::COLS . " FROM stores s WHERE s.id = ? LIMIT 1", array((int) $id)));
    }

    public function find_by_slug($slug) {
        return $this->shape($this->row("SELECT " . self::COLS . " FROM stores s WHERE s.slug = ? LIMIT 1", array((string) $slug)));
    }

    public function find_by_owner($customer_id) {
        return $this->shape($this->row("SELECT " . self::COLS . " FROM stores s WHERE s.owner_customer_id = ? LIMIT 1", array((int) $customer_id)));
    }

    /** sso_sub pemilik — dibutuhkan sebagai payee Koperasi Pay. */
    public function owner_sub($store_id) {
        $r = $this->row("SELECT c.sso_sub FROM stores s JOIN customers c ON c.id = s.owner_customer_id WHERE s.id = ?", array((int) $store_id));
        return $r ? $r['sso_sub'] : NULL;
    }

    public function create($owner_id, array $in) {
        $slug = $this->unique_slug($in['name']);
        $ok = $this->db->query(
            "INSERT INTO stores (owner_customer_id, name, slug, description, city) VALUES (?, ?, ?, ?, ?)",
            array((int) $owner_id, $in['name'], $slug, $in['description'], $in['city']));
        if ($ok === FALSE) {
            if ($this->is_unique_violation()) { throw Api_exception::storeExists(); }
            log_message('error', '[store] insert gagal: ' . json_encode($this->db->error()));
            throw Api_exception::server();
        }
        return $this->find((int) $this->db->insert_id());
    }

    public function update($id, array $in) {
        $this->q("UPDATE stores SET name = ?, description = ?, city = ? WHERE id = ?",
            array($in['name'], $in['description'], $in['city'], (int) $id));
    }

    public function set_status($id, $status) {
        $this->q("UPDATE stores SET status = ? WHERE id = ?", array($status, (int) $id));
        return $this->db->affected_rows() > 0;
    }

    public function list_paged($limit, $offset) {
        $rows = $this->q(
            "SELECT " . self::COLS . ", c.name AS owner_name, c.email AS owner_email,
                    (SELECT COUNT(*) FROM products p WHERE p.store_id = s.id) AS product_count
               FROM stores s JOIN customers c ON c.id = s.owner_customer_id
              ORDER BY s.id DESC LIMIT ? OFFSET ?", array((int) $limit, (int) $offset))->result_array();
        return array_map(array($this, 'shape'), $rows);
    }

    public function count_all() {
        return (int) $this->row("SELECT COUNT(*) AS c FROM stores")['c'];
    }

    private function unique_slug($name) {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = substr($base !== '' ? $base : 'toko', 0, 100);
        // Sufiks acak: slug tidak bisa ditebak/diklaim duluan & tidak butuh loop cek.
        return $base . '-' . bin2hex(random_bytes(3));
    }

    private function shape($s) {
        if ($s === NULL) { return NULL; }
        $s['id']                = (int) $s['id'];
        $s['owner_customer_id'] = (int) $s['owner_customer_id'];
        if (isset($s['product_count'])) { $s['product_count'] = (int) $s['product_count']; }
        return $s;
    }
}
