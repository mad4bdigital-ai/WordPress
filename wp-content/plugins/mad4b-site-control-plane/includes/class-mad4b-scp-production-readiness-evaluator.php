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
	const EVIDENCE_TRUST_CONTRACT = 'mad4b.production-evidence-trust.v1';
	const EVIDENCE_ATTESTATION_CONTRACT = 'mad4b.production-evidence-attestation.v1';
	const EVIDENCE_CRYPTO_PURPOSE = 'production_evidence';
	const EVIDENCE_TTL = 1800;
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
		$trusted_gates = array();
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

			$trust = self::evidence_trust_verify( $row, $stage, $identity );
			if ( is_wp_error( $trust ) ) return $trust;
			$trusted_gates[ $gate ] = $trust;
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
		if ( count( $trusted_gates ) !== count( $stage_by_gate ) ) return self::error( 'mad4b_production_readiness_trusted_gate_count_mismatch' );

		return array(
			'contract' => self::VERDICT_CONTRACT,
			'profile' => 'control_plane_core',
			'candidate_identity' => $identity,
			'evidence_gate_count' => count( $stage_by_gate ),
			'trusted_evidence_gate_count' => count( $trusted_gates ),
			'evidence_trust_contract' => self::EVIDENCE_TRUST_CONTRACT,
			'production_ready' => true,
			'full_feature007_complete' => false,
			'production_authorized' => false,
			'promotion_required' => true,
			'authorizing' => false,
			'mutation_performed' => false,
			'production_mutation' => false,
		);
	}


	private static function evidence_trust_verify( array $row, array $stage, array $identity ) {
		$valid = self::evidence_trust_validate_bindings( $row, $stage, $identity );
		if ( is_wp_error( $valid ) ) return $valid;
		$mode = isset( $stage['trust_mode'] ) ? sanitize_key( (string) $stage['trust_mode'] ) : '';
		if ( 'runtime_recompute' === $mode ) return self::evidence_trust_runtime_recompute( $row, $stage, $identity );
		if ( 'signed_attestation' === $mode ) return self::evidence_trust_signed_attestation( $row, $stage, $identity );
		return self::error( 'mad4b_production_readiness_trust_mode_invalid', array( 'stage_id' => isset( $stage['id'] ) ? (string) $stage['id'] : '' ) );
	}

	/**
	 * Internal verifier-adapter helper. It is deliberately not registered as an
	 * Ability/MCP tool and never grants Production authority.
	 */
	public static function seal_verified_evidence( array $row, array $stage, array $identity ) {
		$valid = self::evidence_trust_validate_bindings( $row, $stage, $identity );
		if ( is_wp_error( $valid ) ) return $valid;
		if ( 'signed_attestation' !== sanitize_key( isset( $stage['trust_mode'] ) ? (string) $stage['trust_mode'] : '' ) ) {
			return self::error( 'mad4b_production_readiness_attestation_not_required' );
		}
		$method_check = self::evidence_trust_verify_method_evidence( $row, $stage, $identity );
		if ( is_wp_error( $method_check ) ) return $method_check;
		if ( ! class_exists( 'MAD4B_SCP_Crypto_Profile' ) ) return self::error( 'mad4b_production_readiness_evidence_crypto_unavailable' );
		$issued_at = self::evidence_trust_now();
		$ttl = self::evidence_trust_ttl();
		$claim = self::evidence_trust_claim( $row, $stage, $identity, $issued_at, $issued_at + $ttl );
		$digest = self::canonical_digest( $claim );
		if ( is_wp_error( $digest ) ) return $digest;
		$signature = MAD4B_SCP_Crypto_Profile::sign_digest_for_purpose( self::EVIDENCE_CRYPTO_PURPOSE, $digest );
		if ( is_wp_error( $signature ) ) return $signature;
		return array(
			'contract' => self::EVIDENCE_ATTESTATION_CONTRACT,
			'claim' => $claim,
			'claim_sha256' => $digest,
			'signature' => $signature,
			'authorizing' => false,
		);
	}

	private static function evidence_trust_validate_bindings( array $row, array $stage, array $identity ) {
		$stage_id = isset( $stage['id'] ) ? sanitize_key( (string) $stage['id'] ) : '';
		$gate = isset( $stage['gate'] ) ? sanitize_key( (string) $stage['gate'] ) : '';
		$producer = isset( $stage['producer'] ) ? (string) $stage['producer'] : '';
		$producer_contract = isset( $stage['producer_contract'] ) ? (string) $stage['producer_contract'] : '';
		$mode = isset( $stage['trust_mode'] ) ? sanitize_key( (string) $stage['trust_mode'] ) : '';
		$method = isset( $stage['attestation_method'] ) ? sanitize_key( (string) $stage['attestation_method'] ) : '';
		if ( '' === $stage_id || '' === $gate || '' === $producer || '' === $producer_contract || '' === $mode || '' === $method ) {
			return self::error( 'mad4b_production_readiness_stage_trust_binding_missing', array( 'stage_id' => $stage_id ) );
		}
		if ( self::GATE_CONTRACT !== ( isset( $row['contract'] ) ? (string) $row['contract'] : '' )
			|| 'staging' !== ( isset( $row['environment'] ) ? (string) $row['environment'] : '' )
			|| $gate !== sanitize_key( isset( $row['gate'] ) ? (string) $row['gate'] : '' )
			|| $producer !== ( isset( $row['producer'] ) ? (string) $row['producer'] : '' )
			|| $producer_contract !== ( isset( $row['producer_contract'] ) ? (string) $row['producer_contract'] : '' ) ) {
			return self::error( 'mad4b_production_readiness_stage_trust_binding_mismatch', array( 'stage_id' => $stage_id ) );
		}
		$row_identity = self::normalize_identity( isset( $row['candidate_identity'] ) && is_array( $row['candidate_identity'] ) ? $row['candidate_identity'] : array() );
		if ( is_wp_error( $row_identity ) || ! self::identity_equal( $identity, $row_identity ) ) {
			return self::error( 'mad4b_production_readiness_stage_trust_identity_mismatch', array( 'stage_id' => $stage_id ) );
		}
		$evidence = isset( $row['producer_evidence'] ) && is_array( $row['producer_evidence'] ) ? $row['producer_evidence'] : array();
		if ( $producer_contract !== ( isset( $evidence['contract'] ) ? (string) $evidence['contract'] : '' ) ) {
			return self::error( 'mad4b_production_readiness_exact_producer_contract_mismatch', array( 'stage_id' => $stage_id ) );
		}
		$digest = isset( $row['producer_evidence_sha256'] ) ? strtolower( trim( (string) $row['producer_evidence_sha256'] ) ) : '';
		$actual = self::canonical_digest( $evidence );
		if ( is_wp_error( $actual ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest ) || ! hash_equals( $digest, $actual ) ) {
			return self::error( 'mad4b_production_readiness_producer_evidence_digest_mismatch', array( 'stage_id' => $stage_id ) );
		}
		foreach ( array( 'production_mutation', 'production_authorized', 'authorizing' ) as $key ) {
			if ( true === ( isset( $evidence[ $key ] ) ? $evidence[ $key ] : false ) ) {
				return self::error( 'mad4b_production_readiness_producer_authority_widened', array( 'stage_id' => $stage_id ) );
			}
		}
		foreach ( array( 'ready', 'mutation_performed', 'production_mutation', 'authorizing', 'rollback_verified', 'postcondition_verified' ) as $key ) {
			if ( array_key_exists( $key, $evidence ) && array_key_exists( $key, $row ) && $evidence[ $key ] !== $row[ $key ] ) {
				return self::error( 'mad4b_production_readiness_outer_evidence_flag_mismatch', array( 'stage_id' => $stage_id, 'field' => $key ) );
			}
		}
		return true;
	}

	private static function evidence_trust_runtime_recompute( array $row, array $stage, array $identity ) {
		if ( 'runtime_recompute' !== sanitize_key( (string) ( isset( $stage['attestation_method'] ) ? $stage['attestation_method'] : '' ) )
			|| 'mad4b/production-certification-readonly-evidence' !== ( isset( $stage['producer_ability'] ) ? (string) $stage['producer_ability'] : '' )
			|| ! class_exists( 'MAD4B_SCP_Production_Certification' ) ) {
			return self::error( 'mad4b_production_readiness_runtime_recompute_unavailable', array( 'stage_id' => isset( $stage['id'] ) ? (string) $stage['id'] : '' ) );
		}
		$producer_stage_id = isset( $stage['producer_stage_id'] ) ? sanitize_key( (string) $stage['producer_stage_id'] ) : '';
		if ( '' === $producer_stage_id || $producer_stage_id !== sanitize_key( (string) $stage['id'] ) ) {
			return self::error( 'mad4b_production_readiness_runtime_recompute_stage_mismatch', array( 'stage_id' => isset( $stage['id'] ) ? (string) $stage['id'] : '' ) );
		}
		$live = MAD4B_SCP_Production_Certification::execute( array( 'stage_id' => $producer_stage_id ) );
		if ( is_wp_error( $live ) || ! is_array( $live ) ) return self::error( 'mad4b_production_readiness_runtime_recompute_failed', array( 'stage_id' => $producer_stage_id ) );
		$valid = self::evidence_trust_validate_bindings( $live, $stage, $identity );
		if ( is_wp_error( $valid ) ) return $valid;
		$expected = self::canonical_digest( self::evidence_trust_row_material( $live, $stage, $identity ) );
		$provided = self::canonical_digest( self::evidence_trust_row_material( $row, $stage, $identity ) );
		if ( is_wp_error( $expected ) || is_wp_error( $provided ) || ! hash_equals( (string) $expected, (string) $provided ) ) {
			return self::error( 'mad4b_production_readiness_runtime_recompute_mismatch', array( 'stage_id' => $producer_stage_id ) );
		}
		return array(
			'contract' => self::EVIDENCE_TRUST_CONTRACT,
			'stage_id' => $producer_stage_id,
			'mode' => 'runtime_recompute',
			'verified' => true,
			'fresh' => true,
			'authorizing' => false,
		);
	}

	private static function evidence_trust_signed_attestation( array $row, array $stage, array $identity ) {
		$method_check = self::evidence_trust_verify_method_evidence( $row, $stage, $identity );
		if ( is_wp_error( $method_check ) ) return $method_check;
		$att = isset( $row['evidence_attestation'] ) && is_array( $row['evidence_attestation'] ) ? $row['evidence_attestation'] : array();
		if ( self::EVIDENCE_ATTESTATION_CONTRACT !== ( isset( $att['contract'] ) ? (string) $att['contract'] : '' )
			|| empty( $att['claim'] ) || ! is_array( $att['claim'] )
			|| empty( $att['signature'] ) || ! is_array( $att['signature'] ) ) {
			return self::error( 'mad4b_production_readiness_evidence_attestation_missing', array( 'stage_id' => isset( $stage['id'] ) ? (string) $stage['id'] : '' ) );
		}
		$claim = $att['claim'];
		$issued_at = isset( $claim['issued_at'] ) ? (int) $claim['issued_at'] : 0;
		$expires_at = isset( $claim['expires_at'] ) ? (int) $claim['expires_at'] : 0;
		$ttl = self::evidence_trust_ttl();
		$now = self::evidence_trust_now();
		if ( $issued_at < 1 || $expires_at < 1 || $issued_at > $now || $expires_at <= $now || $expires_at - $issued_at !== $ttl ) {
			return self::error( 'mad4b_production_readiness_evidence_attestation_stale', array( 'stage_id' => isset( $stage['id'] ) ? (string) $stage['id'] : '' ) );
		}
		if ( class_exists( 'MAD4B_SCP_Time_Policy' ) ) {
			$time_check = MAD4B_SCP_Time_Policy::assert_timestamp( self::EVIDENCE_CRYPTO_PURPOSE, $issued_at, $ttl );
			if ( is_wp_error( $time_check ) ) return self::error( 'mad4b_production_readiness_evidence_attestation_time_invalid' );
		}
		$expected_claim = self::evidence_trust_claim( $row, $stage, $identity, $issued_at, $expires_at );
		$actual_digest = self::canonical_digest( $claim );
		$expected_digest = self::canonical_digest( $expected_claim );
		$declared_digest = isset( $att['claim_sha256'] ) ? strtolower( trim( (string) $att['claim_sha256'] ) ) : '';
		if ( is_wp_error( $actual_digest ) || is_wp_error( $expected_digest )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $declared_digest )
			|| ! hash_equals( $declared_digest, (string) $actual_digest )
			|| ! hash_equals( (string) $actual_digest, (string) $expected_digest ) ) {
			return self::error( 'mad4b_production_readiness_evidence_attestation_claim_mismatch', array( 'stage_id' => isset( $stage['id'] ) ? (string) $stage['id'] : '' ) );
		}
		if ( ! class_exists( 'MAD4B_SCP_Crypto_Profile' ) ) return self::error( 'mad4b_production_readiness_evidence_crypto_unavailable' );
		$verified = MAD4B_SCP_Crypto_Profile::verify_digest_for_purpose( $att['signature'], $declared_digest, self::EVIDENCE_CRYPTO_PURPOSE );
		if ( is_wp_error( $verified ) ) return self::error( 'mad4b_production_readiness_evidence_signature_invalid', array( 'stage_id' => isset( $stage['id'] ) ? (string) $stage['id'] : '' ) );
		return array(
			'contract' => self::EVIDENCE_TRUST_CONTRACT,
			'stage_id' => isset( $stage['id'] ) ? (string) $stage['id'] : '',
			'mode' => 'signed_attestation',
			'attestation_method' => isset( $stage['attestation_method'] ) ? (string) $stage['attestation_method'] : '',
			'claim_sha256' => $declared_digest,
			'signature_profile' => isset( $att['signature']['profile_id'] ) ? (string) $att['signature']['profile_id'] : '',
			'signature_kid' => isset( $att['signature']['kid'] ) ? (string) $att['signature']['kid'] : '',
			'verified' => true,
			'fresh' => true,
			'authorizing' => false,
		);
	}

	private static function evidence_trust_verify_method_evidence( array $row, array $stage, array $identity ) {
		$method = sanitize_key( isset( $stage['attestation_method'] ) ? (string) $stage['attestation_method'] : '' );
		$evidence = isset( $row['producer_evidence'] ) && is_array( $row['producer_evidence'] ) ? $row['producer_evidence'] : array();
		$stage_id = isset( $stage['id'] ) ? (string) $stage['id'] : '';
		if ( 'exact_deployment_verifier' === $method ) {
			if ( empty( $evidence['ready'] ) || empty( $evidence['runtime_identity_match'] ) || ! empty( $evidence['mutation_performed'] ) ) return self::error( 'mad4b_production_readiness_deployment_evidence_invalid', array( 'stage_id' => $stage_id ) );
			$live = strtolower( trim( (string) ( isset( $evidence['live_source_sha'] ) ? $evidence['live_source_sha'] : '' ) ) );
			$expected = strtolower( trim( (string) ( isset( $evidence['expected_sha'] ) ? $evidence['expected_sha'] : '' ) ) );
			if ( ! hash_equals( (string) $identity['source_commit_sha'], $live ) || ! hash_equals( (string) $identity['source_commit_sha'], $expected ) ) return self::error( 'mad4b_production_readiness_deployment_identity_untrusted', array( 'stage_id' => $stage_id ) );
			return true;
		}
		if ( 'external_release_root_verifier' === $method ) {
			if ( empty( $evidence['verified'] ) || empty( $evidence['attestation_verified'] ) || false !== ( isset( $evidence['runtime_self_attestation_authoritative'] ) ? $evidence['runtime_self_attestation_authoritative'] : null ) || 'external_release_verifier' !== ( isset( $evidence['verification_boundary'] ) ? (string) $evidence['verification_boundary'] : '' ) ) return self::error( 'mad4b_production_readiness_release_root_evidence_invalid', array( 'stage_id' => $stage_id ) );
			foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $key ) if ( empty( $evidence[ $key ] ) || ! hash_equals( (string) $identity[ $key ], strtolower( (string) $evidence[ $key ] ) ) ) return self::error( 'mad4b_production_readiness_release_root_identity_mismatch', array( 'stage_id' => $stage_id, 'field' => $key ) );
			return true;
		}
		if ( 'rollback_retention_verifier' === $method ) {
			$expires = isset( $evidence['artifact_expires_at'] ) ? strtotime( (string) $evidence['artifact_expires_at'] ) : false;
			if ( 'github_actions_artifact_api' !== ( isset( $evidence['verification_source'] ) ? (string) $evidence['verification_source'] : '' ) || true === ( isset( $evidence['expired'] ) ? $evidence['expired'] : true ) || false === $expires || $expires <= self::evidence_trust_now() ) return self::error( 'mad4b_production_readiness_rollback_retention_evidence_invalid', array( 'stage_id' => $stage_id ) );
			return true;
		}
		if ( 'repository_workflow_verifier' === $method ) {
			$workflow_head = strtolower( trim( (string) ( isset( $evidence['head_sha'] ) ? $evidence['head_sha'] : '' ) ) );
			if ( 'success' !== strtolower( (string) ( isset( $evidence['conclusion'] ) ? $evidence['conclusion'] : '' ) ) || ! hash_equals( (string) $identity['source_commit_sha'], $workflow_head ) ) return self::error( 'mad4b_production_readiness_repository_workflow_evidence_invalid', array( 'stage_id' => $stage_id ) );
			return true;
		}
		if ( 'durable_host_runner_receipt' === $method ) {
			if ( 'mad4b.tool-execution-receipt.v1' !== ( isset( $evidence['contract'] ) ? (string) $evidence['contract'] : '' ) || empty( $evidence['mutation_performed'] ) || 'PASS' !== ( isset( $evidence['readback_verdict'] ) ? (string) $evidence['readback_verdict'] : '' ) || empty( $evidence['approval_ref'] ) || empty( $evidence['authority_ref'] ) ) return self::error( 'mad4b_production_readiness_host_runner_receipt_invalid', array( 'stage_id' => $stage_id ) );
			return true;
		}
		if ( 'recovery_plane_receipt' === $method ) {
			if ( 'DURABLE_VERIFIED_RECEIPT' !== ( isset( $evidence['evidence_state'] ) ? (string) $evidence['evidence_state'] : '' ) || true !== ( isset( $evidence['readback_verified'] ) ? $evidence['readback_verified'] : false ) ) return self::error( 'mad4b_production_readiness_recovery_receipt_invalid', array( 'stage_id' => $stage_id ) );
			return true;
		}
		if ( 'governed_execution_receipt' === $method ) {
			if ( ! class_exists( 'MAD4B_SCP_Execution_Receipt' ) ) return self::error( 'mad4b_production_readiness_execution_receipt_verifier_unavailable', array( 'stage_id' => $stage_id ) );
			$verified = MAD4B_SCP_Execution_Receipt::verify( $evidence );
			if ( is_wp_error( $verified ) || empty( $verified['valid'] ) ) return self::error( 'mad4b_production_readiness_execution_receipt_invalid', array( 'stage_id' => $stage_id ) );
			return true;
		}
		return self::error( 'mad4b_production_readiness_attestation_method_invalid', array( 'stage_id' => $stage_id, 'method' => $method ) );
	}

	private static function evidence_trust_row_material( array $row, array $stage, array $identity ) {
		return array(
			'contract' => self::GATE_CONTRACT,
			'stage_id' => isset( $stage['id'] ) ? (string) $stage['id'] : '',
			'gate' => isset( $stage['gate'] ) ? (string) $stage['gate'] : '',
			'environment' => 'staging',
			'candidate_identity' => $identity,
			'producer' => isset( $stage['producer'] ) ? (string) $stage['producer'] : '',
			'producer_contract' => isset( $stage['producer_contract'] ) ? (string) $stage['producer_contract'] : '',
			'producer_evidence_sha256' => isset( $row['producer_evidence_sha256'] ) ? strtolower( trim( (string) $row['producer_evidence_sha256'] ) ) : '',
			'ready' => true === ( isset( $row['ready'] ) ? $row['ready'] : false ),
			'mutation_performed' => true === ( isset( $row['mutation_performed'] ) ? $row['mutation_performed'] : false ),
			'production_mutation' => true === ( isset( $row['production_mutation'] ) ? $row['production_mutation'] : false ),
			'authorizing' => true === ( isset( $row['authorizing'] ) ? $row['authorizing'] : false ),
			'rollback_verified' => true === ( isset( $row['rollback_verified'] ) ? $row['rollback_verified'] : false ),
			'postcondition_verified' => true === ( isset( $row['postcondition_verified'] ) ? $row['postcondition_verified'] : false ),
			'trust_mode' => isset( $stage['trust_mode'] ) ? (string) $stage['trust_mode'] : '',
			'attestation_method' => isset( $stage['attestation_method'] ) ? (string) $stage['attestation_method'] : '',
		);
	}

	private static function evidence_trust_claim( array $row, array $stage, array $identity, $issued_at, $expires_at ) {
		return array(
			'contract' => self::EVIDENCE_ATTESTATION_CONTRACT,
			'material' => self::evidence_trust_row_material( $row, $stage, $identity ),
			'issued_at' => (int) $issued_at,
			'expires_at' => (int) $expires_at,
			'authorizing' => false,
		);
	}

	private static function evidence_trust_now() {
		return class_exists( 'MAD4B_SCP_Time_Policy' ) && method_exists( 'MAD4B_SCP_Time_Policy', 'now_epoch' )
			? (int) MAD4B_SCP_Time_Policy::now_epoch()
			: time();
	}

	private static function evidence_trust_ttl() {
		if ( class_exists( 'MAD4B_SCP_Time_Policy' ) && method_exists( 'MAD4B_SCP_Time_Policy', 'bounded_ttl' ) ) {
			$ttl = MAD4B_SCP_Time_Policy::bounded_ttl( self::EVIDENCE_CRYPTO_PURPOSE, self::EVIDENCE_TTL );
			if ( ! is_wp_error( $ttl ) ) return (int) $ttl;
		}
		return self::EVIDENCE_TTL;
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
