<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Durable provider-entry checkpoint, fenced leases and reconciliation-first completion. */
final class MAD4B_SCP_Search_Worker {
	public static function runtime_build() {
		$path = dirname( __DIR__, 2 ) . '/MAD4B-BUILD-PROVENANCE.json';
		$row = is_readable( $path ) && ! is_link( $path ) ? json_decode( file_get_contents( $path ), true ) : array();
		$build = isset( $row['build_fingerprint'] ) ? $row['build_fingerprint'] : '';
		return MAD4B_SCP_Search_Contracts::sha( $build ) ? $build : MAD4B_SCP_Search_Contracts::error( 'runtime_build_missing' );
	}
	private static function epoch() {
		if ( ! class_exists( 'MAD4B_SCP_Restore_Epoch' ) ) return MAD4B_SCP_Search_Contracts::error( 'restore_epoch_unavailable' );
		$status = MAD4B_SCP_Restore_Epoch::status( false, true );
		return is_array( $status ) && ! empty( $status['ready'] ) && isset( $status['epoch'] ) ? (int) $status['epoch'] : MAD4B_SCP_Search_Contracts::error( 'restore_epoch_unbound' );
	}
	public static function plan( array $input ) {
		return self::bound_plan( $input );
	}
	private static function bound_plan( array $input, array $held = array() ) {
		if ( empty( $input['profile_id'] ) || empty( $input['target_id'] ) || ! isset( $input['observation_epoch'] ) || ! is_int( $input['observation_epoch'] ) || $input['observation_epoch'] < 0 || $input['observation_epoch'] > time() ) return MAD4B_SCP_Search_Contracts::error( 'capture_plan_invalid' );
		$profile = MAD4B_SCP_Search_Context::profile( $input['profile_id'] ); if ( is_wp_error( $profile ) ) return $profile;
		if ( empty( $profile['enabled'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_paused' );
		$row = MAD4B_SCP_Search_Store::read( 'target', $input['target_id'] );
		if ( ! is_array( $row ) || empty( $row['target'] ) ) return MAD4B_SCP_Search_Contracts::error( 'target_missing' );
		if ( ! empty( $row['muted'] ) ) return MAD4B_SCP_Search_Contracts::error( 'target_muted' );
		$t = $row['target'];
		$facts = MAD4B_SCP_Search_Runtime::facts( $profile, $t['surface_refs'] ); if ( is_wp_error( $facts ) ) return $facts;
		$context = MAD4B_SCP_Search_Context::compile( $profile, $facts, array( 'market' => $t['market'], 'language' => $t['language'] ) ); if ( is_wp_error( $context ) ) return $context;
		if ( isset( $t['profile_id'] ) && $t['profile_id'] !== $profile['profile_id'] ) return MAD4B_SCP_Search_Contracts::error( 'target_profile_mismatch' );
		if ( ! empty( $t['dependencies']['PROFILE'] ) && $t['dependencies']['PROFILE'] !== $profile['profile_sha256'] ) return MAD4B_SCP_Search_Contracts::error( 'profile_drift' );
		$compiled = MAD4B_SCP_Search_Targets::compile( $context, array( array_merge( $t, array( 'query' => $t['raw_query'] ) ) ), $facts['surfaces'] );
		if ( is_wp_error( $compiled ) || empty( $compiled['targets'] ) ) return MAD4B_SCP_Search_Contracts::error( 'target_runtime_ineligible' );
		$request = array( 'contract' => 'mad4b.serp-request.v1', 'query_id' => $t['target_id'], 'query' => $t['normalized_query'], 'market' => $t['market'], 'language' => $t['language'], 'engine' => $t['engine'], 'device' => $t['device'], 'purpose' => $t['purpose'], 'depth' => (int) $context['effective']['provider_policy']['depth'], 'engine_domain' => isset( $t['engine_domain'] ) ? $t['engine_domain'] : '', 'safe_search' => isset( $t['safe_search'] ) ? $t['safe_search'] : 'provider_default', 'search_mode' => 'organic', 'requested_features' => isset( $t['requested_features'] ) ? $t['requested_features'] : array(), 'context_fingerprint' => $context['fingerprint'] );
		$market = array(); foreach ( $profile['markets'] as $m ) if ( $m['id'] === $t['market'] ) $market = $m;
		$request['requested_country'] = isset( $market['country'] ) ? strtoupper( $market['country'] ) : '';
		$request = array_merge( $request, array_intersect_key( $t, array_flip( array( 'site_uuid', 'brand_id', 'cluster_id', 'surface_type' ) ) ) );
		if ( ! empty( $t['experiment_id'] ) ) {
			$experiment = MAD4B_SCP_Search_Store::evidence( 'experiment', $t['experiment_id'] ); if ( is_wp_error( $experiment ) ) return $experiment;
			$verified = MAD4B_SCP_Content_Experience_Runtime::verify( $experiment['experience_slug'], array( 'post_id' => $experiment['post_id'] ) );
			if ( is_wp_error( $verified ) || $verified['core_state_sha256'] !== $experiment['content_fingerprint'] || MAD4B_SCP_Search_Contracts::digest( $verified['helper_verification'] ) !== $experiment['seo_fingerprint'] || array_column( $facts['surfaces'], 'surface_fingerprint', 'surface_key' ) !== $experiment['surface_fingerprints'] ) return MAD4B_SCP_Search_Contracts::error( 'experiment_content_drift' );
			$request['change_binding'] = array( 'experiment_id' => $t['experiment_id'], 'content_fingerprint' => $experiment['content_fingerprint'], 'seo_fingerprint' => $experiment['seo_fingerprint'] );
		}
		$build = self::runtime_build(); if ( is_wp_error( $build ) ) return $build;
		$epoch = self::epoch(); if ( is_wp_error( $epoch ) ) return $epoch;
		$base = array( 'contract' => 'mad4b.search-capture-plan.v1', 'profile_id' => $profile['profile_id'], 'profile_revision' => $profile['revision'], 'target_id' => $t['target_id'], 'target_revision' => $row['_revision'], 'request' => $request, 'context_dependencies' => $context['dependencies'], 'runtime_build' => $build, 'restore_epoch' => $epoch, 'observation_epoch' => $input['observation_epoch'], 'authorizing' => false, 'mutation_performed' => false );
		$view = MAD4B_SCP_Search_Store::read( 'current', $t['target_id'] );
		if ( ! empty( $context['effective']['refresh_policy']['reuse_allowed'] ) && is_array( $view ) ) {
			$cached = MAD4B_SCP_Search_Store::evidence( 'snapshot', $view['snapshot_id'] );
			if ( ! is_wp_error( $cached ) && MAD4B_SCP_Search_Decisions::equivalent( $cached, $request, $cached['observation_context']['comparability_key'], $context['effective']['refresh_policy']['min_seconds'], time() ) ) {
				$base['mode'] = 'CACHE_REUSE'; $base['snapshot_id'] = $cached['snapshot_id'];
				$base['reason_chain'] = array( 'complete equivalent request/context', 'within freshness and retention window', 'no provider request or reservation' );
				return self::seal( $base );
			}
		}
		$routing = MAD4B_SCP_Search_Providers::select( $request, $context['effective']['provider_policy'], time(), $held );
		if ( empty( $routing['selected'] ) ) return MAD4B_SCP_Search_Contracts::error( 'no_eligible_provider' );
		$id = $routing['selected']['provider_id']; $adapter = MAD4B_SCP_Search_Providers::adapters()[ $id ]; $descriptor = $adapter->descriptor();
		$prepared = $adapter->prepare( $request, $market ); if ( is_wp_error( $prepared ) ) return $prepared;
		$obs_context = MAD4B_SCP_Search_Contracts::observation_context( $request, $descriptor, $prepared['resolved'] ); if ( is_wp_error( $obs_context ) ) return $obs_context;
		$build = self::runtime_build(); if ( is_wp_error( $build ) ) return $build;
		$epoch = self::epoch(); if ( is_wp_error( $epoch ) ) return $epoch;
		$plan = array( 'contract' => 'mad4b.search-capture-plan.v1', 'profile_id' => $profile['profile_id'], 'profile_revision' => $profile['revision'], 'target_id' => $t['target_id'], 'target_revision' => $row['_revision'], 'request' => $request, 'prepared' => $prepared, 'observation_context' => $obs_context, 'routing' => $routing, 'provider_generation' => $descriptor['certification_generation'], 'descriptor_fingerprint' => MAD4B_SCP_Search_Contracts::digest( MAD4B_SCP_Search_Providers::public_descriptor( $descriptor ) ), 'context_dependencies' => $context['dependencies'], 'runtime_build' => $build, 'restore_epoch' => $epoch, 'observation_epoch' => $input['observation_epoch'], 'budget_estimate' => $descriptor['economics'], 'authorizing' => false, 'mutation_performed' => false );
		$plan['mode'] = 'PROVIDER_CAPTURE'; return self::seal( $plan );
	}
	private static function seal( array $plan ) {
		$row = MAD4B_SCP_Search_Store::read( 'target', $plan['target_id'] );
		if ( ! is_array( $row ) ) return MAD4B_SCP_Search_Contracts::error( 'target_missing' );
		$profile = MAD4B_SCP_Search_Context::profile( $plan['profile_id'] ); if ( is_wp_error( $profile ) ) return $profile;
		$facts = MAD4B_SCP_Search_Runtime::facts( $profile, $row['target']['surface_refs'] ); if ( is_wp_error( $facts ) ) return $facts;
		$plan['target'] = $row['target'];
		$plan['derivation_policy'] = array( 'version' => 'signals-context.v1', 'stale_after_seconds' => $profile['refresh_policy']['max_seconds'] );
		$plan['surface_bindings'] = MAD4B_SCP_Search_Contracts::semantic( $facts['surfaces'] );
		$previous = MAD4B_SCP_Search_Store::read( 'current', $plan['target_id'] );
		$plan['prior_snapshot_id'] = is_array( $previous ) ? $previous['snapshot_id'] : '';
		$plan['plan_sha256'] = MAD4B_SCP_Search_Contracts::digest( $plan );
		$plan['job_id'] = MAD4B_SCP_Search_Contracts::digest( array( $plan['profile_id'], $plan['target_id'], $plan['observation_epoch'] ) );
		return $plan;
	}

	private static function update( $id, array $old, $state, array $extra = array() ) {
		if ( isset( $old['lease_until'] ) && time() >= $old['lease_until'] && ! ( isset( $extra['fence'], $extra['lease_until'] ) && $extra['fence'] > $old['fence'] && $extra['lease_until'] > time() ) && ! in_array( $state, array( 'RECONCILED_NO_EFFECT', 'RECONCILIATION_REQUIRED' ), true ) ) return MAD4B_SCP_Search_Contracts::error( 'lease_expired_fenced' );
		$next = array_merge( $old, $extra ); $next['state'] = $state;
		$next['checkpoints'][] = array( 'state' => $state, 'at' => time() );
		return MAD4B_SCP_Search_Store::cas( 'job', $id, $old, $next, $state );
	}

	public static function apply( array $input ) {
		if ( ! MAD4B_SCP_Search_Runtime::can_write() ) return MAD4B_SCP_Search_Contracts::error( 'execution_unauthorized' );
		$semantic = isset( $input['profile_id'], $input['target_id'], $input['observation_epoch'] ) ? MAD4B_SCP_Search_Contracts::digest( array( $input['profile_id'], $input['target_id'], $input['observation_epoch'] ) ) : '';
		$existing = MAD4B_SCP_Search_Store::read( 'job', $semantic );
		if ( is_array( $existing ) ) {
			if ( empty( $input['plan_sha256'] ) || ! hash_equals( $existing['plan']['plan_sha256'], (string) $input['plan_sha256'] ) ) return MAD4B_SCP_Search_Contracts::error( 'replay_plan_mismatch' );
			if ( 'COMPLETE' === $existing['state'] ) return array( 'state' => 'COMPLETE', 'job_id' => $semantic, 'snapshot_id' => $existing['snapshot_id'], 'replayed' => true, 'authorizing' => false );
			return MAD4B_SCP_Search_Contracts::error( 'reconciliation_required', 'Existing observation job must be reconciled; a second provider request was not made.' );
		}
		$plan = self::plan( $input ); if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $input['plan_sha256'] ) || ! hash_equals( $plan['plan_sha256'], (string) $input['plan_sha256'] ) ) return MAD4B_SCP_Search_Contracts::error( 'capture_plan_drift' );
		if ( 'PROVIDER_CAPTURE' === $plan['mode'] ) {
			$lock = MAD4B_SCP_Search_Store::read( 'capture-lock', $plan['target_id'] );
			if ( is_wp_error( $lock ) ) return $lock;
			if ( is_array( $lock ) ) {
				$owner = MAD4B_SCP_Search_Store::read( 'job', $lock['job_id'] );
				if ( is_array( $owner ) && ! in_array( $owner['state'], array( 'COMPLETE', 'CANCELLED_NO_EFFECT', 'RECONCILED_NO_EFFECT' ), true ) ) return MAD4B_SCP_Search_Contracts::error( 'target_reconciliation_required' );
				if ( null === $owner && $lock['lease_until'] > time() ) return MAD4B_SCP_Search_Contracts::error( 'target_lease_active' );
			}
			$claimed = MAD4B_SCP_Search_Store::cas( 'capture-lock', $plan['target_id'], $lock, array( 'job_id' => $plan['job_id'], 'lease_until' => time() + 120 ), 'CAPTURE_SERIALIZED' ); if ( is_wp_error( $claimed ) ) return $claimed;
		}
		$token = bin2hex( random_bytes( 32 ) );
		$job = MAD4B_SCP_Search_Store::cas( 'job', $plan['job_id'], null, array( 'contract' => 'mad4b.search-job.v1', 'job_id' => $plan['job_id'], 'state' => 'LEASED', 'plan' => $plan, 'lease_token_sha256' => hash( 'sha256', $token ), 'lease_until' => time() + 120, 'fence' => 1, 'checkpoints' => array( array( 'state' => 'CANDIDATE', 'at' => time() ), array( 'state' => 'ADMITTED', 'at' => time() ), array( 'state' => 'SCHEDULED', 'at' => time() ), array( 'state' => 'LEASED', 'at' => time() ) ) ), 'LEASED' );
		if ( is_wp_error( $job ) ) return $job;
		if ( 'CACHE_REUSE' === $plan['mode'] ) {
			$job = self::update( $plan['job_id'], $job, 'COMPLETE', array( 'snapshot_id' => $plan['snapshot_id'], 'cache_reused' => true ) );
			return is_wp_error( $job ) ? $job : array( 'state' => 'COMPLETE', 'job_id' => $plan['job_id'], 'snapshot_id' => $plan['snapshot_id'], 'cache_reused' => true, 'provider_execution_performed' => false, 'authorizing' => false );
		}
		$id = $plan['routing']['selected']['provider_id']; $adapter = MAD4B_SCP_Search_Providers::adapters()[ $id ]; $descriptor = $adapter->descriptor();
		$target_row = MAD4B_SCP_Search_Store::read( 'target', $plan['target_id'] );
		if ( ! is_array( $target_row ) ) return MAD4B_SCP_Search_Contracts::error( 'target_missing' );
		$dimensions = array_merge( $plan['request'], array_intersect_key( $target_row['target'], array_flip( array( 'site_uuid', 'brand_id', 'cluster_id', 'surface_type' ) ) ) );
		$dimensions['provider_id'] = $id;
		$reservation = MAD4B_SCP_Search_Budgets::reserve( $descriptor['account_id'], $plan['job_id'], $dimensions, $descriptor['economics']['request_units'], $descriptor['economics']['max_cost_micro'], time() );
		if ( is_wp_error( $reservation ) ) { self::update( $plan['job_id'], $job, 'CANCELLED_NO_EFFECT' ); return $reservation; }
		$job = self::update( $plan['job_id'], $job, 'PROVIDER_PREPARED', array( 'reservation' => $reservation, 'account_id' => $descriptor['account_id'] ) );
		if ( is_wp_error( $job ) ) return $job;
		$breaker = MAD4B_SCP_Provider_Circuit_Breaker::begin_attempt( $id, MAD4B_SCP_Site_Profile::site_uuid(), $descriptor['certification_generation'], false );
		if ( is_wp_error( $breaker ) ) { MAD4B_SCP_Search_Budgets::transition( $descriptor['account_id'], $plan['job_id'], $reservation['epoch'], 'released', time() ); self::update( $plan['job_id'], $job, 'CANCELLED_NO_EFFECT' ); return $breaker; }
		// Revalidate authority and all semantic inputs immediately before provider entry.
		$fresh = self::bound_plan( $input, array( $id => $plan['job_id'] ) );
		if ( ! is_wp_error( $fresh ) && isset( $fresh['routing']['selected']['provider_id'] ) && $fresh['routing']['selected']['provider_id'] === $id ) {
			// Alternatives may lose capacity after our hold. Only the pinned provider can enter.
			$fresh['routing'] = $plan['routing']; unset( $fresh['plan_sha256'], $fresh['job_id'] );
			$fresh['plan_sha256'] = MAD4B_SCP_Search_Contracts::digest( $fresh );
		}
		if ( ! MAD4B_SCP_Search_Runtime::can_write() || is_wp_error( $fresh ) || ! hash_equals( $plan['plan_sha256'], $fresh['plan_sha256'] ) || time() >= $job['lease_until'] ) {
			MAD4B_SCP_Search_Budgets::transition( $descriptor['account_id'], $plan['job_id'], $reservation['epoch'], 'released', time() ); self::update( $plan['job_id'], $job, 'CANCELLED_NO_EFFECT' ); return MAD4B_SCP_Search_Contracts::error( 'provider_entry_fenced' );
		}
		$entered = MAD4B_SCP_Search_Budgets::transition( $descriptor['account_id'], $plan['job_id'], $reservation['epoch'], 'entered', time() ); if ( is_wp_error( $entered ) ) return $entered;
		$job = self::update( $plan['job_id'], $job, 'PROVIDER_ENTERED' ); if ( is_wp_error( $job ) ) return $job;
		try { $response = $adapter->execute( $plan['prepared'] ); } catch ( Throwable $error ) { $response = MAD4B_SCP_Search_Contracts::error( 'external_effect_unknown' ); }
		if ( is_wp_error( $response ) ) {
			MAD4B_SCP_Provider_Circuit_Breaker::record_result( $breaker, false, 'timeout' );
			MAD4B_SCP_Search_Budgets::transition( $descriptor['account_id'], $plan['job_id'], $reservation['epoch'], 'unknown', time() );
			self::update( $plan['job_id'], $job, 'RECONCILIATION_REQUIRED', array( 'blind_retry_allowed' => false ) ); return MAD4B_SCP_Search_Contracts::error( 'reconciliation_required' );
		}
		MAD4B_SCP_Provider_Circuit_Breaker::record_result( $breaker, true );
		return self::complete( $job, $adapter, $descriptor, $response, $token );
	}

	private static function complete( array $job, MAD4B_SCP_Search_SERP_Adapter $adapter, array $descriptor, array $response, $token ) {
		$plan = $job['plan']; $id = $job['job_id']; $descriptor = $adapter->descriptor();
		$current = MAD4B_SCP_Search_Store::read( 'job', $id );
		if ( ! is_array( $current ) || $current['fence'] !== $job['fence'] || ! hash_equals( $current['lease_token_sha256'], hash( 'sha256', $token ) ) || time() >= $current['lease_until'] || self::epoch() !== $plan['restore_epoch'] || $descriptor['certification_generation'] !== $plan['provider_generation'] ) return MAD4B_SCP_Search_Contracts::error( 'late_provider_result_fenced' );
		if ( ! isset( $response['payload'], $response['raw_sha256'] ) || ! is_array( $response['payload'] ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_response_invalid' );
		$normalized = $adapter->normalize( $response['payload'], $plan['request'] );
		if ( is_wp_error( $normalized ) ) { self::update( $id, $current, 'RECONCILIATION_REQUIRED' ); return $normalized; }
		if ( ! isset( $normalized['resolved'] ) ) { self::update( $id, $current, 'RECONCILIATION_REQUIRED' ); return MAD4B_SCP_Search_Contracts::error( 'geo_fidelity_unproven' ); }
		foreach ( array( 'location_id', 'country', 'language_code' ) as $field ) if ( ! isset( $normalized['resolved'][ $field ] ) || (string) $normalized['resolved'][ $field ] !== (string) $plan['prepared']['resolved'][ $field ] ) { self::update( $id, $current, 'RECONCILIATION_REQUIRED' ); return MAD4B_SCP_Search_Contracts::error( 'provider_location_mismatch' ); }
		$snapshot = MAD4B_SCP_Search_Evidence::snapshot( $plan['request'], $descriptor, $plan['prepared']['resolved'], $normalized, $response['raw_sha256'], $plan['runtime_build'], time() );
		if ( is_wp_error( $snapshot ) ) { self::update( $id, $current, 'RECONCILIATION_REQUIRED' ); return $snapshot; }
		$snapshot['owned_surfaces'] = $plan['surface_bindings']; $snapshot['target_version'] = MAD4B_SCP_Search_Contracts::digest( $plan['target'] );
		$snapshot['prior_snapshot_id'] = $plan['prior_snapshot_id']; $snapshot['derivation_policy'] = $plan['derivation_policy'];
		unset( $snapshot['snapshot_id'] ); $snapshot['snapshot_id'] = MAD4B_SCP_Search_Contracts::digest( $snapshot );
		$current = self::update( $id, $current, 'PROVIDER_RETURNED', array( 'pending_snapshot' => $snapshot ) ); if ( is_wp_error( $current ) ) return $current;
		$current = self::update( $id, $current, 'VALIDATED' ); if ( is_wp_error( $current ) ) return $current;
		$current = self::update( $id, $current, 'NORMALIZED' ); if ( is_wp_error( $current ) ) return $current;
		return self::finish_local( $current );
	}

	private static function finish_local( array $current ) {
		$plan = $current['plan']; $id = $current['job_id']; $snapshot = $current['pending_snapshot'];
		$version = MAD4B_SCP_Search_Store::immutable( 'target-version', $snapshot['target_version'], $plan['target'] ); if ( is_wp_error( $version ) ) return $version;
		$stored = MAD4B_SCP_Search_Evidence::commit( $snapshot ); if ( is_wp_error( $stored ) ) return $stored;
		$spent = MAD4B_SCP_Search_Budgets::transition( $current['account_id'], $id, $current['reservation']['epoch'], 'spent', time(), $snapshot['cost']['units'], $snapshot['cost']['cost_micro'] );
		if ( is_wp_error( $spent ) || 'spent' !== $spent['state'] ) { self::update( $id, $current, 'RECONCILIATION_REQUIRED' ); return MAD4B_SCP_Search_Contracts::error( 'cost_reconciliation_required' ); }
		$current = self::update( $id, $current, 'EVIDENCE_COMMITTED', array( 'snapshot_id' => $snapshot['snapshot_id'] ) ); if ( is_wp_error( $current ) ) return $current;
		$target_row = MAD4B_SCP_Search_Store::read( 'target', $plan['target_id'] );
		if ( ! is_array( $target_row ) ) return MAD4B_SCP_Search_Contracts::error( 'target_missing' );
		$inference = MAD4B_SCP_Search_Insights::process( $snapshot, $plan['target'], $plan['prior_snapshot_id'] ); if ( is_wp_error( $inference ) ) return $inference;
		$current = self::update( $id, $current, 'SIGNALS_DERIVED', array( 'inference' => $inference ) ); if ( is_wp_error( $current ) ) return $current;
		$projection = MAD4B_SCP_Search_Store::read( 'current', $plan['target_id'] );
		if ( ! is_array( $projection ) || ( $projection['snapshot_id'] !== $snapshot['snapshot_id'] && $projection['captured_at'] <= $snapshot['captured_at'] ) ) {
			$view = MAD4B_SCP_Search_Store::cas( 'current', $plan['target_id'], $projection, array( 'profile_id' => $plan['profile_id'], 'snapshot_id' => $snapshot['snapshot_id'], 'captured_at' => $snapshot['captured_at'], 'context_dependencies' => $plan['context_dependencies'] ), 'SERP_CAPTURED' ); if ( is_wp_error( $view ) ) return $view;
		}
		$coverage = MAD4B_SCP_Search_Store::read( 'coverage', $plan['profile_id'] );
		$dimensions = is_array( $coverage ) ? $coverage['dimensions'] : array();
		$seen = is_array( $coverage ) && isset( $coverage['jobs'] ) ? $coverage['jobs'] : array();
		$profile = MAD4B_SCP_Search_Context::profile( $plan['profile_id'] );
		if ( ! isset( $seen[ $id ] ) && ! is_wp_error( $profile ) ) {
			foreach ( $profile['priority_policy']['fairness_dimensions'] as $dimension ) {
				$value = isset( $target_row['target'][ $dimension ] ) ? $target_row['target'][ $dimension ] : ( 'provider_id' === $dimension ? $plan['routing']['selected']['provider_id'] : '' );
				$dimensions[ $dimension ][ $value ] = ( isset( $dimensions[ $dimension ][ $value ] ) ? $dimensions[ $dimension ][ $value ] : 0 ) + 1;
			}
			$seen[ $id ] = time(); if ( count( $seen ) > 1000 ) $seen = array_slice( $seen, -1000, null, true );
			$fairness = MAD4B_SCP_Search_Store::cas( 'coverage', $plan['profile_id'], $coverage, array( 'dimensions' => $dimensions, 'jobs' => $seen ), 'COVERAGE_OBSERVED' ); if ( is_wp_error( $fairness ) ) return $fairness;
		}
		$current = self::update( $id, $current, 'COMPLETE' );
		if ( ! is_wp_error( $current ) ) { $copy = $current; unset( $copy['pending_snapshot'] ); $current = MAD4B_SCP_Search_Store::cas( 'job', $id, $current, $copy, 'CHECKPOINT_PAYLOAD_RELEASED' ); }
		return is_wp_error( $current ) ? $current : array( 'state' => 'COMPLETE', 'job_id' => $id, 'snapshot_id' => $snapshot['snapshot_id'], 'authorizing' => false );
	}

	public static function reconcile( array $input ) {
		if ( ! MAD4B_SCP_Search_Runtime::can_write() ) return MAD4B_SCP_Search_Contracts::error( 'execution_unauthorized' );
		$job = MAD4B_SCP_Search_Store::read( 'job', isset( $input['job_id'] ) ? $input['job_id'] : '' );
		if ( ! is_array( $job ) || ! in_array( $job['state'], array( 'LEASED', 'PROVIDER_PREPARED', 'PROVIDER_ENTERED', 'RECONCILIATION_REQUIRED', 'PROVIDER_RETURNED', 'VALIDATED', 'NORMALIZED', 'EVIDENCE_COMMITTED', 'SIGNALS_DERIVED' ), true ) ) return MAD4B_SCP_Search_Contracts::error( 'job_not_reconcilable' );
		if ( in_array( $job['state'], array( 'LEASED', 'PROVIDER_PREPARED' ), true ) ) {
			if ( time() < $job['lease_until'] ) return MAD4B_SCP_Search_Contracts::error( 'active_lease' );
			$adapters = MAD4B_SCP_Search_Providers::adapters(); $id = $job['plan']['routing']['selected']['provider_id'];
			if ( ! isset( $adapters[ $id ] ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_missing' );
			$d = $adapters[ $id ]->descriptor(); $budget = MAD4B_SCP_Search_Budgets::status( $d['account_id'] );
			if ( is_array( $budget ) && isset( $budget['reservations'][ $job['job_id'] ] ) ) {
				$r = $budget['reservations'][ $job['job_id'] ];
				if ( 'entered' === $r['state'] ) { $u = MAD4B_SCP_Search_Budgets::transition( $d['account_id'], $job['job_id'], $r['epoch'], 'unknown', time() ); if ( is_wp_error( $u ) ) return $u; }
				$release = MAD4B_SCP_Search_Budgets::transition( $d['account_id'], $job['job_id'], $r['epoch'], 'released', time() ); if ( is_wp_error( $release ) ) return $release;
			}
			return self::update( $job['job_id'], $job, 'RECONCILED_NO_EFFECT', array( 'reconciliation_reason' => 'provider_entry_checkpoint_was_never_committed', 'blind_retry_allowed' => false ) );
		}
		if ( ! empty( $job['pending_snapshot'] ) ) {
			if ( time() < $job['lease_until'] || self::epoch() !== $job['plan']['restore_epoch'] ) return MAD4B_SCP_Search_Contracts::error( 'local_resume_fenced' );
			$claimed = self::update( $job['job_id'], $job, 'NORMALIZED', array( 'fence' => $job['fence'] + 1, 'lease_until' => time() + 120, 'lease_token_sha256' => hash( 'sha256', random_bytes( 32 ) ) ) );
			return is_wp_error( $claimed ) ? $claimed : self::finish_local( $claimed );
		}
		if ( time() < $job['lease_until'] && 'RECONCILIATION_REQUIRED' !== $job['state'] ) return MAD4B_SCP_Search_Contracts::error( 'active_lease' );
		$id = $job['plan']['routing']['selected']['provider_id']; $adapters = MAD4B_SCP_Search_Providers::adapters();
		if ( ! isset( $adapters[ $id ] ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_missing' );
		$adapter = $adapters[ $id ]; $d = $adapter->descriptor();
		if ( $d['certification_generation'] !== $job['plan']['provider_generation'] ) return MAD4B_SCP_Search_Contracts::error( 'provider_generation_drift' );
		$receipt = $adapter->reconcile( $job ); if ( is_wp_error( $receipt ) ) return $receipt;
		if ( ! is_array( $receipt ) || empty( $receipt['verified'] ) || ! isset( $receipt['request_fingerprint'], $receipt['provider_generation'], $receipt['effect'] ) || $receipt['request_fingerprint'] !== MAD4B_SCP_Search_Contracts::digest( $job['plan']['request'] ) || $receipt['provider_generation'] !== $job['plan']['provider_generation'] ) return MAD4B_SCP_Search_Contracts::error( 'reconciliation_receipt_invalid' );
		$budget = MAD4B_SCP_Search_Budgets::status( $job['account_id'] ); $r = $budget['reservations'][ $job['job_id'] ];
		if ( 'entered' === $r['state'] ) { $u = MAD4B_SCP_Search_Budgets::transition( $job['account_id'], $job['job_id'], $r['epoch'], 'unknown', time() ); if ( is_wp_error( $u ) ) return $u; }
		if ( false === $receipt['effect'] ) {
			$released = MAD4B_SCP_Search_Budgets::transition( $job['account_id'], $job['job_id'], $r['epoch'], 'released', time() ); if ( is_wp_error( $released ) ) return $released;
			return self::update( $job['job_id'], $job, 'RECONCILED_NO_EFFECT', array( 'reconciliation_receipt' => $receipt, 'blind_retry_allowed' => false ) );
		}
		if ( ! empty( $job['retention_blocked'] ) ) {
			if ( ! isset( $receipt['usage']['units'], $receipt['usage']['cost_micro'] ) ) return MAD4B_SCP_Search_Contracts::error( 'retired_evidence_usage_receipt_required' );
			$spent = MAD4B_SCP_Search_Budgets::transition( $job['account_id'], $job['job_id'], $r['epoch'], 'spent', time(), $receipt['usage']['units'], $receipt['usage']['cost_micro'] );
			if ( is_wp_error( $spent ) || 'spent' !== $spent['state'] ) return MAD4B_SCP_Search_Contracts::error( 'cost_reconciliation_required' );
			return self::update( $job['job_id'], $job, 'COMPLETE', array( 'fence' => $job['fence'] + 1, 'lease_until' => time() + 120, 'snapshot_id' => $job['retired_snapshot_id'], 'evidence_retired' => true, 'usage_receipt' => $receipt['usage'], 'blind_retry_allowed' => false ) );
		}
		if ( ! isset( $receipt['response'] ) || ! is_array( $receipt['response'] ) ) return MAD4B_SCP_Search_Contracts::error( 'reconciliation_response_missing' );
		$token = bin2hex( random_bytes( 32 ) );
		$claimed = self::update( $job['job_id'], $job, 'PROVIDER_ENTERED', array( 'fence' => $job['fence'] + 1, 'lease_until' => time() + 120, 'lease_token_sha256' => hash( 'sha256', $token ), 'reconciliation_receipt' => $receipt ) );
		return is_wp_error( $claimed ) ? $claimed : self::complete( $claimed, $adapter, $d, $receipt['response'], $token );
	}
}
