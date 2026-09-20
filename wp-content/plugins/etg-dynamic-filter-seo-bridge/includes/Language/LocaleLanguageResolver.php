<?php
namespace ETG\DynamicFilterSEOBridge\Language;

use WP_Term;

final class LocaleLanguageResolver implements LanguageResolverInterface {
    public function currentLanguage(): string {
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : ( function_exists( 'get_locale' ) ? get_locale() : 'en_US' );
        $language = strtolower( substr( (string) $locale, 0, 2 ) );
        return $this->cleanKey( $language ) ?: 'en';
    }

    public function languageForUri( ?string $uri ): string {
        unset( $uri );
        return $this->currentLanguage();
    }

    public function activeLanguages(): array {
        return array();
    }

    public function defaultLanguage(): string {
        return $this->currentLanguage();
    }

    public function localizeUrl( string $url, string $language ): array {
        $language = $this->cleanKey( $language );
        $current = $this->currentLanguage();
        if ( '' === $language || $language === $current ) { return array( 'available'=>true, 'url'=>$url, 'reason'=>'' ); }
        return array( 'available'=>false, 'url'=>'', 'reason'=>'language_permalink_unavailable' );
    }

    public function executeInLanguage( string $language, callable $callback ): array {
        $language = $this->cleanKey( $language );
        $current = $this->currentLanguage();
        if ( '' !== $language && $language !== $current ) { return array( 'available'=>false, 'switched'=>false, 'reason'=>'language_context_unavailable', 'result'=>null ); }
        try { return array( 'available'=>true, 'switched'=>false, 'reason'=>'', 'result'=>$callback() ); }
        catch ( \Throwable $e ) { throw $e; }
    }

    public function resolve( WP_Term $term, string $taxonomy, ?string $language = null ): array {
        unset( $taxonomy, $language );
        return array( 'term' => $term, 'translation_fallback' => false );
    }

    private function cleanKey( $value ): string {
        if ( function_exists( 'sanitize_key' ) ) { return sanitize_key( (string) $value ); }
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?: '';
    }
}
