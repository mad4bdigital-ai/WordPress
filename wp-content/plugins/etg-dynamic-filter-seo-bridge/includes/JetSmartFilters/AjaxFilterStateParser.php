<?php
namespace ETG\DynamicFilterSEOBridge\JetSmartFilters;

final class AjaxFilterStateParser {
    const CONTRACT = 'etg.dfsb.ajax-filter-state.v1';
    const MAX_QUERY_DEPTH = 8;
    const MAX_QUERY_NODES = 500;
    const MAX_TERMS_PER_TAXONOMY = 20;

    private $allowedTaxonomies;
    private $nodeCount = 0;

    public function __construct( array $allowedTaxonomies = array() ) {
        $this->allowedTaxonomies = array_values( array_unique( array_filter( array_map( 'sanitize_key', $allowedTaxonomies ) ) ) );
    }

    public function parse( array $payload ): array {
        $this->nodeCount = 0;
        $provider = sanitize_key( (string) ( $payload['provider'] ?? '' ) );
        $queryId = sanitize_key( (string) ( $payload['query_id'] ?? '' ) );
        $path = $this->normalizePath( (string) ( $payload['archive_path'] ?? $payload['request_path'] ?? '/' ) );
        $bits = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
        $archive = $bits ? sanitize_title( (string) end( $bits ) ) : '';
        $rawQuery = is_array( $payload['current_query'] ?? null ) ? (array) $payload['current_query'] : array();
        $query = $this->sanitizeTree( $rawQuery, 0 );
        $values = array();
        $unknown = array();
        $malformed = array();
        $unsupported = $this->unsupportedFilterProps( $query );
        $taxQuery = is_array( $query['tax_query'] ?? null ) ? (array) $query['tax_query'] : array();
        if ( $taxQuery ) { $this->inspectTaxQueryLogic( $taxQuery, $malformed ); }
        $safeTaxQuery = $this->extractTaxQuery( $taxQuery, $values, $unknown, $malformed );

        foreach ( $this->allowedTaxonomies as $taxonomy ) {
            if ( isset( $query[ $taxonomy ] ) && ! isset( $values[ $taxonomy ] ) ) {
                $direct = $this->normalizeTerms( $taxonomy, 'slug', $query[ $taxonomy ], $malformed );
                if ( $direct ) { $values[ $taxonomy ] = $direct; }
            }
        }

        $filters = array();
        $multi = array();
        foreach ( $values as $taxonomy => $terms ) {
            $terms = array_values( array_unique( array_filter( array_map( 'sanitize_title', (array) $terms ) ) ) );
            if ( ! $terms ) { continue; }
            $values[ $taxonomy ] = array_slice( $terms, 0, self::MAX_TERMS_PER_TAXONOMY );
            $filters[ $taxonomy ] = (string) $values[ $taxonomy ][0];
            if ( count( $values[ $taxonomy ] ) > 1 ) { $multi[] = $taxonomy; }
        }
        ksort( $filters, SORT_STRING );
        ksort( $values, SORT_STRING );
        ksort( $unknown, SORT_STRING );
        sort( $unsupported, SORT_STRING );

        $filteredQuery = array();
        if ( $safeTaxQuery ) { $filteredQuery['tax_query'] = $safeTaxQuery; }

        if ( '' === $provider ) { $malformed[] = 'missing_provider'; }
        if ( '' === $queryId ) { $malformed[] = 'missing_query_id'; }
        $malformed = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $malformed ) ) ) );
        $filteredQueryComplete = empty( $unknown ) && empty( $malformed ) && empty( $unsupported );
        $active = '' !== $provider && '' !== $queryId && ( ! empty( $filters ) || ! empty( $unsupported ) || ! empty( $unknown ) || ! empty( $malformed ) );

        return array(
            'contract' => self::CONTRACT,
            'active' => $active,
            'state_transport' => 'ajax',
            'url_authority' => false,
            'authorizing' => false,
            'request_path' => $path,
            'archive_path' => $path,
            'archive' => $archive,
            'provider' => $provider,
            'query_id' => $queryId,
            'filters' => $filters,
            'filter_values' => $values,
            'multi_value_filters' => array_values( array_unique( $multi ) ),
            'unknown_filters' => $unknown,
            'malformed' => $malformed,
            'unsupported_filter_props' => $unsupported,
            'filtered_query_complete' => $filteredQueryComplete,
            'filtered_query' => $filteredQuery,
            'duplicates' => array(),
            'query_params' => array(),
            'canonical_query_params' => array(),
            'tracking_query_params' => array(),
            'unsupported_query_params' => array(),
            'pagination_page' => 1,
        );
    }

    private function extractTaxQuery( array $node, array &$values, array &$unknown, array &$malformed ) {
        $safe = array();
        $relation = isset( $node['relation'] ) ? strtoupper( sanitize_key( (string) $node['relation'] ) ) : '';
        if ( in_array( $relation, array( 'AND', 'OR' ), true ) ) { $safe['relation'] = $relation; }
        foreach ( $node as $key => $clause ) {
            if ( 'relation' === $key || ! is_array( $clause ) ) { continue; }
            if ( isset( $clause['taxonomy'] ) ) {
                $taxonomy = sanitize_key( (string) $clause['taxonomy'] );
                $field = sanitize_key( (string) ( $clause['field'] ?? 'term_id' ) );
                $operator = strtoupper( trim( (string) ( $clause['operator'] ?? 'IN' ) ) );
                if ( '' === $taxonomy ) { $malformed[] = 'taxonomy_empty'; continue; }
                if ( ! in_array( $taxonomy, $this->allowedTaxonomies, true ) ) {
                    $unknown[ $taxonomy ] = true;
                    continue;
                }
                if ( ! in_array( $operator, array( 'IN', 'AND' ), true ) ) {
                    $malformed[] = 'unsupported_tax_operator:' . strtolower( str_replace( ' ', '_', $operator ) ) . ':' . $taxonomy;
                    continue;
                }
                $terms = $this->normalizeTerms( $taxonomy, $field, $clause['terms'] ?? array(), $malformed );
                if ( ! $terms ) { continue; }
                $values[ $taxonomy ] = array_values( array_unique( array_merge( (array) ( $values[ $taxonomy ] ?? array() ), $terms ) ) );
                $safe[] = array(
                    'taxonomy' => $taxonomy,
                    'field' => 'slug',
                    'terms' => $terms,
                    'operator' => $operator,
                    'include_children' => ! isset( $clause['include_children'] ) || (bool) $clause['include_children'],
                );
                continue;
            }
            $nested = $this->extractTaxQuery( $clause, $values, $unknown, $malformed );
            if ( $nested ) { $safe[] = $nested; }
        }
        return $safe;
    }

    private function inspectTaxQueryLogic( array $node, array &$malformed ): array {
        $relation = isset( $node['relation'] ) ? strtoupper( sanitize_key( (string) $node['relation'] ) ) : 'AND';
        $taxonomies = array();
        foreach ( $node as $key => $clause ) {
            if ( 'relation' === $key || ! is_array( $clause ) ) { continue; }
            if ( isset( $clause['taxonomy'] ) ) {
                $taxonomy = sanitize_key( (string) $clause['taxonomy'] );
                if ( '' !== $taxonomy ) { $taxonomies[ $taxonomy ] = true; }
                continue;
            }
            foreach ( $this->inspectTaxQueryLogic( $clause, $malformed ) as $taxonomy ) { $taxonomies[ $taxonomy ] = true; }
        }
        if ( 'OR' === $relation && count( $taxonomies ) > 1 ) {
            $malformed[] = 'cross_taxonomy_or_unsupported';
        }
        return array_keys( $taxonomies );
    }

    private function unsupportedFilterProps( array $query ): array {
        $keys = array(
            'meta_query','meta_key','meta_value','meta_value_num','date_query','s','search_terms',
            'author','author_name','author__in','author__not_in','post__in','post__not_in','p','page_id','name','pagename',
            'year','monthnum','day','w','m','category__in','category__not_in','category__and','tag__in','tag__not_in','tag__and','tag_slug__in','tag_slug__and'
        );
        $out = array();
        foreach ( $keys as $key ) {
            if ( ! array_key_exists( $key, $query ) ) { continue; }
            $value = $query[ $key ];
            if ( array() === $value || '' === $value || null === $value || false === $value ) { continue; }
            $out[] = sanitize_key( $key );
        }
        return array_values( array_unique( $out ) );
    }

    private function normalizeTerms( string $taxonomy, string $field, $raw, array &$malformed ): array {
        $items = is_array( $raw ) ? $raw : ( is_scalar( $raw ) ? preg_split( '/[,|]+/', (string) $raw ) : array() );
        $out = array();
        foreach ( array_slice( (array) $items, 0, self::MAX_TERMS_PER_TAXONOMY ) as $value ) {
            if ( is_array( $value ) || is_object( $value ) ) { continue; }
            $slug = '';
            if ( in_array( $field, array( 'term_id', 'id' ), true ) && is_numeric( $value ) && function_exists( 'get_term' ) ) {
                $term = get_term( (int) $value, $taxonomy );
                if ( is_object( $term ) && ! empty( $term->slug ) ) { $slug = sanitize_title( (string) $term->slug ); }
            } elseif ( 'name' === $field && function_exists( 'get_term_by' ) ) {
                $term = get_term_by( 'name', sanitize_text_field( (string) $value ), $taxonomy );
                if ( is_object( $term ) && ! empty( $term->slug ) ) { $slug = sanitize_title( (string) $term->slug ); }
            } else {
                $slug = sanitize_title( (string) $value );
            }
            if ( '' === $slug ) { $malformed[] = 'term_unresolved:' . $taxonomy; continue; }
            $out[] = $slug;
        }
        return array_values( array_unique( $out ) );
    }

    private function sanitizeTree( $value, int $depth ) {
        if ( $depth > self::MAX_QUERY_DEPTH || $this->nodeCount >= self::MAX_QUERY_NODES ) { return array(); }
        $this->nodeCount++;
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $key => $item ) {
                if ( $this->nodeCount >= self::MAX_QUERY_NODES ) { break; }
                $safeKey = is_int( $key ) ? $key : preg_replace( '/[^A-Za-z0-9_\-|:.]/', '', (string) $key );
                if ( '' === (string) $safeKey && ! is_int( $safeKey ) ) { continue; }
                $out[ $safeKey ] = $this->sanitizeTree( $item, $depth + 1 );
            }
            return $out;
        }
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { return $value; }
        if ( is_string( $value ) ) { return substr( sanitize_text_field( $value ), 0, 500 ); }
        return '';
    }

    private function normalizePath( string $path ): string {
        $pathOnly = parse_url( $path, PHP_URL_PATH );
        $pathOnly = is_string( $pathOnly ) ? rawurldecode( $pathOnly ) : '/';
        $pathOnly = '/' . trim( preg_replace( '#/+#', '/', $pathOnly ), '/' ) . '/';
        return '//' === $pathOnly ? '/' : $pathOnly;
    }
}
