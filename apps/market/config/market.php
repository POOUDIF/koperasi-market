<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Konfigurasi Marketplace JDC. Lihat DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md.
*/

$app_url = rtrim((string) env('APP_URL', 'https://jdc.shfopis.com'), '/');

$config['app_url'] = $app_url;

/* SSO — client "market" di JDC Account (pola BFF, §3) */
$config['sso'] = array(
    'issuer'                   => rtrim((string) env('SSO_ISSUER', $app_url . '/account'), '/'),
    'client_id'                => (string) env('SSO_CLIENT_ID', 'market'),
    'client_secret'            => (string) env('SSO_CLIENT_SECRET', ''),
    'redirect_uri'             => $app_url . '/market/api/v1/sso/callback',
    'post_logout_redirect_uri' => $app_url . '/',
    'scopes'                   => 'openid profile email offline_access',
);

$config['bff_session'] = array(
    'cookie_name'   => 'mkt_sid',
    'path'          => '/market',
    'sso_path'      => '/market/api/v1/sso',
    'secure'        => env_bool('COOKIE_SECURE', TRUE),
    'idle_ttl'      => (int) env('SESSION_IDLE_TTL', 604800),      // 7 hari (pola e-commerce, §3.5)
    'absolute_ttl'  => (int) env('SESSION_ABSOLUTE_TTL', 2592000), // 30 hari
    'refresh_grace' => 900,
);

/* Identitas server marketplace ke API internal koperasi (§4.1) */
$config['service_client'] = array(
    'client_id'     => (string) env('SERVICE_CLIENT_ID', 'market-server'),
    'client_secret' => (string) env('SERVICE_CLIENT_SECRET', ''),
);
$config['koperasi_internal_base'] = rtrim((string) env('KOPERASI_INTERNAL_BASE', $app_url . '/koperasi/api/v1/internal'), '/');
$config['koperasi_scopes']        = 'koperasi.members.read koperasi.payments.write';
$config['koperasi_webhook_secret'] = (string) env('KOPERASI_WEBHOOK_SECRET', '');
$config['member_cache_ttl']       = 60;

/* Aturan pesanan */
$config['unpaid_order_ttl_hours']   = 24;
$config['auto_complete_after_days'] = 7;
$config['max_qty_per_item']         = 99;

$config['page_size_default'] = 24;
$config['page_size_max']     = 100;
