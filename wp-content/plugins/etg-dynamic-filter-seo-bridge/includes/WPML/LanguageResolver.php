<?php
namespace ETG\DynamicFilterSEOBridge\WPML;

require_once dirname( __DIR__ ) . '/Language/LanguageResolverInterface.php';

use ETG\DynamicFilterSEOBridge\Language\LanguageResolverInterface;
use WP_Term;

final class LanguageResolver implements LanguageResolverInterface {
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

	public function defaultLanguage(): string {
		if ( function_exists( 'has_filter' ) && has_filter( 'wpml_default_language' ) ) {
			$default = sanitize_key( (string) apply_filters( 'wpml_default_language', null ) );
			if ( '' !== $default ) { return $default; }
		}
		return $this->currentLanguage();
	}

	public function localizeUrl( string $url, string $language ): array {
		$language = sanitize_key( $language );
		if ( '' === $language || $language === $this->defaultLanguage() ) { return array( 'available'=>true, 'url'=>$url, 'reason'=>'' ); }
		if ( ! function_exists( 'has_filter' ) || ! has_filter( 'wpml_permalink' ) ) { return array( 'available'=>false, 'url'=>'', 'reason'=>'language_permalink_unavailable' ); }
		try {
			$candidate = apply_filters( 'wpml_permalink', $url, $language, true );
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) { return array( 'available'=>true, 'url'=>$candidate, 'reason'=>'' ); }
			return array( 'available'=>false, 'url'=>'', 'reason'=>'language_permalink_invalid' );
		} catch ( \Throwable $e ) {
			return array( 'available'=>false, 'url'=>'', 'reason'=>'language_permalink_exception' );
		}
	}

	public function executeInLanguage( string $language, callable $callback ): array {
		$language = sanitize_key( $language );
		$currentAvailable = function_exists( 'has_filter' ) && false !== has_filter( 'wpml_current_language' ) && function_exists( 'apply_filters' );
		if ( ! $currentAvailable ) { return array( 'available'=>false, 'switched'=>false, 'reason'=>'wpml_language_context_unavailable', 'result'=>null ); }
		$previous = sanitize_key( (string) apply_filters( 'wpml_current_language', null ) );
		$switched = false;
		$sitepressObject = null;
		try {
			if ( '' !== $language && $previous !== $language ) {
				global $sitepress;
				if ( ! is_object( $sitepress ) || ! method_exists( $sitepress, 'switch_lang' ) ) { return array( 'available'=>false, 'switched'=>false, 'reason'=>'wpml_language_switch_unavailable', 'result'=>null ); }
				$sitepressObject = $sitepress;
				$sitepressObject->switch_lang( $language, true );
				$switched = true;
			}
			return array( 'available'=>true, 'switched'=>$switched, 'reason'=>'', 'result'=>$callback() );
		} finally {
			if ( $switched && is_object( $sitepressObject ) && method_exists( $sitepressObject, 'switch_lang' ) && '' !== $previous ) {
				try { $sitepressObject->switch_lang( $previous, true ); } catch ( \Throwable $ignored ) {}
			}
		}
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
