<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { throw new RuntimeException( 'WP-CLI only' ); }
define( 'MAD4B_CATALOG_DECOMMISSION_LIBRARY', true );
require __DIR__ . '/../tools/catalog-decommission.php';

$created = time() - MAD4B_SCP_Catalog_Object_Store::READER_GRACE_SECONDS - 5;
$plan = MAD4B_Catalog_Decommission::plan( $created );
WP_CLI::line( wp_json_encode( array(
	'plan' => $plan,
	'plan_sha256' => MAD4B_Catalog_Decommission::digest( $plan ),
) ) );
