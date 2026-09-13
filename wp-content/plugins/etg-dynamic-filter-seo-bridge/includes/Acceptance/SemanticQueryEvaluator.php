<?php
namespace ETG\DynamicFilterSEOBridge\Acceptance;

use ETG\DynamicFilterSEOBridge\Runtime\RuntimeQueryBindingResolver;

final class SemanticQueryEvaluator {
    const CONTRACT = 'etg.dfsb.semantic-query-evaluation.v1';
    const MAX_IDS = 100;

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
            $ids = array();
            $idsComplete = false;
            $idsReason = 'query_items_method_unavailable';
            if ( method_exists( $query, 'get_items' ) ) {
                $items = $query->get_items();
                if ( $items instanceof \Traversable ) $items = iterator_to_array( $items, false );
                if ( is_array( $items ) ) {
                    $unknown = false;
                    foreach ( array_slice( array_values( $items ), 0, self::MAX_IDS ) as $item ) {
                        $id = $this->itemId( $item );
                        if ( null === $id ) { $unknown = true; continue; }
                        $ids[] = $id;
                    }
                    $ids = array_values( $ids );
                    $idsComplete = ! $unknown && $total <= self::MAX_IDS && count( $ids ) === $total;
                    $idsReason = $idsComplete ? 'complete' : ( $total > self::MAX_IDS ? 'total_exceeds_id_ceiling' : 'query_items_not_complete' );
                }
            }
            return array(
                'contract' => self::CONTRACT,
                'state' => 'ok',
                'authorizing' => false,
                'read_only' => true,
                'provider' => $provider,
                'query_id' => $queryId,
                'total' => $total,
                'ids' => $ids,
                'ids_complete' => $idsComplete,
                'ids_scope' => $idsComplete ? 'full_result_set' : 'bounded_query_items',
                'ids_reason' => $idsReason,
                'max_ids' => self::MAX_IDS,
                'binding' => $this->bindingEvidence( $binding ),
                'blocking_reasons' => array(),
            );
        } catch ( \Throwable $e ) {
            return $this->blocked( 'query_evaluation_exception', $provider, $queryId, $binding );
        }
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
            'max_ids' => self::MAX_IDS,
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
