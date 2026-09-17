<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Serialisasi JSON tunggal untuk seluruh API (§10.4). */
class Api_response {

    /**
     * CI3 set_status_header() hanya mengenal daftar kode tetap; kode di luar
     * daftar (mis. 423 PIN terkunci) tanpa teks alasan memicu show_error()
     * → halaman HTML 500. Teks alasan selalu dikirim eksplisit.
     */
    const REASONS = array(
        200 => 'OK', 201 => 'Created', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        409 => 'Conflict', 410 => 'Gone', 422 => 'Unprocessable Entity', 423 => 'Locked',
        429 => 'Too Many Requests', 500 => 'Internal Server Error', 503 => 'Service Unavailable',
    );

    public function send($data, $status = 200) {
        $CI =& get_instance();
        return $CI->output
            ->set_status_header($status, self::REASONS[$status] ?? 'Status')
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
