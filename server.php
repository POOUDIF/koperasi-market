<?php
/**
 * Router untuk PHP built-in server (pengembangan / pengujian):
 *   php -S 127.0.0.1:8080 server.php
 *
 * Di produksi pakai Apache/nginx dengan .htaccess yang sudah disediakan.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serahkan file statis apa adanya.
if ($path !== '/' && file_exists(__DIR__ . $path) && ! is_dir(__DIR__ . $path)) {
    return FALSE;
}

// Jangan pernah menyajikan .env / vendor lewat HTTP.
if (preg_match('#^/(\.env|vendor|application|system|database)#i', $path)) {
    http_response_code(403);
    echo json_encode(array('error' => 'akses ditolak'));
    return TRUE;
}

require_once __DIR__ . '/index.php';
