<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pembungkus tipis Predis (§10.6), dengan mode alternatif REST (Upstash)
 * untuk hosting yang memblokir outbound TCP ke port non-standar (mis. 6379)
 * tapi mengizinkan HTTPS/443 — lihat REDIS_TRANSPORT di .env.
 *
 * Dipakai bersama oleh koperasi, account (IdP), dan market. Satu instance
 * Redis boleh dipakai ketiganya asal REDIS_PREFIX berbeda per layanan
 * (mis. "idp:", "mkt:"; koperasi dibiarkan kosong agar key lama — antrean
 * emas, cache harga — tidak berubah nama).
 *
 * Semua pemanggil WAJIB memperlakukan kegagalan Redis sebagai non-fatal
 * kecuali pada jalur yang benar-benar bergantung padanya.
 */
class Redisx {

    private $c;
    private $transport;
    private $rest_base;
    private $rest_token;
    private $prefix;

    public function __construct($params = array()) {
        $this->transport = env('REDIS_TRANSPORT', 'tcp');
        $this->prefix    = (string) env('REDIS_PREFIX', '');

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

    private function k($k) { return $this->prefix . $k; }

    public function setex($k, $ttl, $v) {
        if ($this->transport === 'rest') { return $this->rest(array('setex', $this->k($k), $ttl, $v)); }
        return $this->c->setex($this->k($k), $ttl, $v);
    }

    /**
     * SET key value NX EX ttl — atomik. TRUE bila key berhasil dibuat.
     * Dipakai untuk kunci terdistribusi (refresh token BFF) dan deteksi replay
     * (jti logout_token, event webhook).
     */
    public function set_nx_ex($k, $v, $ttl) {
        if ($this->transport === 'rest') {
            return $this->rest(array('set', $this->k($k), $v, 'NX', 'EX', (int) $ttl)) === 'OK';
        }
        $r = $this->c->set($this->k($k), $v, 'EX', (int) $ttl, 'NX');
        return $r !== NULL && (string) $r === 'OK';
    }

    public function get($k) {
        if ($this->transport === 'rest') { return $this->rest(array('get', $this->k($k))); }
        return $this->c->get($this->k($k));
    }

    public function del($k) {
        if ($this->transport === 'rest') { return $this->rest(array('del', $this->k($k))); }
        return $this->c->del(array($this->k($k)));
    }

    public function exists($k) {
        if ($this->transport === 'rest') { return ((int) $this->rest(array('exists', $this->k($k)))) > 0; }
        return (int) $this->c->exists($this->k($k)) > 0;
    }

    public function rpush($k, $v) {
        if ($this->transport === 'rest') { return $this->rest(array('rpush', $this->k($k), $v)); }
        return $this->c->rpush($this->k($k), array($v));
    }

    public function blpop($k, $timeout = 0) {
        if ($this->transport === 'rest') {
            // REST Upstash membatasi durasi blocking call; 0 (selamanya) tidak
            // didukung, jadi dipetakan ke jendela pendek dan caller (loop worker
            // emas) akan memanggil ulang — perilaku akhirnya tetap sama, cuma
            // polling per beberapa detik alih-alih benar-benar blocking.
            $t = $timeout > 0 ? $timeout : 30;
            return $this->rest(array('blpop', $this->k($k), $t));
        }
        return $this->c->blpop(array($this->k($k)), $timeout);
    }

    public function incr($k) {
        if ($this->transport === 'rest') { return $this->rest(array('incr', $this->k($k))); }
        return $this->c->incr($this->k($k));
    }

    public function expire($k, $s) {
        if ($this->transport === 'rest') { return $this->rest(array('expire', $this->k($k), $s)); }
        return $this->c->expire($this->k($k), $s);
    }

    public function sadd($k, $member) {
        if ($this->transport === 'rest') { return $this->rest(array('sadd', $this->k($k), $member)); }
        return $this->c->sadd($this->k($k), array($member));
    }

    public function srem($k, $member) {
        if ($this->transport === 'rest') { return $this->rest(array('srem', $this->k($k), $member)); }
        return $this->c->srem($this->k($k), $member);
    }

    /** @return string[] */
    public function smembers($k) {
        if ($this->transport === 'rest') { return (array) $this->rest(array('smembers', $this->k($k))); }
        return (array) $this->c->smembers($this->k($k));
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
