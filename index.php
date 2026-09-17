<?php
/**
 * Front controller layanan KOPERASI DIGITAL.
 *
 * Melayani /koperasi/api/v1/* (lihat .htaccess di root) dan perintah CLI:
 *   php index.php cli/gold_worker start
 *   php index.php cli/ledger_audit run
 *   php index.php cli/webhooks run
 *
 * Seluruh logika bootstrap CI3 ada di shared/bootstrap.php, dipakai bersama
 * account.php (JDC Account / IdP) dan market.php (Marketplace).
 */
$jdc_app = array(
	'name'        => 'koperasi',
	'application' => __DIR__ . '/application',
	'env_dir'     => __DIR__,
);

require __DIR__ . '/shared/bootstrap.php';
