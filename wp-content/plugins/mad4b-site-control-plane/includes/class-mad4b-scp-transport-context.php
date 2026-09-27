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
	private static $write_dispatch_target = '';
	private static $write_dispatch_schema_sha256 = '';

	public static function bind( $server_id, $request ) {
		self::clear();
		$server_id = sanitize_key( (string) $server_id );
		$expected = class_exists( 'MAD4B_SCP_Servers' )
			? MAD4B_SCP_Servers::expected_server_ids()
			: array( 'mad4b-read', 'mad4b-chatgpt', 'mad4b-enrollment', 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-developer', 'mad4b-developer-breakglass', 'mad4b-breakglass' );
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

	public static function resolve_server_for_ability( $declared_server_id, $ability_name, $input = null ) {
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
			$nested_dispatch = self::write_dispatch_target_matches( $ability_name );
			if ( ! $nested_dispatch && ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $ability_name ) ) {
				return new WP_Error( 'mad4b_transport_ability_not_mounted', 'The requested write ability is not mounted on the active ChatGPT transport.' );
			}
			$authority_class = class_exists( 'MAD4B_SCP_Staging_Write_Authority' );
			$authority_effective = $authority_class && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'effective' ) && MAD4B_SCP_Staging_Write_Authority::effective();
			$bootstrap_supported = $authority_class && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_bootstrap_allowed' );
			$bootstrap_allowed = $bootstrap_supported && MAD4B_SCP_Staging_Write_Authority::candidate_bootstrap_allowed( $ability_name, $input );
			if ( ! $authority_effective && ! $bootstrap_allowed ) {
				$data = $bootstrap_supported && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_bootstrap_status' ) ? MAD4B_SCP_Staging_Write_Authority::candidate_bootstrap_status( $ability_name, $input ) : array();
				return new WP_Error( 'mad4b_write_authority_not_ready', 'The requested write ability is discoverable, but governed Staging write authority is not ready.', array( 'candidate_bootstrap' => $data ) );
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

	/**
	 * Execute exactly one hidden governed write target behind mad4b/write-execute.
	 *
	 * The outer HTTP transport remains mad4b-chatgpt. This request-local binding
	 * authorizes only transport resolution for the exact target/schema already
	 * selected by the dispatcher; it creates no grant, OAuth scope or approval.
	 */
	public static function with_write_dispatch_target( $ability_name, $expected_schema_sha256, $callback ) {
		$ability_name = trim( (string) $ability_name );
		$expected_schema_sha256 = strtolower( trim( (string) $expected_schema_sha256 ) );
		if ( 'mad4b-chatgpt' !== self::current_server_id() ) return new WP_Error( 'mad4b_write_dispatch_transport_invalid', 'Nested governed write dispatch requires the active ChatGPT transport.' );
		if ( ! is_callable( $callback ) ) return new WP_Error( 'mad4b_write_dispatch_callback_invalid', 'Nested governed write dispatch requires a callable target.' );
		if ( '' !== self::$write_dispatch_target ) return new WP_Error( 'mad4b_write_dispatch_nested_recursion_denied', 'Nested governed write dispatch recursion is not allowed.' );
		if ( '' === $ability_name || in_array( $ability_name, array( 'mad4b/write-execute', 'mad4b/enrollment-execute', 'mad4b/database-raw-query' ), true ) ) return new WP_Error( 'mad4b_write_dispatch_target_denied', 'The requested nested write target is not eligible for dispatcher delegation.' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_schema_sha256 ) ) return new WP_Error( 'mad4b_write_dispatch_schema_invalid', 'Nested governed write dispatch requires an exact input schema digest.' );
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! MAD4B_SCP_Servers::is_external_write_candidate( $ability_name ) || ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability_name ) ) return new WP_Error( 'mad4b_write_dispatch_target_not_runtime_eligible', 'Nested governed write target is not mounted on the dedicated write authority.' );
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) return new WP_Error( 'mad4b_write_dispatch_target_unavailable', 'Nested governed write target is not registered in the current runtime.' );
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_input_schema' ) ) return new WP_Error( 'mad4b_write_dispatch_contract_unavailable', 'Nested governed write target does not expose the required input schema contract.' );
		$encoded_schema = wp_json_encode( $ability->get_input_schema(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded_schema || ! hash_equals( hash( 'sha256', $encoded_schema ), $expected_schema_sha256 ) ) return new WP_Error( 'mad4b_write_dispatch_schema_drift', 'Nested governed write target schema changed after dispatcher validation.' );

		self::$write_dispatch_target = $ability_name;
		self::$write_dispatch_schema_sha256 = $expected_schema_sha256;
		try {
			return call_user_func( $callback );
		} finally {
			self::$write_dispatch_target = '';
			self::$write_dispatch_schema_sha256 = '';
		}
	}

	private static function write_dispatch_target_matches( $ability_name ) {
		$ability_name = (string) $ability_name;
		return '' !== self::$write_dispatch_target
			&& hash_equals( self::$write_dispatch_target, $ability_name )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', self::$write_dispatch_schema_sha256 );
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
			'write_dispatch_target_bound' => '' !== self::$write_dispatch_target,
			'credential_material_stored' => false,
		);
	}

	public static function clear() {
		self::$server_id = '';
		self::$route = '';
		self::$write_dispatch_target = '';
		self::$write_dispatch_schema_sha256 = '';
	}
}
