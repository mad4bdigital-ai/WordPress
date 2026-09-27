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
					return array(
						'contract' => self::CONTRACT,
						'ok' => false,
						'state' => 'error',
						'attempts' => $attempt,
						'elapsed_ms' => $elapsed_ms,
						'retryable' => false,
						'category' => self::classify_wp_error( $value ),
						'error_code' => (string) $value->get_error_code(),
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
					'category' => 'none',
					'data' => is_array( $value ) ? $value : array( 'value_type' => gettype( $value ) ),
					'read_only' => true,
					'mutation_performed' => false,
				);
			} catch ( Throwable $e ) {
				$classification = self::classify_exception( $e );
				if ( ! empty( $classification['retryable'] ) && $attempt < $max_attempts ) continue;
				return array(
					'contract' => self::CONTRACT,
					'ok' => false,
					'state' => 'exception',
					'attempts' => $attempt,
					'elapsed_ms' => self::elapsed_ms( $started ),
					'retryable' => ! empty( $classification['retryable'] ),
					'category' => isset( $classification['category'] ) ? (string) $classification['category'] : 'unknown',
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
				'result' => isset( $result['data'] ) ? $result['data'] : array(),
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
				'category' => isset( $result['category'] ) ? (string) $result['category'] : 'unknown',
				'attempts' => isset( $result['attempts'] ) ? (int) $result['attempts'] : 1,
				'elapsed_ms' => isset( $result['elapsed_ms'] ) ? (int) $result['elapsed_ms'] : 0,
				'error_fingerprint' => isset( $result['error_fingerprint'] ) ? (string) $result['error_fingerprint'] : '',
				'mutation_state' => 'not_applicable_read_only',
				'reconciliation_required' => false,
				'blind_retry_allowed' => ! empty( $result['retryable'] ),
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
			if ( is_wp_error( $result ) ) return $result;
			return array(
				'result' => $result,
				'attempts' => 1,
				'elapsed_ms' => self::elapsed_ms( $started ),
				'automatic_retry_performed' => false,
			);
		} catch ( Throwable $e ) {
			$classification = self::classify_exception( $e );
			return new WP_Error(
				'mad4b_' . ( '' !== $surface ? $surface : 'mutation' ) . '_dispatch_execution_exception',
				'Governed mutation execution failed inside the target callback. Reconcile observed postconditions before any retry.',
				array(
					'surface' => $surface,
					'target' => $target,
					'category' => isset( $classification['category'] ) ? (string) $classification['category'] : 'unknown',
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
					'category' => 'request_budget',
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
			'retry_transient_read_once' => true,
			'automatic_write_retry_allowed' => false,
			'automatic_enrollment_retry_allowed' => false,
			'reconcile_before_retry_when_mutation_state_unknown' => true,
			'persistent_circuit_breaker_used' => false,
			'persistent_catalog_cache_used' => false,
		);
	}

	public static function classify_exception( Throwable $e ) {
		$message = strtolower( (string) $e->getMessage() );
		$class = strtolower( get_class( $e ) );
		if ( self::contains_any( $message, array( '429', 'rate limit', 'too many requests' ) ) ) {
			return array( 'category' => 'rate_limit', 'retryable' => true );
		}
		if ( self::contains_any( $message, array( 'timeout', 'timed out' ) ) || false !== strpos( $class, 'timeout' ) ) {
			return array( 'category' => 'timeout', 'retryable' => true );
		}
		if ( self::contains_any( $message, array( 'session terminated', 'connection reset', 'server disconnected', 'transport', 'broken pipe', 'eof' ) ) ) {
			return array( 'category' => 'transport', 'retryable' => true );
		}
		if ( self::contains_any( $message, array( '502', '503', '504', 'upstream', 'temporar', 'service unavailable', 'bad gateway', 'gateway timeout' ) ) ) {
			return array( 'category' => 'upstream_unavailable', 'retryable' => true );
		}
		if ( self::contains_any( $message, array( 'unauthorized', 'forbidden', 'permission', 'scope', 'approval', 'authority' ) ) ) {
			return array( 'category' => 'authorization', 'retryable' => false );
		}
		if ( self::contains_any( $message, array( 'invalid argument', 'validation', 'schema', 'contract', 'not found', 'unknown ability' ) ) ) {
			return array( 'category' => 'contract_or_validation', 'retryable' => false );
		}
		return array( 'category' => 'unknown', 'retryable' => false );
	}

	private static function classify_wp_error( WP_Error $error ) {
		$code = strtolower( (string) $error->get_error_code() );
		if ( self::contains_any( $code, array( 'timeout', 'temporar', 'transport', 'rate_limit', 'upstream' ) ) ) return 'transient_wp_error';
		if ( self::contains_any( $code, array( 'forbidden', 'permission', 'scope', 'approval', 'authority', 'denied' ) ) ) return 'authorization';
		if ( self::contains_any( $code, array( 'invalid', 'schema', 'contract', 'required', 'not_found', 'unavailable' ) ) ) return 'contract_or_validation';
		return 'wp_error';
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
