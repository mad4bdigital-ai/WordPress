<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded public facade; domain services never manufacture Capability Fabric grants. */
final class MAD4B_SCP_Search_Runtime {
	public static function can_configure() {
		$env = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_environment() : '';
		return current_user_can( 'manage_options' ) && in_array( $env, array( 'staging', 'development', 'local' ), true ) && MAD4B_SCP_Site_Profile::origin_enrolled();
	}
	public static function can_write() { return self::can_configure() && class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_mutate(); }

	public static function facts( array $profile, array $refs = array(), array $cursors = array() ) {
		$languages = MAD4B_SCP_Search_Surfaces::languages(); $surfaces = array(); $seo = array();
		if ( $refs ) {
			foreach ( $refs as $ref ) {
				$row = MAD4B_SCP_Search_Store::read( 'surface', $ref );
				if ( ! is_array( $row ) || empty( $row['surface'] ) ) return MAD4B_SCP_Search_Contracts::error( 'surface_missing' );
				$fresh = MAD4B_SCP_Search_Surfaces::refresh( $row['surface'], $profile['surface_policy'] );
				if ( is_wp_error( $fresh ) ) return $fresh;
				$surfaces[] = $fresh; $seo[ $ref ] = $fresh['seo_fingerprint'];
			}
		} else {
			$discovery = MAD4B_SCP_Search_Surfaces::discover( $profile, $cursors );
			$surfaces = $discovery['surfaces']; foreach ( $surfaces as $s ) $seo[ $s['surface_key'] ] = $s['seo_fingerprint'];
		}
		foreach ( $surfaces as $s ) if ( isset( $languages[ $s['language'] ] ) && $languages[ $s['language'] ]['active'] && 'eligible' === $s['eligibility']['effective'] && 'TERM' !== $s['surface_type'] ) $languages[ $s['language'] ]['owned_count'] = max( 1, $languages[ $s['language'] ]['owned_count'] );
		$providers = array(); foreach ( MAD4B_SCP_Search_Providers::adapters() as $id => $a ) {
			$d = $a->descriptor(); $providers[ $id ] = array( 'generation' => isset( $d['certification_generation'] ) ? $d['certification_generation'] : '', 'capabilities' => isset( $d['capabilities'] ) ? $d['capabilities'] : array(), 'rights' => isset( $d['evidence_rights'] ) ? $d['evidence_rights'] : array() );
		}
		return array( 'languages' => $languages, 'surfaces' => $surfaces, 'seo' => $seo, 'providers' => $providers, 'budgets' => $profile['budget_policy'] );
	}

	public static function profile_plan( $input ) { return is_array( $input ) ? MAD4B_SCP_Search_Context::plan( $input ) : MAD4B_SCP_Search_Contracts::error( 'input_invalid' ); }
	public static function profile_apply( $input ) { return self::can_configure() && is_array( $input ) ? MAD4B_SCP_Search_Context::apply( $input ) : MAD4B_SCP_Search_Contracts::error( 'configuration_unauthorized' ); }
	public static function profile_verify( $input ) { return MAD4B_SCP_Search_Context::verify( is_array( $input ) ? $input : array() ); }

	public static function discover( $input ) {
		$p = MAD4B_SCP_Search_Context::profile( isset( $input['profile_id'] ) ? $input['profile_id'] : '' );
		return is_wp_error( $p ) ? $p : MAD4B_SCP_Search_Surfaces::discover( $p, isset( $input['cursors'] ) && is_array( $input['cursors'] ) ? $input['cursors'] : array() );
	}
	public static function compile_plan( $input ) {
		$p = MAD4B_SCP_Search_Context::profile( isset( $input['profile_id'] ) ? $input['profile_id'] : '' ); if ( is_wp_error( $p ) ) return $p;
		$facts = self::facts( $p, array(), isset( $input['cursors'] ) && is_array( $input['cursors'] ) ? $input['cursors'] : array() ); if ( is_wp_error( $facts ) ) return $facts;
		$ctx = MAD4B_SCP_Search_Context::compile( $p, $facts ); if ( is_wp_error( $ctx ) ) return $ctx;
		$keywords = isset( $input['candidates'] ) && is_array( $input['candidates'] ) ? $input['candidates'] : array();
		$candidates = MAD4B_SCP_Search_Targets::candidates( $ctx, $facts['surfaces'], $keywords, apply_filters( 'mad4b_scp_search_derived_candidates', array(), $ctx ) );
		$compiled = MAD4B_SCP_Search_Targets::compile( $ctx, $candidates, $facts['surfaces'] ); if ( is_wp_error( $compiled ) ) return $compiled;
		foreach ( $compiled['targets'] as &$target ) $target['profile_id'] = $p['profile_id']; unset( $target );
		$plan = array( 'contract' => 'mad4b.search-compile-plan.v1', 'context' => $ctx, 'compilation' => $compiled, 'surfaces' => $facts['surfaces'], 'authorizing' => false, 'provider_execution_performed' => false );
		$plan['plan_sha256'] = MAD4B_SCP_Search_Contracts::digest( MAD4B_SCP_Search_Contracts::semantic( $plan ) ); return $plan;
	}
	public static function compile_apply( $input ) {
		if ( ! self::can_configure() ) return MAD4B_SCP_Search_Contracts::error( 'configuration_unauthorized' );
		$plan = self::compile_plan( $input ); if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $input['plan_sha256'] ) || ! hash_equals( $plan['plan_sha256'], (string) $input['plan_sha256'] ) ) return MAD4B_SCP_Search_Contracts::error( 'compile_plan_drift' );
		foreach ( $plan['surfaces'] as $surface ) {
			$row = MAD4B_SCP_Search_Store::read( 'surface', $surface['surface_key'] );
			$stored = MAD4B_SCP_Search_Store::cas( 'surface', $surface['surface_key'], $row, array( 'surface' => $surface ), 'SURFACE_DISCOVERED' ); if ( is_wp_error( $stored ) ) return $stored;
		}
		foreach ( $plan['compilation']['targets'] as $target ) { $stored = MAD4B_SCP_Search_Targets::persist( $target ); if ( is_wp_error( $stored ) ) return $stored; }
		return array( 'compiled_count' => count( $plan['compilation']['targets'] ), 'plan_sha256' => $plan['plan_sha256'], 'authorizing' => false, 'provider_execution_performed' => false );
	}
	public static function capture_plan( $input ) { return is_array( $input ) ? MAD4B_SCP_Search_Worker::plan( $input ) : MAD4B_SCP_Search_Contracts::error( 'input_invalid' ); }
	public static function capture_apply( $input ) { return is_array( $input ) ? MAD4B_SCP_Search_Worker::apply( $input ) : MAD4B_SCP_Search_Contracts::error( 'input_invalid' ); }
	public static function reconcile( $input ) { return MAD4B_SCP_Search_Worker::reconcile( is_array( $input ) ? $input : array() ); }

	public static function budget_plan( $input ) {
		$p = MAD4B_SCP_Search_Context::profile( isset( $input['profile_id'] ) ? $input['profile_id'] : '' ); if ( is_wp_error( $p ) ) return $p;
		$a = MAD4B_SCP_Search_Providers::adapters(); $id = isset( $input['provider_id'] ) ? $input['provider_id'] : '';
		if ( ! isset( $a[ $id ] ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_missing' );
		$d = $a[ $id ]->descriptor(); $guard = MAD4B_SCP_Search_Providers::descriptor_guard( $d, time() ); if ( is_wp_error( $guard ) ) return $guard;
		$plan = array( 'contract' => 'mad4b.search-budget-plan.v1', 'profile_fingerprint' => $p['profile_sha256'], 'provider_id' => $id, 'account_id' => $d['account_id'], 'policy' => $p['budget_policy'], 'usage' => array_merge( $d['usage'], array( 'generation' => $d['certification_generation'] ) ), 'authorizing' => false );
		$plan['plan_sha256'] = MAD4B_SCP_Search_Contracts::digest( $plan ); return $plan;
	}
	public static function budget_apply( $input ) {
		if ( ! self::can_write() ) return MAD4B_SCP_Search_Contracts::error( 'execution_unauthorized' );
		$plan = self::budget_plan( $input ); if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $input['plan_sha256'] ) || ! hash_equals( $plan['plan_sha256'], (string) $input['plan_sha256'] ) ) return MAD4B_SCP_Search_Contracts::error( 'budget_plan_drift' );
		return MAD4B_SCP_Search_Budgets::configure( $plan['account_id'], $plan['policy'], $plan['usage'], time() );
	}

	public static function cohort( $input ) {
		$p = MAD4B_SCP_Search_Context::profile( isset( $input['profile_id'] ) ? $input['profile_id'] : '' ); if ( is_wp_error( $p ) ) return $p;
		$facts = self::facts( $p ); if ( is_wp_error( $facts ) ) return $facts;
		$ctx = MAD4B_SCP_Search_Context::compile( $p, $facts ); if ( is_wp_error( $ctx ) ) return $ctx;
		$rows = MAD4B_SCP_Search_Store::list_rows( 'target', isset( $input['cursor'] ) ? $input['cursor'] : '', 200 ); if ( is_wp_error( $rows ) ) return $rows;
		$decisions = array();
		foreach ( $rows['items'] as $row ) {
			$t = $row['target']; if ( empty( $t['profile_id'] ) || $t['profile_id'] !== $p['profile_id'] ) continue;
			$factors = apply_filters( 'mad4b_scp_search_decision_factors', array(), $t );
			if ( ! is_array( $factors ) || ! MAD4B_SCP_Search_Contracts::bounded( $factors ) ) $factors = array();
			// First-party evidence is tagged separately and can reduce paid observation urgency.
			$first_party = apply_filters( 'mad4b_scp_search_performance_evidence', array(), $t );
			$performance = MAD4B_SCP_Search_Decisions::performance_factors( $first_party );
			if ( ! is_wp_error( $performance ) ) $factors = array_merge( $factors, $performance );
			$view = MAD4B_SCP_Search_Store::read( 'current', $t['target_id'] ); $history = array( 'queued_at' => isset( $row['_event']['at'] ) ? $row['_event']['at'] : time(), 'last_observed' => is_array( $view ) ? $view['captured_at'] : 0 );
			$decision = MAD4B_SCP_Search_Decisions::score( $t, $p['priority_policy'], $factors, $history, time() ); if ( is_wp_error( $decision ) ) continue;
			$seconds = MAD4B_SCP_Search_Decisions::refresh( $p['refresh_policy'], array( 'confidence' => is_array( $view ) ? 0.5 : 0, 'fingerprint_changed' => is_array( $view ) && MAD4B_SCP_Search_Context::drift( $view['context_dependencies'], $ctx['dependencies'], array( 'LANGUAGE', 'SURFACE', 'SEO_PROVIDER' ) ) ), $decision['factor_provenance'] );
			$due = ! $history['last_observed'] || time() - $history['last_observed'] >= $seconds || ( ! empty( $row['refresh_requested_at'] ) && $row['refresh_requested_at'] > $history['last_observed'] );
			$plan = MAD4B_SCP_Search_Worker::plan( array( 'profile_id' => $p['profile_id'], 'target_id' => $t['target_id'], 'observation_epoch' => time() ) );
			if ( ! is_wp_error( $plan ) ) { $history['estimated_cost_micro'] = isset( $plan['budget_estimate']['max_cost_micro'] ) ? $plan['budget_estimate']['max_cost_micro'] : 0; $decision = MAD4B_SCP_Search_Decisions::score( $t, $p['priority_policy'], $factors, $history, time() ); }
			$decision['refresh_seconds'] = $seconds; $decision['next_due_at'] = $history['last_observed'] + $seconds;
			$decision['performance_evidence'] = array( 'source_class' => 'first_party_search_performance', 'state' => is_wp_error( $performance ) ? 'REJECTED' : ( $performance ? 'COMPOSED' : 'NOT_OBSERVED' ), 'rejection_reason' => is_wp_error( $performance ) ? $performance->get_error_code() : '', 'live_provider_receipt' => false, 'authorizing' => false );
			$decision['routing'] = is_wp_error( $plan ) ? array( 'excluded' => array( $plan->get_error_code() ) ) : ( isset( $plan['routing'] ) ? $plan['routing'] : array( 'selected' => 'CACHE_REUSE' ) );
			if ( ! empty( $row['pinned'] ) ) $decision['effective_priority'] += 1;
			if ( ! is_wp_error( $plan ) && isset( $plan['routing']['selected']['provider_id'] ) ) $t['provider_id'] = $plan['routing']['selected']['provider_id'];
			$decisions[] = array( 'target' => $t, 'decision' => $decision, 'provider_eligible' => ! is_wp_error( $plan ), 'muted' => ! empty( $row['muted'] ), 'due' => $due, 'last_observed' => $history['last_observed'] );
		}
		$coverage = MAD4B_SCP_Search_Store::read( 'coverage', $p['profile_id'] );
		$batch = MAD4B_SCP_Search_Decisions::batch( $ctx, $decisions, isset( $input['limit'] ) ? $input['limit'] : 20, is_array( $coverage ) ? $coverage['dimensions'] : array(), time() );
		$batch['cursor'] = $rows['cursor']; return $batch;
	}

	public static function proposal( $input ) {
		$signal = MAD4B_SCP_Search_Store::evidence( 'signal', isset( $input['signal_id'] ) ? $input['signal_id'] : '' ); if ( is_wp_error( $signal ) ) return $signal;
		$slug = isset( $input['experience_slug'] ) ? $input['experience_slug'] : '';
		if ( ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) ) return MAD4B_SCP_Search_Contracts::error( 'content_experience_unavailable' );
		$profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug ); if ( is_wp_error( $profile ) ) return $profile;
		$routes = MAD4B_SCP_Content_Experience_Profiles::profile_routes( $slug, $profile['revision'] );
		return array( 'contract' => 'mad4b.search-content-proposal.v1', 'signal_id' => $signal['signal_id'], 'recommendation' => $signal['details'], 'experience_slug' => $slug, 'experience_revision' => $profile['revision'], 'next_plan_ability' => $routes['update_plan'], 'required_sequence' => array( 'plan', 'approval', 'apply', 'verify', 'post_change_observation' ), 'authorizing' => false, 'content_mutation_performed' => false );
	}

	public static function status( $input = array() ) {
		$profile = ! empty( $input['profile_id'] ) ? MAD4B_SCP_Search_Context::profile( $input['profile_id'] ) : null;
		if ( is_wp_error( $profile ) ) return $profile;
		$providers = array(); foreach ( MAD4B_SCP_Search_Providers::adapters() as $id => $adapter ) {
			$d = $adapter->descriptor(); $public = MAD4B_SCP_Search_Providers::public_descriptor( $d );
			$public['profile_allowed'] = ! $profile || ( in_array( $id, $profile['provider_policy']['allowed'], true ) && ! in_array( $id, $profile['provider_policy']['disabled'], true ) );
			$breaker = MAD4B_SCP_Provider_Circuit_Breaker::status( $id, MAD4B_SCP_Site_Profile::site_uuid(), isset( $d['certification_generation'] ) ? $d['certification_generation'] : '' );
			$public['health']['breaker_state'] = is_wp_error( $breaker ) ? 'unknown' : $breaker['state'];
			$budget = MAD4B_SCP_Search_Budgets::status( isset( $d['account_id'] ) ? $d['account_id'] : '' );
			$public['budget_admissible'] = is_array( $budget ) && $budget['reset_at'] > time() && $budget['remaining'] > ceil( $budget['usage_receipt']['remaining'] * $budget['policy']['reserve_fraction'] ) && empty( $budget['overrun_requires_reconciliation'] );
			$providers[ $id ] = $public;
		}
		$jobs = MAD4B_SCP_Search_Store::list_rows( 'job' ); $observations = MAD4B_SCP_Search_Store::list_rows( 'current' );
		$jobs = is_array( $jobs ) ? $jobs['items'] : array(); $views = is_array( $observations ) ? $observations['items'] : array();
		if ( $profile ) {
			$jobs = array_values( array_filter( $jobs, static function ( $r ) use ( $profile ) { return isset( $r['plan']['profile_id'] ) && $r['plan']['profile_id'] === $profile['profile_id']; } ) );
			$views = array_values( array_filter( $views, static function ( $r ) use ( $profile ) { return isset( $r['profile_id'] ) && $r['profile_id'] === $profile['profile_id']; } ) );
		}
		foreach ( $views as $key => $view ) if ( is_wp_error( MAD4B_SCP_Search_Store::evidence( 'snapshot', $view['snapshot_id'] ) ) ) unset( $views[ $key ] );
		$inventory = MAD4B_SCP_Search_Store::list_rows( 'surface', '', 1 );
		return MAD4B_SCP_Search_Experience::model( $profile, $providers, $jobs, array_values( $views ), is_array( $inventory ) && ! empty( $inventory['items'] ) );
	}
	public static function control( $input ) { return MAD4B_SCP_Search_Experience::control( is_array( $input ) ? $input : array() ); }
	public static function provider_probe( $input ) { return MAD4B_SCP_Search_Providers::probe( is_array( $input ) ? $input : array() ); }
	public static function post_change_plan( $input ) { return MAD4B_SCP_Search_Insights::post_change_plan( is_array( $input ) ? $input : array() ); }
	public static function post_change_apply( $input ) { return MAD4B_SCP_Search_Insights::post_change_apply( is_array( $input ) ? $input : array() ); }
	public static function experiment( $input ) { return MAD4B_SCP_Search_Insights::outcome( is_array( $input ) ? $input : array() ); }
	public static function recompute( $input ) {
		if ( ! self::can_configure() ) return MAD4B_SCP_Search_Contracts::error( 'configuration_unauthorized' );
		$s = MAD4B_SCP_Search_Store::evidence( 'snapshot', isset( $input['snapshot_id'] ) ? $input['snapshot_id'] : '' ); if ( is_wp_error( $s ) ) return $s;
		if ( empty( $s['target_version'] ) ) return MAD4B_SCP_Search_Contracts::error( 'frozen_target_required' );
		$t = MAD4B_SCP_Search_Store::evidence( 'target-version', $s['target_version'] ); if ( is_wp_error( $t ) ) return $t;
		return MAD4B_SCP_Search_Insights::process( $s, $t, isset( $input['prior_snapshot_id'] ) ? $input['prior_snapshot_id'] : null );
	}
	public static function import_evidence( $input ) { return self::can_configure() && is_array( $input ) ? MAD4B_SCP_Search_Evidence::import( $input ) : MAD4B_SCP_Search_Contracts::error( 'configuration_unauthorized' ); }
	public static function retention( $input = array() ) {
		if ( ! self::can_configure() ) return MAD4B_SCP_Search_Contracts::error( 'configuration_unauthorized' );
		$kind = isset( $input['kind'] ) ? $input['kind'] : 'snapshot';
		if ( ! in_array( $kind, array( 'snapshot', 'signal', 'graph', 'job' ), true ) ) return MAD4B_SCP_Search_Contracts::error( 'retention_kind_invalid' );
		$rows = MAD4B_SCP_Search_Store::list_rows( $kind, isset( $input['cursor'] ) ? $input['cursor'] : '', 100 ); if ( is_wp_error( $rows ) ) return $rows; $retired = array();
		foreach ( $rows['items'] as $r ) {
			if ( 'job' === $kind ) {
				if ( ! isset( $r['pending_snapshot']['valid_until'] ) || $r['pending_snapshot']['valid_until'] > time() ) continue;
				$id = $r['job_id']; $next = $r; $next['retired_checkpoint_sha256'] = MAD4B_SCP_Search_Contracts::digest( $r['pending_snapshot'] ); $next['retired_snapshot_id'] = $r['pending_snapshot']['snapshot_id'];
				unset( $next['pending_snapshot'] ); $next['state'] = 'RECONCILIATION_REQUIRED'; $next['retention_blocked'] = true;
				$result = MAD4B_SCP_Search_Store::cas( 'job', $id, $r, $next, 'LICENSED_CHECKPOINT_RETIRED' );
			} else {
				if ( ! isset( $r['payload']['valid_until'] ) || $r['payload']['valid_until'] > time() ) continue;
				$id = $r['payload'][ $kind . '_id' ];
				$result = 'snapshot' === $kind ? MAD4B_SCP_Search_Insights::retire( $id, time() ) : MAD4B_SCP_Search_Store::cas( $kind, $id, $r, array( 'retired' => true, 'original_digest' => $r['digest'] ), 'LICENSED_DERIVATIVE_RETIRED' );
			}
			if ( is_wp_error( $result ) ) return $result; $retired[] = $id;
		}
		return array( 'kind' => $kind, 'retired' => $retired, 'cursor' => $rows['cursor'], 'authorizing' => false );
	}
	public static function execution_profiles( $input = array() ) {
		$b = MAD4B_SCP_Search_Store::backend();
		return array( 'contract' => 'mad4b.search-execution-profiles.v1', 'storage_profile' => $b instanceof MAD4B_SCP_Search_WordPress_Store ? 'wordpress_local' : 'registered_external_store', 'worker_profile' => apply_filters( 'mad4b_scp_search_worker_profile', 'wordpress_local' ), 'canonical_identity' => 'site_scoped', 'required_storage_semantics' => array( 'atomic_compare_exchange', 'immutable_digest_checked_evidence', 'bounded_cursor_scan' ), 'external_worker_authority' => 'existing_governed_abilities_only', 'generic_worker_command_allowed' => false, 'authorizing' => false );
	}
}
