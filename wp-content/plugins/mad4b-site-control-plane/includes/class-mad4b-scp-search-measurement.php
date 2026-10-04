<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical, provider-neutral search measurement semantics.
 *
 * This service is deliberately non-authorizing. It normalizes identities and
 * decides whether two observations are safe to compare; it performs no network
 * I/O and creates no provider or content mutation authority.
 */
final class MAD4B_SCP_Search_Measurement {
	const CONTRACT = 'mad4b.search-measurement.v1';
	const QUERY_NORMALIZATION_VERSION = 'mad4b.search-query-normalization.v1';
	const URL_NORMALIZATION_VERSION = 'mad4b.search-url-normalization.v1';
	const OBSERVATION_CONTEXT_VERSION = 'mad4b.search-observation-context.v1';
	const RANK_NORMALIZATION_VERSION = 'mad4b.search-rank-normalization.v1';
	const CAPTURE_COMPLETENESS_VERSION = 'mad4b.search-capture-completeness.v1';

	private static function stable( $value ) {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $is_list ) ksort( $value, SORT_STRING );
			foreach ( $value as $key => $item ) $value[ $key ] = self::stable( $item );
		}
		return $value;
	}

	private static function digest( $value ) {
		return hash( 'sha256', wp_json_encode( self::stable( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public static function normalize_query( $raw_query, $locale = 'und' ) {
		$raw = (string) $raw_query;
		if ( class_exists( 'Normalizer' ) ) {
			$normalized = Normalizer::normalize( $raw, Normalizer::FORM_C );
			if ( is_string( $normalized ) ) $raw = $normalized;
		}
		// Normalize Unicode and ASCII whitespace only. Preserve accents,
		// punctuation, operators and case in v1 to avoid false equivalence.
		$normalized = preg_replace( '/[\p{Z}\s]+/u', ' ', $raw );
		if ( ! is_string( $normalized ) ) $normalized = $raw;
		$normalized = trim( $normalized );
		if ( '' === $normalized ) return new WP_Error( 'mad4b_search_query_empty', 'Search query is empty after canonicalization.' );
		if ( strlen( $normalized ) > 2048 ) return new WP_Error( 'mad4b_search_query_too_long', 'Search query exceeds the canonical bound.' );
		$result = array(
			'contract' => self::QUERY_NORMALIZATION_VERSION,
			'raw_query' => (string) $raw_query,
			'normalized_query' => $normalized,
			'locale' => strtolower( trim( (string) $locale ) ?: 'und' ),
			'normalization_version' => self::QUERY_NORMALIZATION_VERSION,
			'normalization_reason' => 'unicode_nfc_and_whitespace_only_preserve_semantics',
		);
		$result['query_sha256'] = self::digest( $result );
		return $result;
	}

	private static function normalize_query_pairs( $query ) {
		if ( '' === (string) $query ) return '';
		$pairs = array();
		foreach ( explode( '&', (string) $query ) as $pair ) {
			if ( '' === $pair ) continue;
			$parts = explode( '=', $pair, 2 );
			$key = rawurldecode( $parts[0] );
			$value = isset( $parts[1] ) ? rawurldecode( $parts[1] ) : '';
			$pairs[] = array( $key, $value );
		}
		usort( $pairs, static function ( $a, $b ) {
			$left = $a[0] . "\0" . $a[1];
			$right = $b[0] . "\0" . $b[1];
			return strcmp( $left, $right );
		} );
		$out = array();
		foreach ( $pairs as $pair ) $out[] = rawurlencode( $pair[0] ) . ( '' !== $pair[1] ? '=' . rawurlencode( $pair[1] ) : '' );
		return implode( '&', $out );
	}

	public static function normalize_url_identity( $observed_url, $effective_canonical = '', $redirect_target = '', array $ownership = array() ) {
		$observed = trim( (string) $observed_url );
		if ( '' === $observed ) return new WP_Error( 'mad4b_search_url_empty', 'Observed URL is required.' );
		$parts = wp_parse_url( $observed );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return new WP_Error( 'mad4b_search_url_invalid', 'Observed URL is not absolute.' );
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) return new WP_Error( 'mad4b_search_url_scheme_invalid', 'Only HTTP(S) search URLs are supported.' );
		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0 );
			if ( is_string( $ascii ) && '' !== $ascii ) $host = strtolower( $ascii );
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) $port = 0;
		$path = isset( $parts['path'] ) && '' !== (string) $parts['path'] ? (string) $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? self::normalize_query_pairs( (string) $parts['query'] ) : '';
		$normalized = $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . $path . ( '' !== $query ? '?' . $query : '' );

		$canonical = '';
		if ( '' !== trim( (string) $effective_canonical ) ) {
			$canonical_parts = wp_parse_url( trim( (string) $effective_canonical ) );
			if ( is_array( $canonical_parts ) && ! empty( $canonical_parts['scheme'] ) && ! empty( $canonical_parts['host'] ) ) {
				$c = self::normalize_url_identity( (string) $effective_canonical );
				if ( ! is_wp_error( $c ) ) $canonical = (string) $c['normalized_url'];
			}
		}
		$redirect = '';
		if ( '' !== trim( (string) $redirect_target ) ) {
			$r = self::normalize_url_identity( (string) $redirect_target );
			if ( ! is_wp_error( $r ) ) $redirect = (string) $r['normalized_url'];
		}
		$identity_url = '' !== $canonical ? $canonical : ( '' !== $redirect ? $redirect : $normalized );
		$result = array(
			'contract' => self::URL_NORMALIZATION_VERSION,
			'observed_url' => $observed,
			'normalized_url' => $normalized,
			'effective_canonical_url' => $canonical,
			'redirect_target_url' => $redirect,
			'identity_url' => $identity_url,
			'host' => $host,
			'registrable_domain' => strtolower( trim( (string) ( $ownership['registrable_domain'] ?? '' ) ) ),
			'ownership_evidence_state' => '' !== trim( (string) ( $ownership['registrable_domain'] ?? '' ) ) ? 'supplied' : 'unresolved',
			'normalization_version' => self::URL_NORMALIZATION_VERSION,
		);
		$result['url_identity_sha256'] = self::digest( $result );
		return $result;
	}

	public static function owned_url_matches( array $left, array $right ) {
		$a = isset( $left['identity_url'] ) ? (string) $left['identity_url'] : '';
		$b = isset( $right['identity_url'] ) ? (string) $right['identity_url'] : '';
		return '' !== $a && '' !== $b && hash_equals( $a, $b );
	}

	public static function observation_context( array $input ) {
		$query = self::normalize_query( isset( $input['query'] ) ? $input['query'] : '', isset( $input['locale'] ) ? $input['locale'] : ( $input['language'] ?? 'und' ) );
		if ( is_wp_error( $query ) ) return $query;
		$required = array( 'market', 'language', 'engine', 'device' );
		foreach ( $required as $key ) if ( '' === trim( (string) ( $input[ $key ] ?? '' ) ) ) return new WP_Error( 'mad4b_search_observation_context_incomplete', 'Search observation context is incomplete.', array( 'field' => $key ) );
		$depth = max( 1, min( 1000, (int) ( $input['requested_depth'] ?? 10 ) ) );
		$provider = sanitize_key( (string) ( $input['provider_id'] ?? '' ) );
		$cross_class = sanitize_key( (string) ( $input['cross_provider_comparability_class'] ?? '' ) );
		$context = array(
			'contract' => self::OBSERVATION_CONTEXT_VERSION,
			'query_sha256' => $query['query_sha256'],
			'normalized_query' => $query['normalized_query'],
			'query_normalization_version' => $query['normalization_version'],
			'market' => strtoupper( trim( (string) $input['market'] ) ),
			'provider_location_id' => trim( (string) ( $input['provider_location_id'] ?? '' ) ),
			'location_precision' => sanitize_key( (string) ( $input['location_precision'] ?? 'country' ) ),
			'gl' => strtolower( trim( (string) ( $input['gl'] ?? '' ) ) ),
			'language' => strtolower( trim( (string) $input['language'] ) ),
			'hl' => strtolower( trim( (string) ( $input['hl'] ?? $input['language'] ) ) ),
			'engine' => sanitize_key( (string) $input['engine'] ),
			'engine_domain' => strtolower( trim( (string) ( $input['engine_domain'] ?? '' ) ) ),
			'device' => sanitize_key( (string) $input['device'] ),
			'requested_depth' => $depth,
			'requested_features' => array_values( array_unique( array_map( 'sanitize_key', is_array( $input['requested_features'] ?? null ) ? $input['requested_features'] : array() ) ) ),
			'provider_id' => $provider,
			'provider_semantic_profile' => sanitize_key( (string) ( $input['provider_semantic_profile'] ?? '' ) ),
			'cross_provider_comparability_class' => $cross_class,
			'normalization_version' => self::OBSERVATION_CONTEXT_VERSION,
		);
		sort( $context['requested_features'], SORT_STRING );
		$provider_scope = '' !== $cross_class ? 'class:' . $cross_class : 'provider:' . $provider;
		$material = $context;
		$material['provider_scope'] = $provider_scope;
		unset( $material['provider_id'], $material['cross_provider_comparability_class'] );
		$context['comparability_key'] = self::digest( $material );
		$context['context_sha256'] = self::digest( $context );
		return $context;
	}

	public static function compare_contexts( array $left, array $right ) {
		$comparable = ! empty( $left['comparability_key'] ) && ! empty( $right['comparability_key'] )
			&& hash_equals( (string) $left['comparability_key'], (string) $right['comparability_key'] );
		return array(
			'contract' => 'mad4b.search-observation-comparability.v1',
			'comparable' => $comparable,
			'left_context_sha256' => (string) ( $left['context_sha256'] ?? '' ),
			'right_context_sha256' => (string) ( $right['context_sha256'] ?? '' ),
			'reason_code' => $comparable ? 'comparable' : 'material_context_mismatch',
		);
	}

	public static function normalize_rank_result( array $result ) {
		$type = sanitize_key( (string) ( $result['result_type'] ?? 'organic' ) );
		$organic = isset( $result['organic_rank'] ) ? max( 0, (int) $result['organic_rank'] ) : 0;
		$group = isset( $result['group_rank'] ) ? max( 0, (int) $result['group_rank'] ) : 0;
		$absolute = isset( $result['absolute_position'] ) ? max( 0, (int) $result['absolute_position'] ) : 0;
		$native = isset( $result['provider_native_position'] ) ? max( 0, (int) $result['provider_native_position'] ) : 0;
		if ( 'organic' === $type && $organic < 1 ) return new WP_Error( 'mad4b_search_organic_rank_required', 'Organic results require organic_rank.' );
		return array(
			'contract' => self::RANK_NORMALIZATION_VERSION,
			'result_type' => $type,
			'organic_rank' => $organic,
			'group_rank' => $group,
			'absolute_position' => $absolute,
			'provider_native_position' => $native,
			'container_type' => sanitize_key( (string) ( $result['container_type'] ?? $type ) ),
			'comparison_metric' => 'organic' === $type ? 'organic_rank' : 'provider_feature_position',
			'normalization_version' => self::RANK_NORMALIZATION_VERSION,
		);
	}

	public static function capture_completeness( array $capture ) {
		$requested = max( 1, (int) ( $capture['requested_depth'] ?? 0 ) );
		$returned = max( 0, (int) ( $capture['returned_depth'] ?? 0 ) );
		$partial_reason = sanitize_key( (string) ( $capture['partial_reason'] ?? '' ) );
		$validated = ! empty( $capture['validated'] );
		$target_found = ! empty( $capture['target_found'] );
		$provider_complete = ! empty( $capture['provider_complete'] );
		$complete = $validated && '' === $partial_reason && ( $returned >= $requested || $provider_complete );
		$state = $complete ? 'complete' : ( '' !== $partial_reason ? 'partial' : 'incomplete' );
		return array(
			'contract' => self::CAPTURE_COMPLETENESS_VERSION,
			'requested_depth' => $requested,
			'returned_depth' => $returned,
			'validated' => $validated,
			'provider_complete' => $provider_complete,
			'state' => $state,
			'partial_reason' => $partial_reason,
			'target_found' => $target_found,
			'not_found_within_depth' => $complete && ! $target_found,
			'loss_inference_eligible' => $complete && ! $target_found,
		);
	}

	public static function ranking_delta( array $previous_context, array $current_context, array $previous_rank, array $current_rank ) {
		$comparison = self::compare_contexts( $previous_context, $current_context );
		if ( empty( $comparison['comparable'] ) ) return new WP_Error( 'mad4b_search_observations_not_comparable', 'Ranking delta requires materially comparable observations.', $comparison );
		$previous = isset( $previous_rank['organic_rank'] ) ? (int) $previous_rank['organic_rank'] : 0;
		$current = isset( $current_rank['organic_rank'] ) ? (int) $current_rank['organic_rank'] : 0;
		if ( $previous < 1 || $current < 1 ) return new WP_Error( 'mad4b_search_rank_delta_requires_found_results', 'Ranking delta requires positive comparable organic ranks.' );
		return array(
			'contract' => 'mad4b.search-ranking-delta.v1',
			'previous_rank' => $previous,
			'current_rank' => $current,
			'delta' => $previous - $current,
			'comparability_key' => (string) $previous_context['comparability_key'],
		);
	}
}
