<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Cart_model extends MY_Model {

    public function items($customer_id) {
        $rows = $this->q(
            "SELECT ci.product_id, ci.qty, p.name, p.price, p.member_price, p.stock, p.image_url, p.status AS product_status,
                    s.id AS store_id, s.name AS store_name, s.slug AS store_slug, s.status AS store_status
               FROM cart_items ci
               JOIN products p ON p.id = ci.product_id
               JOIN stores s ON s.id = p.store_id
              WHERE ci.customer_id = ?
              ORDER BY s.id, ci.created_at", array((int) $customer_id))->result_array();

        return array_map(function ($r) {
            return array(
                'product_id'   => (int) $r['product_id'],
                'qty'          => (int) $r['qty'],
                'name'         => $r['name'],
                'price'        => Money::out($r['price']),
                'member_price' => $r['member_price'] === NULL ? NULL : Money::out($r['member_price']),
                'stock'        => (int) $r['stock'],
                'image_url'    => $r['image_url'],
                'available'    => $r['product_status'] === 'active' && $r['store_status'] === 'active',
                'store_id'     => (int) $r['store_id'],
                'store_name'   => $r['store_name'],
                'store_slug'   => $r['store_slug'],
            );
        }, $rows);
    }

    public function set_qty($customer_id, $product_id, $qty) {
        $this->q(
            "INSERT INTO cart_items (customer_id, product_id, qty) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE qty = VALUES(qty)",
            array((int) $customer_id, (int) $product_id, (int) $qty));
    }

    public function remove($customer_id, $product_id) {
        $this->q("DELETE FROM cart_items WHERE customer_id = ? AND product_id = ?", array((int) $customer_id, (int) $product_id));
    }

    public function count($customer_id) {
        return (int) $this->row("SELECT COALESCE(SUM(qty), 0) AS c FROM cart_items WHERE customer_id = ?", array((int) $customer_id))['c'];
    }
}
