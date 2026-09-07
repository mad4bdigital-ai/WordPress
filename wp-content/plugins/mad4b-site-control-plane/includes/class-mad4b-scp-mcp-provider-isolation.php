<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Explicit isolation layer for provider-native MCP/AI REST surfaces.
 *
 * This is deliberately OFF by default. When enabled it only removes a bounded,
 * version-independent set of exact MCP/control routes; it never grants MAD4B
 * authority, changes provider settings, creates credentials, or auto-enables
 * mutations. Unknown MCP-looking routes are untouched and therefore remain
 * visible to peer governance as fail-closed blockers.
 */
final class MAD4B_SCP_MCP_Provider_Isolation {
	const CONTRACT = 'mad4b.mcp-provider-isolation.v1';
	const ENABLE_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_ENABLED';
	const PRODUCTION_APPROVAL_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_PRODUCTION_APPROVED';

	private static $booted = false;
	private static $removed_routes = array();

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		// Suppress the generic default WordPress MCP server while explicit MAD4B
		// isolation is effective. Custom MAD4B servers are registered separately.
		add_filter( 'mcp_adapter_create_default_server', array( __CLASS__, 'filter_default_server' ), 1 );

		// Remove only certified provider MCP/control endpoints. A future or unknown
		// MCP route will not match these descriptors and remains visible/blocking.
		add_filter( 'rest_endpoints', array( __CLASS__, 'filter_rest_endpoints' ), PHP_INT_MAX );
	}

	public static function configured() {
		return defined( self::ENABLE_FLAG ) && true === constant( self::ENABLE_FLAG );
	}

	public static function production_approved() {
		return defined( self::PRODUCTION_APPROVAL_FLAG ) && true === constant( self::PRODUCTION_APPROVAL_FLAG );
	}

	public static function effective() {
		if ( ! self::configured() ) return false;
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		if ( 'production' === $environment && ! self::production_approved() ) return false;
		return in_array( $environment, array( 'staging', 'development', 'local', 'production' ), true );
	}

	public static function filter_default_server( $create ) {
		return self::effective() ? false : (bool) $create;
	}

	public static function filter_rest_endpoints( $endpoints ) {
		if ( ! self::effective() || ! is_array( $endpoints ) ) return $endpoints;
		foreach ( array_keys( $endpoints ) as $route ) {
			$descriptor = self::descriptor_for_route( (string) $route );
			if ( ! $descriptor ) continue;
			self::$removed_routes[] = substr( (string) $route, 0, 255 );
			unset( $endpoints[ $route ] );
		}
		self::$removed_routes = array_values( array_unique( self::$removed_routes ) );
		return $endpoints;
	}

	/**
	 * Exact/bounded provider route descriptors. Regexes are anchored and scoped to
	 * known provider namespaces. They are a deny/isolation list, never an allowlist.
	 */
	public static function descriptors() {
		return array(
			array(
				'provider' => 'fluent_forms',
				'pattern' => '#^/fluentform/v1/mcp/(?:status|toggle|install-adapter|config-snippets)/?$#',
				'class' => 'mcp_control_surface',
			),
			array(
				'provider' => 'fluent_forms',
				'pattern' => '#^/fluentform/mcp/?$#',
				'class' => 'mcp_transport',
			),
			array(
				'provider' => 'jetengine',
				'pattern' => '#^/jet-engine/v1/mcp/?$#',
				'class' => 'mcp_transport',
			),
			array(
				'provider' => 'jetengine',
				'pattern' => '#^/jet-engine/v1/mcp-tools/?$#',
				'class' => 'mcp_execution_surface',
			),
			array(
				'provider' => 'jetengine',
				'pattern' => '#^/jet-engine/v1/mcp-tools/run(?:/.*)?$#',
				'class' => 'mcp_execution_surface',
			),
			array(
				'provider' => 'uae_hfe',
				'pattern' => '#^/hfe/v1/mcp-(?:abilities|settings)/?$#',
				'class' => 'mcp_control_surface',
			),
			array(
				'provider' => 'uae_hfe',
				'pattern' => '#^/uae/mcp/?$#',
				'class' => 'mcp_transport',
			),
			array(
				'provider' => 'elementskit',
				'pattern' => '#^/elementskit/v1/mcp-proxy/?$#',
				'class' => 'mcp_execution_surface',
			),
		);
	}

	public static function descriptor_for_route( $route ) {
		$route = '/' . ltrim( (string) $route, '/' );
		foreach ( self::descriptors() as $descriptor ) {
			if ( preg_match( $descriptor['pattern'], $route ) ) return $descriptor;
		}
		return null;
	}

	public static function status() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$descriptors = array();
		foreach ( self::descriptors() as $descriptor ) {
			$descriptors[] = array(
				'provider' => sanitize_key( $descriptor['provider'] ),
				'class' => sanitize_key( $descriptor['class'] ),
				'pattern' => (string) $descriptor['pattern'],
			);
		}
		return array(
			'contract' => self::CONTRACT,
			'configured' => self::configured(),
			'effective' => self::effective(),
			'environment' => $environment,
			'production_approved' => self::production_approved(),
			'default_server_suppressed' => self::effective(),
			'removed_route_count' => count( self::$removed_routes ),
			'removed_routes' => array_slice( self::$removed_routes, 0, 100 ),
			'descriptors' => $descriptors,
			'unknown_routes_fail_closed' => true,
			'changes_provider_settings' => false,
			'creates_authority' => false,
		);
	}
}
