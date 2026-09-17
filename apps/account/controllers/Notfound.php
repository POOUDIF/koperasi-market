<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Notfound extends Base_Controller {

    public function index() {
        $this->render_error(404, 'Halaman tidak ditemukan', 'Alamat yang Anda buka tidak tersedia.');
    }

    public function _remap() { $this->index(); }
}
