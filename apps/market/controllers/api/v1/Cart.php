<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Cart extends Customer_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Cart_model', 'Product_model'));
    }

    /** GET /market/api/v1/cart — dikelompokkan per toko (checkout per toko). */
    public function index() {
        $this->run(function () {
            $groups = array();
            foreach ($this->Cart_model->items($this->customer['id']) as $it) {
                $sid = $it['store_id'];
                if ( ! isset($groups[$sid])) {
                    $groups[$sid] = array('store_id' => $sid, 'store_name' => $it['store_name'],
                                          'store_slug' => $it['store_slug'], 'items' => array(), 'subtotal' => '0');
                }
                $unit = $it['member_price'] !== NULL ? $it['member_price'] : $it['price'];
                $groups[$sid]['items'][] = $it;
                if ($it['available']) {
                    $groups[$sid]['subtotal'] = Money::add($groups[$sid]['subtotal'], Money::mul((string) $unit, (string) $it['qty']));
                }
            }
            foreach ($groups as &$g) { $g['subtotal'] = Money::out($g['subtotal']); }

            return $this->ok(array('stores' => array_values($groups)), 200);
        });
    }

    /** PUT /market/api/v1/cart/items {product_id, qty} — qty 0 menghapus. */
    public function upsert() {
        $this->run(function () {
            $max = (int) $this->config->item('max_qty_per_item');
            $in = $this->validator->check($this->body, array(
                'product_id' => array('required', 'int_gt:0'),
                'qty'        => array('required', 'int_between:0,' . $max),
            ));

            if ($in['qty'] === 0) {
                $this->Cart_model->remove($this->customer['id'], $in['product_id']);
                return $this->ok(array('cart_count' => $this->Cart_model->count($this->customer['id'])), 200);
            }

            $p = $this->Product_model->find_public($in['product_id']);
            if ($p === NULL) { throw Api_exception::productNotFound(); }

            $this->load->model('Store_model');
            $store = $this->Store_model->find($p['store_id']);
            if ((int) $store['owner_customer_id'] === (int) $this->customer['id']) { throw Api_exception::ownProduct(); }
            if ($p['stock'] < $in['qty']) { throw Api_exception::outOfStock($p['name']); }

            $this->Cart_model->set_qty($this->customer['id'], $in['product_id'], $in['qty']);
            return $this->ok(array('cart_count' => $this->Cart_model->count($this->customer['id'])), 200);
        });
    }

    /** DELETE /market/api/v1/cart/items/:product_id */
    public function remove($product_id) {
        $this->run(function () use ($product_id) {
            $this->Cart_model->remove($this->customer['id'], (int) $product_id);
            return $this->ok(array('cart_count' => $this->Cart_model->count($this->customer['id'])), 200);
        });
    }
}
