<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Tanda tangan webhook antar layanan (koperasi → market).
 *
 *   X-JDC-Timestamp: <unix detik>
 *   X-JDC-Signature: v1=<hex HMAC-SHA256(secret, "<timestamp>.<raw body>")>
 *
 * Timestamp ikut ditandatangani sehingga payload lama tidak bisa diputar
 * ulang di luar jendela toleransi; deduplikasi per event id tetap wajib di
 * sisi penerima karena retry resmi juga mengirim ulang event yang sama.
 */
class Webhook_signature {

    const TOLERANCE_SECONDS = 300;

    public function sign($secret, $timestamp, $raw_body) {
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $raw_body, (string) $secret);
    }

    public function verify($secret, $timestamp_header, $signature_header, $raw_body) {
        if ((string) $secret === '' || ! ctype_digit((string) $timestamp_header)) {
            return FALSE;
        }
        if (abs(time() - (int) $timestamp_header) > self::TOLERANCE_SECONDS) {
            return FALSE;
        }
        $expected = $this->sign($secret, $timestamp_header, $raw_body);
        return hash_equals($expected, (string) $signature_header);
    }
}
