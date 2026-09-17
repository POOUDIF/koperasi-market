<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Kirim ulang webhook Koperasi Pay yang gagal + tandai tagihan kedaluwarsa.
 * Jadwalkan tiap menit:
 *
 *   * * * * * php /path/index.php cli/webhooks run
 */
class Webhooks extends CI_Controller {

    public function __construct() {
        parent::__construct();
        if ( ! is_cli()) { show_404(); }
        $this->db->query("SET SESSION time_zone = '+07:00', SESSION transaction_isolation = 'READ-COMMITTED'");
    }

    public function run() {
        $this->load->model('Payment_model');
        $this->Payment_model->expire_stale();

        $this->load->library('Webhook_dispatcher');
        list($ok, $fail) = $this->webhook_dispatcher->run();
        echo '[' . date('c') . "] webhook terkirim={$ok} gagal={$fail}" . PHP_EOL;
    }
}
