<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$active_group  = 'default';
$query_builder = TRUE;

$db['default'] = array(
    'dsn'      => '',
    'hostname' => env('DB_HOST', '127.0.0.1'),
    'port'     => (int) env('DB_PORT', 3306),
    'username' => env('DB_USER', 'root'),
    'password' => (string) env('DB_PASSWORD', ''),
    'database' => env('DB_NAME', 'koperasi_digital'),
    'dbdriver' => env('DB_DRIVER', 'mysqli'),
    'dbprefix' => '',

    // WAJIB FALSE (§8.2): dengan koneksi persisten, transaction yang gagal
    // rollback pada satu request bisa "menempel" ke request berikutnya dan
    // mengunci baris rekening.
    'pconnect' => FALSE,

    // WAJIB FALSE (§8.2): error query tidak boleh memicu show_error() di
    // tengah transaction. Kita periksa $this->db->error() sendiri — lihat
    // MY_Model::q() dan MY_Model::is_unique_violation().
    'db_debug' => FALSE,

    'cache_on' => FALSE,
    'cachedir' => '',
    'char_set' => 'utf8mb4',
    'dbcollat' => 'utf8mb4_unicode_ci',
    'swap_pre' => '',
    'encrypt'  => FALSE,
    'compress' => FALSE,
    'stricton' => FALSE,
    'failover' => array(),
    'save_queries' => (env('APP_ENV') !== 'production'),
);
