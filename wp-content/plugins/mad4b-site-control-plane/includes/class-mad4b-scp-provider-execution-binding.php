<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-neutral exact execution binding.
 *
 * This service never calls providers and never grants authority. It binds any
 * governed plan family to exact semantic traits, provider certification,
 * release-ring maturity, and capability-descriptor generation.
 */
final class MAD4B_SCP_Provider_Execution_Binding {
	const CONTRACT = 'mad4b.provider-execution-binding.v1';
	const REVALIDATION_CONTRACT = 'mad4b.provider-execution-binding-revalidation.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register_read( 'mad4b/provider-execution-binding', 'Provider Execution Binding', 'bind' );
		self::register_read( 'mad4b/provider-execution-binding-revalidate', 'Provider Execution Binding Revalidate', 'revalidate' );
	}

	private static function register_read( $name, $label, $method ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . '; exact semantic/certification binding only. Non-authorizing.',
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

	public static function bind( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$family = sanitize_key( isset( $input['plan_family'] ) ? (string) $input['plan_family'] : '' );
		$provider = sanitize_key( isset( $input['provider_id'] ) ? (string) $input['provider_id'] : '' );
		$capability = trim( (string) ( isset( $input['capability_id'] ) ? $input['capability_id'] : '' ) );
		$traits = isset( $input['required_traits'] ) && is_array( $input['required_traits'] ) ? $input['required_traits'] : array();
		$ring = sanitize_key( isset( $input['release_ring'] ) ? (string) $input['release_ring'] : 'shadow' );
		$certified = ! array_key_exists( 'require_certified', $input ) || ! empty( $input['require_certified'] );

		if ( '' === $family ) return new WP_Error( 'mad4b_provider_binding_plan_family_required', 'Exact plan family is required.' );
		if ( '' === $provider || strlen( $provider ) > 64 ) return new WP_Error( 'mad4b_provider_binding_provider_invalid', 'Exact provider id is required.' );
		if ( '' === $capability || strlen( $capability ) > 100 ) return new WP_Error( 'mad4b_provider_binding_capability_invalid', 'Exact capability id is required.' );
		if ( ! in_array( $ring, array( 'shadow', 'canary', 'active' ), true ) ) return new WP_Error( 'mad4b_provider_binding_ring_invalid', 'Release ring is invalid.' );
		if ( ! class_exists( 'MAD4B_SCP_Capability_Traits' ) ) return new WP_Error( 'mad4b_provider_binding_traits_unavailable', 'Capability traits unavailable.' );

		$resolution = MAD4B_SCP_Capability_Traits::resolve(
			array(
				'capability_id' => $capability,
				'required_traits' => $traits,
				'release_ring' => $ring,
				'require_certified' => $certified,
				'preferred_provider' => $provider,
			)
		);
		if ( ! is_array( $resolution ) || empty( $resolution['selected_provider'] ) || ! hash_equals( $provider, (string) $resolution['selected_provider'] ) ) {
			return new WP_Error( 'mad4b_provider_binding_provider_not_eligible', 'Exact provider is not eligible.', array( 'resolution' => $resolution ) );
		}

		$selected = null;
		foreach ( (array) ( isset( $resolution['eligible'] ) ? $resolution['eligible'] : array() ) as $row ) {
			if ( isset( $row['provider_id'] ) && hash_equals( $provider, (string) $row['provider_id'] ) ) {
				$selected = $row;
				break;
			}
		}
		if ( ! is_array( $selected ) ) return new WP_Error( 'mad4b_provider_binding_selection_missing', 'Selected provider evidence missing.' );

		$profile_sha = strtolower( trim( (string) ( isset( $selected['profile_fingerprint'] ) ? $selected['profile_fingerprint'] : '' ) ) );
		$cert_sha = strtolower( trim( (string) ( isset( $selected['certification_fingerprint'] ) ? $selected['certification_fingerprint'] : '' ) ) );
		$descriptor_sha = strtolower( trim( (string) ( isset( $selected['descriptor_generation_sha256'] ) ? $selected['descriptor_generation_sha256'] : '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $profile_sha ) ) return new WP_Error( 'mad4b_provider_binding_profile_fingerprint_invalid', 'Profile fingerprint invalid.' );
		if ( $certified && 1 !== preg_match( '/^[a-f0-9]{64}$/', $cert_sha ) ) return new WP_Error( 'mad4b_provider_binding_certification_fingerprint_invalid', 'Certification fingerprint invalid.' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $descriptor_sha ) ) return new WP_Error( 'mad4b_provider_binding_descriptor_generation_invalid', 'Descriptor generation invalid.' );

		$binding = array(
			'contract' => self::CONTRACT,
			'plan_family' => $family,
			'provider_id' => $provider,
			'capability_id' => (string) $selected['capability_id'],
			'required_traits' => self::canonicalize( $traits ),
			'provider_profile_fingerprint' => $profile_sha,
			'capability_certification_fingerprint' => $cert_sha,
			'descriptor_generation_sha256' => $descriptor_sha,
			'provider_release_ring' => $ring,
			'certification_level' => isset( $selected['certification_level'] ) ? (string) $selected['certification_level'] : '',
			'activation_stage' => isset( $selected['activation_stage'] ) ? (string) $selected['activation_stage'] : '',
			'resolution_fingerprint' => isset( $resolution['resolution_fingerprint'] ) ? (string) $resolution['resolution_fingerprint'] : '',
			'require_certified' => (bool) $certified,
			'implicit_tie_breaking' => false,
			'provider_execution_performed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
		$binding['binding_sha256'] = self::digest( $binding );
		return $binding;
	}

	public static function revalidate( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$binding = isset( $input['binding'] ) && is_array( $input['binding'] ) ? $input['binding'] : array();
		if ( self::CONTRACT !== (string) ( isset( $binding['contract'] ) ? $binding['contract'] : '' ) ) return new WP_Error( 'mad4b_provider_binding_contract_invalid', 'Binding contract invalid.' );
		$sha = strtolower( trim( (string) ( isset( $binding['binding_sha256'] ) ? $binding['binding_sha256'] : '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha ) || ! hash_equals( $sha, self::digest( $binding ) ) ) return new WP_Error( 'mad4b_provider_binding_digest_invalid', 'Binding digest mismatch.' );

		$current = self::bind(
			array(
				'plan_family' => isset( $binding['plan_family'] ) ? $binding['plan_family'] : '',
				'provider_id' => isset( $binding['provider_id'] ) ? $binding['provider_id'] : '',
				'capability_id' => isset( $binding['capability_id'] ) ? $binding['capability_id'] : '',
				'required_traits' => isset( $binding['required_traits'] ) && is_array( $binding['required_traits'] ) ? $binding['required_traits'] : array(),
				'release_ring' => isset( $binding['provider_release_ring'] ) ? $binding['provider_release_ring'] : 'shadow',
				'require_certified' => ! empty( $binding['require_certified'] ),
			)
		);
		if ( is_wp_error( $current ) ) return $current;
		if ( ! hash_equals( $sha, (string) $current['binding_sha256'] ) ) return new WP_Error( 'mad4b_provider_binding_drift', 'Provider binding drifted.', array( 'current_binding' => $current ) );

		return array(
			'contract' => self::REVALIDATION_CONTRACT,
			'valid' => true,
			'binding_sha256' => $sha,
			'provider_execution_performed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function digest( $value ) {
		if ( is_array( $value ) ) unset( $value['binding_sha256'] );
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

MAD4B_SCP_Provider_Execution_Binding::boot();
