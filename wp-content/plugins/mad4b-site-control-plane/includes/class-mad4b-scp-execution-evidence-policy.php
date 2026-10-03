<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'MAD4B_SCP_Identifiers' ) ) require_once __DIR__ . '/class-mad4b-scp-identifiers.php';

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
	const TERMINAL_EVIDENCE_CONTRACT = 'mad4b.execution-terminal-evidence.v1';
	const TERMINAL_RECEIPT_CONTRACT = 'mad4b.execution-terminal-receipt.v1';
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

	public static function terminal_success_material( array $claim, $result ) {
		$ability = isset( $claim['ability'] ) ? trim( (string) $claim['ability'] ) : '';
		$provider = isset( $claim['provider'] ) ? sanitize_key( (string) $claim['provider'] ) : 'core';
		if ( '' === $ability ) return new WP_Error( 'mad4b_execution_terminal_identity_missing', 'Terminal execution evidence requires the governed ability identity.' );
		$result_sha256 = self::canonical_digest( 'mad4b.execution-result.v1', $result );
		if ( is_wp_error( $result_sha256 ) ) return $result_sha256;
		$material = array(
			'contract' => self::TERMINAL_EVIDENCE_CONTRACT,
			'reason_code' => 'execution_completed',
			'terminal_outcome' => 'success',
			'ability' => $ability,
			'provider_id' => '' === $provider ? 'core' : $provider,
			'server_id' => isset( $claim['server_id'] ) ? sanitize_key( (string) $claim['server_id'] ) : '',
			'request_id' => isset( $claim['request_id'] ) ? substr( (string) $claim['request_id'], 0, 100 ) : '',
			'approval_ticket_id' => isset( $claim['approval_ticket_id'] ) ? strtolower( trim( (string) $claim['approval_ticket_id'] ) ) : '',
			'target_fingerprint' => isset( $claim['target_fingerprint'] ) ? strtolower( trim( (string) $claim['target_fingerprint'] ) ) : '',
			'resource_set_sha256' => isset( $claim['resource_set_sha256'] ) ? strtolower( trim( (string) $claim['resource_set_sha256'] ) ) : '',
			'context_receipt_sha256' => isset( $claim['context_receipt_sha256'] ) ? strtolower( trim( (string) $claim['context_receipt_sha256'] ) ) : '',
			'commit_guard_material_sha256' => isset( $claim['commit_guard_receipt']['material_sha256'] ) ? strtolower( trim( (string) $claim['commit_guard_receipt']['material_sha256'] ) ) : '',
			'result_sha256' => $result_sha256,
			'provider_side_effect_possible' => true,
			'authorizing' => false,
		);
		$material['terminal_material_sha256'] = self::canonical_digest( self::TERMINAL_EVIDENCE_CONTRACT, $material );
		if ( is_wp_error( $material['terminal_material_sha256'] ) ) return $material['terminal_material_sha256'];
		return $material;
	}

	public static function terminal_receipt( array $material, array $audit_entry ) {
		$event_id = isset( $audit_entry['event_id'] ) ? strtolower( trim( (string) $audit_entry['event_id'] ) ) : '';
		$entry_hash = isset( $audit_entry['entry_hash'] ) ? strtolower( trim( (string) $audit_entry['entry_hash'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $event_id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $entry_hash ) ) {
			return self::terminal_persistence_error( 'audit_receipt_invalid', 'Audit persistence did not return a verifiable durable event identity.', array(
				'audit_event_id' => $event_id,
				'audit_entry_hash' => $entry_hash,
			) );
		}
		$receipt = array(
			'contract' => self::TERMINAL_RECEIPT_CONTRACT,
			'terminal_outcome' => 'success',
			'terminal_material_sha256' => isset( $material['terminal_material_sha256'] ) ? (string) $material['terminal_material_sha256'] : '',
			'result_sha256' => isset( $material['result_sha256'] ) ? (string) $material['result_sha256'] : '',
			'audit_event_id' => $event_id,
			'audit_entry_hash' => $entry_hash,
			'crash_point' => 'durable_receipt_committed',
			'durable' => true,
			'authorizing' => false,
		);
		$receipt['receipt_sha256'] = self::canonical_digest( self::TERMINAL_RECEIPT_CONTRACT, $receipt );
		if ( is_wp_error( $receipt['receipt_sha256'] ) ) return $receipt['receipt_sha256'];
		$receipt['receipt_id'] = MAD4B_SCP_Identifiers::receipt_id_from_sha256( $receipt['receipt_sha256'] );
		if ( '' === $receipt['receipt_id'] ) return self::terminal_persistence_error( 'receipt_identity_invalid', 'Terminal execution receipt identity could not be canonicalized.' );
		return $receipt;
	}

	public static function terminal_persistence_error( $reason_code, $message, array $extra = array() ) {
		$state = self::crash_point( 'provider_returned' );
		if ( is_wp_error( $state ) ) $state = array(
			'state' => 'RECONCILING',
			'reconciliation_required' => true,
			'blind_retry_allowed' => false,
			'client_action' => 'reconcile_provider_state_before_any_retry',
			'authorizing' => false,
		);
		return new WP_Error(
			'mad4b_execution_terminal_evidence_persist_failed',
			(string) $message,
			array_merge( $state, array(
				'reason_code' => sanitize_key( (string) $reason_code ),
				'terminal_success' => false,
				'receipt_durable' => false,
			), $extra )
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

	private static function canonical_digest( $domain, $value ) {
		$canonical = self::canonicalize( $value );
		if ( is_wp_error( $canonical ) ) return $canonical;
		$json = self::encode( array( 'domain' => (string) $domain, 'value' => $canonical ) );
		if ( ! is_string( $json ) ) return new WP_Error( 'mad4b_execution_terminal_digest_failed', 'Terminal execution evidence could not be canonically encoded.' );
		return hash( 'sha256', $json );
	}

	private static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			$keys = array_keys( $value );
			$is_list = $keys === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				$out = array();
				foreach ( $value as $item ) {
					$normalized = self::canonicalize( $item );
					if ( is_wp_error( $normalized ) ) return $normalized;
					$out[] = $normalized;
				}
				return $out;
			}
			sort( $keys, SORT_STRING );
			$out = array();
			foreach ( $keys as $key ) {
				$normalized = self::canonicalize( $value[ $key ] );
				if ( is_wp_error( $normalized ) ) return $normalized;
				$out[ (string) $key ] = $normalized;
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			if ( $value instanceof WP_Error ) return new WP_Error( 'mad4b_execution_terminal_result_invalid', 'WP_Error cannot be represented as terminal success evidence.' );
			return self::canonicalize( get_object_vars( $value ) );
		}
		if ( is_string( $value ) || is_int( $value ) || is_bool( $value ) || null === $value ) return $value;
		if ( is_float( $value ) && is_finite( $value ) ) return $value;
		return new WP_Error( 'mad4b_execution_terminal_result_invalid', 'Terminal success result contains an unsupported evidence value.' );
	}

	private static function encode( $value ) {
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : false;
	}
}
