<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Repository pembiayaan murabahah + jadwal angsuran (§14).
 */
class Financing_model extends MY_Model {

    const COLS = 'id, financing_number, user_id, akad, principal_amount, margin_amount,
                  total_payable, duration_months, status, reviewed_by, reviewed_at,
                  created_at, updated_at';

    public function create(array $f) {
        $ok = $this->db->query(
            "INSERT INTO financing
               (financing_number, user_id, akad, principal_amount, margin_amount,
                total_payable, duration_months, status)
             VALUES (?, ?, 'murabahah', ?, ?, ?, ?, 'pending')",
            array($f['financing_number'], $f['user_id'], $f['principal_amount'],
                  $f['margin_amount'], $f['total_payable'], $f['duration_months']));

        if ($ok === FALSE) {
            // Bentrok financing_number → service akan mencoba nomor baru.
            if ($this->is_unique_violation()) { throw Api_exception::duplicateFinancingNumber(); }
            log_message('error', '[financing_model] create gagal: ' . json_encode($this->db->error()));
            throw Api_exception::server();
        }

        return $this->find((int) $this->db->insert_id());
    }

    public function find($id) {
        $f = $this->row("SELECT " . self::COLS . " FROM financing WHERE id = ? LIMIT 1", array($id));
        return $f === NULL ? NULL : $this->shape($f);
    }

    public function get_by_user_paged($user_id, $limit, $offset) {
        $rows = $this->q(
            "SELECT " . self::COLS . " FROM financing
              WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            array($user_id, (int) $limit, (int) $offset))->result_array();

        return array_map(array($this, 'shape'), $rows);
    }

    public function count_by_user($user_id) {
        $r = $this->row("SELECT COUNT(*) AS c FROM financing WHERE user_id = ?", array($user_id));
        return (int) $r['c'];
    }

    public function get_all_paged($limit, $offset) {
        $rows = $this->q(
            "SELECT " . self::COLS . " FROM financing ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            array((int) $limit, (int) $offset))->result_array();

        return array_map(array($this, 'shape'), $rows);
    }

    public function count_all() {
        $r = $this->row("SELECT COUNT(*) AS c FROM financing");
        return (int) $r['c'];
    }

    /* ------------------------------------------------------------ review */

    public function reject($financing_id, $admin_id) {
        $this->q(
            "UPDATE financing
                SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
              WHERE id = ? AND status = 'pending'", array($admin_id, $financing_id));

        if ($this->db->affected_rows() === 0) {
            throw Api_exception::financingNotPending();
        }
    }

    /**
     * Approve + generate seluruh jadwal angsuran secara atomik.
     *
     * Perbaikan CACAT-05: klausa `AND status = 'pending'` pada UPDATE plus cek
     * affected_rows. Di versi Go, status diperiksa di service (di LUAR
     * transaction) lalu UPDATE dijalankan tanpa guard — dua admin yang menekan
     * Approve bersamaan bisa menghasilkan DUA set angsuran untuk satu akad.
     */
    public function approve_with_installments($financing_id, $admin_id, array $installments) {
        return $this->atomic(function () use ($financing_id, $admin_id, $installments) {

            $this->q(
                "UPDATE financing
                    SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND status = 'pending'", array($admin_id, $financing_id));

            if ($this->db->affected_rows() === 0) {
                throw Api_exception::financingNotPending();
            }

            foreach ($installments as $ins) {
                $this->q(
                    "INSERT INTO financing_installments
                       (financing_id, installment_number, amount_due, amount_paid, due_date, status)
                     VALUES (?, ?, ?, ?, ?, 'unpaid')",
                    array($financing_id, $ins['installment_number'], $ins['amount_due'],
                          $ins['amount_paid'], $ins['due_date']));
            }

            return count($installments);
        });
    }

    /* --------------------------------------------------------- angsuran */

    public function get_installments($financing_id) {
        $rows = $this->q(
            "SELECT id, financing_id, installment_number, amount_due, amount_paid,
                    due_date, status, paid_at, created_at, updated_at
               FROM financing_installments
              WHERE financing_id = ?
              ORDER BY installment_number ASC", array($financing_id))->result_array();

        return array_map(function ($i) {
            $i['id']                 = (int) $i['id'];
            $i['financing_id']       = (int) $i['financing_id'];
            $i['installment_number'] = (int) $i['installment_number'];
            $i['amount_due']         = Money::out($i['amount_due']);
            $i['amount_paid']        = Money::out($i['amount_paid']);
            return $i;
        }, $rows);
    }

    public function find_installment($id) {
        return $this->row(
            "SELECT id, financing_id, installment_number, amount_due, amount_paid, due_date, status
               FROM financing_installments WHERE id = ? LIMIT 1", array($id));
    }

    /**
     * Bayar satu angsuran penuh (§14.5). Tujuh langkah, semuanya atomik.
     * Nominal diambil dari `amount_due` di DB, tidak pernah dari input user.
     */
    public function pay_installment($installment_id, $financing_id, $amount_due, $account_id, $user_id) {
        return $this->atomic(function () use ($installment_id, $financing_id, $amount_due, $account_id, $user_id) {

            $this->load->model('Saving_model');

            // a. Kunci rekening + validasi kepemilikan & status.
            $acc = $this->Saving_model->lock_account($account_id, $user_id);
            if (Money::lt($acc['balance'], $amount_due)) {
                throw Api_exception::insufficientBalance();
            }

            // b. Debit saldo, c. catat mutasi.
            $this->Saving_model->debit($account_id, $amount_due);
            $this->Saving_model->ledger($account_id, 'withdraw', $amount_due, 'cicilan_' . $installment_id);

            // d. Tandai lunas. `AND status = 'unpaid'` adalah proteksi race:
            //    kalau request lain sudah melunasinya, affected_rows = 0.
            $this->q(
                "UPDATE financing_installments
                    SET status = 'paid', amount_paid = ?, paid_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND financing_id = ? AND status = 'unpaid'",
                array($amount_due, $installment_id, $financing_id));

            if ($this->db->affected_rows() === 0) {
                throw Api_exception::installmentAlreadyPaid();
            }

            // e. Semua angsuran lunas? tutup pembiayaan.
            $left = (int) $this->row(
                "SELECT COUNT(*) AS c FROM financing_installments
                  WHERE financing_id = ? AND status = 'unpaid'", array($financing_id))['c'];

            if ($left === 0) {
                $this->q("UPDATE financing SET status = 'paid', updated_at = NOW() WHERE id = ?",
                    array($financing_id));
            }

            return array('remaining_unpaid' => $left, 'financing_settled' => ($left === 0));
        });
    }

    private function shape(array $f) {
        $f['id']               = (int) $f['id'];
        $f['user_id']          = (int) $f['user_id'];
        $f['principal_amount'] = Money::out($f['principal_amount']);
        $f['margin_amount']    = Money::out($f['margin_amount']);
        $f['total_payable']    = Money::out($f['total_payable']);
        $f['duration_months']  = (int) $f['duration_months'];
        $f['reviewed_by']      = $f['reviewed_by'] === NULL ? NULL : (int) $f['reviewed_by'];
        return $f;
    }
}
