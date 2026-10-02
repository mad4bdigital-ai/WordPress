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
	private static $developer_dispatch_target = '';
	private static $developer_dispatch_schema_sha256 = '';

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

		// The compact ChatGPT transport may delegate exactly one normal Developer
		// target after schema binding and request-local Developer identity derivation.
		// Breakglass is never eligible for this path.
		if ( 'mad4b-chatgpt' === $current && self::developer_dispatch_target_matches( $ability_name ) ) {
			if ( ! class_exists( 'MAD4B_SCP_Developer_Runtime' ) || ! in_array( $ability_name, MAD4B_SCP_Developer_Runtime::tool_names( false ), true ) ) {
				return new WP_Error( 'mad4b_developer_dispatch_target_denied', 'The nested Developer target is outside the normal Developer tool inventory.' );
			}
			if ( 0 === strpos( $ability_name, 'mad4b/developer-breakglass-' ) ) return new WP_Error( 'mad4b_developer_dispatch_breakglass_denied', 'Developer Breakglass is never available through the ChatGPT dispatcher.' );
			if ( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-developer', $ability_name ) ) return new WP_Error( 'mad4b_developer_dispatch_mount_missing', 'The nested Developer target is not mounted on mad4b-developer.' );
			return 'mad4b-developer';
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

		// Dynamic projection cannot change a mutation's original authorization lane.
		// Normal external writes have already passed the dedicated write admission above.
		if ( 'mad4b-chatgpt' === $current && class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) && MAD4B_SCP_ChatGPT_Tool_Projection::is_projected( $ability_name ) ) {
			$original = MAD4B_SCP_ChatGPT_Tool_Projection::execution_server( $ability_name );
			if ( is_wp_error( $original ) ) return $original;
			if ( $declared_server_id !== $original ) return new WP_Error( 'mad4b_projection_execution_lane_mismatch', 'Projection cannot change the declared execution authority.' );
			return $original;
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

	/**
	 * Execute exactly one normal Developer target behind mad4b/developer-execute.
	 * The outer HTTP transport remains mad4b-chatgpt. The derived Developer
	 * identity exists only for the nested callback and creates no OAuth scope,
	 * grant, approval or persistent credential.
	 */
	public static function with_developer_dispatch_target( $ability_name, $expected_schema_sha256, $callback ) {
		$ability_name = trim( (string) $ability_name );
		$expected_schema_sha256 = strtolower( trim( (string) $expected_schema_sha256 ) );
		if ( 'mad4b-chatgpt' !== self::current_server_id() ) return new WP_Error( 'mad4b_developer_dispatch_transport_invalid', 'Nested Developer dispatch requires the active ChatGPT transport.' );
		if ( ! is_callable( $callback ) ) return new WP_Error( 'mad4b_developer_dispatch_callback_invalid', 'Nested Developer dispatch requires a callable target.' );
		if ( '' !== self::$developer_dispatch_target || '' !== self::$write_dispatch_target ) return new WP_Error( 'mad4b_developer_dispatch_nested_recursion_denied', 'Nested mutation dispatcher recursion is not allowed.' );
		if ( '' === $ability_name || 'mad4b/developer-execute' === $ability_name || 0 === strpos( $ability_name, 'mad4b/developer-breakglass-' ) ) return new WP_Error( 'mad4b_developer_dispatch_target_denied', 'The requested Developer target is not eligible for compact dispatcher delegation.' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_schema_sha256 ) ) return new WP_Error( 'mad4b_developer_dispatch_schema_invalid', 'Nested Developer dispatch requires an exact input schema digest.' );
		if ( ! class_exists( 'MAD4B_SCP_Developer_Runtime' ) || ! in_array( $ability_name, MAD4B_SCP_Developer_Runtime::tool_names( false ), true ) ) return new WP_Error( 'mad4b_developer_dispatch_target_not_cataloged', 'The requested target is not in the normal Developer inventory.' );
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-developer', $ability_name ) ) return new WP_Error( 'mad4b_developer_dispatch_target_not_runtime_eligible', 'The requested Developer target is not mounted on the dedicated Developer authority.' );
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) return new WP_Error( 'mad4b_developer_dispatch_target_unavailable', 'The requested Developer target is not registered in the current runtime.' );
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_input_schema' ) ) return new WP_Error( 'mad4b_developer_dispatch_contract_unavailable', 'The requested Developer target does not expose an input schema contract.' );
		$encoded_schema = wp_json_encode( $ability->get_input_schema(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded_schema || ! hash_equals( hash( 'sha256', $encoded_schema ), $expected_schema_sha256 ) ) return new WP_Error( 'mad4b_developer_dispatch_schema_drift', 'Developer target schema changed after dispatcher validation.' );
		if ( ! class_exists( 'MAD4B_SCP_Developer_Authority' ) || ! method_exists( 'MAD4B_SCP_Developer_Authority', 'chatgpt_dispatch_identity' ) ) return new WP_Error( 'mad4b_developer_dispatch_authority_unavailable', 'Developer dispatch authority derivation is unavailable.' );
		$derived = MAD4B_SCP_Developer_Authority::chatgpt_dispatch_identity();
		if ( is_wp_error( $derived ) ) return $derived;
		if ( ! is_array( $derived ) || empty( $derived['subject_fingerprint'] ) ) return new WP_Error( 'mad4b_developer_dispatch_identity_unavailable', 'Developer dispatch identity derivation did not return an exact subject fingerprint.' );
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! method_exists( 'MAD4B_SCP_Identity_Context', 'with_request_subject_override' ) ) return new WP_Error( 'mad4b_developer_dispatch_identity_context_unavailable', 'Request-local Developer identity overlay is unavailable.' );

		self::$developer_dispatch_target = $ability_name;
		self::$developer_dispatch_schema_sha256 = $expected_schema_sha256;
		try {
			return MAD4B_SCP_Identity_Context::with_request_subject_override(
				'oauth_developer',
				(string) $derived['subject_fingerprint'],
				'mcp_developer_dispatch',
				$callback
			);
		} finally {
			self::$developer_dispatch_target = '';
			self::$developer_dispatch_schema_sha256 = '';
		}
	}

	public static function developer_dispatch_target_matches( $ability_name ) {
		$ability_name = (string) $ability_name;
		return '' !== self::$developer_dispatch_target
			&& hash_equals( self::$developer_dispatch_target, $ability_name )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', self::$developer_dispatch_schema_sha256 );
	}

	public static function write_dispatch_target_matches( $ability_name, $expected_schema_sha256 = '' ) {
		$ability_name = (string) $ability_name;
		$expected_schema_sha256 = strtolower( trim( (string) $expected_schema_sha256 ) );
		if ( '' === self::$write_dispatch_target || ! hash_equals( self::$write_dispatch_target, $ability_name ) ) return false;
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', self::$write_dispatch_schema_sha256 ) ) return false;
		return '' === $expected_schema_sha256 || ( 1 === preg_match( '/^[a-f0-9]{64}$/', $expected_schema_sha256 ) && hash_equals( self::$write_dispatch_schema_sha256, $expected_schema_sha256 ) );
	}

	public static function bounded_write_dispatch_active_for( $ability_name ) {
		return 'mad4b-chatgpt' === self::current_server_id() && self::write_dispatch_target_matches( $ability_name );
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
			'developer_dispatch_target_bound' => '' !== self::$developer_dispatch_target,
			'chatgpt_developer_dispatch_breakglass_allowed' => false,
			'credential_material_stored' => false,
		);
	}

	public static function clear() {
		self::$server_id = '';
		self::$route = '';
		self::$write_dispatch_target = '';
		self::$write_dispatch_schema_sha256 = '';
		self::$developer_dispatch_target = '';
		self::$developer_dispatch_schema_sha256 = '';
	}
}
