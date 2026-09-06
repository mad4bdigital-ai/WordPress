<?php
namespace ETG\DynamicFilterSEOBridge\SEO;

require_once dirname( __DIR__ ) . '/Runtime/RuntimeQueryBindingResolver.php';
require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';

use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;
use ETG\DynamicFilterSEOBridge\JetEngine\QueryIdentityResolver;
use ETG\DynamicFilterSEOBridge\Runtime\RuntimeQueryBindingResolver;

final class JetEngineResultCountAdapter {
    private $bindingResolver;

    public function __construct( $resolver = null ) {
        if ( $resolver instanceof RuntimeQueryBindingResolver ) { $this->bindingResolver = $resolver; }
        elseif ( $resolver instanceof QueryIdentityResolver ) { $this->bindingResolver = new RuntimeQueryBindingResolver( null, $resolver ); }
        else { $this->bindingResolver = new RuntimeQueryBindingResolver(); }
    }

    public function capable(): bool {
        if ( ! function_exists( 'jet_smart_filters' ) || ! $this->bindingResolver->capable() ) { return false; }
        $jsf = jet_smart_filters();
        return is_object( $jsf ) && isset( $jsf->query ) && is_object( $jsf->query ) && method_exists( $jsf->query, 'get_query_from_request' );
    }

    public function resolve( array $context ): array {
        if ( ! function_exists( 'jet_smart_filters' ) ) { return $this->unavailable( 'jet_smart_filters_unavailable' ); }
        $jsf = jet_smart_filters();
        if ( ! is_object( $jsf ) || ! isset( $jsf->query ) || ! is_object( $jsf->query ) || ! method_exists( $jsf->query, 'get_query_from_request' ) ) {
            return $this->unavailable( 'filtered_request_runtime_unavailable' );
        }
        $filtered = $jsf->query->get_query_from_request();
        if ( ! is_array( $filtered ) || empty( $filtered ) ) { return $this->unavailable( 'filtered_request_unavailable' ); }
        return $this->resolveFilteredQuery( $context, $filtered );
    }

    public function resolveFilteredQuery( array $context, array $filtered ): array {
        $provider = sanitize_key( (string) ( $context['provider'] ?? '' ) );
        if ( 'jet-engine' !== $provider ) { return $this->unavailable( 'unsupported_provider' ); }
        $providerMatched = ! empty( $context['provider_observation_matches_url'] ) || ! empty( $context['provider_observation_matches_state'] );
        if ( ! $providerMatched ) { return $this->unavailable( 'provider_mismatch' ); }
        $providerQueryId = QueryId::normalize( $context['query_id'] ?? '' );
        if ( '' === $providerQueryId ) { return $this->unavailable( 'missing_query_id' ); }
        if ( empty( $filtered ) ) { return $this->unavailable( 'filtered_request_unavailable', $providerQueryId ); }

        $binding = $this->bindingResolver->resolve( $provider, $providerQueryId, (array) ( $context['profile'] ?? array() ) );
        $customId = (string) ( $binding['query_builder_custom_query_id'] ?? '' );
        $internalId = (string) ( $binding['query_builder_internal_id'] ?? '' );
        if ( empty( $binding['resolved'] ) || ! is_object( $binding['query'] ?? null ) ) {
            return $this->unavailable( (string) ( $binding['reason'] ?? 'query_identity_inventory_unavailable' ), $providerQueryId, $customId, $internalId );
        }
        $query = $binding['query'];
        if ( ! method_exists( $query, 'setup_query' ) || ! method_exists( $query, 'set_filtered_prop' ) || ! method_exists( $query, 'get_items_total_count' ) ) {
            return $this->unavailable( 'query_builder_query_unavailable', $providerQueryId, $customId, $internalId );
        }
        try {
            $query = clone $query;
            $query->setup_query();
            foreach ( $filtered as $prop => $value ) {
                $prop = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $prop );
                if ( '' === $prop ) { continue; }
                $query->set_filtered_prop( $prop, $value );
            }
            $count = $query->get_items_total_count();
            if ( ! is_numeric( $count ) ) { return $this->unavailable( 'non_numeric_count', $providerQueryId, $customId, $internalId ); }
            return array(
                'count'=>max(0,(int)$count), 'source'=>'jet_engine_query_builder', 'authoritative'=>true,
                'detail'=>!empty($context['ajax_only'])?'ajax_filtered_query_builder_count':'filtered_query_builder_count',
                'provider_query_id'=>$providerQueryId, 'custom_query_id'=>$customId, 'query_builder_custom_query_id'=>$customId,
                'internal_query_id'=>$internalId, 'binding_source'=>(string)($binding['source']??'unavailable'), 'query_identity_source'=>(string)($binding['identity_source']??'unavailable')
            );
        } catch ( \Throwable $e ) { return $this->unavailable( 'adapter_exception', $providerQueryId, $customId, $internalId ); }
    }

    private function unavailable( string $detail = 'unavailable', string $providerQueryId = '', string $customQueryId = '', string $internalQueryId = '' ): array {
        return array(
            'count'=>null, 'source'=>'unavailable', 'authoritative'=>false, 'detail'=>$detail,
            'provider_query_id'=>$providerQueryId, 'custom_query_id'=>$customQueryId, 'query_builder_custom_query_id'=>$customQueryId, 'internal_query_id'=>$internalQueryId
        );
    }
}
