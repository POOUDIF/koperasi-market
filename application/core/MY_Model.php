<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Helper bersama seluruh model: transaction, deteksi error driver,
 * normalisasi tipe MySQL. (§10.9, §10.10, §19.2)
 */
class MY_Model extends CI_Model {

    /**
     * Template transaksi atomik — padanan:
     *   tx, _ := db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
     *   defer tx.Rollback()
     *   ...
     *   tx.Commit()
     *
     * PHP tidak punya `defer`, jadi rollback dijamin lewat catch. Tanpa ini,
     * satu throw yang lolos akan meninggalkan transaction terbuka dan baris
     * rekening TERKUNCI oleh FOR UPDATE sampai koneksi ditutup (§19.6).
     *
     * @param  callable $fn body transaction; nilai kembaliannya diteruskan
     * @return mixed
     */
    protected function atomic(callable $fn) {
        // trans_strict(FALSE): satu transaction gagal tidak mematikan
        // transaction berikutnya di request yang sama.
        $this->db->trans_strict(FALSE);
        $this->db->trans_begin();

        try {
            $result = $fn();

            if ($this->db->trans_status() === FALSE) {
                $this->db->trans_rollback();
                log_message('error', '[db] transaction gagal: ' . json_encode($this->db->error()));
                throw Api_exception::server();
            }

            $this->db->trans_commit();
            return $result;

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            throw $e;
        }
    }

    /**
     * Jalankan query; FALSE (dengan db_debug=FALSE) berarti error driver.
     * @throws Api_exception 500 setelah mencatat detail ke log.
     */
    protected function q($sql, array $binds = array()) {
        $res = $this->db->query($sql, $binds);
        if ($res === FALSE) {
            log_message('error', '[db] query gagal: ' . json_encode($this->db->error()) . ' | SQL: ' . $sql);
            throw Api_exception::server();
        }
        return $res;
    }

    /** Baris pertama sebagai array asosiatif, atau NULL. */
    protected function row($sql, array $binds = array()) {
        $r = $this->q($sql, $binds)->row_array();
        return $r ? $r : NULL;
    }

    /** @return bool TRUE jika error terakhir adalah pelanggaran unique constraint (§10.10). */
    protected function is_unique_violation() {
        $e = $this->db->error();
        return in_array((string) $e['code'], array('23505', '1062'), TRUE)
            || stripos($e['message'], 'duplicate key')   !== FALSE
            || stripos($e['message'], 'duplicate entry') !== FALSE;
    }

    /**
     * PostgreSQL mengembalikan boolean sebagai 't'/'f', MySQL sebagai 1/0 —
     * dan `if ($row['is_email_verified'])` bernilai TRUE untuk string 'f'.
     * Ini sumber bug klasik saat port (§19.2).
     */
    public function truthy($v) {
        return $v === TRUE || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
    }
}
