<?php
namespace ETG\DynamicFilterSEOBridge\Runtime;

require_once dirname( __DIR__ ) . '/JetEngine/QueryIdentityResolver.php';
require_once __DIR__ . '/RuntimeTopologyDiscoverer.php';

use ETG\DynamicFilterSEOBridge\JetEngine\QueryIdentityResolver;

final class RuntimeQueryBindingResolver {
    private $topology;
    private $identity;

    public function __construct( RuntimeTopologyDiscoverer $topology = null, QueryIdentityResolver $identity = null ) {
        $this->topology = $topology ?: new RuntimeTopologyDiscoverer();
        $this->identity = $identity ?: new QueryIdentityResolver();
    }

    public function capable(): bool { return $this->identity->capable(); }

    public function resolve( string $provider, string $providerQueryId, array $profile = array() ): array {
        $provider = sanitize_key( $provider );
        $providerQueryId = sanitize_key( $providerQueryId );
        if ( '' === $providerQueryId ) { return $this->failure( 'missing_provider_query_id', $provider, $providerQueryId ); }
        if ( 'jet-engine' !== $provider ) { return $this->failure( 'unsupported_provider', $provider, $providerQueryId ); }

        // Backward-compatible fast path: some sites use the same identifier in
        // JetSmartFilters and Query Builder. Exact custom-ID resolution remains
        // the only authority; an internal numeric ID is never a fallback.
        $direct = $this->identity->resolve( $providerQueryId );
        if ( ! empty( $direct['resolved'] ) ) {
            return $this->success( $provider, $providerQueryId, $direct, 'provider_query_id_equals_query_builder_custom_id', array() );
        }

        $topology = $this->topology->discover();
        $matches = array();
        $blocked = array();
        foreach ( (array) ( $topology['bindings'] ?? array() ) as $binding ) {
            if ( ! is_array( $binding ) ) { continue; }
            if ( $provider !== sanitize_key( (string) ( $binding['provider'] ?? '' ) ) ) { continue; }
            if ( $providerQueryId !== sanitize_key( (string) ( $binding['provider_query_id'] ?? '' ) ) ) { continue; }
            if ( 'verified' === (string) ( $binding['status'] ?? '' ) ) { $matches[] = $binding; }
            else { $blocked[] = $binding; }
        }

        $unique = array();
        foreach ( $matches as $binding ) {
            $custom = sanitize_key( (string) ( $binding['query_builder_custom_query_id'] ?? '' ) );
            if ( '' === $custom ) { continue; }
            if ( ! isset( $unique[$custom] ) ) { $unique[$custom] = array(); }
            $unique[$custom][] = $binding;
        }
        if ( 1 !== count( $unique ) ) {
            if ( count( $unique ) > 1 ) { return $this->failure( 'topology_binding_ambiguous', $provider, $providerQueryId, count( $unique ), $topology, $matches ); }
            if ( $blocked ) {
                $reason = sanitize_key( (string) ( $blocked[0]['reason'] ?? 'topology_binding_blocked' ) );
                return $this->failure( $reason ?: 'topology_binding_blocked', $provider, $providerQueryId, 0, $topology, $blocked );
            }
            return $this->failure( ! empty( $topology['available'] ) ? 'topology_binding_not_found' : 'runtime_topology_unavailable', $provider, $providerQueryId, 0, $topology );
        }

        $customId = (string) array_key_first( $unique );
        $identity = $this->identity->resolve( $customId );
        if ( empty( $identity['resolved'] ) ) {
            return $this->failure( (string) ( $identity['reason'] ?? 'query_identity_inventory_unavailable' ), $provider, $providerQueryId, (int) ( $identity['match_count'] ?? 0 ), $topology, reset( $unique ) );
        }
        return $this->success( $provider, $providerQueryId, $identity, 'elementor_runtime_topology', reset( $unique ) );
    }

    private function success( string $provider, string $providerQueryId, array $identity, string $source, array $evidence ): array {
        return array(
            'resolved' => true,
            'reason' => 'resolved',
            'provider' => $provider,
            'provider_query_id' => $providerQueryId,
            'query_builder_custom_query_id' => (string) ( $identity['custom_query_id'] ?? '' ),
            'query_builder_internal_id' => (string) ( $identity['internal_query_id'] ?? '' ),
            'query' => $identity['query'] ?? null,
            'source' => $source,
            'identity_source' => (string) ( $identity['source'] ?? 'unavailable' ),
            'match_count' => 1,
            'evidence' => array_slice( $evidence, 0, 20 ),
        );
    }

    private function failure( string $reason, string $provider, string $providerQueryId, int $matchCount = 0, array $topology = array(), array $evidence = array() ): array {
        return array(
            'resolved' => false,
            'reason' => sanitize_key( $reason ) ?: 'unavailable',
            'provider' => $provider,
            'provider_query_id' => $providerQueryId,
            'query_builder_custom_query_id' => '',
            'query_builder_internal_id' => '',
            'query' => null,
            'source' => 'unavailable',
            'identity_source' => 'unavailable',
            'match_count' => $matchCount,
            'topology_available' => ! empty( $topology['available'] ),
            'evidence' => array_slice( $evidence, 0, 20 ),
        );
    }
}
