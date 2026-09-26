<?php

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "WordPress not loaded\n" ); exit( 1 ); }
if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Init_Lock' ) || ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ) exit( 1 );

$status = MAD4B_SCP_Local_OAuth_Init_Lock::status();
if ( 'mad4b.local-oauth-init-lock.v1' !== $status['contract'] || empty( $status['enabled'] ) || empty( $status['first_boot_serialized'] ) ) exit( 1 );
if ( ! empty( $status['lock_active'] ) || ! empty( $status['lock_contains_secret_material'] ) ) exit( 1 );
if ( 0 !== has_action( 'init', array( 'MAD4B_SCP_Local_OAuth_Init_Lock', 'acquire' ) ) ) exit( 1 );
if ( 2 !== has_action( 'init', array( 'MAD4B_SCP_Local_OAuth_Init_Lock', 'release' ) ) ) exit( 1 );
if ( 1 !== has_action( 'init', array( 'MAD4B_SCP_Local_OAuth_Server', 'ensure_runtime' ) ) ) exit( 1 );

$key_path_method = new ReflectionMethod( 'MAD4B_SCP_Local_OAuth_Server', 'private_key_path' );
$key_path_method->setAccessible( true );
$key_path = $key_path_method->invoke( null );
if ( is_wp_error( $key_path ) || ! is_file( $key_path ) || empty( $status['key_present'] ) ) exit( 1 );
$normalized_key = wp_normalize_path( $key_path );
$web_root = trailingslashit( wp_normalize_path( ABSPATH ) );
if ( 0 === strpos( trailingslashit( dirname( $normalized_key ) ), $web_root ) ) exit( 1 );

$lock_path = $key_path . '.init.lock';
if ( is_file( $lock_path ) ) {
	$mode = fileperms( $lock_path ) & 0777;
	if ( 0600 !== $mode ) { fwrite( STDERR, sprintf( "Unexpected init-lock permissions: %o\n", $mode ) ); exit( 1 ); }
	if ( 0 !== filesize( $lock_path ) ) exit( 1 );
}

echo "mad4b.site-control-plane.local-oauth-init-lock.runtime.v1: PASS\n";
