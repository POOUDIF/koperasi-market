<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Repository simpanan: produk, rekening, dan buku besar (§13).
 *
 * Aturan mutlak (§2.1 no.3): saldo HANYA boleh berubah di dalam transaction
 * yang sudah memegang `SELECT ... FOR UPDATE` atas baris rekeningnya.
 */
class Saving_model extends MY_Model {

    /* ------------------------------------------------------------ produk */

    public function find_product($id) {
        return $this->row(
            "SELECT id, name, akad_type, min_deposit, profit_sharing_ratio, is_mandatory
               FROM savings_products WHERE id = ? LIMIT 1", array($id));
    }

    public function get_products() {
        $rows = $this->q(
            "SELECT id, name, akad_type, min_deposit, profit_sharing_ratio, is_mandatory
               FROM savings_products ORDER BY id ASC")->result_array();

        return array_map(function ($p) {
            return array(
                'id'                   => (int) $p['id'],
                'name'                 => $p['name'],
                'akad_type'            => $p['akad_type'],
                'min_deposit'          => Money::out($p['min_deposit']),
                'profit_sharing_ratio' => Money::out($p['profit_sharing_ratio']),
                'is_mandatory'         => $this->truthy($p['is_mandatory']),
            );
        }, $rows);
    }

    /** Produk yang memicu pembukaan rekening otomatis saat registrasi. */
    public function get_mandatory_products() {
        return $this->q("SELECT id FROM savings_products WHERE is_mandatory = 1 ORDER BY id ASC")->result_array();
    }

    /* ---------------------------------------------------------- rekening */

    public function create_account($user_id, $product_id) {
        $ok = $this->db->query(
            "INSERT INTO savings_accounts (user_id, savings_product_id, balance, status)
             VALUES (?, ?, 0, 'active')", array($user_id, $product_id));

        if ($ok === FALSE) {
            log_message('error', '[saving_model] create_account gagal: ' . json_encode($this->db->error()));
            throw Api_exception::server();
        }

        return $this->get_account_by_id((int) $this->db->insert_id());
    }

    public function get_account_by_id($id) {
        $a = $this->row(
            "SELECT id, user_id, savings_product_id, balance, status, created_at, updated_at
               FROM savings_accounts WHERE id = ? LIMIT 1", array($id));

        return $a === NULL ? NULL : $this->shape_account($a);
    }

    public function get_accounts_by_user($user_id) {
        $rows = $this->q(
            "SELECT a.id, a.user_id, a.savings_product_id, a.balance, a.status,
                    a.created_at, a.updated_at,
                    p.name AS product_name, p.akad_type
               FROM savings_accounts a
               JOIN savings_products p ON p.id = a.savings_product_id
              WHERE a.user_id = ?
              ORDER BY a.created_at DESC, a.id DESC", array($user_id))->result_array();

        return array_map(array($this, 'shape_account'), $rows);
    }

    /* -------------------------------------------------------- buku besar */

    public function get_transactions_paged($limit, $offset) {
        $rows = $this->q(
            "SELECT id, savings_account_id, type, amount, reference_id, created_at
               FROM savings_transactions
              ORDER BY created_at DESC, id DESC
              LIMIT ? OFFSET ?", array((int) $limit, (int) $offset))->result_array();

        return array_map(function ($t) {
            $t['id']                 = (int) $t['id'];
            $t['savings_account_id'] = (int) $t['savings_account_id'];
            $t['amount']             = Money::out($t['amount']);
            return $t;
        }, $rows);
    }

    public function count_transactions() {
        $r = $this->row("SELECT COUNT(*) AS c FROM savings_transactions");
        return (int) $r['c'];
    }

    /* ------------------------------------------------ helper transaction */

    /**
     * Kunci baris rekening dan validasi kepemilikan + status dalam satu langkah.
     * WAJIB dipanggil di dalam transaction yang sedang berjalan.
     *
     * Urutannya penting (§10.9 aturan 3): membaca status SEBELUM mengunci
     * berarti membaca data basi — dua request paralel bisa sama-sama lolos.
     *
     * Kepemilikan yang salah dikembalikan sebagai 404, bukan 403, agar ID
     * rekening milik anggota lain tidak bisa dienumerasi (§2.1 aturan 4).
     */
    public function lock_account($account_id, $user_id = NULL) {
        $acc = $this->row(
            "SELECT id, user_id, balance, status FROM savings_accounts WHERE id = ? FOR UPDATE",
            array($account_id));

        if ($acc === NULL)                                          throw Api_exception::savingsAccountNotFound();
        if ($user_id !== NULL && (int) $acc['user_id'] !== (int) $user_id) throw Api_exception::savingsAccountNotFound();
        if ($acc['status'] !== 'active')                            throw Api_exception::accountNotActive();

        return $acc;
    }

    /**
     * Ekspresi relatif (balance ± ?), bukan nilai absolut yang dihitung di PHP
     * (§10.9 aturan 4) — DB yang menghitung, sehingga tidak ada lost update.
     */
    public function credit($account_id, $amount) {
        $this->q("UPDATE savings_accounts SET balance = balance + ?, updated_at = NOW() WHERE id = ?",
            array($amount, $account_id));
    }

    public function debit($account_id, $amount) {
        $this->q("UPDATE savings_accounts SET balance = balance - ?, updated_at = NOW() WHERE id = ?",
            array($amount, $account_id));
    }

    /** Buku besar append-only; `amount` selalu positif, arah ada di `type`. */
    public function ledger($account_id, $type, $amount, $reference_id) {
        $this->q(
            "INSERT INTO savings_transactions (savings_account_id, type, amount, reference_id)
             VALUES (?, ?, ?, ?)",
            array($account_id, $type, $amount, (string) $reference_id));
    }

    private function shape_account(array $a) {
        $a['id']                 = (int) $a['id'];
        $a['user_id']            = (int) $a['user_id'];
        $a['savings_product_id'] = (int) $a['savings_product_id'];
        $a['balance']            = Money::out($a['balance']);
        return $a;
    }
}
