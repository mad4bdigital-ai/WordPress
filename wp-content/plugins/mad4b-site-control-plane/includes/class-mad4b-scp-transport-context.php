<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Request-local binding between the MCP HTTP transport endpoint and central
 * mutation authorization. No credential/session material is stored here.
 */
final class MAD4B_SCP_Transport_Context {
	const CONTRACT = 'mad4b.mcp-transport-context.v3';

	private static $server_id = '';
	private static $route = '';

	public static function bind( $server_id, $request ) {
		self::clear();
		$server_id = sanitize_key( (string) $server_id );
		$expected = class_exists( 'MAD4B_SCP_Servers' )
			? MAD4B_SCP_Servers::expected_server_ids()
			: array( 'mad4b-read', 'mad4b-chatgpt', 'mad4b-enrollment', 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' );
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
		$ability_name = (string) $ability_name;
		$current = self::current_server_id();
		if ( '' === $current ) return $declared_server_id;
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) {
			return new WP_Error( 'mad4b_transport_server_registry_unavailable', 'MAD4B server membership is unavailable.' );
		}

		// The exact enrolled Staging ChatGPT transport may expose the complete stable
		// governed mutation catalog for discovery. Visibility is not authority. Every
		// normal mutation is forced through mad4b-write and fails closed until the
		// Staging write authority, runtime eligibility and dedicated mount are ready.
		if ( 'mad4b-chatgpt' === $current && MAD4B_SCP_Servers::is_external_write_candidate( $ability_name ) ) {
			if ( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $ability_name ) ) {
				return new WP_Error( 'mad4b_transport_ability_not_mounted', 'The requested write ability is not mounted on the active ChatGPT transport.' );
			}
			if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) {
				return new WP_Error( 'mad4b_write_authority_not_ready', 'The requested write ability is discoverable, but governed Staging write authority is not ready.' );
			}
			if ( ! MAD4B_SCP_Staging_Write_Authority::is_write_ability( $ability_name ) ) {
				return new WP_Error( 'mad4b_write_capability_not_eligible', 'The requested provider write is discoverable but is not currently certified for governed execution.' );
			}
			if ( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability_name ) ) {
				return new WP_Error( 'mad4b_write_authority_mount_missing', 'The requested write ability is not mounted on the governed mad4b-write authority server.' );
			}
			return 'mad4b-write';
		}

		if ( ! MAD4B_SCP_Servers::ability_is_mounted( $current, $ability_name ) ) {
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
			'chatgpt_write_delegation_enabled' => class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective(),
			'chatgpt_write_discovery_model' => 'stable_unified_catalog_fail_closed_execution',
			'chatgpt_write_authority_server' => 'mad4b-write',
			'credential_material_stored' => false,
		);
	}

	public static function clear() {
		self::$server_id = '';
		self::$route = '';
	}
}
