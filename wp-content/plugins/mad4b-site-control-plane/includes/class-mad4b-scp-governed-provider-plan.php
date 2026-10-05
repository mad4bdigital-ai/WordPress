<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-neutral, data-governance-bound plan primitive.
 *
 * Every external provider/model plan family can reuse this contract before an
 * outbound call. It binds exact ContentJob, provider capability/certification,
 * request identity and a durable Data Governance decision artifact.
 */
final class MAD4B_SCP_Governed_Provider_Plan {
	const CONTRACT = 'mad4b.governed-provider-plan.v1';
	const REVALIDATION_CONTRACT = 'mad4b.governed-provider-plan-revalidation.v1';
	const ABILITY = 'mad4b/governed-provider-plan-build';
	const REVALIDATE_ABILITY = 'mad4b/governed-provider-plan-revalidate';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register_read( self::ABILITY, 'Build Governed Provider Plan', 'build' );
		self::register_read( self::REVALIDATE_ABILITY, 'Revalidate Governed Provider Plan', 'revalidate' );
	}

	private static function register_read( $name, $label, $method ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . '; no provider execution and no mutation authority.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
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

	public static function build( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$family = sanitize_key( isset( $input['plan_family'] ) ? $input['plan_family'] : '' );
		$job_id = strtolower( trim( (string) ( isset( $input['job_id'] ) ? $input['job_id'] : '' ) ) );
		$provider_id = sanitize_key( isset( $input['provider_id'] ) ? $input['provider_id'] : '' );
		$capability_id = trim( (string) ( isset( $input['capability_id'] ) ? $input['capability_id'] : '' ) );
		$governance_id = strtolower( trim( (string) ( isset( $input['data_governance_artifact_id'] ) ? $input['data_governance_artifact_id'] : '' ) ) );
		$request_sha = strtolower( trim( (string) ( isset( $input['request_sha256'] ) ? $input['request_sha256'] : '' ) ) );
		$purpose = sanitize_key( isset( $input['purpose'] ) ? $input['purpose'] : '' );

		if ( '' === $family || strlen( $family ) > 96 ) return new WP_Error( 'mad4b_governed_provider_plan_family_invalid', 'Governed provider plan family is required.' );
		if ( 1 !== preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $job_id ) ) return new WP_Error( 'mad4b_governed_provider_job_invalid', 'Governed provider plan requires an exact ContentJob identity.' );
		if ( '' === $provider_id || strlen( $provider_id ) > 64 ) return new WP_Error( 'mad4b_governed_provider_identity_invalid', 'Governed provider plan requires an exact provider identity.' );
		if ( '' === $capability_id || strlen( $capability_id ) > 100 ) return new WP_Error( 'mad4b_governed_provider_capability_invalid', 'Governed provider plan requires an exact capability identity.' );
		if ( 1 !== preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $governance_id ) ) return new WP_Error( 'mad4b_governed_provider_governance_artifact_invalid', 'Governed provider plan requires an exact Data Governance artifact identity.' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $request_sha ) ) return new WP_Error( 'mad4b_governed_provider_request_invalid', 'Governed provider plan requires an exact request SHA-256.' );
		if ( '' === $purpose ) return new WP_Error( 'mad4b_governed_provider_purpose_invalid', 'Governed provider plan purpose is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Data_Governance' ) || ! class_exists( 'MAD4B_SCP_Provider_Execution_Binding' ) ) {
			return new WP_Error( 'mad4b_governed_provider_dependencies_unavailable', 'Governed provider planning dependencies are unavailable.' );
		}

		$governance = MAD4B_SCP_Data_Governance::validate_decision_artifact( $job_id, $governance_id, $provider_id );
		if ( is_wp_error( $governance ) ) return $governance;
		if ( 'ALLOW' !== (string) ( isset( $governance['decision'] ) ? $governance['decision'] : '' ) ) {
			return new WP_Error( 'mad4b_governed_provider_decision_not_allow', 'External provider plan requires an ALLOW Data Governance decision.', array( 'decision' => isset( $governance['decision'] ) ? $governance['decision'] : '' ) );
		}

		$binding = MAD4B_SCP_Provider_Execution_Binding::bind(
			array(
				'plan_family' => $family,
				'provider_id' => $provider_id,
				'capability_id' => $capability_id,
				'required_traits' => isset( $input['required_traits'] ) && is_array( $input['required_traits'] ) ? $input['required_traits'] : array(),
				'release_ring' => isset( $input['release_ring'] ) ? $input['release_ring'] : 'shadow',
				'require_certified' => ! array_key_exists( 'require_certified', $input ) || ! empty( $input['require_certified'] ),
			)
		);
		if ( is_wp_error( $binding ) ) return $binding;

		$plan = array(
			'contract' => self::CONTRACT,
			'plan_family' => $family,
			'job_id' => $job_id,
			'provider_id' => $provider_id,
			'capability_id' => $capability_id,
			'purpose' => $purpose,
			'request_sha256' => $request_sha,
			'data_governance_artifact_id' => (string) $governance['artifact_id'],
			'data_governance_decision' => (string) $governance['decision'],
			'data_governance_fingerprint' => (string) $governance['decision_fingerprint'],
			'rights_summary_fingerprint' => (string) $governance['rights_summary_fingerprint'],
			'processor_profile_fingerprint' => (string) $governance['processor_profile_fingerprint'],
			'policy_revision' => (string) $governance['policy_revision'],
			'provider_binding' => $binding,
			'provider_binding_sha256' => (string) $binding['binding_sha256'],
			'execution_ready' => true,
			'provider_execution_performed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
		$plan['plan_sha256'] = self::digest( $plan );
		return $plan;
	}

	public static function revalidate( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$plan = isset( $input['plan'] ) && is_array( $input['plan'] ) ? $input['plan'] : array();
		if ( self::CONTRACT !== (string) ( isset( $plan['contract'] ) ? $plan['contract'] : '' ) ) return new WP_Error( 'mad4b_governed_provider_plan_contract_invalid', 'Governed provider plan contract is invalid.' );
		$sha = strtolower( trim( (string) ( isset( $plan['plan_sha256'] ) ? $plan['plan_sha256'] : '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha ) || ! hash_equals( $sha, self::digest( $plan ) ) ) return new WP_Error( 'mad4b_governed_provider_plan_digest_invalid', 'Governed provider plan digest mismatch.' );

		$governance = MAD4B_SCP_Data_Governance::validate_decision_artifact(
			(string) $plan['job_id'],
			(string) $plan['data_governance_artifact_id'],
			(string) $plan['provider_id']
		);
		if ( is_wp_error( $governance ) ) return $governance;
		foreach ( array(
			'decision' => 'data_governance_decision',
			'decision_fingerprint' => 'data_governance_fingerprint',
			'rights_summary_fingerprint' => 'rights_summary_fingerprint',
			'processor_profile_fingerprint' => 'processor_profile_fingerprint',
			'policy_revision' => 'policy_revision',
		) as $current_key => $plan_key ) {
			if ( ! isset( $governance[ $current_key ], $plan[ $plan_key ] ) || ! hash_equals( (string) $plan[ $plan_key ], (string) $governance[ $current_key ] ) ) {
				return new WP_Error( 'mad4b_governed_provider_governance_drift', 'Data Governance evidence changed after provider planning.', array( 'field' => $plan_key ) );
			}
		}

		$binding = MAD4B_SCP_Provider_Execution_Binding::revalidate( array( 'binding' => isset( $plan['provider_binding'] ) ? $plan['provider_binding'] : array() ) );
		if ( is_wp_error( $binding ) ) return $binding;
		return array(
			'contract' => self::REVALIDATION_CONTRACT,
			'valid' => true,
			'plan_sha256' => $sha,
			'provider_binding_sha256' => (string) $plan['provider_binding_sha256'],
			'data_governance_fingerprint' => (string) $plan['data_governance_fingerprint'],
			'provider_execution_performed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function digest( $value ) {
		if ( is_array( $value ) ) unset( $value['plan_sha256'] );
		$json = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : hash( 'sha256', $json );
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}

MAD4B_SCP_Governed_Provider_Plan::boot();
