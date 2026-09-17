<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * OpenID Provider Metadata (OIDC Discovery 1.0) dan JWKS. Publik & cacheable.
 */
class Wellknown extends Base_Controller {

    /** GET /account/.well-known/openid-configuration */
    public function openid_configuration() {
        $iss = $this->config->item('issuer');

        $this->public_json(array(
            'issuer'                                => $iss,
            'authorization_endpoint'                => $iss . '/oauth/authorize',
            'token_endpoint'                        => $iss . '/oauth/token',
            'userinfo_endpoint'                     => $iss . '/oauth/userinfo',
            'revocation_endpoint'                   => $iss . '/oauth/revoke',
            'end_session_endpoint'                  => $iss . '/oauth/logout',
            'jwks_uri'                              => $iss . '/.well-known/jwks.json',
            'response_types_supported'              => array('code'),
            'response_modes_supported'              => array('query'),
            'grant_types_supported'                 => array('authorization_code', 'refresh_token', 'client_credentials'),
            'subject_types_supported'               => array('public'),
            'id_token_signing_alg_values_supported' => array('RS256'),
            'scopes_supported'                      => $this->config->item('scopes_supported'),
            'claims_supported'                      => array('sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'sid', 'name', 'email', 'email_verified'),
            'token_endpoint_auth_methods_supported' => array('client_secret_basic', 'client_secret_post'),
            'code_challenge_methods_supported'      => array('S256'),
            'prompt_values_supported'               => array('none', 'login'),
            'backchannel_logout_supported'          => TRUE,
            'backchannel_logout_session_supported'  => TRUE,
            'authorization_response_iss_parameter_supported' => TRUE,
        ), 3600);
    }

    /** GET /account/.well-known/jwks.json */
    public function jwks() {
        try {
            $this->public_json($this->token_service_jwks(), 300);
        } catch (Throwable $e) {
            log_message('error', '[jwks] ' . $e->getMessage());
            $this->json(array('error' => 'server_error'), 500);
        }
    }

    private function token_service_jwks() {
        $this->load->library('Token_service');
        return $this->token_service->jwks();
    }

    private function public_json($data, $max_age) {
        $this->output
            ->set_status_header(200)
            ->set_content_type('application/json', 'utf-8')
            ->set_header('Cache-Control: public, max-age=' . (int) $max_age)
            ->set_header('Access-Control-Allow-Origin: *')
            ->set_header('X-Content-Type-Options: nosniff')
            ->set_output(json_encode($data, JSON_UNESCAPED_SLASHES));
    }
}
