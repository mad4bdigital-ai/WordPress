<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Immutable captures and disposable, confidence-aware projections. */
final class MAD4B_SCP_Search_Evidence {
	public static function feature( array $feature ) {
		if ( ! MAD4B_SCP_Search_Contracts::bounded( $feature ) ) return MAD4B_SCP_Search_Contracts::error( 'serp_feature_invalid' );
		$native = isset( $feature['provider_native_type'] ) ? trim( (string) $feature['provider_native_type'] ) : '';
		if ( '' === $native || strlen( $native ) > 191 || preg_match( '/[\\x00-\\x1F\\x7F]/', $native ) ) return MAD4B_SCP_Search_Contracts::error( 'serp_feature_invalid' );
		$family = isset( $feature['family'] ) ? strtolower( trim( (string) $feature['family'] ) ) : 'unknown';
		$known = array(
			'answer_box',
			'ai_overview',
			'featured_snippet',
			'images',
			'knowledge_graph',
			'local_pack',
			'news',
			'organic',
			'related_questions',
			'shopping',
			'videos',
		);
		if ( ! in_array( $family, $known, true ) ) $family = 'unknown';
		$out = array(
			'contract' => 'mad4b.serp-feature.v1',
			'schema_version' => '1.0',
			'family' => $family,
			'provider_native_type' => $native,
			'data' => self::untrusted( isset( $feature['data'] ) ? $feature['data'] : array() ),
			'trust_class' => 'external_evidence_not_instructions',
			'normalization_version' => MAD4B_SCP_Search_Contracts::NORMALIZATION,
			'authorizing' => false,
		);
		$out['feature_sha256'] = MAD4B_SCP_Search_Contracts::digest( $out );
		return $out;
	}

	public static function untrusted( $value, $depth = 0 ) {
		if ( $depth > 12 ) return null;
		if ( is_string( $value ) ) { $value = substr( wp_strip_all_tags( $value ), 0, 8192 ); while ( ! preg_match( '//u', $value ) && strlen( $value ) ) $value = substr( $value, 0, -1 ); return $value; }
		if ( is_array( $value ) ) {
			$out = array(); $n = 0;
			foreach ( $value as $key => $item ) {
				if ( ++$n > 100 ) break;
				if ( is_string( $key ) && preg_match( '/api.?key|secret|authorization|token|password|credential|cookie/i', $key ) ) continue;
				$out[ $key ] = self::untrusted( $item, $depth + 1 );
			}
			return $out;
		}
		return is_scalar( $value ) || null === $value ? $value : null;
	}

	public static function snapshot( array $request, array $descriptor, array $resolved, array $normalized, $raw_sha, $runtime_build, $captured_at ) {
		if ( ! isset( $request['depth'], $request['query_id'] ) || ! is_int( $request['depth'] ) || $request['depth'] < 1 || $request['depth'] > 100 || ! isset( $normalized['cost']['units'], $normalized['cost']['cost_micro'] ) || ! is_int( $normalized['cost']['units'] ) || $normalized['cost']['units'] < 0 || ! is_int( $normalized['cost']['cost_micro'] ) || $normalized['cost']['cost_micro'] < 0 ) return MAD4B_SCP_Search_Contracts::error( 'capture_usage_invalid' );
		if ( ! MAD4B_SCP_Search_Contracts::sha( $raw_sha ) || ! MAD4B_SCP_Search_Contracts::sha( $runtime_build ) || ! MAD4B_SCP_Search_Contracts::bounded( $normalized ) || ! isset( $normalized['organic_results'], $normalized['features'], $normalized['completeness'], $normalized['cost'], $normalized['provider_request_id'] ) || count( $normalized['organic_results'] ) > 100 ) return MAD4B_SCP_Search_Contracts::error( 'snapshot_invalid' );
		$context = MAD4B_SCP_Search_Contracts::observation_context( $request, $descriptor, $resolved ); if ( is_wp_error( $context ) ) return $context;
		$rights = $descriptor['evidence_rights'];
		$region = apply_filters( 'mad4b_scp_search_storage_region', '', $descriptor['provider_id'] );
		if ( true !== $rights['normalized_allowed'] || ( $rights['storage_regions'] && ! in_array( $region, $rights['storage_regions'], true ) ) ) return MAD4B_SCP_Search_Contracts::error( 'evidence_rights_denied' );
		$retention = MAD4B_SCP_Search_Evidence_Policy::resolve_retention( array( 'provider_id' => $descriptor['provider_id'], 'policy_version' => $descriptor['certification_generation'], 'raw_retention_permitted' => $rights['raw_allowed'], 'normalized_retention_permitted' => $rights['normalized_allowed'], 'max_normalized_retention_seconds' => $rights['max_retention_seconds'], 'allowed_storage_regions' => $rights['storage_regions'], 'deletion_required' => true ), array( 'storage_region' => $region, 'raw_retention_seconds' => 0, 'normalized_retention_seconds' => $rights['max_retention_seconds'] ) );
		if ( is_wp_error( $retention ) || ! $retention['normalized_storage_allowed'] ) return MAD4B_SCP_Search_Contracts::error( 'evidence_rights_denied' );
		$organic = array(); $ranks = array();
		foreach ( $normalized['organic_results'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['organic_rank'], $row['url'] ) || ! is_int( $row['organic_rank'] ) || $row['organic_rank'] < 1 || $row['organic_rank'] > 1000 || isset( $ranks[ $row['organic_rank'] ] ) ) return MAD4B_SCP_Search_Contracts::error( 'rank_semantics_invalid' );
			$url = MAD4B_SCP_Search_Contracts::url( $row['url'] ); if ( is_wp_error( $url ) ) return MAD4B_SCP_Search_Contracts::error( 'result_url_invalid' );
			$ranks[ $row['organic_rank'] ] = true;
			$organic[] = array( 'result_type' => 'organic', 'organic_rank' => $row['organic_rank'], 'group_rank' => isset( $row['group_rank'] ) ? $row['group_rank'] : null, 'absolute_position' => isset( $row['absolute_position'] ) ? $row['absolute_position'] : null, 'provider_native_position' => isset( $row['provider_native_position'] ) ? $row['provider_native_position'] : null, 'url' => $url['normalized_url'], 'url_identity' => $url, 'title' => self::untrusted( isset( $row['title'] ) ? $row['title'] : '' ), 'snippet' => self::untrusted( isset( $row['snippet'] ) ? $row['snippet'] : '' ), 'trust_class' => 'external_evidence_not_instructions' );
		}
		usort( $organic, static function ( $a, $b ) { return $a['organic_rank'] <=> $b['organic_rank']; } );
		$completeness = $normalized['completeness'];
		if ( ! isset( $completeness['state'], $completeness['returned_depth'] ) || ! in_array( $completeness['state'], array( 'complete', 'partial', 'truncated' ), true ) || ! is_int( $completeness['returned_depth'] ) || $completeness['returned_depth'] < 0 || $completeness['returned_depth'] > 100 ) return MAD4B_SCP_Search_Contracts::error( 'completeness_invalid' );
		if ( 'complete' === $completeness['state'] && ( $completeness['returned_depth'] < $request['depth'] || count( $organic ) < $request['depth'] || array_diff( range( 1, $request['depth'] ), array_keys( $ranks ) ) ) ) return MAD4B_SCP_Search_Contracts::error( 'complete_capture_unproven' );
		$completeness['requested_depth'] = $request['depth']; $completeness['validation_state'] = 'validated';
		if ( ! is_array( $normalized['features'] ) || count( $normalized['features'] ) > 100 ) return MAD4B_SCP_Search_Contracts::error( 'serp_feature_invalid' );
		$features = array();
		foreach ( $normalized['features'] as $feature ) {
			if ( ! is_array( $feature ) ) return MAD4B_SCP_Search_Contracts::error( 'serp_feature_invalid' );
			$typed = self::feature( $feature ); if ( is_wp_error( $typed ) ) return $typed;
			$features[] = $typed;
		}
		$snapshot = array( 'contract' => 'mad4b.serp-snapshot.v1', 'query_id' => $request['query_id'], 'provider' => $descriptor['provider_id'], 'provider_request_id' => sanitize_text_field( (string) $normalized['provider_request_id'] ), 'market' => $request['market'], 'language' => $request['language'], 'device' => $request['device'], 'engine' => $request['engine'], 'captured_at' => $captured_at, 'valid_from' => $captured_at, 'valid_until' => $captured_at + $rights['max_retention_seconds'], 'organic_results' => $organic, 'features' => $features, 'completeness' => $completeness, 'observation_context' => $context, 'request_identity' => MAD4B_SCP_Search_Contracts::digest( $request ), 'raw_response_sha256' => $raw_sha, 'raw_retained' => false, 'normalization_version' => MAD4B_SCP_Search_Contracts::NORMALIZATION, 'normalized_sha256' => MAD4B_SCP_Search_Contracts::digest( array( $organic, $features, $completeness ) ), 'cost' => $normalized['cost'], 'evidence_rights' => $rights, 'provenance' => array( 'source_class' => 'live_serp', 'provider_generation' => $descriptor['certification_generation'], 'runtime_build' => $runtime_build, 'request_fingerprint' => MAD4B_SCP_Search_Contracts::digest( $request ) ), 'authorizing' => false );
		$snapshot['retention_receipt'] = $retention;
		if ( isset( $request['change_binding'] ) ) $snapshot['change_binding'] = $request['change_binding'];
		$snapshot['snapshot_id'] = MAD4B_SCP_Search_Contracts::digest( $snapshot ); return $snapshot;
	}

	public static function commit( array $snapshot ) {
		if ( empty( $snapshot['valid_until'] ) || $snapshot['valid_until'] <= time() ) return MAD4B_SCP_Search_Contracts::error( 'evidence_retention_expired' );
		return MAD4B_SCP_Search_Store::immutable( 'snapshot', $snapshot['snapshot_id'], $snapshot );
	}

	public static function import( array $input ) {
		if ( empty( $input['source_class'] ) || ! in_array( $input['source_class'], array( 'imported', 'manual', 'historical', 'external_intelligence', 'first_party_search_performance' ), true ) || isset( $input['live_provider_receipt'] ) || ! MAD4B_SCP_Search_Contracts::bounded( $input ) ) return MAD4B_SCP_Search_Contracts::error( 'evidence_source_invalid' );
		$source = array( 'contract' => 'mad4b.search-evidence-source.v1', 'source_class' => $input['source_class'], 'evidence' => self::untrusted( $input ), 'live_provider_receipt' => false, 'authorizing' => false );
		return MAD4B_SCP_Search_Store::immutable( 'source', MAD4B_SCP_Search_Contracts::digest( $source ), $source );
	}

	public static function rank( array $snapshot, array $surface ) {
		foreach ( $snapshot['organic_results'] as $row ) if ( MAD4B_SCP_Search_Contracts::owned_match( $row['url'], $surface ) ) return array( 'state' => 'FOUND', 'rank' => $row['organic_rank'], 'url' => $row['url'] );
		return array( 'state' => 'complete' === $snapshot['completeness']['state'] ? 'NOT_FOUND_WITHIN_DEPTH' : 'UNKNOWN_INCOMPLETE_CAPTURE', 'rank' => null, 'requested_depth' => $snapshot['completeness']['requested_depth'] );
	}

	public static function trajectory( array $snapshots, array $surface ) {
		usort( $snapshots, static function ( $a, $b ) { return $a['captured_at'] <=> $b['captured_at']; } );
		$series = array();
		foreach ( $snapshots as $s ) $series[ $s['observation_context']['comparability_key'] ][] = array( 'snapshot_id' => $s['snapshot_id'], 'at' => $s['captured_at'], 'rank' => self::rank( $s, $surface ), 'complete' => 'complete' === $s['completeness']['state'] );
		$result = array();
		foreach ( $series as $key => $points ) {
			$ranks = array(); foreach ( $points as $p ) if ( 'FOUND' === $p['rank']['state'] && $p['complete'] ) $ranks[] = $p['rank']['rank'];
			$mean = $ranks ? array_sum( $ranks ) / count( $ranks ) : 0; $variance = 0;
			foreach ( $ranks as $r ) $variance += pow( $r - $mean, 2 );
			$volatility = $ranks ? sqrt( $variance / count( $ranks ) ) : 0;
			$last = end( $points ); $first = reset( $points );
			$velocity = count( $ranks ) > 1 && $last['at'] > $first['at'] ? ( end( $ranks ) - reset( $ranks ) ) * 86400 / ( $last['at'] - $first['at'] ) : null;
			$result[ $key ] = array( 'points' => $points, 'velocity_per_day' => $velocity, 'volatility' => $volatility, 'stability' => 1 / ( 1 + $volatility ), 'confidence' => min( 1, count( $ranks ) / 3 ), 'comparable_only' => true );
		}
		return $result;
	}

	private static function signal( $family, array $snapshot, array $evidence, $confidence, array $details ) {
		$r = array( 'contract' => 'mad4b.search-signal.v1', 'family' => $family, 'query_id' => $snapshot['query_id'], 'context' => $snapshot['observation_context'], 'confidence' => $confidence, 'evidence_count' => count( $evidence ), 'evidence_refs' => $evidence, 'evidence_captured_at' => $snapshot['captured_at'], 'valid_until' => $snapshot['valid_until'], 'source_diversity' => 1, 'contradictions' => array(), 'derivation_version' => 'signals-conservative.v1', 'details' => $details, 'authorizing' => false, 'direct_content_mutation' => false );
		$r['signal_id'] = MAD4B_SCP_Search_Contracts::digest( $r ); return $r;
	}

	public static function signals( array $current, array $prior, array $surfaces ) {
		$signals = array(); $matches = array(); $refs = array( $current['snapshot_id'] );
		$comparable = isset( $prior['observation_context']['comparability_key'] ) && $prior['query_id'] === $current['query_id'] && hash_equals( $prior['observation_context']['comparability_key'], $current['observation_context']['comparability_key'] );
		if ( $comparable ) $refs[] = $prior['snapshot_id'];
		foreach ( $surfaces as $surface ) {
			$rank = self::rank( $current, $surface );
			if ( 'FOUND' === $rank['state'] && 'TERM' !== $surface['surface_type'] ) $matches[] = array( 'surface_key' => $surface['surface_key'], 'surface_type' => $surface['surface_type'], 'url' => $rank['url'], 'rank' => $rank['rank'] );
			if ( $comparable && 'complete' === $current['completeness']['state'] && 'complete' === $prior['completeness']['state'] ) {
				$before = self::rank( $prior, $surface );
				if ( 'FOUND' === $before['state'] && 'FOUND' === $rank['state'] && $before['rank'] !== $rank['rank'] ) $signals[] = self::signal( $rank['rank'] > $before['rank'] ? 'ranking_loss' : 'ranking_gain', $current, $refs, 0.8, array( 'before' => $before, 'after' => $rank, 'surface_key' => $surface['surface_key'] ) );
				if ( 'FOUND' === $before['state'] && 'NOT_FOUND_WITHIN_DEPTH' === $rank['state'] && $before['rank'] <= $current['completeness']['requested_depth'] ) $signals[] = self::signal( 'ranking_loss_within_observed_depth', $current, $refs, 0.7, array( 'before' => $before, 'after' => $rank ) );
			}
		}
		$unique_urls = array(); foreach ( $matches as $m ) $unique_urls[ $m['url'] ] = true;
		if ( count( $unique_urls ) > 1 ) $signals[] = self::signal( 'cannibalization_candidate', $current, $refs, 0.6, array( 'candidate_urls' => $matches, 'requires_editorial_review' => true ) );
		if ( ! $matches && 'complete' === $current['completeness']['state'] ) $signals[] = self::signal( 'content_gap_candidate', $current, $refs, 0.5, array( 'absence_scope' => 'within_observed_depth' ) );
		if ( $current['features'] ) $signals[] = self::signal( 'serp_feature_opportunity', $current, $refs, 0.5, array( 'features' => $current['features'] ) );
		$prior_hosts = array(); foreach ( isset( $prior['organic_results'] ) ? $prior['organic_results'] : array() as $r ) $prior_hosts[ $r['url_identity']['host'] ] = true;
		foreach ( $current['organic_results'] as $r ) {
			$owned = false; foreach ( $surfaces as $s ) if ( MAD4B_SCP_Search_Contracts::owned_match( $r['url'], $s ) ) $owned = true;
			if ( $owned ) continue;
			$host = $r['url_identity']['host'];
			$signals[] = self::signal( isset( $prior_hosts[ $host ] ) && $comparable ? 'competitor_persistence' : 'new_competitor_candidate', $current, $refs, $comparable ? 0.7 : 0.4, array( 'host' => $host, 'url' => $r['url'] ) );
		}
		foreach ( $signals as &$signal ) {
			if ( $comparable ) $signal['valid_until'] = min( $signal['valid_until'], $prior['valid_until'] );
			unset( $signal['signal_id'] ); $signal['signal_id'] = MAD4B_SCP_Search_Contracts::digest( $signal );
		}
		unset( $signal ); return $signals;
	}

	public static function experiment( array $before, array $after, array $change ) {
		return array( 'contract' => 'mad4b.search-experiment-outcome.v1', 'change_receipt' => $change, 'before_refs' => array_column( $before, 'snapshot_id' ), 'after_refs' => array_column( $after, 'snapshot_id' ), 'confounders' => array( 'SERP volatility', 'competitor changes', 'seasonality', 'provider or context changes' ), 'causality_claimed' => false, 'interpretation' => 'correlation_only', 'authorizing' => false );
	}
}
