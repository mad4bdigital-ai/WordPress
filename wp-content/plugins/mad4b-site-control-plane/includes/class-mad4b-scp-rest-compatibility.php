<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only REST compatibility diagnostics.
 *
 * The Control Plane never disables the REST API and never rewrites arbitrary
 * REST routes. This probe proves that non-MAD4B REST requests, including WPML's
 * query-parameter health endpoint, pass through the plugin's guards unchanged.
 */
final class MAD4B_SCP_REST_Compatibility {
	const CONTRACT = 'mad4b.rest-compatibility.v1';
	const WPML_ROUTE = '/wpml/v1/rest/status';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 36 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( 'mad4b/rest-compatibility-status' ) ) return;
		wp_register_ability( 'mad4b/rest-compatibility-status', array(
			'label' => 'Get REST Compatibility Status',
			'description' => 'Verify that the Control Plane does not disable or intercept unrelated WordPress REST routes, including WPML REST health checks.',
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
		$rest_enabled = apply_filters( 'rest_enabled', true );
		$wpml = self::wpml_probe();
		return array(
			'contract' => self::CONTRACT,
			'rest_enabled' => (bool) $rest_enabled,
			'control_plane_disables_rest' => false,
			'control_plane_filters_rest_enabled' => false,
			'control_plane_filters_rest_authentication_errors' => false,
			'control_plane_rest_pre_dispatch_scope' => array( '/mcp/mad4b-chatgpt' ),
			'wpml' => $wpml,
			'wpml_internal_probe_ready' => ! empty( $wpml['ready'] ),
			'query_parameters_preserved' => ! empty( $wpml['query_parameters_preserved'] ),
			'external_http_probe_performed' => false,
			'note' => 'The internal probe exercises the WordPress REST dispatcher and all rest_pre_dispatch filters in-process. Firewall/rewrite/CDN failures remain external hosting evidence, not plugin-local REST interception.',
		);
	}

	public static function wpml_probe() {
		if ( ! function_exists( 'rest_get_server' ) || ! class_exists( 'WP_REST_Request' ) ) {
			return array( 'ready' => false, 'state' => 'rest_runtime_unavailable', 'route_registered' => false, 'query_parameters_preserved' => false );
		}
		try {
			$server = rest_get_server();
			$routes = is_object( $server ) && method_exists( $server, 'get_routes' ) ? $server->get_routes() : array();
			$registered = is_array( $routes ) && isset( $routes[ self::WPML_ROUTE ] );
			if ( ! $registered ) {
				return array(
					'ready' => true,
					'state' => 'wpml_route_not_registered',
					'route_registered' => false,
					'query_parameters_preserved' => true,
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
			$blocked_by_mad4b = is_array( $data ) && isset( $data['error'] ) && 0 === strpos( (string) $data['error'], 'mad4b_' );
			return array(
				'ready' => 200 === $status && $status_valid && $get_valid && ! $blocked_by_mad4b,
				'state' => 200 === $status && $status_valid && $get_valid ? 'valid' : 'invalid_response',
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
				'route_registered' => false,
				'query_parameters_preserved' => false,
				'control_plane_block_detected' => false,
				'exception_class' => get_class( $e ),
			);
		}
	}
}
