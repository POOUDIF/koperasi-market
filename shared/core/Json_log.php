<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Logging terstruktur (§21 Fase 7) — satu baris JSON per entri, bukan teks
 * bebas `LEVEL - tanggal --> pesan`. CI3 menyediakan _format_line() sebagai
 * titik ekstensi resmi untuk ini (lihat system/core/Log.php).
 *
 * Dipakai semua layanan: tiap aplikasi punya core/MY_Log.php satu baris yang
 * me-require file ini, karena CI3 hanya mencari MY_Log di APPPATH/core.
 * Kolom `service` membedakan baris log koperasi / account / market saat
 * log dikumpulkan ke satu tempat.
 */
class MY_Log extends CI_Log {

    protected function _format_line($level, $date, $message) {
        return json_encode(array(
            'timestamp' => $date,
            'level'     => strtolower($level),
            'service'   => defined('JDC_SERVICE') ? JDC_SERVICE : NULL,
            'message'   => $message,
            'env'       => defined('ENVIRONMENT') ? ENVIRONMENT : NULL,
        ), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
