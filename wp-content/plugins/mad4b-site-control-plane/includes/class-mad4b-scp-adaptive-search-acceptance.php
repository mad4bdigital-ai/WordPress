<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Machine-measurable Phase 38 acceptance descriptors and reducer.
 *
 * Definitions are evidence requirements, not self-certification. A gate passes
 * only when the caller supplies exact-head evidence satisfying every declared
 * fixture and assertion threshold.
 */
final class MAD4B_SCP_Adaptive_Search_Acceptance {
	const CONTRACT = 'mad4b.adaptive-search-acceptance.v2';

	public static function phase_gates() {
		return array(
			'ADAPTIVE_SEARCH_META_MODEL_PASS' => array(
				'fixtures' => array( 'search_profile_contract', 'runtime_fact_contract', 'effective_context_contract', 'non_authorizing_boundary' ),
				'assertion_count_min' => 4, 'evidence_class' => 'contract_plus_runtime',
			),
			'SEARCH_CONTEXT_COMPILER_PASS' => array(
				'fixtures' => array( 'deterministic_precedence', 'security_constraint_precedence', 'reason_chain', 'dependency_fingerprint' ),
				'assertion_count_min' => 4, 'evidence_class' => 'pure_runtime',
			),
			'INDEXABLE_SURFACE_GRAPH_PASS' => array(
				'fixtures' => array( 'object_surface_separation', 'term_archive', 'post_type_archive', 'eligibility_envelope', 'surface_admission_bound' ),
				'assertion_count_min' => 5, 'evidence_class' => 'runtime_inventory',
			),
			'SEO_FIELD_PROVENANCE_PASS' => array(
				'fixtures' => array( 'field_source_binding', 'provider_conflict_preserved', 'rendered_fallback', 'canonical_provenance' ),
				'assertion_count_min' => 4, 'evidence_class' => 'runtime_provider_fixture',
			),
			'SEARCH_TARGET_COMPILER_PASS' => array(
				'fixtures' => array( 'owned_target', 'discovery_target', 'query_identity_dimensions', 'deduplication', 'post_change_target' ),
				'assertion_count_min' => 5, 'evidence_class' => 'pure_runtime',
			),
			'SEARCH_DECISION_EXPLAINABILITY_PASS' => array(
				'fixtures' => array( 'deterministic_score', 'factor_provenance', 'positive_negative_contributions', 'stable_tie_break', 'decision_digest' ),
				'assertion_count_min' => 5, 'evidence_class' => 'pure_runtime',
			),
			'SEARCH_FAIR_SCHEDULER_PASS' => array(
				'fixtures' => array( 'language_no_starvation', 'market_no_starvation', 'aging', 'minimum_coverage', 'stable_order' ),
				'assertion_count_min' => 5, 'evidence_class' => 'scheduler_runtime',
			),
			'SEARCH_BUDGET_GOVERNOR_PASS' => array(
				'fixtures' => array( 'protected_reserve', 'local_scope_truthful', 'stable_global_account_identity', 'hard_global_requires_shared_authority', 'billing_cycle_reset', 'provider_usage_reconciliation' ),
				'assertion_count_min' => 6, 'evidence_class' => 'runtime_plus_authoritative_backend_fixture',
			),
			'SERP_PROVIDER_CONFORMANCE_PASS' => array(
				'fixtures' => array( 'provider_descriptor', 'request_translation', 'bounded_execution', 'failure_taxonomy', 'normalization', 'usage_economics' ),
				'assertion_count_min' => 6, 'evidence_class' => 'provider_conformance_runtime',
			),
			'SERP_EVIDENCE_INTEGRITY_PASS' => array(
				'fixtures' => array( 'immutable_snapshot', 'request_fingerprint', 'raw_digest', 'normalized_digest', 'normalizer_version', 'cost_receipt' ),
				'assertion_count_min' => 6, 'evidence_class' => 'runtime_evidence',
			),
			'SEARCH_EXTERNAL_EVIDENCE_TRUST_PASS' => array(
				'fixtures' => array( 'schema_bound', 'response_size_bound', 'instruction_isolation', 'secret_non_projection', 'retention_rights' ),
				'assertion_count_min' => 5, 'evidence_class' => 'negative_security_runtime',
			),
			'SEARCH_RECONCILIATION_BEFORE_RETRY_PASS' => array(
				'fixtures' => array( 'unknown_effect_blocks_retry', 'usage_reconciliation', 'breaker_interaction', 'reconciled_retry' ),
				'assertion_count_min' => 4, 'evidence_class' => 'fault_runtime',
			),
			'ADAPTIVE_SEARCH_EXPERIENCE_PASS' => array(
				'fixtures' => array( 'stable_section_identity', 'active_state', 'degraded_provider_state', 'degraded_budget_state', 'reconciliation_state', 'no_secret_projection' ),
				'assertion_count_min' => 6, 'evidence_class' => 'ui_model_runtime',
			),
			'SEARCH_CONTENT_HANDOFF_NON_AUTHORIZING_PASS' => array(
				'fixtures' => array( 'signal_only', 'proposal_boundary', 'content_plan_required', 'no_direct_publish', 'no_production_authority' ),
				'assertion_count_min' => 5, 'evidence_class' => 'governance_runtime',
			),
			'ADAPTIVE_SEARCH_GENERALIZATION_PASS' => array(
				'fixtures' => array( 'single_language_site', 'multilingual_archive_site', 'large_target_inventory', 'partial_translation', 'provider_outage', 'new_provider_without_core_branch', 'new_surface_without_business_branch', 'cross_fault_matrix' ),
				'assertion_count_min' => 8, 'evidence_class' => 'exact_head_generalization_matrix',
			),
		);
	}

	public static function review_hardening_gates() {
		return array(
			'SEARCH_OBSERVATION_COMPARABILITY_PASS' => array(
				'fixtures' => array( 'same_context', 'provider_change_unclassified', 'cross_provider_certification_required', 'cross_provider_classified', 'location_change', 'depth_change' ),
				'assertion_count_min' => 6, 'evidence_class' => 'pure_runtime',
			),
			'SEARCH_QUERY_URL_RANK_CAPTURE_SEMANTICS_PASS' => array(
				'fixtures' => array( 'unicode_query', 'url_alias', 'rank_fields', 'complete_capture', 'partial_capture' ),
				'assertion_count_min' => 5, 'evidence_class' => 'pure_runtime',
			),
			'SEARCH_ELIGIBILITY_AND_SURFACE_ADMISSION_PASS' => array(
				'fixtures' => array( 'indexable_owned', 'noindex', 'unknown_confidence', 'x_robots', 'unknown_param', 'cardinality_required', 'cardinality_cap', 'url_pagination_bound', 'pagination_cap' ),
				'assertion_count_min' => 9, 'evidence_class' => 'pure_runtime',
			),
			'PROVIDER_ACCOUNT_DISTRIBUTED_BUDGET_AUTHORITY_PASS' => array(
				'fixtures' => array( 'stable_global_account_identity', 'hard_global_without_authority_denied', 'authoritative_reservation', 'local_scope_truthful', 'reserve_protected', 'cycle_reset', 'stale_reservation_expiry', 'reconcile_provider_usage' ),
				'assertion_count_min' => 8, 'evidence_class' => 'runtime_plus_authoritative_backend_fixture',
			),
			'PROVIDER_EVIDENCE_RIGHTS_RETENTION_PASS' => array(
				'fixtures' => array( 'raw_denied', 'raw_capped', 'normalized_capped', 'region_denied' ),
				'assertion_count_min' => 4, 'evidence_class' => 'pure_runtime',
			),
			'DETERMINISTIC_SEARCH_DECISION_POLICY_PASS' => array(
				'fixtures' => array( 'deterministic_digest', 'missing_factor_denied', 'provenance_required', 'stable_tie_break', 'cost_penalty' ),
				'assertion_count_min' => 5, 'evidence_class' => 'pure_runtime',
			),
			'ADAPTIVE_SEARCH_CROSS_FAULT_ACCEPTANCE_PASS' => array(
				'fixtures' => array( 'profile_drift', 'provider_drift', 'language_drift', 'surface_drift', 'budget_race', 'quota_cycle_drift', 'lease_loss', 'partial_capture', 'cache_incomparable', 'uncertain_effect_reconcile', 'adaptive_experience_state' ),
				'assertion_count_min' => 11, 'evidence_class' => 'composed_fault_runtime',
			),
		);
	}

	public static function gates() {
		return array_merge( self::phase_gates(), self::review_hardening_gates() );
	}

	public static function evaluate( array $evidence ) {
		$gates = self::gates();
		$results = array();
		$all = true;
		foreach ( $gates as $gate => $definition ) {
			$row = isset( $evidence[ $gate ] ) && is_array( $evidence[ $gate ] ) ? $evidence[ $gate ] : array();
			$fixtures = isset( $row['fixtures'] ) && is_array( $row['fixtures'] ) ? $row['fixtures'] : array();
			$passed = array_keys( array_filter( $fixtures, static function ( $value ) { return true === $value; } ) );
			$missing = array_values( array_diff( $definition['fixtures'], $passed ) );
			$assertions = max( 0, (int) ( $row['assertion_count'] ?? 0 ) );
			$exact_head = ! empty( $row['exact_head_bound'] );
			$pass = empty( $missing ) && $assertions >= (int) $definition['assertion_count_min'] && $exact_head;
			if ( ! $pass ) $all = false;
			$results[ $gate ] = array(
				'pass' => $pass,
				'missing_fixtures' => $missing,
				'assertion_count' => $assertions,
				'required_assertion_count' => (int) $definition['assertion_count_min'],
				'exact_head_bound' => $exact_head,
				'evidence_class' => $definition['evidence_class'],
			);
		}
		return array(
			'contract' => self::CONTRACT,
			'pass' => $all,
			'gate_count' => count( $gates ),
			'phase_gate_count' => count( self::phase_gates() ),
			'review_hardening_gate_count' => count( self::review_hardening_gates() ),
			'gates' => $results,
			'authorizing' => false,
		);
	}
}
