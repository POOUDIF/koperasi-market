<?php
/**
 * Front controller CodeIgniter 3 bersama untuk seluruh layanan JDC.
 *
 * Satu repo, satu document root, beberapa aplikasi CI3 yang saling terpisah
 * (DB, .env, log, prefix Redis masing-masing) — lihat
 * DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §2. Pemanggil WAJIB menyetel:
 *
 *   $jdc_app = array(
 *       'name'        => 'account',          // nama layanan, masuk ke log
 *       'application' => __DIR__ . '/apps/account',
 *       'env_dir'     => __DIR__ . '/apps/account',   // lokasi file .env
 *   );
 *   require __DIR__ . '/shared/bootstrap.php';
 */

if ( ! isset($jdc_app) || ! is_array($jdc_app)) {
	header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
	exit(3);
}

$root = dirname(__DIR__);

define('SHAREDPATH', __DIR__ . DIRECTORY_SEPARATOR);
define('JDC_SERVICE', $jdc_app['name']);
define('JDC_ENV_DIR', rtrim($jdc_app['env_dir'], '/\\') . DIRECTORY_SEPARATOR);

require_once $root . '/vendor/autoload.php';
require_once SHAREDPATH . 'helpers/env.php';

if (is_file(JDC_ENV_DIR . '.env')) {
	Dotenv\Dotenv::createImmutable(JDC_ENV_DIR)->safeLoad();
}

// Dotenv v5 (createImmutable) mengisi $_ENV/$_SERVER, BUKAN putenv(). Karena
// itu getenv('APP_ENV') selalu FALSE dan ENVIRONMENT lama selalu jatuh ke
// 'development' — display_errors menyala di produksi. env() membaca $_ENV.
define('ENVIRONMENT', isset($_SERVER['CI_ENV']) ? $_SERVER['CI_ENV'] : env('APP_ENV', 'production'));

switch (ENVIRONMENT)
{
	case 'development':
		error_reporting(-1);
		ini_set('display_errors', 1);
	break;

	case 'testing':
	case 'production':
		ini_set('display_errors', 0);
		error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT & ~E_USER_NOTICE & ~E_USER_DEPRECATED);
	break;

	default:
		header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
		echo 'The application environment is not set correctly.';
		exit(1);
}

$system_path        = $root . '/system';
$application_folder = $jdc_app['application'];

if (defined('STDIN')) {
	chdir($root);
}

define('SELF', basename($_SERVER['SCRIPT_FILENAME'] ?? 'index.php'));
define('BASEPATH', realpath($system_path) . DIRECTORY_SEPARATOR);
define('FCPATH', $root . DIRECTORY_SEPARATOR);
define('SYSDIR', basename(BASEPATH));

$_app = realpath($application_folder);
if ($_app === FALSE || ! is_dir($_app)) {
	header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
	echo 'Application folder tidak ditemukan.';
	exit(3);
}
define('APPPATH', $_app . DIRECTORY_SEPARATOR);
define('VIEWPATH', APPPATH . 'views' . DIRECTORY_SEPARATOR);
unset($_app, $root);

require_once BASEPATH . 'core/CodeIgniter.php';
