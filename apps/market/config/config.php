<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Konfigurasi inti CodeIgniter. Autoload composer, env() dan .env sudah
| dimuat shared/bootstrap.php sebelum file ini dibaca.
*/
require_once SHAREDPATH . 'libraries/Money.php';
require_once SHAREDPATH . 'core/Request_guard.php';
require_once APPPATH . 'libraries/Api_exception.php';

date_default_timezone_set('Asia/Jakarta');

if (PHP_SAPI !== 'cli') {
    ini_set('max_execution_time', 20);
}

$config['base_url']             = rtrim((string) env('APP_URL', ''), '/');
$config['index_page']           = '';
$config['uri_protocol']         = 'REQUEST_URI';
$config['url_suffix']           = '';
$config['language']             = 'english';
$config['charset']              = 'UTF-8';
$config['enable_hooks']         = FALSE;
$config['subclass_prefix']      = 'MY_';
$config['composer_autoload']    = FALSE;
// '@' dan '+' hanya untuk argumen CLI (mis. cli/admin promote <email>).
$config['permitted_uri_chars']  = (PHP_SAPI === 'cli') ? 'a-z 0-9~%.:_\-@+' : 'a-z 0-9~%.:_\-';
$config['enable_query_strings'] = FALSE;
$config['controller_trigger']   = 'c';
$config['function_trigger']     = 'm';
$config['directory_trigger']    = 'd';
$config['allow_get_array']      = TRUE;

$config['log_threshold']        = (ENVIRONMENT === 'production') ? 1 : 3;
$config['log_path']             = '';
$config['log_file_extension']   = '';
$config['log_file_permissions'] = 0644;
$config['log_date_format']      = 'Y-m-d H:i:s';
$config['error_views_path']     = '';
$config['cache_path']           = '';
$config['cache_query_string']   = FALSE;
$config['encryption_key']       = '';

// Session bawaan CI3 TIDAK dipakai — sesi dikelola sendiri (lihat libraries).
$config['sess_driver']             = 'files';
$config['sess_cookie_name']        = 'ci_session';
$config['sess_samesite']           = 'Lax';
$config['sess_expiration']         = 7200;
$config['sess_save_path']          = NULL;
$config['sess_match_ip']           = FALSE;
$config['sess_time_to_update']     = 300;
$config['sess_regenerate_destroy'] = FALSE;

$config['cookie_prefix']   = '';
$config['cookie_domain']   = '';
$config['cookie_path']     = '/';
$config['cookie_secure']   = env_bool('COOKIE_SECURE', TRUE);
$config['cookie_httponly'] = TRUE;
$config['cookie_samesite'] = 'Lax';

$config['standardize_newlines'] = FALSE;
$config['global_xss_filtering'] = FALSE;
$config['csrf_protection']      = FALSE;   // CSRF ditangani per layanan
$config['csrf_token_name']      = 'csrf_test_name';
$config['csrf_cookie_name']     = 'csrf_cookie_name';
$config['csrf_expire']          = 7200;
$config['csrf_regenerate']      = TRUE;
$config['csrf_exclude_uris']    = array();
$config['compress_output']      = FALSE;
$config['time_reference']       = 'local';
$config['rewrite_short_tags']   = FALSE;
// Isi dengan IP reverse proxy/CDN (mis. Cloudflare) agar ip_address() benar.
$config['proxy_ips']            = (string) env('PROXY_IPS', '');
