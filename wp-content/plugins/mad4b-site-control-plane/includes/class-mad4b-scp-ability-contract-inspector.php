<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical structural inspection for one WordPress Ability.
 *
 * This class classifies contracts and provenance only. It never caches or grants
 * runtime authority. Mutable grants, approvals, policy and provider readiness
 * remain live execution-time checks.
 */
final class MAD4B_SCP_Ability_Contract_Inspector {
	const CONTRACT = 'mad4b.ability-contract-inspector.v1';
	const CLASSIFICATION_CONTRACT = 'mad4b.ability-classification.v2';
	const MAX_CANONICAL_DEPTH = 64;

	public static function inspect( $ability_name ) {
		if ( class_exists( 'MAD4B_SCP_Unified_Capability_Gateway' ) && ! MAD4B_SCP_Unified_Capability_Gateway::runtime_blog_matches() ) {
			return new WP_Error( 'mad4b_projection_blog_switch_denied', 'Use a fresh request to the target site.' );
		}
		try {
			return self::inspect_ability( $ability_name );
		} catch ( Throwable $error ) {
			return new WP_Error( 'mad4b_chatgpt_projection_ability_inspection_failed', 'Ability inspection failed; this capability is unavailable.' );
		}
	}

	public static function site_binding() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return array();
		return array(
			'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
			'origin' => MAD4B_SCP_Site_Profile::current_origin(),
			'environment' => MAD4B_SCP_Site_Profile::current_environment(),
			'profile_revision' => MAD4B_SCP_Site_Profile::revision(),
		);
	}

	public static function digest( $contract, $value ) {
		$contract = trim( (string) $contract );
		if ( '' === $contract || strlen( $contract ) > 191 ) {
			return new WP_Error( 'mad4b_ability_contract_digest_invalid', 'Ability contract digest namespace is invalid.' );
		}
		$json = self::canonical_json( $value );
		if ( is_wp_error( $json ) ) return $json;
		return hash( 'sha256', 'mad4b:' . $contract . "\n" . $json );
	}

	public static function bounded_metadata( $value, $bytes ) {
		$value = (string) $value;
		$bytes = max( 0, (int) $bytes );
		if ( strlen( $value ) <= $bytes ) return $value;
		if ( function_exists( 'mb_strcut' ) ) return mb_strcut( $value, 0, $bytes, 'UTF-8' );
		return wp_check_invalid_utf8( substr( $value, 0, $bytes ), true );
	}

	private static function inspect_ability( $ability_name ) {
		$ability_name = trim( (string) $ability_name );
		if ( '' === $ability_name || ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) {
			return new WP_Error( 'mad4b_chatgpt_projection_ability_unavailable', 'Requested Ability is not registered in the current site runtime.', array( 'ability_name' => $ability_name ) );
		}
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) || ! method_exists( $ability, 'execute' ) ) {
			return new WP_Error( 'mad4b_chatgpt_projection_ability_contract_unavailable', 'Requested Ability does not expose the required WordPress Ability contract.', array( 'ability_name' => $ability_name ) );
		}

		$meta = $ability->get_meta();
		$meta = is_array( $meta ) ? $meta : array();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$readonly_declared = array_key_exists( 'readonly', $annotations ) && is_bool( $annotations['readonly'] );
		$category = method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		$surface = isset( $mcp['surface'] ) ? sanitize_key( (string) $mcp['surface'] ) : '';
		$mutation_lanes = array( 'write', 'developer', 'enrollment', 'internal', 'breakglass', 'developer-breakglass' );
		$lane = 'unclassified';
		if ( in_array( $surface, $mutation_lanes, true ) || in_array( $surface, array( 'admin', 'content' ), true ) ) {
			$lane = $surface;
		} elseif ( $readonly_declared && true === $annotations['readonly'] && in_array( $surface, array( '', 'read' ), true ) ) {
			$lane = 'read';
		} elseif ( $readonly_declared && false === $annotations['readonly'] && '' === $surface ) {
			$lane = 'write';
		}

		$readonly = $readonly_declared && true === $annotations['readonly'];
		$known = 'unclassified' !== $lane;
		$schema_digest = self::input_schema_sha256( $ability );
		if ( '' === $schema_digest ) return new WP_Error( 'mad4b_chatgpt_projection_schema_invalid', 'Ability schema cannot be serialized.' );

		$provider = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::provider_for_ability( 'mad4b-' . $lane, $ability_name ) : null;
		$breakglass = in_array( $lane, array( 'breakglass', 'developer-breakglass' ), true ) || in_array(
			$ability_name,
			array_merge(
				class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::core_tools( 'mad4b-breakglass' ) : array(),
				class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::core_tools( 'mad4b-developer-breakglass' ) : array()
			),
			true
		);

		$projection_blockers = array();
		if ( ! $known ) $projection_blockers[] = 'ability_classification_required';
		if ( 'internal' === $lane ) $projection_blockers[] = 'internal_surface_not_direct';
		$projection_eligible = empty( $projection_blockers );

		$boundary_verified = class_exists( 'MAD4B_SCP_Authorization' ) && MAD4B_SCP_Authorization::execution_boundary_verified( $ability );
		$execution_blocker = ! $projection_eligible
			? 'ability_projection_policy_blocked'
			: ( ! $readonly && ! $boundary_verified
				? 'governed_execution_boundary_required'
				: ( 'read' !== $lane && null === $provider ? 'original_lane_not_mounted' : '' ) );
		$execution_eligible = '' === $execution_blocker;
		$execution_lane = $execution_eligible ? $lane : 'none';

		$classification_basis = array(
			'meta' => $meta,
			'category' => $category,
			'output_schema' => method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : null,
			'execution_provider' => $provider,
			'boundary_verified' => $boundary_verified,
			'classification' => $lane,
			'known' => $known,
			'projection_eligible' => $projection_eligible,
			'execution_lane' => $execution_lane,
			'projection_blockers' => $projection_blockers,
		);
		$classification_sha256 = self::digest( self::CLASSIFICATION_CONTRACT, $classification_basis );
		if ( is_wp_error( $classification_sha256 ) ) {
			return new WP_Error( 'mad4b_chatgpt_projection_classification_invalid', 'Projection classification cannot be serialized canonically.' );
		}

		return array(
			'inspector_contract' => self::CONTRACT,
			'classification_contract' => self::CLASSIFICATION_CONTRACT,
			'ability_name' => $ability_name,
			'input_schema_sha256' => $schema_digest,
			'classification_sha256' => $classification_sha256,
			'classification' => $lane,
			'known' => (bool) $known,
			'lane' => $lane,
			'readonly' => (bool) $readonly,
			'readonly_declared' => (bool) $readonly_declared,
			'conservative_mutation' => ! $readonly_declared,
			'breakglass' => (bool) $breakglass,
			'category' => $category,
			'execution_boundary' => isset( $mcp['mad4b_execution_boundary'] ) ? (string) $mcp['mad4b_execution_boundary'] : '',
			'execution_provider' => null === $provider ? '' : (string) $provider,
			'execution_boundary_verified' => $boundary_verified,
			'execution_blocker' => $execution_blocker,
			'label' => method_exists( $ability, 'get_label' ) ? self::bounded_metadata( $ability->get_label(), 160 ) : '',
			'description' => method_exists( $ability, 'get_description' ) ? self::bounded_metadata( $ability->get_description(), 2048 ) : '',
			'projection_eligible' => (bool) $projection_eligible,
			'execution_eligible' => (bool) $execution_eligible,
			'execution_lane' => $execution_lane,
			'projection_blockers' => $projection_blockers,
		);
	}

	private static function input_schema_sha256( $ability ) {
		$schema = is_object( $ability ) && method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) return '';
		return hash( 'sha256', $json );
	}

	private static function canonical_json( $value ) {
		$normalized = self::normalize( $value, 0 );
		if ( is_wp_error( $normalized ) ) return $normalized;
		$json = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json
			? new WP_Error( 'mad4b_ability_contract_canonical_json_failed', 'Ability contract cannot be encoded canonically.' )
			: (string) $json;
	}

	private static function normalize( $value, $depth ) {
		if ( $depth > self::MAX_CANONICAL_DEPTH ) return new WP_Error( 'mad4b_ability_contract_too_deep', 'Ability contract exceeds the canonical nesting limit.' );
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_string( $value ) ) return $value;
		if ( is_float( $value ) ) return is_finite( $value ) ? $value : new WP_Error( 'mad4b_ability_contract_float_invalid', 'Ability contract contains a non-finite number.' );
		if ( is_object( $value ) ) $value = get_object_vars( $value );
		if ( ! is_array( $value ) ) return new WP_Error( 'mad4b_ability_contract_type_unsupported', 'Ability contract contains an unsupported value.' );
		if ( self::is_list( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				$normalized = self::normalize( $item, $depth + 1 );
				if ( is_wp_error( $normalized ) ) return $normalized;
				$out[] = $normalized;
			}
			return $out;
		}
		$keys = array_map( 'strval', array_keys( $value ) );
		sort( $keys, SORT_STRING );
		$out = array();
		foreach ( $keys as $key ) {
			$normalized = self::normalize( $value[ $key ], $depth + 1 );
			if ( is_wp_error( $normalized ) ) return $normalized;
			$out[ $key ] = $normalized;
		}
		return $out;
	}

	private static function is_list( array $value ) {
		$expected = 0;
		foreach ( $value as $key => $_ ) {
			if ( $key !== $expected ) return false;
			$expected++;
		}
		return true;
	}
}
