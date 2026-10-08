<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Business configuration is independent of Site Profile and cannot grant authority. */
final class MAD4B_SCP_Search_Context {
	/** A private, synchronous privilege boundary for typed runtime controls only. */
	private static $typed_control_scope = null;
	public static function policy() {
		$raw = file_get_contents( dirname( __DIR__, 2 ) . '/config/search-runtime-policy.json' );
		$data = json_decode( (string) $raw, true );
		return is_array( $data ) && isset( $data['contract'] ) && 'mad4b.search-runtime-policy.v1' === $data['contract'] ? $data : MAD4B_SCP_Search_Contracts::error( 'policy_unavailable' );
	}

	private static function security_free( $value, array $denied ) {
		if ( ! is_array( $value ) ) return true;
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), $denied, true ) ) return false;
			if ( ! self::security_free( $item, $denied ) ) return false;
		}
		return true;
	}

	public static function validate( array $raw ) {
		$p = self::policy(); if ( is_wp_error( $p ) ) return $p;
		if ( ! MAD4B_SCP_Search_Contracts::bounded( $raw ) || ! self::security_free( $raw, $p['security_keys'] ) || array_diff( array_keys( $raw ), $p['profile_fields'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_boundary' );
		if ( ! isset( $raw['profile_id'] ) || ! MAD4B_SCP_Search_Contracts::id( $raw['profile_id'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_id_invalid' );
		$out = array_replace_recursive( $p['defaults'], $raw );
		// Lists are replaced, never merged by numeric index.
		foreach ( $raw as $key => $v ) $out[ $key ] = is_array( $v ) ? self::merge( isset( $p['defaults'][ $key ] ) && is_array( $p['defaults'][ $key ] ) ? $p['defaults'][ $key ] : array(), $v ) : $v;
		if ( ! is_bool( $out['enabled'] ) || ! is_array( $out['markets'] ) || count( $out['markets'] ) > 64 ) return MAD4B_SCP_Search_Contracts::error( 'profile_invalid' );
		if ( ( isset( $out['brand_id'] ) && ( ! is_string( $out['brand_id'] ) || ( '' !== $out['brand_id'] && ! MAD4B_SCP_Search_Contracts::id( $out['brand_id'] ) ) ) ) || ! is_string( $out['objective'] ) || '' === trim( $out['objective'] ) || strlen( $out['objective'] ) > 256 ) return MAD4B_SCP_Search_Contracts::error( 'profile_invalid' );
		foreach ( array( 'language_policy', 'surface_policy', 'provider_policy', 'budget_policy', 'refresh_policy', 'priority_policy', 'experiment_policy', 'overlays' ) as $field ) if ( ! is_array( $out[ $field ] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_invalid' );
		$seen = array();
		foreach ( $out['markets'] as $market ) {
			if ( ! is_array( $market ) || empty( $market['id'] ) || ! MAD4B_SCP_Search_Contracts::id( $market['id'] ) || isset( $seen[ $market['id'] ] ) || empty( $market['country'] ) || ! is_string( $market['country'] ) || ! preg_match( '/^[A-Z]{2}$/D', $market['country'] ) || ( isset( $market['provider_locations'] ) && ! is_array( $market['provider_locations'] ) ) ) return MAD4B_SCP_Search_Contracts::error( 'market_invalid' );
			$seen[ $market['id'] ] = true;
		}
		if ( ! is_array( $out['language_policy']['desired'] ) || count( $out['language_policy']['desired'] ) > 64 || true !== $out['language_policy']['owned_requires_live_surface'] ) return MAD4B_SCP_Search_Contracts::error( 'language_policy_invalid' );
		foreach ( $out['language_policy']['desired'] as $language ) if ( ! is_string( $language ) || ! preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $language ) ) return MAD4B_SCP_Search_Contracts::error( 'language_invalid' );
		foreach ( array( 'min_seconds', 'max_seconds', 'baseline_seconds' ) as $field ) if ( ! is_int( $out['refresh_policy'][ $field ] ) ) return MAD4B_SCP_Search_Contracts::error( 'refresh_policy_invalid' );
		if ( $out['refresh_policy']['min_seconds'] < 60 || $out['refresh_policy']['max_seconds'] < $out['refresh_policy']['min_seconds'] || $out['refresh_policy']['baseline_seconds'] < $out['refresh_policy']['min_seconds'] ) return MAD4B_SCP_Search_Contracts::error( 'refresh_policy_invalid' );
		if ( $out['provider_policy']['depth'] < 1 || $out['provider_policy']['depth'] > 100 || ! is_array( $out['provider_policy']['allowed'] ) || ! is_array( $out['provider_policy']['engines'] ) || ! is_array( $out['provider_policy']['devices'] ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_policy_invalid' );
		if ( ! is_int( $out['provider_policy']['depth'] ) || ! is_bool( $out['provider_policy']['freeze_spend'] ) || ! is_bool( $out['refresh_policy']['reuse_allowed'] ) || ! is_array( $out['provider_policy']['disabled'] ) || ! is_array( $out['priority_policy']['weights'] ) || ! is_array( $out['priority_policy']['fairness_dimensions'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_invalid' );
		foreach ( array( 'allowed', 'disabled', 'engines', 'devices' ) as $field ) foreach ( $out['provider_policy'][ $field ] as $id ) if ( ! MAD4B_SCP_Search_Contracts::id( $id ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_policy_invalid' );
		if ( ! is_numeric( $out['priority_policy']['missing_value'] ) || $out['priority_policy']['missing_value'] < 0 || $out['priority_policy']['missing_value'] > 1 || ! is_int( $out['priority_policy']['aging_seconds'] ) || $out['priority_policy']['aging_seconds'] < 1 || array_diff( $out['priority_policy']['fairness_dimensions'], $p['defaults']['priority_policy']['fairness_dimensions'] ) ) return MAD4B_SCP_Search_Contracts::error( 'decision_policy_invalid' );
		if ( ! is_int( $out['surface_policy']['max_surfaces'] ) || $out['surface_policy']['max_surfaces'] < 1 || $out['surface_policy']['max_surfaces'] > 10000 || ! is_int( $out['surface_policy']['max_pagination'] ) || $out['surface_policy']['max_pagination'] < 1 || $out['surface_policy']['max_pagination'] > 100 || ! is_array( $out['surface_policy']['virtual_routes'] ) || ! is_array( $out['surface_policy']['query_parameters'] ) ) return MAD4B_SCP_Search_Contracts::error( 'surface_policy_invalid' );
		foreach ( array( 'pre_seconds', 'post_seconds' ) as $field ) if ( ! is_int( $out['experiment_policy'][ $field ] ) || $out['experiment_policy'][ $field ] < 60 || $out['experiment_policy'][ $field ] > 31536000 ) return MAD4B_SCP_Search_Contracts::error( 'experiment_policy_invalid' );
		foreach ( $out['priority_policy']['weights'] as $weight ) if ( ! is_numeric( $weight ) || $weight < 0 || $weight > 5 ) return MAD4B_SCP_Search_Contracts::error( 'decision_weight_invalid' );
		foreach ( $out['overlays'] as $family => $overlays ) {
			if ( ! in_array( $family, array( 'brand', 'market', 'language', 'surface', 'experiment' ), true ) || ! is_array( $overlays ) ) return MAD4B_SCP_Search_Contracts::error( 'overlay_family_invalid' );
			foreach ( $overlays as $overlay ) if ( ! is_array( $overlay ) || array_diff( array_keys( $overlay ), $p['overlay_fields'] ) ) return MAD4B_SCP_Search_Contracts::error( 'overlay_boundary' );
		}
		$out['contract'] = 'mad4b.search-intelligence-profile.v1';
		$out['site_uuid'] = MAD4B_SCP_Site_Profile::site_uuid();
		$out['authorizing'] = false;
		return $out;
	}

	public static function merge( array $base, array $overlay ) {
		foreach ( $overlay as $key => $value ) {
			$is_map = is_array( $value ) && ! empty( $value ) && array_keys( $value ) !== range( 0, count( $value ) - 1 );
			$base[ $key ] = $is_map && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ? self::merge( $base[ $key ], $value ) : $value;
		}
		return $base;
	}

	public static function profile( $id ) {
		$row = MAD4B_SCP_Search_Store::read( 'profile', $id );
		return is_wp_error( $row ) ? $row : ( is_array( $row ) ? $row['profile'] : MAD4B_SCP_Search_Contracts::error( 'profile_missing' ) );
	}

	public static function plan( array $input ) {
		if ( ! isset( $input['profile'], $input['expected_revision'] ) || ! is_array( $input['profile'] ) || ! is_int( $input['expected_revision'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_plan_invalid' );
		$profile = self::validate( $input['profile'] ); if ( is_wp_error( $profile ) ) return $profile;
		$current = MAD4B_SCP_Search_Store::read( 'profile', $profile['profile_id'] ); if ( is_wp_error( $current ) ) return $current;
		$revision = is_array( $current ) ? (int) $current['_revision'] : 0;
		if ( $revision !== $input['expected_revision'] ) return MAD4B_SCP_Search_Contracts::error( 'profile_revision_drift' );
		// Initial generic API admission never implies permission to execute work or
		// spend. Existing active profiles retain their state; no silent migration.
		// Operator activation is a separate exact-revision typed control action.
		if ( null === $current && ( ! empty( $profile['enabled'] ) || empty( $profile['provider_policy']['freeze_spend'] ) ) ) {
			return MAD4B_SCP_Search_Contracts::error( 'profile_initial_state_unsafe', 'Create Search Profiles paused and spend-frozen, then use independently authorized typed controls.' );
		}
		// Every new generic Profile starts with observations paused and provider
		// spend frozen. Runtime activation always requires independent, exact-
		// revision-fenced typed controls. Existing profiles are not rewritten.
		if ( null === $current && ( true !== $profile['provider_policy']['freeze_spend'] || false !== $profile['enabled'] ) ) {
			return MAD4B_SCP_Search_Contracts::error( 'profile_creation_requires_pause_and_spend_freeze', 'Create the Profile paused and spend-frozen, then use separately governed typed controls to activate.' );
		}
		// Domain invariant: every caller (admin, remote API or workflow) must
		// respect historical geo identity and a separately paused/frozen edit lane.
		if ( is_array( $current ) ) {
			if ( ! isset( $current['profile'] ) || ! is_array( $current['profile'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_boundary' );
			$guard = self::guard_profile_update( $current['profile'], $profile );
			if ( is_wp_error( $guard ) ) return $guard;
		}
		// A market identifier participates in target_id but profile_id does not.
		// Reject cross-profile aliasing before reserving identities on apply.
		// A pre-existing identity collision must never stop a fail-safe shutdown.
		// Scoped pause/freeze/disable may proceed with a quarantine receipt; it
		// cannot certify or adopt the conflicting market identity.
		$fail_safe = self::is_scoped_fail_safe( $profile, $current );
		if ( ! $fail_safe ) {
			$unique = self::market_id_unique( $profile, is_array( $current ) ? $current['profile'] : array() );
			if ( is_wp_error( $unique ) ) return $unique;
		}
		$profile['revision'] = $revision + 1;
		$profile['profile_sha256'] = MAD4B_SCP_Search_Contracts::digest( $profile );
		$plan = array( 'contract' => 'mad4b.search-profile-plan.v1', 'profile' => $profile, 'expected_revision' => $revision, 'policy_fingerprint' => MAD4B_SCP_Search_Contracts::digest( self::policy() ), 'site_binding' => MAD4B_SCP_Search_Store::scope(), 'authorizing' => false, 'mutation_performed' => false );
		$plan['plan_sha256'] = MAD4B_SCP_Search_Contracts::digest( $plan );
		return $plan;
	}

	/**
	 * Market IDs are historical measurement identities, not display labels.
	 * No caller may reassign or silently remove an existing market identity.
	 * Material configuration changes require *both* old and proposed state
	 * to be paused/frozen. Dedicated state-only controls remain possible.
	 */
	private static function guard_profile_update( array $old, array $next ) {
		if ( ! isset( $old['markets'], $next['markets'] ) || ! is_array( $old['markets'] ) || ! is_array( $next['markets'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_boundary' );
		$by_id = array();
		foreach ( $next['markets'] as $market ) {
			if ( ! is_array( $market ) || ! isset( $market['id'], $market['country'] ) || ! is_string( $market['id'] ) || ! is_string( $market['country'] ) ) return MAD4B_SCP_Search_Contracts::error( 'market_invalid' );
			$by_id[ $market['id'] ] = $market['country'];
		}
		foreach ( $old['markets'] as $market ) {
			if ( ! is_array( $market ) || ! isset( $market['id'], $market['country'] ) || ! is_string( $market['id'] ) || ! is_string( $market['country'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_boundary' );
			if ( ! isset( $by_id[ $market['id'] ] ) || $by_id[ $market['id'] ] !== $market['country'] ) return MAD4B_SCP_Search_Contracts::error( 'profile_market_identity_locked', 'Existing market IDs cannot be removed or reassigned to another country without a separately governed migration.' );
		}
		$p = self::policy(); if ( is_wp_error( $p ) ) return $p;
		$fields = array_flip( $p['profile_fields'] );
		$before = array_intersect_key( $old, $fields );
		$after = array_intersect_key( $next, $fields );
		// Exactly three fields belong to independently verified runtime controls.
		unset( $before['enabled'], $after['enabled'] );
		if ( isset( $before['provider_policy'] ) && is_array( $before['provider_policy'] ) ) unset( $before['provider_policy']['freeze_spend'], $before['provider_policy']['disabled'] );
		if ( isset( $after['provider_policy'] ) && is_array( $after['provider_policy'] ) ) unset( $after['provider_policy']['freeze_spend'], $after['provider_policy']['disabled'] );
		$target_change = MAD4B_SCP_Search_Contracts::digest( $before ) !== MAD4B_SCP_Search_Contracts::digest( $after );
		// Domain callers using the generic profile-plan/apply lane cannot change
		// runtime state. Only a separately typed, scoped control transition may.
		$old_disabled = isset( $old['provider_policy']['disabled'] ) ? $old['provider_policy']['disabled'] : array();
		$new_disabled = isset( $next['provider_policy']['disabled'] ) ? $next['provider_policy']['disabled'] : array();
		$state_change = $old['enabled'] !== $next['enabled'] || $old['provider_policy']['freeze_spend'] !== $next['provider_policy']['freeze_spend'] || MAD4B_SCP_Search_Contracts::digest( $old_disabled ) !== MAD4B_SCP_Search_Contracts::digest( $new_disabled );
		$old_frozen = ! empty( $old['provider_policy']['freeze_spend'] );
		$next_frozen = ! empty( $next['provider_policy']['freeze_spend'] );
		if ( $target_change && ( ! empty( $old['enabled'] ) || ! empty( $next['enabled'] ) || ! $old_frozen || ! $next_frozen ) ) {
			return MAD4B_SCP_Search_Contracts::error( 'profile_targeting_requires_pause_and_spend_freeze', 'Pause observations and freeze spend in an independent step before any material profile edit.' );
		}
		if ( $state_change ) {
			$scope = self::$typed_control_scope;
			$expected_sha = MAD4B_SCP_Search_Contracts::digest( $next );
			$authorized = is_array( $scope ) && isset( $scope['profile_id'], $scope['revision'], $scope['profile_sha256'] ) && $scope['profile_id'] === $next['profile_id'] && (int) $scope['revision'] === (int) $old['revision'] && hash_equals( $scope['profile_sha256'], $expected_sha );
			if ( ! $authorized ) return MAD4B_SCP_Search_Contracts::error( 'profile_state_requires_explicit_control', 'Runtime state transitions require an exact-bound typed control operation.' );
		}
		return true;
	}

	/**
	 * Validate the exact CAS-owned profile registry before non-emergency edits.
	 * Do not treat corrupt or tampered state as an empty list.
	 */
	private static function checked_profile_registry( $registry ) {
		$policy = self::policy();
		if ( is_wp_error( $policy ) ) return $policy;
		if ( ! isset( $policy['max_profiles'] ) || ! is_int( $policy['max_profiles'] ) || $policy['max_profiles'] < 1 ) return MAD4B_SCP_Search_Contracts::error( 'profile_registry_invalid' );
		if ( null === $registry ) return array( 'ids' => array(), 'max_profiles' => $policy['max_profiles'] );
		if ( ! is_array( $registry ) || ! isset( $registry['ids'], $registry['_revision'], $registry['_event'], $registry['_event_sha256'] )
			|| ! is_array( $registry['ids'] ) || ! is_int( $registry['_revision'] ) || $registry['_revision'] < 1
			|| ! is_array( $registry['_event'] ) || ! MAD4B_SCP_Search_Contracts::sha( $registry['_event_sha256'] )
			|| array_diff( array_keys( $registry ), array( 'ids', '_revision', '_event', '_event_sha256' ) )
			|| ! hash_equals( MAD4B_SCP_Search_Contracts::digest( $registry['_event'] ), $registry['_event_sha256'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_registry_invalid' );
		$payload = $registry;
		unset( $payload['_event'], $payload['_event_sha256'] );
		if ( ! isset( $registry['_event']['payload_sha256'] ) || ! MAD4B_SCP_Search_Contracts::sha( $registry['_event']['payload_sha256'] )
			|| ! hash_equals( MAD4B_SCP_Search_Contracts::digest( $payload ), $registry['_event']['payload_sha256'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_registry_invalid' );
		$ids = $registry['ids'];
		if ( count( $ids ) > $policy['max_profiles'] || ( $ids && array_keys( $ids ) !== range( 0, count( $ids ) - 1 ) ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_registry_invalid' );
		$seen = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || ! MAD4B_SCP_Search_Contracts::id( $id ) || isset( $seen[ $id ] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_registry_invalid' );
			$seen[ $id ] = true;
		}
		return array( 'ids' => $ids, 'max_profiles' => $policy['max_profiles'] );
	}

	/**
	 * Exact market identities cannot alias between profiles, including older
	 * profiles outside a damaged Registry. A single bounded Store scan avoids
	 * the N+1 profile reads and observes orphaned committed profiles as well.
	 * Concurrent writes remain fenced by immutable claims on apply.
	 */
	private static function market_id_unique( array $candidate, array $previous ) {
		$registry = MAD4B_SCP_Search_Store::read( 'registry', 'profiles' );
		if ( is_wp_error( $registry ) ) return $registry;
		$checked = self::checked_profile_registry( $registry );
		if ( is_wp_error( $checked ) ) return $checked;
		$ids = array();
		foreach ( $candidate['markets'] as $market ) {
			$id = $market['id']; $ids[ $id ] = $market['country'];
			$claim = MAD4B_SCP_Search_Store::read( 'market-identity', $id );
			if ( is_wp_error( $claim ) ) return $claim;
			if ( null === $claim ) continue;
			$expected = array( 'profile_id' => $candidate['profile_id'], 'country' => $market['country'] );
			if ( ! is_array( $claim ) || ! isset( $claim['digest'], $claim['payload'] ) || ! is_string( $claim['digest'] ) || ! is_array( $claim['payload'] ) || ! hash_equals( MAD4B_SCP_Search_Contracts::digest( $expected ), $claim['digest'] ) || ! hash_equals( MAD4B_SCP_Search_Contracts::digest( $claim['payload'] ), $claim['digest'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_market_identity_conflict', 'This market ID has a different or invalid durable owner claim.' );
		}
		$limit = (int) self::policy()['max_profiles'];
		$rows = MAD4B_SCP_Search_Store::list_rows( 'profile', '', $limit + 1 );
		if ( is_wp_error( $rows ) ) return $rows;
		if ( ! isset( $rows['items'] ) || ! is_array( $rows['items'] ) || count( $rows['items'] ) > $limit ) return MAD4B_SCP_Search_Contracts::error( 'profile_registry_invalid', 'Search Profile store cardinality is inconsistent.' );
		foreach ( $rows['items'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['profile']['profile_id'], $row['profile']['markets'] ) || ! MAD4B_SCP_Search_Contracts::id( $row['profile']['profile_id'] ) || ! is_array( $row['profile']['markets'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_registry_invalid' );
			if ( $row['profile']['profile_id'] === $candidate['profile_id'] ) continue;
			foreach ( $row['profile']['markets'] as $market ) {
				if ( ! is_array( $market ) || ! isset( $market['id'] ) || ! is_string( $market['id'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_registry_invalid' );
				if ( isset( $ids[ $market['id'] ] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_market_identity_conflict', 'This market ID also appears in another committed Search Profile.' );
			}
		}
		return true;
	}

	/** Fail-safe controls retain emergency stopping power in legacy collision cases. */
	private static function is_scoped_fail_safe( array $profile, $current ) {
		$scope = self::$typed_control_scope;
		// apply() sees a stamped revision and profile SHA; the exact scope
		// binds the pre-stamp validated profile used by plan() instead.
		$unstamped = $profile; unset( $unstamped['revision'], $unstamped['profile_sha256'] );
		return is_array( $current ) && isset( $current['profile']['profile_id'], $current['profile']['revision'] ) && is_array( $scope )
			&& isset( $scope['action'], $scope['profile_id'], $scope['revision'], $scope['profile_sha256'] )
			&& in_array( $scope['action'], array( 'pause', 'freeze_spend', 'disable_provider' ), true )
			&& $scope['profile_id'] === $profile['profile_id'] && $scope['profile_id'] === $current['profile']['profile_id']
			&& (int) $scope['revision'] === (int) $current['profile']['revision']
			&& hash_equals( $scope['profile_sha256'], MAD4B_SCP_Search_Contracts::digest( $unstamped ) );
	}

	/**
	 * The only domain entrypoint that may transition search runtime state.
	 * Reconstructs the next profile from persisted fields; client-provided
	 * profile JSON, budgets, enabled and freeze_spend are never trusted.
	 */
	public static function control_transition( array $input ) {
		if ( ! MAD4B_SCP_Search_Runtime::can_configure() ) return MAD4B_SCP_Search_Contracts::error( 'configuration_unauthorized' );
		$id = isset( $input['profile_id'] ) ? $input['profile_id'] : '';
		$action = isset( $input['control'] ) ? $input['control'] : '';
		if ( ! MAD4B_SCP_Search_Contracts::id( $id ) || ! is_string( $action ) || ! in_array( $action, array( 'pause', 'resume', 'freeze_spend', 'unfreeze_spend', 'disable_provider', 'enable_provider' ), true ) ) return MAD4B_SCP_Search_Contracts::error( 'control_invalid' );
		$old = self::profile( $id ); if ( is_wp_error( $old ) ) return $old;
		if ( isset( $input['expected_revision'] ) ) {
			$provided = $input['expected_revision'];
			if ( ! ( is_int( $provided ) || ( is_string( $provided ) && preg_match( '/^[1-9][0-9]{0,8}$/D', $provided ) ) ) || (int) $provided !== (int) $old['revision'] ) return MAD4B_SCP_Search_Contracts::error( 'search_control_stale' );
		}
		// High-impact operations require an exact operator revision, even when
		// the caller invokes the control API without the wp-admin form.
		if ( in_array( $action, array( 'resume', 'unfreeze_spend', 'enable_provider' ), true ) && ! isset( $input['expected_revision'] ) ) return MAD4B_SCP_Search_Contracts::error( 'search_control_revision_required' );
		$policy = self::policy(); if ( is_wp_error( $policy ) ) return $policy;
		$raw = array_intersect_key( $old, array_flip( $policy['profile_fields'] ) );
		if ( 'pause' === $action ) $raw['enabled'] = false;
		if ( 'resume' === $action ) $raw['enabled'] = true;
		if ( 'freeze_spend' === $action || 'unfreeze_spend' === $action ) $raw['provider_policy']['freeze_spend'] = 'freeze_spend' === $action;
		if ( 'disable_provider' === $action || 'enable_provider' === $action ) {
			$provider = isset( $input['provider_id'] ) ? $input['provider_id'] : '';
			if ( ! MAD4B_SCP_Search_Contracts::id( $provider ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_id_invalid' );
			$disabled = isset( $raw['provider_policy']['disabled'] ) ? $raw['provider_policy']['disabled'] : array();
			if ( 'disable_provider' === $action ) $disabled[] = $provider;
			else $disabled = array_diff( $disabled, array( $provider ) );
			$raw['provider_policy']['disabled'] = array_values( array_unique( $disabled ) );
		}
		if ( null !== self::$typed_control_scope ) return MAD4B_SCP_Search_Contracts::error( 'search_control_reentrant' );
		$checked = self::validate( $raw ); if ( is_wp_error( $checked ) ) return $checked;
		self::$typed_control_scope = array( 'action' => $action, 'profile_id' => $id, 'revision' => (int) $old['revision'], 'profile_sha256' => MAD4B_SCP_Search_Contracts::digest( $checked ) );
		try {
			$args = array( 'profile' => $raw, 'expected_revision' => (int) $old['revision'] );
			$plan = self::plan( $args ); if ( is_wp_error( $plan ) ) return $plan;
			return self::apply( array_merge( $args, array( 'plan_sha256' => $plan['plan_sha256'] ) ) );
		} finally { self::$typed_control_scope = null; }
	}

	public static function apply( array $input ) {
		if ( ! MAD4B_SCP_Search_Runtime::can_configure() ) return MAD4B_SCP_Search_Contracts::error( 'configuration_unauthorized' );
		$plan = self::plan( $input ); if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $input['plan_sha256'] ) || ! hash_equals( $plan['plan_sha256'], (string) $input['plan_sha256'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_plan_drift' );
		$id = $plan['profile']['profile_id']; $current = MAD4B_SCP_Search_Store::read( 'profile', $id );
		if ( is_wp_error( $current ) || ( null === $current ? 0 : $current['_revision'] ) !== $plan['expected_revision'] ) return MAD4B_SCP_Search_Contracts::error( 'profile_revision_drift' );
		// Atomic immutable claims close the simultaneous-create race across profiles.
		// A failed profile CAS leaves an intentionally reserved identity: fail-closed,
		// never silently reuse it or claim an unsafe rollback.
		$fail_safe = self::is_scoped_fail_safe( $plan['profile'], $current );
		if ( ! $fail_safe ) {
			// The plan was read-only; a fresh registry validation must precede
			// *every* immutable market claim and other profile mutation.
			$before_claims = MAD4B_SCP_Search_Store::read( 'registry', 'profiles' );
			if ( is_wp_error( $before_claims ) ) return $before_claims;
			$checked_before = self::checked_profile_registry( $before_claims );
			if ( is_wp_error( $checked_before ) ) return $checked_before;
		}
		// Fail-safe controls must not mint misleading ownership evidence while
		// a legacy market collision remains in quarantine.
		if ( ! $fail_safe ) {
			foreach ( $plan['profile']['markets'] as $market ) {
				$claim = MAD4B_SCP_Search_Store::immutable( 'market-identity', $market['id'], array( 'profile_id' => $id, 'country' => $market['country'] ) );
				if ( is_wp_error( $claim ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_market_identity_conflict', 'The market identifier is reserved by another owner or could not be verified.' );
			}
		}
		if ( ! $fail_safe ) {
			$registry = MAD4B_SCP_Search_Store::read( 'registry', 'profiles' ); if ( is_wp_error( $registry ) ) return $registry;
			$checked = self::checked_profile_registry( $registry );
			if ( is_wp_error( $checked ) ) return $checked;
			$ids = $checked['ids'];
			if ( ! in_array( $id, $ids, true ) ) {
				if ( count( $ids ) >= $checked['max_profiles'] ) return MAD4B_SCP_Search_Contracts::error( 'profile_cardinality' );
				$ids[] = $id; sort( $ids, SORT_STRING ); $registered = MAD4B_SCP_Search_Store::cas( 'registry', 'profiles', $registry, array( 'ids' => $ids ), 'PROFILE_ADMITTED' ); if ( is_wp_error( $registered ) ) return $registered;
			}
		}
		// Emergency pause/freeze/disable of an existing profile cannot be
		// blocked by a corrupt Registry. It never admits or repairs that Registry.
		$version = MAD4B_SCP_Search_Store::immutable( 'profile-version', $plan['profile']['profile_sha256'], $plan['profile'] ); if ( is_wp_error( $version ) ) return $version;
		$row = MAD4B_SCP_Search_Store::cas( 'profile', $id, $current, array( 'profile' => $plan['profile'], 'plan_sha256' => $plan['plan_sha256'] ), 'SEARCH_PROFILE_CHANGED' );
		if ( is_wp_error( $row ) ) return $row;
		$verified = self::verify( array( 'profile_id' => $id ) );
		if ( is_wp_error( $verified ) || ! isset( $verified['profile_sha256'] ) || ! hash_equals( (string) $row['profile']['profile_sha256'], (string) $verified['profile_sha256'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_readback_failed', 'Persisted profile checksum does not match the committed revision.' );
		$identity_quarantined = $fail_safe && ( empty( $verified['valid'] ) || empty( $verified['market_claims_certified'] ) );
		if ( ! $fail_safe && ( empty( $verified['valid'] ) || empty( $verified['market_identity_integrity'] ) ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_readback_failed', 'Profile commit cannot be certified without matching market ownership evidence.' );
		return array( 'profile' => $row['profile'], 'applied' => true, 'safe_control_identity_quarantined' => $identity_quarantined, 'authorizing' => false );
	}

	public static function verify( array $input ) {
		$p = self::profile( isset( $input['profile_id'] ) ? $input['profile_id'] : '' ); if ( is_wp_error( $p ) ) return $p;
		if ( ! isset( $p['profile_sha256'], $p['revision'], $p['profile_id'], $p['markets'] ) || ! is_string( $p['profile_sha256'] ) || ! is_array( $p['markets'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_readback_invalid' );
		$copy = $p; unset( $copy['profile_sha256'] );
		$valid = MAD4B_SCP_Search_Contracts::sha( $p['profile_sha256'] ) && hash_equals( $p['profile_sha256'], MAD4B_SCP_Search_Contracts::digest( $copy ) );
		$legacy_unclaimed = 0; $market_integrity = true;
		foreach ( $p['markets'] as $market ) {
			if ( ! is_array( $market ) || ! isset( $market['id'], $market['country'] ) || ! is_string( $market['id'] ) || ! is_string( $market['country'] ) ) { $market_integrity = false; break; }
			$claim = MAD4B_SCP_Search_Store::read( 'market-identity', $market['id'] );
			if ( is_wp_error( $claim ) ) { $market_integrity = false; break; }
			if ( null === $claim ) { ++$legacy_unclaimed; continue; }
			$expected = array( 'profile_id' => $p['profile_id'], 'country' => $market['country'] );
			if ( ! is_array( $claim ) || ! isset( $claim['payload'], $claim['digest'] ) || ! is_array( $claim['payload'] ) || ! is_string( $claim['digest'] ) || ! hash_equals( MAD4B_SCP_Search_Contracts::digest( $expected ), $claim['digest'] ) || ! hash_equals( MAD4B_SCP_Search_Contracts::digest( $claim['payload'] ), $claim['digest'] ) ) { $market_integrity = false; break; }
		}
		return array( 'contract' => 'mad4b.search-profile-verify.v1', 'valid' => $valid && $market_integrity, 'revision' => $p['revision'], 'profile_sha256' => $p['profile_sha256'], 'market_identity_integrity' => $market_integrity, 'legacy_unclaimed_market_count' => $legacy_unclaimed, 'market_claims_certified' => $market_integrity && 0 === $legacy_unclaimed, 'authorizing' => false );
	}

	public static function compile( array $profile, array $facts, array $selection = array(), array $safe_override = array() ) {
		$p = self::policy(); if ( is_wp_error( $p ) ) return $p;
		if ( array_diff( array_keys( $safe_override ), $p['overlay_fields'] ) || ! self::security_free( $safe_override, $p['security_keys'] ) ) return MAD4B_SCP_Search_Contracts::error( 'override_boundary' );
		$effective = $profile; $reasons = array( 'system defaults', 'search profile revision ' . $profile['revision'] );
		foreach ( array( 'brand', 'market', 'language', 'surface', 'experiment' ) as $family ) {
			$id = isset( $selection[ $family ] ) ? $selection[ $family ] : ( 'brand' === $family && isset( $profile['brand_id'] ) ? $profile['brand_id'] : '' );
			if ( isset( $profile['overlays'][ $family ][ $id ] ) ) { $effective = self::merge( $effective, $profile['overlays'][ $family ][ $id ] ); $reasons[] = $family . ' overlay ' . $id; }
		}
		$effective = self::merge( $effective, $safe_override );
		if ( $safe_override ) $reasons[] = 'request safe override';
		// Revalidate specialization after overlay composition, not only the base profile.
		$raw = array_intersect_key( $effective, array_flip( $p['profile_fields'] ) );
		$checked = self::validate( $raw ); if ( is_wp_error( $checked ) ) return $checked;
		$languages = array();
		foreach ( $profile['language_policy']['desired'] as $language ) {
			$live = isset( $facts['languages'][ $language ] ) ? $facts['languages'][ $language ] : array();
			$languages[ $language ] = array( 'language' => $language, 'discovery_allowed' => true, 'owned_allowed' => ! empty( $live['active'] ) && ! empty( $live['owned_count'] ), 'partial_translation' => ! empty( $live['partial_translation'] ), 'source' => isset( $live['source'] ) ? $live['source'] : 'desired_only' );
		}
		$deps = array( 'PROFILE' => $profile['profile_sha256'], 'SCHEMA' => MAD4B_SCP_Search_Contracts::digest( $p ) );
		foreach ( array( 'LANGUAGE' => 'languages', 'SURFACE' => 'surfaces', 'SEO_PROVIDER' => 'seo', 'PROVIDER_CAPABILITY' => 'providers', 'BUDGET' => 'budgets' ) as $class => $field ) {
			$value = isset( $facts[ $field ] ) ? $facts[ $field ] : array();
			if ( 'SURFACE' === $class ) { $stable = array(); foreach ( $value as $s ) $stable[ $s['surface_key'] ] = $s['surface_fingerprint']; $value = $stable; }
			$deps[ $class ] = MAD4B_SCP_Search_Contracts::digest( MAD4B_SCP_Search_Contracts::semantic( $value ) );
		}
		// The Phase 38A value compiler validates the composed policy and canonical
		// dependency envelope. Market descriptors remain in this executable registry.
		$foundation_profile = $effective; $foundation_profile['enabled'] = true; $foundation_profile['markets'] = array_column( $effective['markets'], 'id' );
		$foundation_profile['language_policy']['resolved_languages'] = array_keys( $languages );
		$foundation = MAD4B_SCP_Search_Runtime_Context::compile( array( 'profile' => $foundation_profile, 'dependencies' => array( 'profile' => $deps['PROFILE'], 'language' => $deps['LANGUAGE'], 'surface' => $deps['SURFACE'], 'provider' => $deps['PROVIDER_CAPABILITY'], 'budget' => $deps['BUDGET'], 'schema' => $deps['SCHEMA'] ), 'governance_constraints' => array( 'production_authority' => false, 'egress_authority' => false ) ) );
		if ( is_wp_error( $foundation ) ) return $foundation;
		$result = array( 'contract' => 'mad4b.effective-search-context.v1', 'profile_revision' => $profile['revision'], 'profile_id' => $profile['profile_id'], 'resolved_markets' => $profile['markets'], 'resolved_languages' => $languages, 'effective' => $effective, 'dependencies' => $deps, 'reason_chain' => $reasons, 'constraints' => array( 'no_production_authority', 'no_breakglass_widening', 'no_generic_outbound_http', 'no_direct_content_mutation_from_signal' ), 'authorizing' => false );
		$result['foundation_context'] = array( 'contract' => $foundation['contract'], 'fingerprint' => $foundation['fingerprint'], 'dependency_fingerprints' => $foundation['dependency_fingerprints'], 'authorizing' => false );
		$result['fingerprint'] = MAD4B_SCP_Search_Contracts::digest( $result ); $result['context_id'] = $result['fingerprint'];
		return $result;
	}

	public static function drift( array $before, array $after, array $depends_on ) {
		$changed = array();
		foreach ( $depends_on as $class ) if ( ! isset( $before[ $class ], $after[ $class ] ) || ! hash_equals( (string) $before[ $class ], (string) $after[ $class ] ) ) $changed[] = $class . '_DRIFT';
		return $changed;
	}
}
