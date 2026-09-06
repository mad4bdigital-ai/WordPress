<?php
namespace ETG\DynamicFilterSEOBridge\JetSmartFilters;

final class FilterUrlParser {
	private $allowedTaxonomies;

	public function __construct( array $allowedTaxonomies = array() ) {
		$this->allowedTaxonomies = $allowedTaxonomies ?: array( 'location_jet', 'tour-types_jet', 'tour-style_jet' );
	}

	public function parse( ?string $uri = null ): array {
		if ( null === $uri ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
		}
		$path = parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) ? rawurldecode( $path ) : '';
		$empty = array(
			'active' => false, 'request_path' => $path, 'archive' => '', 'provider' => '', 'query_id' => '',
			'filters' => array(), 'unknown_filters' => array(), 'malformed' => array(),
		);

		$jsfPos = strpos( $path, '/jsf/' );
		$taxPos = strpos( $path, '/tax/' );
		if ( false === $jsfPos || false === $taxPos || $taxPos <= $jsfPos ) {
			return $empty;
		}

		$archivePath = trim( substr( $path, 0, $jsfPos ), '/' );
		$archiveBits = array_values( array_filter( explode( '/', $archivePath ), 'strlen' ) );
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
		$filters = array();
		$unknown = array();
		$malformed = array();
		$allowed = $this->allowedTaxonomies();

		foreach ( $pairs as $pair ) {
			$pair = trim( $pair );
			if ( '' === $pair ) { continue; }
			if ( false === strpos( $pair, ':' ) ) {
				$malformed[] = sanitize_text_field( $pair );
				continue;
			}
			list( $taxonomyRaw, $slugRaw ) = explode( ':', $pair, 2 );
			$taxonomy = sanitize_key( $taxonomyRaw );
			$slug = sanitize_title( $slugRaw );
			if ( '' === $taxonomy || '' === $slug ) {
				$malformed[] = sanitize_text_field( $pair );
				continue;
			}
			if ( ! in_array( $taxonomy, $allowed, true ) ) {
				$unknown[ $taxonomy ] = $slug;
				continue;
			}
			$filters[ $taxonomy ] = $slug;
		}

		return array(
			'active' => true,
			'request_path' => $path,
			'archive' => sanitize_title( $archive ),
			'provider' => $provider,
			'query_id' => $queryId,
			'filters' => $filters,
			'unknown_filters' => $unknown,
			'malformed' => $malformed,
		);
	}

	private function allowedTaxonomies(): array {
		$allowed = function_exists( 'apply_filters' )
			? apply_filters( 'etg_filter_seo_allowed_taxonomies', $this->allowedTaxonomies )
			: $this->allowedTaxonomies;
		return array_values( array_filter( array_map( 'sanitize_key', (array) $allowed ) ) );
	}
}
