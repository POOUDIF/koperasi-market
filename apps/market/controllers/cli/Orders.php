<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pemeliharaan pesanan — jadwalkan tiap 5 menit:
 *
 *   * /5 * * * * php /path/market.php cli/orders maintenance
 *
 * - rekonsiliasi pesanan menunggu pembayaran dengan status tagihan koperasi
 * - batalkan pesanan tak dibayar > 24 jam (stok kembali)
 * - selesaikan otomatis pesanan dikirim > 7 hari (dana diteruskan ke penjual)
 * - ulangi settle/refund yang tertunda
 */
class Orders extends CI_Controller {

    public function __construct() {
        parent::__construct();
        if ( ! is_cli()) { show_404(); }
        $this->db->query("SET SESSION time_zone = '+07:00', SESSION transaction_isolation = 'READ-COMMITTED'");
    }

    public function maintenance() {
        $this->load->library('Order_service');
        $s = $this->order_service->maintenance();
        echo '[' . date('c') . "] sinkron={$s['synced']} batal_otomatis={$s['expired']} selesai_otomatis={$s['auto_completed']} payout={$s['payouts']}" . PHP_EOL;
    }
}
