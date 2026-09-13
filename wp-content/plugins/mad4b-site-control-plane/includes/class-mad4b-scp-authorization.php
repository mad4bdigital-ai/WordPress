<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Authorization {
	const EXECUTION_BOUNDARY_CONTRACT = 'mad4b.authorization-execution-boundary.v1';
	const PERMISSION_DENIAL_AUDIT_CONTRACT = 'mad4b.authorization-permission-denial-audit.v1';
	const TARGET_FINGERPRINT_CONTRACT = 'mad4b.authorization-target.v1';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_filter' ) ) return;
		self::$booted = true;
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap_execution_boundary' ), 90, 2 );
	}

	public static function authorize_mutation( $ability_name, $server_id, $provider = 'core', $input = null ) {
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array( 'authenticated' => false );
		if ( empty( $identity['authenticated'] ) ) return self::deny( 'mad4b_identity_required', 'Governed mutation requires an authenticated identity.', $ability_name );
		if ( ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return self::deny( 'mad4b_agent_registry_unavailable', 'MAD4B agent governance is unavailable.', $ability_name );
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return self::deny( $agent->get_error_code(), $agent->get_error_message(), $ability_name );
		if ( 'enabled' !== $agent['status'] ) return self::deny( 'mad4b_agent_disabled', 'Resolved agent is disabled.', $ability_name );

		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
		if ( ! in_array( $agent['environment'], array( 'all', 'unknown', $environment ), true ) ) return self::deny( 'mad4b_agent_environment_denied', 'Agent is not allowed in this environment.', $ability_name );

		if ( ! MAD4B_SCP_Policy::can_mutate() ) return self::deny( 'mad4b_mutation_disabled', 'Mutation capability is disabled by MAD4B policy.', $ability_name );
		if ( 'mad4b-breakglass' === $server_id && ! MAD4B_SCP_Policy::can_breakglass() ) return self::deny( 'mad4b_breakglass_disabled', 'Breakglass capability is disabled.', $ability_name );

		$grant = MAD4B_SCP_Agent_Registry::authorize( $agent, $server_id, $ability_name, $provider, $input );
		if ( is_wp_error( $grant ) ) return self::deny( $grant->get_error_code(), $grant->get_error_message(), $ability_name );

		$impact = class_exists( 'MAD4B_SCP_Impact_Policy' ) ? MAD4B_SCP_Impact_Policy::impact_for( $ability_name, $provider, $input ) : 'high';
		$approval_required = class_exists( 'MAD4B_SCP_Impact_Policy' ) ? MAD4B_SCP_Impact_Policy::requires_approval( $ability_name, $provider, $input ) : true;
		$decision = array(
			'allowed' => true,
			'ability' => $ability_name,
			'server_id' => $server_id,
			'provider' => $provider,
			'impact' => $impact,
			'approval_required' => $approval_required,
			'agent_public_id' => $agent['public_id'],
			'grant_id' => (int) $grant['id'],
			'request_id' => is_array( $identity ) && isset( $identity['request_id'] ) ? (string) $identity['request_id'] : '',
		);

		if ( $approval_required ) {
			if ( ! class_exists( 'MAD4B_SCP_Approval_Tickets' ) ) return self::deny( 'mad4b_approval_unavailable', 'Approval service is unavailable.', $ability_name );
			$ticket_id = is_array( $identity ) && isset( $identity['approval_ticket_id'] ) ? trim( (string) $identity['approval_ticket_id'] ) : '';
			if ( '' === $ticket_id && class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ) $ticket_id = MAD4B_SCP_Staging_Write_Authority::approval_ticket_from_input( $input );
			if ( '' === $ticket_id ) return self::deny( 'mad4b_approval_required', 'Mutation requires a one-time exact approval ticket.', $ability_name );
			$clean_input = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::authorization_input( $input ) : $input;
			$target = self::target_fingerprint( $ability_name, $provider, $clean_input, $agent, $identity );
			$ticket_class = class_exists( 'MAD4B_SCP_Impact_Policy' ) ? MAD4B_SCP_Impact_Policy::ticket_class_for( $ability_name, $provider, $clean_input ) : 'mutation';
			$approved = MAD4B_SCP_Approval_Tickets::authorize_exact( $ticket_id, $agent, $server_id, $ability_name, $provider, $target, $clean_input, $ticket_class );
			if ( is_wp_error( $approved ) ) return self::deny( $approved->get_error_code(), $approved->get_error_message(), $ability_name );
			$decision['approval_ticket_id'] = $ticket_id;
			$decision['target_fingerprint'] = $target;
			$decision['ticket_class'] = $ticket_class;
		}

		self::audit( $ability_name, $decision, 'allowed' );
		return $decision;
	}

	public static function claim_mutation( $ability_name, $server_id, $provider = 'core', $input = null ) {
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array( 'authenticated' => false );
		if ( empty( $identity['authenticated'] ) ) return self::deny( 'mad4b_identity_required', 'Governed mutation requires an authenticated identity.', $ability_name );
		if ( ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return self::deny( 'mad4b_agent_registry_unavailable', 'MAD4B agent governance is unavailable.', $ability_name );
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return self::deny( $agent->get_error_code(), $agent->get_error_message(), $ability_name );
		if ( 'enabled' !== $agent['status'] ) return self::deny( 'mad4b_agent_disabled', 'Resolved agent is disabled.', $ability_name );

		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
		if ( ! in_array( $agent['environment'], array( 'all', 'unknown', $environment ), true ) ) return self::deny( 'mad4b_agent_environment_denied', 'Agent is not allowed in this environment.', $ability_name );
		if ( ! MAD4B_SCP_Policy::can_mutate() ) return self::deny( 'mad4b_mutation_disabled', 'Mutation capability is disabled by MAD4B policy.', $ability_name );
		if ( 'mad4b-breakglass' === $server_id && ! MAD4B_SCP_Policy::can_breakglass() ) return self::deny( 'mad4b_breakglass_disabled', 'Breakglass capability is disabled.', $ability_name );

		$grant = MAD4B_SCP_Agent_Registry::authorize( $agent, $server_id, $ability_name, $provider, $input );
		if ( is_wp_error( $grant ) ) return self::deny( $grant->get_error_code(), $grant->get_error_message(), $ability_name );

		$impact = class_exists( 'MAD4B_SCP_Impact_Policy' ) ? MAD4B_SCP_Impact_Policy::impact_for( $ability_name, $provider, $input ) : 'high';
		$approval_required = class_exists( 'MAD4B_SCP_Impact_Policy' ) ? MAD4B_SCP_Impact_Policy::requires_approval( $ability_name, $provider, $input ) : true;
		$decision = array(
			'allowed' => true,
			'ability' => $ability_name,
			'server_id' => $server_id,
			'provider' => $provider,
			'impact' => $impact,
			'approval_required' => $approval_required,
			'agent_public_id' => $agent['public_id'],
			'grant_id' => (int) $grant['id'],
			'approval_ticket_id' => '',
			'request_id' => is_array( $identity ) && isset( $identity['request_id'] ) ? (string) $identity['request_id'] : '',
		);

		$clean_input = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::authorization_input( $input ) : $input;
		$budget_reservation = class_exists( 'MAD4B_SCP_Budgets' ) ? MAD4B_SCP_Budgets::reserve( $agent, $ability_name, $provider, $clean_input ) : array( 'active' => false, 'costs' => array(), 'reservations' => array() );
		if ( is_wp_error( $budget_reservation ) ) return self::deny( $budget_reservation->get_error_code(), $budget_reservation->get_error_message(), $ability_name );

		if ( $approval_required ) {
			if ( ! class_exists( 'MAD4B_SCP_Approval_Tickets' ) ) { if ( class_exists( 'MAD4B_SCP_Budgets' ) ) MAD4B_SCP_Budgets::rollback( $budget_reservation ); return self::deny( 'mad4b_approval_unavailable', 'Approval service is unavailable.', $ability_name ); }
			$ticket_id = is_array( $identity ) && isset( $identity['approval_ticket_id'] ) ? trim( (string) $identity['approval_ticket_id'] ) : '';
			if ( '' === $ticket_id && class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ) $ticket_id = MAD4B_SCP_Staging_Write_Authority::approval_ticket_from_input( $input );
			if ( '' === $ticket_id ) { if ( class_exists( 'MAD4B_SCP_Budgets' ) ) MAD4B_SCP_Budgets::rollback( $budget_reservation ); return self::deny( 'mad4b_approval_required', 'Mutation requires a one-time exact approval ticket.', $ability_name ); }
			$target = self::target_fingerprint( $ability_name, $provider, $clean_input, $agent, $identity );
			$ticket_class = class_exists( 'MAD4B_SCP_Impact_Policy' ) ? MAD4B_SCP_Impact_Policy::ticket_class_for( $ability_name, $provider, $clean_input ) : 'mutation';
			$claim = MAD4B_SCP_Approval_Tickets::claim_exact( $ticket_id, $agent, $server_id, $ability_name, $provider, $target, $clean_input, $ticket_class );
			if ( is_wp_error( $claim ) ) {
				if ( class_exists( 'MAD4B_SCP_Budgets' ) ) MAD4B_SCP_Budgets::rollback( $budget_reservation );
				if ( 'mad4b_approval_replay_denied' === (string) $claim->get_error_code() ) {
					self::audit_execution_denial( $ability_name, $claim, $input );
					return $claim;
				}
				return self::deny( $claim->get_error_code(), $claim->get_error_message(), $ability_name );
			}
			$decision['approval_ticket_id'] = $ticket_id;
			$decision['target_fingerprint'] = $target;
			$decision['ticket_class'] = $ticket_class;
		}

		$budget_commit = class_exists( 'MAD4B_SCP_Budgets' ) ? MAD4B_SCP_Budgets::commit( $budget_reservation ) : true;
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

		if ( isset( $args['permission_callback'] ) && is_callable( $args['permission_callback'] ) && empty( $mcp['mad4b_permission_denial_audit'] ) ) {
			$permission = $args['permission_callback'];
			$args['permission_callback'] = static function ( $input = null ) use ( $permission, $name ) {
				$result = call_user_func( $permission, $input );
				if ( is_wp_error( $result ) ) MAD4B_SCP_Authorization::audit_remote_permission_denial( $name, $result, $input );
				return $result;
			};
			if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
			$args['meta']['mcp']['mad4b_permission_denial_audit'] = self::PERMISSION_DENIAL_AUDIT_CONTRACT;
		}

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
		if ( in_array( $surface, array( 'content', 'write', 'admin', 'breakglass' ), true ) ) return 'mad4b-' . $surface;
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

	public static function target_fingerprint( $ability_name, $provider, $input, $agent = array(), $identity = array() ) {
		$target = array(
			'contract' => self::TARGET_FINGERPRINT_CONTRACT,
			'ability' => (string) $ability_name,
			'provider' => sanitize_key( (string) $provider ),
			'input' => self::canonical_target_value( is_array( $input ) ? $input : array() ),
		);
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $target );
		$fallback = hash( 'sha256', is_string( $json ) ? $json : serialize( $target ) );
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'mad4b_scp_authorization_target_fingerprint', $fallback, $ability_name, $provider, $input, $agent, $identity ) : $fallback;
		$filtered = strtolower( trim( (string) $filtered ) );
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $filtered ) ? $filtered : $fallback;
	}

	private static function canonical_target_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		$out = array();
		if ( $is_list ) {
			foreach ( $value as $item ) $out[] = self::canonical_target_value( $item );
			return $out;
		}
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		foreach ( $keys as $key ) $out[ (string) $key ] = self::canonical_target_value( $value[ $key ] );
		return $out;
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

	public static function audit_remote_permission_denial( $ability_name, $error, $input = null ) {
		if ( ! is_wp_error( $error ) || 'mad4b_approval_replay_denied' !== (string) $error->get_error_code() ) return false;
		$server_id = class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::current_server_id() : '';
		if ( ! in_array( $server_id, array( 'mad4b-chatgpt', 'mad4b-write' ), true ) ) return false;
		self::audit_execution_denial( $ability_name, $error, $input );
		return true;
	}

	private static function audit_execution_denial( $ability_name, $error, $input = null ) {
		if ( ! is_wp_error( $error ) ) return;
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		$ticket_id = is_array( $identity ) && isset( $identity['approval_ticket_id'] ) && preg_match( '/^[a-f0-9-]{36}$/', (string) $identity['approval_ticket_id'] )
			? strtolower( (string) $identity['approval_ticket_id'] )
			: '';
		if ( '' === $ticket_id
			&& 'mad4b_approval_replay_denied' === (string) $error->get_error_code()
			&& class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ) {
			$input_ticket = strtolower( trim( (string) MAD4B_SCP_Staging_Write_Authority::approval_ticket_from_input( $input ) ) );
			if ( 1 === preg_match( '/^[a-f0-9-]{36}$/', $input_ticket ) ) $ticket_id = $input_ticket;
		}

		self::audit( $ability_name, array(
			'allowed' => false,
			'reason_code' => $error->get_error_code(),
			'request_id' => is_array( $identity ) && isset( $identity['request_id'] ) ? (string) $identity['request_id'] : '',
			'approval_ticket_id' => $ticket_id,
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
