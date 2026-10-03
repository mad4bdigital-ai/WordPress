<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Non-authorizing execution evidence policy.
 *
 * Owns conservative crash-point classification and bounded evidence compaction.
 * It never turns missing evidence into success and never grants retry authority.
 */
final class MAD4B_SCP_Execution_Evidence_Policy {
	const CONTRACT = 'mad4b.execution-evidence-policy.v1';
	const CRASH_CONTRACT = 'mad4b.execution-crash-point.v1';
	const COMPACT_CONTRACT = 'mad4b.audit-evidence-compact.v1';
	const MAX_MANDATORY_PATHS = 128;
	const MAX_PATH_BYTES = 191;
	const MAX_VALUE_BYTES = 500;

	public static function crash_points() {
		return array(
			'intent_persisted' => self::crash_row( 'PREPARED', 'none', false, false, 'replan_then_retry_if_still_authorized' ),
			'approval_claimed' => self::crash_row( 'PREPARED', 'none', false, false, 'reconcile_or_finalize_claim_before_replan' ),
			'before_provider_entry' => self::crash_row( 'PREPARED', 'none', false, false, 'replan_then_retry_if_still_authorized' ),
			'provider_entry_possible' => self::crash_row( 'RECONCILING', 'possible', true, false, 'reconcile_provider_state_before_any_retry' ),
			'provider_returned' => self::crash_row( 'RECONCILING', 'possible', true, false, 'verify_provider_postcondition_and_evidence' ),
			'readback_verified' => self::crash_row( 'COMMITTING', 'verified', false, false, 'persist_required_execution_evidence' ),
			'audit_committed' => self::crash_row( 'COMMITTING', 'verified', false, false, 'persist_durable_receipt' ),
			'durable_receipt_committed' => self::crash_row( 'COMMITTED', 'verified', false, true, 'consume_completed_receipt' ),
		);
	}

	public static function crash_point( $point ) {
		$point = sanitize_key( (string) $point );
		$points = self::crash_points();
		if ( ! isset( $points[ $point ] ) ) {
			return new WP_Error(
				'mad4b_execution_crash_point_unknown',
				'Unknown execution crash point cannot be interpreted as a safe terminal state.',
				array(
					'contract' => self::CRASH_CONTRACT,
					'state' => 'UNKNOWN',
					'terminal' => false,
					'reconciliation_required' => true,
					'blind_retry_allowed' => false,
					'client_action' => 'reconcile_execution_state_before_any_retry',
					'authorizing' => false,
				)
			);
		}
		return array_merge(
			array(
				'contract' => self::CRASH_CONTRACT,
				'crash_point' => $point,
				'blind_retry_allowed' => false,
				'automatic_retry_allowed' => false,
				'authorizing' => false,
			),
			$points[ $point ]
		);
	}

	public static function compact_audit_summary( array $summary, $max_bytes ) {
		$max_bytes = max( 512, (int) $max_bytes );
		$original_json = self::encode( $summary );
		if ( ! is_string( $original_json ) ) {
			return new WP_Error( 'mad4b_execution_evidence_encode_failed', 'Execution evidence could not be encoded.' );
		}
		if ( strlen( $original_json ) <= $max_bytes ) {
			return array(
				'contract' => self::COMPACT_CONTRACT,
				'truncated' => false,
				'summary' => $summary,
				'json' => $original_json,
			);
		}

		$mandatory = array();
		self::collect_mandatory( $summary, '', $mandatory );
		if ( count( $mandatory ) > self::MAX_MANDATORY_PATHS ) {
			return new WP_Error(
				'mad4b_execution_evidence_mandatory_overflow',
				'Execution evidence contains too many mandatory identity/reconciliation fields to compact safely.',
				array( 'mandatory_path_count' => count( $mandatory ), 'max_mandatory_paths' => self::MAX_MANDATORY_PATHS )
			);
		}
		ksort( $mandatory, SORT_STRING );

		$compact = array(
			'contract' => self::COMPACT_CONTRACT,
			'_truncated' => true,
			'_original_bytes' => strlen( $original_json ),
			'_sha256' => hash( 'sha256', $original_json ),
			'_mandatory_paths' => $mandatory,
		);
		foreach ( $summary as $key => $value ) {
			if ( self::mandatory_key( (string) $key ) && ! is_array( $value ) && ! is_object( $value ) ) {
				$compact[ (string) $key ] = self::bounded_scalar( $value );
			}
		}

		$json = self::encode( $compact );
		if ( ! is_string( $json ) || strlen( $json ) > $max_bytes ) {
			return new WP_Error(
				'mad4b_execution_evidence_mandatory_exceeds_bound',
				'Mandatory execution evidence alone exceeds the certified audit bound; evidence persistence must fail closed.',
				array(
					'original_bytes' => strlen( $original_json ),
					'mandatory_path_count' => count( $mandatory ),
					'max_bytes' => $max_bytes,
				)
			);
		}
		return array(
			'contract' => self::COMPACT_CONTRACT,
			'truncated' => true,
			'summary' => $compact,
			'json' => $json,
		);
	}

	private static function crash_row( $state, $provider_effect, $reconciliation, $terminal, $client_action ) {
		return array(
			'state' => (string) $state,
			'provider_effect' => (string) $provider_effect,
			'reconciliation_required' => (bool) $reconciliation,
			'terminal' => (bool) $terminal,
			'client_action' => (string) $client_action,
		);
	}

	private static function collect_mandatory( $value, $path, array &$out ) {
		if ( ! is_array( $value ) ) return;
		foreach ( $value as $key => $item ) {
			$key = (string) $key;
			$next = '' === $path ? $key : $path . '.' . $key;
			if ( self::mandatory_key( $key ) && ! is_array( $item ) && ! is_object( $item ) ) {
				$bounded_path = strlen( $next ) > self::MAX_PATH_BYTES ? substr( $next, 0, self::MAX_PATH_BYTES ) : $next;
				if ( isset( $out[ $bounded_path ] ) && $bounded_path !== $next ) {
					$bounded_path = substr( hash( 'sha256', $next ), 0, 32 ) . ':' . substr( $key, 0, 64 );
				}
				$out[ $bounded_path ] = self::bounded_scalar( $item );
			}
			if ( is_array( $item ) ) self::collect_mandatory( $item, $next, $out );
		}
	}

	private static function mandatory_key( $key ) {
		$key = strtolower( (string) $key );
		if ( in_array( $key, array(
			'reason_code', 'error_code', 'failure_class', 'client_action', 'mutation_status',
			'terminal_outcome', 'reconciliation_required', 'target_ability', 'target_identity',
			'provider_id', 'capability_id', 'approval_ticket_id', 'operation_id', 'operation_key',
			'request_id', 'event_id', 'mutation_id', 'work_id', 'idempotency_key', 'reconciliation_ref',
		), true ) ) return true;
		return 1 === preg_match( '/(?:_sha256|_digest|_fingerprint|_ref)$/', $key );
	}

	private static function bounded_scalar( $value ) {
		if ( is_bool( $value ) || null === $value || is_int( $value ) || is_float( $value ) ) return $value;
		$value = (string) $value;
		return strlen( $value ) > self::MAX_VALUE_BYTES ? substr( $value, 0, self::MAX_VALUE_BYTES ) : $value;
	}

	private static function encode( $value ) {
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : false;
	}
}
