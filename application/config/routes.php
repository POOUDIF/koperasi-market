<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/* Peta routing lengkap — §18.1 (24 endpoint + tambahan admin harga emas) */

$route['default_controller']   = 'api/v1/health';
$route['404_override']         = 'api/v1/notfound';
$route['translate_uri_dashes'] = FALSE;

$api = 'api/v1';

/* ---------- Publik ---------- */
$route[$api . '/health']['get']        = 'api/v1/health/index';
$route[$api . '/register']['post']     = 'api/v1/auth/register';
$route[$api . '/login']['post']        = 'api/v1/auth/login';
$route[$api . '/verify-email']['post'] = 'api/v1/auth/verify_email';
/* Perbaikan usulan CACAT-06 (README): OTP kedaluwarsa tidak lagi butuh bantuan admin manual. */
$route[$api . '/resend-otp']['post']   = 'api/v1/auth/resend_otp';
$route[$api . '/gold/price']['get']    = 'api/v1/gold/price';

/* ---------- Terproteksi (JWT + akun aktif) ---------- */
$route[$api . '/logout']['post']     = 'api/v1/auth/logout';
$route[$api . '/profile']['get']     = 'api/v1/profile/index';
$route[$api . '/profile/kyc']['get'] = 'api/v1/profile/get_kyc';
$route[$api . '/profile/kyc']['put'] = 'api/v1/profile/update_kyc';

$route[$api . '/savings/accounts']['post']        = 'api/v1/savings/open_account';
$route[$api . '/savings/accounts']['get']         = 'api/v1/savings/accounts';
$route[$api . '/savings/products']['get']         = 'api/v1/savings/products';
$route[$api . '/savings/deposit']['post']         = 'api/v1/savings/deposit';
$route[$api . '/savings/deposit-requests']['get'] = 'api/v1/savings/deposit_requests';
/* Perbaikan CACAT-12: alur persetujuan seperti deposit, tapi arah uang terbalik. */
$route[$api . '/savings/withdraw']['post']          = 'api/v1/savings/withdraw';
$route[$api . '/savings/withdraw-requests']['get']  = 'api/v1/savings/withdraw_requests';

$route[$api . '/financing/apply']['post']                   = 'api/v1/financing/apply';
$route[$api . '/financing']['get']                          = 'api/v1/financing/index';
$route[$api . '/financing/(:num)/installments']['get']      = 'api/v1/financing/installments/$1';
$route[$api . '/financing/installments/(:num)/pay']['post'] = 'api/v1/financing/pay/$1';

$route[$api . '/gold/buy']['post']     = 'api/v1/gold/buy';
$route[$api . '/gold/sell']['post']    = 'api/v1/gold/sell';
$route[$api . '/gold/holding']['get']  = 'api/v1/gold/holding';

/* ---------- Admin (JWT + akun aktif + role pengurus|admin|super_admin) ---------- */
$route[$api . '/admin/financing/(:num)/review']['put']                = 'api/v1/admin/review_financing/$1';
$route[$api . '/admin/savings/deposit-requests/(:num)/review']['put'] = 'api/v1/admin/review_deposit/$1';
$route[$api . '/admin/savings/deposit-requests']['get']               = 'api/v1/admin/deposit_requests';
/* Perbaikan CACAT-12 */
$route[$api . '/admin/savings/withdraw-requests/(:num)/review']['put'] = 'api/v1/admin/review_withdraw/$1';
$route[$api . '/admin/savings/withdraw-requests']['get']               = 'api/v1/admin/withdraw_requests';
$route[$api . '/admin/users']['get']                                  = 'api/v1/admin/users';
$route[$api . '/admin/transactions/financing']['get']                 = 'api/v1/admin/tx_financing';
$route[$api . '/admin/transactions/gold']['get']                      = 'api/v1/admin/tx_gold';
$route[$api . '/admin/transactions/saving']['get']                    = 'api/v1/admin/tx_saving';
/* Perbaikan CACAT-08 — manajemen harga emas + invalidasi cache */
$route[$api . '/admin/gold/price']['post']                            = 'api/v1/admin/set_gold_price';
