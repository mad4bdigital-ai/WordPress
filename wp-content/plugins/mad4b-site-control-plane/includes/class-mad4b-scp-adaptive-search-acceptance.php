<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Machine-measurable Phase 38 P0 acceptance descriptors and reducer.
 */
final class MAD4B_SCP_Adaptive_Search_Acceptance {
	const CONTRACT = 'mad4b.adaptive-search-acceptance.v1';

	public static function gates() {
		return array(
			'SEARCH_OBSERVATION_COMPARABILITY_PASS' => array(
				'fixtures' => array( 'same_context', 'provider_change_unclassified', 'cross_provider_classified', 'location_change', 'depth_change' ),
				'assertion_count_min' => 5,
				'evidence_class' => 'pure_runtime',
			),
			'SEARCH_QUERY_URL_RANK_CAPTURE_SEMANTICS_PASS' => array(
				'fixtures' => array( 'unicode_query', 'url_alias', 'rank_fields', 'complete_capture', 'partial_capture' ),
				'assertion_count_min' => 5,
				'evidence_class' => 'pure_runtime',
			),
			'SEARCH_ELIGIBILITY_AND_SURFACE_ADMISSION_PASS' => array(
				'fixtures' => array( 'indexable_owned', 'noindex', 'x_robots', 'unknown_param', 'cardinality_cap', 'pagination_cap' ),
				'assertion_count_min' => 6,
				'evidence_class' => 'pure_runtime',
			),
			'PROVIDER_ACCOUNT_DISTRIBUTED_BUDGET_AUTHORITY_PASS' => array(
				'fixtures' => array( 'hard_global_without_authority_denied', 'authoritative_reservation', 'local_scope_truthful', 'reserve_protected', 'reconcile_provider_usage' ),
				'assertion_count_min' => 5,
				'evidence_class' => 'runtime_plus_authoritative_backend_fixture',
			),
			'PROVIDER_EVIDENCE_RIGHTS_RETENTION_PASS' => array(
				'fixtures' => array( 'raw_denied', 'raw_capped', 'normalized_capped', 'region_denied' ),
				'assertion_count_min' => 4,
				'evidence_class' => 'pure_runtime',
			),
			'DETERMINISTIC_SEARCH_DECISION_POLICY_PASS' => array(
				'fixtures' => array( 'deterministic_digest', 'missing_factor_denied', 'provenance_required', 'stable_tie_break', 'cost_penalty' ),
				'assertion_count_min' => 5,
				'evidence_class' => 'pure_runtime',
			),
			'ADAPTIVE_SEARCH_CROSS_FAULT_ACCEPTANCE_PASS' => array(
				'fixtures' => array( 'provider_drift', 'language_drift', 'surface_drift', 'budget_race', 'partial_capture', 'cache_incomparable', 'uncertain_effect_reconcile' ),
				'assertion_count_min' => 7,
				'evidence_class' => 'composed_fault_runtime',
			),
		);
	}

	public static function evaluate( array $evidence ) {
		$gates = self::gates();
		$results = array();
		$all = true;
		foreach ( $gates as $gate => $definition ) {
			$row = isset( $evidence[ $gate ] ) && is_array( $evidence[ $gate ] ) ? $evidence[ $gate ] : array();
			$fixtures = isset( $row['fixtures'] ) && is_array( $row['fixtures'] ) ? $row['fixtures'] : array();
			$missing = array_values( array_diff( $definition['fixtures'], array_keys( array_filter( $fixtures, static function ( $v ) { return true === $v; } ) ) ) );
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
			'gates' => $results,
			'authorizing' => false,
		);
	}
}
