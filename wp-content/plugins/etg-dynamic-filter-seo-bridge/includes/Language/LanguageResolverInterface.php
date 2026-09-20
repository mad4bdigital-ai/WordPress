<?php
namespace ETG\DynamicFilterSEOBridge\Language;

use WP_Term;

interface LanguageResolverInterface {
    public function currentLanguage(): string;
    public function languageForUri( ?string $uri ): string;
    public function activeLanguages(): array;
    public function defaultLanguage(): string;
    public function localizeUrl( string $url, string $language ): array;
    public function executeInLanguage( string $language, callable $callback ): array;
    public function resolve( WP_Term $term, string $taxonomy, ?string $language = null ): array;
}
