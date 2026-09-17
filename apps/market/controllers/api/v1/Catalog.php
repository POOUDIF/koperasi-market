<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Katalog publik — bisa dibuka tanpa login.
 */
class Catalog extends API_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Product_model', 'Store_model'));
    }

    /** GET /market/api/v1/products?q=&store=&page=&per_page= */
    public function products() {
        $this->run(function () {
            $pg    = $this->paging();
            $q     = trim((string) $this->input->get('q'));
            $store = ctype_digit((string) $this->input->get('store')) ? (int) $this->input->get('store') : NULL;

            if (mb_strlen($q) > 100) { throw Api_exception::badRequest('kata kunci terlalu panjang'); }

            return $this->ok(array(
                'products' => $this->Product_model->search($q, $store, $pg['per_page'], $pg['offset']),
                'page'     => $pg['page'],
                'per_page' => $pg['per_page'],
                'total'    => $this->Product_model->count_search($q, $store),
            ), 200);
        });
    }

    /** GET /market/api/v1/products/:id */
    public function product($id) {
        $this->run(function () use ($id) {
            $p = $this->Product_model->find_public($id);
            if ($p === NULL) { throw Api_exception::productNotFound(); }
            return $this->ok($p, 200);
        });
    }

    /** GET /market/api/v1/stores/:slug */
    public function store($slug) {
        $this->run(function () use ($slug) {
            $s = $this->Store_model->find_by_slug($slug);
            if ($s === NULL || $s['status'] !== 'active') { throw Api_exception::storeNotFound(); }

            return $this->ok(array(
                'store'    => array('id' => $s['id'], 'name' => $s['name'], 'slug' => $s['slug'],
                                    'description' => $s['description'], 'city' => $s['city'], 'created_at' => $s['created_at']),
                'products' => $this->Product_model->search('', $s['id'], 100, 0),
            ), 200);
        });
    }
}
