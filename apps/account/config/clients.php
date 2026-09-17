<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Registri client OAuth/OIDC — sumber kebenaran untuk `php account.php cli/clients sync`.
| Secret TIDAK disimpan di sini, hanya nama variabel env-nya; yang masuk DB
| hanya hash SHA-256 (secret acak 256 bit, jadi hash cepat sudah aman).
|
| redirect_uris & post_logout_redirect_uris dicocokkan PERSIS, tanpa wildcard.
*/

$app_url = rtrim((string) env('APP_URL', 'https://jdc.shfopis.com'), '/');

$config['oauth_clients'] = array(

    'koperasi' => array(
        'name'                      => 'Koperasi Digital',
        'secret_env'                => 'CLIENT_KOPERASI_SECRET',
        'redirect_uris'             => array($app_url . '/koperasi/api/v1/sso/callback'),
        'post_logout_redirect_uris' => array($app_url . '/', $app_url . '/koperasi/'),
        'backchannel_logout_uri'    => $app_url . '/koperasi/api/v1/sso/backchannel-logout',
        'grant_types'               => array('authorization_code', 'refresh_token'),
        'scopes'                    => array('openid', 'profile', 'email', 'offline_access'),
        'audience'                  => NULL,
    ),

    'market' => array(
        'name'                      => 'Marketplace JDC',
        'secret_env'                => 'CLIENT_MARKET_SECRET',
        'redirect_uris'             => array($app_url . '/market/api/v1/sso/callback'),
        'post_logout_redirect_uris' => array($app_url . '/', $app_url . '/market/'),
        'backchannel_logout_uri'    => $app_url . '/market/api/v1/sso/backchannel-logout',
        'grant_types'               => array('authorization_code', 'refresh_token'),
        'scopes'                    => array('openid', 'profile', 'email', 'offline_access'),
        'audience'                  => NULL,
    ),

    // Identitas server marketplace saat memanggil API internal koperasi (§4.1).
    'market-server' => array(
        'name'                      => 'Marketplace JDC (server)',
        'secret_env'                => 'CLIENT_MARKET_SERVER_SECRET',
        'redirect_uris'             => array(),
        'post_logout_redirect_uris' => array(),
        'backchannel_logout_uri'    => NULL,
        'grant_types'               => array('client_credentials'),
        'scopes'                    => array('koperasi.members.read', 'koperasi.payments.write'),
        'audience'                  => 'koperasi',
    ),
);
