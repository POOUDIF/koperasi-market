<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Toko, produk, dan pesanan dari sisi penjual.
 */
class Seller extends Customer_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Store_model', 'Product_model', 'Order_model'));
    }

    /** GET /market/api/v1/seller/store */
    public function store() {
        $this->run(function () {
            return $this->ok(array('store' => $this->Store_model->find_by_owner($this->customer['id'])), 200);
        });
    }

    /**
     * POST /market/api/v1/seller/store {name, description, city}
     * Hanya anggota koperasi aktif ber-KYC — dana penjualan masuk ke simpanan anggota.
     */
    public function open_store() {
        $this->run(function () {
            $in = $this->store_input();

            $this->load->library('Koperasi_client');
            $m = $this->koperasi_client->member($this->customer['sso_sub'], TRUE);
            if (empty($m['is_member']) || empty($m['kyc_completed'])) {
                throw Api_exception::sellerNotEligible();
            }
            if ($this->Store_model->find_by_owner($this->customer['id']) !== NULL) {
                throw Api_exception::storeExists();
            }
            return $this->ok(array('store' => $this->Store_model->create($this->customer['id'], $in)), 201);
        });
    }

    /** PUT /market/api/v1/seller/store */
    public function update_store() {
        $this->run(function () {
            $store = $this->my_store();
            $this->Store_model->update($store['id'], $this->store_input());
            return $this->ok(array('store' => $this->Store_model->find($store['id'])), 200);
        });
    }

    /** GET /market/api/v1/seller/products */
    public function products() {
        $this->run(function () {
            return $this->ok(array('products' => $this->Product_model->by_store($this->my_store()['id'])), 200);
        });
    }

    /** POST /market/api/v1/seller/products */
    public function create_product() {
        $this->run(function () {
            $store = $this->my_store(TRUE);
            return $this->ok($this->Product_model->create($store['id'], $this->product_input()), 201);
        });
    }

    /** PUT /market/api/v1/seller/products/:id */
    public function update_product($id) {
        $this->run(function () use ($id) {
            $store = $this->my_store(TRUE);
            if ($this->Product_model->find_for_store($id, $store['id']) === NULL) {
                throw Api_exception::productNotFound();
            }
            return $this->ok($this->Product_model->update($id, $store['id'], $this->product_input()), 200);
        });
    }

    /** GET /market/api/v1/seller/orders?status= */
    public function orders() {
        $this->run(function () {
            $pg = $this->paging();
            $s  = (string) $this->input->get('status');
            $s  = in_array($s, array('pending_payment', 'paid', 'shipped', 'completed', 'cancelled'), TRUE) ? $s : '';
            $f  = array('store_id' => $this->my_store()['id']);

            $orders = $this->Order_model->list_paged($f, $s, $pg['per_page'], $pg['offset']);
            foreach ($orders as &$o) { $o['items'] = $this->Order_model->items($o['id']); }

            return $this->ok(array(
                'orders' => $orders, 'page' => $pg['page'], 'per_page' => $pg['per_page'],
                'total'  => $this->Order_model->count($f, $s),
            ), 200);
        });
    }

    /** POST /market/api/v1/seller/orders/:id/ship {tracking_number} */
    public function ship($id) {
        $this->run(function () use ($id) {
            $store = $this->my_store();
            $in = $this->validator->check($this->body, array('tracking_number' => array('required', 'min:3', 'max:100')));

            $o = $this->Order_model->find($id);
            if ($o === NULL || (int) $o['store_id'] !== $store['id']) { throw Api_exception::orderNotFound(); }
            if ( ! $this->Order_model->mark_shipped($id, $store['id'], $in['tracking_number'])) {
                throw Api_exception::orderInvalidState('dikirim');
            }
            $this->load->library('Order_service');
            return $this->ok($this->order_service->detail($id), 200);
        });
    }

    /** POST /market/api/v1/seller/orders/:id/cancel {reason} */
    public function cancel($id) {
        $this->run(function () use ($id) {
            $store = $this->my_store();
            $in = $this->validator->check($this->body, array('reason' => array('required', 'min:5', 'max:255')));

            $o = $this->Order_model->find($id);
            if ($o === NULL || (int) $o['store_id'] !== $store['id']) { throw Api_exception::orderNotFound(); }

            $this->load->library('Order_service');
            $this->order_service->cancel_by_seller($o, $in['reason']);
            return $this->ok($this->order_service->detail($id), 200);
        });
    }

    /* ------------------------------------------------------------ bantuan */

    private function my_store($must_be_active = FALSE) {
        $s = $this->Store_model->find_by_owner($this->customer['id']);
        if ($s === NULL) { throw Api_exception::noStore(); }
        if ($must_be_active && $s['status'] !== 'active') { throw Api_exception::storeSuspended(); }
        return $s;
    }

    private function store_input() {
        return $this->validator->check($this->body, array(
            'name'        => array('required', 'min:3', 'max:100'),
            'description' => array('max:2000'),
            'city'        => array('required', 'min:3', 'max:100'),
        ));
    }

    private function product_input() {
        $in = $this->validator->check($this->body, array(
            'name'         => array('required', 'min:3', 'max:150'),
            'description'  => array('max:5000'),
            'price'        => array('required', 'num_gt:0'),
            'member_price' => array('num_gt:0'),
            'stock'        => array('required', 'int_between:0,1000000'),
            'weight_gram'  => array('int_between:0,1000000'),
            'image_url'    => array('max:500'),
            'status'       => array('required', 'in:active,inactive'),
        ));

        $in['member_price'] = $in['member_price'] === '' ? NULL : $in['member_price'];
        if ($in['member_price'] !== NULL && Money::gt($in['member_price'], $in['price'])) {
            throw Api_exception::invalidMemberPrice();
        }
        $in['weight_gram'] = $in['weight_gram'] === '' ? 0 : $in['weight_gram'];
        // Hanya URL https — mencegah javascript:/data: dan mixed content.
        if ($in['image_url'] !== '' && ! preg_match('#^https://[^\s"<>]+$#i', $in['image_url'])) {
            throw Api_exception::badRequest('image_url harus alamat https://');
        }
        return $in;
    }
}
