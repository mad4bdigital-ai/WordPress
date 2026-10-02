<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only normalization over existing execution evidence.
 *
 * This class owns no durable state, authority, retry, claim, lease, journal or
 * provider mutation. It only projects existing Operation Journal, durable
 * idempotency resume, and Connector Resilience evidence into one conservative
 * vocabulary. Unknown source states never become success.
 */
final class MAD4B_SCP_Execution_State_View {
	const CONTRACT = 'mad4b.execution-state-view.v1';

	const PREPARED = 'PREPARED';
	const EXECUTING = 'EXECUTING';
	const COMMITTED = 'COMMITTED';
	const FAILED = 'FAILED';
	const RECONCILING = 'RECONCILING';
	const UNKNOWN = 'UNKNOWN';

	public static function operation( $operation_id ) {
		if ( ! class_exists( 'MAD4B_SCP_Operation_Journal' ) || ! method_exists( 'MAD4B_SCP_Operation_Journal', 'status' ) ) {
			return new WP_Error( 'mad4b_execution_state_journal_unavailable', 'Operation Journal status is unavailable.' );
		}
		$status = MAD4B_SCP_Operation_Journal::status( $operation_id );
		return is_wp_error( $status ) ? $status : self::normalize_journal_status( $status );
	}

	public static function idempotency( array $identity ) {
		if ( ! class_exists( 'MAD4B_SCP_Operation_Resume' ) || ! method_exists( 'MAD4B_SCP_Operation_Resume', 'status' ) ) {
			return new WP_Error( 'mad4b_execution_state_resume_unavailable', 'Durable operation resume status is unavailable.' );
		}
		$status = MAD4B_SCP_Operation_Resume::status( $identity );
		return is_wp_error( $status ) ? $status : self::normalize_resume_status( $status );
	}

	public static function mutation_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return new WP_Error( 'mad4b_execution_state_error_required', 'Mutation error evidence is required.' );
		}
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		return self::normalize_mutation_evidence(
			$data,
			sanitize_key( (string) $error->get_error_code() )
		);
	}

	public static function normalize_journal_status( array $status ) {
		$source_contract = isset( $status['contract'] ) ? (string) $status['contract'] : '';
		if ( 'mad4b.dynamic-operation-status.v1' !== $source_contract ) {
			return new WP_Error( 'mad4b_execution_state_journal_contract_invalid', 'Operation Journal status contract is unsupported.' );
		}

		$lifecycle = isset( $status['lifecycle_state'] ) ? sanitize_key( (string) $status['lifecycle_state'] ) : '';
		$outcome = isset( $status['terminal_outcome'] ) ? sanitize_key( (string) $status['terminal_outcome'] ) : '';
		$orphan = ! empty( $status['orphan_candidate'] );
		$stale_heartbeat = ! empty( $status['stale_heartbeat'] );
		$lock_expired = ! empty( $status['lock_expired'] );
		$hard_deadline_exceeded = ! empty( $status['hard_deadline_exceeded'] );
		$hazard_signal = $stale_heartbeat || $lock_expired || $hard_deadline_exceeded;
		$success_outcomes = array( 'committed', 'completed', 'success', 'succeeded', 'ok' );
		$failure_outcomes = array( 'failed', 'error', 'blocked', 'cancelled', 'canceled', 'rejected', 'aborted' );
		$state = self::UNKNOWN;
		$terminal = false;
		$reconciliation = false;
		$reason = 'unmapped_journal_state';
		$confidence = 'conservative';

		if ( $orphan || $hazard_signal ) {
			$state = self::RECONCILING;
			$reconciliation = true;
			$reason = $orphan ? 'journal_orphan_candidate' : 'journal_hazard_signal_without_orphan';
			$confidence = $orphan ? 'exact' : 'contradictory_evidence';
		} elseif ( in_array( $lifecycle, array( 'planned', 'running' ), true ) && '' !== $outcome ) {
			$state = self::RECONCILING;
			$reconciliation = true;
			$reason = 'journal_nonterminal_with_terminal_outcome';
			$confidence = 'contradictory_evidence';
		} elseif ( 'terminal_failed' === $lifecycle && in_array( $outcome, $success_outcomes, true ) ) {
			$state = self::RECONCILING;
			$reconciliation = true;
			$reason = 'journal_failed_lifecycle_with_success_outcome';
			$confidence = 'contradictory_evidence';
		} elseif ( 'planned' === $lifecycle ) {
			$state = self::PREPARED;
			$reason = 'journal_planned';
			$confidence = 'exact';
		} elseif ( 'running' === $lifecycle ) {
			$state = self::EXECUTING;
			$reason = 'journal_running';
			$confidence = 'exact';
		} elseif ( 'terminal_failed' === $lifecycle ) {
			$state = self::FAILED;
			$terminal = true;
			$reason = 'journal_terminal_failed';
			$confidence = 'exact';
		} elseif ( 'completed' === $lifecycle ) {
			if ( in_array( $outcome, $success_outcomes, true ) ) {
				$state = self::COMMITTED;
				$terminal = true;
				$reason = 'journal_completed_success';
				$confidence = 'exact';
			} elseif ( in_array( $outcome, $failure_outcomes, true ) ) {
				$state = self::FAILED;
				$terminal = true;
				$reason = 'journal_completed_failure';
				$confidence = 'exact';
			} else {
				$reason = '' === $outcome ? 'journal_terminal_outcome_missing' : 'journal_terminal_outcome_unmapped';
			}
		}

		return self::view(
			'operation',
			$source_contract,
			$lifecycle,
			$outcome,
			$state,
			$terminal,
			$reconciliation,
			false,
			$reason,
			$confidence,
			array(
				'operation_id' => isset( $status['operation_id'] ) ? (string) $status['operation_id'] : '',
				'operation_binding_sha256' => isset( $status['operation_binding_sha256'] ) ? (string) $status['operation_binding_sha256'] : '',
				'latest_sequence' => isset( $status['latest_sequence'] ) ? (int) $status['latest_sequence'] : 0,
				'journal_head_sha256' => isset( $status['journal_head_sha256'] ) ? (string) $status['journal_head_sha256'] : '',
				'stale_heartbeat' => $stale_heartbeat,
				'lock_expired' => $lock_expired,
				'hard_deadline_exceeded' => $hard_deadline_exceeded,
				'orphan_candidate' => $orphan,
			)
		);
	}

	public static function normalize_resume_status( array $status ) {
		$source_contract = isset( $status['contract'] ) ? (string) $status['contract'] : '';
		if ( 'mad4b.operation-resume-status.v1' !== $source_contract ) {
			return new WP_Error( 'mad4b_execution_state_resume_contract_invalid', 'Operation Resume status contract is unsupported.' );
		}
		$source_state = isset( $status['status'] ) ? sanitize_key( (string) $status['status'] ) : '';
		$expired = ! empty( $status['expired'] );
		$source_reconciliation = ! empty( $status['reconciliation_required'] );
		$source_retry_allowed = ! empty( $status['retry_allowed'] );
		$result_sha256 = isset( $status['result_sha256'] ) ? strtolower( trim( (string) $status['result_sha256'] ) ) : '';
		$result_digest_valid = 1 === preg_match( '/^[a-f0-9]{64}$/D', $result_sha256 );
		$reconciliation_ref_present = ! empty( $status['reconciliation_ref_present'] );
		$client_action = isset( $status['client_action'] ) ? sanitize_key( (string) $status['client_action'] ) : '';
		$state = self::UNKNOWN;
		$terminal = false;
		$reconciliation = false;
		$retry_after_replan = false;
		$reason = 'unmapped_idempotency_state';
		$confidence = 'conservative';

		if ( in_array( $source_state, array( 'completed', 'released_verified_no_effect' ), true ) && $source_reconciliation ) {
			$state = self::RECONCILING;
			$reconciliation = true;
			$reason = 'idempotency_terminal_state_requires_reconciliation';
			$confidence = 'contradictory_evidence';
		} elseif ( 'completed' === $source_state
			&& $result_digest_valid
			&& ! $source_retry_allowed
			&& 'consume_completed_receipt' === $client_action ) {
			$state = self::COMMITTED;
			$terminal = true;
			$reason = 'idempotency_completed';
			$confidence = 'exact';
		} elseif ( 'completed' === $source_state ) {
			$reconciliation = true;
			$reason = 'idempotency_completed_evidence_incomplete';
			$confidence = 'contradictory_evidence';
		} elseif ( 'released_verified_no_effect' === $source_state
			&& $reconciliation_ref_present
			&& $source_retry_allowed
			&& 'replan_then_retry' === $client_action ) {
			$state = self::PREPARED;
			$retry_after_replan = true;
			$reason = 'idempotency_verified_no_effect_released';
			$confidence = 'exact';
		} elseif ( 'released_verified_no_effect' === $source_state ) {
			$reconciliation = true;
			$reason = 'idempotency_verified_no_effect_evidence_incomplete';
			$confidence = 'contradictory_evidence';
		} elseif ( 'pending' === $source_state && $source_retry_allowed ) {
			$reconciliation = true;
			$reason = 'idempotency_pending_retry_flag_conflict';
			$confidence = 'contradictory_evidence';
		} elseif ( 'pending' === $source_state && ( $expired || $source_reconciliation ) ) {
			if ( 'reconcile_provider_state_before_any_retry' === $client_action ) {
				$state = self::RECONCILING;
				$reconciliation = true;
				$reason = 'idempotency_pending_requires_reconciliation';
				$confidence = 'exact';
			} else {
				$reconciliation = true;
				$reason = 'idempotency_reconciliation_action_mismatch';
				$confidence = 'contradictory_evidence';
			}
		} elseif ( 'pending' === $source_state && 'wait_or_reconnect_without_replay' === $client_action ) {
			$state = self::EXECUTING;
			$reason = 'idempotency_pending_in_flight';
			$confidence = 'durable_pending';
		} elseif ( 'pending' === $source_state ) {
			$reconciliation = true;
			$reason = 'idempotency_pending_action_mismatch';
			$confidence = 'contradictory_evidence';
		}

		return self::view(
			'durable_idempotency',
			$source_contract,
			$source_state,
			'',
			$state,
			$terminal,
			$reconciliation,
			$retry_after_replan,
			$reason,
			$confidence,
			array(
				'claim_epoch' => isset( $status['claim_epoch'] ) ? (int) $status['claim_epoch'] : 0,
				'expired' => $expired,
				'result_sha256' => $result_sha256,
				'reconciliation_ref_present' => $reconciliation_ref_present,
				'source_retry_allowed' => $source_retry_allowed,
				'client_action' => $client_action,
				'execution_started_known' => false,
			)
		);
	}

	public static function normalize_mutation_evidence( array $data, $error_code = '' ) {
		$mutation_state = isset( $data['mutation_state'] ) ? sanitize_key( (string) $data['mutation_state'] ) : '';
		$reconciliation = ! empty( $data['reconciliation_required'] );
		$target_execution_known = array_key_exists( 'target_execution_entered', $data );
		$target_execution_entered = $target_execution_known ? (bool) $data['target_execution_entered'] : null;
		$state = self::UNKNOWN;
		$terminal = false;
		$reason = 'unmapped_mutation_error';
		$confidence = 'conservative';

		if ( $reconciliation || in_array( $mutation_state, array( 'unknown', 'unconfirmed_pending_ticket' ), true ) ) {
			$state = self::RECONCILING;
			$reconciliation = true;
			$reason = 'mutation_outcome_requires_reconciliation';
			$confidence = 'exact';
		} elseif ( 'not_started' === $mutation_state && $target_execution_known && false === $target_execution_entered ) {
			$state = self::FAILED;
			$terminal = true;
			$reason = 'mutation_attempt_not_started';
			$confidence = 'exact';
		} elseif ( 'not_started' === $mutation_state ) {
			$state = self::RECONCILING;
			$reconciliation = true;
			$reason = $target_execution_known
				? 'mutation_not_started_conflicts_with_execution_entry'
				: 'mutation_not_started_without_execution_entry_evidence';
			$confidence = 'contradictory_evidence';
		}

		return self::view(
			'attempt',
			'mad4b.connector-mutation-error-evidence.v1',
			$mutation_state,
			'',
			$state,
			$terminal,
			$reconciliation,
			false,
			$reason,
			$confidence,
			array(
				'error_code' => sanitize_key( (string) $error_code ),
				'target_execution_entered' => $target_execution_entered,
				'target_execution_entered_known' => $target_execution_known,
				'fresh_plan_required' => ! empty( $data['fresh_plan_required'] ),
				'client_action' => isset( $data['client_action'] ) ? sanitize_key( (string) $data['client_action'] ) : '',
			)
		);
	}

	private static function view( $scope, $source_contract, $source_state, $source_outcome, $state, $terminal, $reconciliation, $retry_after_replan, $reason, $confidence, array $evidence ) {
		// Unknown execution evidence is never equivalent to safe/no-effect.
		// Independent reconciliation remains mandatory before any write retry.
		if ( self::UNKNOWN === $state ) $reconciliation = true;
		return array(
			'contract' => self::CONTRACT,
			'scope' => sanitize_key( (string) $scope ),
			'canonical_state' => (string) $state,
			'terminal' => (bool) $terminal,
			'reconciliation_required' => (bool) $reconciliation,
			'blind_retry_allowed' => false,
			'retry_after_replan_allowed' => (bool) $retry_after_replan,
			'source_contract' => (string) $source_contract,
			'source_state' => sanitize_key( (string) $source_state ),
			'source_outcome' => sanitize_key( (string) $source_outcome ),
			'reason' => sanitize_key( (string) $reason ),
			'confidence' => sanitize_key( (string) $confidence ),
			'unmapped_source_state' => self::UNKNOWN === $state,
			'evidence' => $evidence,
			'read_only' => true,
			'mutation_performed' => false,
			'authority_created' => false,
		);
	}
}
