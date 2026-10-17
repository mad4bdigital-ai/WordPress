<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only global health; never substitutes for per-ability admission. */
final class MAD4B_SCP_Authority_Health_Projection {
	public static function authority_status() {
		$schema = class_exists( 'MAD4B_SCP_Schema' ) ? MAD4B_SCP_Schema::status() : array( 'ready' => false );
		$counts = ! empty( $schema['ready'] ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ? MAD4B_SCP_Agent_Registry::counts() : array( 'enabled_agents' => 0, 'enabled_subjects' => 0, 'grants' => 0, 'wildcard_grants' => 0 );
		$mutation_gate = class_exists( 'MAD4B_SCP_Policy' ) && method_exists( 'MAD4B_SCP_Policy', 'mutation_gate_status' )
			? MAD4B_SCP_Policy::mutation_gate_status()
			: array( 'effective' => false, 'source' => 'unavailable' );
		$mutation_configured = ! empty( $mutation_gate['effective'] );
		$mutation_effective = $mutation_configured && MAD4B_SCP_Policy::can_mutate();
		$peer_governance = class_exists( 'MAD4B_SCP_MCP_Peer_Governance' ) ? MAD4B_SCP_MCP_Peer_Governance::status() : array( 'inventory_ready' => false, 'write_side_channel_detected' => false, 'blockers' => array( 'mcp_peer_inventory_unavailable' ) );
		$staging_write = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::status() : array();
		$blockers = array();
		if ( empty( $schema['ready'] ) ) $blockers[] = 'governance_schema_unavailable';
		if ( ! empty( $counts['wildcard_grants'] ) ) $blockers[] = 'wildcard_grants_detected';
		if ( $mutation_configured && empty( $counts['enabled_agents'] ) ) $blockers[] = 'mutation_enabled_without_nhi';
		if ( empty( $peer_governance['inventory_ready'] ) ) $blockers[] = 'mcp_peer_inventory_unavailable';
		if ( ! empty( $peer_governance['write_side_channel_detected'] ) ) $blockers[] = 'mcp_write_side_channel_detected';
		if ( ! empty( $peer_governance['foreign_transport_unreviewed'] ) ) $blockers[] = 'mcp_foreign_transport_unreviewed';
		$blockers = array_values( array_unique( $blockers ) );
		return array(
			// This endpoint is a global governance/peer-health projection only. It is
			// intentionally cheaper than per-ability mutation admission. Every actual
			// mutation still resolves identity, exact grant, current write-authority
			// drift, approvals, budgets and policy inside authorize_mutation().
			'status_scope' => 'global_policy_and_peer_governance_only',
			'per_ability_authorization_required' => true,
			'current_write_authority_evaluated' => false,
			'current_write_authority_evaluation' => 'deferred_to_authorize_mutation',
			'legacy_status_ready_semantics' => 'global_gate_ready_not_execution_authorized',
			'schema_ready' => ! empty( $schema['ready'] ),
			'schema_version' => isset( $schema['installed_version'] ) ? (int) $schema['installed_version'] : 0,
			'mutation_global_enabled' => $mutation_configured,
			'mutation_gate_source' => isset( $mutation_gate['source'] ) ? (string) $mutation_gate['source'] : 'unknown',
			'mutation_explicit_kill_switch' => ! empty( $mutation_gate['explicit_kill_switch'] ),
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
			'staging_write_authority_projection' => 'checkpoint_diagnostic_not_per_ability_execution_verdict',
			'blockers' => $blockers,
			'status' => $blockers ? 'blocked' : ( $mutation_configured ? ( $mutation_effective ? 'ready_for_governed_mutation' : 'mutation_configured_identity_required' ) : 'ready_read_only' ),
		);
	}

}
