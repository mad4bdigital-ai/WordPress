<?php
namespace ETG\DynamicFilterSEOBridge\WPML;

use WP_Term;

final class LanguageResolver {
	public function currentLanguage(): string {
		$language = has_filter( 'wpml_current_language' ) ? (string) apply_filters( 'wpml_current_language', null ) : '';
		if ( '' === $language ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
			$language = strtolower( substr( (string) $locale, 0, 2 ) );
		}
		return sanitize_key( $language );
	}

	public function languageForUri( ?string $uri ): string {
		if ( null === $uri || '' === trim( $uri ) ) { return $this->currentLanguage(); }
		$path = parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) ? trim( rawurldecode( $path ), '/' ) : '';
		$first = '';
		if ( '' !== $path ) { $bits = explode( '/', $path ); $first = sanitize_key( (string) reset( $bits ) ); }
		$active = $this->activeLanguages();
		if ( $first && isset( $active[ $first ] ) ) { return $first; }
		if ( has_filter( 'wpml_default_language' ) ) {
			$default = sanitize_key( (string) apply_filters( 'wpml_default_language', null ) );
			if ( $default ) { return $default; }
		}
		return $this->currentLanguage();
	}

	public function activeLanguages(): array {
		if ( ! has_filter( 'wpml_active_languages' ) ) { return array(); }
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( ! is_array( $languages ) ) { return array(); }
		$out = array();
		foreach ( $languages as $code => $data ) {
			$code = sanitize_key( (string) $code );
			if ( $code ) { $out[ $code ] = is_array( $data ) ? $data : array(); }
		}
		return $out;
	}

	public function resolve( WP_Term $term, string $taxonomy, ?string $language = null ): array {
		$language = $language ?: $this->currentLanguage();
		if ( ! has_filter( 'wpml_object_id' ) ) { return array( 'term' => $term, 'translation_fallback' => false ); }
		$translatedId = apply_filters( 'wpml_object_id', $term->term_id, $taxonomy, false, $language );
		if ( ! $translatedId ) { return array( 'term' => $term, 'translation_fallback' => true ); }
		$translated = get_term( (int) $translatedId, $taxonomy );
		if ( ! $translated instanceof WP_Term ) { return array( 'term' => $term, 'translation_fallback' => true ); }
		return array( 'term' => $translated, 'translation_fallback' => false );
	}
}
