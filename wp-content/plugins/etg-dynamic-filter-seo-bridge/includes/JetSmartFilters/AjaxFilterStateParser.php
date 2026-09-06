<?php
namespace ETG\DynamicFilterSEOBridge\JetSmartFilters;

require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';

use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;

final class AjaxFilterStateParser {
    const CONTRACT = 'etg.dfsb.ajax-filter-state.v1';
    const MAX_QUERY_DEPTH = 8;
    const MAX_QUERY_NODES = 500;
    const MAX_TERMS_PER_TAXONOMY = 20;
    const MAX_QUERY_STRING_BYTES = 500;

    private $allowedTaxonomies;
    private $nodeCount = 0;
    private $limitReasons = array();

    public function __construct( array $allowedTaxonomies = array() ) {
        $this->allowedTaxonomies = array_values( array_unique( array_filter( array_map( 'sanitize_key', $allowedTaxonomies ) ) ) );
    }

    public function parse( array $payload ): array {
        $this->nodeCount = 0;
        $this->limitReasons = array();
        $provider = sanitize_key( (string) ( $payload['provider'] ?? '' ) );
        $queryIdRaw = $payload['query_id'] ?? '';
        $queryId = QueryId::normalize( $queryIdRaw );
        $requestPath = $this->normalizePath( (string) ( $payload['request_path'] ?? $payload['archive_path'] ?? '/' ) );
        $archivePath = $this->baseArchivePath( $this->normalizePath( (string) ( $payload['archive_path'] ?? $requestPath ) ) );
        $bits = array_values( array_filter( explode( '/', trim( $archivePath, '/' ) ), 'strlen' ) );
        $archive = $bits ? sanitize_title( (string) end( $bits ) ) : '';
        $rawQuery = is_array( $payload['current_query'] ?? null ) ? (array) $payload['current_query'] : array();
        $sanitized = $this->sanitizeTree( $rawQuery, 0 );
        $query = is_array( $sanitized ) ? $sanitized : array();
        $values = array();
        $unknown = array();
        $malformed = $this->limitReasons;
        $unsupported = $this->unsupportedFilterProps( $query );

        // WordPress-style tax_query is retained for backward compatibility and server-side simulations.
        $taxQuery = is_array( $query['tax_query'] ?? null ) ? (array) $query['tax_query'] : array();
        if ( $taxQuery ) { $this->inspectTaxQueryLogic( $taxQuery, $malformed ); }
        $safeTaxQuery = $this->extractTaxQuery( $taxQuery, $values, $unknown, $malformed );

        // JetSmartFilters browser currentQuery uses keys such as _tax_query_location_jet.
        $nativeTaxQuery = $this->extractNativeJetSmartFiltersTaxQuery( $query, $values, $unknown, $malformed );

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
            if ( count( $terms ) > self::MAX_TERMS_PER_TAXONOMY ) {
                $malformed[] = 'terms_limit_exceeded:' . sanitize_key( (string) $taxonomy );
                continue;
            }
            $values[ $taxonomy ] = $terms;
            $filters[ $taxonomy ] = (string) $terms[0];
            if ( count( $terms ) > 1 ) { $multi[] = $taxonomy; }
        }
        ksort( $filters, SORT_STRING );
        ksort( $values, SORT_STRING );
        ksort( $unknown, SORT_STRING );
        sort( $unsupported, SORT_STRING );

        $combinedTaxQuery = $this->combineTaxQueries( $safeTaxQuery, $nativeTaxQuery );
        $filteredQuery = array();
        if ( $combinedTaxQuery ) { $filteredQuery['tax_query'] = $combinedTaxQuery; }

        if ( '' === $provider ) { $malformed[] = 'missing_provider'; }
        if ( '' === $queryId ) {
            $malformed[] = is_scalar( $queryIdRaw ) && '' === trim( (string) $queryIdRaw ) ? 'missing_query_id' : 'query_id_malformed';
        }
        $malformed = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $malformed ) ) ) );
        $filteredQueryComplete = empty( $unknown ) && empty( $malformed ) && empty( $unsupported );
        $active = '' !== $provider && '' !== $queryId && ( ! empty( $filters ) || ! empty( $unsupported ) || ! empty( $unknown ) || ! empty( $malformed ) );

        return array(
            'contract' => self::CONTRACT,
            'active' => $active,
            'state_transport' => 'ajax',
            'url_authority' => false,
            'authorizing' => false,
            'request_path' => $requestPath,
            'archive_path' => $archivePath,
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

    private function extractNativeJetSmartFiltersTaxQuery( array $query, array &$values, array &$unknown, array &$malformed ): array {
        $safe = array();
        foreach ( $query as $rawKey => $rawValue ) {
            if ( ! is_string( $rawKey ) || 0 !== strpos( $rawKey, '_tax_query_' ) ) { continue; }
            $tail = substr( $rawKey, strlen( '_tax_query_' ) );
            if ( false !== strpos( $tail, '|' ) ) {
                $parts = explode( '|', $tail, 2 );
                $taxonomy = sanitize_key( (string) $parts[0] );
                $malformed[] = 'native_tax_suffix_unsupported:' . ( $taxonomy ?: 'unknown' );
                continue;
            }
            $taxonomy = sanitize_key( $tail );
            if ( '' === $taxonomy ) { $malformed[] = 'taxonomy_empty'; continue; }
            if ( ! in_array( $taxonomy, $this->allowedTaxonomies, true ) ) { $unknown[ $taxonomy ] = true; continue; }

            $items = is_array( $rawValue ) ? array_values( $rawValue ) : ( is_scalar( $rawValue ) ? preg_split( '/[,]+/', (string) $rawValue ) : array() );
            if ( count( (array) $items ) > self::MAX_TERMS_PER_TAXONOMY + 1 ) {
                $malformed[] = 'terms_limit_exceeded:' . $taxonomy;
                continue;
            }
            $operator = 'IN';
            $termItems = array();
            foreach ( (array) $items as $item ) {
                if ( is_array( $item ) || is_object( $item ) ) { $malformed[] = 'native_tax_value_malformed:' . $taxonomy; continue 2; }
                $text = trim( (string) $item );
                if ( 0 === stripos( $text, 'operator_' ) ) {
                    $candidate = strtoupper( str_replace( '_', ' ', substr( $text, strlen( 'operator_' ) ) ) );
                    $candidate = preg_replace( '/\s+/', ' ', $candidate );
                    $operator = is_string( $candidate ) ? trim( $candidate ) : '';
                    continue;
                }
                if ( '' !== $text ) { $termItems[] = $text; }
            }
            if ( ! in_array( $operator, array( 'IN', 'AND' ), true ) ) {
                $malformed[] = 'unsupported_tax_operator:' . strtolower( str_replace( ' ', '_', $operator ) ) . ':' . $taxonomy;
                continue;
            }
            $terms = $this->normalizeTerms( $taxonomy, 'term_id_or_slug', $termItems, $malformed );
            if ( ! $terms ) { continue; }
            $values[ $taxonomy ] = array_values( array_unique( array_merge( (array) ( $values[ $taxonomy ] ?? array() ), $terms ) ) );
            $safe[] = array(
                'taxonomy' => $taxonomy,
                'field' => 'slug',
                'terms' => $terms,
                'operator' => $operator,
                'include_children' => true,
            );
        }
        return $safe;
    }

    private function combineTaxQueries( array $wpStyle, array $native ): array {
        if ( ! $wpStyle ) { return $native; }
        if ( ! $native ) { return $wpStyle; }
        return array( 'relation'=>'AND', $wpStyle, array_merge( array( 'relation'=>'AND' ), $native ) );
    }

    private function extractTaxQuery( array $node, array &$values, array &$unknown, array &$malformed ) {
        $safe = array();
        $relationValue = $node['relation'] ?? '';
        if ( is_array( $relationValue ) || is_object( $relationValue ) ) { $malformed[] = 'tax_relation_malformed'; $relationValue = ''; }
        $relation = strtoupper( sanitize_key( (string) $relationValue ) );
        if ( in_array( $relation, array( 'AND', 'OR' ), true ) ) { $safe['relation'] = $relation; }
        foreach ( $node as $key => $clause ) {
            if ( 'relation' === $key || ! is_array( $clause ) ) { continue; }
            if ( isset( $clause['taxonomy'] ) ) {
                $taxonomyValue = $clause['taxonomy'];
                if ( is_array( $taxonomyValue ) || is_object( $taxonomyValue ) ) { $malformed[] = 'taxonomy_malformed'; continue; }
                $taxonomy = sanitize_key( (string) $taxonomyValue );
                $fieldValue = $clause['field'] ?? 'term_id';
                $operatorValue = $clause['operator'] ?? 'IN';
                if ( is_array( $fieldValue ) || is_object( $fieldValue ) || is_array( $operatorValue ) || is_object( $operatorValue ) ) { $malformed[] = 'tax_clause_malformed:' . $taxonomy; continue; }
                $field = sanitize_key( (string) $fieldValue );
                $operator = strtoupper( trim( (string) $operatorValue ) );
                if ( '' === $taxonomy ) { $malformed[] = 'taxonomy_empty'; continue; }
                if ( ! in_array( $taxonomy, $this->allowedTaxonomies, true ) ) { $unknown[ $taxonomy ] = true; continue; }
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
        $relationValue = $node['relation'] ?? 'AND';
        if ( is_array( $relationValue ) || is_object( $relationValue ) ) { $malformed[] = 'tax_relation_malformed'; $relationValue = 'AND'; }
        $relation = strtoupper( sanitize_key( (string) $relationValue ) );
        $taxonomies = array();
        foreach ( $node as $key => $clause ) {
            if ( 'relation' === $key || ! is_array( $clause ) ) { continue; }
            if ( isset( $clause['taxonomy'] ) ) {
                if ( is_array( $clause['taxonomy'] ) || is_object( $clause['taxonomy'] ) ) { $malformed[] = 'taxonomy_malformed'; continue; }
                $taxonomy = sanitize_key( (string) $clause['taxonomy'] );
                if ( '' !== $taxonomy ) { $taxonomies[ $taxonomy ] = true; }
                continue;
            }
            foreach ( $this->inspectTaxQueryLogic( $clause, $malformed ) as $taxonomy ) { $taxonomies[ $taxonomy ] = true; }
        }
        if ( 'OR' === $relation && count( $taxonomies ) > 1 ) { $malformed[] = 'cross_taxonomy_or_unsupported'; }
        return array_keys( $taxonomies );
    }

    private function unsupportedFilterProps( array $query ): array {
        $knownUnsupported = array(
            'meta_query','meta_key','meta_value','meta_value_num','date_query','s','search_terms',
            'author','author_name','author__in','author__not_in','post__in','post__not_in','p','page_id','name','pagename',
            'year','monthnum','day','w','m','category__in','category__not_in','category__and','tag__in','tag__not_in','tag__and','tag_slug__in','tag_slug__and'
        );
        $out = array();
        foreach ( $knownUnsupported as $key ) {
            if ( ! array_key_exists( $key, $query ) ) { continue; }
            $value = $query[ $key ];
            if ( array() === $value || '' === $value || null === $value || false === $value ) { continue; }
            $out[] = sanitize_key( $key );
        }

        foreach ( $query as $rawKey => $value ) {
            if ( ! is_string( $rawKey ) || array() === $value || '' === $value || null === $value || false === $value ) { continue; }
            if ( 'tax_query' === $rawKey || in_array( sanitize_key( $rawKey ), $this->allowedTaxonomies, true ) || 0 === strpos( $rawKey, '_tax_query_' ) ) { continue; }
            // Transport/order controls do not change the semantic filter set or total result count.
            if ( in_array( $rawKey, array( 'hc','paged','jet_paged' ), true ) || 0 === strpos( $rawKey, '_pagenum_' ) || 0 === strpos( $rawKey, '_sort_' ) ) { continue; }
            if ( 0 === strpos( $rawKey, '_meta_query_' ) ) { $out[] = 'native_meta_query'; continue; }
            if ( 0 === strpos( $rawKey, '_date_query_' ) ) { $out[] = 'native_date_query'; continue; }
            if ( 0 === strpos( $rawKey, '__s_query' ) ) { $out[] = 'native_search'; continue; }
            if ( 0 === strpos( $rawKey, '_alphabet_' ) ) { $out[] = 'native_alphabet'; continue; }
            if ( in_array( $rawKey, $knownUnsupported, true ) ) { continue; }
            $normalized = sanitize_key( $rawKey );
            $out[] = 'unknown_query_prop_' . ( $normalized ?: 'invalid' );
        }
        return array_values( array_unique( $out ) );
    }

    private function normalizeTerms( string $taxonomy, string $field, $raw, array &$malformed ): array {
        $items = is_array( $raw ) ? array_values( $raw ) : ( is_scalar( $raw ) ? preg_split( '/[,|]+/', (string) $raw ) : array() );
        if ( count( (array) $items ) > self::MAX_TERMS_PER_TAXONOMY ) {
            $malformed[] = 'terms_limit_exceeded:' . $taxonomy;
            return array();
        }
        $out = array();
        foreach ( (array) $items as $value ) {
            if ( is_array( $value ) || is_object( $value ) ) { $malformed[] = 'term_value_malformed:' . $taxonomy; continue; }
            $slug = '';
            $numericField = in_array( $field, array( 'term_id', 'id', 'term_id_or_slug' ), true );
            if ( $numericField && is_numeric( $value ) && function_exists( 'get_term' ) ) {
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
        if ( $depth > self::MAX_QUERY_DEPTH ) { $this->limitReasons[] = 'query_depth_limit_exceeded'; return null; }
        if ( $this->nodeCount >= self::MAX_QUERY_NODES ) { $this->limitReasons[] = 'query_node_limit_exceeded'; return null; }
        $this->nodeCount++;
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $key => $item ) {
                if ( $this->nodeCount >= self::MAX_QUERY_NODES ) { $this->limitReasons[] = 'query_node_limit_exceeded'; break; }
                $safeKey = is_int( $key ) ? $key : preg_replace( '/[^A-Za-z0-9_\-|:.]/', '', (string) $key );
                if ( '' === (string) $safeKey && ! is_int( $safeKey ) ) { $this->limitReasons[] = 'query_key_invalid'; continue; }
                $sanitized = $this->sanitizeTree( $item, $depth + 1 );
                if ( null === $sanitized && in_array( 'query_depth_limit_exceeded', $this->limitReasons, true ) ) { continue; }
                $out[ $safeKey ] = $sanitized;
            }
            return $out;
        }
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { return $value; }
        if ( is_string( $value ) ) {
            if ( strlen( $value ) > self::MAX_QUERY_STRING_BYTES ) { $this->limitReasons[] = 'query_string_limit_exceeded'; }
            return substr( sanitize_text_field( $value ), 0, self::MAX_QUERY_STRING_BYTES );
        }
        if ( null !== $value ) { $this->limitReasons[] = 'query_value_type_unsupported'; }
        return '';
    }

    private function normalizePath( string $path ): string {
        $pathOnly = parse_url( $path, PHP_URL_PATH );
        $pathOnly = is_string( $pathOnly ) ? rawurldecode( $pathOnly ) : '/';
        $pathOnly = '/' . trim( preg_replace( '#/+#', '/', $pathOnly ), '/' ) . '/';
        return '//' === $pathOnly ? '/' : $pathOnly;
    }

    private function baseArchivePath( string $path ): string {
        $marker = strpos( $path, '/jsf/' );
        if ( false !== $marker ) { $path = substr( $path, 0, $marker ); }
        $path = '/' . trim( preg_replace( '#/+#', '/', $path ), '/' );
        return '/' === $path ? '/' : $path . '/';
    }
}
