<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Klien API internal Koperasi (§4.2). Autentikasi dengan access token
 * client_credentials dari JDC Account — tidak pernah dengan sesi pengguna.
 */
class Koperasi_client {

    private $CI;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->library(array('Oidc_client', 'Http_client', 'Redisx'));
    }

    /**
     * Status keanggotaan (cache singkat di Redis — keputusan uang tetap
     * divalidasi ulang oleh koperasi saat membuat tagihan).
     * @return array {is_member, kyc_completed, ...}
     */
    public function member($sub, $fresh = FALSE) {
        $key = 'koperasi:member:' . $sub;
        if ( ! $fresh) {
            try {
                $v = $this->CI->redisx->get($key);
                if ($v !== NULL) { return json_decode($v, TRUE); }
            } catch (Throwable $e) {}
        }

        $data = $this->call('GET', '/members/' . rawurlencode($sub));
        try {
            $this->CI->redisx->setex($key, (int) $this->CI->config->item('member_cache_ttl'), json_encode($data));
        } catch (Throwable $e) {}
        return $data;
    }

    public function create_intent(array $in, $idempotency_key) {
        return $this->call('POST', '/payment-intents', $in, array('Idempotency-Key: ' . $idempotency_key));
    }

    public function get_intent($id)    { return $this->call('GET',  '/payment-intents/' . rawurlencode($id)); }
    public function settle($id)        { return $this->call('POST', '/payment-intents/' . rawurlencode($id) . '/settle'); }
    public function refund($id)        { return $this->call('POST', '/payment-intents/' . rawurlencode($id) . '/refund'); }
    public function cancel_intent($id) { return $this->call('POST', '/payment-intents/' . rawurlencode($id) . '/cancel'); }

    /**
     * @throws Koperasi_error  4xx definitif dari koperasi (kode error ikut)
     * @throws Api_exception   503 bila koperasi/IdP tidak terjangkau
     */
    private function call($method, $path, array $json = NULL, array $headers = array()) {
        try {
            $token = $this->CI->oidc_client->service_token($this->CI->config->item('koperasi_scopes'));
            $opts  = array(
                'headers' => array_merge(array('Authorization: Bearer ' . $token), $headers),
                'timeout' => 10,
            );
            if ($json !== NULL) { $opts['json'] = $json; }
            $r = $this->CI->http_client->request($method, $this->CI->config->item('koperasi_internal_base') . $path, $opts);
        } catch (Throwable $e) {
            log_message('error', "[koperasi] {$method} {$path} gagal: " . $e->getMessage());
            throw Api_exception::paymentUnavailable();
        }

        if ($r['status'] >= 200 && $r['status'] < 300 && is_array($r['json'])) {
            return $r['json'];
        }
        if ($r['status'] >= 400 && $r['status'] < 500 && is_array($r['json'])) {
            throw new Koperasi_error((string) ($r['json']['code'] ?? 'ERROR'), (string) ($r['json']['error'] ?? ''), $r['status']);
        }
        log_message('error', "[koperasi] {$method} {$path} HTTP {$r['status']}: " . substr($r['body'], 0, 300));
        throw Api_exception::paymentUnavailable();
    }
}

class Koperasi_error extends RuntimeException {
    public $code_name;
    public $status;

    public function __construct($code_name, $message, $status) {
        parent::__construct($message);
        $this->code_name = $code_name;
        $this->status    = $status;
    }
}
