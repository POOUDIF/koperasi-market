<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Tugas terjadwal IdP — jadwalkan tiap menit di cron:
 *
 *   * * * * * php /path/account.php cli/maintenance run
 *
 * - kirim ulang back-channel logout yang gagal (backoff eksponensial)
 * - hapus authorization code & refresh token yang sudah lama kedaluwarsa (sekali per jam)
 */
class Maintenance extends CI_Controller {

    public function __construct() {
        parent::__construct();
        if ( ! is_cli()) { show_404(); }
        $this->db->query("SET SESSION time_zone = '+07:00'");
    }

    public function run() {
        $this->load->library('Backchannel_service');
        list($ok, $fail) = $this->backchannel_service->run();
        echo '[' . date('c') . "] backchannel terkirim={$ok} gagal={$fail}" . PHP_EOL;

        if ((int) date('i') === 0) {
            $this->load->model('Oauth_model');
            $this->Oauth_model->purge_expired();
            echo '[' . date('c') . '] purge token kedaluwarsa selesai' . PHP_EOL;
        }
    }
}
