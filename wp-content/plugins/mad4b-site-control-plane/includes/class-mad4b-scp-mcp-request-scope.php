<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact-Staging request scope for the official MCP Adapter runtime.
 *
 * The official Adapter normally initializes on every WordPress REST bootstrap.
 * MAD4B only needs that runtime for its own six MCP transports and for explicit
 * Control Plane diagnostics. On the governed Staging origin, unrelated REST
 * requests (WPML, Core/Site Health, WooCommerce, Elementor, etc.) must not enter
 * the MCP Adapter/peer registration lifecycle at all.
 *
 * This class is deny-only. It never initializes the Adapter, never registers a
 * route, never changes provider settings, and never affects Production.
 */
final class MAD4B_SCP_MCP_Request_Scope {
	const CONTRACT = 'mad4b.mcp-request-scope.v1';
	const STAGING_HOST = 'staging.egypttourgates.com';

	private static $booted = false;
	private static $eligible = false;
	private static $current_request_requires_mcp = false;
	private static $adapter_init_removed = false;
	private static $bridge_watchdog_removed = false;
	private static $rescue_tail_removed = false;
	private static $rescue_pre_dispatch_removed = false;
	private static $deferred_recovery_removed = false;

	public static function bootstrap() {
		if ( self::$booted ) return;
		self::$booted = true;
		self::$eligible = self::governed_staging();
		if ( ! self::$eligible ) return;

		self::$current_request_requires_mcp = self::current_request_requires_mcp_runtime();
		if ( self::$current_request_requires_mcp ) return;

		// Enforce immediately during normal plugin loading, again after every
		// plugin has loaded, and once more at the very start of REST bootstrap.
		// This closes both normal ordering and a later provider re-arming the
		// official singleton before rest_api_init reaches priority 15.
		self::enforce();
		add_action( 'plugins_loaded', array( __CLASS__, 'enforce' ), PHP_INT_MAX );
		add_action( 'rest_api_init', array( __CLASS__, 'enforce' ), PHP_INT_MIN );
	}

	public static function enforce() {
		if ( ! self::$eligible || self::$current_request_requires_mcp ) return;

		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter', false ) ) {
			try {
				$adapter = \WP\MCP\Core\McpAdapter::instance();
				$priority = has_action( 'rest_api_init', array( $adapter, 'init' ) );
				if ( false !== $priority && remove_action( 'rest_api_init', array( $adapter, 'init' ), (int) $priority ) ) {
					self::$adapter_init_removed = true;
				}
			} catch ( Throwable $e ) {
				// Fail closed: do not initialize or reconstruct an Adapter lifecycle.
			}
		}

		// These are MAD4B-only recovery callbacks. They are removed only from an
		// unrelated current request; no provider or WordPress callback is touched.
		if ( class_exists( 'MAD4B_SCP_MCP_Registration_Bridge', false ) ) {
			if ( remove_action( 'rest_api_init', array( 'MAD4B_SCP_MCP_Registration_Bridge', 'verify_adapter_init_after_rest' ), PHP_INT_MAX ) ) {
				self::$bridge_watchdog_removed = true;
			}
			if ( remove_action( 'init', array( 'MAD4B_SCP_MCP_Registration_Bridge', 'recover_missed_rest_lifecycle' ), 9999 ) ) {
				self::$deferred_recovery_removed = true;
			}
		}
		if ( class_exists( 'MAD4B_SCP_MCP_Registration_Rescue', false ) ) {
			if ( remove_action( 'rest_api_init', array( 'MAD4B_SCP_MCP_Registration_Rescue', 'after_rest_init' ), PHP_INT_MAX - 1 ) ) {
				self::$rescue_tail_removed = true;
			}
			if ( remove_filter( 'rest_pre_dispatch', array( 'MAD4B_SCP_MCP_Registration_Rescue', 'before_rest_dispatch' ), -PHP_INT_MAX ) ) {
				self::$rescue_pre_dispatch_removed = true;
			}
		}
	}

	public static function current_request_requires_mcp_runtime() {
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return true;

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		if ( '' !== $page && 0 === strpos( $page, 'mad4b-control-plane' ) ) return true;

		$route = '';
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
			$route = wp_unslash( (string) $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed only.
		if ( '' === $route && '' !== $uri ) {
			$query = wp_parse_url( $uri, PHP_URL_QUERY );
			if ( is_string( $query ) && '' !== $query ) {
				$parsed = array();
				parse_str( $query, $parsed );
				if ( isset( $parsed['rest_route'] ) && is_string( $parsed['rest_route'] ) ) $route = $parsed['rest_route'];
			}
		}

		if ( self::is_mad4b_mcp_route( $route ) ) return true;
		if ( '' === $uri ) return false;

		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) return false;
		$path = '/' . ltrim( rawurldecode( $path ), '/' );
		$prefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
		$needle = '/' . $prefix . '/';
		$offset = strpos( $path, $needle );
		if ( false !== $offset ) $path = '/' . ltrim( substr( $path, $offset + strlen( $needle ) ), '/' );
		return self::is_mad4b_mcp_route( $path );
	}

	private static function is_mad4b_mcp_route( $route ) {
		$route = '/' . ltrim( rtrim( (string) $route, '/' ), '/' );
		return in_array( $route, array(
			'/mcp/mad4b-read',
			'/mcp/mad4b-chatgpt',
			'/mcp/mad4b-content',
			'/mcp/mad4b-write',
			'/mcp/mad4b-admin',
			'/mcp/mad4b-breakglass',
		), true );
	}

	private static function governed_staging() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$host = '';
		if ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) ) {
			$value = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$host = is_string( $value ) ? strtolower( rtrim( trim( $value ), '.' ) ) : '';
		}
		return 'staging' === $environment && self::STAGING_HOST === $host;
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'eligible' => self::$eligible,
			'current_request_requires_mcp_runtime' => self::$current_request_requires_mcp,
			'adapter_init_removed_for_unrelated_request' => self::$adapter_init_removed,
			'bridge_watchdog_removed_for_unrelated_request' => self::$bridge_watchdog_removed,
			'rescue_tail_removed_for_unrelated_request' => self::$rescue_tail_removed,
			'rescue_pre_dispatch_removed_for_unrelated_request' => self::$rescue_pre_dispatch_removed,
			'deferred_recovery_removed_for_unrelated_request' => self::$deferred_recovery_removed,
			'production_changed' => false,
			'provider_settings_changed' => false,
			'wordpress_rest_routes_changed' => false,
		);
	}
}
