<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Repository KYC (§12).
 * PK = user_id (1:1 dengan users, ON DELETE CASCADE).
 */
class User_profile_model extends MY_Model {

    const COLS = 'user_id, nik, phone_number, address, job_title, monthly_income,
                  emergency_contact_name, emergency_contact_phone, created_at, updated_at';

    public function find($user_id) {
        $p = $this->row("SELECT " . self::COLS . " FROM user_profiles WHERE user_id = ? LIMIT 1", array($user_id));
        if ($p === NULL) { return NULL; }

        $p['user_id']        = (int) $p['user_id'];
        $p['monthly_income'] = Money::out($p['monthly_income']);
        return $p;
    }

    /**
     * Upsert — satu endpoint melayani create dan update.
     * PostgreSQL: ON CONFLICT (user_id) DO UPDATE SET x = EXCLUDED.x
     * MySQL 8   : ON DUPLICATE KEY UPDATE x = VALUES(x)              (§9.4)
     */
    public function upsert($user_id, array $p) {
        $sql = "INSERT INTO user_profiles
                  (user_id, nik, phone_number, address, job_title,
                   monthly_income, emergency_contact_name, emergency_contact_phone)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  nik                     = VALUES(nik),
                  phone_number            = VALUES(phone_number),
                  address                 = VALUES(address),
                  job_title               = VALUES(job_title),
                  monthly_income          = VALUES(monthly_income),
                  emergency_contact_name  = VALUES(emergency_contact_name),
                  emergency_contact_phone = VALUES(emergency_contact_phone),
                  updated_at              = NOW()";

        $ok = $this->db->query($sql, array(
            $user_id, $p['nik'], $p['phone_number'], $p['address'], $p['job_title'],
            $p['monthly_income'], $p['emergency_contact_name'], $p['emergency_contact_phone'],
        ));

        if ($ok === FALSE) {
            // NIK bersifat UNIQUE. Kode Go membiarkan kasus ini jatuh ke 500;
            // 409 jauh lebih berguna bagi frontend.
            if ($this->is_unique_violation()) { throw Api_exception::nikTaken(); }
            log_message('error', '[user_profile_model] upsert gagal: ' . json_encode($this->db->error()));
            throw Api_exception::server();
        }
    }
}
