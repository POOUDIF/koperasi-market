<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Sesi server-side pola BFF (Backend-for-Frontend) — §3.1, §3.5 dokumen
 * arsitektur. Browser hanya memegang ID sesi acak di cookie HttpOnly;
 * refresh token IdP tersimpan terenkripsi di Redis dan tidak pernah keluar.
 *
 * Karena semua layanan berbagi SATU origin (jdc.shfopis.com/<layanan>),
 * cookie dibatasi Path ke prefix layanannya (/koperasi, /market) dan diberi
 * nama unik per layanan. Prefix __Host- tidak bisa dipakai (mensyaratkan
 * Path=/), jadi dipakai __Secure- saat HTTPS.
 *
 * Kunci Redis (prefix REDIS_PREFIX layanan ditambahkan Redisx):
 *   bff:sess:<sha256(id)>   JSON sesi — ID mentah tidak pernah disimpan
 *   bff:sid:<idp_sid>       set hash sesi lokal milik satu sesi IdP (back-channel logout)
 *   bff:sub:<sub>           set hash sesi lokal milik satu user (logout global per user)
 *   bff:tx:<state>          transaksi login (nonce, code_verifier, return_to), TTL 10 menit
 *   bff:lock:<hash>         kunci refresh agar request paralel tidak merotasi token dua kali
 */
class Bff_session {

    /** Sentuh last_seen paling sering sekali per interval ini (hemat tulis Redis/Upstash). */
    const TOUCH_INTERVAL = 60;

    private $CI;
    private $cfg;
    private $current = FALSE;   // FALSE = belum dibaca; NULL = tidak ada sesi

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->library(array('Redisx', 'Crypto'));
        $this->cfg = (array) $this->CI->config->item('bff_session');
    }

    /* ---------------------------------------------------------------- cookie */

    public function cookie_name($base = NULL) {
        $base = $base ?: $this->cfg['cookie_name'];
        return ($this->cfg['secure'] ? '__Secure-' : '') . $base;
    }

    private function set_cookie($name, $value, $path, $max_age = NULL) {
        $opts = array(
            'path'     => $path,
            'secure'   => (bool) $this->cfg['secure'],
            'httponly' => TRUE,
            'samesite' => 'Lax',
        );
        if ($max_age !== NULL) { $opts['expires'] = $max_age === 0 ? 1 : time() + $max_age; }
        setcookie($name, $value, $opts);
    }

    /* ------------------------------------------------------ transaksi login */

    public function begin_login($return_to) {
        $state    = random_token(32);
        $nonce    = random_token(32);
        $verifier = random_token(48);   // 64 karakter base64url, dalam rentang PKCE 43..128

        $this->CI->redisx->setex('bff:tx:' . hash('sha256', $state), 600, json_encode(array(
            'nonce'     => $nonce,
            'verifier'  => $verifier,
            'return_to' => $return_to,
        )));
        // Cookie ini mengikat state ke browser yang memulai login — mencegah
        // login CSRF (penyerang menyodorkan callback berisi code miliknya).
        $this->set_cookie($this->cookie_name('sso_tx'), $state, $this->cfg['sso_path'], 600);

        return array('state' => $state, 'nonce' => $nonce, 'verifier' => $verifier);
    }

    /**
     * Ambil & hapus transaksi login (sekali pakai).
     * @return array|null
     */
    public function consume_login($state) {
        $cookie = $_COOKIE[$this->cookie_name('sso_tx')] ?? '';
        $this->set_cookie($this->cookie_name('sso_tx'), '', $this->cfg['sso_path'], 0);

        if ( ! is_string($state) || $state === '' || ! is_string($cookie) || ! hash_equals($cookie, $state)) {
            return NULL;
        }
        $key = 'bff:tx:' . hash('sha256', $state);
        $raw = $this->CI->redisx->get($key);
        $this->CI->redisx->del($key);

        $tx = $raw === NULL ? NULL : json_decode($raw, TRUE);
        return is_array($tx) ? $tx : NULL;
    }

    /* ---------------------------------------------------------------- sesi */

    /**
     * @param array $data user_id, sub, idp_sid, id_token, refresh_token, access_expires_at, auth_time
     */
    public function create(array $data) {
        $this->destroy_current();

        $id   = random_token(32);
        $hash = hash('sha256', $id);
        $now  = time();

        $sess = array(
            'user_id'           => (int) $data['user_id'],
            'sub'               => (string) $data['sub'],
            'idp_sid'           => (string) ($data['idp_sid'] ?? ''),
            'id_token'          => (string) ($data['id_token'] ?? ''),
            'refresh_token_enc' => empty($data['refresh_token']) ? NULL : $this->CI->crypto->encrypt($data['refresh_token']),
            'access_expires_at' => (int) $data['access_expires_at'],
            'auth_time'         => (int) ($data['auth_time'] ?? $now),
            'created_at'        => $now,
            'last_seen_at'      => $now,
        );

        $this->write($hash, $sess);
        if ($sess['idp_sid'] !== '') { $this->index_add('bff:sid:' . $sess['idp_sid'], $hash); }
        $this->index_add('bff:sub:' . $sess['sub'], $hash);

        $this->set_cookie($this->cookie_name(), $id, $this->cfg['path']);
        $this->current = $sess + array('_hash' => $hash);

        return $this->current;
    }

    /** @return array|null sesi aktif untuk request ini */
    public function current() {
        if ($this->current !== FALSE) { return $this->current; }

        $id = $_COOKIE[$this->cookie_name()] ?? NULL;
        if ( ! is_string($id) || strlen($id) < 40 || strlen($id) > 64) {
            return $this->current = NULL;
        }
        $hash = hash('sha256', $id);

        $raw  = $this->CI->redisx->get('bff:sess:' . $hash);
        $sess = $raw === NULL ? NULL : json_decode($raw, TRUE);
        if ( ! is_array($sess)) {
            return $this->current = NULL;
        }

        $now = time();
        if ($now - (int) $sess['created_at'] > (int) $this->cfg['absolute_ttl']
            || $now - (int) $sess['last_seen_at'] > (int) $this->cfg['idle_ttl']) {
            $this->destroy_hash($hash, $sess);
            return $this->current = NULL;
        }

        if ($now - (int) $sess['last_seen_at'] >= self::TOUCH_INTERVAL) {
            $sess['last_seen_at'] = $now;
            $this->write($hash, $sess);
        }

        return $this->current = $sess + array('_hash' => $hash);
    }

    /**
     * Pastikan sesi masih diakui IdP: begitu access token lewat masa berlaku,
     * rotasi refresh token. Ini jaring pengaman bila back-channel logout
     * gagal terkirim — pencabutan di IdP terasa paling lambat 1 TTL access token.
     *
     * @return bool FALSE bila sesi harus diakhiri (IdP menolak refresh).
     */
    public function ensure_fresh(Oidc_client $oidc) {
        $s = $this->current();
        if ($s === NULL) { return FALSE; }
        if (time() < (int) $s['access_expires_at'] - 30) { return TRUE; }
        if (empty($s['refresh_token_enc'])) {
            $this->destroy_current();
            return FALSE;
        }

        $hash = $s['_hash'];
        // Request paralel dari SPA: hanya satu yang merotasi. Yang lain tetap
        // dilayani — sesi masih valid sampai refresh itu terbukti ditolak.
        if ( ! $this->CI->redisx->set_nx_ex('bff:lock:' . $hash, '1', 15)) {
            return TRUE;
        }

        try {
            $fresh = $this->CI->redisx->get('bff:sess:' . $hash);
            $fresh = $fresh === NULL ? NULL : json_decode($fresh, TRUE);
            if ( ! is_array($fresh)) { return FALSE; }
            if (time() < (int) $fresh['access_expires_at'] - 30) {
                $this->current = $fresh + array('_hash' => $hash);
                return TRUE;
            }

            $refresh_token = $this->CI->crypto->decrypt($fresh['refresh_token_enc']);
            if ($refresh_token === NULL) {
                $this->destroy_hash($hash, $fresh);
                return FALSE;
            }

            try {
                $tok = $oidc->refresh($refresh_token);
            } catch (Oidc_grant_error $e) {
                log_message('info', '[bff] refresh ditolak IdP, sesi diakhiri: ' . $e->getMessage());
                $this->destroy_hash($hash, $fresh);
                $this->clear_cookie();
                $this->current = NULL;
                return FALSE;
            } catch (Throwable $e) {
                // IdP tidak terjangkau: beri toleransi terbatas, bukan fail-open selamanya.
                log_message('error', '[bff] refresh gagal (jaringan): ' . $e->getMessage());
                $grace_until = (int) $fresh['access_expires_at'] + (int) $this->cfg['refresh_grace'];
                return time() < $grace_until;
            }

            $fresh['access_expires_at'] = time() + (int) ($tok['expires_in'] ?? 900);
            if ( ! empty($tok['refresh_token'])) {
                $fresh['refresh_token_enc'] = $this->CI->crypto->encrypt($tok['refresh_token']);
            }
            $this->write($hash, $fresh);
            $this->current = $fresh + array('_hash' => $hash);
            return TRUE;

        } finally {
            try { $this->CI->redisx->del('bff:lock:' . $hash); } catch (Throwable $e) {}
        }
    }

    /** Akhiri sesi request ini. @return array|null sesi yang diakhiri */
    public function destroy_current() {
        $s = $this->current();
        if ($s !== NULL) {
            $this->destroy_hash($s['_hash'], $s);
        }
        $this->clear_cookie();
        $this->current = NULL;
        return $s;
    }

    /** Back-channel logout: hapus semua sesi lokal milik satu sesi IdP. */
    public function destroy_by_idp_sid($idp_sid) {
        return $this->destroy_index('bff:sid:' . $idp_sid);
    }

    /** Back-channel logout tanpa sid (mis. user diblokir): hapus semua sesi user. */
    public function destroy_by_sub($sub) {
        return $this->destroy_index('bff:sub:' . $sub);
    }

    public function decrypt_refresh_token(array $s) {
        return empty($s['refresh_token_enc']) ? NULL : $this->CI->crypto->decrypt($s['refresh_token_enc']);
    }

    /* ------------------------------------------------------------- internal */

    private function write($hash, array $sess) {
        unset($sess['_hash']);
        $ttl = min(
            (int) $this->cfg['idle_ttl'],
            max(1, (int) $this->cfg['absolute_ttl'] - (time() - (int) $sess['created_at']))
        );
        $this->CI->redisx->setex('bff:sess:' . $hash, $ttl, json_encode($sess));
    }

    private function index_add($key, $hash) {
        $this->CI->redisx->sadd($key, $hash);
        $this->CI->redisx->expire($key, (int) $this->cfg['absolute_ttl']);
    }

    private function destroy_hash($hash, array $sess = NULL) {
        $this->CI->redisx->del('bff:sess:' . $hash);
        if ($sess !== NULL) {
            if ( ! empty($sess['idp_sid'])) { $this->CI->redisx->srem('bff:sid:' . $sess['idp_sid'], $hash); }
            if ( ! empty($sess['sub']))     { $this->CI->redisx->srem('bff:sub:' . $sess['sub'], $hash); }
        }
    }

    private function destroy_index($key) {
        $n = 0;
        foreach ($this->CI->redisx->smembers($key) as $hash) {
            $raw = $this->CI->redisx->get('bff:sess:' . $hash);
            $this->destroy_hash($hash, $raw === NULL ? NULL : json_decode($raw, TRUE));
            $n++;
        }
        $this->CI->redisx->del($key);
        return $n;
    }

    private function clear_cookie() {
        if (isset($_COOKIE[$this->cookie_name()])) {
            $this->set_cookie($this->cookie_name(), '', $this->cfg['path'], 0);
        }
    }
}
