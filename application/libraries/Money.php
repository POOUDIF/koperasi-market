<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Aritmetika uang & gram dengan bcmath (§10.1, §19.1).
 *
 * ATURAN: seluruh nilai uang diperlakukan sebagai STRING desimal sampai detik
 * terakhir sebelum di-encode ke JSON. Jangan pernah menghitung dengan float —
 * `0.1 + 0.2 !== 0.3` menghasilkan selisih yang, pada perbandingan saldo,
 * berarti anggota bisa menarik uang yang tidak dia punya.
 */
class Money {

    const SCALE = 4;

    /** Normalisasi ke 4 desimal, sekaligus memvalidasi bahwa nilainya numerik. */
    public static function norm($v) {
        $v = self::clean($v);
        return bcadd($v, '0', self::SCALE);
    }

    public static function add($a, $b) { return bcadd(self::clean($a), self::clean($b), self::SCALE); }
    public static function sub($a, $b) { return bcsub(self::clean($a), self::clean($b), self::SCALE); }
    public static function mul($a, $b) { return bcmul(self::clean($a), self::clean($b), self::SCALE); }
    public static function div($a, $b) { return bcdiv(self::clean($a), self::clean($b), self::SCALE); }
    public static function cmp($a, $b) { return bccomp(self::clean($a), self::clean($b), self::SCALE); }

    public static function gt($a, $b)  { return self::cmp($a, $b) === 1; }
    public static function lt($a, $b)  { return self::cmp($a, $b) === -1; }
    public static function gte($a, $b) { return self::cmp($a, $b) >= 0; }
    public static function lte($a, $b) { return self::cmp($a, $b) <= 0; }

    /** Untuk response JSON: kirim sebagai number agar kompatibel frontend lama (§19.3). */
    public static function out($v) { return (float) self::norm($v); }

    /**
     * bcmath PHP 7.4 melempar warning untuk input non-numerik dan diam-diam
     * memperlakukannya sebagai 0. Kita tolak lebih awal, dan tangani notasi
     * ilmiah (1.0E-5) yang muncul saat JSON men-decode angka kecil ke float.
     */
    private static function clean($v) {
        if (is_bool($v) || $v === NULL || $v === '') { return '0'; }

        if (is_float($v) || is_int($v)) {
            $v = number_format((float) $v, self::SCALE + 2, '.', '');
        }
        $v = trim((string) $v);

        if ( ! is_numeric($v)) {
            throw Api_exception::badRequest('nilai numerik tidak valid: ' . $v);
        }
        if (stripos($v, 'e') !== FALSE) {
            $v = number_format((float) $v, self::SCALE + 2, '.', '');
        }
        return $v;
    }
}
