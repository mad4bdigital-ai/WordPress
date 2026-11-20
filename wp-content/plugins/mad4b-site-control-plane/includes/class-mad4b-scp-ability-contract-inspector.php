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
		try {
			if ( class_exists( 'MAD4B_SCP_Unified_Capability_Gateway' ) && ! MAD4B_SCP_Unified_Capability_Gateway::runtime_blog_matches() ) {
				return new WP_Error( 'mad4b_projection_blog_switch_denied', 'Use a fresh request to the target site.' );
			}
			return self::inspect_ability( $ability_name );
		} catch ( Throwable $error ) {
			$correlation = self::record_failure( $error, 'ability_inspection', $ability_name );
			return new WP_Error( 'mad4b_chatgpt_projection_ability_inspection_failed', 'Ability inspection failed; this capability is unavailable.', array( 'correlation_id' => $correlation ) );
		}
	}


	/** Best-effort bounded incident evidence; never affect the original denial. */
	public static function record_failure( Throwable $error, $stage, $ability_name ) {
		$correlation = '';
		try {
			if ( class_exists( 'MAD4B_Native_Incident_MU', false )
				&& method_exists( 'MAD4B_Native_Incident_MU', 'correlation_id' )
				&& method_exists( 'MAD4B_Native_Incident_MU', 'record_throwable' ) ) {
				$correlation = MAD4B_Native_Incident_MU::correlation_id();
				MAD4B_Native_Incident_MU::record_throwable( $error, $stage, $ability_name, $correlation );
			}
		} catch ( Throwable $ignored ) { /* A recorder failure cannot escape. */ }
		return is_string( $correlation ) && preg_match( '/^[a-f0-9]{32}$/D', $correlation ) ? $correlation : '';
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
		// Dispatch transport metadata must not erase the four *original*
		// readonly discover/info and Direct Read identities. Require exact names,
		// the original read category and an explicitly authored descriptor marker.
		// All execute/mutation and foreign declarations remain fail-closed.
		$source_read_dispatch = $readonly_declared
			&& true === $annotations['readonly']
			&& 'mad4b-read' === $category
			&& 'read' === ( $mcp['mad4b_descriptor_read_lane'] ?? null )
			&& (
				( 'developer-dispatch' === $surface && in_array( $ability_name, array( 'mad4b/developer-discover', 'mad4b/developer-info' ), true ) )
				|| ( 'enrollment' === $surface && in_array( $ability_name, array( 'mad4b/enrollment-discover', 'mad4b/enrollment-info' ), true ) )
				|| ( 'direct' === $surface && in_array( $ability_name, array( 'mad4b/direct-connection-status', 'mad4b/direct-list-posts', 'mad4b/direct-get-post' ), true ) )
			);
		// Seven named first-party readonly abilities are *either* the exact declared
		// readonly source identity or unclassified. Never let an enrollment
		// transport label override their read lane or confer a write lane.
		// Special transport names are not generic write surfaces. Exact
		// first-party execute/permission callback provenance and final execution
		// admission must be proven from the registered WP_Ability itself.
		$original_core_dispatch = class_exists( 'MAD4B_SCP_Governed_Ability_Census' )
			&& in_array( $ability_name, array( 'mad4b/write-execute', 'mad4b/developer-execute', 'mad4b/enrollment-execute' ), true )
			&& MAD4B_SCP_Governed_Ability_Census::original_dispatch_transport_boundary_verified( $ability );
		$original_direct_post = class_exists( 'MAD4B_SCP_Governed_Ability_Census' )
			&& in_array( $ability_name, array( 'mad4b/direct-create-post', 'mad4b/direct-update-post' ), true )
			&& MAD4B_SCP_Governed_Ability_Census::original_direct_post_boundary_verified( $ability );
		$native_discovery_name = in_array( $ability_name, array(
			'mad4b/developer-discover', 'mad4b/developer-info',
			'mad4b/enrollment-discover', 'mad4b/enrollment-info',
			'mad4b/direct-connection-status', 'mad4b/direct-list-posts', 'mad4b/direct-get-post',
		), true );
		$lane = 'unclassified';
		if ( $source_read_dispatch ) {
			$lane = 'read';
		} elseif ( $native_discovery_name ) {
			$lane = 'unclassified';
		} elseif ( $original_core_dispatch ) {
			$lane = 'mad4b/write-execute' === $ability_name ? 'write'
				: ( 'mad4b/developer-execute' === $ability_name ? 'developer' : 'enrollment' );
		} elseif ( $original_direct_post ) {
			$lane = 'content';
		} elseif ( in_array( $surface, $mutation_lanes, true ) || in_array( $surface, array( 'admin', 'content' ), true ) ) {
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

		// This single original governed Enrollment bootstrap has no generic
		// MAD4B write wrapper by design. Its independent registered policy and
		// exact first-party callback pair are verified from the *real* Ability.
		// This is structural MCP binding only, never permission to run the
		// callback or create a grant.
		$original_operator_boundary = 'enrollment' === $lane
			&& class_exists( 'MAD4B_SCP_Governed_Ability_Census' )
			&& MAD4B_SCP_Governed_Ability_Census::operator_grant_original_boundary_verified( $ability );
		$original_owner_intent_boundary = 'enrollment' === $lane
			&& class_exists( 'MAD4B_SCP_Governed_Ability_Census' )
			&& MAD4B_SCP_Governed_Ability_Census::staging_owner_intent_original_boundary_verified( $ability );
		$original_projection_boundary = 'enrollment' === $lane
			&& class_exists( 'MAD4B_SCP_Governed_Ability_Census' )
			&& MAD4B_SCP_Governed_Ability_Census::chatgpt_projection_original_boundary_verified( $ability );
		// Other reviewed first-party enrollment Step-ups have their own exact
		// permission/approval policies rather than the generic Authorization
		// wrapper. Their registered callback pair must be proven by the census,
		// and the actual WordPress callback must retain final execution admission.
		// This is descriptor provenance only: it never authorizes a tools/call.
		$direct_step_up_census = 'enrollment' === $lane && class_exists( 'MAD4B_SCP_Governed_Ability_Census' )
			? MAD4B_SCP_Governed_Ability_Census::structural_step_up() : array();
		$reviewed_direct_step_up_boundary = 'enrollment' === $lane
			&& ! $readonly
			&& true === ( $mcp['chatgpt_direct_step_up'] ?? null )
			&& true === ( $mcp['exact_chatgpt_client_required'] ?? null )
			&& false === ( $mcp['generic_remote_admin'] ?? null )
			&& isset( $direct_step_up_census[ $ability_name ] )
			&& 'verified_first_party_php_callbacks' === ( $direct_step_up_census[ $ability_name ]['source'] ?? null )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', (string) ( $direct_step_up_census[ $ability_name ]['callback_pair_digest'] ?? '' ) )
			&& class_exists( 'MAD4B_SCP_Execution_Fence' )
			&& MAD4B_SCP_Execution_Fence::final_execution_wrapper_verified( $ability_name );
		// Runtime Gates Apply is a single *direct-only* exception to generic
		// Staging broker projection. Its original per-call OAuth, site, plan and
		// confirmation gates stay intact; only descriptor provenance is bound.
		$original_runtime_gate_direct = 'enrollment' === $lane
			&& 'mad4b/runtime-gates-apply' === $ability_name
			&& class_exists( 'MAD4B_SCP_Governed_Ability_Census' )
			&& MAD4B_SCP_Governed_Ability_Census::original_runtime_gate_direct_boundary_verified( $ability );
		$original_bounded_boundary = $original_operator_boundary || $original_owner_intent_boundary || $original_projection_boundary || $reviewed_direct_step_up_boundary || $original_core_dispatch || $original_direct_post || $original_runtime_gate_direct;
		$provider = class_exists( 'MAD4B_SCP_Servers' )
			? ( method_exists( 'MAD4B_SCP_Servers', 'provider_for_capability_descriptor' )
				? MAD4B_SCP_Servers::provider_for_capability_descriptor( $original_bounded_boundary ? 'mad4b-chatgpt' : 'mad4b-' . $lane, $ability_name )
				: MAD4B_SCP_Servers::provider_for_ability( $original_bounded_boundary ? 'mad4b-chatgpt' : 'mad4b-' . $lane, $ability_name ) )
			: null;
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

		$boundary_verified = $original_bounded_boundary || ( class_exists( 'MAD4B_SCP_Authorization' ) && MAD4B_SCP_Authorization::execution_boundary_verified( $ability ) );
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
		if ( is_object( $value ) ) {
			if ( ! $value instanceof stdClass ) return new WP_Error( 'mad4b_ability_contract_object_unsupported', 'Ability contract contains an unsupported object type.' );
			$vars = get_object_vars( $value );
			ksort( $vars, SORT_STRING );
			$out = new stdClass();
			foreach ( $vars as $key => $item ) {
				$normalized = self::normalize( $item, $depth + 1 );
				if ( is_wp_error( $normalized ) ) return $normalized;
				$out->{(string) $key} = $normalized;
			}
			return $out;
		}
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
