<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Kunci penandatangan RS256 + penerbitan/verifikasi JWT IdP.
 *
 * Kunci privat = file PEM di OIDC_KEYS_DIR (default apps/account/keys,
 * WAJIB di luar jangkauan web — .htaccess root menolak /apps). Nama file
 * diawali tanggal (YYYYMMDDHHMMSS-...pem); yang TERBARU dipakai menandatangani,
 * SEMUA dipublikasikan di JWKS. Rotasi: generate kunci baru, tunggu > TTL
 * token terpanjang, baru hapus file lama (§ Fase 7 "rotasi kunci").
 */
class Token_service {

    private $CI;
    private $keys;   // kid => ['private' => pem, 'public' => pem, 'jwk' => array]

    public function __construct() {
        $this->CI =& get_instance();
    }

    public function issuer() {
        return $this->CI->config->item('issuer');
    }

    /* ----------------------------------------------------------------- kunci */

    private function keys() {
        if ($this->keys !== NULL) { return $this->keys; }

        $dir   = $this->CI->config->item('keys_dir');
        $files = glob($dir . '*.pem') ?: array();
        sort($files);   // prefix timestamp → urutan kronologis

        $this->keys = array();
        foreach ($files as $f) {
            $pem  = file_get_contents($f);
            $priv = openssl_pkey_get_private($pem);
            if ($priv === FALSE) {
                log_message('error', '[token] kunci privat tidak valid: ' . basename($f));
                continue;
            }
            $d = openssl_pkey_get_details($priv);
            if ( ! isset($d['rsa'])) { continue; }

            $jwk = array(
                'kty' => 'RSA',
                'n'   => base64url_encode($d['rsa']['n']),
                'e'   => base64url_encode($d['rsa']['e']),
            );
            // RFC 7638 JWK thumbprint sebagai kid: stabil & dapat diverifikasi.
            $thumb = base64url_encode(hash('sha256',
                json_encode(array('e' => $jwk['e'], 'kty' => 'RSA', 'n' => $jwk['n']), JSON_UNESCAPED_SLASHES), TRUE));

            $this->keys[$thumb] = array(
                'private' => $pem,
                'public'  => $d['key'],
                'jwk'     => $jwk + array('use' => 'sig', 'alg' => 'RS256', 'kid' => $thumb),
            );
        }

        if (empty($this->keys)) {
            throw new RuntimeException('Belum ada kunci penandatangan. Jalankan: php account.php cli/keys generate');
        }
        return $this->keys;
    }

    public function jwks() {
        return array('keys' => array_values(array_map(function ($k) { return $k['jwk']; }, $this->keys())));
    }

    /** Buat pasangan kunci baru. @return string path file */
    public function generate_key() {
        $cfg = array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA);
        // PHP Windows tanpa OPENSSL_CONF butuh path openssl.cnf eksplisit.
        if ( ! getenv('OPENSSL_CONF')) {
            $cnf = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
            if (is_file($cnf)) { $cfg['config'] = $cnf; }
        }

        $res = openssl_pkey_new($cfg);
        if ($res === FALSE) {
            throw new RuntimeException('openssl_pkey_new gagal: ' . openssl_error_string());
        }
        $pem = '';
        openssl_pkey_export($res, $pem, NULL, $cfg);

        $dir = $this->CI->config->item('keys_dir');
        if ( ! is_dir($dir) && ! mkdir($dir, 0700, TRUE)) {
            throw new RuntimeException('tidak bisa membuat direktori kunci ' . $dir);
        }
        $path = $dir . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.pem';
        if (file_put_contents($path, $pem, LOCK_EX) === FALSE) {
            throw new RuntimeException('tidak bisa menulis ' . $path);
        }
        @chmod($path, 0600);
        $this->keys = NULL;
        return $path;
    }

    /* ------------------------------------------------------------------ JWT */

    public function sign(array $claims, $typ = 'JWT') {
        $keys = $this->keys();
        $kid  = array_key_last_compat($keys);

        return JWT::encode($claims, $keys[$kid]['private'], 'RS256', $kid, array('typ' => $typ));
    }

    /**
     * @param bool $allow_expired untuk id_token_hint saat logout (OIDC RP-Initiated Logout §2)
     * @return array|null klaim
     */
    public function verify($jwt, $allow_expired = FALSE) {
        $keyset = array();
        foreach ($this->keys() as $kid => $k) {
            $keyset[$kid] = new Key($k['public'], 'RS256');
        }

        JWT::$leeway = 60;
        $prev = JWT::$timestamp;
        try {
            if ($allow_expired) {
                // Geser "sekarang" ke saat token diterbitkan, supaya exp tidak ditolak
                // namun tanda tangan tetap diverifikasi penuh.
                $parts = explode('.', (string) $jwt);
                $body  = count($parts) === 3 ? json_decode((string) base64url_decode($parts[1]), TRUE) : NULL;
                if (is_array($body) && isset($body['iat'])) { JWT::$timestamp = (int) $body['iat']; }
            }
            $c = json_decode(json_encode(JWT::decode((string) $jwt, $keyset)), TRUE);
        } catch (Throwable $e) {
            return NULL;
        } finally {
            JWT::$timestamp = $prev;
        }

        return (($c['iss'] ?? NULL) === $this->issuer()) ? $c : NULL;
    }

    /* ------------------------------------------------------------- penerbit */

    public function id_token(array $user, $client_id, $sid, $nonce, $auth_time, array $scopes) {
        $now = time();
        $claims = array(
            'iss'       => $this->issuer(),
            'sub'       => (string) $user['id'],
            'aud'       => $client_id,
            'azp'       => $client_id,
            'iat'       => $now,
            'exp'       => $now + (int) $this->CI->config->item('id_token_ttl'),
            'auth_time' => (int) $auth_time,
            'nonce'     => $nonce,
            'sid'       => $sid,
        );
        return $this->sign($claims + $this->user_claims($user, $scopes));
    }

    public function user_access_token(array $user, $client_id, $sid, $scope) {
        $now = time();
        return $this->sign(array(
            'iss'       => $this->issuer(),
            'sub'       => (string) $user['id'],
            'aud'       => $client_id,
            'client_id' => $client_id,
            'scope'     => $scope,
            'sid'       => $sid,
            'token_use' => 'user',
            'jti'       => random_token(16),
            'iat'       => $now,
            'exp'       => $now + (int) $this->CI->config->item('access_token_ttl'),
        ), 'at+jwt');
    }

    public function service_access_token($client_id, $audience, $scope) {
        $now = time();
        return $this->sign(array(
            'iss'       => $this->issuer(),
            'sub'       => $client_id,
            'aud'       => $audience,
            'client_id' => $client_id,
            'scope'     => $scope,
            'token_use' => 'service',
            'jti'       => random_token(16),
            'iat'       => $now,
            'exp'       => $now + (int) $this->CI->config->item('access_token_ttl'),
        ), 'at+jwt');
    }

    public function logout_token($client_id, $sub, $sid) {
        $now = time();
        return $this->sign(array(
            'iss'    => $this->issuer(),
            'aud'    => $client_id,
            'sub'    => (string) $sub,
            'sid'    => $sid,
            'iat'    => $now,
            'exp'    => $now + 120,
            'jti'    => random_token(16),
            'events' => array('http://schemas.openid.net/event/backchannel-logout' => new stdClass()),
        ), 'logout+jwt');
    }

    public function user_claims(array $user, array $scopes) {
        $c = array();
        if (in_array('profile', $scopes, TRUE)) {
            $c['name'] = $user['name'];
        }
        if (in_array('email', $scopes, TRUE)) {
            $c['email']          = $user['email'];
            $c['email_verified'] = $user['email_verified_at'] !== NULL;
        }
        return $c;
    }
}

if ( ! function_exists('array_key_last_compat')) {
    /** array_key_last() baru ada di PHP 7.3; tetap aman bila hosting lebih tua. */
    function array_key_last_compat(array $a) {
        end($a);
        return key($a);
    }
}
