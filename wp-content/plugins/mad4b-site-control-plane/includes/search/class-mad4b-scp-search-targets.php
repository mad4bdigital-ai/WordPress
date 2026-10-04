<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Compiling a target never executes a provider. */
final class MAD4B_SCP_Search_Targets {
	public static function query_language_provenance( array $candidate, array $target ) {
		$raw = isset( $candidate['query_language_provenance'] ) ? $candidate['query_language_provenance'] : array(
			'source' => isset( $candidate['source'] ) ? $candidate['source'] : 'explicit_query',
			'source_language' => $target['language'],
			'target_language' => $target['language'],
			'market' => $target['market'],
			'method' => 'explicit',
			'market_evidence_refs' => array(),
			'semantic_cluster_relation' => 'unbound',
			'confidence' => 1,
			'approval' => 'operator_supplied',
			'page_translation_implies_query_translation' => false,
		);
		if ( ! is_array( $raw ) || ! MAD4B_SCP_Search_Contracts::bounded( $raw ) ) return MAD4B_SCP_Search_Contracts::error( 'query_language_provenance_invalid' );
		$source = isset( $raw['source'] ) ? trim( (string) $raw['source'] ) : '';
		$source_language = isset( $raw['source_language'] ) ? strtolower( trim( (string) $raw['source_language'] ) ) : '';
		$target_language = isset( $raw['target_language'] ) ? strtolower( trim( (string) $raw['target_language'] ) ) : '';
		$market = isset( $raw['market'] ) ? trim( (string) $raw['market'] ) : '';
		$method = isset( $raw['method'] ) ? strtolower( trim( (string) $raw['method'] ) ) : '';
		$relation = isset( $raw['semantic_cluster_relation'] ) ? strtolower( trim( (string) $raw['semantic_cluster_relation'] ) ) : 'unbound';
		$approval = isset( $raw['approval'] ) ? strtolower( trim( (string) $raw['approval'] ) ) : '';
		$confidence = isset( $raw['confidence'] ) && is_numeric( $raw['confidence'] ) ? (float) $raw['confidence'] : -1;
		$methods = array( 'explicit', 'translation', 'transcreation', 'market_discovery', 'first_party', 'keyword_registry' );
		$relations = array( 'exact_intent', 'localized_variant', 'transcreated_variant', 'related_intent', 'unbound' );
		$approvals = array( 'operator_supplied', 'operator_approved', 'policy_approved', 'unreviewed' );
		if (
			'' === $source || strlen( $source ) > 191 ||
			$target_language !== strtolower( (string) $target['language'] ) ||
			$market !== (string) $target['market'] ||
			! in_array( $method, $methods, true ) ||
			! in_array( $relation, $relations, true ) ||
			! in_array( $approval, $approvals, true ) ||
			$confidence < 0 || $confidence > 1 || ! is_finite( $confidence ) ||
			! empty( $raw['page_translation_implies_query_translation'] )
		) return MAD4B_SCP_Search_Contracts::error( 'query_language_provenance_invalid' );
		if ( in_array( $method, array( 'translation', 'transcreation' ), true ) && '' === $source_language ) return MAD4B_SCP_Search_Contracts::error( 'query_language_source_required' );
		if ( 'translation' === $method && ! in_array( $relation, array( 'exact_intent', 'localized_variant' ), true ) ) return MAD4B_SCP_Search_Contracts::error( 'query_language_relation_invalid' );
		if ( 'transcreation' === $method && ! in_array( $relation, array( 'transcreated_variant', 'related_intent' ), true ) ) return MAD4B_SCP_Search_Contracts::error( 'query_language_relation_invalid' );
		$refs = isset( $raw['market_evidence_refs'] ) ? $raw['market_evidence_refs'] : array();
		if ( ! is_array( $refs ) || count( $refs ) > 32 ) return MAD4B_SCP_Search_Contracts::error( 'query_language_market_evidence_invalid' );
		$clean_refs = array();
		foreach ( $refs as $ref ) {
			if ( ! is_string( $ref ) || '' === trim( $ref ) || strlen( $ref ) > 200 ) return MAD4B_SCP_Search_Contracts::error( 'query_language_market_evidence_invalid' );
			$clean_refs[] = trim( $ref );
		}
		$clean_refs = array_values( array_unique( $clean_refs ) ); sort( $clean_refs, SORT_STRING );
		$out = array(
			'contract' => 'mad4b.query-language-provenance.v1',
			'source' => $source,
			'source_language' => $source_language,
			'target_language' => $target_language,
			'market' => $market,
			'method' => $method,
			'market_evidence_refs' => $clean_refs,
			'semantic_cluster_relation' => $relation,
			'semantic_cluster_id' => isset( $raw['semantic_cluster_id'] ) ? trim( (string) $raw['semantic_cluster_id'] ) : ( isset( $target['cluster_id'] ) ? (string) $target['cluster_id'] : '' ),
			'confidence' => round( $confidence, 6 ),
			'approval' => $approval,
			'page_translation_implies_query_translation' => false,
			'authorizing' => false,
		);
		if ( isset( $raw['source_query_id'] ) ) {
			if ( ! MAD4B_SCP_Search_Contracts::sha( $raw['source_query_id'] ) ) return MAD4B_SCP_Search_Contracts::error( 'query_language_source_query_invalid' );
			$out['source_query_id'] = strtolower( $raw['source_query_id'] );
		}
		if ( strlen( $out['semantic_cluster_id'] ) > 191 ) return MAD4B_SCP_Search_Contracts::error( 'query_language_cluster_invalid' );
		$out['provenance_sha256'] = MAD4B_SCP_Search_Contracts::digest( $out );
		return $out;
	}

	public static function compile( array $context, array $candidates, array $surfaces ) {
		$policy = MAD4B_SCP_Search_Context::policy(); if ( is_wp_error( $policy ) ) return $policy;
		if ( count( $candidates ) > $policy['max_targets_per_compile'] ) return MAD4B_SCP_Search_Contracts::error( 'target_compile_bound' );
		$lookup = array(); foreach ( $surfaces as $s ) if ( isset( $s['surface_key'] ) ) $lookup[ $s['surface_key'] ] = $s;
		$markets = array(); foreach ( $context['resolved_markets'] as $m ) $markets[ $m['id'] ] = $m;
		$out = array(); $excluded = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) return MAD4B_SCP_Search_Contracts::error( 'candidate_invalid' );
			$t = MAD4B_SCP_Search_Contracts::target( $candidate );
			if ( is_wp_error( $t ) ) { $excluded[] = $t->get_error_code(); continue; }
			if ( ! isset( $markets[ $t['market'] ], $context['resolved_languages'][ $t['language'] ] ) || ! in_array( $t['purpose'], $policy['purposes'], true ) || ! in_array( $t['engine'], $context['effective']['provider_policy']['engines'], true ) || ! in_array( $t['device'], $context['effective']['provider_policy']['devices'], true ) ) { $excluded[] = 'target_dimension_ineligible'; continue; }
			$owned = in_array( $t['purpose'], array( 'OWNED_RANK_TRACKING', 'POST_CHANGE_VALIDATION', 'EXPERIMENT_MEASUREMENT' ), true );
			$refs = isset( $t['surface_refs'] ) ? array_values( array_unique( $t['surface_refs'] ) ) : array();
			$eligible = array();
			foreach ( $refs as $ref ) if ( isset( $lookup[ $ref ] ) && 'eligible' === $lookup[ $ref ]['eligibility']['effective'] && $t['language'] === $lookup[ $ref ]['language'] && 'TERM' !== $lookup[ $ref ]['surface_type'] ) $eligible[] = $ref;
			if ( $owned && ( empty( $context['resolved_languages'][ $t['language'] ]['owned_allowed'] ) || ! $eligible ) ) { $excluded[] = 'owned_surface_required'; continue; }
			$t['surface_refs'] = $eligible; $t['site_uuid'] = MAD4B_SCP_Site_Profile::site_uuid();
			$t['brand_id'] = isset( $context['effective']['brand_id'] ) ? $context['effective']['brand_id'] : '';
			$t['context_fingerprint'] = $context['fingerprint']; $t['dependencies'] = $context['dependencies'];
			$t['semantic_cluster'] = isset( $t['cluster_id'] ) ? $t['cluster_id'] : '';
			$provenance = self::query_language_provenance( $candidate, $t ); if ( is_wp_error( $provenance ) ) { $excluded[] = $provenance->get_error_code(); continue; }
			$t['query_language_provenance'] = $provenance;
			if ( isset( $out[ $t['target_id'] ] ) ) $out[ $t['target_id'] ]['surface_refs'] = array_values( array_unique( array_merge( $out[ $t['target_id'] ]['surface_refs'], $eligible ) ) );
			else $out[ $t['target_id'] ] = $t;
		}
		ksort( $out, SORT_STRING );
		return array( 'contract' => 'mad4b.search-target-compilation.v1', 'targets' => array_values( $out ), 'excluded' => $excluded, 'provider_execution_performed' => false, 'authorizing' => false );
	}

	public static function candidates( array $context, array $surfaces, array $keyword_registry = array(), array $derived = array() ) {
		$out = $keyword_registry;
		foreach ( $surfaces as $surface ) {
			if ( empty( $surface['seo']['focus_keywords']['value'] ) || ! empty( $surface['seo']['focus_keywords']['conflicting'] ) || 'TERM' === $surface['surface_type'] ) continue;
			foreach ( (array) $surface['seo']['focus_keywords']['value'] as $query ) foreach ( $context['resolved_markets'] as $market ) foreach ( $context['effective']['provider_policy']['engines'] as $engine ) foreach ( $context['effective']['provider_policy']['devices'] as $device ) {
				$out[] = array( 'query' => $query, 'market' => $market['id'], 'language' => $surface['language'], 'engine' => $engine, 'device' => $device, 'purpose' => 'OWNED_RANK_TRACKING', 'source' => 'field_level_seo', 'surface_refs' => array( $surface['surface_key'] ), 'surface_type' => $surface['surface_type'] );
				if ( count( $out ) >= 5000 ) return $out;
			}
		}
		foreach ( $derived as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['evidence_refs'], $row['source'] ) ) continue;
			$out[] = $row; if ( count( $out ) >= 5000 ) break;
		}
		return $out;
	}

	public static function persist( array $target ) {
		$version = MAD4B_SCP_Search_Contracts::digest( $target );
		$saved = MAD4B_SCP_Search_Store::immutable( 'target-version', $version, $target ); if ( is_wp_error( $saved ) ) return $saved;
		$current = MAD4B_SCP_Search_Store::read( 'target', $target['target_id'] ); if ( is_wp_error( $current ) ) return $current;
		if ( is_array( $current ) && MAD4B_SCP_Search_Contracts::digest( $current['target'] ) === MAD4B_SCP_Search_Contracts::digest( $target ) ) return $current;
		$next = is_array( $current ) ? $current : array(); $next['target'] = $target;
		return MAD4B_SCP_Search_Store::cas( 'target', $target['target_id'], $current, $next, 'SEARCH_TARGET_COMPILED' );
	}
}
