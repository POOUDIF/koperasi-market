<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Health extends API_Controller {

    public function index() {
        $services = array();
        $http = 200;

        try {
            if ($this->db->query('SELECT 1') === FALSE) { throw new RuntimeException('query gagal'); }
            $services['database'] = 'ok';
        } catch (Throwable $e) {
            $services['database'] = 'unreachable';
            $http = 503;
        }
        try {
            $this->redisx->ping();
            $services['redis'] = 'ok';
        } catch (Throwable $e) {
            // Tanpa Redis tidak ada sesi login.
            $services['redis'] = 'unreachable';
            $http = 503;
        }

        return $this->ok(array(
            'status'    => $http === 200 ? 'ok' : 'degraded',
            'service'   => 'market',
            'timestamp' => gmdate('c'),
            'services'  => $services,
        ), $http);
    }
}
