<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Canonical, non-authorizing identities shared by stores, workers and adapters. */
final class MAD4B_SCP_Search_Contracts {
	const CONTRACT = 'mad4b.adaptive-search-intelligence.v1';
	const NORMALIZATION = 'serp-normalized.v1';
	const MAX_BYTES = 1048576;

	public static function error( $code, $message = 'Search Intelligence rejected invalid or stale input.' ) {
		return new WP_Error( 'mad4b_search_' . $code, $message, array( 'authorizing' => false ) );
	}

	public static function canonical( $value ) {
		if ( ! is_array( $value ) ) return $value;
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonical( $item );
		return $value;
	}

	public static function json( $value ) {
		return wp_json_encode( self::canonical( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public static function digest( $value ) {
		$json = self::json( $value );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}
	/** Receipt time remains visible; it must not make unchanged live facts drift every second. */
	public static function semantic( $value ) {
		if ( ! is_array( $value ) ) return $value;
		foreach ( $value as $key => $item ) {
			if ( in_array( $key, array( 'observed_at', 'captured_at' ), true ) ) unset( $value[ $key ] );
			else $value[ $key ] = self::semantic( $item );
		}
		return $value;
	}

	public static function bounded( $value, $depth = 0 ) {
		if ( $depth > 16 || is_object( $value ) || is_resource( $value ) ) return false;
		if ( is_float( $value ) && ! is_finite( $value ) ) return false;
		if ( is_string( $value ) && ( strlen( $value ) > 65536 || ! preg_match( '//u', $value ) ) ) return false;
		if ( is_array( $value ) ) {
			if ( count( $value ) > 5000 ) return false;
			foreach ( $value as $item ) if ( ! self::bounded( $item, $depth + 1 ) ) return false;
		}
		return $depth > 0 || ( is_string( self::json( $value ) ) && strlen( self::json( $value ) ) <= self::MAX_BYTES );
	}

	public static function id( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9][a-z0-9._:-]{0,95}$/D', $value );
	}

	public static function sha( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ); }

	public static function query( $raw ) {
		if ( ! is_string( $raw ) || strlen( $raw ) > 700 || ! preg_match( '//u', $raw ) || preg_match( '/[\x00-\x1f\x7f]/', $raw ) ) return self::error( 'query_invalid' );
		$normalized = trim( preg_replace( '/[\p{Z}\s]+/u', ' ', $raw ) );
		$version = 'query-unicode-exact.v1';
		if ( class_exists( 'Normalizer' ) ) {
			$normalized = Normalizer::normalize( $normalized, Normalizer::FORM_C );
			$version = 'query-nfc-space.v1';
		}
		if ( ! is_string( $normalized ) || '' === $normalized ) return self::error( 'query_empty' );
		$canonical = MAD4B_SCP_Search_Measurement::normalize_query( $raw );
		if ( is_wp_error( $canonical ) ) return $canonical;
		$normalized = $canonical['normalized_query'];
		$version = $canonical['normalization_version'] . ( class_exists( 'Normalizer' ) ? ':nfc' : ':exact' );
		return array( 'raw_query' => $raw, 'normalized_query' => $normalized, 'normalization_version' => $version, 'reason' => 'Whitespace and canonical composition only; case, accents, punctuation and operators are preserved.' );
	}

	public static function url( $raw, array $ignored_parameters = array() ) {
		if ( ! is_string( $raw ) || strlen( $raw ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $raw ) ) return self::error( 'url_invalid' );
		$p = wp_parse_url( $raw );
		if ( ! is_array( $p ) || empty( $p['host'] ) || ! in_array( isset( $p['scheme'] ) ? strtolower( $p['scheme'] ) : '', array( 'http', 'https' ), true ) || isset( $p['user'] ) || isset( $p['pass'] ) ) return self::error( 'url_invalid' );
		$host = strtolower( rtrim( $p['host'], '.' ) );
		$canonical = MAD4B_SCP_Search_Measurement::normalize_url_identity( $raw ); if ( is_wp_error( $canonical ) ) return $canonical;
		$host = $canonical['host'];
		$scheme = strtolower( $p['scheme'] );
		$port = isset( $p['port'] ) && ! ( ( 'https' === $scheme && 443 === $p['port'] ) || ( 'http' === $scheme && 80 === $p['port'] ) ) ? ':' . $p['port'] : '';
		$parts = array();
		foreach ( isset( $p['query'] ) ? explode( '&', $p['query'] ) : array() as $pair ) {
			$key = rawurldecode( explode( '=', $pair, 2 )[0] );
			if ( ! in_array( $key, $ignored_parameters, true ) ) $parts[] = $pair;
		}
		$normalized = $scheme . '://' . $host . $port . ( isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/' ) . ( $parts ? '?' . implode( '&', $parts ) : '' );
		return array( 'observed_url' => $raw, 'normalized_url' => $normalized, 'host' => $host, 'normalization_version' => 'url-conservative.v1', 'url_id' => self::digest( $normalized ) );
	}

	public static function owned_match( $url, array $surface ) {
		$observed = self::url( $url );
		$public = self::url( isset( $surface['public_url'] ) ? $surface['public_url'] : '' );
		if ( is_wp_error( $observed ) || is_wp_error( $public ) || $observed['host'] !== $public['host'] ) return false;
		// Alias equivalence comes only from a live surface provider, never suffix/substring matching.
		$aliases = array_merge( array( isset( $surface['public_url'] ) ? $surface['public_url'] : '', isset( $surface['canonical_url'] ) ? $surface['canonical_url'] : '' ), isset( $surface['verified_aliases'] ) ? $surface['verified_aliases'] : array() );
		foreach ( $aliases as $alias ) {
			$identity = self::url( $alias );
			if ( ! is_wp_error( $identity ) && $identity['host'] === $public['host'] && hash_equals( $observed['url_id'], $identity['url_id'] ) ) return true;
		}
		return false;
	}

	public static function target( array $candidate ) {
		$q = self::query( isset( $candidate['query'] ) ? $candidate['query'] : '' );
		if ( is_wp_error( $q ) ) return $q;
		$identity = array( 'query' => $q['normalized_query'], 'query_normalization' => $q['normalization_version'] );
		foreach ( array( 'market', 'language', 'engine', 'device', 'purpose' ) as $key ) {
			if ( ! isset( $candidate[ $key ] ) || ! is_string( $candidate[ $key ] ) || '' === $candidate[ $key ] || strlen( $candidate[ $key ] ) > 96 ) return self::error( 'target_dimension_missing' );
			$identity[ $key ] = $candidate[ $key ];
		}
		return array_merge( $candidate, $q, array( 'contract' => 'mad4b.search-target.v1', 'target_id' => self::digest( $identity ), 'identity' => $identity, 'authorizing' => false ) );
	}

	public static function observation_context( array $request, array $descriptor, array $resolved ) {
		$context = array( 'contract' => 'mad4b.search-observation-context.v1' );
		foreach ( array( 'market', 'language', 'engine', 'device', 'depth', 'engine_domain', 'safe_search', 'search_mode', 'requested_features' ) as $key ) $context[ $key ] = isset( $request[ $key ] ) ? $request[ $key ] : null;
		foreach ( array( 'location_id', 'country', 'language_code', 'precision' ) as $key ) {
			if ( ! isset( $resolved[ $key ] ) || '' === (string) $resolved[ $key ] ) return self::error( 'geo_fidelity_missing' );
			$context[ 'resolved_' . $key ] = $resolved[ $key ];
		}
		$context['normalization_version'] = self::NORMALIZATION;
		$context['metric'] = 'organic_rank';
		$equivalence = isset( $descriptor['metric_equivalence_id'] ) && '' !== $descriptor['metric_equivalence_id'] ? $descriptor['metric_equivalence_id'] : '';
		$shared = MAD4B_SCP_Search_Measurement::observation_context( array_merge( $request, array( 'query' => isset( $request['query'] ) ? $request['query'] : $request['query_id'], 'requested_depth' => $request['depth'], 'provider_id' => $descriptor['provider_id'], 'provider_semantic_profile' => $descriptor['certification_generation'], 'provider_location_id' => $resolved['location_id'], 'location_precision' => $resolved['precision'], 'gl' => $resolved['country'], 'hl' => $resolved['language_code'], 'cross_provider_comparability_class' => $equivalence, 'cross_provider_comparability_certified' => ! empty( $descriptor['metric_equivalence_certified'] ), 'cross_provider_comparability_evidence_sha256' => isset( $descriptor['metric_equivalence_evidence_sha256'] ) ? $descriptor['metric_equivalence_evidence_sha256'] : '' ) ) );
		if ( is_wp_error( $shared ) ) return $shared;
		$context['measurement_comparability_key'] = $shared['comparability_key'];
		$context['provider_comparability_class'] = $equivalence ?: $descriptor['provider_id'] . ':' . $descriptor['certification_generation'];
		$context['comparability_key'] = self::digest( $context );
		return $context;
	}

	public static function fact( $type, $value, $source, $generation, $observed_at, $expires_at, $confidence = 1.0 ) {
		$fact = array( 'contract' => 'mad4b.search-runtime-fact.v1', 'fact_type' => $type, 'value' => $value, 'source' => $source, 'source_generation' => $generation, 'observed_at' => $observed_at, 'expires_at' => $expires_at, 'confidence' => max( 0, min( 1, $confidence ) ), 'authorizing' => false );
		$fact['fingerprint'] = self::digest( $fact );
		$fact['fact_id'] = $fact['fingerprint'];
		return $fact;
	}
}
