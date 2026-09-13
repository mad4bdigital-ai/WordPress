<?php
namespace ETG\DynamicFilterSEOBridge\Acceptance;

final class CanonicalStateNormalizer {
    const CONTRACT = 'etg.dfsb.acceptance-canonical-state.v1';

    public function directUri( array $profile, array $route, array $candidate ): string {
        $archive = $this->archivePath( $profile );
        $provider = $this->cleanKey( $route['provider'] ?? '' );
        $queryId = $this->cleanIdentifier( ( $route['provider_query_id'] ?? '' ) ?: ( $route['query_id'] ?? '' ) );
        $taxonomy = $this->cleanKey( $candidate['taxonomy'] ?? '' );
        $slug = $this->cleanSlug( $candidate['term_slug'] ?? '' );
        if ( '' === $archive || '' === $provider || '' === $queryId || '' === $taxonomy || '' === $slug ) return '';
        return rtrim( $archive, '/' ) . '/jsf/' . rawurlencode( $provider ) . ':' . rawurlencode( $queryId ) . '/tax/' . rawurlencode( $taxonomy ) . ':' . rawurlencode( $slug ) . '/';
    }

    public function ajaxPayload( array $profile, array $route, array $candidate ): array {
        $archive = $this->archivePath( $profile );
        $provider = $this->cleanKey( $route['provider'] ?? '' );
        $queryId = $this->cleanIdentifier( ( $route['provider_query_id'] ?? '' ) ?: ( $route['query_id'] ?? '' ) );
        $taxonomy = $this->cleanKey( $candidate['taxonomy'] ?? '' );
        $slug = $this->cleanSlug( $candidate['term_slug'] ?? '' );
        return array(
            'provider' => $provider,
            'query_id' => $queryId,
            'request_path' => $archive,
            'archive_path' => $archive,
            'current_query' => array(
                'tax_query' => array(
                    'relation' => 'AND',
                    array(
                        'taxonomy' => $taxonomy,
                        'field' => 'slug',
                        'terms' => array( $slug ),
                        'operator' => 'IN',
                        'include_children' => true,
                    ),
                ),
            ),
        );
    }

    public function context( array $context ): array {
        $values = array();
        $source = isset( $context['filter_values'] ) && is_array( $context['filter_values'] ) ? $context['filter_values'] : (array) ( $context['filters'] ?? array() );
        foreach ( $source as $taxonomy => $terms ) {
            $taxonomy = $this->cleanKey( $taxonomy );
            if ( '' === $taxonomy ) continue;
            if ( ! is_array( $terms ) ) $terms = array( $terms );
            $clean = array_values( array_unique( array_filter( array_map( array( $this, 'cleanSlug' ), $terms ) ) ) );
            sort( $clean, SORT_STRING );
            if ( $clean ) $values[ $taxonomy ] = $clean;
        }
        ksort( $values, SORT_STRING );
        $missing = array();
        foreach ( (array) ( $context['missing_terms'] ?? array() ) as $taxonomy => $reason ) $missing[ $this->cleanKey( $taxonomy ) ] = (string) $reason;
        ksort( $missing, SORT_STRING );

        $state = array(
            'contract' => self::CONTRACT,
            'profile_id' => $this->cleanKey( $context['profile_id'] ?? '' ),
            'provider' => $this->cleanKey( $context['provider'] ?? '' ),
            'query_id' => $this->cleanIdentifier( $context['query_id'] ?? '' ),
            'archive_path' => $this->normalizePath( $context['archive_path'] ?? '' ),
            'filters' => $values,
            'in_scope' => ! empty( $context['in_scope'] ),
            'scope_valid' => ! empty( $context['scope_valid'] ),
            'runtime_ready' => ! empty( $context['runtime_ready'] ),
            'authorizing' => ! empty( $context['authorizing'] ),
            'url_authority' => ! empty( $context['url_authority'] ),
            'ajax_only' => ! empty( $context['ajax_only'] ),
            'presentation_state_complete' => ! array_key_exists( 'presentation_state_complete', $context ) || ! empty( $context['presentation_state_complete'] ),
            'filtered_query_complete' => ! array_key_exists( 'filtered_query_complete', $context ) || ! empty( $context['filtered_query_complete'] ),
            'missing_terms' => $missing,
            'translation_fallback' => ! empty( $context['translation_fallback'] ),
            'post_type_binding_matches_profile' => ! array_key_exists( 'post_type_observation_matches_profile', $context ) || ! empty( $context['post_type_observation_matches_profile'] ),
            'filtered_query' => isset( $context['filtered_query'] ) && is_array( $context['filtered_query'] ) ? $context['filtered_query'] : array(),
        );
        $state['state_digest'] = $this->digest( $this->digestableState( $state ) );
        return $state;
    }

    public function directFilteredQuery( array $state ): array {
        $clauses = array( 'relation' => 'AND' );
        foreach ( (array) ( $state['filters'] ?? array() ) as $taxonomy => $terms ) {
            $terms = array_values( array_filter( (array) $terms ) );
            if ( ! $terms ) continue;
            $clauses[] = array(
                'taxonomy' => $this->cleanKey( $taxonomy ),
                'field' => 'slug',
                'terms' => $terms,
                'operator' => 'IN',
                'include_children' => true,
            );
        }
        return count( $clauses ) > 1 ? array( 'tax_query' => $clauses ) : array();
    }

    public function semanticStateEqual( array $a, array $b ): bool {
        foreach ( array( 'profile_id', 'provider', 'query_id', 'archive_path', 'filters' ) as $key ) {
            if ( ( $a[ $key ] ?? null ) !== ( $b[ $key ] ?? null ) ) return false;
        }
        return true;
    }

    private function digestableState( array $state ): array {
        unset( $state['state_digest'], $state['filtered_query'] );
        return $state;
    }

    private function digest( array $value ): string {
        $encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
        return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
    }

    private function archivePath( array $profile ): string {
        $paths = array_values( array_filter( array_map( array( $this, 'normalizePath' ), (array) ( $profile['archive_paths'] ?? array() ) ) ) );
        sort( $paths, SORT_STRING );
        return $paths ? $paths[0] : '';
    }

    private function normalizePath( $value ): string {
        $path = parse_url( (string) $value, PHP_URL_PATH );
        if ( ! is_string( $path ) ) return '';
        $path = preg_replace( '#/+#', '/', $path );
        if ( ! is_string( $path ) || '' === $path ) return '';
        return '/' . trim( strtolower( $path ), '/' ) . '/';
    }

    private function cleanKey( $value ): string {
        if ( function_exists( 'sanitize_key' ) ) return sanitize_key( (string) $value );
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?: '';
    }

    private function cleanSlug( $value ): string {
        if ( function_exists( 'sanitize_title' ) ) return sanitize_title( (string) $value );
        $value = strtolower( trim( (string) $value ) );
        return trim( preg_replace( '/[^a-z0-9_\-]+/', '-', $value ) ?: '', '-' );
    }

    private function cleanIdentifier( $value ): string {
        $value = strtolower( trim( (string) $value ) );
        return preg_match( '/^[a-z0-9._\-]{1,80}$/', $value ) ? $value : '';
    }
}
