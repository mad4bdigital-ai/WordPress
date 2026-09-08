<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Boots the exact certified WordPress MCP Adapter from inside the MAD4B plugin
 * distribution when no separately-installed adapter is already active.
 *
 * Source checkouts intentionally do not commit a second copy of upstream code.
 * The distribution workflow expands the certified mcp-adapter.zip into
 * vendor/mcp-adapter before producing the installable MAD4B ZIP.
 */
final class MAD4B_SCP_Bundled_MCP_Adapter {
	const CONTRACT = 'mad4b.bundled-mcp-adapter.v1';

	private static $attempted = false;
	private static $bundled = false;
	private static $root = '';
	private static $main_file = '';
	private static $error = null;

	public static function boot() {
		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) return true;
		if ( self::$attempted ) return self::$bundled ? true : self::$error;
		self::$attempted = true;

		$vendor_root = trailingslashit( MAD4B_SCP_DIR ) . 'vendor/mcp-adapter';
		if ( ! is_dir( $vendor_root ) ) {
			self::$error = new WP_Error( 'mad4b_bundled_mcp_adapter_missing', 'The integrated MCP Adapter runtime is not present in this package.' );
			return self::$error;
		}

		$candidates = array(
			trailingslashit( $vendor_root ) . 'mcp-adapter.php',
			trailingslashit( $vendor_root ) . 'mcp-adapter/mcp-adapter.php',
		);
		$main = '';
		foreach ( $candidates as $candidate ) {
			if ( is_readable( $candidate ) ) { $main = $candidate; break; }
		}
		if ( '' === $main ) {
			$found = glob( trailingslashit( $vendor_root ) . '*/mcp-adapter.php' );
			if ( is_array( $found ) && ! empty( $found ) && is_readable( $found[0] ) ) $main = $found[0];
		}
		if ( '' === $main ) {
			self::$error = new WP_Error( 'mad4b_bundled_mcp_adapter_entrypoint_missing', 'The integrated MCP Adapter entrypoint is unavailable.' );
			return self::$error;
		}

		require_once $main;
		if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			self::$error = new WP_Error( 'mad4b_bundled_mcp_adapter_boot_failed', 'The integrated MCP Adapter did not expose its certified runtime class.' );
			return self::$error;
		}

		self::$bundled = true;
		self::$main_file = $main;
		self::$root = dirname( $main );
		if ( ! defined( 'MAD4B_SCP_BUNDLED_MCP_ADAPTER' ) ) define( 'MAD4B_SCP_BUNDLED_MCP_ADAPTER', true );
		return true;
	}

	public static function is_bundled() { return (bool) self::$bundled; }
	public static function root() { return self::$bundled ? self::$root : ''; }
	public static function main_file() { return self::$bundled ? self::$main_file : ''; }
	public static function error() { return self::$error; }

	public static function version() {
		if ( ! self::$bundled ) return '';
		if ( defined( 'WP_MCP_VERSION' ) ) return (string) WP_MCP_VERSION;
		$file = self::main_file();
		if ( '' === $file || ! is_readable( $file ) ) return '';
		if ( ! function_exists( 'get_file_data' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$data = get_file_data( $file, array( 'Version' => 'Version' ), 'plugin' );
		return isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'attempted' => (bool) self::$attempted,
			'bundled' => (bool) self::$bundled,
			'version' => self::version(),
			'error' => is_wp_error( self::$error ) ? self::$error->get_error_code() : '',
		);
	}
}
