<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Logika bisnis pembiayaan murabahah (§14).
 */
class Financing_service {

    private $CI;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->model('Financing_model');
    }

    /**
     * FLOW-13 Pengajuan (§14.1).
     *
     *   margin_amount = principal x MURABAHAH_MARGIN_RATE   (default 0.10)
     *   total_payable = principal + margin
     *
     * Keduanya dihitung dengan bcmath dan di-persist: margin dikunci di awal
     * akad dan tidak pernah dihitung ulang. Itu inti murabahah — harga jual
     * disepakati di muka, tidak bertambah karena waktu.
     */
    public function apply_murabahah($user_id, array $in) {
        $rate      = (string) $this->CI->config->item('murabahah_margin_rate');
        $principal = Money::norm($in['principal_amount']);
        $margin    = Money::mul($principal, $rate);
        $total     = Money::add($principal, $margin);

        // Retry 3x untuk bentrok financing_number (sangat jarang, tapi mungkin
        // bila dua pengajuan tiba di mikrodetik yang sama).
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->CI->Financing_model->create(array(
                    'financing_number' => $this->generate_number($attempt),
                    'user_id'          => $user_id,
                    'principal_amount' => $principal,
                    'margin_amount'    => $margin,
                    'total_payable'    => $total,
                    'duration_months'  => (int) $in['duration_months'],
                ));
            } catch (Api_exception $e) {
                if ($e->code_name !== 'DUPLICATE_FINANCING_NUMBER') { throw $e; }
                log_message('warning', "[financing] nomor bentrok, percobaan ke-" . ($attempt + 1));
            }
        }

        // Handler Go memetakan semua error di endpoint ini ke 500; 503 lebih tepat.
        throw Api_exception::financingNumberBusy();
    }

    /**
     * FLOW-15 Review oleh admin (§14.3).
     * @param string $action 'approve' | 'reject'
     */
    public function review($admin_id, $financing_id, $action) {
        $f = $this->CI->Financing_model->find($financing_id);

        if ($f === NULL)                { throw Api_exception::financingNotFound(); }
        if ($f['status'] !== 'pending') { throw Api_exception::financingNotPending(); }

        if ($action === 'reject') {
            $this->CI->Financing_model->reject($financing_id, $admin_id);
        } else {
            $this->CI->Financing_model->approve_with_installments(
                $financing_id, $admin_id, $this->generate_installments($f));
        }

        return $this->CI->Financing_model->find($financing_id);
    }

    /** FLOW-16 Jadwal angsuran, dengan cek kepemilikan (§14.4). */
    public function get_installments($user_id, $financing_id) {
        $f = $this->CI->Financing_model->find($financing_id);

        if ($f === NULL)                             throw Api_exception::financingNotFound();
        if ((int) $f['user_id'] !== (int) $user_id)  throw Api_exception::financingNotFound();  // 404, bukan 403

        return $this->CI->Financing_model->get_installments($financing_id);
    }

    /**
     * FLOW-17 Bayar satu angsuran (§14.5).
     * Pra-validasi di luar transaction; sisanya atomik di model.
     */
    public function pay_installment($user_id, $installment_id, $account_id) {
        $ins = $this->CI->Financing_model->find_installment($installment_id);
        if ($ins === NULL) { throw Api_exception::installmentNotFound(); }

        $f = $this->CI->Financing_model->find((int) $ins['financing_id']);
        if ($f === NULL)                            throw Api_exception::installmentNotFound();
        if ((int) $f['user_id'] !== (int) $user_id) throw Api_exception::installmentNotFound();  // 404, bukan 403
        if ($ins['status'] === 'paid')              throw Api_exception::installmentAlreadyPaid();

        // Nominal diambil dari DB, bukan dari input anggota.
        return $this->CI->Financing_model->pay_installment(
            (int) $ins['id'], (int) $ins['financing_id'], Money::norm($ins['amount_due']),
            $account_id, $user_id);
    }

    /**
     * Generator jadwal angsuran flat bulanan (§14.3).
     *
     * Angsuran terakhir menyerap sisa pembulatan supaya jumlah seluruh
     * amount_due PERSIS sama dengan total_payable. Contoh 11.000.000 / 3:
     * 3.666.666,6667 + 3.666.666,6667 + 3.666.666,6666.
     */
    public function generate_installments(array $f, $base_date = NULL) {
        $n     = (int) $f['duration_months'];
        $total = Money::norm($f['total_payable']);
        $per   = Money::div($total, (string) $n);

        $base = new DateTimeImmutable($base_date === NULL ? 'now' : $base_date);
        $rows = array();

        for ($i = 0; $i < $n; $i++) {
            $amount = ($i === $n - 1)
                ? Money::sub($total, Money::mul($per, (string) ($n - 1)))
                : $per;

            $rows[] = array(
                'installment_number' => $i + 1,
                'amount_due'         => $amount,
                'amount_paid'        => '0.0000',
                // Padanan now.AddDate(0, i+1, 0) di Go, termasuk perilaku
                // overflow-nya (31 Jan + 1 bulan = 3 Mar). Kalau koperasi mau
                // "akhir bulan tetap akhir bulan", ganti ke modify('last day of
                // +N month") — dan sadari itu BERBEDA dari sistem lama (§19.8).
                'due_date'           => $base->modify('+' . ($i + 1) . ' month')->format('Y-m-d'),
                'status'             => 'unpaid',
            );
        }

        return $rows;
    }

    /**
     * Format Go: FIN-MRB-{unixnano}-{attempt}. PHP tidak punya presisi
     * nanodetik yang sama, jadi detik + fraksi hrtime + entropi acak.
     */
    private function generate_number($attempt) {
        return sprintf('FIN-MRB-%d-%06d-%d', time(), hrtime(TRUE) % 1000000, $attempt);
    }
}
