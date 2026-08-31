<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Worker CLI emas — Fase 6 (§16.2). Konsumen queue:gold_mint yang diisi oleh
 * Gold_service::buy() setelah debit saldo berhasil.
 *
 *   php index.php cli/gold_worker start     — loop BLPOP tanpa henti (di bawah Supervisor/NSSM)
 *   php index.php cli/gold_worker recover   — requeue 'pending' + cek receipt 'processing' (cron 5 menit)
 *   php index.php cli/gold_worker once 42   — proses satu ID transaksi (debugging manual)
 *
 * Arsitektur ini memilih Pilihan A dari §16.2: worker hanya MENGIRIM transaksi
 * (set 'processing' + tx_hash), tidak menunggu receipt secara sinkron di
 * dalam loop utama — itu akan memblokir seluruh antrian selama beberapa detik
 * per transaksi karena PHP tidak punya goroutine. Pengecekan receipt dilakukan
 * oleh `recover()`, dijadwalkan lewat cron terpisah.
 *
 * Selama Chain_client::is_ready() FALSE (SIGNER_SERVICE_URL/GOLD_CONTRACT_ADDRESS
 * belum diisi), worker berjalan di mode log-only: transaksi tetap 'pending' dan
 * akan di-requeue otomatis oleh recover() setiap siklus cron berikutnya.
 */
class Gold_worker extends CI_Controller {

    private $queue_key;

    public function __construct() {
        parent::__construct();
        if ( ! is_cli()) { show_404(); }

        set_time_limit(0);
        $this->load->model(array('Gold_model', 'User_model'));
        $this->load->library(array('Chain_client'));
        // read_write_timeout = 0 → BLPOP boleh menunggu selamanya (§10.6).
        $this->load->library('Redisx', array('blocking' => TRUE), 'redisq');

        $this->queue_key = $this->config->item('gold_mint_queue_key');
    }

    /** Loop utama event-driven, dijalankan sebagai proses long-running. */
    public function start() {
        $this->_log('worker dimulai — mode event-driven (BLPOP)');
        $this->recover();

        while (TRUE) {
            try {
                $res = $this->redisq->blpop($this->queue_key, 0); // [key, value]
                if ( ! $res || count($res) < 2) { continue; }

                $tx_id = (int) $res[1];
                $this->_log("menerima ID transaksi dari queue: {$tx_id}");
                $this->_process($tx_id);

            } catch (Throwable $e) {
                $this->_log('loop error: ' . $e->getMessage(), 'error');
                sleep(2); // jangan spin ketat saat Redis tumbang
            }
        }
    }

    /** Startup + pemulihan berkala (dijadwalkan lewat cron tiap 5 menit). */
    public function recover() {
        foreach ($this->Gold_model->find_by_status('pending') as $t) {
            try {
                $this->redisq->rpush($this->queue_key, $t['id']);
                $this->_log("requeue pending ID={$t['id']}");
            } catch (Throwable $e) {
                $this->_log("gagal requeue ID={$t['id']}: " . $e->getMessage(), 'error');
            }
        }

        foreach ($this->Gold_model->find_processing_with_hash() as $t) {
            $this->_check_receipt((int) $t['id'], $t['tx_hash']);
        }
    }

    /** Proses satu ID secara manual — dipakai untuk debugging. */
    public function once($tx_id = NULL) {
        if ($tx_id === NULL) {
            $this->_log('penggunaan: php index.php cli/gold_worker once <id>', 'error');
            return;
        }
        $this->_process((int) $tx_id);
    }

    // --------------------------------------------------------------- privat

    private function _process($tx_id) {
        $tx = $this->Gold_model->find_by_id($tx_id);
        if ( ! $tx) { $this->_log("transaksi ID={$tx_id} tidak ditemukan", 'error'); return; }

        // Idempotensi: ID yang sama bisa masuk antrian dua kali (mis. setelah recover()).
        if ($tx['status'] !== 'pending') {
            $this->_log("ID={$tx_id} status='{$tx['status']}' (bukan pending), dilewati");
            return;
        }

        $wallet = $this->User_model->get_wallet_address((int) $tx['user_id']);

        // Wallet belum diisi anggota → token tidak bisa dikirim → refund saldo.
        if (empty($wallet)) {
            $this->_log("user ID={$tx['user_id']} belum set wallet_address — refund ID={$tx_id}", 'warning');
            $this->Gold_model->refund_failed_transaction($tx_id);
            return;
        }

        if ( ! $this->chain_client->is_ready()) {
            $this->_log("[log-only] ID={$tx_id} — signer service belum dikonfigurasi | wallet: {$wallet}");
            return; // status tetap 'pending', akan di-requeue saat recovery berikutnya
        }

        // gram → unit on-chain (§16.1: kontrak CoopGold memakai 4 desimal).
        $units = bcmul(Money::norm($tx['gram_amount']), '10000', 0);

        try {
            $hash = $this->chain_client->mint($wallet, $units, $tx_id);
        } catch (Throwable $e) {
            $this->_log("GAGAL mint ID={$tx_id}: " . $e->getMessage(), 'error');
            // Perbaikan CACAT-02: saldo anggota sudah didebet saat /gold/buy,
            // jadi kegagalan broadcast WAJIB memicu refund otomatis.
            $this->Gold_model->refund_failed_transaction($tx_id);
            return;
        }

        $this->Gold_model->update_status_and_hash($tx_id, 'processing', $hash);
        $this->_log("ID={$tx_id} dikirim ke chain — tx_hash: {$hash}");
    }

    private function _check_receipt($tx_id, $hash) {
        try {
            $receipt = $this->chain_client->get_receipt($hash);
        } catch (Throwable $e) {
            $this->_log("gagal ambil receipt ID={$tx_id}: " . $e->getMessage(), 'error');
            return; // biarkan 'processing', coba lagi siklus cron berikutnya
        }

        if ($receipt === NULL) { return; } // belum ter-mine

        if ((int) $receipt['status'] === 1) {
            $this->Gold_model->update_status($tx_id, 'success');
            $this->_log("ID={$tx_id} dikonfirmasi on-chain — success");
        } else {
            $this->_log("ID={$tx_id} di-REVERT oleh EVM — refund...", 'warning');
            $this->Gold_model->refund_failed_transaction($tx_id);
        }
    }

    /**
     * @param string $level 'info'|'warning'|'error' — hanya untuk STDOUT/STDERR
     *                      dan prefiks baris. CI_Log bawaan CI3 cuma mengenal
     *                      'error'/'debug'/'info'/'all' (tidak ada 'warning'),
     *                      jadi dipetakan ke 'info' saat menulis ke log_message().
     */
    private function _log($msg, $level = 'info') {
        $line = '[' . date('Y-m-d H:i:s') . '] [gold-worker] [' . strtoupper($level) . "] {$msg}";
        fwrite($level === 'error' ? STDERR : STDOUT, $line . PHP_EOL);
        log_message($level === 'error' ? 'error' : 'info', $line);
    }
}
