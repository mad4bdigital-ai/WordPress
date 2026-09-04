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

	public function resolve( WP_Term $term, string $taxonomy, ?string $language = null ): array {
		$language = $language ?: $this->currentLanguage();
		if ( ! has_filter( 'wpml_object_id' ) ) {
			return array( 'term' => $term, 'translation_fallback' => false );
		}
		$translatedId = apply_filters( 'wpml_object_id', $term->term_id, $taxonomy, false, $language );
		if ( ! $translatedId ) {
			return array( 'term' => $term, 'translation_fallback' => true );
		}
		$translated = get_term( (int) $translatedId, $taxonomy );
		if ( ! $translated instanceof WP_Term ) {
			return array( 'term' => $term, 'translation_fallback' => true );
		}
		return array( 'term' => $translated, 'translation_fallback' => false );
	}
}
