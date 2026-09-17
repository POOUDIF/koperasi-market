<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/* Peta routing lengkap — §18.1 (24 endpoint + tambahan admin harga emas)
 *
 * Sejak routing berbasis path (DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §2.1)
 * API koperasi berada di /koperasi/api/v1. URL lama /api/v1/* dialihkan 308
 * oleh .htaccess root, sehingga key route di bawah memuat prefix 'koperasi'. */

$route['default_controller']   = 'notfound';
$route['404_override']         = 'notfound';
$route['translate_uri_dashes'] = FALSE;

$api = 'koperasi/api/v1';

/* ---------- Publik ---------- */
$route[$api . '/health']['get']        = 'api/v1/health/index';
$route[$api . '/register']['post']     = 'api/v1/auth/register';
$route[$api . '/login']['post']        = 'api/v1/auth/login';
$route[$api . '/verify-email']['post'] = 'api/v1/auth/verify_email';
/* Perbaikan usulan CACAT-06 (README): OTP kedaluwarsa tidak lagi butuh bantuan admin manual. */
$route[$api . '/resend-otp']['post']   = 'api/v1/auth/resend_otp';
$route[$api . '/gold/price']['get']    = 'api/v1/gold/price';

/* ---------- Terproteksi (sesi SSO + akun aktif [+ keanggotaan]) ---------- */
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

/* ---------- Riwayat transaksi & notifikasi (fitur baru, di luar blueprint) ---------- */
$route[$api . '/transactions']['get']                = 'api/v1/transactions/index';
$route[$api . '/notifications']['get']               = 'api/v1/notifications/index';
$route[$api . '/notifications/unread-count']['get']  = 'api/v1/notifications/unread_count';
$route[$api . '/notifications/read-all']['put']      = 'api/v1/notifications/mark_all_read';
$route[$api . '/notifications/(:num)/read']['put']   = 'api/v1/notifications/mark_read/$1';

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

/* ---------- SSO — pola BFF (§3) ---------- */
$route[$api . '/sso/login']['get']               = 'api/v1/sso/login';
$route[$api . '/sso/callback']['get']            = 'api/v1/sso/callback';
$route[$api . '/sso/logout']['post']             = 'api/v1/sso/logout';
$route[$api . '/sso/backchannel-logout']['post'] = 'api/v1/sso/backchannel_logout';

/* ---------- Keanggotaan & PIN transaksi ---------- */
$route[$api . '/membership']['get']           = 'api/v1/membership/index';
$route[$api . '/membership/activate']['post'] = 'api/v1/membership/activate';
$route[$api . '/security/pin']['get']         = 'api/v1/security/pin_status';
$route[$api . '/security/pin']['put']         = 'api/v1/security/set_pin';

/* ---------- Koperasi Pay — sisi anggota (§4.3) ---------- */
$route[$api . '/payments/(pi_[a-f0-9]{24})']['get']          = 'api/v1/payments/show/$1';
$route[$api . '/payments/(pi_[a-f0-9]{24})/confirm']['post'] = 'api/v1/payments/confirm/$1';
$route[$api . '/payments/(pi_[a-f0-9]{24})/cancel']['post']  = 'api/v1/payments/cancel/$1';

/* ---------- API internal antar layanan (token client_credentials, §4.1) ---------- */
$route[$api . '/internal/members/([A-Za-z0-9_-]+)']['get']                  = 'api/v1/internal/members/show/$1';
$route[$api . '/internal/payment-intents']['post']                          = 'api/v1/internal/payment_intents/create';
$route[$api . '/internal/payment-intents/(pi_[a-f0-9]{24})']['get']         = 'api/v1/internal/payment_intents/show/$1';
$route[$api . '/internal/payment-intents/(pi_[a-f0-9]{24})/settle']['post'] = 'api/v1/internal/payment_intents/settle/$1';
$route[$api . '/internal/payment-intents/(pi_[a-f0-9]{24})/refund']['post'] = 'api/v1/internal/payment_intents/refund/$1';
$route[$api . '/internal/payment-intents/(pi_[a-f0-9]{24})/cancel']['post'] = 'api/v1/internal/payment_intents/cancel/$1';
