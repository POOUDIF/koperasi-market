<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Verifikasi integritas buku besar (§22, "Verifikasi integritas buku besar").
 * Blueprint menyebut tiga query yang HARUS selalu mengembalikan nol baris,
 * dan menyarankan menjadikannya cron harian — sebelumnya tidak ada file/
 * scheduled job untuk ini sama sekali di proyek.
 *
 *   php index.php cli/ledger_audit run
 *
 * Exit code 0 = bersih, 1 = ada anomali (untuk dipasangkan ke alerting cron,
 * mis. `MAILTO=...` di crontab, atau exit code non-zero ditangkap Supervisor/
 * Task Scheduler). Jadwalkan harian; poin #3 (transaksi emas menggantung)
 * baru benar-benar bermakna setelah Gold_worker (Fase 6) berjalan produksi.
 */
class Ledger_audit extends CI_Controller {

    public function __construct() {
        parent::__construct();
        if ( ! is_cli()) { show_404(); }
    }

    public function run() {
        $dirty = FALSE;

        $dirty |= $this->_check_savings_ledger();
        $dirty |= $this->_check_financing_installments();
        $dirty |= $this->_check_gold_hanging();

        if ($dirty) {
            $this->_log('SELESAI DENGAN ANOMALI — lihat detail di atas', 'error');
            exit(1);
        }

        $this->_log('bersih — tidak ada anomali ditemukan');
        exit(0);
    }

    /** Saldo rekening wajib sama dengan agregat buku besarnya. */
    private function _check_savings_ledger() {
        $rows = $this->db->query(
            "SELECT a.id, a.balance,
                    COALESCE(SUM(CASE WHEN t.type='deposit'  THEN t.amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN t.type='withdraw' THEN t.amount ELSE 0 END), 0) AS ledger_balance
               FROM savings_accounts a
               LEFT JOIN savings_transactions t ON t.savings_account_id = a.id
              GROUP BY a.id, a.balance
             HAVING a.balance <> COALESCE(SUM(CASE WHEN t.type='deposit'  THEN t.amount ELSE 0 END), 0)
                               - COALESCE(SUM(CASE WHEN t.type='withdraw' THEN t.amount ELSE 0 END), 0)"
        )->result_array();

        if (empty($rows)) {
            $this->_log('OK  saldo rekening cocok dengan buku besar (savings_transactions)');
            return FALSE;
        }

        foreach ($rows as $r) {
            $this->_log("ANOMALI rekening id={$r['id']} balance={$r['balance']} "
                . "ledger_balance={$r['ledger_balance']}", 'error');
        }
        return TRUE;
    }

    /** Total angsuran wajib sama dengan total_payable. */
    private function _check_financing_installments() {
        $rows = $this->db->query(
            "SELECT f.id, f.total_payable, SUM(i.amount_due) AS jumlah_angsuran
               FROM financing f
               JOIN financing_installments i ON i.financing_id = f.id
              GROUP BY f.id, f.total_payable
             HAVING f.total_payable <> SUM(i.amount_due)"
        )->result_array();

        if (empty($rows)) {
            $this->_log('OK  total_payable cocok dengan SUM(amount_due) tiap pembiayaan');
            return FALSE;
        }

        foreach ($rows as $r) {
            $this->_log("ANOMALI financing id={$r['id']} total_payable={$r['total_payable']} "
                . "jumlah_angsuran={$r['jumlah_angsuran']}", 'error');
        }
        return TRUE;
    }

    /**
     * Transaksi emas menggantung > 1 jam — indikasi Gold_worker (Fase 6) mati
     * atau signer service tidak terjangkau. MySQL: INTERVAL 1 HOUR (bukan
     * sintaks Postgres `INTERVAL '1 hour'` di blueprint asli).
     */
    private function _check_gold_hanging() {
        $rows = $this->db->query(
            "SELECT id, status, created_at FROM gold_transactions
              WHERE status IN ('pending','processing') AND created_at < NOW() - INTERVAL 1 HOUR"
        )->result_array();

        if (empty($rows)) {
            $this->_log('OK  tidak ada transaksi emas menggantung > 1 jam');
            return FALSE;
        }

        foreach ($rows as $r) {
            $this->_log("ANOMALI gold_transactions id={$r['id']} status={$r['status']} "
                . "created_at={$r['created_at']} (worker mati / signer tidak terjangkau?)", 'error');
        }
        return TRUE;
    }

    private function _log($msg, $level = 'info') {
        $line = '[' . date('Y-m-d H:i:s') . "] [ledger-audit] {$msg}";
        fwrite($level === 'error' ? STDERR : STDOUT, $line . PHP_EOL);
        log_message($level === 'error' ? 'error' : 'info', $line);
    }
}
