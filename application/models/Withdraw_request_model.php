<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Antrian verifikasi penarikan (CACAT-12) — pola identik dengan
 * Deposit_request_model, dicerminkan sengaja agar konsisten dengan §13.3-13.5.
 *
 * Endpoint anggota HANYA membuat permohonan berstatus `pending`; saldo tidak
 * berubah sampai admin menyetujuinya lewat review().
 */
class Withdraw_request_model extends MY_Model {

    const COLS = 'id, user_id, savings_account_id, amount, destination_account,
                  reference_id, status, reviewed_by, reviewed_at, created_at, updated_at';

    public function insert(array $d) {
        $ok = $this->db->query(
            "INSERT INTO withdraw_requests
               (user_id, savings_account_id, amount, destination_account, reference_id, status)
             VALUES (?, ?, ?, ?, ?, 'pending')",
            array($d['user_id'], $d['savings_account_id'], $d['amount'],
                  $d['destination_account'], $d['reference_id']));

        if ($ok === FALSE) {
            log_message('error', '[withdraw_request_model] insert gagal: ' . json_encode($this->db->error()));
            throw Api_exception::server();
        }

        return $this->find((int) $this->db->insert_id());
    }

    public function find($id) {
        $r = $this->row("SELECT " . self::COLS . " FROM withdraw_requests WHERE id = ? LIMIT 1", array($id));
        return $r === NULL ? NULL : $this->shape($r);
    }

    public function get_by_user_paged($user_id, $limit, $offset) {
        $rows = $this->q(
            "SELECT " . self::COLS . " FROM withdraw_requests
              WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            array($user_id, (int) $limit, (int) $offset))->result_array();

        return array_map(array($this, 'shape'), $rows);
    }

    public function count_by_user($user_id) {
        $r = $this->row("SELECT COUNT(*) AS c FROM withdraw_requests WHERE user_id = ?", array($user_id));
        return (int) $r['c'];
    }

    public function get_all_paged($limit, $offset, $status = NULL) {
        $where = '';
        $binds = array();

        if ($status !== NULL && $status !== '') {
            $where   = ' WHERE status = ?';
            $binds[] = $status;
        }
        $binds[] = (int) $limit;
        $binds[] = (int) $offset;

        $rows = $this->q(
            "SELECT " . self::COLS . " FROM withdraw_requests" . $where .
            " ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?", $binds)->result_array();

        return array_map(array($this, 'shape'), $rows);
    }

    public function count_all($status = NULL) {
        if ($status !== NULL && $status !== '') {
            $r = $this->row("SELECT COUNT(*) AS c FROM withdraw_requests WHERE status = ?", array($status));
        } else {
            $r = $this->row("SELECT COUNT(*) AS c FROM withdraw_requests");
        }
        return (int) $r['c'];
    }

    /**
     * Verifikasi penarikan oleh admin — SATU transaction utuh, sama seperti
     * Deposit_request_model::review() (perbaikan CACAT-04).
     *
     * Berbeda dari deposit: saldo di sini BERKURANG, jadi kecukupan saldo
     * WAJIB divalidasi ULANG di sini, SETELAH baris rekening terkunci —
     * bukan hanya percaya pengecekan lunak yang dilakukan saat pengajuan
     * (Saving_service::request_withdraw()). Saldo bisa sudah berubah di
     * antara pengajuan dan persetujuan, mis. penarikan lain sudah disetujui
     * lebih dulu (pelajaran yang sama dengan perbaikan CACAT-01, §2.1 aturan 3).
     *
     * @param string $action 'approve' | 'reject'
     */
    public function review($admin_id, $request_id, $action) {
        return $this->atomic(function () use ($admin_id, $request_id, $action) {

            $req = $this->row(
                "SELECT id, savings_account_id, amount, status, reference_id
                   FROM withdraw_requests WHERE id = ? FOR UPDATE", array($request_id));

            if ($req === NULL)                throw Api_exception::withdrawRequestNotFound();
            if ($req['status'] !== 'pending') throw Api_exception::withdrawAlreadyReviewed();

            if ($action === 'reject') {
                $this->q(
                    "UPDATE withdraw_requests
                        SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
                      WHERE id = ?", array($admin_id, $request_id));

                return 'rejected';
            }

            $this->load->model('Saving_model');
            $acc = $this->Saving_model->lock_account((int) $req['savings_account_id']);

            if (Money::lt($acc['balance'], $req['amount'])) {
                throw Api_exception::insufficientBalance();
            }

            $this->Saving_model->debit((int) $req['savings_account_id'], $req['amount']);
            $this->Saving_model->ledger((int) $req['savings_account_id'], 'withdraw',
                $req['amount'], (string) $req['reference_id']);

            $this->q(
                "UPDATE withdraw_requests
                    SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
                  WHERE id = ?", array($admin_id, $request_id));

            return 'approved';
        });
    }

    private function shape(array $r) {
        $r['id']                 = (int) $r['id'];
        $r['user_id']            = (int) $r['user_id'];
        $r['savings_account_id'] = (int) $r['savings_account_id'];
        $r['amount']             = Money::out($r['amount']);
        $r['reviewed_by']        = $r['reviewed_by'] === NULL ? NULL : (int) $r['reviewed_by'];
        return $r;
    }
}
