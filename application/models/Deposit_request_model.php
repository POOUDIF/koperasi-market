<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Antrian verifikasi setoran (§13.3 – §13.5).
 *
 * Endpoint anggota HANYA membuat permohonan berstatus `pending`; saldo tidak
 * berubah sampai admin menyetujuinya lewat review_deposit().
 */
class Deposit_request_model extends MY_Model {

    const COLS = 'id, user_id, savings_account_id, amount, payment_method, proof_image_url,
                  reference_id, status, reviewed_by, reviewed_at, created_at, updated_at';

    public function insert(array $d) {
        $ok = $this->db->query(
            "INSERT INTO deposit_requests
               (user_id, savings_account_id, amount, payment_method, proof_image_url, reference_id, status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')",
            array($d['user_id'], $d['savings_account_id'], $d['amount'],
                  $d['payment_method'], $d['proof_image_url'], $d['reference_id']));

        if ($ok === FALSE) {
            log_message('error', '[deposit_request_model] insert gagal: ' . json_encode($this->db->error()));
            throw Api_exception::server();
        }

        return $this->find((int) $this->db->insert_id());
    }

    public function find($id) {
        $r = $this->row("SELECT " . self::COLS . " FROM deposit_requests WHERE id = ? LIMIT 1", array($id));
        return $r === NULL ? NULL : $this->shape($r);
    }

    public function get_by_user_paged($user_id, $limit, $offset) {
        $rows = $this->q(
            "SELECT " . self::COLS . " FROM deposit_requests
              WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            array($user_id, (int) $limit, (int) $offset))->result_array();

        return array_map(array($this, 'shape'), $rows);
    }

    public function count_by_user($user_id) {
        $r = $this->row("SELECT COUNT(*) AS c FROM deposit_requests WHERE user_id = ?", array($user_id));
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
            "SELECT " . self::COLS . " FROM deposit_requests" . $where .
            " ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?", $binds)->result_array();

        return array_map(array($this, 'shape'), $rows);
    }

    public function count_all($status = NULL) {
        if ($status !== NULL && $status !== '') {
            $r = $this->row("SELECT COUNT(*) AS c FROM deposit_requests WHERE status = ?", array($status));
        } else {
            $r = $this->row("SELECT COUNT(*) AS c FROM deposit_requests");
        }
        return (int) $r['c'];
    }

    /**
     * Verifikasi setoran oleh admin — SATU transaction utuh.
     *
     * Perbaikan CACAT-04: versi Go memakai DUA transaction terpisah (tambah
     * saldo, lalu tandai approved). Bila proses mati di antaranya, saldo sudah
     * bertambah tapi permohonan tetap `pending` — admin bisa meng-approve lagi
     * dan saldo bertambah dua kali. Di sini keduanya menyatu, dan baris
     * permohonan dikunci FOR UPDATE supaya dua admin tidak bisa memproses
     * permohonan yang sama secara bersamaan.
     *
     * @param string $action 'approve' | 'reject'
     */
    public function review($admin_id, $request_id, $action) {
        return $this->atomic(function () use ($admin_id, $request_id, $action) {

            // 1. Kunci baris permohonan, lalu validasi statusnya.
            $req = $this->row(
                "SELECT id, savings_account_id, amount, status, reference_id
                   FROM deposit_requests WHERE id = ? FOR UPDATE", array($request_id));

            if ($req === NULL)                throw Api_exception::depositRequestNotFound();
            if ($req['status'] !== 'pending') throw Api_exception::depositAlreadyReviewed();

            if ($action === 'reject') {
                $this->q(
                    "UPDATE deposit_requests
                        SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
                      WHERE id = ?", array($admin_id, $request_id));

                return 'rejected';
            }

            // 2. Kunci rekening tujuan & pastikan masih aktif.
            $this->load->model('Saving_model');
            $this->Saving_model->lock_account((int) $req['savings_account_id']);

            // 3. Tambah saldo + 4. catat di buku besar.
            $this->Saving_model->credit((int) $req['savings_account_id'], $req['amount']);
            $this->Saving_model->ledger((int) $req['savings_account_id'], 'deposit',
                $req['amount'], (string) $req['reference_id']);

            // 5. Tandai approved — DALAM TRANSACTION YANG SAMA.
            $this->q(
                "UPDATE deposit_requests
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
