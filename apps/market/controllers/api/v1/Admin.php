<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin marketplace: moderasi toko & pemantauan pesanan.
 */
class Admin extends Admin_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Store_model', 'Order_model'));
    }

    /** GET /market/api/v1/admin/stores */
    public function stores() {
        $this->run(function () {
            $pg = $this->paging();
            return $this->ok(array(
                'stores'   => $this->Store_model->list_paged($pg['per_page'], $pg['offset']),
                'page'     => $pg['page'],
                'per_page' => $pg['per_page'],
                'total'    => $this->Store_model->count_all(),
            ), 200);
        });
    }

    /** PUT /market/api/v1/admin/stores/:id/status {status: active|suspended} */
    public function store_status($id) {
        $this->run(function () use ($id) {
            $in = $this->validator->check($this->body, array('status' => array('required', 'in:active,suspended')));
            if ($this->Store_model->find($id) === NULL) { throw Api_exception::storeNotFound(); }
            $this->Store_model->set_status($id, $in['status']);
            return $this->ok(array('store' => $this->Store_model->find($id)), 200);
        });
    }

    /** GET /market/api/v1/admin/orders?status= */
    public function orders() {
        $this->run(function () {
            $pg = $this->paging();
            $s  = (string) $this->input->get('status');
            $s  = in_array($s, array('pending_payment', 'paid', 'shipped', 'completed', 'cancelled'), TRUE) ? $s : '';
            return $this->ok(array(
                'orders'   => $this->Order_model->list_paged(array(), $s, $pg['per_page'], $pg['offset']),
                'page'     => $pg['page'],
                'per_page' => $pg['per_page'],
                'total'    => $this->Order_model->count(array(), $s),
            ), 200);
        });
    }
}
