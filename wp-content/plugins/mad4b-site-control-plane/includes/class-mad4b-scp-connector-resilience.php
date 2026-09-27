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
	const CONTRACT = 'mad4b.connector-resilience.v1';
	const DEFAULT_READ_ATTEMPTS = 2;
	const DEFAULT_REQUEST_BUDGET_MS = 12000;

	public static function safe_read( $name, $callback, array $options = array() ) {
		$name = sanitize_key( (string) $name );
		$retry_transient = ! array_key_exists( 'retry_transient', $options ) || ! empty( $options['retry_transient'] );
		$max_attempts = isset( $options['max_attempts'] )
			? max( 1, min( self::DEFAULT_READ_ATTEMPTS, absint( $options['max_attempts'] ) ) )
			: self::DEFAULT_READ_ATTEMPTS;
		if ( ! $retry_transient ) $max_attempts = 1;

		$attempt = 0;
		$started = microtime( true );
		do {
			$attempt++;
			try {
				$value = call_user_func( $callback );
				$elapsed_ms = self::elapsed_ms( $started );
				if ( is_wp_error( $value ) ) {
					$classification = self::classify_wp_error( $value );
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
						'client_action' => $retry_exhausted ? 'inspect_then_retry_later' : ( isset( $classification['client_action'] ) ? (string) $classification['client_action'] : 'inspect' ),
						'retry_after_seconds' => self::retry_after_seconds_from_wp_error( $value ),
						'error_code' => self::safe_error_code( $value ),
						'error_fingerprint' => self::wp_error_fingerprint( $name, $value ),
						'raw_error_message_exposed' => false,
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
					'data' => $value,
					'read_only' => true,
					'mutation_performed' => false,
				);
			} catch ( Throwable $e ) {
				$classification = self::classify_exception( $e );
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
					'client_action' => $retry_exhausted ? 'inspect_then_retry_later' : ( isset( $classification['client_action'] ) ? (string) $classification['client_action'] : 'inspect' ),
					'error_class' => get_class( $e ),
					'error_fingerprint' => self::exception_fingerprint( $name, $e ),
					'raw_error_message_exposed' => false,
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
			'raw_error_message_exposed' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	public static function execute_read( $target, $callback ) {
		$target = sanitize_text_field( (string) $target );
		$result = self::safe_read( 'dispatch_' . sanitize_key( $target ), $callback, array(
			'retry_transient' => true,
			'max_attempts' => self::DEFAULT_READ_ATTEMPTS,
		) );
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
		$started = microtime( true );
		try {
			$result = call_user_func( $callback );
			if ( is_wp_error( $result ) ) {
				$classification = self::classify_wp_error( $result );
				$original_error_code = self::safe_error_code( $result );
				$error_fingerprint = self::wp_error_fingerprint( $surface . '_' . sanitize_key( $target ), $result );
				$elapsed_ms = self::elapsed_ms( $started );
				self::audit_mutation_dispatch_failure(
					$surface,
					$target,
					$classification,
					$original_error_code,
					$error_fingerprint,
					$elapsed_ms,
					'target_error'
				);
				$code_suffix = ! empty( $classification['retryable'] ) ? '_dispatch_uncertain_remote_error' : '_dispatch_target_error';
				return new WP_Error(
					'mad4b_' . ( '' !== $surface ? $surface : 'mutation' ) . $code_suffix,
					'Governed mutation returned an error after execution may have started. Reconcile observed postconditions before any retry.',
					array(
						'surface' => $surface,
						'target' => $target,
						'original_error_code' => $original_error_code,
						'category' => isset( $classification['category'] ) ? (string) $classification['category'] : 'unknown',
						'client_action' => 'reconcile_then_replan',
						'retryable' => false,
						'attempts' => 1,
						'elapsed_ms' => $elapsed_ms,
						'error_fingerprint' => $error_fingerprint,
						'mutation_state' => 'unknown',
						'reconciliation_required' => true,
						'blind_retry_allowed' => false,
						'automatic_retry_performed' => false,
						'raw_error_message_exposed' => false,
					)
				);
			}
			return array(
				'result' => $result,
				'attempts' => 1,
				'elapsed_ms' => self::elapsed_ms( $started ),
				'automatic_retry_performed' => false,
			);
		} catch ( Throwable $e ) {
			$classification = self::classify_exception( $e );
			$error_fingerprint = self::exception_fingerprint( $surface . '_' . sanitize_key( $target ), $e );
			$elapsed_ms = self::elapsed_ms( $started );
			self::audit_mutation_dispatch_failure(
				$surface,
				$target,
				$classification,
				'',
				$error_fingerprint,
				$elapsed_ms,
				'execution_exception',
				get_class( $e )
			);
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
					'elapsed_ms' => $elapsed_ms,
					'error_class' => get_class( $e ),
					'error_fingerprint' => $error_fingerprint,
					'mutation_state' => 'unknown',
					'reconciliation_required' => true,
					'blind_retry_allowed' => false,
					'automatic_retry_performed' => false,
					'raw_error_message_exposed' => false,
				)
			);
		}
	}


	private static function audit_mutation_dispatch_failure( $surface, $target, array $classification, $original_error_code, $error_fingerprint, $elapsed_ms, $failure_kind, $error_class = '' ) {
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) || ! method_exists( 'MAD4B_SCP_Audit', 'record' ) ) return;
		$category = isset( $classification['category'] ) ? sanitize_key( (string) $classification['category'] ) : 'unknown';
		if ( '' === $category ) $category = 'unknown';
		$original_error_code = sanitize_key( (string) $original_error_code );
		$original_error_code = substr( $original_error_code, 0, 96 );
		$error_fingerprint = strtolower( trim( (string) $error_fingerprint ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $error_fingerprint ) ) $error_fingerprint = '';
		$error_class = preg_replace( '/[^A-Za-z0-9_\\\\]/', '', (string) $error_class );
		$error_class = substr( (string) $error_class, 0, 191 );
		$summary = array(
			'contract' => 'mad4b.mutation-dispatch-error.v1',
			'surface' => sanitize_key( (string) $surface ),
			'target' => sanitize_text_field( (string) $target ),
			'failure_kind' => sanitize_key( (string) $failure_kind ),
			'original_error_code' => $original_error_code,
			'category' => $category,
			'error_fingerprint' => $error_fingerprint,
			'error_class' => $error_class,
			'elapsed_ms' => max( 0, (int) $elapsed_ms ),
			'mutation_state' => 'unknown',
			'reconciliation_required' => true,
			'blind_retry_allowed' => false,
			'automatic_retry_performed' => false,
			'raw_error_message_exposed' => false,
		);
		try {
			MAD4B_SCP_Audit::record( 'mad4b/mutation-dispatch-error', $summary, 'failure' );
		} catch ( Throwable $ignored ) {
			// Diagnostic audit failure must never change target mutation semantics.
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

		foreach ( $checks as $name => $callback ) {
			$name = sanitize_key( (string) $name );
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
			'session_termination_budget' => 2,
			'stop_fanout_after_session_termination_budget' => true,
			'supported_read_bundles' => array( 'identity', 'runtime', 'certification', 'providers' ),
			'read_transaction_required_for_bundles' => true,
			'persistent_session_breaker_used' => false,
			'retry_transient_read_once' => true,
			'no_immediate_retry_after_automatic_retry_exhausted' => true,
			'rate_limit_requires_backoff' => true,
			'supported_error_categories' => array( 'rate_limit', 'timeout', 'transport', 'upstream_unavailable', 'authorization', 'contract_or_validation', 'request_budget', 'internal', 'unknown' ),
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
		if ( self::contains_any( $message, array( '429', 'rate limit', 'too many requests' ) ) ) {
			return array( 'category' => 'rate_limit', 'retryable' => true, 'auto_retry' => false, 'client_action' => 'backoff_then_retry' );
		}
		if ( self::contains_any( $message, array( 'timeout', 'timed out' ) ) || false !== strpos( $class, 'timeout' ) ) {
			return array( 'category' => 'timeout', 'retryable' => true, 'auto_retry' => true, 'client_action' => 'retry_once' );
		}
		if ( self::contains_any( $message, array( 'session terminated', 'connection reset', 'server disconnected', 'transport', 'broken pipe', 'eof' ) ) ) {
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
		if ( self::contains_any( $haystack, array( '429', 'rate_limit', 'rate limit', 'too many requests' ) ) ) {
			return array( 'category' => 'rate_limit', 'retryable' => true, 'auto_retry' => false, 'client_action' => 'backoff_then_retry' );
		}
		if ( self::contains_any( $haystack, array( 'timeout', 'timed out' ) ) ) {
			return array( 'category' => 'timeout', 'retryable' => true, 'auto_retry' => true, 'client_action' => 'retry_once' );
		}
		if ( self::contains_any( $message, array( 'certificate', 'ssl', 'could not resolve host', 'name or service not known', 'dns' ) ) ) {
			return array( 'category' => 'transport', 'retryable' => false, 'auto_retry' => false, 'client_action' => 'repair_connection_configuration' );
		}
		if ( self::contains_any( $haystack, array( 'transport', 'connection reset', 'session terminated', 'server disconnected', 'http_request_failed' ) ) ) {
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
