<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pembungkus tipis Predis (§10.6), dengan mode alternatif REST (Upstash)
 * untuk hosting yang memblokir outbound TCP ke port non-standar (mis. 6379)
 * tapi mengizinkan HTTPS/443 — lihat REDIS_TRANSPORT di .env.
 *
 * Semua pemanggil WAJIB memperlakukan kegagalan Redis sebagai non-fatal
 * kecuali pada jalur yang benar-benar bergantung padanya (penyimpanan OTP).
 */
class Redisx {

    private $c;
    private $transport;
    private $rest_base;
    private $rest_token;

    public function __construct($params = array()) {
        $this->transport = env('REDIS_TRANSPORT', 'tcp');

        if ($this->transport === 'rest') {
            $this->rest_base  = 'https://' . env('REDIS_HOST');
            $this->rest_token = env('REDIS_PASSWORD');
            return;
        }

        $config = array(
            'scheme' => env('REDIS_SCHEME', 'tcp'),
            'host'   => env('REDIS_HOST', '127.0.0.1'),
            'port'   => (int) env('REDIS_PORT', 6379),
            // 0 = blokir selamanya; WAJIB untuk BLPOP di worker emas.
            'read_write_timeout' => ( ! empty($params['blocking'])) ? 0 : 3,
            'timeout' => 3,
        );

        $password = env('REDIS_PASSWORD');
        if ($password !== NULL) {
            $config['password'] = $password;
        }

        $this->c = new Predis\Client($config);
    }

    public function client() { return $this->c; }

    public function setex($k, $ttl, $v) {
        if ($this->transport === 'rest') { return $this->rest(array('setex', $k, $ttl, $v)); }
        return $this->c->setex($k, $ttl, $v);
    }

    public function get($k) {
        if ($this->transport === 'rest') { return $this->rest(array('get', $k)); }
        return $this->c->get($k);
    }

    public function del($k) {
        if ($this->transport === 'rest') { return $this->rest(array('del', $k)); }
        return $this->c->del(array($k));
    }

    public function exists($k) {
        if ($this->transport === 'rest') { return ((int) $this->rest(array('exists', $k))) > 0; }
        return (int) $this->c->exists($k) > 0;
    }

    public function rpush($k, $v) {
        if ($this->transport === 'rest') { return $this->rest(array('rpush', $k, $v)); }
        return $this->c->rpush($k, array($v));
    }

    public function blpop($k, $timeout = 0) {
        if ($this->transport === 'rest') {
            // REST Upstash membatasi durasi blocking call; 0 (selamanya) tidak
            // didukung, jadi dipetakan ke jendela pendek dan caller (loop worker
            // emas) akan memanggil ulang — perilaku akhirnya tetap sama, cuma
            // polling per beberapa detik alih-alih benar-benar blocking.
            $t = $timeout > 0 ? $timeout : 30;
            return $this->rest(array('blpop', $k, $t));
        }
        return $this->c->blpop(array($k), $timeout);
    }

    public function incr($k) {
        if ($this->transport === 'rest') { return $this->rest(array('incr', $k)); }
        return $this->c->incr($k);
    }

    public function expire($k, $s) {
        if ($this->transport === 'rest') { return $this->rest(array('expire', $k, $s)); }
        return $this->c->expire($k, $s);
    }

    public function ping() {
        if ($this->transport === 'rest') { return $this->rest(array('ping')); }
        return $this->c->ping();
    }

    /** Eksekusi satu perintah lewat Upstash REST API (§10.6 mode alternatif). */
    private function rest(array $parts) {
        $path = implode('/', array_map('rawurlencode', $parts));
        $ch = curl_init($this->rest_base . '/' . $path);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $this->rest_token),
            CURLOPT_TIMEOUT        => 15,
        ));
        $body = curl_exec($ch);
        if ($body === FALSE) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Upstash REST gagal: ' . $err);
        }
        curl_close($ch);

        $data = json_decode($body, TRUE);
        if ( ! is_array($data)) {
            throw new RuntimeException('Upstash REST balasan tidak valid: ' . $body);
        }
        if (isset($data['error'])) {
            throw new RuntimeException('Upstash REST error: ' . $data['error']);
        }
        return $data['result'];
    }
}
