<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Serializes only the first Local OAuth signing-key bootstrap.
 *
 * Local OAuth writes the key through a temporary file + rename. On POSIX,
 * rename() can replace an existing destination, so two simultaneous first
 * requests could otherwise generate different keys and let the later rename
 * replace the first key. This lock spans init priorities 0..2 around Local
 * OAuth ensure_runtime() at priority 1. Once the key exists, no lock is taken.
 */
final class MAD4B_SCP_Local_OAuth_Init_Lock {
	const CONTRACT = 'mad4b.local-oauth-init-lock.v1';
	private static $handle = null;
	private static $lock_path = '';

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'acquire' ), 0 );
		add_action( 'init', array( __CLASS__, 'release' ), 2 );
	}

	public static function acquire() {
		if ( ! self::enabled() || is_resource( self::$handle ) ) return;
		$key_path = self::key_path();
		if ( '' === $key_path || is_file( $key_path ) ) return;
		$dir = dirname( $key_path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return;
		@chmod( $dir, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$lock_path = $key_path . '.init.lock';
		$handle = @fopen( $lock_path, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) return;
		@chmod( $lock_path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! flock( $handle, LOCK_EX ) ) { fclose( $handle ); return; }
		self::$handle = $handle;
		self::$lock_path = $lock_path;
	}

	public static function release() {
		if ( ! is_resource( self::$handle ) ) return;
		flock( self::$handle, LOCK_UN );
		fclose( self::$handle );
		self::$handle = null;
	}

	public static function status() {
		$key_path = self::key_path();
		return array(
			'contract' => self::CONTRACT,
			'enabled' => self::enabled(),
			'key_present' => '' !== $key_path && is_file( $key_path ),
			'lock_active' => is_resource( self::$handle ),
			'first_boot_serialized' => true,
			'lock_contains_secret_material' => false,
		);
	}

	private static function enabled() {
		return defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) && true === constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' );
	}

	private static function key_path() {
		$path = defined( 'MAD4B_MCP_LOCAL_OAUTH_PRIVATE_KEY_PATH' )
			? trim( (string) constant( 'MAD4B_MCP_LOCAL_OAUTH_PRIVATE_KEY_PATH' ) )
			: trailingslashit( dirname( rtrim( ABSPATH, '/\\' ) ) ) . '.mad4b/oauth/wordpress-local-rs256-private.pem';
		if ( '' === $path || 1 !== preg_match( '#^(?:[A-Za-z]:[\\\\/]|/)#', $path ) ) return '';
		$normalized = wp_normalize_path( $path );
		$web_root = trailingslashit( wp_normalize_path( ABSPATH ) );
		if ( 0 === strpos( trailingslashit( dirname( $normalized ) ), $web_root ) ) return '';
		return $path;
	}
}
