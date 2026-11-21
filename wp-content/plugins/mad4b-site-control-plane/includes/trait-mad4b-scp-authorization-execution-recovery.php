<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact authorization execution checkpoint and recovery methods.
 * Isolated from the legacy facade for maintainability; no policy, audit,
 * claim, identity, or mutation behavior is changed by this extraction.
 */
trait MAD4B_SCP_Authorization_Execution_Recovery_Trait {
	public static function execution_checkpoint( array $claim, $point, array $extra = array() ) {
		if ( ! class_exists( 'MAD4B_SCP_Execution_Evidence_Policy' ) ) {
			return new WP_Error( 'mad4b_execution_checkpoint_policy_unavailable', 'Execution checkpoint policy is unavailable.', array(
				'reconciliation_required' => true,
				'blind_retry_allowed' => false,
			) );
		}
		$state = MAD4B_SCP_Execution_Evidence_Policy::crash_point( $point );
		if ( is_wp_error( $state ) ) return $state;
		$attempt = isset( $claim['execution_attempt_sha256'] ) ? strtolower( trim( (string) $claim['execution_attempt_sha256'] ) ) : self::execution_attempt_sha256( $claim );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $attempt ) ) {
			return new WP_Error( 'mad4b_execution_attempt_identity_invalid', 'Execution checkpoint requires a stable attempt identity.', array(
				'reconciliation_required' => true,
				'blind_retry_allowed' => false,
			) );
		}
		$summary = array_merge( array(
			'reason_code' => 'execution_checkpoint',
			'execution_attempt_sha256' => $attempt,
			'crash_point' => (string) $state['crash_point'],
			'recovery_state' => (string) $state['state'],
			'reconciliation_required' => ! empty( $state['reconciliation_required'] ),
			'blind_retry_allowed' => false,
			'client_action' => isset( $state['client_action'] ) ? (string) $state['client_action'] : '',
			'ability' => isset( $claim['ability'] ) ? (string) $claim['ability'] : '',
			'provider' => isset( $claim['provider'] ) ? sanitize_key( (string) $claim['provider'] ) : 'core',
			'request_id' => isset( $claim['request_id'] ) ? substr( (string) $claim['request_id'], 0, 100 ) : '',
			'target_fingerprint' => isset( $claim['target_fingerprint'] ) ? strtolower( trim( (string) $claim['target_fingerprint'] ) ) : '',
			'authorizing' => false,
		), $extra );
		$entry = self::audit( isset( $claim['ability'] ) ? $claim['ability'] : '', $summary, 'execution_checkpoint' );
		if ( is_wp_error( $entry ) ) {
			return new WP_Error( 'mad4b_execution_checkpoint_persist_failed', 'Execution checkpoint could not be durably persisted.', array_merge( $state, array(
				'reason_code' => sanitize_key( (string) $entry->get_error_code() ),
				'execution_attempt_sha256' => $attempt,
				'terminal_success' => false,
			) ) );
		}
		return array(
			'contract' => MAD4B_SCP_Execution_Evidence_Policy::CRASH_CONTRACT,
			'execution_attempt_sha256' => $attempt,
			'crash_point' => (string) $state['crash_point'],
			'state' => (string) $state['state'],
			'audit_event_id' => isset( $entry['event_id'] ) ? (string) $entry['event_id'] : '',
			'audit_entry_hash' => isset( $entry['entry_hash'] ) ? (string) $entry['entry_hash'] : '',
			'authorizing' => false,
		);
	}

	private static function execution_attempt_sha256( array $claim ) {
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

}
