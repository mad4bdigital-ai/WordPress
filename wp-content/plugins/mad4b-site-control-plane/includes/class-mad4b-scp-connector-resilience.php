<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared fail-closed resilience policy for MCP/connector-facing callbacks.
 *
 * Design rules:
 * - Read-only work may retry once when the failure is classified transient.
 * - Mutation/enrollment work is never retried automatically.
 * - Exceptions never expose raw messages to remote callers.
 * - Multi-check diagnostics degrade to partial results instead of collapsing
 *   the entire request when one independent check fails.
 * - A request budget may stop launching additional checks, but it never aborts
 *   an in-flight callback or converts a failed check into success.
 * - No persistent circuit breaker/cache is used: authority and provider truth
 *   must be recomputed on the next request.
 */
final class MAD4B_SCP_Connector_Resilience {
	const CONTRACT = 'mad4b.connector-resilience.v2';
	const DEFAULT_READ_ATTEMPTS = 2;
	const DEFAULT_REQUEST_BUDGET_MS = 12000;
	const SESSION_TERMINATION_BUDGET = 2;

	public static function safe_read( $name, $callback, array $options = array() ) {
		$name = sanitize_key( (string) $name );
		$retry_transient = ! array_key_exists( 'retry_transient', $options ) || ! empty( $options['retry_transient'] );
		$max_attempts = isset( $options['max_attempts'] )
			? max( 1, min( self::DEFAULT_READ_ATTEMPTS, absint( $options['max_attempts'] ) ) )
			: self::DEFAULT_READ_ATTEMPTS;
		if ( ! $retry_transient ) $max_attempts = 1;

		$attempt = 0;
		$session_termination_count = 0;
		$started = microtime( true );
		do {
			$attempt++;
			try {
				$value = call_user_func( $callback );
				$elapsed_ms = self::elapsed_ms( $started );
				if ( is_wp_error( $value ) ) {
					$classification = self::classify_wp_error( $value );
					if ( 'session_terminated' === ( isset( $classification['category'] ) ? (string) $classification['category'] : '' ) ) $session_termination_count++;
					if ( $retry_transient
						&& ! empty( $classification['auto_retry'] )
						&& $attempt < $max_attempts ) {
						continue;
					}
					$retry_exhausted = ! empty( $classification['auto_retry'] ) && $attempt >= $max_attempts;
					return array(
						'contract' => self::CONTRACT,
						'ok' => false,
						'state' => 'error',
						'attempts' => $attempt,
						'elapsed_ms' => $elapsed_ms,
						'retryable' => ! empty( $classification['retryable'] ),
						'automatic_retry_allowed' => ! empty( $classification['auto_retry'] ) && ! $retry_exhausted,
						'automatic_retry_performed' => $attempt > 1,
						'automatic_retry_exhausted' => $retry_exhausted,
						'category' => isset( $classification['category'] ) ? (string) $classification['category'] : 'wp_error',
						'client_action' => $retry_exhausted && 'session_terminated' !== ( isset( $classification['category'] ) ? (string) $classification['category'] : '' ) ? 'inspect_then_retry_later' : ( isset( $classification['client_action'] ) ? (string) $classification['client_action'] : 'inspect' ),
						'retry_after_seconds' => self::retry_after_seconds_from_wp_error( $value ),
						'error_code' => self::safe_error_code( $value ),
						'error_fingerprint' => self::wp_error_fingerprint( $name, $value ),
						'raw_error_message_exposed' => false,
						'session_termination_count' => $session_termination_count,
						'read_only' => true,
						'mutation_performed' => false,
					);
				}
				return array(
					'contract' => self::CONTRACT,
					'ok' => true,
					'state' => 'ready',
					'attempts' => $attempt,
					'elapsed_ms' => $elapsed_ms,
					'retryable' => false,
					'automatic_retry_allowed' => false,
					'automatic_retry_performed' => $attempt > 1,
					'automatic_retry_exhausted' => false,
					'category' => 'none',
					'session_termination_count' => $session_termination_count,
					'data' => $value,
					'read_only' => true,
					'mutation_performed' => false,
				);
			} catch ( Throwable $e ) {
				$classification = self::classify_exception( $e );
				if ( 'session_terminated' === ( isset( $classification['category'] ) ? (string) $classification['category'] : '' ) ) $session_termination_count++;
				if ( $retry_transient
					&& ! empty( $classification['auto_retry'] )
					&& $attempt < $max_attempts ) {
					continue;
				}
				$retry_exhausted = ! empty( $classification['auto_retry'] ) && $attempt >= $max_attempts;
				return array(
					'contract' => self::CONTRACT,
					'ok' => false,
					'state' => 'exception',
					'attempts' => $attempt,
					'elapsed_ms' => self::elapsed_ms( $started ),
					'retryable' => ! empty( $classification['retryable'] ),
					'automatic_retry_allowed' => ! empty( $classification['auto_retry'] ) && ! $retry_exhausted,
					'automatic_retry_performed' => $attempt > 1,
					'automatic_retry_exhausted' => $retry_exhausted,
					'category' => isset( $classification['category'] ) ? (string) $classification['category'] : 'unknown',
					'client_action' => $retry_exhausted && 'session_terminated' !== ( isset( $classification['category'] ) ? (string) $classification['category'] : '' ) ? 'inspect_then_retry_later' : ( isset( $classification['client_action'] ) ? (string) $classification['client_action'] : 'inspect' ),
					'error_class' => get_class( $e ),
					'error_fingerprint' => self::exception_fingerprint( $name, $e ),
					'raw_error_message_exposed' => false,
					'session_termination_count' => $session_termination_count,
					'read_only' => true,
					'mutation_performed' => false,
				);
			}
		} while ( $attempt < $max_attempts );

		return array(
			'contract' => self::CONTRACT,
			'ok' => false,
			'state' => 'unreachable',
			'attempts' => $attempt,
			'elapsed_ms' => self::elapsed_ms( $started ),
			'retryable' => false,
			'automatic_retry_allowed' => false,
			'automatic_retry_performed' => $attempt > 1,
			'automatic_retry_exhausted' => false,
			'category' => 'internal',
			'session_termination_count' => $session_termination_count,
			'raw_error_message_exposed' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	public static function generation_fenced_compact_read( $name, $callback, $projector, $max_bytes = 8192 ) {
		$name = sanitize_key( (string) $name );
		$max_bytes = max( 1024, min( 32768, absint( $max_bytes ) ) );
		if ( ! is_callable( $callback ) || ! is_callable( $projector ) ) {
			return new WP_Error( 'mad4b_generation_fenced_read_callback_invalid', 'Generation-fenced compact read requires callable read and projection callbacks.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Read_Consistency' ) || ! method_exists( 'MAD4B_SCP_Read_Consistency', 'snapshot_header' ) ) {
			return new WP_Error( 'mad4b_generation_fenced_read_snapshot_unavailable', 'Runtime generation snapshot service is unavailable.' );
		}

		$before = MAD4B_SCP_Read_Consistency::snapshot_header( array() );
		if ( ! is_array( $before ) || empty( $before['runtime_generation'] ) ) {
			return new WP_Error( 'mad4b_generation_fenced_read_snapshot_incomplete', 'Runtime generation snapshot is incomplete before the read.' );
		}
		$read = self::safe_read( $name, $callback, array(
			'retry_transient' => true,
			'max_attempts' => self::DEFAULT_READ_ATTEMPTS,
		) );
		if ( empty( $read['ok'] ) ) {
			return new WP_Error(
				'mad4b_generation_fenced_read_failed',
				'Generation-fenced read did not complete safely.',
				array(
					'category' => isset( $read['category'] ) ? (string) $read['category'] : 'unknown',
					'source_error_code' => isset( $read['error_code'] ) ? sanitize_key( (string) $read['error_code'] ) : '',
					'client_action' => isset( $read['client_action'] ) ? (string) $read['client_action'] : 'reconnect_then_retry_once',
					'automatic_retry_performed' => ! empty( $read['automatic_retry_performed'] ),
					'automatic_retry_exhausted' => ! empty( $read['automatic_retry_exhausted'] ),
					'mutation_performed' => false,
				)
			);
		}
		$after = MAD4B_SCP_Read_Consistency::snapshot_header( array() );
		if ( ! is_array( $after ) || empty( $after['runtime_generation'] ) ) {
			return new WP_Error( 'mad4b_generation_fenced_read_snapshot_incomplete', 'Runtime generation snapshot is incomplete after the read.' );
		}
		$before_generation = strtolower( (string) $before['runtime_generation'] );
		$after_generation = strtolower( (string) $after['runtime_generation'] );
		if ( ! hash_equals( $before_generation, $after_generation ) ) {
			return new WP_Error(
				'mad4b_generation_fenced_read_generation_changed',
				'Runtime generation changed during the compact read.',
				array(
					'client_action' => 'reconnect_then_restart_read',
					'blind_apply_allowed' => false,
					'mutation_performed' => false,
				)
			);
		}

		$projected = call_user_func( $projector, array_key_exists( 'data', $read ) ? $read['data'] : null, $before, $after );
		if ( is_wp_error( $projected ) ) return $projected;
		if ( ! is_array( $projected ) ) return new WP_Error( 'mad4b_generation_fenced_read_projection_invalid', 'Compact read projection must return an object.' );
		$projected['runtime_generation'] = $after_generation;
		$projected['snapshot_id'] = isset( $after['snapshot_id'] ) ? (string) $after['snapshot_id'] : '';
		$projected['read_attempts'] = isset( $read['attempts'] ) ? (int) $read['attempts'] : 1;
		$projected['automatic_retry_performed'] = ! empty( $read['automatic_retry_performed'] );
		$projected['response_budget_bytes'] = $max_bytes;
		$projected['response_bytes'] = 0;
		$final_bytes = 0;
		// Stabilize the self-reported byte count. Updating the integer itself can
		// change the encoded size at a decimal-width boundary (for example 9999 ->
		// 10000), so compute to a fixed point before enforcing the hard cap.
		for ( $i = 0; $i < 4; $i++ ) {
			$encoded = wp_json_encode( $projected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $encoded ) ) return new WP_Error( 'mad4b_generation_fenced_read_encoding_failed', 'Generation-fenced compact read could not be encoded.' );
			$final_bytes = strlen( $encoded );
			if ( (int) $projected['response_bytes'] === $final_bytes ) break;
			$projected['response_bytes'] = $final_bytes;
		}
		$encoded = wp_json_encode( $projected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) return new WP_Error( 'mad4b_generation_fenced_read_encoding_failed', 'Generation-fenced compact read could not be encoded.' );
		$final_bytes = strlen( $encoded );
		$projected['response_bytes'] = $final_bytes;
		if ( $final_bytes < 1 || $final_bytes > $max_bytes ) {
			return new WP_Error(
				'mad4b_generation_fenced_read_oversized',
				'Generation-fenced compact read exceeded its response budget.',
				array( 'response_bytes' => $final_bytes, 'response_budget_bytes' => $max_bytes )
			);
		}
		return $projected;
	}

	public static function execute_read( $target, $callback ) {
		$target = sanitize_text_field( (string) $target );
		$breaker = class_exists( 'MAD4B_SCP_Provider_Circuit_Breaker' ) ? MAD4B_SCP_Provider_Circuit_Breaker::begin_for_target( 'read', $target ) : array( 'applicable'=>false );
		if ( is_wp_error( $breaker ) ) return $breaker;
		$result = self::safe_read( 'dispatch_' . sanitize_key( $target ), $callback, array(
			'retry_transient' => true,
			'max_attempts' => ! empty( $breaker['half_open_probe'] ) ? 1 : self::DEFAULT_READ_ATTEMPTS,
		) );
		if ( class_exists( 'MAD4B_SCP_Provider_Circuit_Breaker' ) ) {
			MAD4B_SCP_Provider_Circuit_Breaker::record_result( $breaker, ! empty( $result['ok'] ), isset( $result['category'] ) ? (string) $result['category'] : '' );
		}
		if ( ! empty( $result['ok'] ) ) {
			return array(
				'result' => array_key_exists( 'data', $result ) ? $result['data'] : null,
				'attempts' => isset( $result['attempts'] ) ? (int) $result['attempts'] : 1,
				'elapsed_ms' => isset( $result['elapsed_ms'] ) ? (int) $result['elapsed_ms'] : 0,
			);
		}
		return new WP_Error(
			'mad4b_read_dispatch_execution_failed',
			'Governed read execution failed. Inspect structured metadata before retrying.',
			array(
				'target' => $target,
				'retryable' => ! empty( $result['retryable'] ),
				'automatic_retry_allowed' => ! empty( $result['automatic_retry_allowed'] ),
				'automatic_retry_performed' => ! empty( $result['automatic_retry_performed'] ),
				'automatic_retry_exhausted' => ! empty( $result['automatic_retry_exhausted'] ),
				'category' => isset( $result['category'] ) ? (string) $result['category'] : 'unknown',
				'client_action' => isset( $result['client_action'] ) ? (string) $result['client_action'] : 'inspect',
				'retry_after_seconds' => isset( $result['retry_after_seconds'] ) ? (int) $result['retry_after_seconds'] : 0,
				'attempts' => isset( $result['attempts'] ) ? (int) $result['attempts'] : 1,
				'elapsed_ms' => isset( $result['elapsed_ms'] ) ? (int) $result['elapsed_ms'] : 0,
				'error_fingerprint' => isset( $result['error_fingerprint'] ) ? (string) $result['error_fingerprint'] : '',
				'mutation_state' => 'not_applicable_read_only',
				'reconciliation_required' => false,
				'blind_retry_allowed' => ! empty( $result['automatic_retry_allowed'] ),
				'raw_error_message_exposed' => false,
			)
		);
	}

	public static function execute_mutation( $surface, $target, $callback ) {
		$surface = sanitize_key( (string) $surface );
		$target = sanitize_text_field( (string) $target );
		$breaker = class_exists( 'MAD4B_SCP_Provider_Circuit_Breaker' ) ? MAD4B_SCP_Provider_Circuit_Breaker::begin_for_target( $surface, $target ) : array( 'applicable'=>false );
		if ( is_wp_error( $breaker ) ) return $breaker;
		$started = microtime( true );
		try {
			$result = call_user_func( $callback );
			if ( is_wp_error( $result ) ) {
				$classification = self::classify_wp_error( $result );
				$original_error_code = self::safe_error_code( $result );
				$original_data = method_exists( $result, 'get_error_data' ) ? $result->get_error_data( $original_error_code ) : array();
				$mutation_evidence = is_array( $original_data ) && isset( $original_data['mad4b_mutation_evidence'] ) && is_array( $original_data['mad4b_mutation_evidence'] ) ? $original_data['mad4b_mutation_evidence'] : array();
				$postcondition_recovery = isset( $mutation_evidence['postcondition_recovery'] ) && is_array( $mutation_evidence['postcondition_recovery'] ) ? $mutation_evidence['postcondition_recovery'] : array();
				$dispatch_not_started = self::wp_error_proves_mutation_not_started( $original_error_code );
				if ( class_exists( 'MAD4B_SCP_Provider_Circuit_Breaker' ) ) MAD4B_SCP_Provider_Circuit_Breaker::record_result( $breaker, false, isset( $classification['category'] ) ? (string) $classification['category'] : 'unknown' );
				$code_suffix = $dispatch_not_started
					? '_dispatch_not_started'
					: ( ! empty( $classification['retryable'] ) ? '_dispatch_uncertain_remote_error' : '_dispatch_target_error' );
				return new WP_Error(
					'mad4b_' . ( '' !== $surface ? $surface : 'mutation' ) . $code_suffix,
					$dispatch_not_started
						? 'Governed mutation was rejected before the target callback started. Repair the dispatch contract and re-plan before retrying.'
						: 'Governed mutation returned an error after execution may have started. Reconcile observed postconditions before any retry.',
					array(
						'surface' => $surface,
						'target' => $target,
						'original_error_code' => $original_error_code,
						'category' => isset( $classification['category'] ) ? (string) $classification['category'] : 'unknown',
						'client_action' => $dispatch_not_started ? 'repair_dispatch_then_replan' : 'reconcile_then_replan',
						'retryable' => false,
						'attempts' => 1,
						'elapsed_ms' => self::elapsed_ms( $started ),
						'error_fingerprint' => self::wp_error_fingerprint( $surface . '_' . sanitize_key( $target ), $result ),
						'mutation_state' => $dispatch_not_started ? 'not_started' : 'unknown',
						'reconciliation_required' => ! $dispatch_not_started,
						'blind_retry_allowed' => false,
						'postcondition_recovery' => $postcondition_recovery,
						'postcondition_reader_certified' => ! empty( $postcondition_recovery['reader_certified'] ),
						'retry_reclaim_eligible' => ! empty( $postcondition_recovery['retry_reclaim_eligible'] ),
						'automatic_retry_performed' => false,
						'raw_error_message_exposed' => false,
					)
				);
			}
			if ( class_exists( 'MAD4B_SCP_Provider_Circuit_Breaker' ) ) MAD4B_SCP_Provider_Circuit_Breaker::record_result( $breaker, true, '' );
			return array(
				'result' => $result,
				'attempts' => 1,
				'elapsed_ms' => self::elapsed_ms( $started ),
				'automatic_retry_performed' => false,
			);
		} catch ( Throwable $e ) {
			$classification = self::classify_exception( $e );
			if ( class_exists( 'MAD4B_SCP_Provider_Circuit_Breaker' ) ) MAD4B_SCP_Provider_Circuit_Breaker::record_result( $breaker, false, isset( $classification['category'] ) ? (string) $classification['category'] : 'unknown' );
			return new WP_Error(
				'mad4b_' . ( '' !== $surface ? $surface : 'mutation' ) . '_dispatch_execution_exception',
				'Governed mutation execution failed inside the target callback. Reconcile observed postconditions before any retry.',
				array(
					'surface' => $surface,
					'target' => $target,
					'category' => isset( $classification['category'] ) ? (string) $classification['category'] : 'unknown',
					'client_action' => 'reconcile_then_replan',
					'retryable' => false,
					'attempts' => 1,
					'elapsed_ms' => self::elapsed_ms( $started ),
					'error_class' => get_class( $e ),
					'error_fingerprint' => self::exception_fingerprint( $surface . '_' . sanitize_key( $target ), $e ),
					'mutation_state' => 'unknown',
					'reconciliation_required' => true,
					'blind_retry_allowed' => false,
					'automatic_retry_performed' => false,
					'raw_error_message_exposed' => false,
				)
			);
		}
	}

	public static function run_checks( array $checks, array $options = array() ) {
		$budget_ms = isset( $options['budget_ms'] )
			? max( 1000, min( 30000, absint( $options['budget_ms'] ) ) )
			: self::DEFAULT_REQUEST_BUDGET_MS;
		$retry_transient = ! array_key_exists( 'retry_transient', $options ) || ! empty( $options['retry_transient'] );
		$started = microtime( true );
		$results = array();
		$failed = array();
		$retryable = array();
		$skipped = array();
		$skipped_session_breaker = array();
		$session_termination_count = 0;
		$session_breaker_open = false;

		foreach ( $checks as $name => $callback ) {
			$name = sanitize_key( (string) $name );
			if ( $session_breaker_open ) {
				$results[ $name ] = array(
					'contract' => self::CONTRACT,
					'ok' => false,
					'state' => 'skipped_session_breaker',
					'attempts' => 0,
					'elapsed_ms' => 0,
					'retryable' => true,
					'automatic_retry_allowed' => false,
					'automatic_retry_performed' => false,
					'automatic_retry_exhausted' => true,
					'category' => 'session_terminated',
					'client_action' => 'reconnect_snapshot_then_resume',
					'session_termination_count' => $session_termination_count,
					'raw_error_message_exposed' => false,
					'read_only' => true,
					'mutation_performed' => false,
				);
				$failed[] = $name;
				$retryable[] = $name;
				$skipped_session_breaker[] = $name;
				continue;
			}
			if ( self::elapsed_ms( $started ) >= $budget_ms ) {
				$results[ $name ] = array(
					'contract' => self::CONTRACT,
					'ok' => false,
					'state' => 'skipped_budget',
					'attempts' => 0,
					'elapsed_ms' => 0,
					'retryable' => true,
					'automatic_retry_allowed' => false,
					'category' => 'request_budget',
					'client_action' => 'reduce_scope_then_retry_preflight',
					'raw_error_message_exposed' => false,
					'read_only' => true,
					'mutation_performed' => false,
				);
				$failed[] = $name;
				$retryable[] = $name;
				$skipped[] = $name;
				continue;
			}
			$results[ $name ] = self::safe_read( $name, $callback, array(
				'retry_transient' => $retry_transient,
				'max_attempts' => self::DEFAULT_READ_ATTEMPTS,
			) );
			$session_termination_count += isset( $results[ $name ]['session_termination_count'] ) ? max( 0, (int) $results[ $name ]['session_termination_count'] ) : 0;
			if ( $session_termination_count >= self::SESSION_TERMINATION_BUDGET ) $session_breaker_open = true;
			if ( empty( $results[ $name ]['ok'] ) ) {
				$failed[] = $name;
				if ( ! empty( $results[ $name ]['retryable'] ) ) $retryable[] = $name;
			}
		}

		return array(
			'contract' => self::CONTRACT,
			'state' => empty( $failed ) ? 'ready' : 'partial',
			'partial' => ! empty( $failed ),
			'failed_checks' => array_values( array_unique( $failed ) ),
			'retryable_checks' => array_values( array_unique( $retryable ) ),
			'skipped_budget_checks' => array_values( array_unique( $skipped ) ),
			'skipped_session_breaker_checks' => array_values( array_unique( $skipped_session_breaker ) ),
			'session_termination_count' => $session_termination_count,
			'session_breaker_open' => $session_breaker_open,
			'session_termination_budget' => self::SESSION_TERMINATION_BUDGET,
			'budget_ms' => $budget_ms,
			'elapsed_ms' => self::elapsed_ms( $started ),
			'checks' => $results,
			'read_only' => true,
			'mutation_performed' => false,
			'production_mutation_performed' => false,
		);
	}

	public static function client_guidance() {
		return array(
			'contract' => self::CONTRACT,
			'prefer_compact_preflight_before_deep_diagnostics' => true,
			'avoid_large_parallel_fanout' => true,
			'max_recommended_parallel_read_calls' => 2,
			'preferred_parallelism' => 1,
			'read_parallelism_max' => 2,
			'reconnect_attempts' => 1,
			'replay_read_after_reconnect' => true,
			'replay_mutation_after_reconnect' => false,
			'snapshot_identity_required' => true,
			'discard_partial_on_generation_change' => true,
			'resume_completed_reads_on_generation_match' => true,
			'session_termination_budget' => self::SESSION_TERMINATION_BUDGET,
			'stop_fanout_after_session_termination_budget' => true,
			'session_breaker_scope' => 'request_local',
			'session_termination_category' => 'session_terminated',
			'metadata_micro_read_preferred' => true,
			'metadata_envelope_ability' => 'mad4b/read-metadata-envelope',
			'session_safe_diagnostics_ability' => 'mad4b/session-safe-diagnostics',
			'full_staging_authority_handshake_ability' => 'mad4b/full-staging-authority-handshake',
			'full_staging_authority_direct_status_plan_fanout_allowed' => false,
			'full_staging_authority_handshake_response_budget_bytes' => 8192,
			'single_request_composite_diagnostics_preferred' => true,
			'direct_composite_fanout_allowed' => false,
			'session_safe_report_max_bytes' => 16384,
			'resume_after_reconnect_requires_generation_match' => true,
			'supported_read_bundles' => array( 'identity', 'runtime', 'certification', 'providers' ),
			'read_transaction_required_for_bundles' => true,
			'persistent_session_breaker_used' => false,
			'retry_transient_read_once' => true,
			'no_immediate_retry_after_automatic_retry_exhausted' => true,
			'rate_limit_requires_backoff' => true,
			'supported_error_categories' => array( 'runtime_restart', 'runtime_maintenance', 'rate_limit', 'timeout', 'session_terminated', 'transport', 'upstream_unavailable', 'authorization', 'contract_or_validation', 'request_budget', 'internal', 'unknown' ),
			'runtime_restart_honors_retry_after' => true,
			'runtime_restart_immediate_auto_retry_allowed' => false,
			'runtime_maintenance_honors_retry_after' => true,
			'runtime_maintenance_immediate_auto_retry_allowed' => false,
			'default_read_attempt_budget' => self::DEFAULT_READ_ATTEMPTS,
			'default_request_budget_ms' => self::DEFAULT_REQUEST_BUDGET_MS,
			'automatic_write_retry_allowed' => false,
			'automatic_enrollment_retry_allowed' => false,
			'reconcile_before_retry_when_mutation_state_unknown' => true,
			'uncertain_approval_plan_reconciliation_ability' => 'mad4b/approval-plan-reconcile',
			'never_replay_approval_plan_before_reconciliation' => true,
			'persistent_circuit_breaker_used' => false,
			'persistent_catalog_cache_used' => false,
		);
	}

	public static function classify_exception( Throwable $e ) {
		$message = strtolower( (string) $e->getMessage() );
		$class = strtolower( get_class( $e ) );
		if ( self::contains_any( $message, array( 'runtime restart grace', 'post-update restart grace', 'mad4b_mcp_runtime_restart_grace' ) ) ) {
			return array( 'category' => 'runtime_restart', 'retryable' => true, 'auto_retry' => false, 'client_action' => 'retry_after_restart_grace' );
		}
		if ( self::contains_any( $message, array( 'runtime maintenance', 'maintenance is active', 'mad4b_mcp_runtime_maintenance_busy' ) ) ) {
			return array( 'category' => 'runtime_maintenance', 'retryable' => true, 'auto_retry' => false, 'client_action' => 'retry_after_runtime_maintenance' );
		}
		if ( self::contains_any( $message, array( '429', 'rate limit', 'too many requests' ) ) ) {
			return array( 'category' => 'rate_limit', 'retryable' => true, 'auto_retry' => false, 'client_action' => 'backoff_then_retry' );
		}
		if ( self::contains_any( $message, array( 'timeout', 'timed out' ) ) || false !== strpos( $class, 'timeout' ) ) {
			return array( 'category' => 'timeout', 'retryable' => true, 'auto_retry' => true, 'client_action' => 'retry_once' );
		}
		if ( self::contains_any( $message, array( 'session terminated', 'server disconnected', 'broken pipe', 'unexpected eof', 'end of file' ) ) ) {
			return array( 'category' => 'session_terminated', 'retryable' => true, 'auto_retry' => true, 'client_action' => 'reconnect_snapshot_then_resume' );
		}
		if ( self::contains_any( $message, array( 'connection reset', 'transport' ) ) ) {
			return array( 'category' => 'transport', 'retryable' => true, 'auto_retry' => true, 'client_action' => 'reconnect_then_retry_once' );
		}
		if ( self::contains_any( $message, array( '502', '503', '504', 'upstream', 'temporar', 'service unavailable', 'bad gateway', 'gateway timeout' ) ) ) {
			return array( 'category' => 'upstream_unavailable', 'retryable' => true, 'auto_retry' => true, 'client_action' => 'retry_once' );
		}
		if ( self::contains_any( $message, array( 'unauthorized', 'forbidden', 'permission', 'scope', 'approval', 'authority' ) ) ) {
			return array( 'category' => 'authorization', 'retryable' => false, 'auto_retry' => false, 'client_action' => 'repair_authority_or_scope' );
		}
		if ( self::contains_any( $message, array( 'invalid argument', 'validation', 'schema', 'contract', 'not found', 'unknown ability' ) ) ) {
			return array( 'category' => 'contract_or_validation', 'retryable' => false, 'auto_retry' => false, 'client_action' => 'repair_request_or_contract' );
		}
		return array( 'category' => 'unknown', 'retryable' => false, 'auto_retry' => false, 'client_action' => 'inspect_before_retry' );
	}

	private static function classify_wp_error( WP_Error $error ) {
		$code = strtolower( (string) $error->get_error_code() );
		$message = strtolower( (string) $error->get_error_message() );
		$haystack = $code . ' ' . $message;
		if ( 'mad4b_mcp_runtime_restart_grace' === $code || self::contains_any( $haystack, array( 'runtime_restart_grace', 'runtime restart grace', 'post-update restart grace' ) ) ) {
			return array( 'category' => 'runtime_restart', 'retryable' => true, 'auto_retry' => false, 'client_action' => 'retry_after_restart_grace' );
		}
		if ( 'mad4b_mcp_runtime_maintenance_busy' === $code || self::contains_any( $haystack, array( 'runtime_maintenance_busy', 'runtime maintenance', 'maintenance is active' ) ) ) {
			return array( 'category' => 'runtime_maintenance', 'retryable' => true, 'auto_retry' => false, 'client_action' => 'retry_after_runtime_maintenance' );
		}
		if ( self::contains_any( $haystack, array( '429', 'rate_limit', 'rate limit', 'too many requests' ) ) ) {
			return array( 'category' => 'rate_limit', 'retryable' => true, 'auto_retry' => false, 'client_action' => 'backoff_then_retry' );
		}
		if ( self::contains_any( $haystack, array( 'timeout', 'timed out' ) ) ) {
			return array( 'category' => 'timeout', 'retryable' => true, 'auto_retry' => true, 'client_action' => 'retry_once' );
		}
		if ( self::contains_any( $message, array( 'certificate', 'ssl', 'could not resolve host', 'name or service not known', 'dns' ) ) ) {
			return array( 'category' => 'transport', 'retryable' => false, 'auto_retry' => false, 'client_action' => 'repair_connection_configuration' );
		}
		if ( self::contains_any( $haystack, array( 'session terminated', 'server disconnected', 'broken pipe', 'unexpected eof', 'end of file' ) ) ) {
			return array( 'category' => 'session_terminated', 'retryable' => true, 'auto_retry' => true, 'client_action' => 'reconnect_snapshot_then_resume' );
		}
		if ( self::contains_any( $haystack, array( 'transport', 'connection reset', 'http_request_failed' ) ) ) {
			return array( 'category' => 'transport', 'retryable' => true, 'auto_retry' => true, 'client_action' => 'reconnect_then_retry_once' );
		}
		if ( self::contains_any( $haystack, array( '502', '503', '504', 'upstream', 'temporar', 'bad gateway', 'gateway timeout' ) ) ) {
			return array( 'category' => 'upstream_unavailable', 'retryable' => true, 'auto_retry' => true, 'client_action' => 'retry_once' );
		}
		if ( self::contains_any( $haystack, array( 'forbidden', 'permission', 'scope', 'approval', 'authority', 'denied', 'unauthorized' ) ) ) {
			return array( 'category' => 'authorization', 'retryable' => false, 'auto_retry' => false, 'client_action' => 'repair_authority_or_scope' );
		}
		if ( self::contains_any( $haystack, array( 'invalid', 'schema', 'contract', 'required', 'not_found', 'not found', 'unknown' ) ) ) {
			return array( 'category' => 'contract_or_validation', 'retryable' => false, 'auto_retry' => false, 'client_action' => 'repair_request_or_contract' );
		}
		return array( 'category' => 'unknown', 'retryable' => false, 'auto_retry' => false, 'client_action' => 'inspect_before_retry' );
	}

	private static function safe_error_code( WP_Error $error ) {
		$code = sanitize_key( (string) $error->get_error_code() );
		return substr( $code, 0, 96 );
	}

	private static function wp_error_proves_mutation_not_started( $error_code ) {
		$error_code = sanitize_key( (string) $error_code );
		return in_array(
			$error_code,
			array(
				'mad4b_write_dispatch_transport_invalid',
				'mad4b_write_dispatch_callback_invalid',
				'mad4b_write_dispatch_nested_recursion_denied',
				'mad4b_write_dispatch_target_denied',
				'mad4b_write_dispatch_target_not_started',
				'mad4b_write_dispatch_schema_invalid',
				'mad4b_write_dispatch_target_not_runtime_eligible',
				'mad4b_write_dispatch_target_unavailable',
				'mad4b_write_dispatch_contract_unavailable',
				'mad4b_write_dispatch_schema_drift',
			),
			true
		);
	}

	private static function retry_after_seconds_from_wp_error( WP_Error $error ) {
		$data = $error->get_error_data();
		if ( is_array( $data ) ) {
			foreach ( array( 'retry_after', 'retry_after_seconds' ) as $key ) {
				if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) return max( 0, min( 86400, (int) $data[ $key ] ) );
			}
		}
		return 0;
	}

	private static function contains_any( $haystack, array $needles ) {
		foreach ( $needles as $needle ) {
			if ( '' !== (string) $needle && false !== strpos( (string) $haystack, (string) $needle ) ) return true;
		}
		return false;
	}

	private static function exception_fingerprint( $name, Throwable $e ) {
		return hash( 'sha256', sanitize_key( (string) $name ) . "\n" . get_class( $e ) . "\n" . $e->getMessage() );
	}

	private static function wp_error_fingerprint( $name, WP_Error $error ) {
		return hash( 'sha256', sanitize_key( (string) $name ) . "\n" . (string) $error->get_error_code() . "\n" . $error->get_error_message() );
	}

	private static function elapsed_ms( $started ) {
		return max( 0, (int) round( ( microtime( true ) - (float) $started ) * 1000 ) );
	}
}
