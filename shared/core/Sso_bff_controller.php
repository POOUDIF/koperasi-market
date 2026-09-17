<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Endpoint SSO pola BFF yang identik di setiap layanan client (koperasi,
 * market). Subclass cukup mengimplementasikan provision_user() — JIT
 * provisioning ke tabel user milik layanan itu (§3.6).
 *
 *   GET  <base>/api/v1/sso/login?return_to=&prompt=
 *   GET  <base>/api/v1/sso/callback
 *   POST <base>/api/v1/sso/logout                 (XHR, balas redirect_url ke IdP)
 *   POST <base>/api/v1/sso/backchannel-logout     (dipanggil server IdP)
 *
 * WAJIB di-require SETELAH MY_Controller layanan (butuh API_Controller).
 */
abstract class Sso_bff_controller extends API_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('Oidc_client', 'Bff_session'));
    }

    /**
     * Buat/sinkronkan user lokal dari klaim ID token.
     * @return int id user lokal
     * @throws Api_exception untuk menolak login (mis. konflik penautan akun)
     */
    abstract protected function provision_user(array $claims);

    protected function base_path() {
        return rtrim($this->config->item('bff_session')['path'], '/');
    }

    /** GET /sso/login */
    public function login() {
        $return_to = Request_guard::safe_return_to(
            $this->input->get('return_to'), $this->base_path(), $this->base_path() . '/');

        $extra  = array();
        $prompt = $this->input->get('prompt');
        if (in_array($prompt, array('login', 'none'), TRUE)) {
            $extra['prompt'] = $prompt;
        }

        try {
            $tx  = $this->bff_session->begin_login($return_to);
            $url = $this->oidc_client->authorization_url($tx['state'], $tx['nonce'], $tx['verifier'], $extra);
        } catch (Throwable $e) {
            log_message('error', '[sso] gagal memulai login: ' . $e->getMessage());
            return $this->redirect_to($this->base_path() . '/auth/error?code=idp_unavailable');
        }

        return $this->redirect_to($url);
    }

    /** GET /sso/callback */
    public function callback() {
        $error_page = $this->base_path() . '/auth/error?code=';

        try {
            $tx = $this->bff_session->consume_login($this->input->get('state'));
        } catch (Throwable $e) {
            log_message('error', '[sso] redis tidak tersedia saat callback: ' . $e->getMessage());
            return $this->redirect_to($error_page . 'session_unavailable');
        }
        if ($tx === NULL) {
            return $this->redirect_to($error_page . 'invalid_state');
        }

        $err = $this->input->get('error');
        if (is_string($err) && $err !== '') {
            // prompt=none tanpa sesi IdP → kembali sebagai tamu, bukan halaman error.
            if (in_array($err, array('login_required', 'interaction_required'), TRUE)) {
                return $this->redirect_to($tx['return_to']);
            }
            return $this->redirect_to($error_page . 'access_denied');
        }

        // RFC 9207: parameter iss mencegah mix-up attack.
        $iss = $this->input->get('iss');
        if ($iss !== NULL && $iss !== rtrim($this->oidc_client->config('issuer'), '/')) {
            return $this->redirect_to($error_page . 'invalid_issuer');
        }

        $code = $this->input->get('code');
        if ( ! is_string($code) || $code === '') {
            return $this->redirect_to($error_page . 'invalid_request');
        }

        try {
            $tok    = $this->oidc_client->exchange_code($code, $tx['verifier']);
            $claims = $this->oidc_client->verify_id_token($tok['id_token'] ?? '', $tx['nonce']);
        } catch (Throwable $e) {
            log_message('error', '[sso] penukaran code / verifikasi id_token gagal: ' . $e->getMessage());
            return $this->redirect_to($error_page . 'token_invalid');
        }

        try {
            $user_id = $this->provision_user($claims);
        } catch (Api_exception $e) {
            log_message('error', '[sso] provisioning ditolak sub=' . $claims['sub'] . ': ' . $e->getMessage());
            return $this->redirect_to($error_page . strtolower($e->code_name));
        } catch (Throwable $e) {
            log_message('error', '[sso] provisioning gagal: ' . $e->getMessage());
            return $this->redirect_to($error_page . 'server_error');
        }

        try {
            $this->bff_session->create(array(
                'user_id'           => $user_id,
                'sub'               => $claims['sub'],
                'idp_sid'           => $claims['sid'] ?? '',
                'id_token'          => $tok['id_token'],
                'refresh_token'     => $tok['refresh_token'] ?? NULL,
                'access_expires_at' => time() + (int) ($tok['expires_in'] ?? 900),
                'auth_time'         => (int) ($claims['auth_time'] ?? time()),
            ));
        } catch (Throwable $e) {
            log_message('error', '[sso] gagal membuat sesi: ' . $e->getMessage());
            return $this->redirect_to($error_page . 'session_unavailable');
        }

        return $this->redirect_to($tx['return_to']);
    }

    /** POST /sso/logout — balas URL end_session IdP; SPA yang menavigasi. */
    public function logout() {
        if ( ! Request_guard::is_same_origin_xhr()) {
            $this->fail(Api_exception::forbidden('permintaan ditolak (CSRF)'));
        }

        $this->run(function () {
            $fallback = $this->oidc_client->config('post_logout_redirect_uri');

            try {
                $s = $this->bff_session->destroy_current();
            } catch (Throwable $e) {
                log_message('error', '[sso] gagal menghapus sesi lokal: ' . $e->getMessage());
                $s = NULL;
            }

            if ($s === NULL) {
                return $this->ok(array('redirect_url' => $fallback), 200);
            }

            $refresh = $this->bff_session->decrypt_refresh_token($s);
            if ($refresh !== NULL) {
                $this->oidc_client->revoke($refresh);
            }

            try {
                $url = $this->oidc_client->end_session_url($s['id_token']);
            } catch (Throwable $e) {
                $url = $fallback;
            }
            return $this->ok(array('redirect_url' => $url), 200);
        });
    }

    /** POST /sso/backchannel-logout — Back-Channel Logout 1.0 §2.5. */
    public function backchannel_logout() {
        $this->output->set_header('Cache-Control: no-store');

        $token = $this->input->post('logout_token');
        if ( ! is_string($token) || $token === '') {
            return $this->ok(array('error' => 'invalid_request'), 400);
        }

        try {
            $claims = $this->oidc_client->verify_logout_token($token);
        } catch (Throwable $e) {
            log_message('error', '[sso] logout_token ditolak: ' . $e->getMessage());
            return $this->ok(array('error' => 'invalid_request'), 400);
        }

        try {
            $n = ! empty($claims['sid'])
                ? $this->bff_session->destroy_by_idp_sid($claims['sid'])
                : $this->bff_session->destroy_by_sub($claims['sub']);
        } catch (Throwable $e) {
            log_message('error', '[sso] gagal menghapus sesi back-channel: ' . $e->getMessage());
            return $this->ok(array('error' => 'temporarily_unavailable'), 503);
        }

        log_message('info', '[sso] back-channel logout sid=' . ($claims['sid'] ?? '-') . " sesi_dihapus={$n}");
        return $this->ok(array('status' => 'ok'), 200);
    }

    protected function redirect_to($url) {
        $this->output
            ->set_status_header(302)
            ->set_header('Location: ' . $url)
            ->set_header('Cache-Control: no-store')
            ->set_header('Referrer-Policy: no-referrer');
    }
}
