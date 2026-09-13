<?php
namespace ETG\DynamicFilterSEOBridge\Acceptance;

final class CaseSelector {
    const CONTRACT = 'etg.dfsb.acceptance-case-selection.v1';
    const MAX_TAXONOMIES = 8;
    const MAX_TERMS_PER_TAXONOMY = 5;

    private $termsProvider;

    public function __construct( callable $termsProvider = null ) {
        $this->termsProvider = $termsProvider;
    }

    public function select( array $profile ): array {
        $rules = isset( $profile['taxonomy_rules'] ) && is_array( $profile['taxonomy_rules'] ) ? $profile['taxonomy_rules'] : array();
        $allowed = $this->allowedTaxonomies( $profile, $rules );
        $ranked = array();
        foreach ( $allowed as $taxonomy ) {
            $rule = isset( $rules[ $taxonomy ] ) && is_array( $rules[ $taxonomy ] ) ? $rules[ $taxonomy ] : array();
            $ranked[] = array(
                'taxonomy' => $taxonomy,
                'priority' => isset( $rule['priority'] ) && is_numeric( $rule['priority'] ) ? (int) $rule['priority'] : 100,
            );
        }
        usort( $ranked, static function ( array $a, array $b ): int {
            if ( $a['priority'] === $b['priority'] ) return strcmp( $a['taxonomy'], $b['taxonomy'] );
            return $a['priority'] < $b['priority'] ? -1 : 1;
        } );

        $candidates = array();
        $reasons = array();
        foreach ( array_slice( $ranked, 0, self::MAX_TAXONOMIES ) as $entry ) {
            $taxonomy = (string) $entry['taxonomy'];
            $terms = $this->terms( $taxonomy );
            if ( ! $terms ) {
                $reasons[] = 'no_real_terms:' . $taxonomy;
                continue;
            }
            $rank = 0;
            foreach ( array_slice( $terms, 0, self::MAX_TERMS_PER_TAXONOMY ) as $term ) {
                $normalized = $this->normalizeTerm( $term, $taxonomy );
                if ( ! $normalized ) continue;
                ++$rank;
                $normalized['selection_rank'] = $rank;
                $normalized['selection_reason'] = 'deterministic_real_term';
                $candidates[] = $normalized;
            }
        }

        return array(
            'contract' => self::CONTRACT,
            'authorizing' => false,
            'read_only' => true,
            'profile_mutation' => false,
            'candidates' => $candidates,
            'candidate_count' => count( $candidates ),
            'blocking_reasons' => $candidates ? array() : array_values( array_unique( $reasons ?: array( 'no_allowed_taxonomy_cases' ) ) ),
            'limits' => array(
                'max_taxonomies' => self::MAX_TAXONOMIES,
                'max_terms_per_taxonomy' => self::MAX_TERMS_PER_TAXONOMY,
            ),
        );
    }

    private function allowedTaxonomies( array $profile, array $rules ): array {
        $allowed = array();
        foreach ( (array) ( $profile['allowed_taxonomy_sets'] ?? array() ) as $set ) {
            if ( is_string( $set ) ) $items = preg_split( '/[+;,]+/', $set );
            elseif ( is_array( $set ) ) $items = $set;
            else $items = array();
            foreach ( (array) $items as $item ) {
                $taxonomy = $this->cleanKey( $item );
                if ( '' !== $taxonomy && isset( $rules[ $taxonomy ] ) ) $allowed[ $taxonomy ] = true;
            }
        }
        return array_keys( $allowed );
    }

    private function terms( string $taxonomy ): array {
        try {
            if ( $this->termsProvider ) {
                $terms = call_user_func( $this->termsProvider, $taxonomy, self::MAX_TERMS_PER_TAXONOMY );
            } elseif ( function_exists( 'get_terms' ) ) {
                $terms = get_terms( array(
                    'taxonomy' => $taxonomy,
                    'hide_empty' => true,
                    'number' => self::MAX_TERMS_PER_TAXONOMY,
                    'orderby' => 'term_id',
                    'order' => 'ASC',
                ) );
                if ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) return array();
            } else return array();
        } catch ( \Throwable $e ) { return array(); }
        if ( $terms instanceof \Traversable ) $terms = iterator_to_array( $terms, false );
        return is_array( $terms ) ? array_values( $terms ) : array();
    }

    private function normalizeTerm( $term, string $taxonomy ): array {
        if ( is_object( $term ) ) {
            $id = isset( $term->term_id ) ? (int) $term->term_id : 0;
            $slug = isset( $term->slug ) ? $this->cleanSlug( $term->slug ) : '';
            $name = isset( $term->name ) ? trim( (string) $term->name ) : '';
        } elseif ( is_array( $term ) ) {
            $id = isset( $term['term_id'] ) ? (int) $term['term_id'] : ( isset( $term['id'] ) ? (int) $term['id'] : 0 );
            $slug = isset( $term['slug'] ) ? $this->cleanSlug( $term['slug'] ) : '';
            $name = isset( $term['name'] ) ? trim( (string) $term['name'] ) : '';
        } else return array();
        if ( $id < 1 || '' === $slug ) return array();
        return array(
            'taxonomy' => $taxonomy,
            'term_id' => $id,
            'term_slug' => $slug,
            'term_name' => $name,
        );
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
}
