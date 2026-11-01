<?php
/**
 * Non-authorizing execution recovery and server classification projections.
 * Original immutable checkpoint rules and approval planning are unchanged.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Execution_Recovery_Projection {
	public static function execution_attempt_sha256( array $claim ) {
		$material = array(
			'contract' => 'mad4b.execution-attempt-identity.v1',
			'ability' => isset( $claim['ability'] ) ? (string) $claim['ability'] : '',
			'provider' => isset( $claim['provider'] ) ? sanitize_key( (string) $claim['provider'] ) : 'core',
			'server_id' => isset( $claim['server_id'] ) ? sanitize_key( (string) $claim['server_id'] ) : '',
			'request_id' => isset( $claim['request_id'] ) ? substr( (string) $claim['request_id'], 0, 100 ) : '',
			'approval_ticket_id' => isset( $claim['approval_ticket_id'] ) ? strtolower( trim( (string) $claim['approval_ticket_id'] ) ) : '',
			'target_fingerprint' => isset( $claim['target_fingerprint'] ) ? strtolower( trim( (string) $claim['target_fingerprint'] ) ) : '',
			'policy_decision_sha256' => isset( $claim['policy_decision_sha256'] ) ? strtolower( trim( (string) $claim['policy_decision_sha256'] ) ) : '',
		);
		$json = wp_json_encode( $material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	public static function execution_attempt_state( $execution_attempt_sha256 ) {
		$attempt = strtolower( trim( (string) $execution_attempt_sha256 ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $attempt ) ) {
			return new WP_Error( 'mad4b_execution_attempt_identity_invalid', 'Execution attempt identity is invalid.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) || ! method_exists( 'MAD4B_SCP_Audit', 'execution_checkpoint_events' ) ) {
			return new WP_Error( 'mad4b_execution_attempt_recovery_unavailable', 'Execution checkpoint recovery evidence is unavailable.', array(
				'reconciliation_required' => true,
				'blind_retry_allowed' => false,
			) );
		}
		$lookup = MAD4B_SCP_Audit::execution_checkpoint_events( $attempt, 50 );
		if ( is_wp_error( $lookup ) ) return $lookup;
		$events = isset( $lookup['events'] ) && is_array( $lookup['events'] ) ? $lookup['events'] : array();
		if ( empty( $events ) ) {
			return array(
				'contract' => 'mad4b.execution-attempt-recovery.v1',
				'execution_attempt_sha256' => $attempt,
				'state' => 'UNKNOWN',
				'terminal' => false,
				'reconciliation_required' => true,
				'blind_retry_allowed' => false,
				'client_action' => 'reconcile_execution_state_before_any_retry',
				'evidence_count' => 0,
				'authorizing' => false,
			);
		}
		if ( empty( $lookup['chain_valid'] ) || empty( $lookup['head_consistent'] ) ) {
			return array(
				'contract' => 'mad4b.execution-attempt-recovery.v1',
				'execution_attempt_sha256' => $attempt,
				'state' => 'RECONCILING',
				'terminal' => false,
				'reconciliation_required' => true,
				'blind_retry_allowed' => false,
				'client_action' => 'repair_and_verify_audit_chain_before_execution_recovery',
				'evidence_count' => count( $events ),
				'chain_valid' => ! empty( $lookup['chain_valid'] ),
				'head_consistent' => ! empty( $lookup['head_consistent'] ),
				'authorizing' => false,
			);
		}
		$last = end( $events );
		$summary = is_array( $last ) && isset( $last['summary'] ) && is_array( $last['summary'] ) ? $last['summary'] : array();
		$point = isset( $summary['crash_point'] ) ? sanitize_key( (string) $summary['crash_point'] ) : '';
		if ( 'unified_execution_receipt_committed' === ( isset( $summary['reason_code'] ) ? sanitize_key( (string) $summary['reason_code'] ) : '' ) ) {
			$point = 'durable_receipt_committed';
		}
		if ( ! class_exists( 'MAD4B_SCP_Execution_Evidence_Policy' ) ) {
			return new WP_Error( 'mad4b_execution_checkpoint_policy_unavailable', 'Execution checkpoint policy is unavailable.' );
		}
		$state = MAD4B_SCP_Execution_Evidence_Policy::crash_point( $point );
		if ( is_wp_error( $state ) ) return $state;
		return array_merge( $state, array(
			'contract' => 'mad4b.execution-attempt-recovery.v1',
			'execution_attempt_sha256' => $attempt,
			'evidence_count' => count( $events ),
			'last_audit_event_id' => isset( $last['event_id'] ) ? (string) $last['event_id'] : '',
			'last_audit_entry_hash' => isset( $last['entry_hash'] ) ? (string) $last['entry_hash'] : '',
			'chain_valid' => ! empty( $lookup['chain_valid'] ),
			'head_consistent' => ! empty( $lookup['head_consistent'] ),
			'authorizing' => false,
		) );
	}

	public static function local_approval_planner_execution( $ability_name ) {
		$planner = class_exists( 'MAD4B_SCP_Staging_Write_Planning_Guard' )
			? MAD4B_SCP_Staging_Write_Planning_Guard::ABILITY
			: 'mad4b/approval-plan';
		if ( $planner !== (string) $ability_name ) return false;
		$current = class_exists( 'MAD4B_SCP_Transport_Context' )
			? sanitize_key( (string) MAD4B_SCP_Transport_Context::current_server_id() )
			: '';
		return ! in_array( $current, array( 'mad4b-chatgpt', 'mad4b-write' ), true );
	}

	public static function declared_server_for_registration( array $args ) {
		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		$surface = isset( $mcp['surface'] ) ? sanitize_key( (string) $mcp['surface'] ) : '';
		if ( in_array( $surface, array( 'content', 'write', 'admin', 'breakglass', 'developer', 'developer-breakglass' ), true ) ) return 'mad4b-' . $surface;
		$category = isset( $args['category'] ) ? sanitize_key( (string) $args['category'] ) : '';
		if ( in_array( $category, array( 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass', 'mad4b-developer', 'mad4b-developer-breakglass' ), true ) ) return $category;
		return 'mad4b-write';
	}
}
