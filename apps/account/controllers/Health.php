<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Health extends Base_Controller {

    /** GET /account/health */
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
            // IdP tetap berfungsi tanpa Redis (hanya rate limit yang fail-open).
            $services['redis'] = 'unreachable';
        }

        try {
            $this->load->library('Token_service');
            $services['signing_keys'] = count($this->token_service->jwks()['keys']) > 0 ? 'ok' : 'missing';
        } catch (Throwable $e) {
            $services['signing_keys'] = 'missing';
            $http = 503;
        }

        $this->json(array(
            'status'    => $http === 200 ? 'ok' : 'degraded',
            'service'   => 'account',
            'timestamp' => gmdate('c'),
            'services'  => $services,
        ), $http);
    }
}
