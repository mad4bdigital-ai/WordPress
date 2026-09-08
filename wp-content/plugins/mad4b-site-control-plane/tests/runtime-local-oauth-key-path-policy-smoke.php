<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function mad4b_key_path_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Key_Path_Policy' ) ) mad4b_key_path_fail( 'Local OAuth key-path policy unavailable.' );
$status = MAD4B_SCP_Local_OAuth_Key_Path_Policy::status();
if ( 'mad4b.local-oauth-key-path-policy.v1' !== $status['contract'] ) mad4b_key_path_fail( 'Unexpected key-path policy contract.', $status );
if ( empty( $status['effective'] ) || empty( $status['path_selected'] ) || empty( $status['outside_wordpress_root'] ) ) mad4b_key_path_fail( 'Current CLI fixture key path is not proven outside WordPress.', $status );
if ( ! empty( $status['key_material_exposed'] ) ) mad4b_key_path_fail( 'Key-path policy must never expose key material.', $status );

// Model a common subdirectory installation: the WordPress root is below the
// actual HTTP document root. dirname(ABSPATH) would be /var/www/html and would
// still be web-addressable; the policy must instead step outside document root.
$default = MAD4B_SCP_Local_OAuth_Key_Path_Policy::safe_default_path_for_roots( '/var/www/html/wp/', '/var/www/html' );
$expected = '/var/www/.mad4b/oauth/wordpress-local-rs256-private.pem';
if ( is_wp_error( $default ) || wp_normalize_path( $expected ) !== wp_normalize_path( $default ) ) mad4b_key_path_fail( 'Subdirectory install did not choose a document-root-external default.', $default );

$unsafe_document = MAD4B_SCP_Local_OAuth_Key_Path_Policy::validate_path_against_roots( '/var/www/html/.mad4b/oauth/key.pem', '/var/www/html/wp/', '/var/www/html' );
if ( ! is_wp_error( $unsafe_document ) || 'mad4b_local_oauth_key_path_document_root_exposed' !== $unsafe_document->get_error_code() ) mad4b_key_path_fail( 'Document-root-contained explicit key path was not rejected.', $unsafe_document );

$unsafe_wordpress = MAD4B_SCP_Local_OAuth_Key_Path_Policy::validate_path_against_roots( '/var/www/html/wp/private/key.pem', '/var/www/html/wp/', '/var/www/html' );
if ( ! is_wp_error( $unsafe_wordpress ) || 'mad4b_local_oauth_key_path_wordpress_exposed' !== $unsafe_wordpress->get_error_code() ) mad4b_key_path_fail( 'WordPress-root-contained explicit key path was not rejected.', $unsafe_wordpress );

$safe = MAD4B_SCP_Local_OAuth_Key_Path_Policy::validate_path_against_roots( '/var/www/.mad4b/oauth/key.pem', '/var/www/html/wp/', '/var/www/html' );
if ( true !== $safe ) mad4b_key_path_fail( 'Document-root-external key path should be accepted.', $safe );

$unknown_web_root = MAD4B_SCP_Local_OAuth_Key_Path_Policy::safe_default_path_for_roots( '/var/www/html/wp/', '' );
if ( ! in_array( PHP_SAPI, array( 'cli', 'phpdbg' ), true ) && ! is_wp_error( $unknown_web_root ) ) mad4b_key_path_fail( 'Web runtime without a document root must require explicit safe key configuration.' );

echo 'mad4b.site-control-plane.local-oauth-key-path-policy.runtime.v1: PASS' . PHP_EOL;
