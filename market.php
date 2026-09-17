<?php
/**
 * Front controller MARKETPLACE JDC.
 *
 * Melayani /market/api/v1/* (lihat .htaccess di root) dan perintah CLI:
 *   php market.php cli/orders maintenance
 *   php market.php cli/admin promote <email>
 */
$jdc_app = array(
	'name'        => 'market',
	'application' => __DIR__ . '/apps/market',
	'env_dir'     => __DIR__ . '/apps/market',
);

require __DIR__ . '/shared/bootstrap.php';
