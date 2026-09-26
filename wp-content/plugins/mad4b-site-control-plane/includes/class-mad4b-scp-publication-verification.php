<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Non-authorizing Publication Verification reducer.
 *
 * It evaluates trusted observations supplied by certified observation adapters.
 * It never publishes, purges cache, infers indexing, or performs network I/O.
 */
final class MAD4B_SCP_Publication_Verification {
	const CONTRACT = 'mad4b.publication-verification.v1';
	const MAX_DIMENSIONS = 32;
	const MAX_PROPAGATION_SECONDS = 86400;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 43 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/publication-verification-evaluate' ) ) return;
		wp_register_ability(
			'mad4b/publication-verification-evaluate',
			array(
				'label' => 'Evaluate Publication Verification',
				'description' => 'Reduce origin/public-edge observations into a non-authorizing verification verdict.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'evaluate' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array(
						'readonly' => true,
						'destructive' => false,
						'idempotent' => true,
					),
				),
			)
		);
	}

	public static function evaluate( $input ) {
		$required = isset( $input['required_dimensions'] ) && is_array( $input['required_dimensions'] )
			? array_values( array_unique( array_map( 'sanitize_key', $input['required_dimensions'] ) ) )
			: array();
		if ( empty( $required ) || count( $required ) > self::MAX_DIMENSIONS ) {
			return new WP_Error( 'mad4b_publication_dimensions_invalid', 'Required publication verification dimensions are invalid.' );
		}

		$origin = self::normalize_observation( $input['origin'] ?? null, $required, 'origin' );
		if ( is_wp_error( $origin ) ) return $origin;
		$edge = self::normalize_observation( $input['public_edge'] ?? null, $required, 'public_edge' );
		if ( is_wp_error( $edge ) ) return $edge;

		$started_at = self::timestamp( $input['verification_started_at'] ?? '' );
		$evaluated_at = self::timestamp( $input['evaluated_at'] ?? '' );
		$timeout = isset( $input['propagation_timeout_seconds'] ) ? (int) $input['propagation_timeout_seconds'] : 300;
		if ( false === $started_at || false === $evaluated_at || $evaluated_at < $started_at || $timeout < 0 || $timeout > self::MAX_PROPAGATION_SECONDS ) {
			return new WP_Error( 'mad4b_publication_timing_invalid', 'Publication verification timing is invalid.' );
		}
		$elapsed = $evaluated_at - $started_at;

		$origin_failures = self::failures( $origin, $required );
		$edge_failures = self::failures( $edge, $required );

		if ( ! empty( $origin_failures ) ) {
			$verdict = 'FAIL';
			$reason = 'origin_mismatch';
		} elseif ( empty( $edge_failures ) ) {
			$verdict = 'PASS';
			$reason = 'origin_and_public_edge_match';
		} elseif ( $elapsed <= $timeout ) {
			$verdict = 'PENDING_PROPAGATION';
			$reason = 'public_edge_not_converged_within_window';
		} else {
			$verdict = 'FAIL';
			$reason = 'public_edge_propagation_timeout';
		}

		$indexing = isset( $input['third_party_indexing_observation'] ) && is_array( $input['third_party_indexing_observation'] )
			? $input['third_party_indexing_observation']
			: array();

		return array(
			'contract' => self::CONTRACT,
			'verdict' => $verdict,
			'reason_code' => $reason,
			'required_dimensions' => $required,
			'origin' => $origin,
			'public_edge' => $edge,
			'origin_failures' => $origin_failures,
			'public_edge_failures' => $edge_failures,
			'propagation_elapsed_seconds' => $elapsed,
			'propagation_timeout_seconds' => $timeout,
			'third_party_indexing' => array(
				'observed' => ! empty( $indexing ),
				'verdict' => ! empty( $indexing['verdict'] ) ? sanitize_key( (string) $indexing['verdict'] ) : 'NOT_OBSERVED',
				'inferred_from_publication' => false,
			),
			'cache_purge_performed' => false,
			'publication_mutation_performed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function normalize_observation( $value, array $required, $surface ) {
		if ( ! is_array( $value ) ) return new WP_Error( 'mad4b_publication_observation_invalid', 'Publication observation is missing: ' . $surface );
		$dimensions = isset( $value['dimensions'] ) && is_array( $value['dimensions'] ) ? $value['dimensions'] : array();
		$out = array();
		foreach ( $required as $dimension ) {
			$row = isset( $dimensions[ $dimension ] ) && is_array( $dimensions[ $dimension ] ) ? $dimensions[ $dimension ] : null;
			if ( null === $row ) {
				$out[ $dimension ] = array( 'verdict' => 'MISSING', 'expected_sha256' => '', 'observed_sha256' => '' );
				continue;
			}
			$verdict = strtoupper( sanitize_key( (string) ( $row['verdict'] ?? '' ) ) );
			if ( ! in_array( $verdict, array( 'PASS', 'FAIL', 'MISSING', 'UNKNOWN' ), true ) ) $verdict = 'UNKNOWN';
			$expected = strtolower( trim( (string) ( $row['expected_sha256'] ?? '' ) ) );
			$observed = strtolower( trim( (string) ( $row['observed_sha256'] ?? '' ) ) );
			if ( '' !== $expected && 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) ) return new WP_Error( 'mad4b_publication_fingerprint_invalid', 'Expected fingerprint is invalid.' );
			if ( '' !== $observed && 1 !== preg_match( '/^[a-f0-9]{64}$/', $observed ) ) return new WP_Error( 'mad4b_publication_fingerprint_invalid', 'Observed fingerprint is invalid.' );
			if ( 'PASS' === $verdict && ( '' === $expected || '' === $observed || ! hash_equals( $expected, $observed ) ) ) {
				$verdict = 'FAIL';
			}
			$out[ $dimension ] = array(
				'verdict' => $verdict,
				'expected_sha256' => $expected,
				'observed_sha256' => $observed,
			);
		}
		return array(
			'surface' => $surface,
			'observed_at' => isset( $value['observed_at'] ) ? (string) $value['observed_at'] : '',
			'observer_ref' => isset( $value['observer_ref'] ) ? substr( sanitize_text_field( (string) $value['observer_ref'] ), 0, 191 ) : '',
			'dimensions' => $out,
		);
	}

	private static function failures( array $observation, array $required ) {
		$out = array();
		foreach ( $required as $dimension ) {
			if ( 'PASS' !== (string) $observation['dimensions'][ $dimension ]['verdict'] ) $out[] = $dimension;
		}
		return $out;
	}

	private static function timestamp( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return false;
		$ts = strtotime( $value );
		return false === $ts ? false : $ts;
	}
}

MAD4B_SCP_Publication_Verification::boot();
