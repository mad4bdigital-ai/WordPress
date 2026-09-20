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
		$language = sanitize_key( (string) ( $context['language'] ?? '' ) );
		$terms = (array) ( $context['terms'] ?? array() );
		$mode = $this->compositionMode( $context );
		if ( 'travel' === $mode ) {
			$location = $this->termName( $terms, 'location' );
			$type = $this->termName( $terms, 'tour_type' );
			$style = $this->termName( $terms, 'style' );
			if ( 0 === strpos( $language, 'ar' ) ) {
				$lead = trim( implode( ' ', array_filter( array( $type, $style ) ) ) );
				$title = $location && $lead ? $lead . ' في ' . $location : ( $location ?: $lead );
			} elseif ( 0 === strpos( $language, 'en' ) ) {
				$title = trim( implode( ' ', array_filter( array( $style, $location, $type ) ) ) );
			} else {
				$title = trim( implode( ' — ', array_filter( array( $location, $type, $style ) ) ) );
			}
		} else {
			$names = array();
			foreach ( $this->orderedRoles( $context ) as $role ) {
				$name = $this->termName( $terms, $role );
				if ( '' !== $name ) { $names[] = $name; }
			}
			$title = implode( ' — ', array_unique( $names ) );
		}
		$title = preg_replace( '/\s+/u', ' ', (string) $title );
		$title = is_string( $title ) ? trim( $title ) : '';
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'etg_filter_seo_h1', $title, $context ) : $title;
	}

	public function intro( array $context ): string {
		$parts = array();
		foreach ( $this->orderedTerms( $context ) as $term ) {
			$text = ! empty( $term['short_description'] ) ? (string) $term['short_description'] : (string) ( $term['description'] ?? '' );
			if ( '' !== trim( wp_strip_all_tags( $text ) ) ) { $parts[] = wp_kses_post( $text ); }
		}
		$intro = implode( "\n\n", array_unique( $parts ) );
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'etg_filter_seo_intro', $intro, $context ) : $intro;
	}

	public function sections( array $context ): string {
		$terms = (array) ( $context['terms'] ?? array() );
		$language = (string) ( $context['language'] ?? '' );
		$html = array();
		foreach ( $this->orderedRoles( $context ) as $role ) {
			if ( empty( $terms[ $role ] ) ) { continue; }
			$term = $terms[ $role ];
			$body = ! empty( $term['description'] ) ? (string) $term['description'] : (string) ( $term['short_description'] ?? '' );
			if ( '' === trim( wp_strip_all_tags( $body ) ) ) { continue; }
			$html[] = sprintf(
				'<section class="etg-filter-seo-section etg-filter-seo-section--%1$s"><h2>%2$s</h2><div class="etg-filter-seo-section__content">%3$s</div></section>',
				esc_attr( str_replace( '_', '-', $role ) ),
				esc_html( $this->sectionHeading( $role, $terms, $language, $context ) ),
				wp_kses_post( $body )
			);
		}
		$sections = implode( "\n", $html );
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'etg_filter_seo_sections', $sections, $context ) : $sections;
	}

	public function metaTitle( array $context ): string {
		$explicit = array();
		foreach ( $this->orderedTerms( $context ) as $term ) { if ( ! empty( $term['seo_title'] ) ) { $explicit[] = trim( (string) $term['seo_title'] ); } }
		$title = $explicit ? implode( ' — ', array_unique( $explicit ) ) : $this->title( $context );
		if ( '' === $title ) { return ''; }
		$site = trim( (string) get_bloginfo( 'name' ) );
		$title = $site ? $title . ' | ' . $site : $title;
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'etg_filter_seo_meta_title', $title, $context ) : $title;
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
		if ( function_exists( 'wp_html_excerpt' ) && strlen( $description ) > 160 ) { $description = rtrim( wp_html_excerpt( $description, 157, '' ) ) . '...'; }
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'etg_filter_seo_meta_description', $description, $context ) : $description;
	}

	public function keyword( array $context ): string {
		$keywords = array();
		foreach ( $this->orderedTerms( $context ) as $term ) { if ( ! empty( $term['focus_keyword'] ) ) { $keywords[] = trim( (string) $term['focus_keyword'] ); } }
		$keyword = trim( implode( ' ', array_unique( array_filter( $keywords ) ) ) );
		if ( '' === $keyword ) { $keyword = strtolower( $this->title( $context ) ); }
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'etg_filter_seo_keyword', $keyword, $context ) : $keyword;
	}

	public function breadcrumbLabels( array $context ): array {
		$terms=(array)($context['terms']??array());$labels=array();
		foreach($this->orderedRoles($context) as $role){if(!empty($terms[$role]['name'])){$labels[]=trim((string)$terms[$role]['name']);}}
		return array_values(array_unique(array_filter($labels,'strlen')));
	}

	private function orderedTerms( array $context ): array {
		$terms = (array) ( $context['terms'] ?? array() );
		$out = array();
		foreach ( $this->orderedRoles( $context ) as $role ) { if ( ! empty( $terms[ $role ] ) ) { $out[] = $terms[ $role ]; } }
		return $out;
	}

	private function orderedRoles( array $context ): array {
		$terms = (array) ( $context['terms'] ?? array() );
		$rules = (array) ( $context['profile']['taxonomy_rules'] ?? array() );
		$rows = array();
		foreach ( $rules as $taxonomy => $rule ) {
			$role = sanitize_key( (string) ( $rule['role'] ?? $taxonomy ) );
			if ( '' === $role || ! isset( $terms[ $role ] ) ) { continue; }
			$rows[] = array( 'role'=>$role, 'priority'=>(int)($rule['priority']??100), 'taxonomy'=>(string)$taxonomy );
		}
		usort( $rows, static function ( $a, $b ) { $cmp = $a['priority'] <=> $b['priority']; return 0 !== $cmp ? $cmp : strcmp( $a['taxonomy'], $b['taxonomy'] ); } );
		$roles = array();
		foreach ( $rows as $row ) { $roles[] = $row['role']; }
		foreach ( array_keys( $terms ) as $role ) { if ( ! in_array( $role, $roles, true ) ) { $roles[] = (string) $role; } }
		return $roles;
	}

	private function compositionMode( array $context ): string {
		$mode = sanitize_key( (string) ( $context['profile']['composition_mode'] ?? '' ) );
		if ( in_array( $mode, array( 'travel', 'generic' ), true ) ) { return $mode; }
		$terms = (array) ( $context['terms'] ?? array() );
		return isset( $terms['location'] ) || isset( $terms['tour_type'] ) || isset( $terms['style'] ) ? 'travel' : 'generic';
	}

	private function termName( array $terms, string $role ): string { return ! empty( $terms[ $role ]['name'] ) ? trim( (string) $terms[ $role ]['name'] ) : ''; }

	private function sectionHeading( string $role, array $terms, string $language, array $context ): string {
		$name = $this->termName( $terms, $role );
		if ( 'travel' !== $this->compositionMode( $context ) ) { return $name; }
		$location = $this->termName( $terms, 'location' );
		if ( 0 === strpos( $language, 'ar' ) ) { if ( 'location' === $role ) { return 'عن ' . $name; } if ( 'tour_type' === $role && $location ) { return $name . ' في ' . $location; } if ( 'style' === $role ) { return 'تجربة ' . $name; } return $name; }
		if ( 0 === strpos( $language, 'en' ) ) { if ( 'location' === $role ) { return 'About ' . $name; } if ( 'tour_type' === $role && $location ) { return $location . ' ' . $name; } if ( 'style' === $role ) { return $name . ' Experience'; } return 'About ' . $name; }
		return $name;
	}
}
