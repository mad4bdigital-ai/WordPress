<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic v1 Expected-Value-of-Observation policy.
 */
final class MAD4B_SCP_Search_Decision_Policy {
	const CONTRACT = 'mad4b.search-decision-policy.v1';
	const POLICY_VERSION = 'mad4b.search-evo-deterministic.v1';

	public static function default_policy() {
		return array(
			'contract' => self::CONTRACT,
			'policy_version' => self::POLICY_VERSION,
			'missing_value_policy' => 'deny',
			'factors' => array(
				'business_value' => array( 'weight' => 25, 'direction' => 'positive', 'required' => true ),
				'information_gain' => array( 'weight' => 25, 'direction' => 'positive', 'required' => true ),
				'urgency' => array( 'weight' => 15, 'direction' => 'positive', 'required' => true ),
				'actionability' => array( 'weight' => 10, 'direction' => 'positive', 'required' => true ),
				'change_probability' => array( 'weight' => 10, 'direction' => 'positive', 'required' => true ),
				'confidence_need' => array( 'weight' => 10, 'direction' => 'positive', 'required' => true ),
				'expected_cost' => array( 'weight' => 5, 'direction' => 'negative', 'required' => true ),
			),
			'tie_break' => 'target_identity_lexical_asc',
		);
	}

	private static function stable( $value ) {
		if ( is_array( $value ) ) {
			$list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $list ) ksort( $value, SORT_STRING );
			foreach ( $value as $key => $item ) $value[ $key ] = self::stable( $item );
		}
		return $value;
	}

	private static function digest( $value ) {
		return hash( 'sha256', wp_json_encode( self::stable( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function factor_observation( $name, $input ) {
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_search_decision_factor_invalid', 'Decision factor observation must be an object.', array( 'factor' => $name ) );
		$value = isset( $input['value'] ) ? (float) $input['value'] : -1;
		if ( $value < 0 || $value > 100 ) return new WP_Error( 'mad4b_search_decision_factor_range_invalid', 'Decision factor value must be within 0..100.', array( 'factor' => $name ) );
		$source = sanitize_text_field( (string) ( $input['source'] ?? '' ) );
		$observed = sanitize_text_field( (string) ( $input['observed_at'] ?? '' ) );
		$confidence = isset( $input['confidence'] ) ? (float) $input['confidence'] : -1;
		$normalization = sanitize_text_field( (string) ( $input['normalization_version'] ?? '' ) );
		if ( '' === $source || '' === $observed || '' === $normalization || $confidence < 0 || $confidence > 1 ) {
			return new WP_Error( 'mad4b_search_decision_factor_provenance_required', 'Decision factor requires source, observed_at, confidence and normalization_version.', array( 'factor' => $name ) );
		}
		return array(
			'value' => round( $value, 6 ),
			'source' => $source,
			'observed_at' => $observed,
			'confidence' => round( $confidence, 6 ),
			'normalization_version' => $normalization,
			'market' => strtoupper( trim( (string) ( $input['market'] ?? '' ) ) ),
			'language' => strtolower( trim( (string) ( $input['language'] ?? '' ) ) ),
		);
	}

	public static function evaluate( array $candidate, array $policy = array() ) {
		$policy = empty( $policy ) ? self::default_policy() : $policy;
		$target_id = trim( (string) ( $candidate['target_id'] ?? '' ) );
		if ( '' === $target_id ) return new WP_Error( 'mad4b_search_decision_target_required', 'Decision candidate requires target_id.' );
		$schema = isset( $policy['factors'] ) && is_array( $policy['factors'] ) ? $policy['factors'] : array();
		if ( empty( $schema ) ) return new WP_Error( 'mad4b_search_decision_policy_invalid', 'Decision policy has no factors.' );
		$input_factors = isset( $candidate['factors'] ) && is_array( $candidate['factors'] ) ? $candidate['factors'] : array();
		$factor_rows = array();
		$weighted = 0.0;
		$weight_total = 0.0;
		foreach ( $schema as $name => $definition ) {
			$name = sanitize_key( (string) $name );
			if ( ! array_key_exists( $name, $input_factors ) ) {
				if ( ! empty( $definition['required'] ) || 'deny' === (string) ( $policy['missing_value_policy'] ?? 'deny' ) ) {
					return new WP_Error( 'mad4b_search_decision_factor_missing', 'Required decision factor is missing.', array( 'factor' => $name ) );
				}
				continue;
			}
			$observation = self::factor_observation( $name, $input_factors[ $name ] );
			if ( is_wp_error( $observation ) ) return $observation;
			$weight = abs( (float) ( $definition['weight'] ?? 0 ) );
			if ( $weight <= 0 || $weight > 1000 ) return new WP_Error( 'mad4b_search_decision_weight_invalid', 'Decision factor weight is invalid.', array( 'factor' => $name ) );
			$direction = sanitize_key( (string) ( $definition['direction'] ?? 'positive' ) );
			if ( ! in_array( $direction, array( 'positive', 'negative' ), true ) ) return new WP_Error( 'mad4b_search_decision_direction_invalid', 'Decision factor direction is invalid.', array( 'factor' => $name ) );
			$signed = ( 'negative' === $direction ? -1 : 1 ) * ( $observation['value'] / 100 ) * $weight;
			$weighted += $signed;
			$weight_total += $weight;
			$factor_rows[ $name ] = $observation + array(
				'weight' => $weight,
				'direction' => $direction,
				'weighted_contribution' => round( $signed, 8 ),
			);
		}
		if ( $weight_total <= 0 ) return new WP_Error( 'mad4b_search_decision_weight_total_invalid', 'Decision policy total weight is zero.' );
		$score = max( 0, min( 100, 50 + ( 50 * ( $weighted / $weight_total ) ) ) );
		$policy_material = $policy;
		$policy_sha = self::digest( $policy_material );
		$result = array(
			'contract' => self::CONTRACT,
			'policy_version' => sanitize_text_field( (string) ( $policy['policy_version'] ?? self::POLICY_VERSION ) ),
			'policy_sha256' => $policy_sha,
			'target_id' => $target_id,
			'score' => round( $score, 6 ),
			'factors' => $factor_rows,
			'tie_break_key' => $target_id,
			'tie_break_policy' => 'target_identity_lexical_asc',
			'authorizing' => false,
		);
		$result['decision_sha256'] = self::digest( $result );
		return $result;
	}

	public static function rank( array $candidates, array $policy = array() ) {
		$rows = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) return new WP_Error( 'mad4b_search_decision_candidate_invalid', 'Decision candidates must be objects.' );
			$row = self::evaluate( $candidate, $policy );
			if ( is_wp_error( $row ) ) return $row;
			$rows[] = $row;
		}
		usort( $rows, static function ( $a, $b ) {
			if ( (float) $a['score'] === (float) $b['score'] ) return strcmp( (string) $a['tie_break_key'], (string) $b['tie_break_key'] );
			return (float) $a['score'] > (float) $b['score'] ? -1 : 1;
		} );
		foreach ( $rows as $index => &$row ) $row['rank'] = $index + 1;
		unset( $row );
		return array( 'contract' => self::CONTRACT, 'policy_version' => $rows ? $rows[0]['policy_version'] : self::POLICY_VERSION, 'items' => $rows, 'authorizing' => false );
	}
}
