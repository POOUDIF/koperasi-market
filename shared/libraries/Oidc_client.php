<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * Relying Party OpenID Connect untuk layanan yang login lewat JDC Account
 * (koperasi, market). Dijalankan di backend (pola BFF) — token tidak pernah
 * sampai ke browser. Lihat DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §3.
 *
 * Konfigurasi: $config['sso'] di config layanan (issuer, client_id,
 * client_secret, redirect_uri, post_logout_redirect_uri, scopes) dan,
 * untuk panggilan antar layanan, $config['service_client'].
 */
class Oidc_client {

    const LEEWAY = 60;

    private $CI;
    private $cfg;
    private $discovery;
    private $jwks;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->library(array('Http_client', 'Redisx'));
        $this->cfg = (array) $this->CI->config->item('sso');
    }

    public function config($key) {
        return isset($this->cfg[$key]) ? $this->cfg[$key] : NULL;
    }

    /* ------------------------------------------------------------ discovery */

    public function discovery() {
        if ($this->discovery !== NULL) { return $this->discovery; }

        $cached = $this->cache_get('oidc:discovery');
        if (is_array($cached)) { return $this->discovery = $cached; }

        $issuer = rtrim($this->cfg['issuer'], '/');
        $r = $this->http('GET', $issuer . '/.well-known/openid-configuration');
        if ($r['status'] !== 200 || ! is_array($r['json'])) {
            throw new RuntimeException('discovery IdP gagal: HTTP ' . $r['status']);
        }
        // OIDC Discovery §4.3: issuer di dokumen WAJIB identik dengan yang
        // dikonfigurasi — mencegah dokumen dari IdP lain dipakai diam-diam.
        if (($r['json']['issuer'] ?? NULL) !== $issuer) {
            throw new RuntimeException('issuer discovery tidak cocok dengan konfigurasi');
        }

        $this->cache_set('oidc:discovery', $r['json'], 3600);
        return $this->discovery = $r['json'];
    }

    private function jwks($force = FALSE) {
        if ( ! $force && $this->jwks !== NULL) { return $this->jwks; }

        if ( ! $force) {
            $cached = $this->cache_get('oidc:jwks');
            if (is_array($cached)) { return $this->jwks = JWK::parseKeySet($cached, 'RS256'); }
        }

        $r = $this->http('GET', $this->discovery()['jwks_uri']);
        if ($r['status'] !== 200 || ! isset($r['json']['keys'])) {
            throw new RuntimeException('JWKS IdP gagal: HTTP ' . $r['status']);
        }
        $this->cache_set('oidc:jwks', $r['json'], 3600);
        return $this->jwks = JWK::parseKeySet($r['json'], 'RS256');
    }

    /* ---------------------------------------------------------- endpoint */

    /** URL authorize dengan PKCE S256 + state + nonce. */
    public function authorization_url($state, $nonce, $code_verifier, array $extra = array()) {
        $params = array_merge(array(
            'response_type'         => 'code',
            'client_id'             => $this->cfg['client_id'],
            'redirect_uri'          => $this->cfg['redirect_uri'],
            'scope'                 => $this->cfg['scopes'],
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => base64url_encode(hash('sha256', $code_verifier, TRUE)),
            'code_challenge_method' => 'S256',
        ), $extra);

        return $this->discovery()['authorization_endpoint'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array respons token (id_token, access_token, refresh_token, expires_in) */
    public function exchange_code($code, $code_verifier) {
        return $this->token_request(array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->cfg['redirect_uri'],
            'code_verifier' => $code_verifier,
        ));
    }

    /**
     * @return array respons token
     * @throws Oidc_grant_error bila refresh token ditolak (sesi IdP berakhir,
     *         user diblokir, reuse terdeteksi) — pemanggil WAJIB mengakhiri sesi.
     */
    public function refresh($refresh_token) {
        return $this->token_request(array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ));
    }

    /** Cabut refresh token (RFC 7009). Best-effort: kegagalan hanya di-log. */
    public function revoke($refresh_token) {
        try {
            $d = $this->discovery();
            if (empty($d['revocation_endpoint'])) { return; }
            $this->http('POST', $d['revocation_endpoint'], array(
                'form'       => array('token' => $refresh_token, 'token_type_hint' => 'refresh_token'),
                'basic_auth' => array($this->cfg['client_id'], $this->cfg['client_secret']),
            ));
        } catch (Throwable $e) {
            log_message('error', '[oidc] revoke gagal: ' . $e->getMessage());
        }
    }

    public function end_session_url($id_token_hint, $post_logout_redirect_uri = NULL) {
        $params = array(
            'client_id'                => $this->cfg['client_id'],
            'post_logout_redirect_uri' => $post_logout_redirect_uri ?: $this->cfg['post_logout_redirect_uri'],
        );
        if ($id_token_hint) { $params['id_token_hint'] = $id_token_hint; }

        return $this->discovery()['end_session_endpoint'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Access token client_credentials untuk memanggil layanan lain. Di-cache
     * di Redis sampai 60 detik sebelum kedaluwarsa.
     */
    public function service_token($scope) {
        $svc = (array) $this->CI->config->item('service_client');
        $key = 'oidc:svc_token:' . hash('sha256', $svc['client_id'] . '|' . $scope);

        $cached = $this->cache_get($key);
        if (is_array($cached) && ! empty($cached['access_token'])) {
            return $cached['access_token'];
        }

        $r = $this->http('POST', $this->discovery()['token_endpoint'], array(
            'form'       => array('grant_type' => 'client_credentials', 'scope' => $scope),
            'basic_auth' => array($svc['client_id'], $svc['client_secret']),
        ));
        if ($r['status'] !== 200 || empty($r['json']['access_token'])) {
            throw new RuntimeException('client_credentials ditolak: ' . $r['body']);
        }

        $ttl = max(1, (int) ($r['json']['expires_in'] ?? 300) - 60);
        $this->cache_set($key, array('access_token' => $r['json']['access_token']), $ttl);
        return $r['json']['access_token'];
    }

    /* ------------------------------------------------------------ verifikasi */

    /**
     * Verifikasi ID token (OIDC Core §3.1.3.7): tanda tangan RS256 dari JWKS,
     * iss, aud, azp, exp/iat, nonce.
     * @return array klaim
     */
    public function verify_id_token($jwt, $expected_nonce) {
        $c = $this->decode($jwt);

        $this->assert_issuer($c);
        $aud = (array) $c['aud'];
        if ( ! in_array($this->cfg['client_id'], $aud, TRUE)) {
            throw new UnexpectedValueException('aud id_token tidak cocok');
        }
        if (count($aud) > 1 && ($c['azp'] ?? NULL) !== $this->cfg['client_id']) {
            throw new UnexpectedValueException('azp id_token tidak cocok');
        }
        if (empty($c['sub'])) {
            throw new UnexpectedValueException('sub kosong');
        }
        if ( ! is_string($c['nonce'] ?? NULL) || ! hash_equals((string) $expected_nonce, $c['nonce'])) {
            throw new UnexpectedValueException('nonce id_token tidak cocok');
        }
        return $c;
    }

    /**
     * Verifikasi logout_token Back-Channel Logout 1.0 §2.6.
     * @return array klaim
     */
    public function verify_logout_token($jwt) {
        $c = $this->decode($jwt);

        $this->assert_issuer($c);
        if ( ! in_array($this->cfg['client_id'], (array) $c['aud'], TRUE)) {
            throw new UnexpectedValueException('aud logout_token tidak cocok');
        }
        if ( ! isset($c['events']['http://schemas.openid.net/event/backchannel-logout'])) {
            throw new UnexpectedValueException('events backchannel-logout tidak ada');
        }
        if (isset($c['nonce'])) {
            throw new UnexpectedValueException('logout_token tidak boleh memuat nonce');
        }
        if (empty($c['sid']) && empty($c['sub'])) {
            throw new UnexpectedValueException('logout_token wajib memuat sid atau sub');
        }
        if (abs(time() - (int) ($c['iat'] ?? 0)) > 300) {
            throw new UnexpectedValueException('logout_token terlalu lama');
        }
        if (empty($c['jti'])) {
            throw new UnexpectedValueException('logout_token tanpa jti');
        }
        // Tolak replay: jti hanya boleh dipakai sekali.
        try {
            if ( ! $this->CI->redisx->set_nx_ex('oidc:logout_jti:' . hash('sha256', $c['jti']), '1', 600)) {
                throw new UnexpectedValueException('logout_token replay');
            }
        } catch (UnexpectedValueException $e) {
            throw $e;
        } catch (Throwable $e) {
            log_message('error', '[oidc] cek replay jti gagal (diloloskan): ' . $e->getMessage());
        }
        return $c;
    }

    /**
     * Verifikasi access token client_credentials untuk API internal.
     * @return array klaim
     */
    public function verify_service_token($jwt, $audience, array $required_scopes) {
        $c = $this->decode($jwt);

        $this->assert_issuer($c);
        if ( ! in_array($audience, (array) $c['aud'], TRUE)) {
            throw new UnexpectedValueException('aud access token tidak cocok');
        }
        if (($c['token_use'] ?? NULL) !== 'service') {
            throw new UnexpectedValueException('bukan token layanan');
        }
        $granted = preg_split('/\s+/', trim((string) ($c['scope'] ?? '')));
        foreach ($required_scopes as $s) {
            if ( ! in_array($s, $granted, TRUE)) {
                throw new UnexpectedValueException('scope ' . $s . ' tidak diberikan');
            }
        }
        return $c;
    }

    /* ------------------------------------------------------------- internal */

    private function decode($jwt) {
        JWT::$leeway = self::LEEWAY;
        try {
            $obj = JWT::decode((string) $jwt, $this->jwks());
        } catch (UnexpectedValueException $e) {
            // kid tidak dikenal → kemungkinan rotasi kunci: ambil JWKS ulang SEKALI.
            if (stripos($e->getMessage(), 'kid') === FALSE) { throw $e; }
            $obj = JWT::decode((string) $jwt, $this->jwks(TRUE));
        }
        return json_decode(json_encode($obj), TRUE);
    }

    private function assert_issuer(array $c) {
        if (($c['iss'] ?? NULL) !== rtrim($this->cfg['issuer'], '/')) {
            throw new UnexpectedValueException('iss tidak cocok');
        }
    }

    private function token_request(array $form) {
        $r = $this->http('POST', $this->discovery()['token_endpoint'], array(
            'form'       => $form,
            'basic_auth' => array($this->cfg['client_id'], $this->cfg['client_secret']),
        ));

        if ($r['status'] === 200 && is_array($r['json'])) {
            return $r['json'];
        }

        $error = $r['json']['error'] ?? 'http_' . $r['status'];
        if (in_array($error, array('invalid_grant', 'invalid_client', 'unauthorized_client'), TRUE)) {
            throw new Oidc_grant_error($error, $r['json']['error_description'] ?? '');
        }
        throw new RuntimeException('token endpoint gagal: ' . $r['status'] . ' ' . $r['body']);
    }

    private function http($method, $url, array $opts = array()) {
        $opts['timeout'] = isset($opts['timeout']) ? $opts['timeout'] : 10;
        return $this->CI->http_client->request($method, $url, $opts);
    }

    private function cache_get($key) {
        try {
            $v = $this->CI->redisx->get($key);
            return $v === NULL ? NULL : json_decode($v, TRUE);
        } catch (Throwable $e) {
            return NULL;
        }
    }

    private function cache_set($key, $value, $ttl) {
        try {
            $this->CI->redisx->setex($key, $ttl, json_encode($value));
        } catch (Throwable $e) {
            log_message('error', '[oidc] cache gagal: ' . $e->getMessage());
        }
    }
}

/** Grant ditolak IdP secara definitif (bukan gangguan jaringan). */
class Oidc_grant_error extends RuntimeException {
    public $error;

    public function __construct($error, $description = '') {
        parent::__construct($error . ($description !== '' ? ': ' . $description : ''));
        $this->error = $error;
    }
}
