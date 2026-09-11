<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Request-local binding between the MCP HTTP transport endpoint and central
 * mutation authorization. No credential/session material is stored here.
 */
final class MAD4B_SCP_Transport_Context {
	const CONTRACT = 'mad4b.mcp-transport-context.v1';

	private static $server_id = '';
	private static $route = '';

	public static function bind( $server_id, $request ) {
		self::clear();
		$server_id = sanitize_key( (string) $server_id );
		$expected = class_exists( 'MAD4B_SCP_Servers' )
			? MAD4B_SCP_Servers::expected_server_ids()
			: array( 'mad4b-read', 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' );
		if ( ! in_array( $server_id, $expected, true ) ) {
			return new WP_Error( 'mad4b_transport_server_unknown', 'The MCP transport server is not a governed MAD4B server.' );
		}
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return new WP_Error( 'mad4b_transport_request_unavailable', 'The MCP transport request route is unavailable.' );
		}

		$route = (string) $request->get_route();
		$expected_route = '/mcp/' . $server_id;
		if ( $route !== $expected_route ) {
			return new WP_Error(
				'mad4b_transport_route_mismatch',
				'The MCP request route does not match the server permission callback.',
				array( 'expected_route' => $expected_route )
			);
		}

		self::$server_id = $server_id;
		self::$route = $route;
		return true;
	}

	public static function resolve_server_for_ability( $declared_server_id, $ability_name ) {
		$declared_server_id = sanitize_key( (string) $declared_server_id );
		$current = self::current_server_id();
		if ( '' === $current ) return $declared_server_id;
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) {
			return new WP_Error( 'mad4b_transport_server_registry_unavailable', 'MAD4B server membership is unavailable.' );
		}
		if ( ! MAD4B_SCP_Servers::ability_is_mounted( $current, (string) $ability_name ) ) {
			return new WP_Error( 'mad4b_transport_ability_not_mounted', 'The requested ability is not mounted on the active MCP transport server.' );
		}
		return $current;
	}

	public static function current_server_id() {
		return sanitize_key( (string) self::$server_id );
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'bound' => '' !== self::$server_id,
			'server_id' => self::current_server_id(),
			'route' => sanitize_text_field( (string) self::$route ),
			'credential_material_stored' => false,
		);
	}

	public static function clear() {
		self::$server_id = '';
		self::$route = '';
	}
}
