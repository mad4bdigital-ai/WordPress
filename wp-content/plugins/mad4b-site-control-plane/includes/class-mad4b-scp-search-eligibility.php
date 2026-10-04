<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Search eligibility and bounded surface admission.
 * Non-authorizing: it can only constrain candidate surfaces.
 */
final class MAD4B_SCP_Search_Eligibility {
	const CONTRACT = 'mad4b.search-eligibility.v1';
	const SURFACE_ADMISSION_CONTRACT = 'mad4b.search-surface-admission.v1';

	private static function token_list( $value ) {
		if ( is_array( $value ) ) $value = implode( ',', $value );
		$tokens = preg_split( '/[\s,;]+/', strtolower( trim( (string) $value ) ) );
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', is_array( $tokens ) ? $tokens : array() ) ) ) );
	}

	public static function resolve( array $evidence ) {
		$status = (int) ( $evidence['http_status'] ?? 0 );
		$robots_allowed = array_key_exists( 'robots_txt_allowed', $evidence ) ? (bool) $evidence['robots_txt_allowed'] : null;
		$x_robots = self::token_list( $evidence['x_robots_tag'] ?? '' );
		$meta_robots = self::token_list( $evidence['meta_robots'] ?? '' );
		$noindex = in_array( 'noindex', $x_robots, true ) || in_array( 'noindex', $meta_robots, true ) || in_array( 'none', $x_robots, true ) || in_array( 'none', $meta_robots, true );
		$nofollow = in_array( 'nofollow', $x_robots, true ) || in_array( 'nofollow', $meta_robots, true ) || in_array( 'none', $x_robots, true ) || in_array( 'none', $meta_robots, true );
		$canonical_state = sanitize_key( (string) ( $evidence['canonical_state'] ?? 'unknown' ) );
		$redirect_state = sanitize_key( (string) ( $evidence['redirect_state'] ?? 'none' ) );
		$language_live = array_key_exists( 'language_live', $evidence ) ? (bool) $evidence['language_live'] : true;
		$hreflang = sanitize_key( (string) ( $evidence['hreflang_state'] ?? 'unknown' ) );
		$sitemap = sanitize_key( (string) ( $evidence['sitemap_state'] ?? 'unknown' ) );
		$public = array_key_exists( 'object_public', $evidence ) ? (bool) $evidence['object_public'] : true;

		$crawlable = $public && $status >= 200 && $status < 400 && true === $robots_allowed;
		$indexable = $crawlable && $status < 300 && ! $noindex && ! in_array( $redirect_state, array( 'redirected', 'loop', 'error' ), true );
		$canonical_eligible = in_array( $canonical_state, array( 'self', 'equivalent' ), true );
		$owned_tracking = $indexable && $canonical_eligible && $language_live;
		$reasons = array();
		if ( ! $public ) $reasons[] = 'object_not_public';
		if ( ! $crawlable ) $reasons[] = 'not_crawlable';
		if ( $noindex ) $reasons[] = 'robots_noindex';
		if ( ! $canonical_eligible ) $reasons[] = 'canonicalized_elsewhere_or_conflicting';
		if ( ! $language_live ) $reasons[] = 'language_not_live';
		if ( 'invalid' === $hreflang || 'conflicting' === $hreflang ) $reasons[] = 'hreflang_invalid_or_conflicting';

		$known = 0;
		$total = 7;
		foreach ( array( $status > 0, null !== $robots_allowed, '' !== $canonical_state, '' !== $redirect_state, '' !== $hreflang, '' !== $sitemap, array_key_exists( 'meta_robots', $evidence ) || array_key_exists( 'x_robots_tag', $evidence ) ) as $item ) if ( $item ) $known++;
		return array(
			'contract' => self::CONTRACT,
			'crawlable' => $crawlable,
			'robots_txt_allowed' => $robots_allowed,
			'x_robots_tokens' => $x_robots,
			'meta_robots_tokens' => $meta_robots,
			'nofollow' => $nofollow,
			'indexable' => $indexable,
			'canonical_state' => $canonical_state,
			'redirect_state' => $redirect_state,
			'sitemap_state' => $sitemap,
			'hreflang_state' => $hreflang,
			'language_live' => $language_live,
			'owned_tracking_eligible' => $owned_tracking,
			'eligibility_state' => $owned_tracking ? 'eligible' : ( null === $robots_allowed || 'unknown' === $canonical_state ? 'unknown' : 'ineligible' ),
			'discovery_eligible' => true,
			'confidence' => round( $known / $total, 4 ),
			'reason_codes' => array_values( array_unique( $reasons ) ),
		);
	}

	public static function admit_surface( array $surface, array $policy ) {
		$type = sanitize_key( (string) ( $surface['surface_type'] ?? '' ) );
		$url = trim( (string) ( $surface['url'] ?? '' ) );
		if ( '' === $type || '' === $url ) return new WP_Error( 'mad4b_search_surface_identity_required', 'Surface type and URL are required.' );
		$allowed_types = array_values( array_unique( array_map( 'sanitize_key', is_array( $policy['allowed_surface_types'] ?? null ) ? $policy['allowed_surface_types'] : array() ) ) );
		if ( ! in_array( $type, $allowed_types, true ) ) return new WP_Error( 'mad4b_search_surface_type_denied', 'Surface type is not admitted by policy.', array( 'surface_type' => $type ) );

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return new WP_Error( 'mad4b_search_surface_url_invalid', 'Search surface URL must be absolute.' );
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$prefixes = is_array( $policy['allowed_path_prefixes'] ?? null ) ? $policy['allowed_path_prefixes'] : array( '/' );
		$path_allowed = false;
		foreach ( $prefixes as $prefix ) {
			$prefix = (string) $prefix;
			if ( '' !== $prefix && 0 === strpos( $path, $prefix ) ) { $path_allowed = true; break; }
		}
		if ( ! $path_allowed ) return new WP_Error( 'mad4b_search_surface_path_denied', 'Surface path is outside the admitted route prefixes.' );

		$query = array();
		if ( ! empty( $parts['query'] ) ) parse_str( (string) $parts['query'], $query );
		$allowed_params = array_values( array_unique( array_map( 'sanitize_key', is_array( $policy['allowed_query_params'] ?? null ) ? $policy['allowed_query_params'] : array() ) ) );
		$unknown = array();
		foreach ( array_keys( $query ) as $key ) if ( ! in_array( sanitize_key( (string) $key ), $allowed_params, true ) ) $unknown[] = (string) $key;
		if ( ! empty( $unknown ) ) return new WP_Error( 'mad4b_search_surface_query_param_denied', 'Surface contains query parameters that are not explicitly admitted.', array( 'unknown_params' => $unknown ) );

		$cardinality = max( 1, (int) ( $surface['estimated_cardinality'] ?? 1 ) );
		$max_cardinality = max( 1, (int) ( $policy['max_cardinality'] ?? 1000 ) );
		if ( $cardinality > $max_cardinality ) return new WP_Error( 'mad4b_search_surface_cardinality_exceeded', 'Estimated surface cardinality exceeds policy bound.', array( 'estimated_cardinality' => $cardinality, 'max_cardinality' => $max_cardinality ) );

		$page = max( 1, (int) ( $surface['page_number'] ?? 1 ) );
		$max_page = max( 1, (int) ( $policy['max_page_number'] ?? 50 ) );
		if ( $page > $max_page ) return new WP_Error( 'mad4b_search_surface_pagination_exceeded', 'Pagination exceeds admitted bound.', array( 'page_number' => $page, 'max_page_number' => $max_page ) );

		$virtual = in_array( $type, array( 'virtual_landing_surface', 'faceted_archive', 'paginated_archive' ), true );
		if ( $virtual && ! empty( $policy['require_indexable_virtual'] ) && empty( $surface['indexable'] ) ) {
			return new WP_Error( 'mad4b_search_virtual_surface_not_indexable', 'Virtual surface admission requires effective indexability.' );
		}

		return array(
			'contract' => self::SURFACE_ADMISSION_CONTRACT,
			'admitted' => true,
			'surface_type' => $type,
			'url' => $url,
			'estimated_cardinality' => $cardinality,
			'max_cardinality' => $max_cardinality,
			'page_number' => $page,
			'max_page_number' => $max_page,
			'unknown_query_params' => array(),
			'authorizing' => false,
		);
	}
}
