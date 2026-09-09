<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Riwayat transaksi gabungan anggota (fitur baru, di luar blueprint 24 endpoint).
 *
 * `savings_transactions` SUDAH menjadi buku besar tunggal untuk seluruh
 * mutasi saldo rupiah anggota: setoran/tarik simpanan biasa (§13), cicilan
 * pembiayaan (Financing_model::pay_installment menulis type='withdraw',
 * reference_id='cicilan_{id}'), dan beli/jual emas (Gold_model menulis
 * reference_id='gold_buy_{id}' / 'gold_sell_{id}' / 'gold_refund_{id}').
 * Karena itu "riwayat transaksi" anggota cukup dibaca dari satu tabel ini —
 * tidak perlu UNION lintas tabel, dan saldo berjalan (running balance) bisa
 * dihitung tepat dengan window function MySQL 8, bukan diperkirakan.
 */
class Transaction_model extends MY_Model {

    const VALID_JENIS = array('semua', 'simpanan', 'pinjaman', 'emas');

    public function get_history_paged($user_id, $jenis, $date, $limit, $offset) {
        $date = ($date === '') ? NULL : $date;

        $sql = $this->base_cte() . "
            SELECT id, created_at, type, amount, reference_id, jenis, saldo
              FROM ledger
             WHERE (? = 'semua' OR jenis = ?)
               AND (? IS NULL OR DATE(created_at) = ?)
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?";

        $rows = $this->q($sql, array(
            $user_id, $jenis, $jenis, $date, $date, (int) $limit, (int) $offset,
        ))->result_array();

        return array_map(array($this, 'shape'), $rows);
    }

    public function count_history($user_id, $jenis, $date) {
        $date = ($date === '') ? NULL : $date;

        $sql = $this->base_cte() . "
            SELECT COUNT(*) AS c FROM ledger
             WHERE (? = 'semua' OR jenis = ?)
               AND (? IS NULL OR DATE(created_at) = ?)";

        $r = $this->row($sql, array($user_id, $jenis, $jenis, $date, $date));
        return (int) $r['c'];
    }

    /**
     * CTE bersama: klasifikasi jenis dari pola reference_id, dan saldo
     * berjalan (total seluruh rekening simpanan anggota) via SUM() OVER,
     * diurutkan ASC supaya akumulasinya kronologis lalu dibaca DESC di luar.
     */
    private function base_cte() {
        return "
            WITH ledger AS (
                SELECT
                    st.id, st.created_at, st.type, st.amount, st.reference_id,
                    CASE
                        WHEN st.reference_id LIKE 'cicilan\\_%' THEN 'pinjaman'
                        WHEN st.reference_id LIKE 'gold\\_%'    THEN 'emas'
                        ELSE 'simpanan'
                    END AS jenis,
                    SUM(CASE WHEN st.type = 'deposit' THEN st.amount ELSE -st.amount END)
                        OVER (ORDER BY st.created_at ASC, st.id ASC) AS saldo
                FROM savings_transactions st
                JOIN savings_accounts sa ON sa.id = st.savings_account_id
                WHERE sa.user_id = ?
            )";
    }

    private function shape(array $r) {
        $r['id']          = (int) $r['id'];
        $r['amount']       = Money::out($r['amount']);
        $r['saldo']        = Money::out($r['saldo']);
        $r['direction']    = $r['type'] === 'deposit' ? 'in' : 'out';
        $r['description']  = $this->describe($r['jenis'], $r['type'], $r['reference_id']);
        unset($r['reference_id']);
        return $r;
    }

    private function describe($jenis, $type, $reference_id) {
        if ($jenis === 'pinjaman') {
            return 'Cicilan Pinjaman';
        }
        if ($jenis === 'emas') {
            if (strpos($reference_id, 'gold_buy_') === 0)    { return 'Beli Emas Digital'; }
            if (strpos($reference_id, 'gold_sell_') === 0)   { return 'Jual Emas Digital'; }
            if (strpos($reference_id, 'gold_refund_') === 0) { return 'Refund Transaksi Emas'; }
            return 'Transaksi Emas Digital';
        }
        return $type === 'deposit' ? 'Setor Simpanan' : 'Tarik Simpanan';
    }
}
