<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pure, deterministic differential compatibility/chaos evaluation.
 * NEVER invokes a provider, WP ability, HTTP, code, SQL, shell or live objects.
 * Candidates are data fixtures only; findings cannot change policy or authority.
 */
final class MAD4B_SCP_G8_Compatibility_Fuzz {
	const CONTRACT = 'mad4b.g8-compatibility-fuzz.v1';
	const MAX_CASES = 64;
	const MAX_BYTES = 131072;

	private static function identifier( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9_.-]{1,80}$/D', $value );
	}

	private static function plain_data( $value, $depth = 0 ) {
		if ( $depth > 7 || is_object( $value ) || is_resource( $value ) ) return false;
		if ( is_array( $value ) ) {
			if ( count( $value ) > 64 ) return false;
			foreach ( $value as $field => $member ) {
				if ( is_string( $field ) && strlen( $field ) > 100 ) return false;
				if ( ! self::plain_data( $member, $depth + 1 ) ) return false;
			}
			return true;
		}
		return null === $value || is_bool( $value ) || is_int( $value )
			|| ( is_float( $value ) && is_finite( $value ) )
			|| ( is_string( $value ) && strlen( $value ) <= 4096 );
	}

	private static function valid_descriptor( $value ) {
		if ( ! is_array( $value ) || count( $value ) > 16 || ! self::plain_data( $value ) ) return false;
		if ( ! self::identifier( $value['provider'] ?? null ) || ! self::identifier( $value['capability'] ?? null ) ) return false;
		if ( ! in_array( $value['method'] ?? '', array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) return false;
		if ( ! is_bool( $value['readonly'] ?? null ) || ! is_bool( $value['reversible'] ?? null ) ) return false;
		if ( ! in_array( $value['effect'] ?? '', array( 'none', 'local', 'external', 'unknown' ), true ) ) return false;
		if ( ! is_array( $value['schema'] ?? null ) || count( $value['schema'] ) > 32 ) return false;
		if ( ! is_int( $value['risk'] ?? null ) || $value['risk'] < 0 || $value['risk'] > 5 ) return false;
		return strlen( serialize( $value ) ) < 8192;
	}

	private static function fixture_valid( $fixture ) {
		if ( ! is_array( $fixture ) || count( $fixture ) > 12 || ! self::plain_data( $fixture ) ) return false;
		if ( ! in_array( $fixture['fault'] ?? '', array( 'none', 'timeout', 'cancelled', 'duplicate', 'concurrency', 'provider_error', 'malformed_response', 'hidden_effect' ), true ) ) return false;
		if ( ! is_int( $fixture['latency_ms'] ?? null ) || $fixture['latency_ms'] < 0 || $fixture['latency_ms'] > 300000 ) return false;
		if ( ! is_int( $fixture['delivery_count'] ?? null ) || $fixture['delivery_count'] < 0 || $fixture['delivery_count'] > 100 ) return false;
		if ( ! is_bool( $fixture['response_valid'] ?? null ) ) return false;
		return strlen( serialize( $fixture ) ) < 4096;
	}

	/** Deterministic disposable fixtures, no executable callbacks. */
	public static function corpus( $seed, $count = 24 ) {
		if ( ! is_int( $seed ) || $seed < 0 || $seed > 2147483647 || ! is_int( $count ) || $count < 1 || $count > self::MAX_CASES )
			return new WP_Error( 'mad4b_g8_fuzz_seed_invalid', 'Bounded deterministic seed and case count required.' );
		$types = array( 'none', 'timeout', 'cancelled', 'duplicate', 'concurrency', 'provider_error', 'malformed_response', 'hidden_effect' );
		$state = $seed; $cases = array();
		for ( $i = 0; $i < $count; ++$i ) {
			// Safe integer arithmetic and deterministic across 64-bit PHP runtimes.
			$state = ( 1103515245 * $state + 12345 ) % 2147483648;
			$k = (int) ( $state % count( $types ) );
			$cases[] = array( 'fault' => $types[ $k ], 'latency_ms' => $k === 1 ? 300000 : (int) ( $state % 1500 ),
				'delivery_count' => $k === 3 ? 2 : 1, 'response_valid' => $k !== 6 );
		}
		return $cases;
	}

	public static function evaluate( array $context, array $certified, array $candidate, array $fixtures, $seed ) {
		if ( ! class_exists( 'MAD4B_SCP_G8_Record', false ) || ! MAD4B_SCP_G8_Record::staging()
			|| ( $context['mode'] ?? '' ) !== 'disposable'
			|| true !== ( $context['isolated'] ?? false )
			|| false !== ( $context['shared_objects'] ?? true )
			|| false !== ( $context['paid_effects'] ?? true )
			|| false !== ( $context['irreversible_effects'] ?? true ) )
			return new WP_Error( 'mad4b_g8_fuzz_isolation_required', 'Disposable, isolated, non-paid and reversible-only test context required.' );
		if ( ! is_int( $seed ) || $seed < 0 || $seed > 2147483647
			|| ! self::valid_descriptor( $certified ) || ! self::valid_descriptor( $candidate )
			|| ( $candidate['provider'] ?? '' ) !== $certified['provider']
			|| ( $candidate['capability'] ?? '' ) !== $certified['capability']
			|| ! $fixtures || count( $fixtures ) > self::MAX_CASES || strlen( serialize( $fixtures ) ) > self::MAX_BYTES )
			return new WP_Error( 'mad4b_g8_fuzz_contract_invalid', 'Exact matching descriptor and bounded fixtures required.' );

		$findings = array();
		$add = static function ( $type ) use ( &$findings ) {
			if ( ! in_array( $type, $findings, true ) ) $findings[] = $type;
		};
		if ( $candidate['risk'] < $certified['risk'] ) $add( 'candidate_risk_downgrade' );
		if ( $candidate['schema'] !== $certified['schema'] ) $add( 'schema_semantic_diff' );
		if ( $candidate['method'] !== $certified['method'] ) $add( 'method_semantic_diff' );
		if ( 'none' === $candidate['effect'] && 'none' !== $certified['effect'] ) $add( 'hidden_effect_annotation' );
		if ( $candidate['readonly'] && 'none' !== $candidate['effect'] ) $add( 'contradictory_readonly_effect' );
		if ( 'GET' === $candidate['method'] && 'none' !== $candidate['effect'] ) $add( 'get_with_side_effect' );
		if ( $candidate['reversible'] && ! $certified['reversible'] ) $add( 'unproved_reversibility' );
		if ( 'unknown' === $candidate['effect'] ) $add( 'unknown_candidate_effect' );

		$counts = array();
		foreach ( $fixtures as $fixture ) {
			if ( ! self::fixture_valid( $fixture ) )
				return new WP_Error( 'mad4b_g8_fuzz_fixture_invalid', 'Malformed bounded fixture cannot be executed or interpreted.' );
			$fault = $fixture['fault'];
			$counts[ $fault ] = ( $counts[ $fault ] ?? 0 ) + 1;
			if ( 'timeout' === $fault || $fixture['latency_ms'] >= 300000 ) $add( 'timeout_requires_manual_reconciliation' );
			if ( 'cancelled' === $fault ) $add( 'cancelled_generation_fenced' );
			if ( 'duplicate' === $fault || $fixture['delivery_count'] > 1 ) $add( 'duplicate_delivery_idempotency_required' );
			if ( 'concurrency' === $fault ) $add( 'concurrency_prestate_cas_required' );
			if ( 'provider_error' === $fault ) $add( 'provider_error_no_blind_retry' );
			if ( 'malformed_response' === $fault || ! $fixture['response_valid'] ) $add( 'malformed_response_quarantine' );
			if ( 'hidden_effect' === $fault ) $add( 'hidden_effect_quarantine' );
		}
		sort( $findings, SORT_STRING );
		$provenance = array( 'seed' => $seed, 'provider' => $certified['provider'],
			'capability' => $certified['capability'], 'certified' => $certified,
			'candidate' => $candidate, 'fixtures' => $fixtures, 'findings' => $findings );
		return array(
			'contract' => self::CONTRACT,
			'state' => $findings ? 'FINDINGS_REQUIRE_REVIEW' : 'NO_FINDINGS_IN_BOUNDED_FIXTURES',
			'seed' => $seed,
			'case_count' => count( $fixtures ),
			'fault_counts' => $counts,
			'findings' => $findings,
			'quarantine_scope' => $findings ? $certified['provider'] . ':' . $certified['capability'] : '',
			'proposed_canary_only' => true,
			'candidate_active_response_eligible' => false,
			'policy_mutation_performed' => false,
			'provider_executed' => false,
			'external_effect_performed' => false,
			'evidence_sha256' => hash( 'sha256', serialize( $provenance ) ),
			'authorizing' => false,
		);
	}
}
