<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-adaptive-operations-context.php';

/** Three-way, field-exact preflight for existing typed mutation consumers. */
final class MAD4B_SCP_Ownership_Reconciliation {
	const CONTRACT = 'mad4b.ownership-reconciliation-plan.v1';
	const MAX_FIELDS = 64;
	const MAX_VALUE_DEPTH = 16;
	const MAX_VALUE_NODES = 2048;
	const MAX_VALUE_BYTES = 262144;
	const MAX_STRING_BYTES = 32768;
	const TTL = 300;
	const BASELINE_CONTRACT = 'mad4b.ownership-managed-baseline.v1';
	const READBACK_CONTRACT = 'mad4b.ownership-baseline-readback.v1';

	/**
	 * The native typed executor MUST read the actual persisted snapshot, then
	 * pass this exact payload in $result['readback'] to Execution_Receipt::build.
	 * This is a non-authorizing recipe; no caller-declared success is trusted.
	 */
	public static function baseline_readback_material( array $snapshot, array $binding ) {
		$v = self::snapshot_valid( $snapshot ); if ( is_wp_error( $v ) ) return $v;
		$v = MAD4B_SCP_Adaptive_Operations_Context::validate( $binding ); if ( is_wp_error( $v ) ) return $v;
		if ( array_key_exists( 'lineage_proof', $snapshot ) || array_key_exists( 'lineage_sha256', $snapshot ) ) return self::error( 'baseline_snapshot_already_managed' );
		if ( ! isset( $snapshot['target_fingerprint'] ) || ! is_string( $snapshot['target_fingerprint'] ) ||
			'' === $snapshot['target_fingerprint'] || strlen( $snapshot['target_fingerprint'] ) > 191 ) return self::error( 'baseline_target_missing' );
		$digest = self::snapshot_digest( $snapshot ); if ( is_wp_error( $digest ) ) return $digest;
		$binding_sha256 = MAD4B_SCP_Adaptive_Operations_Context::digest( 'mad4b.ownership-baseline-binding.v1', $binding );
		if ( is_wp_error( $binding_sha256 ) ) return $binding_sha256;
		// Match native Execution_Receipt::canon() key order so its signed
		// readback_reconciliation digest and our local verification are identical.
		return array(
			'binding_sha256' => $binding_sha256,
			'contract' => self::READBACK_CONTRACT,
			'resource_id' => $snapshot['resource_id'],
			'snapshot_sha256' => $digest,
			'target_fingerprint' => $snapshot['target_fingerprint'],
		);
	}


	/** Native typed consumer only; ownership cannot be assigned through an ability. */
	public static function managed_baseline( array $snapshot, array $binding, array $execution_receipt ) {
		$v = self::snapshot_valid( $snapshot ); if ( is_wp_error( $v ) ) return $v;
		$v = MAD4B_SCP_Adaptive_Operations_Context::validate( $binding ); if ( is_wp_error( $v ) ) return $v;
		$current_binding = MAD4B_SCP_Adaptive_Operations_Context::current();
		if ( is_wp_error( $current_binding ) ) return $current_binding;
		$v = MAD4B_SCP_Adaptive_Operations_Context::assert_same( $binding, $current_binding ); if ( is_wp_error( $v ) ) return $v;
		if ( ! class_exists( 'MAD4B_SCP_Execution_Receipt' ) ) return self::error( 'execution_receipt_unavailable' );
		$verified = MAD4B_SCP_Execution_Receipt::verify( $execution_receipt ); if ( is_wp_error( $verified ) ) return $verified;
		if ( ! is_array( $verified ) || true !== ( $verified['valid'] ?? null ) ||
			true !== ( $verified['cryptographic_signature_verified'] ?? null ) ||
			! MAD4B_SCP_Adaptive_Operations_Context::sha( $verified['receipt_sha256'] ?? null ) ||
			! MAD4B_SCP_Adaptive_Operations_Context::sha( $execution_receipt['receipt_sha256'] ?? null ) ||
			! hash_equals( $verified['receipt_sha256'], $execution_receipt['receipt_sha256'] ) ) {
			return self::error( 'baseline_execution_receipt_not_verified' );
		}
		$readback = self::baseline_readback_material( $snapshot, $binding ); if ( is_wp_error( $readback ) ) return $readback;
		$encoded = wp_json_encode( array( 'readback' => $readback ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) return self::error( 'baseline_readback_encoding_failed' );
		$expected_readback = hash( 'sha256', $encoded );
		$stage = $execution_receipt['stages']['readback_reconciliation'] ?? array();
		if ( ! is_array( $stage ) || 'PASS' !== ( $stage['status'] ?? null ) ||
			'readback_or_reconciliation' !== ( $stage['evidence_type'] ?? null ) ||
			! MAD4B_SCP_Adaptive_Operations_Context::sha( $stage['evidence_sha256'] ?? null ) ||
			! hash_equals( $expected_readback, $stage['evidence_sha256'] ) ||
			! is_string( $execution_receipt['target_fingerprint'] ?? null ) ||
			! hash_equals( $readback['target_fingerprint'], $execution_receipt['target_fingerprint'] ) ) {
			return self::error( 'baseline_snapshot_readback_unbound' );
		}
		$snapshot['lineage_sha256'] = $verified['receipt_sha256'];
		$sealed = MAD4B_SCP_Adaptive_Operations_Context::seal( self::BASELINE_CONTRACT, array( 'snapshot' => $snapshot, 'binding' => $binding ) );
		if ( is_wp_error( $sealed ) ) return $sealed;
		$snapshot['lineage_proof'] = $sealed;
		return $snapshot;
	}

	/**
	 * Snapshots: resource_id/revision/owner_revision/fields/owners; baseline also
	 * has lineage_sha256. Policies are reviewed consumer code, never tool input.
	 * Field names are opaque exact names: no dotted-path expansion or aliases.
	 */
	public static function plan( array $last_managed, array $current, array $desired, array $policies, array $binding ) {
		$v = MAD4B_SCP_Adaptive_Operations_Context::validate( $binding ); if ( is_wp_error( $v ) ) return $v;
		$live_binding = MAD4B_SCP_Adaptive_Operations_Context::current();
		if ( is_wp_error( $live_binding ) ) return $live_binding;
		$v = MAD4B_SCP_Adaptive_Operations_Context::assert_same( $binding, $live_binding ); if ( is_wp_error( $v ) ) return $v;
		foreach ( array( $last_managed, $current, $desired ) as $snapshot ) { $v = self::snapshot_valid( $snapshot ); if ( is_wp_error( $v ) ) return $v; }
		if ( $current['resource_id'] !== $desired['resource_id'] ||
			$last_managed['resource_id'] !== $current['resource_id'] ) return self::error( 'resource_mismatch' );
		if ( ! hash_equals( $last_managed['target_fingerprint'], $current['target_fingerprint'] ) ||
			! hash_equals( $current['target_fingerprint'], $desired['target_fingerprint'] ) ) {
			return self::error( 'target_identity_changed' );
		}
		$v = self::snapshot_values_bounded( $policies ); if ( is_wp_error( $v ) ) return $v;
		$lineage = $last_managed['lineage_proof'] ?? array();
        if ( ! is_array( $lineage ) ) return self::error( 'baseline_lineage_format_invalid' );
        $baseline_material = MAD4B_SCP_Adaptive_Operations_Context::unseal( self::BASELINE_CONTRACT, $lineage );
		$baseline_value = $last_managed; unset( $baseline_value['lineage_proof'] );
		$baseline_valid = ! is_wp_error( self::snapshot_valid( $last_managed ) ) && ! is_wp_error( $baseline_material ) && ( $baseline_material['snapshot'] ?? null ) === $baseline_value && ! is_wp_error( MAD4B_SCP_Adaptive_Operations_Context::assert_same( $baseline_material['binding'] ?? array(), $binding ) ) && $last_managed['resource_id'] === $current['resource_id'];
		$fields = array_unique( array_merge( array_keys( $current['fields'] ), array_keys( $desired['fields'] ), array_keys( $last_managed['fields'] ?? array() ) ) );
		if ( count( $fields ) > self::MAX_FIELDS || count( $policies ) > self::MAX_FIELDS ) return self::error( 'field_limit' );
		sort( $fields, SORT_STRING );
		$changes = array(); $preserved = array(); $conflicts = array(); $classification = array();
		foreach ( $fields as $field ) {
			$now = self::field( $current, $field ); $want = self::field( $desired, $field ); $last = self::field( $last_managed, $field );
			$changed = $now !== $want;
			if ( ! $changed ) { $classification[ $field ] = 'NO_DRIFT'; continue; }
			$p = isset( $policies[ $field ] ) && is_array( $policies[ $field ] ) ? $policies[ $field ] : array();
			$owner = $current['owners'][ $field ] ?? 'unknown'; $old_owner = $last_managed['owners'][ $field ] ?? 'unknown';
			$reason = '';
			if ( ! $baseline_valid ) $reason = 'missing_managed_baseline';
			elseif ( self::security_sensitive( $field, $p ) ) $reason = 'security_or_authority_field';
			elseif ( ! empty( $p['locked'] ) ) $reason = 'centrally_locked_field';
			elseif ( $current['owner_revision'] !== $last_managed['owner_revision'] || $owner !== $old_owner ) $reason = 'ownership_changed';
			elseif ( 'provider' === $owner ) $reason = 'provider_owned_drift';
			elseif ( 'human' === $owner || $now !== $last ) {
				$classification[ $field ] = 'HUMAN_DELTA';
				if ( true === ( $p['preserve_human'] ?? false ) && true === ( $p['human_delta_valid'] ?? false ) && 'preserve' === ( $p['merge_strategy'] ?? '' ) ) {
					$preserved[ $field ] = array( 'reason' => 'valid_unlocked_human_delta', 'current_sha256' => self::value_digest( $now ) );
					continue;
				}
				// The only merge strategy is an explicit, reviewed additive language set.
				$merged = self::language_union( $last, $now, $want, $p );
				if ( ! is_wp_error( $merged ) ) {
					$changes[ $field ] = array( 'before' => $now, 'after' => array( 'present' => true, 'value' => $merged ), 'classification' => 'VALID_HUMAN_REBASE' );
					$classification[ $field ] = 'VALID_HUMAN_REBASE';
					continue;
				}
				$reason = 'human_owned_or_ambiguous_delta';
			} elseif ( 'managed' !== $owner || 'managed' !== ( $p['owner'] ?? '' ) || true !== ( $p['non_authorizing'] ?? false ) || true !== ( $p['bounded'] ?? false ) ) $reason = 'ownership_or_policy_unknown';
			if ( '' !== $reason ) { $conflicts[ $field ] = $reason; $classification[ $field ] = 'REVIEW_REQUIRED'; continue; }
			$changes[ $field ] = array( 'before' => $now, 'after' => $want, 'classification' => 'MANAGED_DRIFT' );
			$classification[ $field ] = 'MANAGED_DRIFT';
		}
		$expected_after = $current;
		foreach ( $changes as $field => $change ) { if ( $change['after']['present'] ) $expected_after['fields'][ $field ] = $change['after']['value']; else unset( $expected_after['fields'][ $field ] ); }
		$expected_after['revision'] += empty( $changes ) ? 0 : 1;
		$basis = array( 'contract' => self::CONTRACT, 'binding' => $binding, 'resource_id' => $current['resource_id'], 'expected_revision' => $current['revision'], 'owner_revision' => $current['owner_revision'], 'current_sha256' => self::snapshot_digest( $current ), 'expected_after_sha256' => self::snapshot_digest( $expected_after ), 'lineage_sha256' => $last_managed['lineage_sha256'] ?? '', 'policy_sha256' => MAD4B_SCP_Adaptive_Operations_Context::digest( 'ownership-field-policy:v1', $policies ), 'baseline_valid' => $baseline_valid, 'changes' => $changes, 'preserved' => $preserved, 'conflicts' => $conflicts, 'classification' => $classification, 'expires_at' => time() + self::TTL );
		foreach ( array( 'current_sha256', 'expected_after_sha256', 'policy_sha256' ) as $key ) if ( is_wp_error( $basis[ $key ] ) ) return $basis[ $key ];
		$sealed = MAD4B_SCP_Adaptive_Operations_Context::seal( self::CONTRACT, $basis );
		if ( is_wp_error( $sealed ) ) return $sealed;
		return array( 'contract' => self::CONTRACT, 'plan_sha256' => $sealed['sha256'], 'sealed_plan' => $sealed, 'state' => $baseline_valid && empty( $conflicts ) ? ( empty( $changes ) ? 'NO_OP' : 'BOUNDED_REPAIR_PLANNED' ) : 'APPROVAL_REQUIRED', 'diff' => self::public_diff( $changes ), 'preserved_fields' => array_keys( $preserved ), 'user_owned_conflicts' => $conflicts, 'mutation_performed' => false, 'authorizing' => false, 'grants_created' => false, 'commit_requires_existing_typed_executor' => true );
	}

	/** Invoke under the consumer's native CAS lock immediately before its write. */
	public static function commit_guard( array $plan, array $current, array $policies, array $binding ) {
		$basis = self::basis( $plan ); if ( is_wp_error( $basis ) ) return $basis;
		$v = MAD4B_SCP_Adaptive_Operations_Context::assert_same( $basis['binding'], $binding ); if ( is_wp_error( $v ) ) return $v;
		$live_binding = MAD4B_SCP_Adaptive_Operations_Context::current();
		if ( is_wp_error( $live_binding ) ) return $live_binding;
		$v = MAD4B_SCP_Adaptive_Operations_Context::assert_same( $binding, $live_binding ); if ( is_wp_error( $v ) ) return $v;
		$v = self::snapshot_valid( $current ); if ( is_wp_error( $v ) ) return $v;
		if ( true !== $basis['baseline_valid'] ) return self::error( 'missing_managed_baseline' );
		if ( time() >= $basis['expires_at'] ) return self::error( 'plan_expired' );
		if ( ! empty( $basis['conflicts'] ) ) return self::error( 'review_required' );
		if ( 'staging' !== $binding['environment'] ) return self::error( 'automatic_repair_environment_denied' );
		$policy_sha = MAD4B_SCP_Adaptive_Operations_Context::digest( 'ownership-field-policy:v1', $policies );
		$sha = self::snapshot_digest( $current );
		if ( is_wp_error( $sha ) || is_wp_error( $policy_sha ) || $current['revision'] !== $basis['expected_revision'] || $current['owner_revision'] !== $basis['owner_revision'] || $current['resource_id'] !== $basis['resource_id'] || ! hash_equals( $basis['current_sha256'], $sha ) || ! hash_equals( $basis['policy_sha256'], $policy_sha ) ) return self::error( 'concurrent_edit_or_policy_changed' );
		return array( 'contract' => 'mad4b.ownership-commit-guard.v1', 'native_cas_required' => true, 'expected_revision' => $basis['expected_revision'], 'changes' => $basis['changes'], 'plan_sha256' => $plan['plan_sha256'], 'authorizing' => false );
	}

	public static function verify_readback( array $plan, array $after, array $binding ) {
		$basis = self::basis( $plan ); if ( is_wp_error( $basis ) ) return $basis;
		$v = MAD4B_SCP_Adaptive_Operations_Context::assert_same( $basis['binding'], $binding ); if ( is_wp_error( $v ) ) return $v;
		$live_binding = MAD4B_SCP_Adaptive_Operations_Context::current();
		if ( is_wp_error( $live_binding ) ) return $live_binding;
		$v = MAD4B_SCP_Adaptive_Operations_Context::assert_same( $binding, $live_binding ); if ( is_wp_error( $v ) ) return $v;
		$v = self::snapshot_valid( $after ); if ( is_wp_error( $v ) ) return $v;
		if ( time() >= $basis['expires_at'] ) return self::error( 'plan_expired' );
		if ( true !== $basis['baseline_valid'] ) return self::error( 'missing_managed_baseline' );
		if ( ! empty( $basis['conflicts'] ) || $after['resource_id'] !== $basis['resource_id'] || $after['revision'] !== $basis['expected_revision'] + ( empty( $basis['changes'] ) ? 0 : 1 ) || $after['owner_revision'] !== $basis['owner_revision'] ) return self::error( 'readback_revision_conflict' );
		foreach ( $basis['changes'] as $field => $change ) if ( self::field( $after, $field ) !== $change['after'] ) return self::error( 'partial_apply_readback' );
		foreach ( $basis['preserved'] as $field => $evidence ) {
			if ( ! is_array( $evidence ) || ! MAD4B_SCP_Adaptive_Operations_Context::sha( $evidence['current_sha256'] ?? null ) ) return self::error( 'preserved_evidence_invalid' );
			$observed = self::value_digest( self::field( $after, $field ) );
			if ( is_wp_error( $observed ) || ! MAD4B_SCP_Adaptive_Operations_Context::sha( $observed ) ||
				! hash_equals( $evidence['current_sha256'], $observed ) ) return self::error( 'human_delta_overwritten' );
		}
		$actual = self::snapshot_digest( $after );
		if ( is_wp_error( $actual ) || ! hash_equals( $basis['expected_after_sha256'], $actual ) ) return self::error( 'unrelated_state_or_owner_changed' );
		return array( 'contract' => 'mad4b.ownership-readback.v1', 'plan_sha256' => $plan['plan_sha256'], 'resource_id' => $basis['resource_id'], 'readback_sha256' => self::snapshot_digest( $after ), 'verified' => true, 'authorizing' => false, 'native_execution_receipt_required' => true );
	}

	private static function basis( array $plan ) {
		$sealed = $plan['sealed_plan'] ?? array();
        if ( ! is_array( $sealed ) ) return self::error( 'plan_evidence_format_invalid' );
        $basis = MAD4B_SCP_Adaptive_Operations_Context::unseal( self::CONTRACT, $sealed );
		if ( is_wp_error( $basis ) ) return $basis;
		if ( ! is_array( $basis ) || ! isset( $plan['plan_sha256'] ) || ! MAD4B_SCP_Adaptive_Operations_Context::sha( $plan['plan_sha256'] ) || ! hash_equals( $plan['plan_sha256'], $plan['sealed_plan']['sha256'] ) ) return self::error( 'plan_digest_changed' );
		if ( ( $basis['contract'] ?? '' ) !== self::CONTRACT || ! isset( $basis['binding'], $basis['resource_id'], $basis['expected_revision'], $basis['owner_revision'], $basis['current_sha256'], $basis['expected_after_sha256'], $basis['policy_sha256'], $basis['baseline_valid'], $basis['changes'], $basis['preserved'], $basis['conflicts'], $basis['expires_at'] ) || ! is_array( $basis['binding'] ) || ! is_array( $basis['changes'] ) || ! is_array( $basis['preserved'] ) || ! is_array( $basis['conflicts'] ) || ! is_bool( $basis['baseline_valid'] ) || ! is_int( $basis['expected_revision'] ) || ! is_int( $basis['owner_revision'] ) || ! is_int( $basis['expires_at'] ) ) return self::error( 'plan_material_invalid' );
		foreach ( array( 'current_sha256', 'expected_after_sha256', 'policy_sha256' ) as $key ) if ( ! MAD4B_SCP_Adaptive_Operations_Context::sha( $basis[ $key ] ) ) return self::error( 'plan_material_invalid' );
		return $basis;
	}
	private static function snapshot_valid( array $s ) {
		if ( empty( $s['resource_id'] ) || ! is_string( $s['resource_id'] ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,191}$/D', $s['resource_id'] ) || ! isset( $s['revision'], $s['owner_revision'], $s['fields'], $s['owners'] ) || ! is_int( $s['revision'] ) || $s['revision'] < 1 || ! is_int( $s['owner_revision'] ) || $s['owner_revision'] < 1 || ! is_array( $s['fields'] ) || ! is_array( $s['owners'] ) ) return self::error( 'snapshot_incomplete' );
		if ( count( $s['fields'] ) > self::MAX_FIELDS || count( $s['owners'] ) > self::MAX_FIELDS ) return self::error( 'field_limit' );
		$allowed = array( 'resource_id', 'revision', 'owner_revision', 'fields', 'owners',
			'target_fingerprint', 'lineage_sha256', 'lineage_proof' );
		foreach ( array_keys( $s ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) return self::error( 'snapshot_unknown_metadata' );
		}
		if ( ! isset( $s['target_fingerprint'] ) || ! is_string( $s['target_fingerprint'] ) ||
			'' === $s['target_fingerprint'] || strlen( $s['target_fingerprint'] ) > 191 ||
			1 !== preg_match( '/^[A-Za-z0-9._:-]+$/D', $s['target_fingerprint'] ) ) {
			return self::error( 'snapshot_target_invalid' );
		}
		if ( array_key_exists( 'lineage_sha256', $s ) &&
			! MAD4B_SCP_Adaptive_Operations_Context::sha( $s['lineage_sha256'] ) ) return self::error( 'snapshot_lineage_invalid' );
		if ( array_key_exists( 'lineage_proof', $s ) && ! is_array( $s['lineage_proof'] ) ) return self::error( 'snapshot_lineage_invalid' );
		foreach ( array_keys( $s['fields'] ) as $field ) if ( ! is_string( $field ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,100}$/D', $field ) ) return self::error( 'field_identifier_invalid' );
		foreach ( $s['owners'] as $field => $owner ) {
			if ( ! is_string( $field ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,100}$/D', $field ) || ! in_array( $owner, array( 'managed', 'human', 'provider', 'unknown' ), true ) ) return self::error( 'owner_record_invalid' );
		}
		// Covers fields AND signed lineage and all other metadata: validating
		// fields alone left a route for deeply nested extraneous snapshot data.
		return self::snapshot_values_bounded( $s );
	}

	/**
	 * Bound values BEFORE recursive canonicalization and preserve exact
	 * JSON-compatible types. This is a traversal limit, not authorization.
	 * Explicit limits avoid large/cyclic PHP arrays exhausting worker memory.
	 */
	private static function snapshot_values_bounded( array $fields ) {
		$stack = array( array( $fields, 0 ) );
		$nodes = 0; $bytes = 0;
		while ( ! empty( $stack ) ) {
			$item = array_pop( $stack );
			$value = $item[0]; $depth = $item[1];
			if ( ++$nodes > self::MAX_VALUE_NODES || $depth > self::MAX_VALUE_DEPTH ) return self::error( 'snapshot_value_unbounded' );
			if ( is_array( $value ) ) {
				if ( count( $value ) > self::MAX_VALUE_NODES ) return self::error( 'snapshot_value_unbounded' );
				foreach ( $value as $key => $child ) {
					if ( is_string( $key ) ) {
						$bytes += strlen( $key );
						if ( strlen( $key ) > 191 || $bytes > self::MAX_VALUE_BYTES ||
							1 !== preg_match( '//u', $key ) ) return self::error( 'snapshot_value_unbounded' );
					}
					$stack[] = array( $child, $depth + 1 );
					if ( count( $stack ) > self::MAX_VALUE_NODES ) return self::error( 'snapshot_value_unbounded' );
				}
			} elseif ( is_string( $value ) ) {
				$bytes += strlen( $value );
				if ( strlen( $value ) > self::MAX_STRING_BYTES || $bytes > self::MAX_VALUE_BYTES ||
					1 !== preg_match( '//u', $value ) ) return self::error( 'snapshot_value_unbounded' );
			} elseif ( ! is_null( $value ) && ! is_bool( $value ) && ! is_int( $value ) ) {
				// Floats, PHP objects and resources are not deterministic
				// owned-field JSON; canonicalization never gets these values.
				return self::error( 'snapshot_value_type_invalid' );
			}
		}
		return true;
	}
	private static function field( array $s, $field ) { return array( 'present' => array_key_exists( $field, $s['fields'] ?? array() ), 'value' => $s['fields'][ $field ] ?? null ); }
	private static function snapshot_digest( array $s ) { return MAD4B_SCP_Adaptive_Operations_Context::digest( 'ownership-snapshot:v1', $s ); }
	private static function value_digest( $v ) { return MAD4B_SCP_Adaptive_Operations_Context::digest( 'ownership-value:v1', $v ); }
	private static function security_sensitive( $field, array $policy ) {
		return MAD4B_SCP_Structural_Redaction::sensitive_key( $field ) || preg_match( '/(?:authority|grant|permission|scope|consent|approval|eligib|spend|budget|billing|resume|unfreeze|risk|trust|origin|environment|sandbox|lock|enabled|activation)/i', $field ) || true !== ( $policy['non_authorizing'] ?? false ) || ! empty( $policy['security_sensitive'] );
	}
	private static function language_union( array $last, array $now, array $want, array $p ) {
		if ( 'additive_language_set' !== ( $p['merge_strategy'] ?? '' ) || true !== ( $p['human_delta_valid'] ?? false ) || true !== ( $p['preserve_human'] ?? false ) || true !== ( $p['bounded'] ?? false ) || true !== ( $p['non_authorizing'] ?? false ) ) return self::error( 'merge_not_admitted' );
		foreach ( array( $last, $now, $want ) as $f ) { if ( ! $f['present'] || ! is_array( $f['value'] ) || count( $f['value'] ) > 32 ) return self::error( 'language_set_invalid' ); foreach ( $f['value'] as $language ) if ( ! is_string( $language ) || ! preg_match( '/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $language ) ) return self::error( 'language_set_invalid' ); }
		if ( array_diff( $last['value'], $now['value'] ) || array_diff( $last['value'], $want['value'] ) ) return self::error( 'language_removal_conflict' );
		$merged = array_values( array_unique( array_merge( $want['value'], $now['value'] ) ) ); sort( $merged, SORT_STRING );
		return count( $merged ) > 32 ? self::error( 'language_set_limit' ) : $merged;
	}
	private static function public_diff( array $changes ) {
		$out = array(); foreach ( $changes as $field => $change ) $out[] = array( 'field' => $field, 'classification' => $change['classification'], 'before_sha256' => self::value_digest( $change['before'] ), 'after_sha256' => self::value_digest( $change['after'] ), 'values_redacted' => true ); return $out;
	}
	private static function error( $reason ) { return MAD4B_SCP_Adaptive_Operations_Context::error( 'ownership_' . $reason ); }
}
