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
