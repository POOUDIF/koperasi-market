<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Endpoint OAuth 2.0 / OpenID Connect.
 */
class Oauth extends Base_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('Oauth_server', 'Idp_session', 'Token_service'));
    }

    /** GET /account/oauth/authorize */
    public function authorize() {
        $q = $this->input->get(NULL, FALSE) ?: array();
        $q = array_filter($q, 'is_string');

        try {
            $req = $this->oauth_server->validate_authorize($q);
        } catch (Oauth_error $e) {
            if ($e->redirectable) {
                return $this->redirect_to($this->oauth_server->error_redirect_url($e));
            }
            return $this->render_error(400, 'Permintaan masuk tidak valid', $e->getMessage());
        }

        $session = $this->idp_session->current();

        $needs_login = $session === NULL
            || ($req['prompt'] === 'login' && ! isset($q['auth_after']))
            || ($req['auth_after'] > 0 && (int) $session['auth_time'] < $req['auth_after']);

        if ($needs_login) {
            if ($req['prompt'] === 'none') {
                $e = new Oauth_error('login_required', 'pengguna belum masuk', 400, TRUE, $req['redirect_uri'], $req['state']);
                return $this->redirect_to($this->oauth_server->error_redirect_url($e));
            }

            $auth_after = $req['prompt'] === 'login' ? time() : $req['auth_after'];
            $return = $this->oauth_server->authorize_return_url($q, $auth_after);

            return $this->redirect_to($this->config->item('base_path') . '/login?return=' . rawurlencode($return)
                . ($session !== NULL ? '&reauth=1' : ''));
        }

        return $this->redirect_to($this->oauth_server->issue_code($req, $session));
    }

    /** POST /account/oauth/token */
    public function token() {
        try {
            $this->ratelimit->check('token', 30, 1);
            $client = $this->oauth_server->authenticate_client();
            $post   = array_filter($this->input->post(NULL) ?: array(), 'is_string');
            return $this->json($this->oauth_server->token($client, $post), 200);

        } catch (Oauth_error $e) {
            if ($e->basic_auth) {
                $this->output->set_header('WWW-Authenticate: Basic realm="jdc-account"');
            }
            return $this->json(array('error' => $e->error, 'error_description' => $e->getMessage()), $e->status);
        } catch (Api_exception $e) {
            return $this->json(array('error' => $e->status === 429 ? 'slow_down' : 'invalid_request',
                'error_description' => $e->getMessage()), $e->status);
        } catch (Throwable $e) {
            log_message('error', '[oauth/token] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return $this->json(array('error' => 'server_error'), 500);
        }
    }

    /** GET|POST /account/oauth/userinfo */
    public function userinfo() {
        $auth = (string) $this->input->get_request_header('Authorization', FALSE);
        $jwt  = stripos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : '';
        $c    = $jwt !== '' ? $this->token_service->verify($jwt) : NULL;

        $user = ($c !== NULL && ($c['token_use'] ?? NULL) === 'user') ? $this->User_model->find_by_id($c['sub']) : NULL;
        if ($user === NULL || $user['status'] !== 'active') {
            $this->output->set_header('WWW-Authenticate: Bearer error="invalid_token"');
            return $this->json(array('error' => 'invalid_token'), 401);
        }

        $scopes = explode(' ', (string) $c['scope']);
        return $this->json(array('sub' => (string) $user['id']) + $this->token_service->user_claims($user, $scopes), 200);
    }

    /** POST /account/oauth/revoke (RFC 7009) */
    public function revoke() {
        try {
            $client = $this->oauth_server->authenticate_client();
            $this->oauth_server->revoke($client, (string) $this->input->post('token'));
            return $this->json(new stdClass(), 200);
        } catch (Oauth_error $e) {
            return $this->json(array('error' => $e->error), $e->status);
        }
    }

    /**
     * GET /account/oauth/logout — OIDC RP-Initiated Logout 1.0.
     *
     * Logout langsung hanya bila id_token_hint sah DAN milik sesi browser ini;
     * selain itu tampilkan konfirmasi (mencegah logout CSRF lewat tautan).
     */
    public function end_session() {
        $hint   = (string) $this->input->get('id_token_hint');
        $claims = $hint !== '' ? $this->token_service->verify($hint, TRUE) : NULL;

        $client_id = $claims['aud'] ?? (string) $this->input->get('client_id');
        $client_id = is_array($client_id) ? (string) reset($client_id) : (string) $client_id;
        $redirect  = $this->validated_post_logout_uri($client_id, (string) $this->input->get('post_logout_redirect_uri'));
        $state     = (string) $this->input->get('state');

        $session = $this->idp_session->current();

        if ($session === NULL) {
            return $this->finish_logout($redirect, $state);
        }
        if ($claims !== NULL && isset($claims['sid']) && hash_equals($session['sid'], (string) $claims['sid'])) {
            $this->idp_session->logout('rp_logout');
            return $this->finish_logout($redirect, $state);
        }

        return $this->render('logout_confirm', array(
            'title'    => 'Keluar dari JDC',
            'user'     => $session['user'],
            'redirect' => $redirect,
            'state'    => $state,
        ));
    }

    /** POST /account/oauth/logout — konfirmasi dari halaman di atas. */
    public function end_session_confirm() {
        $this->load->library('Csrf');
        if ( ! $this->csrf->valid()) {
            return $this->render_error(403, 'Permintaan ditolak', 'Formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.');
        }

        $redirect = (string) $this->input->post('redirect');
        // Nilai ini berasal dari form kita sendiri, tapi tetap dicek ulang terhadap semua client.
        if ($redirect !== '' && ! $this->is_registered_post_logout_uri($redirect)) {
            $redirect = '';
        }
        $this->idp_session->logout('logout_confirmed');
        return $this->finish_logout($redirect, (string) $this->input->post('state'));
    }

    private function finish_logout($redirect, $state) {
        if ($redirect === '') {
            return $this->redirect_to($this->config->item('base_path') . '/login?msg=logged_out');
        }
        if ($state !== '') {
            $redirect .= (strpos($redirect, '?') === FALSE ? '?' : '&') . 'state=' . rawurlencode($state);
        }
        return $this->redirect_to($redirect);
    }

    private function validated_post_logout_uri($client_id, $uri) {
        if ($uri === '' || $client_id === '') { return ''; }
        $client = $this->Oauth_model->find_client($client_id);
        return ($client !== NULL && in_array($uri, $client['post_logout_redirect_uris'], TRUE)) ? $uri : '';
    }

    private function is_registered_post_logout_uri($uri) {
        foreach ($this->Oauth_model->all_clients() as $c) {
            $full = $this->Oauth_model->find_client($c['client_id']);
            if ($full !== NULL && in_array($uri, $full['post_logout_redirect_uris'], TRUE)) { return TRUE; }
        }
        return FALSE;
    }
}
