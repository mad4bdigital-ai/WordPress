<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Deterministic bounded baseline: no opaque model or business-specific weights. */
final class MAD4B_SCP_Search_Decisions {
	public static function score( array $target, array $policy, array $factors, array $history, $now ) {
		$values = array(); $provenance = array(); $score = 1.0; $reasons = array();
		foreach ( $policy['weights'] as $name => $weight ) {
			$factor = isset( $factors[ $name ] ) && is_array( $factors[ $name ] ) ? $factors[ $name ] : array();
			$valid = isset( $factor['value'], $factor['source'], $factor['observed_at'], $factor['expires_at'], $factor['confidence'], $factor['normalization_version'], $factor['market'], $factor['language'] ) && is_numeric( $factor['value'] ) && $factor['value'] >= 0 && $factor['value'] <= 1 && $factor['observed_at'] <= $now && $factor['expires_at'] >= $now && $factor['market'] === $target['market'] && $factor['language'] === $target['language'];
			$valid = $valid && is_finite( (float) $factor['value'] ) && is_string( $factor['source'] ) && '' !== trim( $factor['source'] ) && is_string( $factor['normalization_version'] ) && '' !== trim( $factor['normalization_version'] ) && is_numeric( $factor['confidence'] ) && is_finite( (float) $factor['confidence'] ) && $factor['confidence'] >= 0 && $factor['confidence'] <= 1 && is_int( $factor['observed_at'] ) && is_int( $factor['expires_at'] );
			$value = $valid ? (float) $factor['value'] : (float) $policy['missing_value'];
			$values[ $name ] = $value;
			$provenance[ $name ] = $valid ? $factor : array( 'source' => 'policy_missing_value', 'value' => $value, 'normalization_version' => $policy['version'], 'market' => $target['market'], 'language' => $target['language'], 'confidence' => 0, 'observed_at' => $now, 'expires_at' => $now );
			$score *= pow( max( 0.001, $value ), (float) $weight );
			$reasons[] = array( 'factor' => $name, 'value' => $value, 'weight' => $weight, 'source' => $provenance[ $name ]['source'] );
		}
		$cost = isset( $history['estimated_cost_micro'] ) ? $history['estimated_cost_micro'] : 1;
		if ( ! is_numeric( $cost ) || $cost < 0 ) return MAD4B_SCP_Search_Contracts::error( 'decision_cost_unknown' );
		$never = empty( $history['last_observed'] );
		$age = max( 0, $now - (int) ( ! $never ? $history['last_observed'] : ( isset( $history['queued_at'] ) ? $history['queued_at'] : $now ) ) );
		$bonus = ( $never ? 1 : 0 ) + $age / max( 1, (int) $policy['aging_seconds'] );
		$penalty = max( 0, min( 1, isset( $history['duplicate_penalty'] ) ? $history['duplicate_penalty'] : 0 ) );
		$score = round( 1000000 * $score * ( 1 - $penalty ) / max( 1, $cost ) + $bonus, 9 );
		return array( 'contract' => 'mad4b.search-decision.v1', 'target_id' => $target['target_id'], 'policy_version' => $policy['version'], 'effective_priority' => $score, 'positive_factors' => $reasons, 'negative_factors' => array( 'expected_cost_micro' => $cost, 'duplicate_penalty' => isset( $history['duplicate_penalty'] ) ? $history['duplicate_penalty'] : 0 ), 'factor_provenance' => $provenance, 'aging_contribution' => $bonus, 'never_checked' => $never, 'context_fingerprint' => isset( $target['context_fingerprint'] ) ? $target['context_fingerprint'] : '', 'authorizing' => false );
	}

	public static function refresh( array $policy, array $trajectory, array $factors ) {
		$volatility = max( 0, min( 1, isset( $trajectory['volatility'] ) ? $trajectory['volatility'] / 100 : 0 ) );
		$value = isset( $factors['business_value']['value'] ) ? max( 0, min( 1, $factors['business_value']['value'] ) ) : 0.5;
		$confidence = isset( $trajectory['confidence'] ) ? max( 0, min( 1, $trajectory['confidence'] ) ) : 0;
		$changes = ! empty( $trajectory['fingerprint_changed'] ) || ! empty( $trajectory['ranking_loss'] ) ? 4 : 1;
		$seconds = (int) round( $policy['baseline_seconds'] * ( 0.5 + $confidence ) / ( ( 0.5 + $value + $volatility ) * $changes ) );
		return max( (int) $policy['min_seconds'], min( (int) $policy['max_seconds'], $seconds ) );
	}

	public static function equivalent( array $snapshot, array $request, $comparability_key, $max_age, $now ) {
		return isset( $snapshot['request_identity'], $snapshot['observation_context']['comparability_key'], $snapshot['captured_at'], $snapshot['valid_until'], $snapshot['completeness']['state'] ) && hash_equals( (string) $snapshot['request_identity'], MAD4B_SCP_Search_Contracts::digest( $request ) ) && hash_equals( (string) $snapshot['observation_context']['comparability_key'], $comparability_key ) && $snapshot['valid_until'] > $now && $snapshot['captured_at'] <= $now && $now - $snapshot['captured_at'] <= $max_age && 'complete' === $snapshot['completeness']['state'];
	}

	public static function batch( array $context, array $decisions, $limit, array $coverage, $now ) {
		$dimensions = $context['effective']['priority_policy']['fairness_dimensions'];
		$pending = $decisions; $selected = array(); $excluded = array();
		while ( $pending && count( $selected ) < max( 0, min( 200, (int) $limit ) ) ) {
			usort( $pending, static function ( $a, $b ) use ( $coverage, $dimensions ) {
				// Oldest observation wins within a coverage cohort. Bounded EVO cannot
				// perpetually displace an eligible target that has never been observed.
				$fairness = static function ( $row ) use ( $coverage, $dimensions ) {
					$count = 0; foreach ( $dimensions as $dimension ) { $value = isset( $row['target'][ $dimension ] ) ? $row['target'][ $dimension ] : ''; $count += isset( $coverage[ $dimension ][ $value ] ) ? $coverage[ $dimension ][ $value ] : 0; } return $count;
				};
				$compare = $fairness( $a ) <=> $fairness( $b );
				if ( 0 !== $compare ) return $compare;
				$compare = ( isset( $a['last_observed'] ) ? $a['last_observed'] : 0 ) <=> ( isset( $b['last_observed'] ) ? $b['last_observed'] : 0 );
				if ( 0 !== $compare ) return $compare;
				$compare = $b['decision']['effective_priority'] <=> $a['decision']['effective_priority'];
				return 0 !== $compare ? $compare : strcmp( $a['target']['target_id'], $b['target']['target_id'] );
			} );
			$row = array_shift( $pending );
			if ( ! empty( $row['muted'] ) || empty( $row['provider_eligible'] ) || ( isset( $row['due'] ) && ! $row['due'] ) ) { $excluded[] = array( 'target_id' => $row['target']['target_id'], 'reason' => ! empty( $row['muted'] ) ? 'muted' : ( empty( $row['provider_eligible'] ) ? 'provider_ineligible' : 'fresh_evidence' ) ); continue; }
			$selected[] = $row;
			foreach ( $dimensions as $d ) { $value = isset( $row['target'][ $d ] ) ? $row['target'][ $d ] : ''; $coverage[ $d ][ $value ] = ( isset( $coverage[ $d ][ $value ] ) ? $coverage[ $d ][ $value ] : 0 ) + 1; }
		}
		foreach ( $pending as $row ) $excluded[] = array( 'target_id' => $row['target']['target_id'], 'reason' => 'cohort_limit' );
		$batch = array( 'contract' => 'mad4b.search-batch.v1', 'objective' => $context['effective']['objective'], 'selection_policy' => 'coverage_then_evo_then_identity.v1', 'selected' => $selected, 'excluded' => $excluded, 'coverage_after' => $coverage, 'profile_fingerprint' => $context['dependencies']['PROFILE'], 'policy_fingerprints' => $context['dependencies'], 'budget_envelope' => $context['effective']['budget_policy'], 'created_at' => $now, 'authorizing' => false );
		$batch['batch_id'] = MAD4B_SCP_Search_Contracts::digest( $batch ); return $batch;
	}

	public static function pacing( $remaining, $reset_at, $now, $burst_multiplier = 1 ) {
		if ( ! is_numeric( $remaining ) || $remaining < 0 || $reset_at <= $now || $burst_multiplier < 1 || $burst_multiplier > 10 ) return MAD4B_SCP_Search_Contracts::error( 'pacing_unknown' );
		return array( 'per_day' => (int) floor( $remaining * 86400 / max( 86400, $reset_at - $now ) ), 'burst_limit' => (int) floor( $remaining * min( 1, 86400 / ( $reset_at - $now ) ) * $burst_multiplier ), 'reset_at' => $reset_at, 'authorizing' => false );
	}
}
