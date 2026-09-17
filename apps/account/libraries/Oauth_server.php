<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Authorization server OAuth 2.0 / OpenID Connect Core untuk JDC.
 *
 * Profil yang didukung — sengaja sempit (OAuth 2.0 Security BCP, RFC 9700):
 *   - response_type=code SAJA, PKCE S256 WAJIB untuk semua client
 *   - state & nonce wajib; redirect_uri dicocokkan persis
 *   - grant: authorization_code, refresh_token (rotasi + deteksi reuse), client_credentials
 *   - autentikasi client: client_secret_basic / client_secret_post
 *   - parameter `iss` pada respons authorize (RFC 9207)
 */
class Oauth_server {

    private $CI;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->model(array('Oauth_model', 'Session_model', 'User_model', 'Audit_model'));
        $this->CI->load->library('Token_service');
    }

    /* =========================================================== authorize */

    /**
     * @return array permintaan tervalidasi
     * @throws Oauth_error redirectable=FALSE bila client/redirect_uri tidak sah
     *         (jangan pernah redirect ke URI yang belum diverifikasi).
     */
    public function validate_authorize(array $q) {
        $client = is_string($q['client_id'] ?? NULL) ? $this->CI->Oauth_model->find_client($q['client_id']) : NULL;
        if ($client === NULL) {
            throw new Oauth_error('invalid_client', 'Aplikasi tidak dikenal.', 400, FALSE);
        }
        $redirect_uri = $q['redirect_uri'] ?? NULL;
        if ( ! is_string($redirect_uri) || ! in_array($redirect_uri, $client['redirect_uris'], TRUE)) {
            throw new Oauth_error('invalid_request', 'Alamat kembali (redirect_uri) tidak terdaftar.', 400, FALSE);
        }

        $state = $q['state'] ?? NULL;
        $fail = function ($error, $desc) use ($redirect_uri, $state) {
            return new Oauth_error($error, $desc, 400, TRUE, $redirect_uri, is_string($state) ? $state : NULL);
        };

        if ( ! in_array('authorization_code', $client['grant_types'], TRUE) || ! $client['is_first_party']) {
            throw $fail('unauthorized_client', 'client tidak diizinkan memakai authorization code');
        }
        if (($q['response_type'] ?? NULL) !== 'code') {
            throw $fail('unsupported_response_type', 'hanya response_type=code yang didukung');
        }
        if ( ! is_string($state) || $state === '' || strlen($state) > 512) {
            throw $fail('invalid_request', 'state wajib');
        }
        $nonce = $q['nonce'] ?? NULL;
        if ( ! is_string($nonce) || $nonce === '' || strlen($nonce) > 255) {
            throw $fail('invalid_request', 'nonce wajib');
        }
        $challenge = $q['code_challenge'] ?? NULL;
        if (($q['code_challenge_method'] ?? NULL) !== 'S256'
            || ! is_string($challenge) || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            throw $fail('invalid_request', 'PKCE S256 wajib');
        }

        $scopes = array_values(array_unique(preg_split('/\s+/', trim((string) ($q['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY)));
        if ( ! in_array('openid', $scopes, TRUE)) {
            throw $fail('invalid_scope', 'scope openid wajib');
        }
        foreach ($scopes as $s) {
            if ( ! in_array($s, $client['scopes'], TRUE)) {
                throw $fail('invalid_scope', 'scope tidak diizinkan: ' . $s);
            }
        }

        $prompt = (string) ($q['prompt'] ?? '');
        if ( ! in_array($prompt, array('', 'none', 'login'), TRUE)) {
            throw $fail('invalid_request', 'prompt tidak didukung');
        }

        // prompt=login & max_age diterjemahkan ke satu syarat: auth_time >= auth_after.
        $auth_after = 0;
        if (isset($q['auth_after']) && ctype_digit((string) $q['auth_after'])) {
            $auth_after = (int) $q['auth_after'];
        }
        if (isset($q['max_age']) && ctype_digit((string) $q['max_age'])) {
            $auth_after = max($auth_after, time() - (int) $q['max_age']);
        }

        return array(
            'client'         => $client,
            'redirect_uri'   => $redirect_uri,
            'state'          => $state,
            'nonce'          => $nonce,
            'code_challenge' => $challenge,
            'scopes'         => $scopes,
            'prompt'         => $prompt,
            'auth_after'     => $auth_after,
        );
    }

    /** URL authorize untuk kembali setelah login — prompt/max_age dibuang supaya tidak berputar. */
    public function authorize_return_url(array $q, $auth_after) {
        unset($q['prompt'], $q['max_age']);
        if ($auth_after > 0) { $q['auth_after'] = $auth_after; }
        return $this->CI->config->item('base_path') . '/oauth/authorize?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    public function issue_code(array $req, array $session) {
        $code = random_token(32);

        $this->CI->Oauth_model->insert_code(array(
            'code_hash'      => hash('sha256', $code),
            'client_id'      => $req['client']['client_id'],
            'user_id'        => $session['user_id'],
            'session_id'     => $session['id'],
            'redirect_uri'   => $req['redirect_uri'],
            'scope'          => implode(' ', $req['scopes']),
            'nonce'          => $req['nonce'],
            'code_challenge' => $req['code_challenge'],
            'ttl'            => $this->CI->config->item('code_ttl'),
        ));
        $this->CI->Session_model->link_client($session['id'], $req['client']['client_id']);

        return $this->append_query($req['redirect_uri'], array(
            'code'  => $code,
            'state' => $req['state'],
            'iss'   => $this->CI->token_service->issuer(),
        ));
    }

    public function error_redirect_url(Oauth_error $e) {
        $params = array('error' => $e->error, 'error_description' => $e->getMessage(), 'iss' => $this->CI->token_service->issuer());
        if ($e->state !== NULL) { $params['state'] = $e->state; }
        return $this->append_query($e->redirect_uri, $params);
    }

    /* =============================================================== token */

    /** @throws Oauth_error invalid_client (401) */
    public function authenticate_client() {
        $id = NULL; $secret = NULL; $basic = FALSE;

        $auth = $this->CI->input->get_request_header('Authorization', FALSE);
        if (is_string($auth) && stripos($auth, 'Basic ') === 0) {
            $decoded = base64_decode(substr($auth, 6), TRUE);
            if ($decoded !== FALSE && strpos($decoded, ':') !== FALSE) {
                list($id, $secret) = explode(':', $decoded, 2);
                $id = rawurldecode($id); $secret = rawurldecode($secret);
                $basic = TRUE;
            }
        } else {
            $id     = $this->CI->input->post('client_id');
            $secret = $this->CI->input->post('client_secret');
        }

        $client = is_string($id) && $id !== '' ? $this->CI->Oauth_model->find_client($id) : NULL;
        $given  = hash('sha256', is_string($secret) ? $secret : '');
        $valid  = $client !== NULL && hash_equals($client['secret_hash'], $given);

        if ( ! $valid) {
            $this->CI->Audit_model->log('client_auth_failed', array('client_id' => is_string($id) ? substr($id, 0, 64) : NULL));
            throw new Oauth_error('invalid_client', 'autentikasi client gagal', 401, FALSE, NULL, NULL, $basic);
        }
        return $client;
    }

    public function token(array $client, array $post) {
        $grant = (string) ($post['grant_type'] ?? '');
        if ( ! in_array($grant, $client['grant_types'], TRUE)) {
            throw new Oauth_error('unauthorized_client', 'grant_type tidak diizinkan untuk client ini');
        }

        switch ($grant) {
            case 'authorization_code': return $this->grant_authorization_code($client, $post);
            case 'refresh_token':      return $this->grant_refresh_token($client, $post);
            case 'client_credentials': return $this->grant_client_credentials($client, $post);
        }
        throw new Oauth_error('unsupported_grant_type', 'grant_type tidak didukung');
    }

    private function grant_authorization_code(array $client, array $post) {
        $code     = (string) ($post['code'] ?? '');
        $verifier = (string) ($post['code_verifier'] ?? '');
        $redirect = (string) ($post['redirect_uri'] ?? '');

        if ( ! preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            throw new Oauth_error('invalid_request', 'code_verifier tidak valid');
        }

        // Keputusan diambil di dalam transaction, error dilempar SETELAH commit
        // supaya pencabutan akibat code reuse tidak ikut ter-rollback.
        $result = $this->CI->Oauth_model->atomic_public(function (Oauth_model $m) use ($client, $code, $verifier, $redirect) {
            $row = $m->lock_code(hash('sha256', $code));
            if ($row === NULL || $row['client_id'] !== $client['client_id']) {
                return array('error' => 'code tidak valid');
            }
            if ($row['used_at'] !== NULL) {
                // RFC 6749 §4.1.2: code dipakai dua kali → cabut token yang sudah terbit darinya.
                $m->revoke_by_code($row['id']);
                return array('error' => 'code sudah dipakai', 'audit' => 'auth_code_reuse', 'user_id' => $row['user_id']);
            }
            $m->mark_code_used($row['id']);

            if ((int) $row['expired'] === 1)          { return array('error' => 'code kedaluwarsa'); }
            if ( ! hash_equals($row['redirect_uri'], $redirect)) { return array('error' => 'redirect_uri tidak cocok'); }
            if ( ! hash_equals($row['code_challenge'], base64url_encode(hash('sha256', $verifier, TRUE)))) {
                return array('error' => 'PKCE verifier salah');
            }
            return array('row' => $row);
        });

        if (isset($result['error'])) {
            if (isset($result['audit'])) {
                $this->CI->Audit_model->log($result['audit'], array('user_id' => $result['user_id'], 'client_id' => $client['client_id']));
            }
            throw new Oauth_error('invalid_grant', $result['error']);
        }
        $row = $result['row'];

        list($session, $user) = $this->live_session_and_user($row['session_id'], $row['user_id']);
        $scopes = explode(' ', $row['scope']);

        $resp = array(
            'access_token' => $this->CI->token_service->user_access_token($user, $client['client_id'], $session['sid'], $row['scope']),
            'token_type'   => 'Bearer',
            'expires_in'   => (int) $this->CI->config->item('access_token_ttl'),
            'scope'        => $row['scope'],
            'id_token'     => $this->CI->token_service->id_token($user, $client['client_id'], $session['sid'],
                                  $row['nonce'], $session['auth_time'], $scopes),
        );

        if (in_array('offline_access', $scopes, TRUE) && in_array('refresh_token', $client['grant_types'], TRUE)) {
            $resp['refresh_token'] = $this->new_refresh_token(array(
                'family_id'    => bin2hex(random_bytes(16)),
                'client_id'    => $client['client_id'],
                'user_id'      => $user['id'],
                'session_id'   => $session['id'],
                'auth_code_id' => $row['id'],
                'scope'        => $row['scope'],
            ));
        }

        $this->CI->Audit_model->log('token_issued', array('user_id' => $user['id'], 'client_id' => $client['client_id']));
        return $resp;
    }

    private function grant_refresh_token(array $client, array $post) {
        $token = (string) ($post['refresh_token'] ?? '');
        $grace = (int) $this->CI->config->item('refresh_reuse_grace');

        $result = $this->CI->Oauth_model->atomic_public(function (Oauth_model $m) use ($client, $token, $grace) {
            $row = $m->lock_refresh(hash('sha256', $token));
            if ($row === NULL || $row['client_id'] !== $client['client_id']) {
                return array('error' => 'refresh token tidak valid');
            }
            if ($row['revoked_at'] !== NULL) {
                return array('error' => 'refresh token dicabut');
            }
            if ($row['used_at'] !== NULL) {
                if (time() - (int) $row['used_at'] <= $grace) {
                    // Hampir pasti dua request paralel dari client yang sama.
                    return array('error' => 'refresh token sudah dirotasi');
                }
                $m->revoke_family($row['family_id']);
                return array('error' => 'refresh token dipakai ulang', 'audit' => 'refresh_token_reuse', 'user_id' => $row['user_id']);
            }
            if ((int) $row['expired'] === 1) {
                return array('error' => 'refresh token kedaluwarsa');
            }
            $m->mark_refresh_used($row['id']);
            return array('row' => $row);
        });

        if (isset($result['error'])) {
            if (isset($result['audit'])) {
                $this->CI->Audit_model->log($result['audit'], array('user_id' => $result['user_id'], 'client_id' => $client['client_id']));
            }
            throw new Oauth_error('invalid_grant', $result['error']);
        }
        $row = $result['row'];

        try {
            list($session, $user) = $this->live_session_and_user($row['session_id'], $row['user_id']);
        } catch (Oauth_error $e) {
            $this->CI->Oauth_model->revoke_family($row['family_id']);
            throw $e;
        }
        // Aktivitas di layanan mana pun menjaga sesi SSO tetap hidup (idle timeout).
        $this->CI->Session_model->touch($session['id']);

        return array(
            'access_token'  => $this->CI->token_service->user_access_token($user, $client['client_id'], $session['sid'], $row['scope']),
            'token_type'    => 'Bearer',
            'expires_in'    => (int) $this->CI->config->item('access_token_ttl'),
            'scope'         => $row['scope'],
            'refresh_token' => $this->new_refresh_token(array(
                'family_id'    => $row['family_id'],
                'client_id'    => $client['client_id'],
                'user_id'      => $user['id'],
                'session_id'   => $session['id'],
                'auth_code_id' => NULL,
                'scope'        => $row['scope'],
            )),
        );
    }

    private function grant_client_credentials(array $client, array $post) {
        if (empty($client['audience'])) {
            throw new Oauth_error('unauthorized_client', 'client tidak memiliki audience');
        }
        $requested = preg_split('/\s+/', trim((string) ($post['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($requested)) { $requested = $client['scopes']; }
        foreach ($requested as $s) {
            if ( ! in_array($s, $client['scopes'], TRUE)) {
                throw new Oauth_error('invalid_scope', 'scope tidak diizinkan: ' . $s);
            }
        }
        $scope = implode(' ', array_unique($requested));

        return array(
            'access_token' => $this->CI->token_service->service_access_token($client['client_id'], $client['audience'], $scope),
            'token_type'   => 'Bearer',
            'expires_in'   => (int) $this->CI->config->item('access_token_ttl'),
            'scope'        => $scope,
        );
    }

    /** RFC 7009 — selalu sukses dari sudut pandang client. */
    public function revoke(array $client, $token) {
        $row = $this->CI->Oauth_model->find_refresh_for_revoke(hash('sha256', (string) $token));
        if ($row !== NULL && $row['client_id'] === $client['client_id']) {
            $this->CI->Oauth_model->revoke_family($row['family_id']);
        }
    }

    /* ============================================================ internal */

    private function live_session_and_user($session_id, $user_id) {
        $session = $this->CI->Session_model->find_active_by_id($session_id, $this->CI->config->item('session_idle_ttl'));
        $user    = $this->CI->User_model->find_by_id($user_id);

        if ($session === NULL || $user === NULL || $user['status'] !== 'active') {
            throw new Oauth_error('invalid_grant', 'sesi SSO sudah berakhir');
        }
        return array($session, $user);
    }

    private function new_refresh_token(array $r) {
        $token = random_token(32);
        $r['token_hash'] = hash('sha256', $token);
        $r['ttl'] = $this->CI->config->item('refresh_token_ttl');
        $this->CI->Oauth_model->insert_refresh($r);
        return $token;
    }

    private function append_query($uri, array $params) {
        return $uri . (strpos($uri, '?') === FALSE ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

class Oauth_error extends Exception {
    public $error;
    public $status;
    public $redirectable;
    public $redirect_uri;
    public $state;
    public $basic_auth;

    public function __construct($error, $description, $status = 400, $redirectable = FALSE,
                                $redirect_uri = NULL, $state = NULL, $basic_auth = FALSE) {
        parent::__construct($description);
        $this->error        = $error;
        $this->status       = $status;
        $this->redirectable = $redirectable;
        $this->redirect_uri = $redirect_uri;
        $this->state        = $state;
        $this->basic_auth   = $basic_auth;
    }
}
