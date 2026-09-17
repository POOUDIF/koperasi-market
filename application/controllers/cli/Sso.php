<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 *   php index.php cli/sso link_users [--dry-run]
 *
 * Langkah 4 migrasi (§7): tautkan user lama koperasi ke akun JDC dengan
 * sso_sub = id. Jalankan SETELAH `php account.php cli/users migrate`
 * (yang mengimpor user dengan id yang sama). Idempoten.
 */
class Sso extends CI_Controller {

    public function __construct() {
        parent::__construct();
        if ( ! is_cli()) { show_404(); }
    }

    public function link_users($flag = '') {
        $where = "sso_sub IS NULL AND password_hash IS NOT NULL AND password_hash <> '' AND email NOT LIKE '%@system.internal'";
        $count = (int) $this->db->query("SELECT COUNT(*) AS c FROM users WHERE {$where}")->row_array()['c'];

        if ($flag === '--dry-run') {
            echo "[DRY-RUN] {$count} user akan ditautkan (sso_sub = id)" . PHP_EOL;
            return;
        }

        $this->db->query("UPDATE users SET sso_sub = CAST(id AS CHAR) WHERE {$where}");
        echo "OK {$this->db->affected_rows()} user ditautkan" . PHP_EOL;
    }
}
