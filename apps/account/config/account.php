<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Konfigurasi JDC Account (Identity Provider OpenID Connect).
| Nilai waktu dalam detik. Lihat DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §3.5.
*/

$app_url = rtrim((string) env('APP_URL', 'https://jdc.shfopis.com'), '/');

$config['app_url']   = $app_url;
$config['base_path'] = '/account';
// Issuer = URL dasar IdP; discovery ada di <issuer>/.well-known/openid-configuration.
$config['issuer']    = $app_url . '/account';

$config['cookie_secure'] = env_bool('COOKIE_SECURE', TRUE);

/* Sesi SSO di IdP */
$config['session_idle_ttl']     = (int) env('IDP_SESSION_IDLE_TTL', 7200);       // 2 jam
$config['session_absolute_ttl'] = (int) env('IDP_SESSION_ABSOLUTE_TTL', 43200);  // 12 jam
$config['session_remember_ttl'] = (int) env('IDP_SESSION_REMEMBER_TTL', 2592000); // 30 hari ("Ingat saya")

/* Token */
$config['code_ttl']          = 60;
$config['access_token_ttl']  = (int) env('ACCESS_TOKEN_TTL', 900);     // 15 menit
$config['id_token_ttl']      = 600;
$config['refresh_token_ttl'] = (int) env('REFRESH_TOKEN_TTL', 2592000); // 30 hari
// Rotasi refresh token yang dipakai ulang dalam jendela ini dianggap race
// (dua request paralel), bukan pencurian — ditolak tanpa mencabut keluarga token.
$config['refresh_reuse_grace'] = 30;

$config['keys_dir'] = rtrim((string) env('OIDC_KEYS_DIR', APPPATH . 'keys'), '/\\') . DIRECTORY_SEPARATOR;

$config['scopes_supported'] = array('openid', 'profile', 'email', 'offline_access',
    'koperasi.members.read', 'koperasi.payments.write');

/* Kredensial & keamanan akun */
$config['bcrypt_cost']          = 12;
$config['password_min']         = 8;
$config['otp_ttl']              = (int) env('OTP_TTL_SECONDS', 900);
$config['otp_max_attempts']     = 5;
$config['reset_token_ttl']      = 3600;
$config['login_max_failures']   = 5;    // per email ...
$config['login_failure_window'] = 900;  // ... per 15 menit
// Pepper HMAC untuk hash OTP & token email. Wajib diisi di produksi.
$config['token_pepper'] = (string) env('APP_KEY', '');

$config['page_size_default'] = 25;
$config['page_size_max']     = 100;

/* Tautan layanan di halaman Akun Saya */
$config['services'] = array(
    array('name' => 'Beranda JDC',      'url' => $app_url . '/',                    'desc' => 'Profil Jawa Dwipa Cooperative'),
    array('name' => 'Koperasi Digital', 'url' => $app_url . '/koperasi/dashboard',  'desc' => 'Simpanan, pembiayaan, dan emas digital'),
    array('name' => 'Marketplace',      'url' => $app_url . '/market/',             'desc' => 'Belanja produk anggota koperasi'),
);
