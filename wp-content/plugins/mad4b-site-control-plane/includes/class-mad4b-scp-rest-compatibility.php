<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only REST compatibility diagnostics plus a deny-only scope guard for
 * MAD4B's MCP registration recovery callbacks.
 *
 * The Control Plane never needs to disable the REST API or globally rewrite
 * unrelated REST authentication. MCP lifecycle recovery is permitted only when
 * the current HTTP request targets one of MAD4B's own MCP routes. All ordinary
 * WordPress REST requests (core, Site Health, WPML, WooCommerce, Elementor, etc.)
 * are explicitly isolated from that recovery machinery.
 */
final class MAD4B_SCP_REST_Compatibility {
	const CONTRACT = 'mad4b.rest-compatibility.v2';
	const WPML_ROUTE = '/wpml/v1/rest/status';
	const MAX_HOOK_CALLBACKS = 200;

	private static $mcp_recovery_scope_evaluated = false;
	private static $mcp_recovery_request = false;
	private static $mcp_recovery_callbacks_removed = array();
	private static $wpml_probe_cache = null;

	public static function boot() {
		// This runs on plugins_loaded before normal REST bootstrap. If a host/MU
		// component primed REST even earlier, the bridge may already have scheduled
		// an init-time recovery; disarm that too for every non-MAD4B HTTP request.
		self::scope_mcp_recovery_to_current_http_request();
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 36 );
	}

	private static function scope_mcp_recovery_to_current_http_request() {
		if ( self::$mcp_recovery_scope_evaluated ) return;
		self::$mcp_recovery_scope_evaluated = true;
		self::$mcp_recovery_request = self::current_http_request_targets_mad4b_mcp();
		if ( self::$mcp_recovery_request ) return;

		$callbacks = array(
			array( 'type' => 'action', 'hook' => 'rest_api_init', 'callback' => array( 'MAD4B_SCP_MCP_Registration_Bridge', 'verify_adapter_init_after_rest' ), 'priority' => PHP_INT_MAX ),
			array( 'type' => 'action', 'hook' => 'rest_api_init', 'callback' => array( 'MAD4B_SCP_MCP_Registration_Rescue', 'after_rest_init' ), 'priority' => PHP_INT_MAX - 1 ),
			array( 'type' => 'filter', 'hook' => 'rest_pre_dispatch', 'callback' => array( 'MAD4B_SCP_MCP_Registration_Rescue', 'before_rest_dispatch' ), 'priority' => -PHP_INT_MAX ),
			array( 'type' => 'action', 'hook' => 'init', 'callback' => array( 'MAD4B_SCP_MCP_Registration_Bridge', 'recover_missed_rest_lifecycle' ), 'priority' => 9999 ),
		);

		foreach ( $callbacks as $entry ) {
			$bound = 'filter' === $entry['type']
				? false !== has_filter( $entry['hook'], $entry['callback'] )
				: false !== has_action( $entry['hook'], $entry['callback'] );
			if ( ! $bound ) continue;

			$removed = 'filter' === $entry['type']
				? remove_filter( $entry['hook'], $entry['callback'], $entry['priority'] )
				: remove_action( $entry['hook'], $entry['callback'], $entry['priority'] );
			if ( $removed ) self::$mcp_recovery_callbacks_removed[] = $entry['hook'] . ':' . (string) $entry['priority'];
		}
	}

	private static function current_http_request_targets_mad4b_mcp() {
		$route = '';
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
			$route = wp_unslash( (string) $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed below, never executed.
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
		$rest_prefix = '/' . $prefix . '/';
		if ( 0 === strpos( $path, $rest_prefix ) ) $path = '/' . ltrim( substr( $path, strlen( $rest_prefix ) ), '/' );
		return self::is_mad4b_mcp_route( $path );
	}

	private static function is_mad4b_mcp_route( $route ) {
		$route = '/' . ltrim( rtrim( (string) $route, '/' ), '/' );
		if ( '/' === $route ) return false;

		$allowed = array(
			'/mcp/mad4b-read',
			'/mcp/mad4b-chatgpt',
			'/mcp/mad4b-enrollment',
			'/mcp/mad4b-content',
			'/mcp/mad4b-write',
			'/mcp/mad4b-admin',
			'/mcp/mad4b-breakglass',
		);
		return in_array( $route, $allowed, true );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( 'mad4b/rest-compatibility-status' ) ) return;
		wp_register_ability( 'mad4b/rest-compatibility-status', array(
			'label' => 'Get REST Compatibility Status',
			'description' => 'Verify that the Control Plane does not disable or globally intercept unrelated WordPress REST routes, including WPML REST health checks.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'status' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	public static function status() {
		$rest_enabled_hooks = self::hook_inventory( 'rest_enabled' );
		$rest_auth_hooks = self::hook_inventory( 'rest_authentication_errors' );
		$rest_enabled = (bool) apply_filters( 'rest_enabled', true );
		$wpml = self::wpml_probe();
		$external_wpml = class_exists( 'MAD4B_SCP_External_WPML_Acceptance_Finalizer' ) && method_exists( 'MAD4B_SCP_External_WPML_Acceptance_Finalizer', 'external_wpml_receipt_status' )
			? MAD4B_SCP_External_WPML_Acceptance_Finalizer::external_wpml_receipt_status()
			: array();
		$external_wpml_required = ! empty( $wpml['wpml_active'] );
		$external_wpml_verified = ! $external_wpml_required || ( ! empty( $external_wpml['observed'] ) && ! empty( $external_wpml['verified'] ) && empty( $external_wpml['stale'] ) );
		$control_plane_on_rest_enabled = ! empty( $rest_enabled_hooks['control_plane_detected'] );
		$control_plane_on_rest_auth = ! empty( $rest_auth_hooks['control_plane_detected'] );
		$expected_rest_scope = array(
			'/mcp/mad4b-read', '/mcp/mad4b-chatgpt', '/mcp/mad4b-enrollment', '/mcp/mad4b-content',
			'/mcp/mad4b-write', '/mcp/mad4b-admin', '/mcp/mad4b-breakglass',
		);
		$mcp_recovery_scoped = true;
		foreach ( $expected_rest_scope as $route ) {
			if ( ! self::is_mad4b_mcp_route( $route ) ) { $mcp_recovery_scoped = false; break; }
		}
		if ( $mcp_recovery_scoped ) {
			foreach ( array( '/wpml/v1/rest/status', '/wp/v2/types/post', '/wc/v3/products' ) as $route ) {
				if ( self::is_mad4b_mcp_route( $route ) ) { $mcp_recovery_scoped = false; break; }
			}
		}
		$local_checks = array(
			'rest_enabled' => $rest_enabled,
			'control_plane_not_on_rest_enabled_hook' => ! $control_plane_on_rest_enabled,
			'control_plane_not_on_rest_authentication_hook' => ! $control_plane_on_rest_auth,
			'control_plane_does_not_block_wpml_rest' => empty( $wpml['control_plane_block_detected'] ),
			'mcp_recovery_scope_evaluated' => self::$mcp_recovery_scope_evaluated,
			'mcp_recovery_scoped_to_mad4b_routes' => $mcp_recovery_scoped,
			'external_wpml_acceptance_not_claimed_locally' => true,
		);
		$local_blockers = array();
		foreach ( $local_checks as $key => $ok ) if ( ! $ok ) $local_blockers[] = $key;
		$local_ready = empty( $local_blockers );

		return array(
			'contract' => self::CONTRACT,
			'ready' => $local_ready,
			'state' => $local_ready ? 'ready' : 'blocked',
			'blockers' => $local_blockers,
			'local_rest_isolation_ready' => $local_ready,
			'local_rest_isolation' => array(
				'ready' => $local_ready,
				'state' => $local_ready ? 'ready' : 'blocked',
				'checks' => $local_checks,
				'blockers' => $local_blockers,
			),
			'rest_enabled' => $rest_enabled,
			'control_plane_disables_rest' => ! $rest_enabled && $control_plane_on_rest_enabled,
			'control_plane_filters_rest_enabled' => $control_plane_on_rest_enabled,
			'control_plane_filters_rest_authentication_errors' => $control_plane_on_rest_auth,
			'rest_enabled_hook' => $rest_enabled_hooks,
			'rest_authentication_errors_hook' => $rest_auth_hooks,
			'control_plane_rest_pre_dispatch_scope' => $expected_rest_scope,
			'mcp_recovery_scope_evaluated' => self::$mcp_recovery_scope_evaluated,
			'mcp_recovery_scoped_to_mad4b_routes' => $mcp_recovery_scoped,
			'current_http_request_targets_mad4b_mcp' => self::$mcp_recovery_request,
			'mcp_recovery_callbacks_removed_for_unrelated_request' => array_values( self::$mcp_recovery_callbacks_removed ),
			'wpml' => $wpml,
			'wpml_internal_probe_ready' => ! empty( $wpml['ready'] ),
			'query_parameters_preserved' => ! empty( $wpml['query_parameters_preserved'] ),
			'wpml_internal_probe_role' => 'diagnostic_only',
			'wpml_internal_probe_blocks_local_certification' => false,
			'external_wpml_acceptance_required' => $external_wpml_required,
			'external_wpml_acceptance_verified' => $external_wpml_verified,
			'external_wpml_acceptance_state' => ! $external_wpml_required ? 'not_required' : ( $external_wpml_verified ? 'verified' : 'pending' ),
			'external_wpml_acceptance' => self::bounded_external_wpml_receipt( $external_wpml ),
			'external_http_probe_performed' => false,
			'provider_probe_mode' => 'passive_snapshot',
			'provider_self_calls_started' => 0,
			'internal_rest_dispatch_performed' => false,
			'automatic_probe_retry_allowed' => false,
			'external_test_url' => self::wpml_external_test_url(),
			'note' => 'Local REST isolation remains structural and non-authorizing. Local WPML observation is passive-only: status reads never materialize REST, internally dispatch the provider route, or perform loopback HTTP. Behavioral acceptance comes only from the separately observed external receipt.',
		);
	}

	public static function wpml_probe() {
		if ( null !== self::$wpml_probe_cache ) return self::$wpml_probe_cache;
		$wpml_active = self::wpml_active();
		$snapshot = class_exists( 'MAD4B_SCP_Provider_Diagnostic_Policy' )
			? MAD4B_SCP_Provider_Diagnostic_Policy::rest_route_snapshot( self::WPML_ROUTE )
			: self::passive_rest_route_snapshot( self::WPML_ROUTE );
		$route_registered = array_key_exists( 'route_registered', $snapshot ) ? $snapshot['route_registered'] : null;
		$materialized = ! empty( $snapshot['rest_server_materialized'] );

		if ( ! $wpml_active ) {
			$state = 'wpml_not_active';
			$ready = true;
		} elseif ( null === $route_registered ) {
			$state = 'passive_route_unobserved';
			$ready = true;
		} elseif ( true === $route_registered ) {
			$state = 'passive_route_observed';
			$ready = true;
		} else {
			$state = 'wpml_route_missing';
			$ready = false;
		}

		self::$wpml_probe_cache = array(
			'ready' => $ready,
			'state' => $state,
			'wpml_active' => $wpml_active,
			'route_registered' => $route_registered,
			'query_parameters_preserved' => null,
			'control_plane_block_detected' => false,
			'rest_server_materialized' => $materialized,
			'rest_server_materialized_by_probe' => false,
			'probe_mode' => 'passive_snapshot',
			'active_probe_performed' => false,
			'internal_rest_dispatch_performed' => false,
			'loopback_http_performed' => false,
			'provider_self_calls_started' => 0,
			'automatic_retry_allowed' => false,
		);
		return self::$wpml_probe_cache;
	}

	private static function passive_rest_route_snapshot( $route ) {
		global $wp_rest_server;
		$server = isset( $wp_rest_server ) && is_object( $wp_rest_server ) && method_exists( $wp_rest_server, 'get_routes' )
			? $wp_rest_server
			: null;
		$out = array(
			'rest_server_materialized' => is_object( $server ),
			'route_registered' => null,
		);
		if ( ! is_object( $server ) ) return $out;
		try {
			$routes = $server->get_routes();
			if ( is_array( $routes ) ) $out['route_registered'] = array_key_exists( '/' . ltrim( rtrim( (string) $route, '/' ), '/' ), $routes );
		} catch ( Throwable $e ) {
			$out['route_registered'] = null;
		}
		return $out;
	}

	private static function bounded_external_wpml_receipt( $receipt ) {
		if ( ! is_array( $receipt ) ) return array();
		return array(
			'contract' => isset( $receipt['contract'] ) ? sanitize_text_field( (string) $receipt['contract'] ) : '',
			'observed' => ! empty( $receipt['observed'] ),
			'verified' => ! empty( $receipt['verified'] ),
			'stale' => ! empty( $receipt['stale'] ),
			'state' => isset( $receipt['state'] ) ? sanitize_key( (string) $receipt['state'] ) : '',
			'route_registered' => ! empty( $receipt['route_registered'] ),
			'response_status' => isset( $receipt['response_status'] ) ? (int) $receipt['response_status'] : 0,
			'classification' => isset( $receipt['classification'] ) ? sanitize_key( (string) $receipt['classification'] ) : '',
			'observed_at' => isset( $receipt['observed_at'] ) ? sanitize_text_field( (string) $receipt['observed_at'] ) : '',
		);
	}

	private static function wpml_active() {
		return defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' );
	}

	private static function wpml_external_test_url() {
		return add_query_arg(
			array( 'test_get_parameter' => '1', 'cachebuster' => (string) time() ),
			untrailingslashit( rest_url( ltrim( self::WPML_ROUTE, '/' ) ) )
		);
	}

	/**
	 * Inspect a global REST hook without executing or changing it. Callback source
	 * paths are reduced to plugin-relative/basename labels so diagnostics never
	 * disclose the server filesystem layout.
	 */
	private static function hook_inventory( $hook_name ) {
		global $wp_filter;
		$result = array(
			'hook' => sanitize_key( (string) $hook_name ),
			'registered' => false,
			'callback_count' => 0,
			'control_plane_callback_count' => 0,
			'control_plane_detected' => false,
			'truncated' => false,
			'callbacks' => array(),
		);
		if ( ! isset( $wp_filter[ $hook_name ] ) || ! is_object( $wp_filter[ $hook_name ] ) || ! isset( $wp_filter[ $hook_name ]->callbacks ) || ! is_array( $wp_filter[ $hook_name ]->callbacks ) ) return $result;

		$result['registered'] = true;
		foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
			if ( ! is_array( $callbacks ) ) continue;
			foreach ( $callbacks as $callback ) {
				if ( $result['callback_count'] >= self::MAX_HOOK_CALLBACKS ) { $result['truncated'] = true; break 2; }
				$callable = is_array( $callback ) && isset( $callback['function'] ) ? $callback['function'] : null;
				$described = self::describe_callback( $callable );
				$described['priority'] = (int) $priority;
				$result['callbacks'][] = $described;
				++$result['callback_count'];
				if ( ! empty( $described['control_plane'] ) ) ++$result['control_plane_callback_count'];
			}
		}
		$result['control_plane_detected'] = $result['control_plane_callback_count'] > 0;
		return $result;
	}

	private static function describe_callback( $callable ) {
		$label = 'unknown';
		$class = '';
		$file = '';
		$reflection = null;
		try {
			if ( is_string( $callable ) ) {
				$label = $callable;
				if ( false !== strpos( $callable, '::' ) ) {
					list( $class, $method ) = explode( '::', $callable, 2 );
					$reflection = new ReflectionMethod( $class, $method );
				} elseif ( function_exists( $callable ) ) {
					$reflection = new ReflectionFunction( $callable );
				}
			} elseif ( is_array( $callable ) && 2 === count( $callable ) ) {
				$class = is_object( $callable[0] ) ? get_class( $callable[0] ) : (string) $callable[0];
				$method = (string) $callable[1];
				$label = $class . '::' . $method;
				$reflection = new ReflectionMethod( $callable[0], $method );
			} elseif ( $callable instanceof Closure ) {
				$label = 'Closure';
				$reflection = new ReflectionFunction( $callable );
			} elseif ( is_object( $callable ) && method_exists( $callable, '__invoke' ) ) {
				$class = get_class( $callable );
				$label = $class . '::__invoke';
				$reflection = new ReflectionMethod( $callable, '__invoke' );
			}
			if ( $reflection && method_exists( $reflection, 'getFileName' ) ) {
				$ref_file = $reflection->getFileName();
				if ( is_string( $ref_file ) ) $file = $ref_file;
			}
		} catch ( Throwable $e ) {
			$reflection = null;
		}

		$control_plane = 0 === strpos( $class, 'MAD4B_SCP_' );
		$root = defined( 'MAD4B_SCP_DIR' ) ? realpath( MAD4B_SCP_DIR ) : false;
		$resolved = '' !== $file ? realpath( $file ) : false;
		if ( false !== $root && false !== $resolved ) {
			$root_normalized = rtrim( wp_normalize_path( $root ), '/' ) . '/';
			$file_normalized = wp_normalize_path( $resolved );
			if ( 0 === strpos( $file_normalized, $root_normalized ) ) $control_plane = true;
		}

		$file_label = '';
		if ( false !== $resolved ) {
			if ( $control_plane && false !== $root ) {
				$file_label = ltrim( substr( wp_normalize_path( $resolved ), strlen( rtrim( wp_normalize_path( $root ), '/' ) ) ), '/' );
			} else {
				$file_label = basename( $resolved );
			}
		}
		return array(
			'callback' => sanitize_text_field( $label ),
			'file' => sanitize_text_field( $file_label ),
			'control_plane' => (bool) $control_plane,
		);
	}
}
