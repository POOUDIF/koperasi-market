<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Seluruh URL IdP berada di bawah /account (routing berbasis path, satu domain).
| CI3 membaca REQUEST_URI apa adanya, jadi prefix "account" ikut ditulis di sini.
*/

$route['default_controller']   = 'home/index';
$route['404_override']         = 'notfound';
$route['translate_uri_dashes'] = FALSE;

$b = 'account';

/* ---------- Halaman akun ---------- */
$route[$b]['get']                       = 'home/index';
$route[$b . '/profile']['post']         = 'home/update_profile';
$route[$b . '/password']['post']        = 'home/change_password';
$route[$b . '/sessions/revoke']['post'] = 'home/revoke_session';

$route[$b . '/login']['get']   = 'login/form';
$route[$b . '/login']['post']  = 'login/submit';
$route[$b . '/logout']['post'] = 'login/logout';

$route[$b . '/register']['get']      = 'register/form';
$route[$b . '/register']['post']     = 'register/submit';
$route[$b . '/verify-email']['get']  = 'register/verify_form';
$route[$b . '/verify-email']['post'] = 'register/verify_submit';
$route[$b . '/resend-otp']['post']   = 'register/resend';

$route[$b . '/forgot-password']['get']  = 'password/forgot_form';
$route[$b . '/forgot-password']['post'] = 'password/forgot_submit';
$route[$b . '/reset-password']['get']   = 'password/reset_form';
$route[$b . '/reset-password']['post']  = 'password/reset_submit';

/* ---------- OpenID Connect ---------- */
$route[$b . '/.well-known/openid-configuration']['get'] = 'wellknown/openid_configuration';
$route[$b . '/.well-known/jwks.json']['get']            = 'wellknown/jwks';
$route[$b . '/oauth/authorize']['get']                  = 'oauth/authorize';
$route[$b . '/oauth/token']['post']                     = 'oauth/token';
$route[$b . '/oauth/userinfo']['get']                   = 'oauth/userinfo';
$route[$b . '/oauth/userinfo']['post']                  = 'oauth/userinfo';
$route[$b . '/oauth/revoke']['post']                    = 'oauth/revoke';
$route[$b . '/oauth/logout']['get']                     = 'oauth/end_session';
$route[$b . '/oauth/logout']['post']                    = 'oauth/end_session_confirm';

/* ---------- Admin platform ---------- */
$route[$b . '/admin/users']['get']                   = 'admin/users';
$route[$b . '/admin/users/(:num)/status']['post']    = 'admin/set_status/$1';

$route[$b . '/health']['get'] = 'health/index';
