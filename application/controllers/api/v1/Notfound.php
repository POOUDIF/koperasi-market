<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 404_override — route yang tidak dikenal tetap membalas JSON, bukan
 * halaman HTML CI3 yang akan membingungkan klien API.
 */
class Notfound extends CI_Controller {

    public function index() {
        $this->output
            ->set_status_header(404)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode(array(
                'error' => 'endpoint tidak ditemukan',
                'code'  => 'NOT_FOUND',
            )));
    }

    public function _remap() { $this->index(); }
}
