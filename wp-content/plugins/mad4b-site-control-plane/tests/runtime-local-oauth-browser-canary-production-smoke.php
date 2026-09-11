<?php

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "WordPress not loaded\n" ); exit( 1 ); }
if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Browser_Canary' ) ) {
	fwrite( STDERR, "Local OAuth browser canary class unavailable\n" );
	exit( 1 );
}
if ( ! current_user_can( 'manage_options' ) ) {
	fwrite( STDERR, "Production canary runtime proof requires administrator\n" );
	exit( 1 );
}

$status = MAD4B_SCP_Local_OAuth_Browser_Canary::status();
if ( 'production' !== $status['environment'] ) {
	fwrite( STDERR, 'Expected production environment, got: ' . (string) $status['environment'] . "\n" );
	exit( 1 );
}
if ( empty( $status['staging_only'] ) || ! empty( $status['can_run'] ) ) exit( 1 );

ob_start();
MAD4B_SCP_Local_OAuth_Browser_Canary::render_page();
$html = (string) ob_get_clean();
if ( false === strpos( $html, 'Canary configuration is intentionally unavailable outside Staging.' ) ) exit( 1 );
foreach ( array( 'MAD4B_MCP_LOCAL_OAUTH_CLIENTS', 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS', 'Run Local OAuth Browser Canary', 'mad4b-staging-browser-canary', 'mad4b-staging-canary' ) as $forbidden ) {
	if ( false !== strpos( $html, $forbidden ) ) {
		fwrite( STDERR, "Production canary UI exposed forbidden configuration marker: {$forbidden}\n" );
		exit( 1 );
	}
}

echo "mad4b.site-control-plane.local-oauth-browser-canary.production.runtime.v1: PASS\n";
