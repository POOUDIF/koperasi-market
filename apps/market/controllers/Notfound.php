<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Notfound extends CI_Controller {

    public function index() {
        $this->output
            ->set_status_header(404)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode(array('error' => 'endpoint tidak ditemukan', 'code' => 'NOT_FOUND')));
    }

    public function _remap() { $this->index(); }
}
