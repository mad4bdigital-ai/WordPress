<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Retained, aggregate-only intelligence. A report or comparison never authorizes a write. */
final class MAD4B_SCP_G5_Growth_Evidence {
	const CONTRACT = 'mad4b.feature007-g5-growth-observation.v1';
	const MAX_OBSERVATIONS = 20;
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 32 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/growth-evidence-preview' ) ) ) return;
		wp_register_ability( 'mad4b/growth-evidence-preview', array(
			'label' => 'Preview Retained Growth Evidence', 'description' => 'Compare exact retained aggregate observations with dimensional provenance, consent and uncertainty. This does not capture reports or authorize content changes.',
			'category' => 'mad4b-admin', 'execute_callback' => array( __CLASS__, 'preview' ), 'permission_callback' => array( 'MAD4B_SCP_G5_External_Providers', 'can_manage' ),
			'input_schema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'observation_refs' ), 'properties' => array( 'observation_refs' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_OBSERVATIONS, 'items' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'provider_id', 'observation_id' ), 'properties' => array( 'provider_id' => array( 'type' => 'string', 'maxLength' => 64 ), 'observation_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ) ) ) ) ) ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array( 'public' => false, 'show_in_rest' => false, 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
		) );
	}

	public static function preview( $input ) {
		if ( ! MAD4B_SCP_G5_External_Providers::can_manage() ) return self::error( 'access_denied' );
		if ( ! is_array( $input ) || array_keys( $input ) !== array( 'observation_refs' ) || ! is_array( $input['observation_refs'] ) || ! $input['observation_refs'] || count( $input['observation_refs'] ) > self::MAX_OBSERVATIONS ) return self::error( 'input_invalid' );
		$observations = array(); $seen = array();
		foreach ( $input['observation_refs'] as $ref ) {
			if ( ! is_array( $ref ) || array_diff( array_keys( $ref ), array( 'provider_id', 'observation_id' ) ) || ! MAD4B_SCP_G5_External_Providers::id( $ref['provider_id'] ?? null ) || ! MAD4B_SCP_G5_External_Providers::sha( $ref['observation_id'] ?? null ) ) return self::error( 'input_invalid' );
			$key = $ref['provider_id'] . ':' . $ref['observation_id'];
			if ( isset( $seen[ $key ] ) ) return self::error( 'duplicate_observation' );
			$seen[ $key ] = true;
			$row = self::resolve( $ref ); if ( is_wp_error( $row ) ) return $row;
			$observations[] = $row;
		}
		$comparison = self::compare( $observations );
		return array( 'contract' => 'mad4b.feature007-g5-growth-preview.v1', 'observations' => $observations, 'comparison' => $comparison, 'proposal' => array( 'state' => $comparison['comparable'] ? 'editorial_review_candidate' : 'additional_comparable_evidence_required', 'requires_separate_current_content_plan' => true, 'causality_claimed' => false, 'direct_content_mutation' => false, 'authorizing' => false ), 'outbound_requests' => 0, 'paid_execution_performed' => false, 'authority_created' => false, 'authorizing' => false );
	}

	/** Only server-resolved rows enter normalization; caller input contains opaque evidence refs. */
	private static function resolve( array $ref ) {
		$adapters = MAD4B_SCP_G5_External_Providers::adapters();
		if ( ! isset( $adapters[ $ref['provider_id'] ] ) ) return self::error( 'adapter_unavailable' );
		$adapter = $adapters[ $ref['provider_id'] ];
		try {
			$d = $adapter->descriptor(); $guard = MAD4B_SCP_G5_External_Providers::descriptor_guard( $adapter, $d );
			if ( is_wp_error( $guard ) ) return $guard;
			// Resolve only metadata needed for authorization before the retained payload is touched.
			$scope_meta = $adapter->observation_scope( $ref['observation_id'] );
			if ( is_wp_error( $scope_meta ) || ! is_array( $scope_meta ) ) return self::error( 'observation_scope_unavailable' );
			$scope = self::scope_value( $scope_meta, $d ); if ( is_wp_error( $scope ) ) return $scope;
			// Revalidation occurs for every row. An admin role does not replace private provider consent.
			if ( true !== $adapter->authorize_read( $scope ) ) return self::error( 'observation_access_denied' );
			$consent = $adapter->consent_status( $scope );
			if ( ! is_array( $consent ) || ! is_int( $consent['expires_at'] ?? null ) || $consent['expires_at'] <= time() || ! is_array( $consent['granted_scopes'] ?? null ) || array_diff( $d['required_scopes'], $consent['granted_scopes'] ) || ( $consent['account_ref'] ?? null ) !== $scope['account_ref'] || ( $consent['property_ref'] ?? null ) !== $scope['property_ref'] || ( $consent['tenant_ref'] ?? null ) !== $scope['tenant_ref'] || ( $consent['generation_sha256'] ?? null ) !== $d['generation_sha256'] ) return self::error( 'external_consent_required' );
			$row = $adapter->read_observation( $ref['observation_id'], $scope );
			if ( is_wp_error( $row ) || ! is_array( $row ) ) return self::error( 'observation_unavailable' );
			$row_scope = self::scope( $row, $d ); if ( is_wp_error( $row_scope ) ) return $row_scope;
			if ( ! hash_equals( MAD4B_SCP_G5_External_Providers::digest( $scope ), MAD4B_SCP_G5_External_Providers::digest( $row_scope ) ) ) return self::error( 'observation_scope_changed' );
			$normalized = self::normalize( $row, $d, $scope, $ref['observation_id'] );
			if ( is_wp_error( $normalized ) ) return $normalized;
			// Recheck current descriptor and subject after retained evidence access.
			$after = $adapter->descriptor();
			// Immutable descriptor identity is not enough: certification may expire while
			// a retained read is running. Re-evaluate code provenance, site, rights,
			// economics and current expiry before releasing the observation.
			if ( ! is_array( $after ) ) return self::error( 'observation_binding_changed' );
			$after_guard = MAD4B_SCP_G5_External_Providers::descriptor_guard( $adapter, $after );
			if ( is_wp_error( $after_guard ) ) return $after_guard;
			if ( ! hash_equals( MAD4B_SCP_G5_External_Providers::digest( $d ), MAD4B_SCP_G5_External_Providers::digest( $after ) ) || true !== $adapter->authorize_read( $scope ) ) return self::error( 'observation_binding_changed' );
			$final_consent = $adapter->consent_status( $scope );
			if ( ! is_array( $final_consent ) || ! hash_equals( MAD4B_SCP_G5_External_Providers::digest( $consent ), MAD4B_SCP_G5_External_Providers::digest( $final_consent ) ) || $final_consent['expires_at'] <= time() ) return self::error( 'external_consent_changed' );
			return $normalized;
		} catch ( Throwable $e ) { return self::error( 'observation_unavailable' ); }
	}

	private static function scope( array $row, array $d ) {
		$s = $row['scope'] ?? null;
		if ( ! is_array( $s ) ) return self::error( 'observation_scope_mismatch' );
		return self::scope_value( $s, $d );
	}

	private static function scope_value( array $s, array $d ) {
		if ( array_diff( array_keys( $s ), array( 'account_ref', 'property_ref', 'tenant_ref', 'site_uuid', 'capability_id' ) ) || ( $s['account_ref'] ?? null ) !== $d['account_ref'] || ( $s['tenant_ref'] ?? null ) !== $d['tenant_ref'] || ( $s['site_uuid'] ?? null ) !== $d['site_uuid'] || ! in_array( $s['property_ref'] ?? null, $d['property_refs'], true ) || ! in_array( $s['capability_id'] ?? null, $d['capability_ids'], true ) ) return self::error( 'observation_scope_mismatch' );
		return $s;
	}

	private static function normalize( array $row, array $d, array $scope, $id ) {
		if ( self::CONTRACT !== ( $row['contract'] ?? null ) || $id !== ( $row['observation_id'] ?? null ) || $d['provider_id'] !== ( $row['provider_id'] ?? null ) || $d['generation_sha256'] !== ( $row['generation_sha256'] ?? null ) || $d['schema_sha256'] !== ( $row['schema_sha256'] ?? null ) || 'aggregate_only' !== ( $row['privacy_class'] ?? null ) ) return self::error( 'observation_contract_invalid' );
		$now = time();
		if ( ! is_int( $row['observed_at'] ?? null ) || $row['observed_at'] <= 0 || $row['observed_at'] > $now || ! is_int( $row['valid_until'] ?? null ) || $row['valid_until'] <= $now || $row['valid_until'] <= $row['observed_at'] || $row['valid_until'] - $row['observed_at'] > $d['rights']['max_retention_seconds'] || ! MAD4B_SCP_G5_External_Providers::sha( $row['source_sha256'] ?? null ) || ! in_array( $row['storage_region'] ?? null, $d['rights']['storage_regions'], true ) ) return self::error( 'observation_stale_or_rights_denied' );
		$dimensions = $row['dimensions'] ?? null;
		$keys = array( 'query', 'surface_ref', 'market', 'language', 'window_start', 'window_end', 'attribution_window_seconds', 'timezone', 'granularity', 'metric_currency' );
		if ( ! is_array( $dimensions ) || count( $dimensions ) !== count( $keys ) || array_diff( array_keys( $dimensions ), $keys ) ) return self::error( 'dimensions_missing' );
		foreach ( $keys as $key ) if ( 'attribution_window_seconds' !== $key && ( ! is_string( $dimensions[ $key ] ) || '' === $dimensions[ $key ] || strlen( $dimensions[ $key ] ) > 512 || preg_match( '/[\x00-\x1f\x7f]/', $dimensions[ $key ] ) ) ) return self::error( 'dimensions_invalid' );
		foreach ( array( 'window_start', 'window_end' ) as $key ) {
			$v = $dimensions[ $key ];
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $v ) || gmdate( 'Y-m-d', strtotime( $v . ' UTC' ) ) !== $v ) return self::error( 'window_invalid' );
		}
		if ( $dimensions['window_end'] < $dimensions['window_start'] || ! is_int( $dimensions['attribution_window_seconds'] ) || $dimensions['attribution_window_seconds'] < 0 || $dimensions['attribution_window_seconds'] > 31536000 || ! preg_match( '/^(?:[A-Z]{3}|NONE)$/D', $dimensions['metric_currency'] ) ) return self::error( 'window_or_currency_invalid' );
		$metrics = $row['metrics'] ?? null;
		if ( ! is_array( $metrics ) || ! $metrics || count( $metrics ) > 32 || array_diff( array_keys( $metrics ), array_keys( $d['metrics'] ) ) ) return self::error( 'metrics_invalid' );
		$normalized_metrics = array();
		foreach ( $metrics as $name => $value ) {
			if ( ( ! is_int( $value ) && ! is_float( $value ) ) || ! is_finite( (float) $value ) ) return self::error( 'metric_value_invalid' );
			$normalized_metrics[ $name ] = array( 'value' => $value, 'semantic_id' => $d['metrics'][ $name ]['semantic_id'], 'unit' => $d['metrics'][ $name ]['unit'] );
		}
		$sampling = $row['sampling'] ?? null; $cost = $row['cost'] ?? null;
		if ( ! is_array( $sampling ) || array_diff( array_keys( $sampling ), array( 'state', 'rate', 'method', 'thresholding' ) ) || ! in_array( $sampling['state'] ?? null, array( 'complete', 'partial', 'sampled', 'unknown' ), true ) || ( ! is_int( $sampling['rate'] ?? null ) && ! is_float( $sampling['rate'] ?? null ) ) || ! is_finite( (float) $sampling['rate'] ) || $sampling['rate'] < 0 || $sampling['rate'] > 1 || ! MAD4B_SCP_G5_External_Providers::id( $sampling['method'] ?? null ) || ! is_bool( $sampling['thresholding'] ?? null ) || ( 'complete' === $sampling['state'] && ( 1 !== $sampling['rate'] && 1.0 !== $sampling['rate'] ) ) ) return self::error( 'sampling_invalid' );
		if ( ! is_array( $cost ) || array_diff( array_keys( $cost ), array( 'state', 'quota_units', 'cost_micro', 'currency', 'receipt_sha256', 'budget_state' ) ) || ! in_array( $cost['state'] ?? null, array( 'settled', 'uncertain' ), true ) || ! is_int( $cost['quota_units'] ?? null ) || $cost['quota_units'] < 0 || ! is_int( $cost['cost_micro'] ?? null ) || $cost['cost_micro'] < 0 || ( $cost['currency'] ?? null ) !== $d['economics']['currency'] || ! MAD4B_SCP_G5_External_Providers::sha( $cost['receipt_sha256'] ?? null ) || ! in_array( $cost['budget_state'] ?? null, array( 'admitted', 'exhausted', 'reconciliation_required' ), true ) ) return self::error( 'cost_receipt_unknown' );
		$contradictions = $row['contradictions'] ?? null;
		if ( ! is_array( $contradictions ) || count( $contradictions ) > 20 ) return self::error( 'contradiction_contract_invalid' );
		foreach ( $contradictions as $hash ) if ( ! MAD4B_SCP_G5_External_Providers::sha( $hash ) ) return self::error( 'contradiction_contract_invalid' );
		$comparison_scope = array( 'provider_id' => $d['provider_id'], 'account_ref' => $scope['account_ref'], 'property_ref' => $scope['property_ref'], 'tenant_ref' => $scope['tenant_ref'], 'site_uuid' => $scope['site_uuid'], 'capability_id' => $scope['capability_id'], 'generation_sha256' => $d['generation_sha256'], 'schema_sha256' => $d['schema_sha256'], 'dimensions' => $dimensions, 'metric_semantics' => $d['metrics'], 'sampling_method' => $sampling['method'] );
		return array( 'contract' => self::CONTRACT, 'observation_id' => $id, 'provider_id' => $d['provider_id'], 'scope' => $scope, 'dimensions' => $dimensions, 'metrics' => $normalized_metrics, 'sampling' => $sampling, 'cost' => $cost, 'observed_at' => $row['observed_at'], 'valid_until' => $row['valid_until'], 'source_sha256' => $row['source_sha256'], 'generation_sha256' => $d['generation_sha256'], 'schema_sha256' => $d['schema_sha256'], 'comparability_key' => MAD4B_SCP_G5_External_Providers::digest( $comparison_scope ), 'contradictions' => array_values( $contradictions ), 'privacy_class' => 'aggregate_only', 'source_class' => 'server_retained_provider_evidence', 'trust_class' => 'external_evidence_not_instructions', 'cross_provider_comparison' => 'separate_reviewed_equivalence_required', 'authorizing' => false );
	}

	/** Internal calculation only. It has no write, credential or network path. */
	private static function compare( array $observations ) {
		$blockers = array(); $first = reset( $observations ); $groups = array();
		foreach ( $observations as $observation ) {
			$groups[ $observation['comparability_key'] ][] = $observation['observation_id'];
			if ( $first['comparability_key'] !== $observation['comparability_key'] ) $blockers[] = 'mixed_dimensions_or_metric_semantics';
			if ( array_keys( $first['metrics'] ) !== array_keys( $observation['metrics'] ) ) $blockers[] = 'different_metric_sets';
			if ( 'complete' !== $observation['sampling']['state'] || $observation['sampling']['thresholding'] ) $blockers[] = 'partial_sampled_or_thresholded_observation';
			if ( 'settled' !== $observation['cost']['state'] || 'admitted' !== $observation['cost']['budget_state'] ) $blockers[] = 'cost_or_budget_reconciliation_required';
			if ( $observation['contradictions'] ) $blockers[] = 'contradictory_observations';
		}
		$conflicts = array();
		foreach ( $observations as $left_index => $left ) foreach ( $observations as $right_index => $right ) {
			if ( $right_index <= $left_index || $left['comparability_key'] !== $right['comparability_key'] ) continue;
			if ( ! hash_equals( MAD4B_SCP_G5_External_Providers::digest( $left['metrics'] ), MAD4B_SCP_G5_External_Providers::digest( $right['metrics'] ) ) ) {
				$blockers[] = 'contradictory_observations';
				$conflicts[] = array( 'left_ref' => $left['observation_id'], 'right_ref' => $right['observation_id'], 'resolution' => 'source_review_required' );
			}
		}
		$blockers = array_values( array_unique( $blockers ) );
		return array( 'comparable' => ! $blockers, 'groups' => $groups, 'blockers' => $blockers, 'conflicts' => $conflicts, 'aggregation_performed' => false, 'causality_claimed' => false, 'direct_content_mutation' => false, 'authorizing' => false );
	}

	private static function error( $code ) { return MAD4B_SCP_G5_External_Providers::error( $code ); }
}
