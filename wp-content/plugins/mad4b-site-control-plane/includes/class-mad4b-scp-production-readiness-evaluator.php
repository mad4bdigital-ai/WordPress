<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical runtime evaluator for Production-readiness evidence.
 *
 * The evaluator is strictly read-only/non-authorizing. It validates a complete
 * Staging evidence bundle against the exact live runtime identity and returns a
 * verdict; it never persists evidence, grants Production authority or mutates
 * Production/Staging state.
 */
final class MAD4B_SCP_Production_Readiness_Evaluator {
	const CONTRACT = 'mad4b.production-readiness-evaluator.v1';
	const ABILITY = 'mad4b/production-readiness-evaluate';
	const BUNDLE_CONTRACT = 'mad4b.production-live-evidence-bundle.v1';
	const GATE_CONTRACT = 'mad4b.production-live-gate-evidence.v1';
	const VERDICT_CONTRACT = 'mad4b.production-live-evidence-verdict.v1';
	const MAX_BUNDLE_BYTES = 524288;
	const MAX_EVIDENCE_ROWS = 64;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
		wp_register_ability(
			self::ABILITY,
			array(
				'label' => 'Production Readiness Evaluate',
				'description' => 'Evaluate a complete exact-candidate Staging certification bundle without granting Production authority or mutating state.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'execute' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'bundle' => array( 'type' => 'object', 'additionalProperties' => true ),
					),
					'required' => array( 'bundle' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);
	}

	public static function execute( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$bundle = isset( $input['bundle'] ) && is_array( $input['bundle'] ) ? $input['bundle'] : array();
		$identity = self::current_candidate_identity();
		if ( is_wp_error( $identity ) ) return $identity;
		return self::evaluate_against_identity( $bundle, $identity, self::current_environment() );
	}

	public static function evaluate_against_identity( array $bundle, array $current_identity, $environment ) {
		$environment = sanitize_key( (string) $environment );
		if ( 'staging' !== $environment ) return self::error( 'mad4b_production_readiness_environment_not_staging' );

		$encoded = wp_json_encode( $bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_BUNDLE_BYTES ) {
			return self::error( 'mad4b_production_readiness_bundle_oversized' );
		}
		if ( self::BUNDLE_CONTRACT !== ( isset( $bundle['contract'] ) ? (string) $bundle['contract'] : '' )
			|| 'control_plane_core' !== ( isset( $bundle['profile'] ) ? (string) $bundle['profile'] : '' )
			|| 'staging' !== ( isset( $bundle['environment'] ) ? (string) $bundle['environment'] : '' ) ) {
			return self::error( 'mad4b_production_readiness_bundle_scope_invalid' );
		}
		if ( isset( $bundle['authorizing'] ) && false !== $bundle['authorizing'] ) return self::error( 'mad4b_production_readiness_bundle_authority_widened' );
		if ( isset( $bundle['production_authorized'] ) && false !== $bundle['production_authorized'] ) return self::error( 'mad4b_production_readiness_bundle_authority_widened' );

		$identity = self::normalize_identity( isset( $bundle['candidate_identity'] ) && is_array( $bundle['candidate_identity'] ) ? $bundle['candidate_identity'] : array() );
		$current_identity = self::normalize_identity( $current_identity );
		if ( is_wp_error( $identity ) || is_wp_error( $current_identity ) ) return self::error( 'mad4b_production_readiness_candidate_identity_invalid' );
		if ( ! self::identity_equal( $identity, $current_identity ) ) {
			return self::error( 'mad4b_production_readiness_runtime_identity_mismatch', array( 'fresh_certification_required' => true ) );
		}

		$plan = self::load_config( 'config/production-certification-plan.json', 'mad4b.production-certification-plan.v1' );
		if ( is_wp_error( $plan ) ) return $plan;
		$policy = self::load_config( 'config/production-readiness-policy.json', 'mad4b.production-readiness-policy.v2' );
		if ( is_wp_error( $policy ) ) return $policy;

		$stages = isset( $plan['stages'] ) && is_array( $plan['stages'] ) ? $plan['stages'] : array();
		$stage_by_gate = array();
		foreach ( $stages as $stage ) {
			if ( ! is_array( $stage ) ) continue;
			$gate = isset( $stage['gate'] ) ? sanitize_key( (string) $stage['gate'] ) : '';
			if ( '' === $gate || isset( $stage_by_gate[ $gate ] ) ) return self::error( 'mad4b_production_readiness_plan_gate_invalid' );
			$stage_by_gate[ $gate ] = $stage;
		}
		if ( empty( $stage_by_gate ) ) return self::error( 'mad4b_production_readiness_plan_empty' );

		$rows = isset( $bundle['evidence'] ) && is_array( $bundle['evidence'] ) ? $bundle['evidence'] : array();
		if ( count( $rows ) > self::MAX_EVIDENCE_ROWS ) return self::error( 'mad4b_production_readiness_evidence_budget_exceeded' );
		$by_gate = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || self::GATE_CONTRACT !== ( isset( $row['contract'] ) ? (string) $row['contract'] : '' ) ) {
				return self::error( 'mad4b_production_readiness_gate_contract_invalid' );
			}
			$gate = isset( $row['gate'] ) ? sanitize_key( (string) $row['gate'] ) : '';
			if ( '' === $gate || ! isset( $stage_by_gate[ $gate ] ) ) return self::error( 'mad4b_production_readiness_gate_unscoped' );
			if ( isset( $by_gate[ $gate ] ) ) return self::error( 'mad4b_production_readiness_gate_duplicated' );
			$by_gate[ $gate ] = true;
			$stage = $stage_by_gate[ $gate ];

			if ( 'staging' !== ( isset( $row['environment'] ) ? (string) $row['environment'] : '' )
				|| true === ( isset( $row['production_mutation'] ) ? $row['production_mutation'] : false )
				|| true === ( isset( $row['authorizing'] ) ? $row['authorizing'] : false )
				|| true !== ( isset( $row['ready'] ) ? $row['ready'] : false ) ) {
				return self::error( 'mad4b_production_readiness_gate_scope_invalid', array( 'gate' => $gate ) );
			}
			$row_identity = self::normalize_identity( isset( $row['candidate_identity'] ) && is_array( $row['candidate_identity'] ) ? $row['candidate_identity'] : array() );
			if ( is_wp_error( $row_identity ) || ! self::identity_equal( $identity, $row_identity ) ) {
				return self::error( 'mad4b_production_readiness_gate_identity_mismatch', array( 'gate' => $gate ) );
			}
			$producer = isset( $row['producer'] ) ? (string) $row['producer'] : '';
			if ( '' === $producer || $producer !== (string) ( isset( $stage['producer'] ) ? $stage['producer'] : '' ) ) {
				return self::error( 'mad4b_production_readiness_gate_producer_mismatch', array( 'gate' => $gate ) );
			}
			$producer_evidence = isset( $row['producer_evidence'] ) && is_array( $row['producer_evidence'] ) ? $row['producer_evidence'] : array();
			$producer_contract = isset( $row['producer_contract'] ) ? (string) $row['producer_contract'] : '';
			$evidence_contract = isset( $producer_evidence['contract'] ) ? (string) $producer_evidence['contract'] : '';
			if ( '' === $producer_contract || $producer_contract !== $evidence_contract || self::GATE_CONTRACT === $producer_contract ) {
				return self::error( 'mad4b_production_readiness_producer_evidence_contract_invalid', array( 'gate' => $gate ) );
			}
			$digest = isset( $row['producer_evidence_sha256'] ) ? strtolower( trim( (string) $row['producer_evidence_sha256'] ) ) : '';
			$actual_digest = self::canonical_digest( $producer_evidence );
			if ( is_wp_error( $actual_digest ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $digest ) || ! hash_equals( $digest, $actual_digest ) ) {
				return self::error( 'mad4b_production_readiness_producer_evidence_digest_mismatch', array( 'gate' => $gate ) );
			}
			foreach ( array( 'production_mutation', 'production_authorized', 'authorizing' ) as $authority_key ) {
				if ( true === ( isset( $producer_evidence[ $authority_key ] ) ? $producer_evidence[ $authority_key ] : false ) ) {
					return self::error( 'mad4b_production_readiness_producer_authority_widened', array( 'gate' => $gate ) );
				}
			}
			if ( isset( $producer_evidence['candidate_identity'] ) && is_array( $producer_evidence['candidate_identity'] ) ) {
				$embedded = self::normalize_identity( $producer_evidence['candidate_identity'] );
				if ( is_wp_error( $embedded ) || ! self::identity_equal( $identity, $embedded ) ) {
					return self::error( 'mad4b_production_readiness_producer_identity_mismatch', array( 'gate' => $gate ) );
				}
			}
			$expected_contract = isset( $stage['evidence_contract'] ) ? (string) $stage['evidence_contract'] : '';
			if ( self::GATE_CONTRACT !== $expected_contract && $expected_contract !== $producer_contract ) {
				return self::error( 'mad4b_production_readiness_producer_contract_mismatch', array( 'gate' => $gate ) );
			}

			$class = isset( $stage['mutation_class'] ) ? sanitize_key( (string) $stage['mutation_class'] ) : '';
			if ( 'read_only' === $class ) {
				if ( false !== ( isset( $row['mutation_performed'] ) ? $row['mutation_performed'] : null ) ) {
					return self::error( 'mad4b_production_readiness_read_only_mutated', array( 'gate' => $gate ) );
				}
			} elseif ( 'reversible_staging_mutation' === $class ) {
				if ( true !== ( isset( $row['mutation_performed'] ) ? $row['mutation_performed'] : false )
					|| true !== ( isset( $row['rollback_verified'] ) ? $row['rollback_verified'] : false )
					|| true !== ( isset( $row['postcondition_verified'] ) ? $row['postcondition_verified'] : false ) ) {
					return self::error( 'mad4b_production_readiness_reversible_evidence_incomplete', array( 'gate' => $gate ) );
				}
			} else {
				return self::error( 'mad4b_production_readiness_mutation_class_invalid', array( 'gate' => $gate ) );
			}
		}

		$missing = array_values( array_diff( array_keys( $stage_by_gate ), array_keys( $by_gate ) ) );
		if ( ! empty( $missing ) ) return self::error( 'mad4b_production_readiness_gate_missing', array( 'gates' => $missing ) );

		$core = isset( $policy['profiles']['control_plane_core'] ) && is_array( $policy['profiles']['control_plane_core'] )
			? $policy['profiles']['control_plane_core']
			: array();
		$optional = isset( $core['optional_workstream_ids'] ) && is_array( $core['optional_workstream_ids'] ) ? array_map( 'strval', $core['optional_workstream_ids'] ) : array();
		$disabled = isset( $bundle['optional_capabilities_disabled'] ) && is_array( $bundle['optional_capabilities_disabled'] ) ? array_map( 'strval', $bundle['optional_capabilities_disabled'] ) : array();
		sort( $optional, SORT_STRING );
		sort( $disabled, SORT_STRING );
		if ( $optional !== $disabled ) return self::error( 'mad4b_production_readiness_optional_fail_closed_set_invalid' );

		return array(
			'contract' => self::VERDICT_CONTRACT,
			'profile' => 'control_plane_core',
			'candidate_identity' => $identity,
			'evidence_gate_count' => count( $stage_by_gate ),
			'production_ready' => true,
			'full_feature007_complete' => false,
			'production_authorized' => false,
			'promotion_required' => true,
			'authorizing' => false,
			'mutation_performed' => false,
			'production_mutation' => false,
		);
	}

	public static function canonical_digest( $value ) {
		$normalized = self::canonicalize( $value, 0 );
		if ( is_wp_error( $normalized ) ) return $normalized;
		$json = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) return self::error( 'mad4b_production_readiness_canonical_json_failed' );
		return hash( 'sha256', $json );
	}

	private static function canonicalize( $value, $depth ) {
		if ( $depth > 32 ) return self::error( 'mad4b_production_readiness_canonical_depth_exceeded' );
		if ( is_array( $value ) ) {
			$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				$out = array();
				foreach ( $value as $item ) {
					$next = self::canonicalize( $item, $depth + 1 );
					if ( is_wp_error( $next ) ) return $next;
					$out[] = $next;
				}
				return $out;
			}
			$keys = array_keys( $value );
			sort( $keys, SORT_STRING );
			$out = array();
			foreach ( $keys as $key ) {
				$next = self::canonicalize( $value[ $key ], $depth + 1 );
				if ( is_wp_error( $next ) ) return $next;
				$out[ (string) $key ] = $next;
			}
			return $out;
		}
		if ( is_string( $value ) || is_int( $value ) || is_bool( $value ) || null === $value ) return $value;
		if ( is_float( $value ) && is_finite( $value ) ) return $value;
		return self::error( 'mad4b_production_readiness_canonical_value_invalid' );
	}

	private static function current_candidate_identity() {
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_identity_status' ) ) {
			return self::error( 'mad4b_production_readiness_runtime_identity_unavailable' );
		}
		$status = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status();
		if ( ! is_array( $status ) || empty( $status['identity_ready'] ) ) return self::error( 'mad4b_production_readiness_runtime_identity_not_ready' );
		return self::normalize_identity( $status );
	}

	private static function normalize_identity( array $source ) {
		$identity = array(
			'source_commit_sha' => strtolower( trim( (string) ( isset( $source['source_commit_sha'] ) ? $source['source_commit_sha'] : '' ) ) ),
			'build_fingerprint' => strtolower( trim( (string) ( isset( $source['build_fingerprint'] ) ? $source['build_fingerprint'] : '' ) ) ),
			'package_manifest_digest' => strtolower( trim( (string) ( isset( $source['package_manifest_digest'] ) ? $source['package_manifest_digest'] : '' ) ) ),
		);
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $identity['source_commit_sha'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $identity['build_fingerprint'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $identity['package_manifest_digest'] ) ) {
			return self::error( 'mad4b_production_readiness_candidate_identity_invalid' );
		}
		return $identity;
	}

	private static function identity_equal( array $left, array $right ) {
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $key ) {
			if ( ! isset( $left[ $key ], $right[ $key ] ) || ! hash_equals( (string) $left[ $key ], (string) $right[ $key ] ) ) return false;
		}
		return true;
	}

	private static function current_environment() {
		if ( class_exists( 'MAD4B_SCP_Environment' ) && method_exists( 'MAD4B_SCP_Environment', 'effective' ) ) {
			return sanitize_key( (string) MAD4B_SCP_Environment::effective() );
		}
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}

	private static function load_config( $relative, $contract ) {
		$path = MAD4B_SCP_DIR . ltrim( (string) $relative, '/' );
		if ( ! is_readable( $path ) || is_link( $path ) ) return self::error( 'mad4b_production_readiness_config_unavailable' );
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) || strlen( $raw ) > 1048576 ) return self::error( 'mad4b_production_readiness_config_invalid' );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || (string) ( isset( $data['contract'] ) ? $data['contract'] : '' ) !== (string) $contract ) {
			return self::error( 'mad4b_production_readiness_config_contract_invalid' );
		}
		return $data;
	}

	private static function error( $code, array $data = array() ) {
		$data = array_merge(
			array(
				'production_ready' => false,
				'production_authorized' => false,
				'promotion_required' => true,
				'authorizing' => false,
				'mutation_performed' => false,
				'production_mutation' => false,
				'blind_retry_allowed' => false,
			),
			$data
		);
		return new WP_Error( sanitize_key( (string) $code ), 'Production readiness evidence is incomplete or invalid.', $data );
	}
}
