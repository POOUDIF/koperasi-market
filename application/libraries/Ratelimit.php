<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Rate limit berbasis Redis (§10.7).
 *
 * Go memakai token bucket in-memory per proses. PHP-FPM punya banyak proses,
 * jadi state harus terpusat di Redis — kalau tidak, batasnya terkalikan
 * sebanyak jumlah worker FPM.
 */
class Ratelimit {

    /** Maksimum $burst request per $window detik, per IP per bucket. */
    public function check($bucket, $burst = NULL, $window = 1) {
        $CI =& get_instance();

        $burst = ($burst === NULL) ? (int) $CI->config->item('rate_limit_burst') : (int) $burst;
        $ip    = $CI->input->ip_address();
        $key   = sprintf('rl:%s:%s:%d', $bucket, $ip, (int) floor(time() / $window));

        try {
            $hits = (int) $CI->redisx->incr($key);
            if ($hits === 1) { $CI->redisx->expire($key, $window + 1); }
        } catch (Throwable $e) {
            // fail-open: jangan matikan login hanya karena Redis tumbang.
            log_message('error', '[ratelimit] Redis gagal, request diloloskan: ' . $e->getMessage());
            return;
        }

        if ($hits > $burst) {
            throw Api_exception::tooManyRequests();
        }
    }
}
