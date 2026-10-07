<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded dual-read shadow runner for exact governed read abilities.
 *
 * The certified active result is the only raw result returned to the caller.
 * Candidate output is reduced to privacy-safe comparison evidence and digests.
 */
final class MAD4B_SCP_Provider_Shadow_Read {
	const CONTRACT = 'mad4b.provider-shadow-read.v1';
	const RECIPE_CONTRACT = 'mad4b.provider-shadow-recipe.v1';
	const RECEIPT_CONTRACT = 'mad4b.provider-shadow-read-receipt.v1';
	const MAX_SAMPLES = 12;
	const MAX_INPUT_BYTES = 32768;
	const MAX_RESULT_BYTES = 262144;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 36 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/provider-shadow-read-run' ) ) ) return;
		$category = 'mad4b-admin';
		wp_register_ability( 'mad4b/provider-shadow-read-run', array(
			'label' => 'Run Bounded Provider Shadow Read',
			'description' => 'Compare an exact active governed read path with a candidate read path while returning only the active raw result.',
			'category' => $category,
			'input_schema' => array( 'type'=>'object', 'additionalProperties'=>true ),
			'output_schema' => array( 'type'=>'object', 'additionalProperties'=>true ),
			'execute_callback' => array( __CLASS__, 'run' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
			'meta' => array(
				'public' => false, 'show_in_rest' => false,
				'mcp' => array( 'public'=>false, 'type'=>'tool', 'surface'=>'admin' ),
				'annotations' => array( 'readonly'=>true, 'destructive'=>false, 'idempotent'=>true ),
				'mad4b_contract' => self::CONTRACT,
			),
		) );
	}

	public static function validate_recipe( array $recipe ) {
		if ( ! class_exists( 'MAD4B_SCP_Structural_Redaction' ) || ! class_exists( 'MAD4B_SCP_Execution_Fence' ) || ! method_exists( 'MAD4B_SCP_Execution_Fence', 'with_governed_child' ) ) return new WP_Error( 'mad4b_shadow_safety_runtime_unavailable', 'Privacy classification and governed child execution are required for shadow reads.' );
		$bounded = self::bounded_json( $recipe, self::MAX_INPUT_BYTES );
		if ( is_wp_error( $bounded ) ) return $bounded;
		if ( self::RECIPE_CONTRACT !== (string) ( isset( $recipe['contract'] ) ? $recipe['contract'] : '' ) ) return new WP_Error( 'mad4b_shadow_recipe_contract_invalid', 'Shadow recipe contract is invalid.' );
		$recipe_id = sanitize_key( isset( $recipe['recipe_id'] ) ? (string) $recipe['recipe_id'] : '' );
		$provider = sanitize_key( isset( $recipe['provider_id'] ) ? (string) $recipe['provider_id'] : '' );
		$capability = strtolower( trim( (string) ( isset( $recipe['capability_id'] ) ? $recipe['capability_id'] : '' ) ) );
		if ( '' === $recipe_id || '' === $provider || 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]{0,99}$/', $capability ) ) return new WP_Error( 'mad4b_shadow_recipe_identity_invalid', 'Shadow recipe identity is incomplete.' );

		$generation = isset( $recipe['runtime_generation'] ) && is_array( $recipe['runtime_generation'] ) ? $recipe['runtime_generation'] : array();
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Generation_Fence' ) ) return new WP_Error( 'mad4b_shadow_generation_unavailable', 'Runtime generation fence is unavailable.' );
		$current = MAD4B_SCP_Runtime_Generation_Fence::assert_current( $generation );
		if ( is_wp_error( $current ) ) return $current;

		$policy = isset( $recipe['policy'] ) && is_array( $recipe['policy'] ) ? $recipe['policy'] : array();
		$min_samples = $policy['min_samples'] ?? 1;
		$max_samples = $policy['max_samples'] ?? $min_samples;
		if ( ! is_int( $min_samples ) || ! is_int( $max_samples ) || $min_samples < 1 || $max_samples < $min_samples || $max_samples > self::MAX_SAMPLES ) return new WP_Error( 'mad4b_shadow_sample_policy_invalid', 'Signed sample bounds must be positive integers within the runner limit.' );
		$latency_tolerance = max( 0, min( 60000, (int) ( isset( $policy['max_latency_regression_ms'] ) ? $policy['max_latency_regression_ms'] : 1000 ) ) );
		$max_mismatch_ppm = max( 0, min( 1000000, (int) ( isset( $policy['max_semantic_mismatch_ppm'] ) ? $policy['max_semantic_mismatch_ppm'] : 0 ) ) );
		if ( empty( $policy['require_distinct_workloads'] ) ) return new WP_Error( 'mad4b_shadow_workload_coverage_required', 'Shadow recipe must require distinct workload coverage.' );
		if ( empty( $policy['candidate_output_discarded'] ) ) return new WP_Error( 'mad4b_shadow_candidate_discard_policy_required', 'Shadow recipe must explicitly discard candidate raw output.' );

		$cost = isset( $recipe['cost_authority'] ) && is_array( $recipe['cost_authority'] ) ? $recipe['cost_authority'] : array();
		if ( ! is_int( $cost['provider_units_per_sample'] ?? null ) || ! is_int( $cost['currency_minor_units_per_sample'] ?? null ) ) return new WP_Error( 'mad4b_shadow_cost_authority_required', 'Shadow cost requires explicit integer units.' );
		if ( 'signed_zero_cost' !== (string) ( isset( $cost['mode'] ) ? $cost['mode'] : '' )
			|| 0 !== (int) ( isset( $cost['provider_units_per_sample'] ) ? $cost['provider_units_per_sample'] : -1 )
			|| 0 !== (int) ( isset( $cost['currency_minor_units_per_sample'] ) ? $cost['currency_minor_units_per_sample'] : -1 ) ) {
			return new WP_Error( 'mad4b_shadow_cost_authority_required', 'Shadow sampling requires signed zero-cost authority or a separately governed budgeted recipe.' );
		}

		$active = self::validate_target( isset( $recipe['active'] ) && is_array( $recipe['active'] ) ? $recipe['active'] : array(), 'active', $provider, $capability );
		if ( is_wp_error( $active ) ) return $active;
		$candidate = self::validate_target( isset( $recipe['candidate'] ) && is_array( $recipe['candidate'] ) ? $recipe['candidate'] : array(), 'candidate', $provider, $capability );
		if ( is_wp_error( $candidate ) ) return $candidate;

		$expires_at = (int) ( isset( $recipe['expires_at'] ) ? $recipe['expires_at'] : 0 );
		if ( $expires_at <= self::now_epoch() ) return new WP_Error( 'mad4b_shadow_recipe_expired', 'Shadow recipe is expired.' );

		$digest = self::payload_sha256( $recipe );
		$declared = strtolower( trim( (string) ( isset( $recipe['recipe_sha256'] ) ? $recipe['recipe_sha256'] : '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $declared ) || ! hash_equals( $digest, $declared ) ) return new WP_Error( 'mad4b_shadow_recipe_digest_mismatch', 'Shadow recipe digest mismatch.' );
		if ( ! class_exists( 'MAD4B_SCP_Crypto_Profile' ) ) return new WP_Error( 'mad4b_shadow_crypto_unavailable', 'Shadow recipe signature verifier is unavailable.' );
		$signature = isset( $recipe['signature'] ) && is_array( $recipe['signature'] ) ? $recipe['signature'] : array();
		$verified = MAD4B_SCP_Crypto_Profile::verify_digest_for_purpose( $signature, $digest, 'behavioral_evidence' );
		if ( is_wp_error( $verified ) ) return $verified;

		return array(
			'recipe_id'=>$recipe_id,
			'provider_id'=>$provider,
			'capability_id'=>$capability,
			'active'=>$active,
			'candidate'=>$candidate,
			'policy'=>array(
				'min_samples'=>$min_samples,
				'max_samples'=>$max_samples,
				'max_latency_regression_ms'=>$latency_tolerance,
				'max_semantic_mismatch_ppm'=>$max_mismatch_ppm,
				'require_distinct_workloads'=>true,
				'candidate_output_discarded'=>true,
			),
			'cost_authority'=>$cost,
			'runtime_generation'=>$generation,
			'recipe_sha256'=>$digest,
			'expires_at'=>$expires_at,
		);
	}

	public static function run( $input = array() ) {
		$recipe = isset( $input['recipe'] ) && is_array( $input['recipe'] ) ? $input['recipe'] : array();
		$valid = self::validate_recipe( $recipe );
		if ( is_wp_error( $valid ) ) return $valid;

		$workloads = isset( $input['workloads'] ) && is_array( $input['workloads'] ) ? array_values( $input['workloads'] ) : array();
		if ( count( $workloads ) < $valid['policy']['min_samples'] || count( $workloads ) > $valid['policy']['max_samples'] ) return new WP_Error( 'mad4b_shadow_sample_count_invalid', 'Shadow workload count is outside the signed policy bounds.' );

		$ids = array();
		$workload_digests = array();
		$active_results = array();
		$samples = array();
		$mismatches = 0;
		$candidate_failures = 0;
		foreach ( $workloads as $row ) {
			if ( ! is_array( $row ) ) return new WP_Error( 'mad4b_shadow_workload_invalid', 'Shadow workload must be an object.' );
			$workload_id = sanitize_key( isset( $row['workload_id'] ) ? (string) $row['workload_id'] : '' );
			if ( '' === $workload_id || isset( $ids[ $workload_id ] ) ) return new WP_Error( 'mad4b_shadow_workload_identity_invalid', 'Shadow workloads require unique non-empty ids.' );
			$ids[ $workload_id ] = true;
			$active_input = isset( $row['active_input'] ) && is_array( $row['active_input'] ) ? $row['active_input'] : array();
			$candidate_input = isset( $row['candidate_input'] ) && is_array( $row['candidate_input'] ) ? $row['candidate_input'] : $active_input;
			if ( ! hash_equals( self::stable_digest( $active_input ), self::stable_digest( $candidate_input ) ) ) return new WP_Error( 'mad4b_shadow_workload_object_mismatch', 'Both read paths must observe the same exact workload input.' );
			foreach ( array( $active_input, $candidate_input ) as $payload ) {
				$bounded = self::bounded_json( $payload, self::MAX_INPUT_BYTES );
				if ( is_wp_error( $bounded ) ) return $bounded;
				$privacy = MAD4B_SCP_Structural_Redaction::classify( $payload, 'provider_shadow_input' );
				if ( ! is_array( $privacy ) || 'public_bounded' !== ( $privacy['classification'] ?? '' ) ) return new WP_Error( 'mad4b_shadow_private_input_denied', 'Shadow workloads must be proven non-secret and fully bounded.' );
			}
			$workload_digest = self::stable_digest( array( 'active_input'=>$active_input, 'candidate_input'=>$candidate_input ) );
			if ( '' === $workload_digest || isset( $workload_digests[ $workload_digest ] ) ) return new WP_Error( 'mad4b_shadow_correlated_sample_denied', 'Shadow workloads must be semantically distinct; duplicate/correlated inputs are rejected.' );
			$workload_digests[ $workload_digest ] = true;
			$generation_check = MAD4B_SCP_Runtime_Generation_Fence::assert_current( $valid['runtime_generation'] );
			if ( is_wp_error( $generation_check ) ) return $generation_check;

			$active_observation = self::execute_read_target( $valid['active'], $active_input );
			if ( is_wp_error( $active_observation['result'] ) ) return new WP_Error( 'mad4b_shadow_active_read_failed', 'Certified active read failed; candidate result was not allowed to replace it.', array( 'workload_id'=>$workload_id, 'active_error_code'=>$active_observation['result']->get_error_code() ) );
			$bounded = self::bounded_json( $active_observation['result'], self::MAX_RESULT_BYTES );
			if ( is_wp_error( $bounded ) ) return $bounded;
			$privacy = MAD4B_SCP_Structural_Redaction::classify( $active_observation['result'], 'provider_shadow_active' );
			if ( ! is_array( $privacy ) || 'public_bounded' !== ( $privacy['classification'] ?? '' ) ) return new WP_Error( 'mad4b_shadow_active_private_field_leakage', 'Active read output must be proven non-secret before candidate sampling.' );
			$active_results[] = array( 'workload_id'=>$workload_id, 'result'=>$active_observation['result'] );

			$generation_check = MAD4B_SCP_Runtime_Generation_Fence::assert_current( $valid['runtime_generation'] );
			if ( is_wp_error( $generation_check ) ) return $generation_check;
			$candidate_observation = self::execute_read_target( $valid['candidate'], $candidate_input );
			$generation_check = MAD4B_SCP_Runtime_Generation_Fence::assert_current( $valid['runtime_generation'] );
			if ( is_wp_error( $generation_check ) ) return $generation_check;
			$comparison = self::compare_observations( $active_observation, $candidate_observation, $valid['policy'] );
			if ( is_wp_error( $comparison ) ) return $comparison;
			if ( ! empty( $comparison['semantic_mismatch'] ) ) $mismatches++;
			if ( ! empty( $comparison['candidate_failed'] ) ) $candidate_failures++;
			$samples[] = array(
				'workload_id'=>$workload_id,
				'active_sha256'=>$comparison['active_sha256'],
				'candidate_sha256'=>$comparison['candidate_sha256'],
				'active_error_code'=>$comparison['active_error_code'],
				'candidate_error_code'=>$comparison['candidate_error_code'],
				'active_latency_ms'=>$comparison['active_latency_ms'],
				'candidate_latency_ms'=>$comparison['candidate_latency_ms'],
				'latency_regression_ms'=>$comparison['latency_regression_ms'],
				'semantic_mismatch'=>$comparison['semantic_mismatch'],
				'candidate_failed'=>$comparison['candidate_failed'],
				'candidate_privacy_classification'=>$comparison['candidate_privacy_classification'],
				'candidate_raw_output_exposed'=>false,
			);
		}

		$count = count( $samples );
		$mismatch_ppm = $count > 0 ? (int) floor( ( $mismatches * 1000000 ) / $count ) : 1000000;
		$max_latency = 0;
		foreach ( $samples as $sample ) $max_latency = max( $max_latency, (int) $sample['latency_regression_ms'] );
		$eligible = 0 === $candidate_failures
			&& $mismatch_ppm <= $valid['policy']['max_semantic_mismatch_ppm']
			&& $max_latency <= $valid['policy']['max_latency_regression_ms'];

		$receipt = array(
			'contract'=>self::RECEIPT_CONTRACT,
			'recipe_id'=>$valid['recipe_id'],
			'recipe_sha256'=>$valid['recipe_sha256'],
			'provider_id'=>$valid['provider_id'],
			'capability_id'=>$valid['capability_id'],
			'active_ability'=>$valid['active']['ability'],
			'candidate_ability'=>$valid['candidate']['ability'],
			'runtime_generation_sha256'=>(string) $valid['runtime_generation']['generation_sha256'],
			'sample_count'=>$count,
			'workload_coverage'=>array_keys( $ids ),
			'semantic_mismatch_count'=>$mismatches,
			'semantic_mismatch_ppm'=>$mismatch_ppm,
			'candidate_failure_count'=>$candidate_failures,
			'max_latency_regression_ms_observed'=>$max_latency,
			'policy'=>$valid['policy'],
			'samples'=>$samples,
			'candidate_raw_output_exposed'=>false,
			'new_grant_or_mount_performed'=>false,
			'authorizing'=>false,
			'read_exposure_promotion_eligible'=>$eligible,
			'issued_at'=>self::now_epoch(),
			'expires_at'=>min( $valid['expires_at'], self::now_epoch() + 21600 ),
		);
		$receipt['receipt_sha256'] = self::stable_digest( $receipt );
		if ( ! class_exists( 'MAD4B_SCP_Crypto_Profile' ) ) return new WP_Error( 'mad4b_shadow_crypto_unavailable', 'Shadow receipt signer is unavailable.' );
		$profile = MAD4B_SCP_Crypto_Profile::default_profile( 'behavioral_evidence' );
		if ( is_wp_error( $profile ) || '' === (string) $profile ) return is_wp_error( $profile ) ? $profile : new WP_Error( 'mad4b_shadow_crypto_profile_unavailable', 'Behavioral evidence crypto profile is unavailable.' );
		$signature = MAD4B_SCP_Crypto_Profile::sign_digest( $profile, $receipt['receipt_sha256'] );
		if ( is_wp_error( $signature ) ) return $signature;
		$receipt['signature'] = $signature;

		return array(
			'contract'=>self::CONTRACT,
			'active_results'=>$active_results,
			'candidate_result_discarded'=>true,
			'comparison_receipt'=>$receipt,
			'execution_performed'=>true,
			'mutation_performed'=>false,
			'authorizing'=>false,
		);
	}

	public static function payload_sha256( array $recipe ) {
		unset( $recipe['recipe_sha256'], $recipe['signature'] );
		return self::stable_digest( $recipe );
	}

	private static function validate_target( array $target, $role, $provider, $capability ) {
		if ( true !== ( $target['zero_effect_read'] ?? false ) || true !== ( $target['nonsecret_read'] ?? false ) ) return new WP_Error( 'mad4b_shadow_target_effect_proof_required', 'The signed recipe must bind a proven zero-effect, non-secret read target.' );
		$ability_name = isset( $target['ability'] ) ? trim( (string) $target['ability'] ) : '';
		if ( class_exists( 'MAD4B_SCP_Canonicalization' ) ) {
			$canonical = MAD4B_SCP_Canonicalization::ability_name( $ability_name );
			if ( is_wp_error( $canonical ) ) return $canonical;
			$ability_name = $canonical;
		}
		if ( '' === $ability_name || ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) return new WP_Error( 'mad4b_shadow_target_unavailable', 'Shadow target ability is not registered.', array( 'role'=>$role ) );
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) || ! method_exists( $ability, 'execute' ) ) return new WP_Error( 'mad4b_shadow_target_contract_invalid', 'Shadow target does not expose the required Ability contract.', array( 'role'=>$role ) );
		$meta = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		if ( ! array_key_exists( 'readonly', $annotations ) || true !== $annotations['readonly'] ) return new WP_Error( 'mad4b_shadow_target_not_readonly', 'Shadow execution accepts only explicitly readonly targets.', array( 'role'=>$role ) );

		if ( ! class_exists( 'MAD4B_SCP_Capability_Descriptor_Registry' ) ) return new WP_Error( 'mad4b_shadow_descriptor_unavailable', 'Capability Descriptor registry is unavailable.' );
		$descriptor = MAD4B_SCP_Capability_Descriptor_Registry::describe( $ability_name );
		if ( is_wp_error( $descriptor ) || ! is_array( $descriptor ) || 'read' !== (string) ( isset( $descriptor['lane'] ) ? $descriptor['lane'] : '' ) || empty( $descriptor['execution_eligible'] ) ) return new WP_Error( 'mad4b_shadow_descriptor_ineligible', 'Shadow target is not an execution-eligible read capability.', array( 'role'=>$role ) );
		$binding = isset( $target['capability_descriptor_binding'] ) && is_array( $target['capability_descriptor_binding'] ) ? $target['capability_descriptor_binding'] : array();
		$binding_check = MAD4B_SCP_Capability_Descriptor_Registry::assert_binding( $ability_name, $binding, 'provider_shadow_' . sanitize_key( $role ) );
		if ( is_wp_error( $binding_check ) ) return $binding_check;

		$schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
		$schema_sha = self::stable_digest( $schema );
		$expected_schema = strtolower( trim( (string) ( isset( $target['input_schema_sha256'] ) ? $target['input_schema_sha256'] : '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_schema ) || ! hash_equals( $schema_sha, $expected_schema ) ) return new WP_Error( 'mad4b_shadow_target_schema_drift', 'Shadow target input schema changed after recipe approval.', array( 'role'=>$role ) );

		if ( ! class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ) return new WP_Error( 'mad4b_shadow_provider_certification_unavailable', 'Provider read certification is required.' );
		if ( 'candidate' === $role ) {
			$status = MAD4B_SCP_Provider_Compatibility_Certification::ability_status( $provider, $ability_name );
			if ( empty( $status ) || $capability !== (string) ( isset( $status['capability_id'] ) ? $status['capability_id'] : '' ) || 'read' !== (string) ( isset( $status['risk'] ) ? $status['risk'] : '' ) || empty( $status['structural_compatible'] ) ) {
				return new WP_Error( 'mad4b_shadow_candidate_not_proven_readonly', 'Candidate provider path lacks exact read-only structural certification.' );
			}
		}

		return array( 'ability'=>$ability_name, 'ability_object'=>$ability, 'input_schema_sha256'=>$schema_sha, 'capability_descriptor_binding'=>$binding );
	}

	private static function execute_read_target( array $target, array $params ) {
		$ability = $target['ability_object'];
		$ability_name = $target['ability'];
		$start = microtime( true );
		try {
			$callback = static function () use ( $ability, $params ) { return $ability->execute( $params ); };
			if ( class_exists( 'MAD4B_SCP_Execution_Fence' ) && method_exists( 'MAD4B_SCP_Execution_Fence', 'with_governed_child' ) ) {
				$callback = static function () use ( $ability_name, $params, $ability ) {
					return MAD4B_SCP_Execution_Fence::with_governed_child( $ability_name, $params, static function () use ( $ability, $params ) { return $ability->execute( $params ); }, 'provider_shadow_read' );
				};
			}
			$result = class_exists( 'MAD4B_SCP_Connector_Resilience' ) && method_exists( 'MAD4B_SCP_Connector_Resilience', 'execute_read' )
				? MAD4B_SCP_Connector_Resilience::execute_read( $ability_name, $callback )
				: call_user_func( $callback );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'mad4b_shadow_target_exception', 'Shadow read target threw before a bounded observation was available.' );
		}
		$latency = max( 0, (int) round( ( microtime( true ) - $start ) * 1000 ) );
		return array( 'result'=>$result, 'latency_ms'=>$latency );
	}

	private static function compare_observations( array $active, array $candidate, array $policy ) {
		$active_result = $active['result'];
		$candidate_result = $candidate['result'];
		foreach ( array( 'active'=>$active_result, 'candidate'=>$candidate_result ) as $role=>$result ) {
			if ( is_wp_error( $result ) ) continue;
			$bounded = self::bounded_json( $result, self::MAX_RESULT_BYTES );
			if ( is_wp_error( $bounded ) ) return $bounded;
			$classification = MAD4B_SCP_Structural_Redaction::classify( $result, 'provider_shadow_' . $role );
			if ( ! is_array( $classification ) || 'public_bounded' !== ( $classification['classification'] ?? '' ) ) return new WP_Error( 'mad4b_shadow_' . $role . '_private_field_leakage', 'Shadow read output is private, uncertain, or truncated.' );
		}
		$active_error = is_wp_error( $active_result ) ? $active_result->get_error_code() : '';
		$candidate_error = is_wp_error( $candidate_result ) ? $candidate_result->get_error_code() : '';
		$active_sha = self::stable_digest( is_wp_error( $active_result ) ? array( 'error_code'=>$active_error ) : $active_result );
		$candidate_sha = self::stable_digest( is_wp_error( $candidate_result ) ? array( 'error_code'=>$candidate_error ) : $candidate_result );
		if ( '' === $active_sha || '' === $candidate_sha ) return new WP_Error( 'mad4b_shadow_result_digest_failed', 'Shadow result could not be reduced to bounded comparison evidence.' );

		$privacy = 'public_bounded';
		if ( ! is_wp_error( $candidate_result ) && class_exists( 'MAD4B_SCP_Structural_Redaction' ) ) {
			$class = MAD4B_SCP_Structural_Redaction::classify( $candidate_result, 'provider_shadow_candidate' );
			$privacy = is_array( $class ) && isset( $class['classification'] ) ? (string) $class['classification'] : 'unknown';
			if ( 'sensitive_redacted' === $privacy ) return new WP_Error( 'mad4b_shadow_candidate_private_field_leakage', 'Candidate output contains secret/private material and cannot be sampled.' );
		}
		$active_latency = (int) $active['latency_ms'];
		$candidate_latency = (int) $candidate['latency_ms'];
		$regression = max( 0, $candidate_latency - $active_latency );
		return array(
			'active_sha256'=>$active_sha,
			'candidate_sha256'=>$candidate_sha,
			'active_error_code'=>$active_error,
			'candidate_error_code'=>$candidate_error,
			'active_latency_ms'=>$active_latency,
			'candidate_latency_ms'=>$candidate_latency,
			'latency_regression_ms'=>$regression,
			'semantic_mismatch'=>! hash_equals( $active_sha, $candidate_sha ),
			'candidate_failed'=>'' !== $candidate_error,
			'candidate_privacy_classification'=>$privacy,
		);
	}

	private static function bounded_json( $value, $max_bytes ) {
		$json = self::stable_json( $value );
		if ( '' === $json ) return new WP_Error( 'mad4b_shadow_json_invalid', 'Shadow value cannot be canonically encoded.' );
		if ( strlen( $json ) > (int) $max_bytes ) return new WP_Error( 'mad4b_shadow_value_too_large', 'Shadow value exceeds its bounded size.' );
		return $json;
	}

	private static function stable_digest( $value ) {
		$json = self::stable_json( $value );
		return '' === $json ? '' : hash( 'sha256', $json );
	}

	private static function stable_json( $value ) {
		$value = self::canonicalize( $value );
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	private static function canonicalize( $value ) {
		if ( is_wp_error( $value ) ) return array( 'error_code'=>$value->get_error_code() );
		if ( is_array( $value ) ) {
			$keys = array_keys( $value );
			$list = array() === $value || $keys === range( 0, count( $value ) - 1 );
			if ( $list ) return array_map( array( __CLASS__, 'canonicalize' ), $value );
			sort( $keys, SORT_STRING );
			$out = array();
			foreach ( $keys as $key ) $out[ (string) $key ] = self::canonicalize( $value[ $key ] );
			return $out;
		}
		if ( is_object( $value ) ) return self::canonicalize( get_object_vars( $value ) );
		return $value;
	}

	private static function now_epoch() {
		return class_exists( 'MAD4B_SCP_Time_Policy' ) && method_exists( 'MAD4B_SCP_Time_Policy', 'now_epoch' ) ? (int) MAD4B_SCP_Time_Policy::now_epoch() : time();
	}

	public static function can_manage( $input = null ) {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}
}

MAD4B_SCP_Provider_Shadow_Read::boot();
