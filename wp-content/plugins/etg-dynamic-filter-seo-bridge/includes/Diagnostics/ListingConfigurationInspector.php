<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';

use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;

final class ListingConfigurationInspector {
    const CONTRACT = 'etg.dfsb.elementor-listing-configuration-inspection.v1';
    const MAX_TEMPLATES = 50;
    const MAX_ELEMENTS = 5000;
    const MAX_DRIFT = 100;

    public function inspectRoute( string $providerQueryId, array $topology, array $taxonomies ): array {
        $providerQueryId = QueryId::normalize( $providerQueryId );
        $base = array(
            'contract' => self::CONTRACT,
            'authorizing' => false,
            'read_only' => true,
            'profile_mutation' => false,
            'available' => false,
            'source' => 'elementor_saved_configuration_unavailable',
            'provider_query_id' => $providerQueryId,
            'templates_scanned' => 0,
            'elements_scanned' => 0,
            'truncated' => false,
            'drift_count' => 0,
            'drift' => array(),
        );
        if ( '' === $providerQueryId || ! function_exists( 'get_post_meta' ) ) { return $base; }

        $bindings = array();
        foreach ( (array) ( $topology['bindings'] ?? array() ) as $binding ) {
            if ( ! is_array( $binding ) || 'jet-engine' !== sanitize_key( (string) ( $binding['provider'] ?? '' ) ) ) { continue; }
            if ( $providerQueryId !== QueryId::normalize( $binding['provider_query_id'] ?? '' ) ) { continue; }
            if ( 'verified' !== (string) ( $binding['status'] ?? '' ) ) { continue; }
            $bindings[] = $binding;
        }
        if ( ! $bindings ) {
            $base['available'] = ! empty( $topology['available'] );
            $base['source'] = 'verified_listing_binding_not_observed';
            return $base;
        }

        $expectedPostTypes = array();
        $templateIds = array();
        foreach ( $bindings as $binding ) {
            $expectedPostTypes = array_merge( $expectedPostTypes, $this->cleanKeys( (array) ( $binding['post_types'] ?? array() ) ) );
            foreach ( (array) ( $binding['template_ids'] ?? array() ) as $templateId ) {
                $templateId = absint( $templateId );
                if ( $templateId ) { $templateIds[$templateId] = true; }
            }
        }
        $expectedPostTypes = array_values( array_unique( $expectedPostTypes ) );
        sort( $expectedPostTypes, SORT_STRING );
        $templateIds = array_slice( array_keys( $templateIds ), 0, self::MAX_TEMPLATES );
        sort( $templateIds, SORT_NUMERIC );
        if ( ! $templateIds || ! $expectedPostTypes ) {
            $base['available'] = true;
            $base['source'] = 'verified_listing_binding_incomplete';
            return $base;
        }

        $drift = array();
        $elementsScanned = 0;
        $truncated = false;
        $templatesScanned = 0;
        foreach ( $templateIds as $templateId ) {
            if ( $elementsScanned >= self::MAX_ELEMENTS || count( $drift ) >= self::MAX_DRIFT ) { $truncated = true; break; }
            try { $raw = get_post_meta( $templateId, '_elementor_data', true ); } catch ( \Throwable $error ) { continue; }
            if ( is_string( $raw ) ) { $decoded = json_decode( $raw, true ); $raw = is_array( $decoded ) ? $decoded : array(); }
            if ( ! is_array( $raw ) || ! $raw ) { continue; }
            $templatesScanned++;
            $this->walk(
                $raw,
                $templateId,
                $providerQueryId,
                $expectedPostTypes,
                $taxonomies,
                $drift,
                $elementsScanned,
                $truncated
            );
            if ( $truncated ) { break; }
        }

        usort( $drift, static function ( $a, $b ) {
            $ak = sprintf( '%010d|%s|%s|%s', (int) ( $a['template_id'] ?? 0 ), (string) ( $a['node_id'] ?? '' ), (string) ( $a['reason'] ?? '' ), (string) ( $a['taxonomy'] ?? '' ) );
            $bk = sprintf( '%010d|%s|%s|%s', (int) ( $b['template_id'] ?? 0 ), (string) ( $b['node_id'] ?? '' ), (string) ( $b['reason'] ?? '' ), (string) ( $b['taxonomy'] ?? '' ) );
            return strcmp( $ak, $bk );
        } );

        return array(
            'contract' => self::CONTRACT,
            'authorizing' => false,
            'read_only' => true,
            'profile_mutation' => false,
            'available' => true,
            'source' => 'elementor_library_elementor_data_via_verified_topology_binding',
            'provider_query_id' => $providerQueryId,
            'expected_post_types' => $expectedPostTypes,
            'templates_scanned' => $templatesScanned,
            'elements_scanned' => $elementsScanned,
            'truncated' => $truncated,
            'drift_count' => count( $drift ),
            'drift' => array_slice( $drift, 0, self::MAX_DRIFT ),
        );
    }

    private function walk( array $nodes, int $templateId, string $providerQueryId, array $expectedPostTypes, array $taxonomies, array &$drift, int &$elementsScanned, bool &$truncated ): void {
        foreach ( $nodes as $node ) {
            if ( $elementsScanned >= self::MAX_ELEMENTS || count( $drift ) >= self::MAX_DRIFT ) { $truncated = true; return; }
            if ( ! is_array( $node ) ) { continue; }
            $elementsScanned++;
            $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
            $widgetType = sanitize_key( (string) ( $node['widgetType'] ?? '' ) );
            $nodeId = sanitize_text_field( (string) ( $node['id'] ?? '' ) );
            $observedProvider = QueryId::normalize( $settings['_element_id'] ?? '' );
            $customQueryEnabled = ! empty( $settings['custom_query'] ) && in_array( strtolower( trim( (string) $settings['custom_query'] ) ), array( '1', 'yes', 'true', 'on' ), true );

            if ( 'jet-listing-grid' === $widgetType && $customQueryEnabled && $providerQueryId === $observedProvider ) {
                $localPostTypes = $this->cleanKeys( (array) ( $settings['custom_post_types'] ?? array() ) );
                if ( $localPostTypes && $localPostTypes !== $expectedPostTypes ) {
                    $drift[] = array(
                        'status' => 'drift',
                        'reason' => 'listing_custom_post_type_mismatch',
                        'severity_hint' => 'blocking',
                        'template_id' => $templateId,
                        'node_id' => $nodeId,
                        'widget_type' => $widgetType,
                        'provider_query_id' => $providerQueryId,
                        'expected_post_types' => $expectedPostTypes,
                        'observed_local_post_types' => $localPostTypes,
                        'authorizing' => false,
                    );
                }

                foreach ( $this->localTaxonomies( (array) ( $settings['posts_query'] ?? array() ) ) as $taxonomy ) {
                    if ( ! isset( $taxonomies[$taxonomy] ) || ! is_array( $taxonomies[$taxonomy] ) ) {
                        $drift[] = array(
                            'status' => 'review',
                            'reason' => 'listing_taxonomy_authority_unresolved',
                            'severity_hint' => 'warning',
                            'template_id' => $templateId,
                            'node_id' => $nodeId,
                            'widget_type' => $widgetType,
                            'provider_query_id' => $providerQueryId,
                            'taxonomy' => $taxonomy,
                            'expected_post_types' => $expectedPostTypes,
                            'authorizing' => false,
                        );
                        continue;
                    }
                    $objectTypes = $this->cleanKeys( (array) ( $taxonomies[$taxonomy]['object_type'] ?? array() ) );
                    if ( $objectTypes && ! array_intersect( $expectedPostTypes, $objectTypes ) ) {
                        $drift[] = array(
                            'status' => 'drift',
                            'reason' => 'listing_taxonomy_post_type_mismatch',
                            'severity_hint' => 'blocking',
                            'template_id' => $templateId,
                            'node_id' => $nodeId,
                            'widget_type' => $widgetType,
                            'provider_query_id' => $providerQueryId,
                            'taxonomy' => $taxonomy,
                            'taxonomy_object_types' => $objectTypes,
                            'expected_post_types' => $expectedPostTypes,
                            'authorizing' => false,
                        );
                    }
                }
            }

            if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
                $this->walk( $node['elements'], $templateId, $providerQueryId, $expectedPostTypes, $taxonomies, $drift, $elementsScanned, $truncated );
                if ( $truncated ) { return; }
            }
        }
    }

    private function localTaxonomies( array $postsQuery ): array {
        $out = array();
        foreach ( $postsQuery as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $type = sanitize_key( (string) ( $row['type'] ?? '' ) );
            if ( 'tax_query' !== $type && ! array_key_exists( 'tax_query_taxonomy', $row ) ) { continue; }
            $taxonomy = sanitize_key( (string) ( $row['tax_query_taxonomy'] ?? '' ) );
            if ( '' !== $taxonomy ) { $out[$taxonomy] = true; }
        }
        $out = array_keys( $out );
        sort( $out, SORT_STRING );
        return $out;
    }

    private function cleanKeys( array $values ): array {
        $out = array();
        foreach ( $values as $value ) {
            if ( ! is_scalar( $value ) ) { continue; }
            $key = sanitize_key( (string) $value );
            if ( '' !== $key && 'any' !== $key ) { $out[$key] = true; }
        }
        $out = array_keys( $out );
        sort( $out, SORT_STRING );
        return $out;
    }
}
