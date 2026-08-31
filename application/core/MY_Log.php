<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Logging terstruktur (§21 Fase 7) — satu baris JSON per entri, bukan teks
 * bebas `LEVEL - tanggal --> pesan`. CI3 menyediakan _format_line() sebagai
 * titik ekstensi resmi untuk ini (lihat system/core/Log.php) — subclass_prefix
 * 'MY_' (application/config/config.php) membuat file ini otomatis dipakai
 * menggantikan CI_Log tanpa mengubah satu pun pemanggilan log_message()
 * di seluruh kode aplikasi.
 *
 * Format JSON per baris memudahkan ingest ke log aggregator (ELK/Loki/dst)
 * di produksi, dan tetap mudah dibaca manusia lewat `jq` saat development.
 */
class MY_Log extends CI_Log {

    protected function _format_line($level, $date, $message) {
        return json_encode(array(
            'timestamp' => $date,
            'level'     => strtolower($level),
            'message'   => $message,
            'env'       => defined('ENVIRONMENT') ? ENVIRONMENT : NULL,
        ), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
