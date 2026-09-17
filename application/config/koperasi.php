<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/* Konstanta bisnis — §8.1 */

$config['jwt_secret']    = (string) env('JWT_SECRET', '');
$config['jwt_ttl_hours'] = (int) env('JWT_TOKEN_TTL_HOURS', 24);
$config['jwt_issuer']    = 'koperasi-digital';

$config['murabahah_margin_rate'] = (string) env('MURABAHAH_MARGIN_RATE', '0.10');
$config['financing_max_months']  = 360;

$config['gold_max_gram_per_tx'] = (string) env('GOLD_MAX_GRAM_PER_TX', '100');
$config['gold_min_gram']        = '0.0001';
$config['gold_price_cache_key'] = 'gold:current_price';
$config['gold_price_cache_ttl'] = (int) env('GOLD_PRICE_CACHE_TTL', 900);
$config['gold_mint_queue_key']  = 'queue:gold_mint';
$config['gold_decimals']        = 4;

$config['otp_ttl_seconds'] = (int) env('OTP_TTL_SECONDS', 900);
$config['bcrypt_cost']     = 12;
$config['money_scale']     = 4;

/* Rate limit: token bucket Go = 3 rps burst 5; di sini 5 request / detik per IP. */
$config['rate_limit_burst'] = 5;

$config['roles_admin'] = array('pengurus', 'admin', 'super_admin');

/* Paginasi endpoint admin — perbaikan CACAT-09 */
$config['page_size_default'] = 50;
$config['page_size_max']     = 200;

/*
| --------------------------------------------------------------------------
| SSO — koperasi sebagai client OIDC pola BFF (DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §3, §5.1)
| --------------------------------------------------------------------------
| AUTH_MODE:
|   legacy = hanya Bearer JWT lama (sebelum cutover)
|   both   = cookie sesi SSO dulu, fallback Bearer (masa transisi)
|   sso    = hanya cookie sesi SSO (target produksi)
*/
$app_url = rtrim((string) env('APP_URL', 'https://jdc.shfopis.com'), '/');

$config['app_url']   = $app_url;
$config['auth_mode'] = in_array(env('AUTH_MODE', 'sso'), array('legacy', 'both', 'sso'), TRUE) ? env('AUTH_MODE', 'sso') : 'sso';

$config['sso'] = array(
    'issuer'                   => rtrim((string) env('SSO_ISSUER', $app_url . '/account'), '/'),
    'client_id'                => (string) env('SSO_CLIENT_ID', 'koperasi'),
    'client_secret'            => (string) env('SSO_CLIENT_SECRET', ''),
    'redirect_uri'             => $app_url . '/koperasi/api/v1/sso/callback',
    'post_logout_redirect_uri' => $app_url . '/',
    'scopes'                   => 'openid profile email offline_access',
);

$config['bff_session'] = array(
    'cookie_name'   => 'kop_sid',
    'path'          => '/koperasi',
    'sso_path'      => '/koperasi/api/v1/sso',
    'secure'        => env_bool('COOKIE_SECURE', TRUE),
    // Lebih ketat dari marketplace karena menyangkut uang anggota (§3.5).
    'idle_ttl'      => (int) env('SESSION_IDLE_TTL', 1800),
    'absolute_ttl'  => (int) env('SESSION_ABSOLUTE_TTL', 28800),
    'refresh_grace' => 300,
);

// Step-up: aksi sensitif (ubah PIN transaksi) butuh login ulang dalam jendela ini.
$config['recent_auth_seconds'] = 600;

/*
| --------------------------------------------------------------------------
| API internal & Koperasi Pay (§4)
| --------------------------------------------------------------------------
*/
$config['internal_audience'] = 'koperasi';

// Client layanan yang boleh membuat tagihan. Kunci = client_id di IdP.
$config['payment_clients'] = array(
    'market-server' => array(
        'display_name'      => 'Marketplace JDC',
        'webhook_url'       => (string) env('MARKET_WEBHOOK_URL', $app_url . '/market/api/v1/webhooks/koperasi'),
        'webhook_secret'    => (string) env('MARKET_WEBHOOK_SECRET', ''),
        'return_url_prefix' => $app_url . '/market/',
    ),
);

$config['payment_intent_ttl']     = 1800;                            // 30 menit untuk konfirmasi
$config['payment_max_amount']     = (string) env('PAYMENT_MAX_AMOUNT', '50000000');
$config['escrow_product_name']    = 'Rekening Penampung Marketplace';
$config['escrow_user_email']      = 'escrow.marketplace@system.internal';
$config['payee_product_name']     = 'Simpanan Sukarela';

$config['pin_max_attempts']  = 5;
$config['pin_lock_minutes']  = 30;
