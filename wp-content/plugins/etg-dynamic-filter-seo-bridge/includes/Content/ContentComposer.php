<?php
namespace ETG\DynamicFilterSEOBridge\Content;

final class ContentComposer {
	private $gallery;

	public function __construct( GalleryComposer $gallery ) { $this->gallery = $gallery; }

	public function decorate( array $context ): array {
		$context['combo'] = array(
			'title' => $this->title( $context ),
			'keyword' => $this->keyword( $context ),
			'meta_description' => $this->metaDescription( $context ),
			'intro' => $this->intro( $context ),
			'gallery_ids' => $this->gallery->ids( $context, 'combined' ),
		);
		return $context;
	}

	public function title( array $context ): string {
		$language = isset( $context['language'] ) ? (string) $context['language'] : '';
		$terms = isset( $context['terms'] ) ? (array) $context['terms'] : array();
		$location = $this->termName( $terms, 'location' );
		$type = $this->termName( $terms, 'tour_type' );
		$style = $this->termName( $terms, 'style' );
		if ( 0 === strpos( $language, 'ar' ) ) {
			$lead = trim( implode( ' ', array_filter( array( $type, $style ) ) ) );
			$title = $location && $lead ? $lead . ' في ' . $location : ( $location ?: $lead );
		} else {
			$title = trim( implode( ' ', array_filter( array( $style, $location, $type ) ) ) );
		}
		$title = preg_replace( '/\s+/u', ' ', $title );
		$title = is_string( $title ) ? trim( $title ) : '';
		return (string) apply_filters( 'etg_filter_seo_h1', $title, $context );
	}

	public function intro( array $context ): string {
		$parts = array();
		foreach ( $this->orderedTerms( $context ) as $term ) {
			$text = ! empty( $term['short_description'] ) ? (string) $term['short_description'] : (string) ( $term['description'] ?? '' );
			if ( '' !== trim( wp_strip_all_tags( $text ) ) ) { $parts[] = wp_kses_post( $text ); }
		}
		return (string) apply_filters( 'etg_filter_seo_intro', implode( "\n\n", $parts ), $context );
	}

	public function sections( array $context ): string {
		$terms = isset( $context['terms'] ) ? (array) $context['terms'] : array();
		$language = isset( $context['language'] ) ? (string) $context['language'] : '';
		$html = array();
		foreach ( array( 'location', 'tour_type', 'style' ) as $role ) {
			if ( empty( $terms[ $role ] ) ) { continue; }
			$term = $terms[ $role ];
			$body = ! empty( $term['description'] ) ? (string) $term['description'] : (string) ( $term['short_description'] ?? '' );
			if ( '' === trim( wp_strip_all_tags( $body ) ) ) { continue; }
			$heading = $this->sectionHeading( $role, $terms, $language );
			$html[] = sprintf(
				'<section class="etg-filter-seo-section etg-filter-seo-section--%1$s"><h2>%2$s</h2><div class="etg-filter-seo-section__content">%3$s</div></section>',
				esc_attr( str_replace( '_', '-', $role ) ), esc_html( $heading ), wp_kses_post( $body )
			);
		}
		return (string) apply_filters( 'etg_filter_seo_sections', implode( "\n", $html ), $context );
	}

	public function metaTitle( array $context ): string {
		$title = $this->title( $context );
		if ( '' === $title ) { return ''; }
		$site = trim( (string) get_bloginfo( 'name' ) );
		return (string) apply_filters( 'etg_filter_seo_meta_title', $site ? $title . ' | ' . $site : $title, $context );
	}

	public function metaDescription( array $context ): string {
		$parts = array();
		foreach ( $this->orderedTerms( $context ) as $term ) {
			$text = ! empty( $term['meta_description'] ) ? (string) $term['meta_description'] : ( ! empty( $term['short_description'] ) ? (string) $term['short_description'] : (string) ( $term['description'] ?? '' ) );
			$text = preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) );
			$text = is_string( $text ) ? trim( $text ) : '';
			if ( '' !== $text ) { $parts[] = $text; }
		}
		$description = trim( implode( ' ', array_unique( $parts ) ) );
		if ( function_exists( 'wp_html_excerpt' ) && strlen( $description ) > 160 ) {
			$description = rtrim( wp_html_excerpt( $description, 157, '' ) ) . '...';
		}
		return (string) apply_filters( 'etg_filter_seo_meta_description', $description, $context );
	}

	public function keyword( array $context ): string {
		$keywords = array();
		foreach ( $this->orderedTerms( $context ) as $term ) {
			if ( ! empty( $term['focus_keyword'] ) ) { $keywords[] = trim( (string) $term['focus_keyword'] ); }
		}
		$keyword = trim( implode( ' ', array_unique( array_filter( $keywords ) ) ) );
		if ( '' === $keyword ) { $keyword = strtolower( $this->title( $context ) ); }
		return (string) apply_filters( 'etg_filter_seo_keyword', $keyword, $context );
	}

	private function orderedTerms( array $context ): array {
		$terms = isset( $context['terms'] ) ? (array) $context['terms'] : array();
		$out = array();
		foreach ( array( 'location', 'tour_type', 'style' ) as $role ) { if ( ! empty( $terms[ $role ] ) ) { $out[] = $terms[ $role ]; } }
		return $out;
	}

	private function termName( array $terms, string $role ): string { return ! empty( $terms[ $role ]['name'] ) ? trim( (string) $terms[ $role ]['name'] ) : ''; }

	private function sectionHeading( string $role, array $terms, string $language ): string {
		$name = $this->termName( $terms, $role );
		$location = $this->termName( $terms, 'location' );
		if ( 0 === strpos( $language, 'ar' ) ) {
			if ( 'location' === $role ) { return 'عن ' . $name; }
			if ( 'tour_type' === $role && $location ) { return $name . ' في ' . $location; }
			if ( 'style' === $role ) { return 'تجربة ' . $name; }
			return $name;
		}
		if ( 'location' === $role ) { return 'About ' . $name; }
		if ( 'tour_type' === $role && $location ) { return $location . ' ' . $name; }
		if ( 'style' === $role ) { return $name . ' Experience'; }
		return 'About ' . $name;
	}
}
