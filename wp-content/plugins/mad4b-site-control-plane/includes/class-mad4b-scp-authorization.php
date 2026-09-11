<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Authorization {
	const TARGET_FINGERPRINT_CONTRACT = 'mad4b.authorization-target.v1';
	const EXECUTION_BOUNDARY_CONTRACT = 'mad4b.approval-execution-boundary.v1';
	const MAX_TARGET_CANONICAL_BYTES = 65536;
	const MAX_TARGET_DEPTH = 8;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap_execution_boundary' ), 190, 2 );
	}

	public static function target_fingerprint( $ability_name, $provider, $input, array $agent = array(), array $identity = array() ) {
		$provider = sanitize_key( (string) $provider );
		if ( '' === $provider ) $provider = 'core';
		$filtered = apply_filters( 'mad4b_scp_authorization_target_fingerprint', '', (string) $ability_name, $provider, $input, $agent, $identity );
		if ( is_string( $filtered ) && '' !== trim( $filtered ) ) return substr( trim( $filtered ), 0, 191 );

		$normalized = self::canonicalize_target_value( $input, 0 );
		if ( is_wp_error( $normalized ) ) return '';
		$payload = array(
			'contract' => self::TARGET_FINGERPRINT_CONTRACT,
			'ability' => (string) $ability_name,
			'provider' => $provider,
			'input' => $normalized,
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json || strlen( $json ) > self::MAX_TARGET_CANONICAL_BYTES ) return '';
		return hash( 'sha256', $json );
	}

	private static function canonicalize_target_value( $value, $depth ) {
		if ( $depth > self::MAX_TARGET_DEPTH ) return new WP_Error( 'mad4b_target_fingerprint_too_deep', 'Mutation target input exceeds the maximum canonical nesting depth.' );
		if ( is_array( $value ) ) {
			$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				$out = array();
				foreach ( $value as $item ) {
					$normalized = self::canonicalize_target_value( $item, $depth + 1 );
					if ( is_wp_error( $normalized ) ) return $normalized;
					$out[] = $normalized;
				}
				return $out;
			}
			$keys = array_keys( $value );
			sort( $keys, SORT_STRING );
			$out = array();
			foreach ( $keys as $key ) {
				if ( ! is_string( $key ) && ! is_int( $key ) ) return new WP_Error( 'mad4b_target_fingerprint_invalid_key', 'Mutation target input contains an unsupported object key.' );
				$normalized = self::canonicalize_target_value( $value[ $key ], $depth + 1 );
				if ( is_wp_error( $normalized ) ) return $normalized;
				$out[ (string) $key ] = $normalized;
			}
			return $out;
		}
		if ( is_string( $value ) || is_int( $value ) || is_bool( $value ) || null === $value ) return $value;
		if ( is_float( $value ) && is_finite( $value ) ) return $value;
		return new WP_Error( 'mad4b_target_fingerprint_invalid_value', 'Mutation target input contains an unsupported value type.' );
	}

	public static function authorize_mutation( $ability_name, $server_id, $provider = 'core', $input = null ) {
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::is_ready() ) return self::error( 'mad4b_governance_schema_unavailable', 'Governance schema is unavailable.' );
		if ( ! class_exists( 'MAD4B_SCP_MCP_Peer_Governance' ) ) return self::error( 'mcp_peer_inventory_unavailable', 'MCP peer governance is unavailable.' );
		$peer_guard = MAD4B_SCP_MCP_Peer_Governance::mutation_guard();
		if ( is_wp_error( $peer_guard ) ) return $peer_guard;

		$declared_server_id = sanitize_key( (string) $server_id );
		if ( ! class_exists( 'MAD4B_SCP_Transport_Context' ) ) return self::error( 'mad4b_transport_context_unavailable', 'MCP transport context is unavailable; governed mutation fails closed.' );
		$resolved_server_id = MAD4B_SCP_Transport_Context::resolve_server_for_ability( $declared_server_id, $ability_name );
		if ( is_wp_error( $resolved_server_id ) ) return $resolved_server_id;
		$server_id = sanitize_key( (string) $resolved_server_id );
		if ( '' === $server_id ) return self::error( 'mad4b_transport_server_unresolved', 'The effective MCP mutation server could not be resolved.' );

		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;
		$provider = sanitize_key( (string) $provider );
		if ( '' === $provider ) $provider = 'core';
		$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], $server_id, $ability_name, $provider );
		if ( is_wp_error( $grant ) ) return $grant;

		$authorization_input = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::authorization_input( $input ) : $input;
		$identity_ticket = isset( $identity['approval_ticket_id'] ) ? strtolower( trim( (string) $identity['approval_ticket_id'] ) ) : '';
		$input_ticket = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::approval_ticket_from_input( $input ) : '';
		if ( '' !== $identity_ticket && '' !== $input_ticket && $identity_ticket !== $input_ticket ) return self::error( 'mad4b_approval_request_binding_conflict', 'Identity and governance input reference different approval tickets.' );
		$approval_ticket_id = '' !== $identity_ticket ? $identity_ticket : $input_ticket;
		$approval_ticket_source = '' !== $identity_ticket ? 'identity' : ( '' !== $input_ticket ? 'governance_input' : 'none' );
		if ( '' !== $approval_ticket_id && ! preg_match( '/^[a-f0-9-]{36}$/', $approval_ticket_id ) ) return self::error( 'mad4b_approval_id_invalid', 'Approval ticket identifier is malformed.' );

		$scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? $identity['token_scopes'] : array();
		$require_scopes = (bool) apply_filters( 'mad4b_scp_require_token_scopes', false, $identity, $agent, $ability_name );
		if ( $require_scopes && empty( $scopes ) ) return self::error( 'mad4b_nhi_scope_required', 'Authenticated subject did not provide required token scopes.' );
		if ( $scopes ) {
			$scope_allowed = in_array( 'ability:' . $ability_name, $scopes, true ) || in_array( 'server:' . $server_id, $scopes, true );
			if ( ! $scope_allowed && class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ) $scope_allowed = MAD4B_SCP_Staging_Write_Authority::remote_scope_delegation_allowed( $identity, $server_id, $ability_name, $input );
			if ( ! $scope_allowed ) return self::error( 'mad4b_nhi_scope_denied', 'Token scope does not include this exact ability/server and no certified one-time Staging write delegation applies.' );
		}

		$constraints = array();
		if ( ! empty( $grant['resource_constraints'] ) ) {
			$decoded = json_decode( $grant['resource_constraints'], true );
			if ( ! is_array( $decoded ) ) return self::error( 'mad4b_nhi_constraints_invalid', 'Stored resource constraints are invalid.' );
			$constraints = $decoded;
		}
		if ( $constraints && ! apply_filters( 'mad4b_scp_resource_constraints_allowed', false, $constraints, $ability_name, $authorization_input, $agent, $identity ) ) return self::error( 'mad4b_nhi_resource_constraints_unresolved', 'Resource constraints are present but no certified evaluator authorized this target.' );

		$impact = class_exists( 'MAD4B_SCP_Impact_Policy' ) ? MAD4B_SCP_Impact_Policy::impact_for( $ability_name, $provider, $authorization_input ) : 'high';
		$approval_required = class_exists( 'MAD4B_SCP_Impact_Policy' ) ? MAD4B_SCP_Impact_Policy::requires_approval( $ability_name, $provider, $authorization_input ) : true;
		$target_fingerprint = self::target_fingerprint( $ability_name, $provider, $authorization_input, $agent, $identity );
		if ( '' === $target_fingerprint ) return self::error( 'mad4b_approval_target_unresolved', 'A deterministic mutation target fingerprint could not be resolved.' );

		$ticket_class = class_exists( 'MAD4B_SCP_Impact_Policy' ) ? MAD4B_SCP_Impact_Policy::ticket_class_for( $ability_name, $provider, $authorization_input ) : 'mutation';
		if ( $approval_required ) {
			if ( '' === $approval_ticket_id ) return self::error( 'mad4b_approval_required', 'This governed mutation requires an exact short-lived one-time approval ticket.' );
			if ( ! class_exists( 'MAD4B_SCP_Approval_Tickets' ) ) return self::error( 'mad4b_approval_service_unavailable', 'Approval service is unavailable.' );
			$approval = MAD4B_SCP_Approval_Tickets::validate_exact( $approval_ticket_id, $agent, $server_id, $ability_name, $provider, $target_fingerprint, $authorization_input, $ticket_class );
			if ( is_wp_error( $approval ) ) return $approval;
		}

		if ( ! class_exists( 'MAD4B_SCP_Budgets' ) ) return self::error( 'mad4b_budget_service_unavailable', 'NHI budget service is unavailable.' );
		$costs = MAD4B_SCP_Budgets::costs_for( $ability_name, $provider, $authorization_input );
		if ( is_wp_error( $costs ) ) return $costs;

		return array(
			'allowed' => true,
			'reason_code' => 'preflight_allowed',
			'agent_id' => (int) $agent['id'],
			'agent_public_id' => (string) $agent['public_id'],
			'subject_type' => isset( $identity['subject_type'] ) ? (string) $identity['subject_type'] : '',
			'subject_fingerprint' => isset( $identity['subject_fingerprint'] ) ? (string) $identity['subject_fingerprint'] : '',
			'request_id' => isset( $identity['request_id'] ) ? (string) $identity['request_id'] : '',
			'server_id' => $server_id,
			'declared_server_id' => $declared_server_id,
			'transport_bound' => $server_id !== $declared_server_id,
			'ability' => (string) $ability_name,
			'provider' => $provider,
			'grant_id' => isset( $grant['id'] ) ? (int) $grant['id'] : 0,
			'scopes_present' => ! empty( $scopes ),
			'constraints' => $constraints,
			'impact' => $impact,
			'approval_required' => $approval_required,
			'approval_ticket_id' => $approval_required ? $approval_ticket_id : '',
			'approval_ticket_source' => $approval_required ? $approval_ticket_source : 'not_required',
			'ticket_class' => $ticket_class,
			'target_fingerprint' => $target_fingerprint,
			'budget_costs' => $costs,
			'execution_side_effects' => false,
		);
	}

	public static function claim_mutation( $ability_name, $server_id, $provider = 'core', $input = null ) {
		$decision = self::authorize_mutation( $ability_name, $server_id, $provider, $input );
		if ( is_wp_error( $decision ) ) {
			self::audit_execution_denial( $ability_name, $decision );
			return $decision;
		}
		$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $decision['agent_public_id'] );
		if ( ! $agent ) return self::deny( 'mad4b_nhi_agent_missing', 'Resolved authorization agent is no longer available.', $ability_name );
		$authorization_input = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::authorization_input( $input ) : $input;

		if ( ! empty( $decision['approval_required'] ) ) {
			$ticket_id = (string) $decision['approval_ticket_id'];
			if ( ! MAD4B_SCP_Identity_Context::bind_approval_ticket_for_request( $ticket_id ) ) return self::deny( 'mad4b_approval_request_binding_conflict', 'A different approval ticket is already bound to this execution request.', $ability_name );
		}

		$budget_reservation = MAD4B_SCP_Budgets::reserve( $agent, $ability_name, $decision['provider'], $authorization_input, ! empty( $decision['approval_required'] ) );
		if ( is_wp_error( $budget_reservation ) ) return self::deny( $budget_reservation->get_error_code(), $budget_reservation->get_error_message(), $ability_name );

		if ( ! empty( $decision['approval_required'] ) ) {
			$claim = MAD4B_SCP_Approval_Tickets::claim_exact( $decision['approval_ticket_id'], $agent, $decision['server_id'], $ability_name, $decision['provider'], $decision['target_fingerprint'], $authorization_input, $decision['ticket_class'] );
			if ( is_wp_error( $claim ) ) {
				MAD4B_SCP_Budgets::rollback( $budget_reservation );
				return self::deny( $claim->get_error_code(), $claim->get_error_message(), $ability_name );
			}
		}

		$budget_commit = MAD4B_SCP_Budgets::commit( $budget_reservation );
		if ( is_wp_error( $budget_commit ) ) return self::deny( $budget_commit->get_error_code(), $budget_commit->get_error_message(), $ability_name );

		$decision['reason_code'] = 'execution_claimed';
		$decision['execution_side_effects'] = true;
		$decision['approval_claimed'] = ! empty( $decision['approval_required'] );
		$decision['budget'] = array(
			'configured' => ! empty( $budget_reservation['active'] ),
			'costs' => isset( $budget_reservation['costs'] ) ? $budget_reservation['costs'] : array(),
			'reservations' => isset( $budget_reservation['reservations'] ) ? $budget_reservation['reservations'] : array(),
		);
		self::audit( $ability_name, $decision, 'allowed' );
		return $decision;
	}

	public static function wrap_execution_boundary( $args, $name ) {
		if ( ! is_array( $args ) || ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) return $args;
		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) return $args;
		if ( empty( $mcp['mad4b_governed_write_authority'] ) || ! empty( $mcp['mad4b_execution_boundary'] ) ) return $args;

		$original = $args['execute_callback'];
		$declared_server = self::declared_server_for_registration( $args );
		$args['execute_callback'] = static function ( $input = null ) use ( $original, $name, $declared_server ) {
			$provider = MAD4B_SCP_Authorization::provider_for_execution( $name, $declared_server );
			if ( is_wp_error( $provider ) ) return $provider;
			$claim = MAD4B_SCP_Authorization::claim_mutation( $name, $declared_server, $provider, $input );
			if ( is_wp_error( $claim ) ) return $claim;
			try {
				$result = call_user_func( $original, $input );
			} catch ( \Throwable $throwable ) {
				MAD4B_SCP_Authorization::finalize_execution_claim( $claim, new WP_Error( 'mad4b_execution_exception', 'Governed mutation threw before a successful verified result.' ) );
				throw $throwable;
			}
			$final = MAD4B_SCP_Authorization::finalize_execution_claim( $claim, $result );
			if ( is_wp_error( $final ) ) return $final;
			return $result;
		};
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
		$args['meta']['mcp']['mad4b_execution_boundary'] = self::EXECUTION_BOUNDARY_CONTRACT;
		return $args;
	}

	public static function finalize_execution_claim( array $claim, $result ) {
		if ( empty( $claim['approval_required'] ) || empty( $claim['approval_ticket_id'] ) ) return true;
		$status = is_wp_error( $result ) ? 'failed' : 'used';
		$final = MAD4B_SCP_Approval_Tickets::finalize_claim( $claim['approval_ticket_id'], $status );
		if ( is_wp_error( $final ) ) {
			self::audit( isset( $claim['ability'] ) ? $claim['ability'] : '', array(
				'allowed' => false,
				'reason_code' => $final->get_error_code(),
				'approval_ticket_id' => isset( $claim['approval_ticket_id'] ) ? $claim['approval_ticket_id'] : '',
				'execution_result' => $status,
			), 'failed' );
			return $final;
		}
		return true;
	}

	private static function declared_server_for_registration( array $args ) {
		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		$surface = isset( $mcp['surface'] ) ? sanitize_key( (string) $mcp['surface'] ) : '';
		if ( in_array( $surface, array( 'content', 'admin', 'breakglass' ), true ) ) return 'mad4b-' . $surface;
		$category = isset( $args['category'] ) ? sanitize_key( (string) $args['category'] ) : '';
		if ( in_array( $category, array( 'mad4b-content', 'mad4b-admin', 'mad4b-breakglass' ), true ) ) return $category;
		return 'mad4b-write';
	}

	public static function provider_for_execution( $ability_name, $declared_server ) {
		if ( ! class_exists( 'MAD4B_SCP_Transport_Context' ) || ! class_exists( 'MAD4B_SCP_Servers' ) ) return self::error( 'mad4b_execution_provider_unavailable', 'Governed execution provider resolution is unavailable.' );
		$resolved = MAD4B_SCP_Transport_Context::resolve_server_for_ability( sanitize_key( (string) $declared_server ), $ability_name );
		if ( is_wp_error( $resolved ) ) return $resolved;
		$provider = MAD4B_SCP_Servers::provider_for_ability( sanitize_key( (string) $resolved ), $ability_name );
		if ( null === $provider && 0 === strpos( (string) $ability_name, 'mad4b/' ) ) $provider = 'core';
		$provider = sanitize_key( (string) $provider );
		return '' !== $provider ? $provider : self::error( 'mad4b_execution_provider_unresolved', 'Governed execution provider could not be resolved for this ability.' );
	}

	public static function authority_status() {
		$schema = class_exists( 'MAD4B_SCP_Schema' ) ? MAD4B_SCP_Schema::status() : array( 'ready' => false );
		$counts = ! empty( $schema['ready'] ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ? MAD4B_SCP_Agent_Registry::counts() : array( 'enabled_agents' => 0, 'enabled_subjects' => 0, 'grants' => 0, 'wildcard_grants' => 0 );
		$mutation_configured = defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true === MAD4B_MCP_MUTATION_ENABLED;
		$mutation_effective = $mutation_configured ? MAD4B_SCP_Policy::can_mutate() : false;
		$peer_governance = class_exists( 'MAD4B_SCP_MCP_Peer_Governance' ) ? MAD4B_SCP_MCP_Peer_Governance::status() : array( 'inventory_ready' => false, 'write_side_channel_detected' => false, 'blockers' => array( 'mcp_peer_inventory_unavailable' ) );
		$staging_write = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::status() : array();
		$blockers = array();
		if ( empty( $schema['ready'] ) ) $blockers[] = 'governance_schema_unavailable';
		if ( ! empty( $counts['wildcard_grants'] ) ) $blockers[] = 'wildcard_grants_detected';
		if ( $mutation_configured && empty( $counts['enabled_agents'] ) ) $blockers[] = 'mutation_enabled_without_nhi';
		if ( empty( $peer_governance['inventory_ready'] ) ) $blockers[] = 'mcp_peer_inventory_unavailable';
		if ( ! empty( $peer_governance['write_side_channel_detected'] ) ) $blockers[] = 'mcp_write_side_channel_detected';
		$blockers = array_values( array_unique( $blockers ) );
		return array(
			'schema_ready' => ! empty( $schema['ready'] ),
			'schema_version' => isset( $schema['installed_version'] ) ? (int) $schema['installed_version'] : 0,
			'mutation_global_enabled' => $mutation_configured,
			'mutation_effective_for_request' => $mutation_effective,
			'nhi_mutation_required' => true,
			'enabled_agents' => (int) $counts['enabled_agents'],
			'enabled_subject_bindings' => (int) $counts['enabled_subjects'],
			'exact_grants' => (int) $counts['grants'],
			'wildcard_grants' => (int) $counts['wildcard_grants'],
			'approval_service_ready' => class_exists( 'MAD4B_SCP_Approval_Tickets' ) && ! empty( $schema['ready'] ),
			'budget_service_ready' => class_exists( 'MAD4B_SCP_Budgets' ) && ! empty( $schema['ready'] ),
			'transport_context' => class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::status() : array( 'bound' => false, 'server_id' => '', 'credential_material_stored' => false ),
			'mcp_peer_governance' => $peer_governance,
			'staging_write_authority' => $staging_write,
			'blockers' => $blockers,
			'status' => $blockers ? 'blocked' : ( $mutation_configured ? ( $mutation_effective ? 'ready_for_governed_mutation' : 'mutation_configured_identity_required' ) : 'ready_read_only' ),
		);
	}

	private static function audit_execution_denial( $ability_name, $error ) {
		if ( ! is_wp_error( $error ) ) return;
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		self::audit( $ability_name, array(
			'allowed' => false,
			'reason_code' => $error->get_error_code(),
			'request_id' => is_array( $identity ) && isset( $identity['request_id'] ) ? (string) $identity['request_id'] : '',
			'approval_ticket_id' => is_array( $identity ) && isset( $identity['approval_ticket_id'] ) && preg_match( '/^[a-f0-9-]{36}$/', (string) $identity['approval_ticket_id'] ) ? strtolower( (string) $identity['approval_ticket_id'] ) : '',
		), 'denied' );
	}

	private static function error( $code, $message ) { return new WP_Error( $code, $message ); }
	private static function deny( $code, $message, $ability_name ) {
		self::audit( $ability_name, array( 'allowed' => false, 'reason_code' => $code ), 'denied' );
		return new WP_Error( $code, $message );
	}
	private static function audit( $ability_name, array $summary, $status ) {
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) MAD4B_SCP_Audit::record( 'mad4b/authorization:' . (string) $ability_name, $summary, $status );
	}
}

if ( function_exists( 'add_filter' ) ) MAD4B_SCP_Authorization::boot();
