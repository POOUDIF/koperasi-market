<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Product_model extends MY_Model {

    const COLS = 'p.id, p.store_id, p.name, p.description, p.price, p.member_price, p.stock, p.weight_gram,
                  p.image_url, p.status, p.created_at, p.updated_at';

    /** Katalog publik: hanya produk aktif dari toko aktif. */
    public function search($q, $store_id, $limit, $offset) {
        list($where, $binds) = $this->public_filter($q, $store_id);

        $rows = $this->q(
            "SELECT " . self::COLS . ", s.name AS store_name, s.slug AS store_slug, s.city AS store_city
               FROM products p JOIN stores s ON s.id = p.store_id
              WHERE {$where}
              ORDER BY p.created_at DESC, p.id DESC LIMIT ? OFFSET ?",
            array_merge($binds, array((int) $limit, (int) $offset)))->result_array();

        return array_map(array($this, 'shape'), $rows);
    }

    public function count_search($q, $store_id) {
        list($where, $binds) = $this->public_filter($q, $store_id);
        return (int) $this->row("SELECT COUNT(*) AS c FROM products p JOIN stores s ON s.id = p.store_id WHERE {$where}", $binds)['c'];
    }

    public function find_public($id) {
        return $this->shape($this->row(
            "SELECT " . self::COLS . ", s.name AS store_name, s.slug AS store_slug, s.city AS store_city
               FROM products p JOIN stores s ON s.id = p.store_id
              WHERE p.id = ? AND p.status = 'active' AND s.status = 'active' LIMIT 1", array((int) $id)));
    }

    public function find_for_store($id, $store_id) {
        return $this->shape($this->row("SELECT " . self::COLS . " FROM products p WHERE p.id = ? AND p.store_id = ? LIMIT 1",
            array((int) $id, (int) $store_id)));
    }

    public function by_store($store_id) {
        return array_map(array($this, 'shape'), $this->q(
            "SELECT " . self::COLS . " FROM products p WHERE p.store_id = ? ORDER BY p.id DESC", array((int) $store_id))->result_array());
    }

    public function create($store_id, array $in) {
        $this->q(
            "INSERT INTO products (store_id, name, description, price, member_price, stock, weight_gram, image_url, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            array((int) $store_id, $in['name'], $in['description'], $in['price'], $in['member_price'],
                  (int) $in['stock'], (int) $in['weight_gram'], $in['image_url'], $in['status']));
        return $this->find_for_store((int) $this->db->insert_id(), $store_id);
    }

    public function update($id, $store_id, array $in) {
        $this->q(
            "UPDATE products SET name = ?, description = ?, price = ?, member_price = ?, stock = ?, weight_gram = ?,
                                 image_url = ?, status = ?
              WHERE id = ? AND store_id = ?",
            array($in['name'], $in['description'], $in['price'], $in['member_price'], (int) $in['stock'],
                  (int) $in['weight_gram'], $in['image_url'], $in['status'], (int) $id, (int) $store_id));
        return $this->find_for_store($id, $store_id);
    }

    public function shape($p) {
        if ($p === NULL) { return NULL; }
        $p['id']           = (int) $p['id'];
        $p['store_id']     = (int) $p['store_id'];
        $p['price']        = Money::out($p['price']);
        $p['member_price'] = $p['member_price'] === NULL ? NULL : Money::out($p['member_price']);
        $p['stock']        = (int) $p['stock'];
        $p['weight_gram']  = (int) $p['weight_gram'];
        return $p;
    }

    private function public_filter($q, $store_id) {
        $where = "p.status = 'active' AND s.status = 'active'";
        $binds = array();
        if ($q !== '') {
            $where  .= ' AND (p.name LIKE ? OR p.description LIKE ?)';
            $like    = '%' . $this->db->escape_like_str($q) . '%';
            $binds[] = $like;
            $binds[] = $like;
        }
        if ($store_id !== NULL) {
            $where  .= ' AND p.store_id = ?';
            $binds[] = (int) $store_id;
        }
        return array($where, $binds);
    }
}
