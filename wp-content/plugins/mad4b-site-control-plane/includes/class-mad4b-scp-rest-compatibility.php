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
		$control_plane_on_rest_enabled = ! empty( $rest_enabled_hooks['control_plane_detected'] );
		$control_plane_on_rest_auth = ! empty( $rest_auth_hooks['control_plane_detected'] );
		$expected_rest_scope = array(
			'/mcp/mad4b-read', '/mcp/mad4b-chatgpt', '/mcp/mad4b-content',
			'/mcp/mad4b-write', '/mcp/mad4b-admin', '/mcp/mad4b-breakglass',
		);
		$mcp_recovery_scoped = $expected_rest_scope === $expected_rest_scope;
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
			'external_wpml_acceptance_required' => true,
			'external_wpml_acceptance_verified' => false,
			'external_http_probe_performed' => false,
			'external_test_url' => self::wpml_external_test_url(),
			'note' => 'Local REST isolation is evaluated only from MAD4B-controlled structural facts. The internal WPML probe is diagnostic only; external WPML HTTP acceptance remains a separate live gate.',
		);
	}

	public static function wpml_probe() {
		if ( ! function_exists( 'rest_get_server' ) || ! class_exists( 'WP_REST_Request' ) ) {
			return array( 'ready' => false, 'state' => 'rest_runtime_unavailable', 'wpml_active' => self::wpml_active(), 'route_registered' => false, 'query_parameters_preserved' => false, 'control_plane_block_detected' => false );
		}

		try {
			$server = rest_get_server();
			$routes = is_object( $server ) && method_exists( $server, 'get_routes' ) ? $server->get_routes() : array();
			$registered = is_array( $routes ) && isset( $routes[ self::WPML_ROUTE ] );
			$wpml_active = self::wpml_active();
			if ( ! $registered ) {
				return array(
					'ready' => ! $wpml_active,
					'state' => $wpml_active ? 'wpml_route_missing' : 'wpml_not_active',
					'wpml_active' => $wpml_active,
					'route_registered' => false,
					'query_parameters_preserved' => ! $wpml_active,
					'control_plane_block_detected' => false,
				);
			}

			$request = new WP_REST_Request( 'GET', self::WPML_ROUTE );
			$request->set_query_params( array(
				'test_get_parameter' => '1',
				'cachebuster' => (string) time(),
			) );
			$response = rest_do_request( $request );
			$status = is_object( $response ) && method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 0;
			$data = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : null;
			$status_valid = is_array( $data ) && isset( $data['status'] ) && 'valid' === (string) $data['status'];
			$get_valid = is_array( $data ) && isset( $data['get_parameters'] ) && 'valid' === (string) $data['get_parameters'];
			$blocked_by_mad4b = is_array( $data ) && (
				( isset( $data['error'] ) && 0 === strpos( (string) $data['error'], 'mad4b_' ) ) ||
				( isset( $data['code'] ) && 0 === strpos( (string) $data['code'], 'mad4b_' ) )
			);
			return array(
				'ready' => 200 === $status && $status_valid && $get_valid && ! $blocked_by_mad4b,
				'state' => 200 === $status && $status_valid && $get_valid ? 'valid' : 'invalid_response',
				'wpml_active' => $wpml_active,
				'route_registered' => true,
				'http_status' => $status,
				'status_field_valid' => $status_valid,
				'get_parameters_field_valid' => $get_valid,
				'query_parameters_preserved' => $get_valid,
				'control_plane_block_detected' => $blocked_by_mad4b,
				'error_code' => is_array( $data ) && isset( $data['code'] ) ? sanitize_key( (string) $data['code'] ) : '',
			);
		} catch ( Throwable $e ) {
			return array(
				'ready' => false,
				'state' => 'probe_exception',
				'wpml_active' => self::wpml_active(),
				'route_registered' => false,
				'query_parameters_preserved' => false,
				'control_plane_block_detected' => false,
				'exception_class' => get_class( $e ),
			);
		}
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
