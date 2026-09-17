<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| API Marketplace di /market/api/v1 (routing berbasis path, satu domain).
*/

$route['default_controller']   = 'notfound';
$route['404_override']         = 'notfound';
$route['translate_uri_dashes'] = FALSE;

$api = 'market/api/v1';

$route[$api . '/health']['get'] = 'api/v1/health/index';

/* ---------- SSO (BFF) ---------- */
$route[$api . '/sso/login']['get']               = 'api/v1/sso/login';
$route[$api . '/sso/callback']['get']            = 'api/v1/sso/callback';
$route[$api . '/sso/logout']['post']             = 'api/v1/sso/logout';
$route[$api . '/sso/backchannel-logout']['post'] = 'api/v1/sso/backchannel_logout';

/* ---------- Publik (katalog) ---------- */
$route[$api . '/products']['get']               = 'api/v1/catalog/products';
$route[$api . '/products/(:num)']['get']        = 'api/v1/catalog/product/$1';
$route[$api . '/stores/([a-z0-9-]+)']['get']    = 'api/v1/catalog/store/$1';

/* ---------- Pembeli ---------- */
$route[$api . '/me']['get']                     = 'api/v1/me/index';
$route[$api . '/cart']['get']                   = 'api/v1/cart/index';
$route[$api . '/cart/items']['put']             = 'api/v1/cart/upsert';
$route[$api . '/cart/items/(:num)']['delete']   = 'api/v1/cart/remove/$1';
$route[$api . '/checkout']['post']              = 'api/v1/checkout/create';
$route[$api . '/orders']['get']                 = 'api/v1/orders/index';
$route[$api . '/orders/(:num)']['get']          = 'api/v1/orders/show/$1';
$route[$api . '/orders/(:num)/pay']['post']     = 'api/v1/orders/pay/$1';
$route[$api . '/orders/(:num)/cancel']['post']  = 'api/v1/orders/cancel/$1';
$route[$api . '/orders/(:num)/complete']['post'] = 'api/v1/orders/complete/$1';

/* ---------- Penjual ---------- */
$route[$api . '/seller/store']['get']                    = 'api/v1/seller/store';
$route[$api . '/seller/store']['post']                   = 'api/v1/seller/open_store';
$route[$api . '/seller/store']['put']                    = 'api/v1/seller/update_store';
$route[$api . '/seller/products']['get']                 = 'api/v1/seller/products';
$route[$api . '/seller/products']['post']                = 'api/v1/seller/create_product';
$route[$api . '/seller/products/(:num)']['put']          = 'api/v1/seller/update_product/$1';
$route[$api . '/seller/orders']['get']                   = 'api/v1/seller/orders';
$route[$api . '/seller/orders/(:num)/ship']['post']      = 'api/v1/seller/ship/$1';
$route[$api . '/seller/orders/(:num)/cancel']['post']    = 'api/v1/seller/cancel/$1';

/* ---------- Webhook Koperasi Pay (server-ke-server, HMAC) ---------- */
$route[$api . '/webhooks/koperasi']['post'] = 'api/v1/webhooks/koperasi';

/* ---------- Admin marketplace ---------- */
$route[$api . '/admin/stores']['get']               = 'api/v1/admin/stores';
$route[$api . '/admin/stores/(:num)/status']['put'] = 'api/v1/admin/store_status/$1';
$route[$api . '/admin/orders']['get']               = 'api/v1/admin/orders';
