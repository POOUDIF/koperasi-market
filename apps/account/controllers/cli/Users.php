<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 *   php account.php cli/users migrate [--dry-run]   impor user koperasi lama (§7)
 *   php account.php cli/users promote <email>       jadikan admin platform
 *
 * Migrasi mempertahankan id (= sub) dan hash bcrypt sehingga anggota lama
 * tidak perlu reset kata sandi. Idempoten: aman dijalankan berulang.
 * Sumber: DB koperasi (MIGRATE_SOURCE_DB_NAME, default koperasi_digital) di
 * server & kredensial yang sama kecuali MIGRATE_SOURCE_DB_* diisi.
 *
 * Setelah ini jalankan di koperasi: php index.php cli/sso link_users
 */
class Users extends CI_Controller {

    public function __construct() {
        parent::__construct();
        if ( ! is_cli()) { show_404(); }
        $this->db->query("SET SESSION time_zone = '+07:00'");
    }

    public function migrate($flag = '') {
        $dry = ($flag === '--dry-run');

        $src = $this->load->database(array(
            'hostname' => env('MIGRATE_SOURCE_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port'     => (int) env('MIGRATE_SOURCE_DB_PORT', env('DB_PORT', 3306)),
            'username' => env('MIGRATE_SOURCE_DB_USER', env('DB_USER', 'root')),
            'password' => (string) env('MIGRATE_SOURCE_DB_PASSWORD', env('DB_PASSWORD', '')),
            'database' => env('MIGRATE_SOURCE_DB_NAME', 'koperasi_digital'),
            'dbdriver' => 'mysqli',
            'char_set' => 'utf8mb4',
            'dbcollat' => 'utf8mb4_unicode_ci',
            'db_debug' => FALSE,
            'pconnect' => FALSE,
        ), TRUE);
        $src->query("SET SESSION time_zone = '+07:00'");

        $rows = $src->query(
            "SELECT id, nama_lengkap, email, password_hash, is_email_verified, status, role, created_at
               FROM users
              WHERE password_hash IS NOT NULL AND password_hash <> ''
              ORDER BY id")->result_array();

        $stat = array('baru' => 0, 'diperbarui' => 0, 'konflik' => 0);

        foreach ($rows as $r) {
            $email = strtolower(trim($r['email']));
            $clash = $this->db->query("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1", array($email, $r['id']))->row_array();
            if ($clash) {
                fwrite(STDERR, "KONFLIK id={$r['id']} email={$email} sudah dipakai id={$clash['id']} di IdP — lewati" . PHP_EOL);
                $stat['konflik']++;
                continue;
            }

            $exists = (bool) $this->db->query("SELECT 1 FROM users WHERE id = ?", array($r['id']))->row_array();
            $stat[$exists ? 'diperbarui' : 'baru']++;
            if ($dry) { continue; }

            // Status koperasi 'banned' dipertahankan sebagai blokir global; 'inactive'
            // tetap ditegakkan lokal oleh koperasi saja.
            $ok = $this->db->query(
                "INSERT INTO users (id, name, email, password_hash, email_verified_at, status, is_platform_admin, password_changed_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   name = VALUES(name), email = VALUES(email), password_hash = VALUES(password_hash),
                   email_verified_at = VALUES(email_verified_at), status = VALUES(status),
                   is_platform_admin = VALUES(is_platform_admin)",
                array((int) $r['id'], $r['nama_lengkap'], $email, $r['password_hash'],
                      ((int) $r['is_email_verified'] === 1) ? $r['created_at'] : NULL,
                      $r['status'] === 'banned' ? 'banned' : 'active',
                      $r['role'] === 'super_admin' ? 1 : 0,
                      $r['created_at'], $r['created_at']));
            if ($ok === FALSE) {
                fwrite(STDERR, "GAGAL id={$r['id']}: " . json_encode($this->db->error()) . PHP_EOL);
                exit(1);
            }
        }

        if ( ! $dry && ! empty($rows)) {
            // sub user baru di IdP tidak boleh bertabrakan dengan id lama koperasi.
            $max = (int) $src->query("SELECT COALESCE(MAX(id), 0) AS m FROM users")->row_array()['m'];
            $cur = (int) $this->db->query("SELECT COALESCE(MAX(id), 0) AS m FROM users")->row_array()['m'];
            $this->db->query('ALTER TABLE users AUTO_INCREMENT = ' . (max($max, $cur) + 1));
        }

        echo ($dry ? '[DRY-RUN] ' : '') . "sumber=" . count($rows)
            . " baru={$stat['baru']} diperbarui={$stat['diperbarui']} konflik={$stat['konflik']}" . PHP_EOL;
        exit($stat['konflik'] > 0 ? 2 : 0);
    }

    public function promote($email = '') {
        $this->db->query("UPDATE users SET is_platform_admin = 1 WHERE email = ?", array(strtolower(trim($email))));
        if ($this->db->affected_rows() < 1) {
            fwrite(STDERR, "akun {$email} tidak ditemukan atau sudah admin" . PHP_EOL);
            exit(1);
        }
        echo "OK {$email} kini admin platform" . PHP_EOL;
    }
}
