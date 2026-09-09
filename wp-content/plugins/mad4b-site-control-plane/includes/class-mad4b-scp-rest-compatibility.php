<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only REST compatibility diagnostics.
 *
 * The Control Plane never needs to disable the REST API or globally rewrite
 * unrelated REST authentication. This probe inspects the live hook registry and
 * exercises WPML's query-parameter health route through the WordPress REST
 * dispatcher. External CDN/WAF/rewrite behavior is intentionally reported as a
 * separate acceptance boundary.
 */
final class MAD4B_SCP_REST_Compatibility {
	const CONTRACT = 'mad4b.rest-compatibility.v2';
	const WPML_ROUTE = '/wpml/v1/rest/status';
	const MAX_HOOK_CALLBACKS = 200;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 36 );
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

		return array(
			'contract' => self::CONTRACT,
			'rest_enabled' => $rest_enabled,
			'control_plane_disables_rest' => ! $rest_enabled && $control_plane_on_rest_enabled,
			'control_plane_filters_rest_enabled' => $control_plane_on_rest_enabled,
			'control_plane_filters_rest_authentication_errors' => $control_plane_on_rest_auth,
			'rest_enabled_hook' => $rest_enabled_hooks,
			'rest_authentication_errors_hook' => $rest_auth_hooks,
			'control_plane_rest_pre_dispatch_scope' => array( '/mcp/mad4b-chatgpt' ),
			'wpml' => $wpml,
			'wpml_internal_probe_ready' => ! empty( $wpml['ready'] ),
			'query_parameters_preserved' => ! empty( $wpml['query_parameters_preserved'] ),
			'external_http_probe_performed' => false,
			'external_test_url' => self::wpml_external_test_url(),
			'note' => 'The internal probe exercises the WordPress REST dispatcher and live rest_pre_dispatch filters in-process. CDN/WAF/Apache/Nginx failures remain external hosting evidence and require a separate HTTP acceptance probe.',
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
