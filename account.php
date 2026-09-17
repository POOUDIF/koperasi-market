<?php
/**
 * Front controller JDC ACCOUNT — Identity Provider (OpenID Connect).
 *
 * Melayani /account/* (lihat .htaccess di root) dan perintah CLI:
 *   php account.php cli/keys generate
 *   php account.php cli/clients sync
 *   php account.php cli/backchannel run
 *   php account.php cli/migrate_users run [--dry-run]
 */
$jdc_app = array(
	'name'        => 'account',
	'application' => __DIR__ . '/apps/account',
	'env_dir'     => __DIR__ . '/apps/account',
);

require __DIR__ . '/shared/bootstrap.php';
