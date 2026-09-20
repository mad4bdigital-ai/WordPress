<?php
namespace ETG\DynamicFilterSEOBridge\WPML;

use ETG\DynamicFilterSEOBridge\SEO\PublicationRegistry;

final class HreflangAdapter {
    private $contextProvider;
    private $publication;

    public function __construct( callable $contextProvider, PublicationRegistry $publication ) {
        $this->contextProvider = $contextProvider;
        $this->publication = $publication;
    }

    public function register(): void {
        if ( function_exists( 'add_filter' ) ) {
            add_filter( 'wpml_hreflangs', array( $this, 'hreflangs' ), 99 );
        }
    }

    public function hreflangs( $items ) {
        $context = $this->context();
        if ( ! $this->allowed( $context ) ) { return $items; }
        $alternates = $this->publication->alternatesForContext( $context );
        return $alternates ?: $items;
    }

    private function context(): array {
        $context = call_user_func( $this->contextProvider );
        return is_array( $context ) ? $context : array();
    }

    private function allowed( array $context ): bool {
        if ( 'ajax' === (string) ( $context['state_transport'] ?? '' ) || ! empty( $context['ajax_only'] ) ) { return false; }
        if ( empty( $context['active'] ) || empty( $context['in_scope'] ) || empty( $context['runtime_ready'] ) || empty( $context['filters'] ) ) { return false; }
        if ( isset( $context['scope_valid'] ) && empty( $context['scope_valid'] ) ) { return false; }
        $profile = (array) ( $context['profile'] ?? array() );
        if ( empty( $profile['publication']['hreflang'] ) && empty( $profile['publication']['multilingual'] ) ) { return false; }
        $requireProvider = array_key_exists( 'require_provider_observation_for_index', $profile ) ? (bool) $profile['require_provider_observation_for_index'] : true;
        if ( $requireProvider && empty( $context['provider_observed'] ) ) { return false; }
        if ( ! empty( $context['provider_observed'] ) && empty( $context['provider_observation_matches_url'] ) ) { return false; }
        $binding = (array) ( $context['post_type_binding'] ?? array() );
        if ( ! empty( $profile['require_post_type_binding'] ) && ( empty( $binding['observed'] ) || empty( $binding['matches_profile'] ) ) ) { return false; }
        return empty( $context['unknown_filters'] ) && empty( $context['malformed'] ) && empty( $context['missing_terms'] ) && empty( $context['translation_fallback'] ) && ! empty( $context['terms'] );
    }
}
