<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pembungkus tipis Predis (§10.6).
 *
 * Semua pemanggil WAJIB memperlakukan kegagalan Redis sebagai non-fatal
 * kecuali pada jalur yang benar-benar bergantung padanya (penyimpanan OTP).
 */
class Redisx {

    private $c;

    public function __construct($params = array()) {
        $this->c = new Predis\Client(array(
            'scheme' => 'tcp',
            'host'   => env('REDIS_HOST', '127.0.0.1'),
            'port'   => (int) env('REDIS_PORT', 6379),
            // 0 = blokir selamanya; WAJIB untuk BLPOP di worker emas.
            'read_write_timeout' => ( ! empty($params['blocking'])) ? 0 : 3,
            'timeout' => 3,
        ));
    }

    public function client() { return $this->c; }

    public function setex($k, $ttl, $v)     { return $this->c->setex($k, $ttl, $v); }
    public function get($k)                 { return $this->c->get($k); }
    public function del($k)                 { return $this->c->del(array($k)); }
    public function exists($k)              { return (int) $this->c->exists($k) > 0; }
    public function rpush($k, $v)           { return $this->c->rpush($k, array($v)); }
    public function blpop($k, $timeout = 0) { return $this->c->blpop(array($k), $timeout); }
    public function incr($k)                { return $this->c->incr($k); }
    public function expire($k, $s)          { return $this->c->expire($k, $s); }
    public function ping()                  { return $this->c->ping(); }
}
