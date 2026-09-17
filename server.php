<?php
/**
 * Router PHP built-in server untuk pengembangan cepat — meniru .htaccess root
 * (routing berbasis path, DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §2.1):
 *
 *   php -S 127.0.0.1:8300 server.php
 *
 * KETERBATASAN: php -S melayani satu request dalam satu waktu. Back-channel
 * logout (IdP → koperasi/market) dan webhook Koperasi Pay (koperasi → market)
 * memanggil server yang SAMA dari dalam request, sehingga akan timeout lalu
 * diulang cron. Untuk uji alur lengkap & tests/*.sh pakai Apache (Laragon).
 */
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = __DIR__;

// Tolak kode, konfigurasi, kunci, dan file tersembunyi (kecuali .well-known OIDC).
if (preg_match('#^/(application|apps|shared|system|vendor|database|tests|signer-service|frontend|market-frontend|compro|packages|node_modules|DOCS|storage|public_html)(/|$)#', $path)
    || preg_match('#(^|/)\.(?!well-known/)#', $path)
    || preg_match('#^/[^/]+\.(php|md|sh|json|lock)$#', $path)) {
    http_response_code(403);
    return TRUE;
}

$serve = function ($file) {
    $types = array('html' => 'text/html; charset=utf-8', 'js' => 'text/javascript', 'css' => 'text/css',
                   'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp',
                   'xml' => 'application/xml', 'txt' => 'text/plain; charset=utf-8', 'json' => 'application/json',
                   'woff2' => 'font/woff2', 'ico' => 'image/x-icon');
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($file);
    return TRUE;
};

// URL lama
if (preg_match('#^/api/v1/(.*)$#', $path, $m)) { header('Location: /koperasi/api/v1/' . $m[1], TRUE, 308); return TRUE; }
if (preg_match('#^/(login|register|verify-otp)/?$#', $path)) { header('Location: /koperasi/dashboard', TRUE, 301); return TRUE; }
if (preg_match('#^/(koperasi|market)$#', $path, $m)) { header('Location: /' . $m[1] . '/', TRUE, 301); return TRUE; }

// API & IdP
if (preg_match('#^/koperasi/api/v1(/|$)#', $path)) { require $root . '/index.php'; return TRUE; }
if (preg_match('#^/market/api/v1(/|$)#', $path))   { require $root . '/market.php'; return TRUE; }
if (preg_match('#^/account/assets/#', $path) && is_file($root . '/public_html' . $path)) { return $serve($root . '/public_html' . $path); }
if (preg_match('#^/account(/|$)#', $path))         { require $root . '/account.php'; return TRUE; }

// SPA
if (preg_match('#^/(koperasi|market)/#', $path, $m)) {
    $file = $root . '/public_html' . $path;
    return $serve(is_file($file) ? $file : $root . '/public_html/' . $m[1] . '/index.html');
}

// Company profile
$compro = $root . '/public_html/compro';
if (is_file($compro . $path))                                  { return $serve($compro . $path); }
if (substr($path, -1) === '/' && is_file($compro . $path . 'index.html')) { return $serve($compro . $path . 'index.html'); }
if (is_file($compro . $path . '/index.html'))                   { header('Location: ' . $path . '/', TRUE, 301); return TRUE; }

http_response_code(404);
return is_file($compro . '/404.html') ? $serve($compro . '/404.html') : TRUE;
