<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Health check untuk load balancer (§17.2). Tanpa auth.
 */
class Health extends API_Controller {

    public function index() {
        $services = array();
        $status   = 'ok';
        $http     = 200;

        try {
            if ($this->db->query('SELECT 1') === FALSE) {
                throw new RuntimeException(json_encode($this->db->error()));
            }
            $services['database'] = 'ok';
        } catch (Throwable $e) {
            $services['database'] = 'unreachable';
            log_message('error', '[health] database: ' . $e->getMessage());
            $status = 'degraded';
            $http   = 503;
        }

        // Tidak ada di versi Go, tapi layak: login, logout, OTP, dan antrian
        // emas semuanya bergantung pada Redis.
        try {
            $this->redisx->ping();
            $services['redis'] = 'ok';
        } catch (Throwable $e) {
            $services['redis'] = 'unreachable';
            log_message('error', '[health] redis: ' . $e->getMessage());
            $status = 'degraded';
            $http   = 503;
        }

        return $this->ok(array(
            'status'    => $status,
            'timestamp' => gmdate('c'),
            'services'  => $services,
        ), $http);
    }
}
