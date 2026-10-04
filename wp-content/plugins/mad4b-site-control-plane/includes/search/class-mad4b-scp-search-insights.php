<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Inference is recomputable from evidence and cannot execute content operations. */
final class MAD4B_SCP_Search_Insights {
	public static function process( array $snapshot, array $target, $prior_id = null ) {
		if ( isset( $snapshot['target_version'] ) && $snapshot['target_version'] !== MAD4B_SCP_Search_Contracts::digest( $target ) ) return MAD4B_SCP_Search_Contracts::error( 'target_version_mismatch' );
		if ( isset( $snapshot['prior_snapshot_id'] ) ) {
			if ( null !== $prior_id && $prior_id !== $snapshot['prior_snapshot_id'] ) return MAD4B_SCP_Search_Contracts::error( 'prior_snapshot_mismatch' );
			$prior_id = $snapshot['prior_snapshot_id'];
		}
		$previous = MAD4B_SCP_Search_Store::read( 'current', $target['target_id'] );
		if ( null === $prior_id ) $prior_id = is_array( $previous ) && $previous['snapshot_id'] !== $snapshot['snapshot_id'] ? $previous['snapshot_id'] : '';
		$prior = $prior_id ? MAD4B_SCP_Search_Store::evidence( 'snapshot', $prior_id ) : array();
		if ( is_wp_error( $prior ) ) $prior = array();
		$surfaces = array();
		foreach ( $target['surface_refs'] as $id ) { $row = MAD4B_SCP_Search_Store::read( 'surface', $id ); if ( is_array( $row ) ) $surfaces[] = $row['surface']; }
		if ( isset( $snapshot['owned_surfaces'] ) ) $surfaces = $snapshot['owned_surfaces'];
		$signals = MAD4B_SCP_Search_Evidence::signals( $snapshot, $prior, $surfaces );
		$context = isset( $target['dependencies'] ) ? $target['dependencies'] : array();
		$flags = array();
		if ( ! $surfaces && 'MARKET_DISCOVERY' === $target['purpose'] ) $flags[] = 'market_gap_candidate';
		if ( 'LANGUAGE_GAP' === $target['purpose'] ) $flags[] = 'language_gap_candidate';
		foreach ( $surfaces as $s ) {
			if ( false !== strpos( $s['surface_type'], 'ARCHIVE' ) ) $flags[] = 'archive_opportunity';
			foreach ( $s['seo'] as $field => $row ) if ( ! empty( $row['conflicting'] ) ) $flags[] = 'seo_metadata_conflict';
		}
		if ( $prior && $snapshot['observation_context']['comparability_key'] !== $prior['observation_context']['comparability_key'] ) $flags[] = 'provider_disagreement_requires_comparability_review';
		$stale_after = isset( $snapshot['derivation_policy']['stale_after_seconds'] ) ? $snapshot['derivation_policy']['stale_after_seconds'] : null;
		if ( $prior && null !== $stale_after && $snapshot['captured_at'] - $prior['captured_at'] > $stale_after ) $flags[] = 'stale_evidence';
		foreach ( array_unique( $flags ) as $family ) {
			$signal = array( 'contract' => 'mad4b.search-signal.v1', 'family' => $family, 'query_id' => $target['target_id'], 'context' => $snapshot['observation_context'], 'confidence' => 0.5, 'evidence_refs' => array( $snapshot['snapshot_id'] ), 'evidence_count' => 1, 'derivation_version' => 'signals-context.v1', 'details' => array( 'purpose' => $target['purpose'], 'surface_refs' => $target['surface_refs'] ), 'authorizing' => false, 'direct_content_mutation' => false );
			$signal['signal_id'] = MAD4B_SCP_Search_Contracts::digest( $signal ); $signals[] = $signal;
		}
		$ids = array();
		foreach ( $signals as $signal ) {
			if ( ! isset( $signal['valid_until'] ) ) { $signal['valid_until'] = $snapshot['valid_until']; unset( $signal['signal_id'] ); $signal['signal_id'] = MAD4B_SCP_Search_Contracts::digest( $signal ); }
			$stored = MAD4B_SCP_Search_Store::immutable( 'signal', $signal['signal_id'], $signal ); if ( is_wp_error( $stored ) ) return $stored;
			$ids[] = $signal['signal_id'];
		}
		$graph = self::graph( $snapshot, $target, $surfaces, $signals );
		if ( $prior ) $graph['valid_until'] = min( $graph['valid_until'], $prior['valid_until'] );
		$saved = MAD4B_SCP_Search_Store::immutable( 'graph', $snapshot['snapshot_id'], $graph );
		return is_wp_error( $saved ) ? $saved : array( 'signal_ids' => $ids, 'graph_id' => $snapshot['snapshot_id'], 'authorizing' => false );
	}

	public static function graph( array $snapshot, array $target, array $surfaces, array $signals ) {
		$nodes = array(); $edges = array();
		$node = static function ( $type, $id, $data = array() ) use ( &$nodes ) { $key = $type . ':' . $id; $nodes[ $key ] = array( 'node_id' => $key, 'type' => $type, 'data' => $data ); return $key; };
		$edge = static function ( $from, $to, $relation ) use ( &$edges, $snapshot ) { $edges[] = array( 'from' => $from, 'to' => $to, 'relation' => $relation, 'evidence_ref' => $snapshot['snapshot_id'] ); };
		$site = $node( 'Site', MAD4B_SCP_Site_Profile::site_uuid() ); $brand = $node( 'Brand', isset( $target['brand_id'] ) ? $target['brand_id'] : '' ); $edge( $site, $brand, 'has_brand' );
		$query = $node( 'Query', $target['target_id'], $target['identity'] ); $capture = $node( 'SERPSnapshot', $snapshot['snapshot_id'] ); $edge( $query, $capture, 'observed_by' );
		foreach ( array( 'Market' => 'market', 'Language' => 'language', 'Intent' => 'purpose', 'Cluster' => 'cluster_id' ) as $type => $field ) $edge( $query, $node( $type, isset( $target[ $field ] ) ? $target[ $field ] : '' ), 'has_context' );
		foreach ( $surfaces as $s ) { $surface = $node( 'SearchSurface', $s['surface_key'], array( 'type' => $s['surface_type'], 'language' => $s['language'] ) ); $object = $node( 'Object', $s['object_id'], $s['object_ref'] ); $edge( $object, $surface, 'exposes_surface' ); $edge( $query, $surface, 'tracks_owned_surface' ); }
		foreach ( $snapshot['organic_results'] as $r ) { $url = $node( 'URL', $r['url_identity']['url_id'], array( 'url' => $r['url'] ) ); $domain = $node( 'Domain', $r['url_identity']['host'] ); $edge( $capture, $url, 'observed_result' ); $edge( $domain, $url, 'hosts' ); }
		foreach ( $signals as $s ) { $signal = $node( 'Signal', $s['signal_id'], array( 'family' => $s['family'], 'confidence' => $s['confidence'] ) ); $edge( $capture, $signal, 'supports_inference' ); $edge( $signal, $node( 'Recommendation', $s['signal_id'], array( 'approval_required' => true ) ), 'proposes_review' ); }
		foreach ( $snapshot['features'] as $index => $feature ) $edge( $capture, $node( 'SERPFeature', $snapshot['snapshot_id'] . ':' . $index, $feature ), 'observed_feature' );
		return array( 'contract' => 'mad4b.search-knowledge-graph.v1', 'graph_id' => $snapshot['snapshot_id'], 'nodes' => array_values( $nodes ), 'edges' => $edges, 'valid_until' => $snapshot['valid_until'], 'recomputable' => true, 'authorizing' => false );
	}

	public static function post_change_plan( array $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Content_Experience_Runtime' ) || empty( $input['experience_slug'] ) || empty( $input['post_id'] ) || empty( $input['profile_id'] ) || empty( $input['target_id'] ) ) return MAD4B_SCP_Search_Contracts::error( 'change_binding_missing' );
		$verification = MAD4B_SCP_Content_Experience_Runtime::verify( $input['experience_slug'], array( 'post_id' => (int) $input['post_id'] ) );
		if ( is_wp_error( $verification ) || empty( $verification['marker_match'] ) || empty( $verification['profile_revision_match'] ) || empty( $verification['core_state_sha256'] ) || empty( $verification['verification_sha256'] ) ) return MAD4B_SCP_Search_Contracts::error( 'content_change_unverified' );
		$p = MAD4B_SCP_Search_Context::profile( $input['profile_id'] ); if ( is_wp_error( $p ) ) return $p;
		$row = MAD4B_SCP_Search_Store::read( 'target', $input['target_id'] ); if ( ! is_array( $row ) || $row['target']['profile_id'] !== $p['profile_id'] ) return MAD4B_SCP_Search_Contracts::error( 'target_profile_mismatch' );
		$facts = MAD4B_SCP_Search_Runtime::facts( $p, $row['target']['surface_refs'] ); if ( is_wp_error( $facts ) ) return $facts;
		$bound = false; foreach ( $facts['surfaces'] as $surface ) if ( 'post' === $surface['object_ref']['kind'] && (int) $surface['object_ref']['id'] === (int) $input['post_id'] && 'eligible' === $surface['eligibility']['effective'] ) $bound = true;
		if ( ! $bound ) return MAD4B_SCP_Search_Contracts::error( 'change_surface_binding_missing' );
		$plan = array( 'contract' => 'mad4b.search-post-change-plan.v1', 'profile_id' => $p['profile_id'], 'profile_fingerprint' => $p['profile_sha256'], 'target_id' => $input['target_id'], 'verification' => $verification, 'pre_window_seconds' => $p['experiment_policy']['pre_seconds'], 'post_window_seconds' => $p['experiment_policy']['post_seconds'], 'content_fingerprint' => $verification['core_state_sha256'], 'seo_fingerprint' => MAD4B_SCP_Search_Contracts::digest( $verification['helper_verification'] ), 'purpose' => 'POST_CHANGE_VALIDATION', 'causality_claimed' => false, 'authorizing' => false );
		$plan['post_id'] = (int) $input['post_id']; $plan['experience_slug'] = $input['experience_slug'];
		$plan['surface_fingerprints'] = array_column( $facts['surfaces'], 'surface_fingerprint', 'surface_key' );
		$candidate = $row['target']; $candidate['query'] = $candidate['raw_query']; $candidate['purpose'] = 'POST_CHANGE_VALIDATION';
		$context = MAD4B_SCP_Search_Context::compile( $p, $facts ); if ( is_wp_error( $context ) ) return $context;
		$compiled = MAD4B_SCP_Search_Targets::compile( $context, array( $candidate ), $facts['surfaces'] );
		if ( is_wp_error( $compiled ) || empty( $compiled['targets'] ) ) return MAD4B_SCP_Search_Contracts::error( 'change_target_ineligible' );
		$plan['observation_target'] = $compiled['targets'][0]; $plan['observation_target']['profile_id'] = $p['profile_id'];
		$plan['plan_sha256'] = MAD4B_SCP_Search_Contracts::digest( $plan ); return $plan;
	}
	public static function post_change_apply( array $input ) {
		if ( ! MAD4B_SCP_Search_Runtime::can_configure() ) return MAD4B_SCP_Search_Contracts::error( 'configuration_unauthorized' );
		$plan = self::post_change_plan( $input ); if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $input['plan_sha256'] ) || ! hash_equals( $plan['plan_sha256'], (string) $input['plan_sha256'] ) ) return MAD4B_SCP_Search_Contracts::error( 'change_plan_drift' );
		$id = $plan['plan_sha256'];
		$target = $plan['observation_target']; $target['experiment_id'] = $id;
		$stored = MAD4B_SCP_Search_Targets::persist( $target ); if ( is_wp_error( $stored ) ) return $stored;
		$existing = MAD4B_SCP_Search_Store::read( 'experiment', $id ); if ( is_array( $existing ) ) return $existing;
		$plan['experiment_id'] = $id; $plan['change_verified_at'] = time();
		return MAD4B_SCP_Search_Store::immutable( 'experiment', $id, $plan );
	}
	public static function outcome( array $input ) {
		$experiment = MAD4B_SCP_Search_Store::evidence( 'experiment', isset( $input['experiment_id'] ) ? $input['experiment_id'] : '' ); if ( is_wp_error( $experiment ) ) return $experiment;
		$before = array(); $after = array(); $rejected = array();
		$refs = isset( $input['snapshot_ids'] ) && is_array( $input['snapshot_ids'] ) ? $input['snapshot_ids'] : array();
		if ( count( $refs ) > 100 ) return MAD4B_SCP_Search_Contracts::error( 'experiment_bound' );
		foreach ( $refs as $id ) {
			$s = MAD4B_SCP_Search_Store::evidence( 'snapshot', $id );
			if ( is_wp_error( $s ) || ! in_array( $s['query_id'], array( $experiment['target_id'], $experiment['observation_target']['target_id'] ), true ) || 'complete' !== $s['completeness']['state'] ) { $rejected[] = $id; continue; }
			$delta = $s['captured_at'] - $experiment['change_verified_at'];
			if ( $delta < 0 && -$delta <= $experiment['pre_window_seconds'] ) $before[] = $s;
			elseif ( $delta >= 0 && $delta <= $experiment['post_window_seconds'] && isset( $s['change_binding']['experiment_id'] ) && $s['change_binding']['experiment_id'] === $experiment['experiment_id'] ) $after[] = $s;
			else $rejected[] = $id;
		}
		$result = MAD4B_SCP_Search_Evidence::experiment( $before, $after, $experiment );
		$result['rejected_refs'] = $rejected; $result['observation_state'] = $before && $after ? 'WINDOWS_OBSERVED' : 'BASELINING';
		return $result;
	}

	public static function retire( $snapshot_id, $now ) {
		$row = MAD4B_SCP_Search_Store::read( 'snapshot', $snapshot_id );
		if ( ! is_array( $row ) || empty( $row['payload']['valid_until'] ) || $row['payload']['valid_until'] > $now ) return MAD4B_SCP_Search_Contracts::error( 'retention_not_due' );
		$tombstone = array( 'contract' => 'mad4b.search-evidence-retirement.v1', 'snapshot_id' => $snapshot_id, 'original_digest' => $row['digest'], 'raw_response_sha256' => $row['payload']['raw_response_sha256'], 'rights' => $row['payload']['evidence_rights'], 'retired_at' => $now, 'reason' => 'provider_retention_expired', 'authorizing' => false );
		$event = MAD4B_SCP_Search_Store::immutable( 'retirement', $snapshot_id, $tombstone ); if ( is_wp_error( $event ) ) return $event;
		// Retention follows derivatives and durable checkpoint copies, not just the capture row.
		$graph = MAD4B_SCP_Search_Store::read( 'graph', $snapshot_id );
		if ( is_array( $graph ) && isset( $graph['payload'] ) ) {
			foreach ( $graph['payload']['nodes'] as $node ) if ( 'Signal' === $node['type'] ) {
				$id = substr( $node['node_id'], strlen( 'Signal:' ) ); $signal = MAD4B_SCP_Search_Store::read( 'signal', $id );
				if ( is_array( $signal ) && isset( $signal['payload'], $signal['digest'] ) ) { $r = MAD4B_SCP_Search_Store::cas( 'signal', $id, $signal, array( 'retired' => true, 'original_digest' => $signal['digest'], 'retirement_event_id' => $snapshot_id ), 'DERIVED_EVIDENCE_RETIRED' ); if ( is_wp_error( $r ) ) return $r; }
			}
			$r = MAD4B_SCP_Search_Store::cas( 'graph', $snapshot_id, $graph, array( 'retired' => true, 'original_digest' => $graph['digest'], 'retirement_event_id' => $snapshot_id ), 'GRAPH_RETIRED' ); if ( is_wp_error( $r ) ) return $r;
		}
		return MAD4B_SCP_Search_Store::cas( 'snapshot', $snapshot_id, $row, array( 'retired' => true, 'original_digest' => $row['digest'], 'retirement_event_id' => $snapshot_id ), 'EVIDENCE_RETIRED_BY_RIGHTS' );
	}
}
