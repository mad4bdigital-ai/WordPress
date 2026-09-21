<?php

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "WordPress not loaded\n" ); exit( 1 ); }
if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Browser_Canary' ) || ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ) {
	fwrite( STDERR, "Local OAuth browser canary classes unavailable\n" );
	exit( 1 );
}
if ( ! current_user_can( 'manage_options' ) ) {
	fwrite( STDERR, "Browser canary runtime requires administrator\n" );
	exit( 1 );
}

$redirect = MAD4B_SCP_Local_OAuth_Browser_Canary::redirect_uri();
if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_CLIENTS' ) ) {
	define(
		'MAD4B_MCP_LOCAL_OAUTH_CLIENTS',
		array(
			MAD4B_SCP_Local_OAuth_Browser_Canary::CLIENT_ID => array(
				'client_name' => 'MAD4B Staging Browser Canary',
				'redirect_uris' => array( $redirect ),
				'application_type' => 'web',
			),
		)
	);
}

MAD4B_SCP_Local_OAuth_Server::ensure_runtime();
$status = MAD4B_SCP_Local_OAuth_Browser_Canary::status();
if ( 'mad4b.local-oauth-browser-canary.v1' !== $status['contract'] ) exit( 1 );
if ( 'staging' !== $status['environment'] || empty( $status['staging_only'] ) ) exit( 1 );
if ( empty( $status['client_registered'] ) ) exit( 1 );
if ( empty( $status['local_oauth_effective'] ) || empty( $status['resource_bridge_effective'] ) ) {
	fwrite( STDERR, 'Canary prerequisites are not effective: ' . wp_json_encode( $status ) . "\n" );
	exit( 1 );
}
if ( empty( $status['can_run'] ) ) exit( 1 );
if ( ! empty( $status['persists_pkce_material'] ) || ! empty( $status['persists_bearer_tokens'] ) ) exit( 1 );
if ( ! empty( $status['creates_credentials'] ) || ! empty( $status['creates_clients'] ) || ! empty( $status['changes_configuration'] ) ) exit( 1 );
if ( ! empty( $status['external_connection_certified'] ) ) exit( 1 );
if ( $redirect !== $status['redirect_uri'] ) exit( 1 );
if ( false === strpos( $redirect, 'page=mad4b-control-plane-oauth-canary' ) || false === strpos( $redirect, 'mad4b_oauth_canary=callback' ) ) exit( 1 );

ob_start();
MAD4B_SCP_Local_OAuth_Browser_Canary::render_page();
$html = (string) ob_get_clean();
foreach ( array( 'MAD4B Local OAuth Browser Canary', 'Run Local OAuth Browser Canary', 'mad4b-staging-browser-canary', 'External connection certified', 'tools/Invoke-MAD4BLocalOAuthStagingCanary.ps1' ) as $marker ) {
	if ( false === strpos( $html, $marker ) ) {
		fwrite( STDERR, "Missing canary UI marker: {$marker}\n" );
		exit( 1 );
	}
}
if ( false !== strpos( $html, 'access_token' ) || false !== strpos( $html, 'refresh_token' ) ) {
	fwrite( STDERR, "Browser canary UI must not render bearer token fields\n" );
	exit( 1 );
}

echo "mad4b.site-control-plane.local-oauth-browser-canary.runtime.v1: PASS\n";
