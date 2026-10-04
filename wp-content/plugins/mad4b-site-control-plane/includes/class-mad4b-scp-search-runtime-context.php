<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-neutral Search Profile and Effective Search Context runtime.
 *
 * Search configuration is intentionally non-authorizing. Business overlays may
 * specialize observation policy, but they can never widen authority, egress,
 * Production policy, Breakglass or WordPress mutation authority.
 */
final class MAD4B_SCP_Search_Runtime_Context {
	const FACT_CONTRACT = 'mad4b.search-runtime-fact.v1';
	const PROFILE_CONTRACT = 'mad4b.search-intelligence-profile.v1';
	const PROFILE_PLAN_CONTRACT = 'mad4b.search-intelligence-profile-plan.v1';
	const CONTEXT_CONTRACT = 'mad4b.effective-search-context.v1';
	const STATUS_CONTRACT = 'mad4b.search-context-status.v1';
	const INVALIDATION_CONTRACT = 'mad4b.search-context-invalidation.v1';
	const OPTION = 'mad4b_search_intelligence_profiles_v1';
	const MAX_PROFILES = 64;
	const MAX_LIST_ITEMS = 256;

	private static $profiles = null;

	private static $overlay_order = array(
		'search_profile',
		'brand_profile',
		'market_overlay',
		'language_overlay',
		'surface_overlay',
		'experiment_policy',
		'request_safe_override',
	);

	private static $policy_fields = array(
		'markets',
		'language_policy',
		'surface_policy',
		'provider_policy',
		'budget_policy',
		'refresh_policy',
		'freshness_policy',
		'priority_policy',
		'experiment_policy',
		'objective',
	);

	private static $protected_fragments = array(
		'authority',
		'authorization',
		'breakglass',
		'production',
		'egress',
		'secret',
		'credential',
		'wordpress_mutation',
		'mutation_authority',
		'security_policy',
	);

	public static function reset_request_cache() {
		self::$profiles = null;
		return true;
	}

	private static function stable( $value ) {
		if ( is_array( $value ) ) {
			$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $is_list ) ksort( $value, SORT_STRING );
			foreach ( $value as $key => $item ) $value[ $key ] = self::stable( $item );
		}
		return $value;
	}

	public static function digest( $value ) {
		return hash( 'sha256', wp_json_encode( self::stable( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function clean_id( $value, $field, $max = 96 ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > $max || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value ) ) {
			return new WP_Error( 'mad4b_search_context_identity_invalid', 'Search context identity is invalid.', array( 'field' => $field ) );
		}
		return $value;
	}

	private static function normalize_scalar_list( $items, $limit = self::MAX_LIST_ITEMS, $case = 'preserve' ) {
		$result = array();
		foreach ( (array) $items as $item ) {
			if ( is_array( $item ) || is_object( $item ) ) continue;
			$item = trim( (string) $item );
			if ( '' === $item || strlen( $item ) > 191 ) continue;
			if ( 'lower' === $case ) $item = strtolower( $item );
			if ( 'upper' === $case ) $item = strtoupper( $item );
			$result[] = $item;
			if ( count( $result ) >= $limit ) break;
		}
		$result = array_values( array_unique( $result ) );
		sort( $result, SORT_STRING );
		return $result;
	}

	private static function key_is_protected( $key ) {
		$key = strtolower( trim( (string) $key ) );
		foreach ( self::$protected_fragments as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) return true;
		}
		return false;
	}

	private static function protected_path( $value, $path = '' ) {
		if ( ! is_array( $value ) ) return '';
		foreach ( $value as $key => $item ) {
			$current = '' === $path ? (string) $key : $path . '.' . (string) $key;
			if ( self::key_is_protected( $key ) ) return $current;
			$nested = self::protected_path( $item, $current );
			if ( '' !== $nested ) return $nested;
		}
		return '';
	}

	private static function normalize_overlay( $overlay, $source ) {
		if ( null === $overlay || array() === $overlay ) return array();
		if ( ! is_array( $overlay ) ) {
			return new WP_Error( 'mad4b_search_context_overlay_invalid', 'Search context overlay must be an object.', array( 'source' => $source ) );
		}
		$protected = self::protected_path( $overlay );
		if ( '' !== $protected ) {
			return new WP_Error(
				'mad4b_search_context_security_widening_denied',
				'Search specialization cannot contain authority, Production, Breakglass, egress, secret or mutation-authority fields.',
				array( 'source' => $source, 'path' => $protected )
			);
		}
		$clean = array();
		foreach ( $overlay as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( ! in_array( $key, self::$policy_fields, true ) ) {
				return new WP_Error( 'mad4b_search_context_overlay_field_denied', 'Unknown Search context overlay field.', array( 'source' => $source, 'field' => $key ) );
			}
			if ( 'markets' === $key ) {
				$clean[ $key ] = self::normalize_scalar_list( $value, 128, 'upper' );
				continue;
			}
			if ( 'objective' === $key ) {
				$clean[ $key ] = sanitize_text_field( (string) $value );
				continue;
			}
			if ( ! is_array( $value ) ) {
				return new WP_Error( 'mad4b_search_context_policy_invalid', 'Search policy fields must be objects.', array( 'source' => $source, 'field' => $key ) );
			}
			$clean[ $key ] = self::stable( $value );
		}
		ksort( $clean, SORT_STRING );
		return $clean;
	}

	private static function merge_overlay( array $base, array $overlay ) {
		foreach ( $overlay as $key => $value ) {
			if (
				isset( $base[ $key ] ) &&
				is_array( $base[ $key ] ) &&
				is_array( $value ) &&
				! empty( $base[ $key ] ) &&
				! empty( $value ) &&
				array_keys( $base[ $key ] ) !== range( 0, count( $base[ $key ] ) - 1 ) &&
				array_keys( $value ) !== range( 0, count( $value ) - 1 )
			) {
				$base[ $key ] = self::merge_overlay( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return self::stable( $base );
	}

	public static function runtime_fact( array $input ) {
		$fact_id = self::clean_id( isset( $input['fact_id'] ) ? $input['fact_id'] : '', 'fact_id', 128 );
		if ( is_wp_error( $fact_id ) ) return $fact_id;
		$fact_type = self::clean_id( isset( $input['fact_type'] ) ? $input['fact_type'] : '', 'fact_type', 96 );
		if ( is_wp_error( $fact_type ) ) return $fact_type;
		$source = sanitize_text_field( (string) ( isset( $input['source'] ) ? $input['source'] : '' ) );
		$observed_at = sanitize_text_field( (string) ( isset( $input['observed_at'] ) ? $input['observed_at'] : '' ) );
		$expires_at = sanitize_text_field( (string) ( isset( $input['expires_at'] ) ? $input['expires_at'] : '' ) );
		$source_generation = sanitize_text_field( (string) ( isset( $input['source_generation'] ) ? $input['source_generation'] : '' ) );
		$confidence = isset( $input['confidence'] ) ? (float) $input['confidence'] : -1;
		if ( '' === $source || '' === $observed_at || '' === $source_generation || $confidence < 0 || $confidence > 1 ) {
			return new WP_Error( 'mad4b_search_runtime_fact_provenance_required', 'Runtime fact requires source, source_generation, observed_at and confidence.' );
		}
		$fact = array(
			'contract' => self::FACT_CONTRACT,
			'fact_id' => $fact_id,
			'fact_type' => $fact_type,
			'value' => isset( $input['value'] ) ? self::stable( $input['value'] ) : null,
			'source' => $source,
			'source_generation' => $source_generation,
			'confidence' => round( $confidence, 6 ),
			'observed_at' => $observed_at,
			'expires_at' => $expires_at,
			'authorizing' => false,
		);
		$fact['fingerprint'] = self::digest( $fact );
		return $fact;
	}

	private static function normalize_profile( array $raw, $revision ) {
		$protected = self::protected_path( $raw );
		if ( '' !== $protected ) {
			return new WP_Error(
				'mad4b_search_profile_security_widening_denied',
				'Search Profile cannot encode authority, Production, Breakglass, egress, secret or mutation-authority fields.',
				array( 'path' => $protected )
			);
		}
		$profile_id = self::clean_id( isset( $raw['profile_id'] ) ? $raw['profile_id'] : '', 'profile_id' );
		if ( is_wp_error( $profile_id ) ) return $profile_id;
		$site_uuid = self::clean_id( isset( $raw['site_uuid'] ) ? $raw['site_uuid'] : '', 'site_uuid', 128 );
		if ( is_wp_error( $site_uuid ) ) return $site_uuid;
		$brand_id = self::clean_id( isset( $raw['brand_id'] ) ? $raw['brand_id'] : 'default', 'brand_id' );
		if ( is_wp_error( $brand_id ) ) return $brand_id;

		$policy = array();
		foreach ( self::$policy_fields as $field ) {
			if ( ! array_key_exists( $field, $raw ) ) continue;
			$normalized = self::normalize_overlay( array( $field => $raw[ $field ] ), 'search_profile' );
			if ( is_wp_error( $normalized ) ) return $normalized;
			$policy[ $field ] = $normalized[ $field ];
		}

		$profile = array(
			'contract' => self::PROFILE_CONTRACT,
			'profile_id' => $profile_id,
			'site_uuid' => $site_uuid,
			'brand_id' => $brand_id,
			'enabled' => ! array_key_exists( 'enabled', $raw ) || ! empty( $raw['enabled'] ),
			'revision' => max( 1, (int) $revision ),
			'markets' => isset( $policy['markets'] ) ? $policy['markets'] : array(),
			'language_policy' => isset( $policy['language_policy'] ) ? $policy['language_policy'] : array(),
			'surface_policy' => isset( $policy['surface_policy'] ) ? $policy['surface_policy'] : array(),
			'provider_policy' => isset( $policy['provider_policy'] ) ? $policy['provider_policy'] : array(),
			'budget_policy' => isset( $policy['budget_policy'] ) ? $policy['budget_policy'] : array(),
			'refresh_policy' => isset( $policy['refresh_policy'] ) ? $policy['refresh_policy'] : array(),
			'freshness_policy' => isset( $policy['freshness_policy'] ) ? $policy['freshness_policy'] : array(),
			'priority_policy' => isset( $policy['priority_policy'] ) ? $policy['priority_policy'] : array(),
			'experiment_policy' => isset( $policy['experiment_policy'] ) ? $policy['experiment_policy'] : array(),
			'objective' => isset( $policy['objective'] ) ? $policy['objective'] : '',
			'authorizing' => false,
		);
		$material = $profile;
		unset( $material['profile_sha256'] );
		$profile['profile_sha256'] = self::digest( $material );
		return $profile;
	}

	private static function stored_profiles() {
		if ( null !== self::$profiles ) return self::$profiles;
		$rows = get_option( self::OPTION, array() );
		if ( ! is_array( $rows ) ) $rows = array();
		$clean = array();
		foreach ( $rows as $profile_id => $row ) {
			if ( ! is_array( $row ) ) continue;
			$id = self::clean_id( $profile_id, 'profile_id' );
			if ( is_wp_error( $id ) ) continue;
			$clean[ $id ] = $row;
		}
		ksort( $clean, SORT_STRING );
		self::$profiles = $clean;
		return self::$profiles;
	}

	public static function profile( $profile_id ) {
		$profile_id = self::clean_id( $profile_id, 'profile_id' );
		if ( is_wp_error( $profile_id ) ) return $profile_id;
		$rows = self::stored_profiles();
		return isset( $rows[ $profile_id ] ) ? $rows[ $profile_id ] : new WP_Error( 'mad4b_search_profile_missing', 'Search Profile is not configured.' );
	}

	public static function profile_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$raw = isset( $input['profile'] ) && is_array( $input['profile'] ) ? $input['profile'] : array();
		$profile_id = self::clean_id( isset( $raw['profile_id'] ) ? $raw['profile_id'] : '', 'profile_id' );
		if ( is_wp_error( $profile_id ) ) return $profile_id;
		$rows = self::stored_profiles();
		$current = isset( $rows[ $profile_id ] ) ? $rows[ $profile_id ] : null;
		$current_revision = is_array( $current ) && isset( $current['revision'] ) ? (int) $current['revision'] : 0;
		$expected_revision = isset( $input['expected_revision'] ) ? (int) $input['expected_revision'] : $current_revision;
		if ( $expected_revision !== $current_revision ) {
			return new WP_Error( 'mad4b_search_profile_revision_drift', 'Search Profile changed since planning.', array( 'current_revision' => $current_revision ) );
		}
		$profile = self::normalize_profile( $raw, $current_revision + 1 );
		if ( is_wp_error( $profile ) ) return $profile;
		if ( is_array( $current ) && isset( $current['site_uuid'] ) && (string) $current['site_uuid'] !== (string) $profile['site_uuid'] ) {
			return new WP_Error( 'mad4b_search_profile_site_identity_immutable', 'Existing Search Profile cannot change site_uuid.' );
		}
		$plan = array(
			'contract' => self::PROFILE_PLAN_CONTRACT,
			'profile' => $profile,
			'current_revision' => $current_revision,
			'expected_revision' => $expected_revision,
			'mutation_performed' => false,
			'authorizing' => false,
			'wordpress_mutation_authority_granted' => false,
			'production_authority_granted' => false,
		);
		$plan['plan_sha256'] = self::digest( $plan );
		return $plan;
	}

	public static function profile_apply( $input ) {
		$input = is_array( $input ) ? $input : array();
		$raw = isset( $input['profile'] ) && is_array( $input['profile'] ) ? $input['profile'] : array();
		$profile_id = self::clean_id( isset( $raw['profile_id'] ) ? $raw['profile_id'] : '', 'profile_id' );
		if ( is_wp_error( $profile_id ) ) return $profile_id;
		if ( ! class_exists( 'MAD4B_SCP_Distributed_Lock' ) ) {
			return new WP_Error( 'mad4b_search_profile_lock_unavailable', 'Search Profile apply requires the distributed lock service.' );
		}
		$lock = MAD4B_SCP_Distributed_Lock::catalog_name( 'search-profile:' . $profile_id );
		$acquired = MAD4B_SCP_Distributed_Lock::acquire( $lock );
		if ( is_wp_error( $acquired ) ) {
			return new WP_Error(
				'mad4b_search_profile_apply_in_progress',
				'Another Search Profile apply is already active for this profile.',
				array( 'profile_id' => $profile_id, 'lock_error' => $acquired->get_error_code() )
			);
		}
		try {
			// Re-read after acquiring the profile-scoped mutex. This closes the
			// read-plan-write race between concurrent requests using the same
			// expected revision and prevents a last-writer-wins lost update.
			self::reset_request_cache();
			$expected = strtolower( trim( (string) ( isset( $input['plan_sha256'] ) ? $input['plan_sha256'] : '' ) ) );
			$plan_input = $input;
			unset( $plan_input['plan_sha256'], $plan_input['_mad4b_approval_ticket_id'], $plan_input['_mad4b_context_receipt'] );
			$plan = self::profile_plan( $plan_input );
			if ( is_wp_error( $plan ) ) return $plan;
			if ( '' === $expected || ! hash_equals( $plan['plan_sha256'], $expected ) ) {
				return new WP_Error( 'mad4b_search_profile_plan_drift', 'Search Profile apply does not match the exact reviewed plan.' );
			}
			$rows = self::stored_profiles();
			$profile = $plan['profile'];
			$rows[ $profile['profile_id'] ] = $profile;
			if ( count( $rows ) > self::MAX_PROFILES ) return new WP_Error( 'mad4b_search_profile_limit', 'Search Profile limit reached.' );
			ksort( $rows, SORT_STRING );
			if ( ! update_option( self::OPTION, $rows, false ) && get_option( self::OPTION, array() ) !== $rows ) {
				return new WP_Error( 'mad4b_search_profile_write_failed', 'Unable to persist Search Profile.' );
			}
			self::$profiles = $rows;
			return array(
				'contract' => self::PROFILE_CONTRACT,
				'profile' => $profile,
				'applied' => true,
				'plan_sha256' => $plan['plan_sha256'],
				'concurrency_guard' => 'profile_scoped_distributed_lock',
				'authorizing' => false,
				'wordpress_mutation_authority_granted' => false,
				'production_authority_granted' => false,
			);
		} finally {
			MAD4B_SCP_Distributed_Lock::release( $lock );
		}
	}

	public static function profile_verify( $input ) {
		$input = is_array( $input ) ? $input : array();
		$current = self::profile( isset( $input['profile_id'] ) ? $input['profile_id'] : '' );
		if ( is_wp_error( $current ) ) return $current;
		$expected_revision = isset( $input['expected_revision'] ) ? (int) $input['expected_revision'] : (int) $current['revision'];
		$expected_sha = strtolower( trim( (string) ( isset( $input['expected_profile_sha256'] ) ? $input['expected_profile_sha256'] : $current['profile_sha256'] ) ) );
		$revision_match = (int) $current['revision'] === $expected_revision;
		$sha_match = 64 === strlen( $expected_sha ) && hash_equals( (string) $current['profile_sha256'], $expected_sha );
		return array(
			'contract' => self::PROFILE_CONTRACT,
			'profile_id' => $current['profile_id'],
			'revision' => (int) $current['revision'],
			'profile_sha256' => (string) $current['profile_sha256'],
			'revision_match' => $revision_match,
			'profile_sha256_match' => $sha_match,
			'verified' => $revision_match && $sha_match,
			'authorizing' => false,
		);
	}

	private static function profile_as_overlay( array $profile ) {
		$overlay = array();
		foreach ( self::$policy_fields as $field ) {
			if ( array_key_exists( $field, $profile ) ) $overlay[ $field ] = $profile[ $field ];
		}
		return $overlay;
	}

	public static function dependency_fingerprints( array $input ) {
		$keys = array( 'profile', 'language', 'surface', 'provider', 'budget', 'schema' );
		$out = array();
		foreach ( $keys as $key ) {
			$value = isset( $input[ $key ] ) ? $input[ $key ] : null;
			$out[ $key ] = is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/', strtolower( $value ) )
				? strtolower( $value )
				: self::digest( $value );
		}
		return $out;
	}

	public static function invalidate_dependencies( array $before, array $after ) {
		$before = self::dependency_fingerprints( $before );
		$after = self::dependency_fingerprints( $after );
		$map = array(
			'profile' => array( 'effective_context', 'target_projection', 'decision_projection', 'scheduler_projection' ),
			'language' => array( 'effective_context', 'owned_target_projection', 'surface_projection', 'decision_projection' ),
			'surface' => array( 'surface_projection', 'owned_target_projection', 'seo_projection', 'decision_projection' ),
			'provider' => array( 'provider_route_projection', 'decision_projection' ),
			'budget' => array( 'budget_projection', 'decision_projection', 'scheduler_projection' ),
			'schema' => array( 'effective_context', 'surface_projection', 'target_projection', 'provider_route_projection', 'decision_projection', 'scheduler_projection' ),
		);
		$drift = array();
		$invalidate = array();
		foreach ( $map as $key => $projections ) {
			if ( hash_equals( $before[ $key ], $after[ $key ] ) ) continue;
			$drift[] = strtoupper( $key ) . '_DRIFT';
			$invalidate = array_merge( $invalidate, $projections );
		}
		$invalidate = array_values( array_unique( $invalidate ) );
		sort( $invalidate, SORT_STRING );
		return array(
			'contract' => self::INVALIDATION_CONTRACT,
			'drift_classes' => $drift,
			'invalidate_projections' => $invalidate,
			'preserve_historical_evidence' => true,
			'before' => $before,
			'after' => $after,
			'authorizing' => false,
		);
	}

	public static function compile( $input ) {
		$input = is_array( $input ) ? $input : array();
		$profile = isset( $input['profile'] ) && is_array( $input['profile'] )
			? $input['profile']
			: self::profile( isset( $input['profile_id'] ) ? $input['profile_id'] : '' );
		if ( is_wp_error( $profile ) ) return $profile;
		if ( empty( $profile['enabled'] ) ) return new WP_Error( 'mad4b_search_profile_disabled', 'Search Profile is disabled.' );

		$profile_overlay = self::normalize_overlay( self::profile_as_overlay( $profile ), 'search_profile' );
		if ( is_wp_error( $profile_overlay ) ) return $profile_overlay;

		$overlays = array(
			'search_profile' => $profile_overlay,
			'brand_profile' => isset( $input['brand_profile'] ) ? $input['brand_profile'] : array(),
			'market_overlay' => isset( $input['market_overlay'] ) ? $input['market_overlay'] : array(),
			'language_overlay' => isset( $input['language_overlay'] ) ? $input['language_overlay'] : array(),
			'surface_overlay' => isset( $input['surface_overlay'] ) ? $input['surface_overlay'] : array(),
			'experiment_policy' => isset( $input['experiment_policy'] ) ? $input['experiment_policy'] : array(),
			'request_safe_override' => isset( $input['request_safe_override'] ) ? $input['request_safe_override'] : array(),
		);

		$resolved = array();
		$reason_chain = array();
		foreach ( self::$overlay_order as $source ) {
			$overlay = 'search_profile' === $source ? $overlays[ $source ] : self::normalize_overlay( $overlays[ $source ], $source );
			if ( is_wp_error( $overlay ) ) return $overlay;
			if ( empty( $overlay ) ) continue;
			$before = self::digest( $resolved );
			$resolved = self::merge_overlay( $resolved, $overlay );
			$after = self::digest( $resolved );
			if ( ! hash_equals( $before, $after ) ) {
				$reason_chain[] = array(
					'source' => $source,
					'overlay_sha256' => self::digest( $overlay ),
					'result_sha256' => $after,
				);
			}
		}

		$constraints = isset( $input['governance_constraints'] ) && is_array( $input['governance_constraints'] )
			? self::stable( $input['governance_constraints'] )
			: array();
		$constraint_protected = self::protected_path( $constraints );
		// Governance constraints may describe protected controls because they
		// constrain the compiler; they are never merged into business overlays.
		$constraint_digest = self::digest( $constraints );

		$dependencies = self::dependency_fingerprints( isset( $input['dependencies'] ) && is_array( $input['dependencies'] ) ? $input['dependencies'] : array() );
		$context = array(
			'contract' => self::CONTEXT_CONTRACT,
			'context_id' => '',
			'profile_id' => (string) $profile['profile_id'],
			'profile_revision' => (int) $profile['revision'],
			'profile_sha256' => (string) $profile['profile_sha256'],
			'resolved_markets' => isset( $resolved['markets'] ) ? $resolved['markets'] : array(),
			'resolved_languages' => isset( $resolved['language_policy']['resolved_languages'] ) && is_array( $resolved['language_policy']['resolved_languages'] )
				? self::normalize_scalar_list( $resolved['language_policy']['resolved_languages'], 128, 'lower' )
				: array(),
			'surface_policy' => isset( $resolved['surface_policy'] ) ? $resolved['surface_policy'] : array(),
			'provider_policy' => isset( $resolved['provider_policy'] ) ? $resolved['provider_policy'] : array(),
			'freshness_policy' => isset( $resolved['freshness_policy'] ) ? $resolved['freshness_policy'] : ( isset( $resolved['refresh_policy'] ) ? $resolved['refresh_policy'] : array() ),
			'priority_policy' => isset( $resolved['priority_policy'] ) ? $resolved['priority_policy'] : array(),
			'budget_policy' => isset( $resolved['budget_policy'] ) ? $resolved['budget_policy'] : array(),
			'experiment_policy' => isset( $resolved['experiment_policy'] ) ? $resolved['experiment_policy'] : array(),
			'objective' => isset( $resolved['objective'] ) ? $resolved['objective'] : '',
			'constraints' => $constraints,
			'governance_constraints_sha256' => $constraint_digest,
			'governance_protected_path_present' => '' !== $constraint_protected,
			'reason_chain' => $reason_chain,
			'dependency_fingerprints' => $dependencies,
			'overlay_precedence' => self::$overlay_order,
			'authorizing' => false,
			'wordpress_mutation_authority_granted' => false,
			'production_authority_granted' => false,
			'egress_authority_granted' => false,
		);
		$context['fingerprint'] = self::digest( $context );
		$context['context_id'] = 'search-context-' . substr( $context['fingerprint'], 0, 32 );
		return $context;
	}

	public static function status( $input = array() ) {
		$rows = self::stored_profiles();
		$profiles = array();
		foreach ( $rows as $profile ) {
			$profiles[] = array(
				'profile_id' => isset( $profile['profile_id'] ) ? (string) $profile['profile_id'] : '',
				'site_uuid' => isset( $profile['site_uuid'] ) ? (string) $profile['site_uuid'] : '',
				'brand_id' => isset( $profile['brand_id'] ) ? (string) $profile['brand_id'] : '',
				'enabled' => ! empty( $profile['enabled'] ),
				'revision' => isset( $profile['revision'] ) ? (int) $profile['revision'] : 0,
				'profile_sha256' => isset( $profile['profile_sha256'] ) ? (string) $profile['profile_sha256'] : '',
				'market_count' => isset( $profile['markets'] ) && is_array( $profile['markets'] ) ? count( $profile['markets'] ) : 0,
			);
		}
		return array(
			'contract' => self::STATUS_CONTRACT,
			'profiles' => $profiles,
			'count' => count( $profiles ),
			'overlay_precedence' => self::$overlay_order,
			'security_boundary' => 'specialization_may_constrain_or_prioritize_but_never_grant_authority',
			'protected_overlay_fragments' => self::$protected_fragments,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}
}
