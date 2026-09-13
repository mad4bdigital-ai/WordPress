<?php
namespace ETG\DynamicFilterSEOBridge\Acceptance;

use ETG\DynamicFilterSEOBridge\Runtime\RuntimeQueryBindingResolver;

final class SemanticQueryEvaluator {
    const CONTRACT = 'etg.dfsb.semantic-query-evaluation.v2';
    const MAX_IDS = 100;
    const MAX_PAGE_FETCHES = 20;

    private $bindingResolver;
    private $bindingProvider;

    public function __construct( RuntimeQueryBindingResolver $bindingResolver = null, callable $bindingProvider = null ) {
        $this->bindingResolver = $bindingResolver;
        $this->bindingProvider = $bindingProvider;
    }

    public function evaluate( array $state, array $profile, array $filteredQuery ): array {
        $provider = $this->cleanKey( $state['provider'] ?? '' );
        $queryId = $this->cleanIdentifier( $state['query_id'] ?? '' );
        if ( 'jet-engine' !== $provider ) return $this->blocked( 'unsupported_provider', $provider, $queryId );
        if ( '' === $queryId ) return $this->blocked( 'missing_query_id', $provider, $queryId );
        if ( ! $filteredQuery ) return $this->blocked( 'filtered_query_unavailable', $provider, $queryId );

        try {
            $binding = $this->bindingProvider
                ? call_user_func( $this->bindingProvider, $provider, $queryId, $profile )
                : ( $this->bindingResolver ? $this->bindingResolver->resolve( $provider, $queryId, $profile ) : array() );
        } catch ( \Throwable $e ) {
            return $this->blocked( 'query_binding_exception', $provider, $queryId );
        }
        $binding = is_array( $binding ) ? $binding : array();
        $query = $binding['query'] ?? null;
        if ( empty( $binding['resolved'] ) || ! is_object( $query ) ) {
            return $this->blocked( (string) ( $binding['reason'] ?? 'query_binding_unresolved' ), $provider, $queryId, $binding );
        }
        if ( ! method_exists( $query, 'setup_query' ) || ! method_exists( $query, 'set_filtered_prop' ) || ! method_exists( $query, 'get_items_total_count' ) ) {
            return $this->blocked( 'query_runtime_contract_incomplete', $provider, $queryId, $binding );
        }

        try {
            $query = clone $query;
            $query->setup_query();
            foreach ( $filteredQuery as $prop => $value ) {
                $prop = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $prop );
                if ( '' !== $prop ) $query->set_filtered_prop( $prop, $value );
            }
            $total = $query->get_items_total_count();
            if ( ! is_numeric( $total ) ) return $this->blocked( 'non_numeric_total', $provider, $queryId, $binding );
            $total = max( 0, (int) $total );
            $collection = $this->collectIds( $query, $total );

            return array(
                'contract' => self::CONTRACT,
                'state' => 'ok',
                'authorizing' => false,
                'read_only' => true,
                'provider' => $provider,
                'query_id' => $queryId,
                'total' => $total,
                'ids' => $collection['ids'],
                'ids_complete' => $collection['complete'],
                'ids_scope' => $collection['complete'] ? 'full_result_set' : 'bounded_query_items',
                'ids_reason' => $collection['reason'],
                'collection_mode' => $collection['mode'],
                'items_per_page' => $collection['items_per_page'],
                'page_fetches' => $collection['page_fetches'],
                'max_ids' => self::MAX_IDS,
                'max_page_fetches' => self::MAX_PAGE_FETCHES,
                'binding' => $this->bindingEvidence( $binding ),
                'blocking_reasons' => array(),
            );
        } catch ( \Throwable $e ) {
            return $this->blocked( 'query_evaluation_exception', $provider, $queryId, $binding );
        }
    }

    private function collectIds( $query, int $total ): array {
        $result = array(
            'ids' => array(),
            'complete' => false,
            'reason' => 'query_items_method_unavailable',
            'mode' => 'unavailable',
            'items_per_page' => null,
            'page_fetches' => 0,
        );
        if ( 0 === $total ) {
            $result['complete'] = true;
            $result['reason'] = 'complete';
            $result['mode'] = 'empty_result_set';
            return $result;
        }
        if ( ! method_exists( $query, 'get_items' ) ) return $result;

        $perPage = 0;
        if ( method_exists( $query, 'get_items_per_page' ) ) {
            $value = $query->get_items_per_page();
            if ( is_numeric( $value ) ) $perPage = max( 0, (int) $value );
        }
        $result['items_per_page'] = $perPage ?: null;

        if ( $total > self::MAX_IDS ) {
            $page = $this->readPageIds( clone $query, self::MAX_IDS );
            $result['ids'] = $page['ids'];
            $result['page_fetches'] = 1;
            $result['mode'] = 'bounded_sample';
            $result['reason'] = 'total_exceeds_id_ceiling';
            return $result;
        }

        if ( $perPage <= 0 || $perPage >= $total ) {
            $page = $this->readPageIds( clone $query, self::MAX_IDS );
            $result['ids'] = $page['ids'];
            $result['page_fetches'] = 1;
            $result['mode'] = 'single_query_items';
            if ( $page['unknown'] ) $result['reason'] = 'query_item_identity_unavailable';
            elseif ( $page['truncated'] ) $result['reason'] = 'query_items_exceed_id_ceiling';
            elseif ( count( $page['ids'] ) === $total ) { $result['complete'] = true; $result['reason'] = 'complete'; }
            else $result['reason'] = 'query_items_not_complete';
            return $result;
        }

        $pages = (int) ceil( $total / $perPage );
        if ( $pages > self::MAX_PAGE_FETCHES ) {
            $page = $this->readPageIds( clone $query, self::MAX_IDS );
            $result['ids'] = $page['ids'];
            $result['page_fetches'] = 1;
            $result['mode'] = 'bounded_sample';
            $result['reason'] = 'page_fetch_ceiling_exceeded';
            return $result;
        }

        $result['mode'] = 'paged_query_items';
        $unknown = false;
        for ( $pageNumber = 1; $pageNumber <= $pages; $pageNumber++ ) {
            $pageQuery = clone $query;
            if ( $pageNumber > 1 ) $pageQuery->set_filtered_prop( '_page', $pageNumber );
            $remaining = self::MAX_IDS - count( $result['ids'] );
            if ( $remaining <= 0 ) { $result['reason'] = 'id_ceiling_reached_before_total'; break; }
            $page = $this->readPageIds( $pageQuery, $remaining );
            $result['page_fetches']++;
            $unknown = $unknown || $page['unknown'];
            foreach ( $page['ids'] as $id ) $result['ids'][] = $id;
            if ( $page['truncated'] ) { $result['reason'] = 'query_items_exceed_id_ceiling'; break; }
            if ( count( $result['ids'] ) === $total ) {
                $result['complete'] = ! $unknown;
                $result['reason'] = $unknown ? 'query_item_identity_unavailable' : 'complete';
                break;
            }
            if ( count( $result['ids'] ) > $total ) { $result['reason'] = 'query_items_exceed_total'; break; }
            if ( ! $page['ids'] && $pageNumber < $pages ) { $result['reason'] = 'query_page_empty_before_total'; break; }
        }
        if ( ! $result['complete'] && 'query_items_method_unavailable' === $result['reason'] ) {
            $result['reason'] = $unknown ? 'query_item_identity_unavailable' : 'query_items_not_complete';
        }
        return $result;
    }

    private function readPageIds( $query, int $limit ): array {
        $items = $query->get_items();
        if ( $items instanceof \Traversable ) $items = iterator_to_array( $items, false );
        if ( ! is_array( $items ) ) return array( 'ids'=>array(), 'unknown'=>true, 'truncated'=>false );
        $values = array_values( $items );
        $truncated = count( $values ) > $limit;
        $ids = array();
        $unknown = false;
        foreach ( array_slice( $values, 0, max( 0, $limit ) ) as $item ) {
            $id = $this->itemId( $item );
            if ( null === $id ) { $unknown = true; continue; }
            $ids[] = $id;
        }
        return array( 'ids'=>array_values( $ids ), 'unknown'=>$unknown, 'truncated'=>$truncated );
    }

    private function blocked( string $reason, string $provider, string $queryId, array $binding = array() ): array {
        return array(
            'contract' => self::CONTRACT,
            'state' => 'blocked',
            'authorizing' => false,
            'read_only' => true,
            'provider' => $provider,
            'query_id' => $queryId,
            'total' => null,
            'ids' => array(),
            'ids_complete' => false,
            'ids_scope' => 'unavailable',
            'ids_reason' => $reason,
            'collection_mode' => 'unavailable',
            'items_per_page' => null,
            'page_fetches' => 0,
            'max_ids' => self::MAX_IDS,
            'max_page_fetches' => self::MAX_PAGE_FETCHES,
            'binding' => $this->bindingEvidence( $binding ),
            'blocking_reasons' => array( $reason ),
        );
    }

    private function bindingEvidence( array $binding ): array {
        return array(
            'resolved' => ! empty( $binding['resolved'] ),
            'reason' => (string) ( $binding['reason'] ?? '' ),
            'provider_query_id' => (string) ( $binding['provider_query_id'] ?? '' ),
            'query_builder_custom_query_id' => (string) ( $binding['query_builder_custom_query_id'] ?? '' ),
            'query_builder_internal_id' => (string) ( $binding['query_builder_internal_id'] ?? '' ),
            'source' => (string) ( $binding['source'] ?? '' ),
            'identity_source' => (string) ( $binding['identity_source'] ?? '' ),
        );
    }

    private function itemId( $item ) {
        if ( is_numeric( $item ) ) return (int) $item;
        if ( is_object( $item ) ) {
            foreach ( array( 'ID', 'id', 'term_id' ) as $key ) if ( isset( $item->{$key} ) && is_numeric( $item->{$key} ) ) return (int) $item->{$key};
            return null;
        }
        if ( is_array( $item ) ) {
            foreach ( array( 'ID', 'id', 'term_id' ) as $key ) if ( isset( $item[ $key ] ) && is_numeric( $item[ $key ] ) ) return (int) $item[ $key ];
        }
        return null;
    }

    private function cleanKey( $value ): string {
        if ( function_exists( 'sanitize_key' ) ) return sanitize_key( (string) $value );
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?: '';
    }

    private function cleanIdentifier( $value ): string {
        $value = strtolower( trim( (string) $value ) );
        return preg_match( '/^[a-z0-9._\-]{1,80}$/', $value ) ? $value : '';
    }
}
