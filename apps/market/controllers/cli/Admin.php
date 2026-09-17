<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 *   php market.php cli/admin promote <email>
 * Akun harus sudah pernah login ke marketplace (baris customers ada).
 */
class Admin extends CI_Controller {

    public function __construct() {
        parent::__construct();
        if ( ! is_cli()) { show_404(); }
    }

    public function promote($email = '') {
        $this->load->model('Customer_model');
        if ($this->Customer_model->promote_admin($email) < 1) {
            fwrite(STDERR, "akun {$email} tidak ditemukan (sudah pernah login ke marketplace?) atau sudah admin" . PHP_EOL);
            exit(1);
        }
        echo "OK {$email} kini admin marketplace" . PHP_EOL;
    }
}
