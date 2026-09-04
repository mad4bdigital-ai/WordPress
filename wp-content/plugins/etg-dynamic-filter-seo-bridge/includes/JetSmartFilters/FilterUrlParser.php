<?php
namespace ETG\DynamicFilterSEOBridge\JetSmartFilters;

final class FilterUrlParser {
	private $allowedTaxonomies;
	private $allowedQueryParams;
	private $trackingQueryParams;

	public function __construct( array $allowedTaxonomies = array(), array $allowedQueryParams = array(), array $trackingQueryParams = array() ) {
		$this->allowedTaxonomies = $allowedTaxonomies ?: array( 'location_jet', 'tour-types_jet', 'tour-style_jet' );
		$this->allowedQueryParams = array_values( array_filter( array_map( 'sanitize_key', $allowedQueryParams ) ) );
		$this->trackingQueryParams = $trackingQueryParams ?: array( 'gclid', 'fbclid', 'msclkid' );
	}

	public function parse( ?string $uri = null ): array {
		if ( null === $uri ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
		}
		$path = parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) ? rawurldecode( $path ) : '';
		$queryString = parse_url( $uri, PHP_URL_QUERY );
		$query = array();
		if ( is_string( $queryString ) && '' !== $queryString ) { parse_str( $queryString, $query ); }
		$queryState = $this->classifyQueryParams( $query );
		$empty = array_merge( array(
			'active' => false, 'request_path' => $path, 'archive_path' => '', 'archive' => '', 'provider' => '', 'query_id' => '',
			'filters' => array(), 'unknown_filters' => array(), 'malformed' => array(), 'duplicates' => array(),
		), $queryState );

		$jsfPos = strpos( $path, '/jsf/' );
		$taxPos = strpos( $path, '/tax/' );
		if ( false === $jsfPos || false === $taxPos || $taxPos <= $jsfPos ) { return $empty; }

		$archivePath = '/' . trim( substr( $path, 0, $jsfPos ), '/' ) . '/';
		$archiveBits = array_values( array_filter( explode( '/', trim( $archivePath, '/' ) ), 'strlen' ) );
		$archive = $archiveBits ? (string) end( $archiveBits ) : '';
		$providerStart = $jsfPos + strlen( '/jsf/' );
		$providerRaw = trim( substr( $path, $providerStart, $taxPos - $providerStart ), '/' );
		$provider = '';
		$queryId = '';
		if ( '' !== $providerRaw ) {
			$parts = explode( ':', $providerRaw, 2 );
			$provider = sanitize_key( $parts[0] );
			$queryId = isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';
		}

		$taxString = trim( substr( $path, $taxPos + strlen( '/tax/' ) ), '/' );
		$pairs = '' !== $taxString ? explode( ';', $taxString ) : array();
		$filters = array(); $unknown = array(); $malformed = array(); $duplicates = array();
		$allowed = $this->allowedTaxonomies();
		foreach ( $pairs as $pair ) {
			$pair = trim( $pair );
			if ( '' === $pair ) { continue; }
			if ( false === strpos( $pair, ':' ) ) { $malformed[] = sanitize_text_field( $pair ); continue; }
			list( $taxonomyRaw, $slugRaw ) = explode( ':', $pair, 2 );
			$taxonomy = sanitize_key( $taxonomyRaw );
			if ( preg_match( '/[,\+|]/', $slugRaw ) ) {
				$malformed[] = 'multi_value:' . $taxonomy;
				continue;
			}
			$slug = sanitize_title( $slugRaw );
			if ( '' === $taxonomy || '' === $slug ) { $malformed[] = sanitize_text_field( $pair ); continue; }
			if ( isset( $filters[ $taxonomy ] ) || isset( $unknown[ $taxonomy ] ) ) {
				$duplicates[ $taxonomy ] = $slug; $malformed[] = 'duplicate:' . $taxonomy; continue;
			}
			if ( ! in_array( $taxonomy, $allowed, true ) ) { $unknown[ $taxonomy ] = $slug; continue; }
			$filters[ $taxonomy ] = $slug;
		}

		return array_merge( array(
			'active' => true,
			'request_path' => $path,
			'archive_path' => $archivePath,
			'archive' => sanitize_title( $archive ),
			'provider' => $provider,
			'query_id' => $queryId,
			'filters' => $filters,
			'unknown_filters' => $unknown,
			'malformed' => $malformed,
			'duplicates' => $duplicates,
		), $queryState );
	}

	private function classifyQueryParams( array $query ): array {
		$tracking = array(); $allowed = array(); $unsupported = array(); $paginationPage = 1;
		foreach ( $query as $rawKey => $value ) {
			$key = sanitize_key( (string) $rawKey );
			if ( '' === $key ) { continue; }
			if ( 0 === strpos( $key, 'utm_' ) || in_array( $key, $this->trackingQueryParams, true ) ) { $tracking[ $key ] = $value; continue; }
			if ( in_array( $key, array( 'paged', 'page' ), true ) ) {
				$page = is_numeric( $value ) ? max( 1, (int) $value ) : 1;
				$paginationPage = max( $paginationPage, $page );
				if ( $page > 1 ) { $unsupported[ $key ] = $value; }
				continue;
			}
			if ( in_array( $key, $this->allowedQueryParams, true ) && is_scalar( $value ) ) { $allowed[ $key ] = sanitize_text_field( (string) $value ); continue; }
			$unsupported[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '[complex]';
		}
		ksort( $allowed ); ksort( $tracking ); ksort( $unsupported );
		return array(
			'query_params' => $query,
			'canonical_query_params' => $allowed,
			'tracking_query_params' => $tracking,
			'unsupported_query_params' => $unsupported,
			'pagination_page' => $paginationPage,
		);
	}

	private function allowedTaxonomies(): array {
		$allowed = function_exists( 'apply_filters' ) ? apply_filters( 'etg_filter_seo_allowed_taxonomies', $this->allowedTaxonomies ) : $this->allowedTaxonomies;
		return array_values( array_filter( array_map( 'sanitize_key', (array) $allowed ) ) );
	}
}
