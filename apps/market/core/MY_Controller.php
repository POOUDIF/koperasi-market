<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Basis controller API Marketplace.
 *
 *   API_Controller      publik (katalog, webhook)
 *   Customer_Controller wajib login SSO + akun marketplace aktif
 *   Admin_Controller    + role admin marketplace
 */
class API_Controller extends CI_Controller {

    protected $body = array();

    /** @var array|null baris customers untuk pemanggil yang login */
    protected $customer;

    public function __construct() {
        parent::__construct();
        $this->db->query("SET SESSION time_zone = '+07:00', SESSION transaction_isolation = 'READ-COMMITTED'");
        Request_guard::api_security_headers();
        $this->_parse_json_body();
    }

    private function _parse_json_body() {
        if ( ! in_array($this->input->method(TRUE), array('POST', 'PUT', 'PATCH', 'DELETE'), TRUE)) { return; }
        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded') === 0) { return; }

        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === FALSE) { return; }

        $decoded = json_decode($raw, TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail(Api_exception::badRequest('body bukan JSON yang valid'));
        }
        $this->body = is_array($decoded) ? $decoded : array();
    }

    protected function ok($data, $status = 200) {
        return $this->api_response->send($data, $status);
    }

    protected function fail(Api_exception $e) {
        $this->api_response->send(array('error' => $e->getMessage(), 'code' => $e->code_name), $e->status);
        $this->output->_display();
        exit;
    }

    protected function run(callable $fn) {
        try {
            return $fn();
        } catch (Api_exception $e) {
            $this->fail($e);
        } catch (Throwable $t) {
            log_message('error', '[api] ' . get_class($t) . ': ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
            $this->fail(Api_exception::server());
        }
    }

    protected function paging() {
        $max  = (int) $this->config->item('page_size_max');
        $def  = (int) $this->config->item('page_size_default');
        $page = max(1, (int) $this->input->get('page'));
        $per  = (int) $this->input->get('per_page');
        $per  = ($per <= 0) ? $def : min($max, $per);
        return array('page' => $page, 'per_page' => $per, 'offset' => ($page - 1) * $per);
    }

    protected function require_customer() {
        if ($this->customer !== NULL) { return $this->customer; }

        $this->load->library(array('Bff_session', 'Oidc_client'));
        $this->load->model('Customer_model');

        try {
            $s = $this->bff_session->current();
        } catch (Throwable $e) {
            log_message('error', '[auth] penyimpanan sesi tidak terjangkau: ' . $e->getMessage());
            $this->fail(Api_exception::sessionUnavailable());
        }
        if ($s === NULL) {
            $this->fail(Api_exception::unauthorized());
        }
        if (Request_guard::is_unsafe_method() && ! Request_guard::is_same_origin_xhr()) {
            $this->fail(Api_exception::forbidden('permintaan ditolak (CSRF)'));
        }

        try {
            $fresh = $this->bff_session->ensure_fresh($this->oidc_client);
        } catch (Throwable $e) {
            log_message('error', '[auth] refresh sesi gagal: ' . $e->getMessage());
            $fresh = TRUE;
        }
        if ( ! $fresh) {
            $this->fail(Api_exception::unauthorized('sesi telah berakhir, silakan masuk kembali'));
        }

        $c = $this->Customer_model->find($this->bff_session->current()['user_id']);
        if ($c === NULL) {
            $this->fail(Api_exception::unauthorized());
        }
        if ($c['status'] !== 'active') {
            $this->fail(Api_exception::forbidden('akun marketplace Anda ditangguhkan, hubungi admin'));
        }
        return $this->customer = $c;
    }
}

class Customer_Controller extends API_Controller {

    public function __construct() {
        parent::__construct();
        $this->require_customer();
    }
}

class Admin_Controller extends Customer_Controller {

    public function __construct() {
        parent::__construct();
        if ($this->customer['role'] !== 'admin') {
            $this->fail(Api_exception::forbidden());
        }
    }
}
