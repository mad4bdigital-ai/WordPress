<?php
namespace ETG\DynamicFilterSEOBridge\Context;

use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\JetSmartFilters\FilterUrlParser;
use ETG\DynamicFilterSEOBridge\Terms\TermMetaReader;
use ETG\DynamicFilterSEOBridge\WPML\LanguageResolver;
use WP_Term;

final class FilterContextBuilder {
	private $parser; private $languages; private $meta; private $content;
	public function __construct( FilterUrlParser $parser, LanguageResolver $languages, TermMetaReader $meta, ContentComposer $content ) {
		$this->parser = $parser; $this->languages = $languages; $this->meta = $meta; $this->content = $content;
	}

	public function build( ?string $uri = null ): array {
		$parsed = $this->parser->parse( $uri );
		$language = $this->languages->currentLanguage();
		$context = array_merge( $parsed, array(
			'language' => $language, 'terms' => array(), 'missing_terms' => array(), 'translation_fallback' => false,
			'jet_smart_filters_provider' => $this->currentProvider(), 'combo' => array(),
		) );
		if ( empty( $parsed['active'] ) ) { return $context; }

		foreach ( (array) $parsed['filters'] as $taxonomy => $slug ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( ! $term instanceof WP_Term ) { $context['missing_terms'][ $taxonomy ] = $slug; continue; }
			$resolved = $this->languages->resolve( $term, $taxonomy, $language );
			if ( $resolved['translation_fallback'] ) { $context['translation_fallback'] = true; }
			$role = $this->roleForTaxonomy( $taxonomy );
			if ( $role ) { $context['terms'][ $role ] = $this->meta->read( $resolved['term'] ); }
		}
		return $this->content->decorate( $context );
	}

	private function roleForTaxonomy( string $taxonomy ): string {
		$map = apply_filters( 'etg_filter_seo_taxonomy_role_map', array(
			'location_jet' => 'location', 'tour-types_jet' => 'tour_type', 'tour-style_jet' => 'style',
		) );
		return isset( $map[ $taxonomy ] ) ? sanitize_key( $map[ $taxonomy ] ) : '';
	}

	private function currentProvider(): string {
		if ( ! function_exists( 'jet_smart_filters' ) ) { return ''; }
		$instance = jet_smart_filters();
		if ( ! is_object( $instance ) || ! isset( $instance->query ) || ! is_object( $instance->query ) || ! method_exists( $instance->query, 'get_current_provider' ) ) { return ''; }
		$provider = $instance->query->get_current_provider( 'raw' );
		return is_scalar( $provider ) ? sanitize_text_field( (string) $provider ) : '';
	}
}
