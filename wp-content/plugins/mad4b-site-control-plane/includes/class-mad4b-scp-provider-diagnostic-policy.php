<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Zero-touch policy for provider diagnostics on request-serving paths.
 *
 * Passive status/read surfaces may inspect already-materialized runtime state,
 * but must never create the REST server, internally dispatch provider routes,
 * perform loopback HTTP, or retry provider probes. Deep registry materialization
 * is owned only by the explicit Control Plane Connection > MCP Endpoints surface.
 * Provider behavioral acceptance is delegated to the governed external executor.
 */
final class MAD4B_SCP_Provider_Diagnostic_Policy {
	const CONTRACT = 'mad4b.provider-diagnostic-policy.v1';

	public static function current_rest_server() {
		global $wp_rest_server;
		return isset( $wp_rest_server )
			&& is_object( $wp_rest_server )
			&& method_exists( $wp_rest_server, 'get_routes' )
			? $wp_rest_server
			: null;
	}

	public static function rest_route_snapshot( $route ) {
		$route = '/' . ltrim( rtrim( (string) $route, '/' ), '/' );
		$server = self::current_rest_server();
		$base = array(
			'contract' => self::CONTRACT,
			'mode' => 'passive_snapshot',
			'route' => $route,
			'rest_server_materialized' => is_object( $server ),
			'rest_server_materialized_by_diagnostic' => false,
			'route_observed' => false,
			'route_registered' => null,
			'internal_rest_dispatch_performed' => false,
			'loopback_http_performed' => false,
			'provider_self_calls_started' => 0,
			'automatic_retry_allowed' => false,
		);
		if ( ! is_object( $server ) ) return $base;

		try {
			$routes = $server->get_routes();
			if ( ! is_array( $routes ) ) return $base;
			$base['route_observed'] = true;
			$base['route_registered'] = array_key_exists( $route, $routes );
		} catch ( Throwable $e ) {
			$base['route_observed'] = false;
			$base['route_registered'] = null;
		}
		return $base;
	}

	public static function explicit_rest_materialization_allowed() {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) return false;
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) return false;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		return 'mad4b-control-plane-connection' === $page && 'endpoints' === $tab;
	}

	/**
	 * Resolve the outer HTTP REST route without materializing the REST server.
	 * Nested rest_do_request() calls do not change this outer request identity.
	 */
	public static function current_http_rest_route() {
		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		if ( '' !== trim( $route ) ) return '/' . ltrim( rtrim( rawurldecode( $route ), '/' ), '/' );

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- routing observation only.
		if ( '' === $uri ) return '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) return '';
		$path = '/' . ltrim( rawurldecode( $path ), '/' );
		$prefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
		$needle = '/' . $prefix . '/';
		$offset = strpos( $path, $needle );
		if ( false === $offset ) return '';
		return '/' . ltrim( substr( $path, $offset + strlen( $needle ) ), '/' );
	}

	/**
	 * Accept provider evidence only when the outer HTTP request itself targets
	 * the exact provider route. Internal/nested REST dispatch never qualifies.
	 */
	public static function current_request_is_external_provider_rest( $route ) {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) return false;
		if ( self::current_request_is_mad4b_protocol() ) return false;
		$expected = '/' . ltrim( rtrim( (string) $route, '/' ), '/' );
		$current = self::current_http_rest_route();
		return '' !== $current && hash_equals( $expected, $current );
	}

	public static function current_request_is_foreign_rest() {
		if ( self::current_request_is_mad4b_protocol() ) return false;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return true;

		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		if ( '' !== trim( $route ) ) return true;

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- routing observation only.
		if ( '' === $uri ) return false;
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) return false;
		$prefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
		return false !== strpos( '/' . ltrim( rawurldecode( $path ), '/' ), '/' . $prefix . '/' );
	}

	public static function current_request_is_wordpress_cron() {
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) return true;
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) return true;
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- routing observation only.
		if ( '' === $uri ) return false;
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) && '/wp-cron.php' === substr( '/' . ltrim( $path, '/' ), -12 );
	}

	public static function current_request_is_foreign_wp_admin() {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) return false;
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) return false;
		if ( self::current_request_is_wordpress_cron() ) return false;

		$pagenow = isset( $GLOBALS['pagenow'] ) ? sanitize_key( (string) $GLOBALS['pagenow'] ) : '';
		if ( in_array( $pagenow, array( 'update.php', 'update-core.php', 'plugin-install.php', 'plugins.php' ), true ) ) return false;

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		if ( '' === $page ) return false;
		if ( 0 === strpos( $page, 'mad4b-control-plane' ) || 'mad4b-approval-decisions' === $page ) return false;
		return true;
	}

	public static function current_request_is_zero_touch_surface() {
		return self::current_request_is_foreign_rest()
			|| self::current_request_is_wordpress_cron()
			|| self::current_request_is_foreign_wp_admin();
	}

	public static function zero_touch_reason() {
		if ( self::current_request_is_foreign_rest() ) return 'foreign_rest';
		if ( self::current_request_is_wordpress_cron() ) return 'wordpress_cron';
		if ( self::current_request_is_foreign_wp_admin() ) return 'foreign_wp_admin';
		return '';
	}

	private static function current_request_is_mad4b_protocol() {
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
			&& method_exists( 'MAD4B_SCP_MCP_Request_Scope', 'current_request_is_protocol_hotpath' )
			&& MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) return true;

		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		$route = '/' . ltrim( rtrim( $route, '/' ), '/' );
		if ( 0 === strpos( $route, '/mad4b/' ) || 0 === strpos( $route, '/mcp/mad4b-' ) ) return true;

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- routing observation only.
		if ( '' === $uri ) return false;
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) return false;
		$path = '/' . ltrim( rawurldecode( $path ), '/' );
		return false !== strpos( $path, '/wp-json/mad4b/' )
			|| false !== strpos( $path, '/wp-json/mcp/mad4b-' )
			|| 0 === strpos( $path, '/oauth/mcp/' )
			|| 0 === strpos( $path, '/.well-known/oauth-' );
	}

	public static function active_provider_dispatch_allowed() {
		// Provider behavior is proven by the governed external/browser executor.
		// Local status reads never dispatch a provider endpoint internally.
		return false;
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'mode' => 'passive_by_default',
			'request_serving_provider_self_calls_allowed' => false,
			'internal_provider_rest_dispatch_allowed' => false,
			'loopback_provider_http_allowed' => false,
			'automatic_probe_retry_allowed' => false,
			'explicit_rest_materialization_surface' => 'mad4b-control-plane-connection:endpoints',
			'provider_behavior_executor' => 'governed_external_executor',
			'current_request_zero_touch' => self::current_request_is_zero_touch_surface(),
			'current_request_zero_touch_reason' => self::zero_touch_reason(),
			'foreign_rest_zero_touch' => true,
			'wordpress_cron_zero_touch' => true,
			'foreign_wp_admin_zero_touch' => true,
			'external_provider_receipt_requires_exact_outer_http_route' => true,
		);
	}
}
