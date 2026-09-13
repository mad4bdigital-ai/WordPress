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
        $queryPrototype = $binding['query'] ?? null;
        if ( empty( $binding['resolved'] ) || ! is_object( $queryPrototype ) ) {
            return $this->blocked( (string) ( $binding['reason'] ?? 'query_binding_unresolved' ), $provider, $queryId, $binding );
        }
        if ( ! method_exists( $queryPrototype, 'setup_query' ) || ! method_exists( $queryPrototype, 'set_filtered_prop' ) || ! method_exists( $queryPrototype, 'get_items_total_count' ) ) {
            return $this->blocked( 'query_runtime_contract_incomplete', $provider, $queryId, $binding );
        }

        try {
            $countQuery = $this->freshQuery( $queryPrototype, $filteredQuery, 1 );
            $total = $countQuery->get_items_total_count();
            if ( ! is_numeric( $total ) ) return $this->blocked( 'non_numeric_total', $provider, $queryId, $binding );
            $total = max( 0, (int) $total );
            $collection = $this->collectIds( $queryPrototype, $filteredQuery, $total );

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
                'page_signatures' => $collection['page_signatures'],
                'raw_id_count' => $collection['raw_id_count'],
                'unique_id_count' => $collection['unique_id_count'],
                'pagination_failure' => $collection['pagination_failure'],
                'infrastructure_failure' => $collection['infrastructure_failure'],
                'max_ids' => self::MAX_IDS,
                'max_page_fetches' => self::MAX_PAGE_FETCHES,
                'binding' => $this->bindingEvidence( $binding ),
                'blocking_reasons' => array(),
            );
        } catch ( \Throwable $e ) {
            return $this->blocked( 'query_evaluation_exception', $provider, $queryId, $binding );
        }
    }

    private function collectIds( $queryPrototype, array $filteredQuery, int $total ): array {
        $result = array(
            'ids' => array(),
            'complete' => false,
            'reason' => 'query_items_method_unavailable',
            'mode' => 'unavailable',
            'items_per_page' => null,
            'page_fetches' => 0,
            'page_signatures' => array(),
            'raw_id_count' => 0,
            'unique_id_count' => 0,
            'pagination_failure' => '',
            'infrastructure_failure' => false,
        );
        if ( 0 === $total ) {
            $result['complete'] = true;
            $result['reason'] = 'complete';
            $result['mode'] = 'empty_result_set';
            return $result;
        }
        if ( ! method_exists( $queryPrototype, 'get_items' ) ) return $result;

        $pageOneQuery = $this->freshQuery( $queryPrototype, $filteredQuery, 1 );
        $perPage = 0;
        if ( method_exists( $pageOneQuery, 'get_items_per_page' ) ) {
            $value = $pageOneQuery->get_items_per_page();
            if ( is_numeric( $value ) ) $perPage = max( 0, (int) $value );
        }
        $result['items_per_page'] = $perPage ?: null;

        if ( $total > self::MAX_IDS ) {
            $page = $this->readPageIds( $pageOneQuery, self::MAX_IDS );
            $result['ids'] = $this->orderedUnique( $page['ids'] );
            $result['raw_id_count'] = count( $page['ids'] );
            $result['unique_id_count'] = count( $result['ids'] );
            $result['page_fetches'] = 1;
            $result['page_signatures'][] = $this->pageSignature( $page['ids'] );
            $result['mode'] = 'bounded_sample';
            $result['reason'] = 'total_exceeds_id_ceiling';
            return $result;
        }

        if ( $perPage <= 0 || $perPage >= $total ) {
            $page = $this->readPageIds( $pageOneQuery, self::MAX_IDS );
            $result['ids'] = $this->orderedUnique( $page['ids'] );
            $result['raw_id_count'] = count( $page['ids'] );
            $result['unique_id_count'] = count( $result['ids'] );
            $result['page_fetches'] = 1;
            $result['page_signatures'][] = $this->pageSignature( $page['ids'] );
            $result['mode'] = 'single_query_items';
            if ( $result['raw_id_count'] > $total ) $this->markInfrastructureFailure( $result, 'raw_collected_id_count_exceeds_total' );
            elseif ( $page['unknown'] ) $result['reason'] = 'query_item_identity_unavailable';
            elseif ( $page['truncated'] ) $result['reason'] = 'query_items_exceed_id_ceiling';
            elseif ( $result['unique_id_count'] === $total ) { $result['complete'] = true; $result['reason'] = 'complete'; }
            else $result['reason'] = 'query_items_not_complete';
            return $result;
        }

        $pages = (int) ceil( $total / $perPage );
        if ( $pages > self::MAX_PAGE_FETCHES ) {
            $page = $this->readPageIds( $pageOneQuery, self::MAX_IDS );
            $result['ids'] = $this->orderedUnique( $page['ids'] );
            $result['raw_id_count'] = count( $page['ids'] );
            $result['unique_id_count'] = count( $result['ids'] );
            $result['page_fetches'] = 1;
            $result['page_signatures'][] = $this->pageSignature( $page['ids'] );
            $result['mode'] = 'bounded_sample';
            $result['reason'] = 'page_fetch_ceiling_exceeded';
            return $result;
        }

        $result['mode'] = 'paged_query_items';
        $unknown = false;
        $seen = array();
        $previousSignature = null;
        for ( $pageNumber = 1; $pageNumber <= $pages; $pageNumber++ ) {
            $pageQuery = 1 === $pageNumber ? $pageOneQuery : $this->freshQuery( $queryPrototype, $filteredQuery, $pageNumber );
            if ( $pageNumber > 1 && method_exists( $pageQuery, 'get_current_items_page' ) ) {
                $reportedPage = $pageQuery->get_current_items_page();
                if ( is_numeric( $reportedPage ) && (int) $reportedPage !== $pageNumber ) {
                    $this->markInfrastructureFailure( $result, 'paged_query_items_do_not_advance' );
                    break;
                }
            }
            $remaining = self::MAX_IDS - count( $result['ids'] );
            if ( $remaining <= 0 ) { $result['reason'] = 'id_ceiling_reached_before_total'; break; }
            $page = $this->readPageIds( $pageQuery, $remaining );
            $result['page_fetches']++;
            $unknown = $unknown || $page['unknown'];
            $signature = $this->pageSignature( $page['ids'] );
            $result['page_signatures'][] = $signature;
            $result['raw_id_count'] += count( $page['ids'] );

            $newUnique = 0;
            foreach ( $page['ids'] as $id ) {
                $key = (string) $id;
                if ( isset( $seen[ $key ] ) ) continue;
                $seen[ $key ] = true;
                $result['ids'][] = $id;
                $newUnique++;
            }
            $result['unique_id_count'] = count( $result['ids'] );

            if ( $pageNumber > 1 && null !== $previousSignature && $signature === $previousSignature && $result['unique_id_count'] < $total ) {
                $this->markInfrastructureFailure( $result, 'duplicate_page_signature' );
                break;
            }
            if ( $result['raw_id_count'] > $total ) {
                $this->markInfrastructureFailure( $result, 'raw_collected_id_count_exceeds_total' );
                break;
            }
            if ( 0 === $newUnique && $result['unique_id_count'] < $total ) {
                $this->markInfrastructureFailure( $result, 'pagination_no_progress' );
                break;
            }
            if ( $page['truncated'] ) { $result['reason'] = 'query_items_exceed_id_ceiling'; break; }
            if ( $result['unique_id_count'] === $total ) {
                $result['complete'] = ! $unknown;
                $result['reason'] = $unknown ? 'query_item_identity_unavailable' : 'complete';
                break;
            }
            if ( ! $page['ids'] && $pageNumber < $pages ) { $result['reason'] = 'query_page_empty_before_total'; break; }
            $previousSignature = $signature;
        }
        if ( ! $result['complete'] && ! $result['infrastructure_failure'] && 'query_items_method_unavailable' === $result['reason'] ) {
            $result['reason'] = $unknown ? 'query_item_identity_unavailable' : 'query_items_not_complete';
        }
        return $result;
    }

    private function freshQuery( $queryPrototype, array $filteredQuery, int $pageNumber ) {
        $query = clone $queryPrototype;

        // JetEngine Query Builder instances can be reused by the manager and may
        // already carry final_query/current_query state from an earlier page.
        // A semantic acceptance page fetch must start from the immutable query
        // definition, not from a previously evaluated runtime instance.
        if ( method_exists( $query, 'reset_query' ) ) $query->reset_query();
        if ( property_exists( $query, 'final_query' ) ) $query->final_query = null;
        if ( property_exists( $query, 'current_query' ) ) $query->current_query = null;

        $query->setup_query();
        foreach ( $filteredQuery as $prop => $value ) {
            $prop = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $prop );
            if ( '' !== $prop ) $query->set_filtered_prop( $prop, $value );
        }
        if ( $pageNumber > 1 ) $query->set_filtered_prop( '_page', $pageNumber );
        return $query;
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

    private function orderedUnique( array $ids ): array {
        $result = array();
        $seen = array();
        foreach ( $ids as $id ) {
            $key = (string) $id;
            if ( isset( $seen[ $key ] ) ) continue;
            $seen[ $key ] = true;
            $result[] = $id;
        }
        return $result;
    }

    private function pageSignature( array $ids ): string {
        return hash( 'sha256', implode( ',', array_map( 'strval', array_values( $ids ) ) ) );
    }

    private function markInfrastructureFailure( array &$result, string $reason ): void {
        $result['complete'] = false;
        $result['reason'] = $reason;
        $result['pagination_failure'] = $reason;
        $result['infrastructure_failure'] = true;
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
            'page_signatures' => array(),
            'raw_id_count' => 0,
            'unique_id_count' => 0,
            'pagination_failure' => '',
            'infrastructure_failure' => false,
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
