<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Serialisasi JSON tunggal untuk seluruh API (§10.4). */
class Api_response {

    public function send($data, $status = 200) {
        $CI =& get_instance();
        return $CI->output
            ->set_status_header($status)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
