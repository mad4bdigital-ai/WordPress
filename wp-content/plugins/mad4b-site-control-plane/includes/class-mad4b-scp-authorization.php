<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Authorization {
	public static function target_fingerprint( $ability_name, $provider, $input, array $agent = array(), array $identity = array() ) {
		return (string) apply_filters( 'mad4b_scp_authorization_target_fingerprint', '', (string) $ability_name, sanitize_key( (string) $provider ), $input, $agent, $identity );
	}

	public static function authorize_mutation( $ability_name, $server_id, $provider = 'core', $input = null ) {
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::is_ready() ) return self::deny( 'mad4b_governance_schema_unavailable', 'Governance schema is unavailable.', $ability_name, array() );
		if ( ! class_exists( 'MAD4B_SCP_MCP_Peer_Governance' ) ) return self::deny( 'mcp_peer_inventory_unavailable', 'MCP peer governance is unavailable.', $ability_name, array() );
		$peer_guard = MAD4B_SCP_MCP_Peer_Governance::mutation_guard();
		if ( is_wp_error( $peer_guard ) ) return self::deny( $peer_guard->get_error_code(), $peer_guard->get_error_message(), $ability_name, array() );

		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return self::deny( $identity->get_error_code(), $identity->get_error_message(), $ability_name, array() );
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return self::deny( $agent->get_error_code(), $agent->get_error_message(), $ability_name, $identity );
		$provider = sanitize_key( (string) $provider );
		if ( '' === $provider ) $provider = 'core';
		$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], $server_id, $ability_name, $provider );
		if ( is_wp_error( $grant ) ) return self::deny( $grant->get_error_code(), $grant->get_error_message(), $ability_name, $identity, $agent );

		$scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? $identity['token_scopes'] : array();
		$require_scopes = (bool) apply_filters( 'mad4b_scp_require_token_scopes', false, $identity, $agent, $ability_name );
		if ( $require_scopes && empty( $scopes ) ) return self::deny( 'mad4b_nhi_scope_required', 'Authenticated subject did not provide required token scopes.', $ability_name, $identity, $agent );
		if ( $scopes && ! in_array( 'ability:' . $ability_name, $scopes, true ) ) return self::deny( 'mad4b_nhi_scope_denied', 'Token scope does not include this exact ability.', $ability_name, $identity, $agent );

		$constraints = array();
		if ( ! empty( $grant['resource_constraints'] ) ) {
			$decoded = json_decode( $grant['resource_constraints'], true );
			if ( ! is_array( $decoded ) ) return self::deny( 'mad4b_nhi_constraints_invalid', 'Stored resource constraints are invalid.', $ability_name, $identity, $agent );
			$constraints = $decoded;
		}
		if ( $constraints && ! apply_filters( 'mad4b_scp_resource_constraints_allowed', false, $constraints, $ability_name, $input, $agent, $identity ) ) {
			return self::deny( 'mad4b_nhi_resource_constraints_unresolved', 'Resource constraints are present but no certified evaluator authorized this target.', $ability_name, $identity, $agent );
		}

		$impact = class_exists( 'MAD4B_SCP_Impact_Policy' ) ? MAD4B_SCP_Impact_Policy::impact_for( $ability_name, $provider, $input ) : 'high';
		$approval_required = class_exists( 'MAD4B_SCP_Impact_Policy' ) ? MAD4B_SCP_Impact_Policy::requires_approval( $ability_name, $provider, $input ) : true;
		$approval_ticket_id = isset( $identity['approval_ticket_id'] ) ? (string) $identity['approval_ticket_id'] : '';
		$target_fingerprint = self::target_fingerprint( $ability_name, $provider, $input, $agent, $identity );

		if ( ! class_exists( 'MAD4B_SCP_Budgets' ) ) return self::deny( 'mad4b_budget_service_unavailable', 'NHI budget service is unavailable.', $ability_name, $identity, $agent );
		$budget_reservation = MAD4B_SCP_Budgets::reserve( $agent, $ability_name, $provider, $input, $approval_required );
		if ( is_wp_error( $budget_reservation ) ) return self::deny( $budget_reservation->get_error_code(), $budget_reservation->get_error_message(), $ability_name, $identity, $agent );

		if ( $approval_required ) {
			if ( '' === $approval_ticket_id ) {
				MAD4B_SCP_Budgets::rollback( $budget_reservation );
				return self::deny( 'mad4b_approval_required', 'This high-impact mutation requires an exact short-lived approval ticket.', $ability_name, $identity, $agent );
			}
			if ( ! class_exists( 'MAD4B_SCP_Approval_Tickets' ) ) {
				MAD4B_SCP_Budgets::rollback( $budget_reservation );
				return self::deny( 'mad4b_approval_service_unavailable', 'Approval service is unavailable.', $ability_name, $identity, $agent );
			}
			$ticket_class = MAD4B_SCP_Impact_Policy::ticket_class_for( $ability_name, $provider, $input );
			$approval = MAD4B_SCP_Approval_Tickets::consume_exact( $approval_ticket_id, $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class );
			if ( is_wp_error( $approval ) ) {
				MAD4B_SCP_Budgets::rollback( $budget_reservation );
				return self::deny( $approval->get_error_code(), $approval->get_error_message(), $ability_name, $identity, $agent );
			}
		}

		$budget_commit = MAD4B_SCP_Budgets::commit( $budget_reservation );
		if ( is_wp_error( $budget_commit ) ) return self::deny( $budget_commit->get_error_code(), $budget_commit->get_error_message(), $ability_name, $identity, $agent );

		$decision = array(
			'allowed' => true,
			'reason_code' => 'allowed',
			'agent_id' => (int) $agent['id'],
			'agent_public_id' => (string) $agent['public_id'],
			'subject_type' => (string) $identity['subject_type'],
			'subject_fingerprint' => (string) $identity['subject_fingerprint'],
			'request_id' => (string) $identity['request_id'],
			'server_id' => sanitize_key( (string) $server_id ),
			'ability' => (string) $ability_name,
			'provider' => $provider,
			'grant_id' => isset( $grant['id'] ) ? (int) $grant['id'] : 0,
			'scopes_present' => ! empty( $scopes ),
			'constraints' => $constraints,
			'impact' => $impact,
			'approval_required' => $approval_required,
			'approval_ticket_id' => $approval_required ? $approval_ticket_id : '',
			'target_fingerprint' => $target_fingerprint,
			'budget' => array(
				'configured' => ! empty( $budget_reservation['active'] ),
				'costs' => isset( $budget_reservation['costs'] ) ? $budget_reservation['costs'] : array(),
				'reservations' => isset( $budget_reservation['reservations'] ) ? $budget_reservation['reservations'] : array(),
			),
		);
		self::audit( $ability_name, $decision, 'allowed' );
		return $decision;
	}

	public static function authority_status() {
		$schema = class_exists( 'MAD4B_SCP_Schema' ) ? MAD4B_SCP_Schema::status() : array( 'ready' => false );
		$counts = ! empty( $schema['ready'] ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ? MAD4B_SCP_Agent_Registry::counts() : array( 'enabled_agents' => 0, 'enabled_subjects' => 0, 'grants' => 0, 'wildcard_grants' => 0 );
		$mutation_configured = defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true === MAD4B_MCP_MUTATION_ENABLED;
		$mutation_effective = $mutation_configured ? MAD4B_SCP_Policy::can_mutate() : false;
		$peer_governance = class_exists( 'MAD4B_SCP_MCP_Peer_Governance' ) ? MAD4B_SCP_MCP_Peer_Governance::status() : array( 'inventory_ready' => false, 'write_side_channel_detected' => false, 'blockers' => array( 'mcp_peer_inventory_unavailable' ) );
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
			'mcp_peer_governance' => $peer_governance,
			'blockers' => $blockers,
			'status' => $blockers ? 'blocked' : ( $mutation_configured ? ( $mutation_effective ? 'ready_for_governed_mutation' : 'mutation_configured_identity_required' ) : 'ready_read_only' ),
		);
	}

	private static function deny( $code, $message, $ability_name, array $identity = array(), array $agent = array() ) {
		self::audit( $ability_name, array(
			'allowed' => false,
			'reason_code' => $code,
			'agent_public_id' => isset( $agent['public_id'] ) ? $agent['public_id'] : '',
			'subject_type' => isset( $identity['subject_type'] ) ? $identity['subject_type'] : '',
			'subject_fingerprint' => isset( $identity['subject_fingerprint'] ) ? substr( $identity['subject_fingerprint'], 0, 16 ) : '',
			'request_id' => isset( $identity['request_id'] ) ? $identity['request_id'] : '',
		), 'denied' );
		return new WP_Error( $code, $message );
	}

	private static function audit( $ability_name, array $summary, $status ) {
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) MAD4B_SCP_Audit::record( 'mad4b/authorization:' . (string) $ability_name, $summary, $status );
	}
}
