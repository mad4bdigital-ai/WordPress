<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Hardened policy/execution helper for bounded Staging enrollment operations.
 *
 * Registration of mad4b/enrollment-* is owned exclusively by MAD4B_SCP_Abilities.
 *
 * This is deliberately not a generic remote-admin surface. Only operations
 * already admitted by Remote Operation Parity may be selected, and only when
 * the current catalog says they are:
 * - mounted on mad4b-enrollment,
 * - addressed to the operator caller role,
 * - remote-parity ready and execution eligible,
 * - Staging/production-denied,
 * - not human-decision operations.
 *
 * Execution additionally requires the dedicated OAuth authority step-up scope,
 * the exact ChatGPT CIMD client, an enrolled Staging administrator, exact
 * catalog registration + dispatch-policy digests, and exact input-schema hash.
 */
final class MAD4B_SCP_Enrollment_Dispatch {
	const CONTRACT = 'mad4b.chatgpt-enrollment-dispatch.v1';
	const DISCOVER_ABILITY = 'mad4b/enrollment-discover';
	const INFO_ABILITY = 'mad4b/enrollment-info';
	const EXECUTE_ABILITY = 'mad4b/enrollment-execute';

	public static function can_execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_enrollment_dispatch_admin_required', 'Administrator capability is required.' );
		if ( ! self::staging_profile_exact() ) return new WP_Error( 'mad4b_enrollment_dispatch_staging_profile_required', 'Exact enrolled Staging Site Profile is required.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		}
		if ( self::generic_raw_sql_breakglass_enabled() || ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_breakglass() ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_breakglass_denied', 'Breakglass must remain disabled during bounded enrollment dispatch.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_bearer_required', 'Verified OAuth bearer identity is required.' );
		}
		if ( ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_step_up_scope_required', 'Dedicated Staging authority step-up scope is required.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
			|| ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_chatgpt_client_required', 'Enrollment dispatch requires OAuth attribution to the exact ChatGPT CIMD client.' );
		}
		if ( ! is_array( $input ) || empty( $input['operation_id'] ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_operation_required', 'A governed operation_id is required.' );
		}
		$operation = self::operation( (string) $input['operation_id'] );
		return is_wp_error( $operation ) ? $operation : true;
	}

	private static function staging_profile_exact() {
		return class_exists( 'MAD4B_SCP_Site_Profile' )
			&& MAD4B_SCP_Site_Profile::configured()
			&& 'staging' === sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() )
			&& MAD4B_SCP_Site_Profile::origin_enrolled()
			&& MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
	}

	private static function generic_raw_sql_breakglass_enabled() {
		return defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) && true === constant( 'MAD4B_MCP_BREAKGLASS_ENABLED' );
	}

	private static function target_ability( $ability_name ) {
		$ability_name = trim( (string) $ability_name );
		if ( '' === $ability_name ) return new WP_Error( 'mad4b_enrollment_dispatch_target_missing', 'Enrollment operation has no target ability.' );
		if ( in_array( $ability_name, array( self::DISCOVER_ABILITY, self::INFO_ABILITY, self::EXECUTE_ABILITY ), true ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_recursion_denied', 'Nested enrollment dispatch is not allowed.' );
		}
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_target_unavailable', 'Enrollment target ability is not registered in the current runtime.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-enrollment', $ability_name ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_target_not_mounted', 'Enrollment target is not mounted on the bounded enrollment surface.' );
		}
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) || ! method_exists( $ability, 'execute' ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_target_contract_unavailable', 'Enrollment target does not expose the required Ability contract.' );
		}
		$meta = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_read_target_denied', 'Only explicitly mutating enrollment abilities may be dispatched.' );
		}
		if ( 'enrollment' !== ( isset( $mcp['surface'] ) ? (string) $mcp['surface'] : '' ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_target_surface_mismatch', 'Enrollment target metadata does not bind the ability to the enrollment surface.' );
		}
		if ( ! array_key_exists( 'generic_remote_admin', $mcp ) || false !== $mcp['generic_remote_admin'] ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_generic_admin_denied', 'Generic remote-admin enrollment targets are not dispatchable.' );
		}
		if ( ! array_key_exists( 'production_mutation_allowed', $mcp ) || false !== $mcp['production_mutation_allowed'] ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_target_production_denied', 'Production-capable enrollment targets are not dispatchable.' );
		}
		return $ability;
	}

	private static function ability_input_schema_sha256( $ability ) {
		$schema = is_object( $ability ) && method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) $json = 'null';
		return hash( 'sha256', $json );
	}

	private static function dispatch_policy_digest( $operation_id, array $row, $input_schema_sha256 ) {
		$payload = array(
			'contract' => self::CONTRACT,
			'operation_id' => (string) $operation_id,
			'registration_digest' => isset( $row['registration_digest'] ) ? (string) $row['registration_digest'] : '',
			'remote_ability' => isset( $row['remote_ability'] ) ? (string) $row['remote_ability'] : '',
			'authority_surface' => isset( $row['authority_surface'] ) ? (string) $row['authority_surface'] : '',
			'remote_caller_role' => isset( $row['remote_caller_role'] ) ? (string) $row['remote_caller_role'] : '',
			'remote_mode' => isset( $row['remote_mode'] ) ? (string) $row['remote_mode'] : '',
			'production_policy' => isset( $row['production_policy'] ) ? (string) $row['production_policy'] : '',
			'human_decision_required' => ! empty( $row['human_decision_required'] ),
			'remote_registered' => ! empty( $row['remote_registered'] ),
			'execution_eligible' => ! empty( $row['execution_eligible'] ),
			'remote_parity_ready' => ! empty( $row['remote_parity_ready'] ),
			'input_schema_sha256' => strtolower( (string) $input_schema_sha256 ),
		);
		ksort( $payload, SORT_STRING );
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	private static function operation( $operation_id ) {
		$operation_id = sanitize_key( (string) $operation_id );
		if ( '' === $operation_id || ! class_exists( 'MAD4B_SCP_Remote_Operation_Parity' ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_operation_unknown', 'Governed enrollment operation is unknown.' );
		}
		$catalog = MAD4B_SCP_Remote_Operation_Parity::catalog();
		if ( ! isset( $catalog[ $operation_id ] ) || ! is_array( $catalog[ $operation_id ] ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_operation_unknown', 'Governed enrollment operation is not present in the current catalog.' );
		}
		$row = $catalog[ $operation_id ];
		$ability_name = isset( $row['remote_ability'] ) ? (string) $row['remote_ability'] : '';
		$enrollment_abilities = MAD4B_SCP_Remote_Operation_Parity::enrollment_abilities();
		if ( 'mad4b-enrollment' !== ( isset( $row['authority_surface'] ) ? (string) $row['authority_surface'] : '' )
			|| 'operator' !== ( isset( $row['remote_caller_role'] ) ? (string) $row['remote_caller_role'] : '' )
			|| ! in_array( $ability_name, $enrollment_abilities, true )
			|| ! empty( $row['human_decision_required'] )
			|| 'deny' !== ( isset( $row['production_policy'] ) ? (string) $row['production_policy'] : '' )
			|| empty( $row['remote_registered'] )
			|| empty( $row['execution_eligible'] )
			|| empty( $row['remote_parity_ready'] ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_operation_not_eligible', 'Operation is not eligible for non-human-decision ChatGPT enrollment dispatch.' );
		}
		$ability = self::target_ability( $ability_name );
		if ( is_wp_error( $ability ) ) return $ability;
		$schema_sha = self::ability_input_schema_sha256( $ability );
		$row['operation_id'] = $operation_id;
		$row['input_schema_sha256'] = $schema_sha;
		$row['dispatch_policy_digest'] = self::dispatch_policy_digest( $operation_id, $row, $schema_sha );
		return array( 'row' => $row, 'ability' => $ability );
	}

	private static function eligible_operations() {
		if ( ! class_exists( 'MAD4B_SCP_Remote_Operation_Parity' ) ) return array();
		$eligible = array();
		foreach ( MAD4B_SCP_Remote_Operation_Parity::catalog() as $operation_id => $row ) {
			$current = self::operation( $operation_id );
			if ( is_wp_error( $current ) ) continue;
			$eligible[ $operation_id ] = $current['row'];
		}
		ksort( $eligible, SORT_STRING );
		return $eligible;
	}

	public static function discover( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$query = isset( $input['query'] ) ? strtolower( trim( sanitize_text_field( (string) $input['query'] ) ) ) : '';
		$limit = isset( $input['limit'] ) ? max( 1, min( 100, absint( $input['limit'] ) ) ) : 50;
		$items = array();
		foreach ( self::eligible_operations() as $operation_id => $row ) {
			$haystack = strtolower( implode( ' ', array(
				$operation_id,
				isset( $row['feature_id'] ) ? (string) $row['feature_id'] : '',
				isset( $row['remote_ability'] ) ? (string) $row['remote_ability'] : '',
				isset( $row['provider'] ) ? (string) $row['provider'] : '',
				isset( $row['executor'] ) ? (string) $row['executor'] : '',
				isset( $row['remote_mode'] ) ? (string) $row['remote_mode'] : '',
			) ) );
			if ( '' !== $query && false === strpos( $haystack, $query ) ) continue;
			$items[ $operation_id ] = $row;
			if ( count( $items ) >= $limit ) break;
		}
		return array(
			'contract' => self::CONTRACT,
			'query' => $query,
			'count' => count( $items ),
			'operations' => $items,
			'human_decision_operations_excluded' => true,
			'production_mutation_allowed' => false,
			'breakglass_included' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	public static function info( $input ) {
		$operation_id = isset( $input['operation_id'] ) ? (string) $input['operation_id'] : '';
		$current = self::operation( $operation_id );
		if ( is_wp_error( $current ) ) return $current;
		$row = $current['row'];
		$ability = $current['ability'];
		return array(
			'contract' => self::CONTRACT,
			'operation_id' => $row['operation_id'],
			'remote_ability' => $row['remote_ability'],
			'registration_digest' => $row['registration_digest'],
			'dispatch_policy_digest' => $row['dispatch_policy_digest'],
			'input_schema_sha256' => $row['input_schema_sha256'],
			'input_schema' => method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null,
			'operation' => $row,
			'step_up_scope_required' => MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE,
			'human_decision_required' => false,
			'production_mutation_allowed' => false,
			'breakglass_included' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	public static function execute( $input ) {
		$operation_id = isset( $input['operation_id'] ) ? (string) $input['operation_id'] : '';
		$current = self::operation( $operation_id );
		if ( is_wp_error( $current ) ) return $current;
		$row = $current['row'];
		$ability = $current['ability'];

		$expected_registration = strtolower( trim( (string) ( isset( $input['expected_registration_digest'] ) ? $input['expected_registration_digest'] : '' ) ) );
		$expected_policy = strtolower( trim( (string) ( isset( $input['expected_dispatch_policy_digest'] ) ? $input['expected_dispatch_policy_digest'] : '' ) ) );
		$expected_schema = strtolower( trim( (string) ( isset( $input['expected_input_schema_sha256'] ) ? $input['expected_input_schema_sha256'] : '' ) ) );

		if ( ! hash_equals( strtolower( (string) $row['registration_digest'] ), $expected_registration ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_registration_drift', 'Operation registration changed after planning.', array( 'current_registration_digest' => $row['registration_digest'] ) );
		}
		if ( ! hash_equals( strtolower( (string) $row['dispatch_policy_digest'] ), $expected_policy ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_policy_drift', 'Enrollment dispatch policy changed after planning.', array( 'current_dispatch_policy_digest' => $row['dispatch_policy_digest'] ) );
		}
		if ( ! hash_equals( strtolower( (string) $row['input_schema_sha256'] ), $expected_schema ) ) {
			return new WP_Error( 'mad4b_enrollment_dispatch_schema_drift', 'Enrollment target input schema changed after planning.', array( 'current_input_schema_sha256' => $row['input_schema_sha256'] ) );
		}

		$params = array_key_exists( 'input', $input ) ? $input['input'] : null;
		$target_schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
		if ( ( null === $target_schema || empty( $target_schema ) ) && is_array( $params ) && empty( $params ) ) $params = null;

		$result = $ability->execute( $params );
		if ( is_wp_error( $result ) ) return $result;

		$target_reported_mutation = is_array( $result ) && array_key_exists( 'mutation_performed', $result );
		return array(
			'contract' => self::CONTRACT,
			'operation_id' => $row['operation_id'],
			'remote_ability' => $row['remote_ability'],
			'registration_digest' => $row['registration_digest'],
			'dispatch_policy_digest' => $row['dispatch_policy_digest'],
			'input_schema_sha256' => $row['input_schema_sha256'],
			'result' => $result,
			'human_decision_required' => false,
			'production_mutation' => false,
			'breakglass_included' => false,
			'operation_invoked' => true,
			'mutation_performed' => $target_reported_mutation ? (bool) $result['mutation_performed'] : null,
			'mutation_evidence_source' => $target_reported_mutation ? 'target_result' : 'not_reported',
		);
	}
}

