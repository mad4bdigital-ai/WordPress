<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Compiling a target never executes a provider. */
final class MAD4B_SCP_Search_Targets {
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
			$t['query_language_provenance'] = isset( $candidate['query_language_provenance'] ) ? $candidate['query_language_provenance'] : array( 'source' => isset( $candidate['source'] ) ? $candidate['source'] : 'explicit_query', 'target_language' => $t['language'], 'method' => 'explicit', 'confidence' => 1, 'approval' => 'operator_supplied', 'page_translation_implies_query_translation' => false );
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
