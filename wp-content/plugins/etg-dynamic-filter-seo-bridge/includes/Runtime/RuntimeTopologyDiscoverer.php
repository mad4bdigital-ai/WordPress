<?php
namespace ETG\DynamicFilterSEOBridge\Runtime;

require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';

use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;

final class RuntimeTopologyDiscoverer {
    const CONTRACT = 'etg.dfsb.runtime-topology.v1';
    const MAX_TEMPLATES = 250;
    const MAX_ELEMENTS = 10000;
    const MAX_BINDINGS = 500;
    const MAX_QUERY_SURFACES = 2000;
    const MAX_PROVIDER_GROUP_DRIFT = 200;
    const MAX_TEMPLATE_REFERENCES = 1000;
    const MAX_REFERENCED_TEMPLATES = 100;
    const MAX_TEMPLATE_REFERENCE_DEPTH = 6;
    const CACHE_TTL = 300;

    private $templateProvider;
    private $queryProvider;
    private $templateReferenceProvider;
    private static $memoryCache = null;

    public function __construct( callable $templateProvider = null, callable $queryProvider = null, callable $templateReferenceProvider = null ) {
        $this->templateProvider = $templateProvider;
        $this->queryProvider = $queryProvider;
        $this->templateReferenceProvider = $templateReferenceProvider;
    }

    public function registerInvalidationHooks(): void {
        if ( function_exists( 'add_action' ) ) {
            add_action( 'save_post_elementor_library', array( $this, 'invalidate' ), 20, 0 );
            add_action( 'deleted_post', array( $this, 'invalidate' ), 20, 0 );
        }
    }

    public function invalidate(): void {
        self::$memoryCache = null;
        if ( function_exists( 'delete_transient' ) ) { delete_transient( 'etg_dfsb_runtime_topology_v1' ); }
    }

    public function discover( bool $refresh = false ): array {
        if ( ! $refresh && null !== self::$memoryCache && array_key_exists( 'template_reference_count', self::$memoryCache ) ) { return self::$memoryCache; }
        if ( ! $refresh && function_exists( 'get_transient' ) ) {
            $cached = get_transient( 'etg_dfsb_runtime_topology_v1' );
            if ( is_array( $cached ) && self::CONTRACT === (string) ( $cached['contract'] ?? '' ) && array_key_exists( 'template_reference_count', $cached ) ) {
                self::$memoryCache = $cached;
                return $cached;
            }
        }

        $queries = $this->queries();
        $templates = $this->templates();
        $queryIndex = $this->queryIndex( $queries['items'] );
        $bindings = array();
        $providerIds = array();
        $querySurfaces = array();
        $querySurfaceCount = 0;
        $elementsScanned = 0;
        $truncated = false;
        $templateReferences = array();
        $templateReferenceCount = 0;
        $templateReferencesTruncated = false;
        $referencedTemplatesScanned = 0;
        $templateIndex = array();
        $scanQueue = array();
        $scheduled = array();
        $scanned = array();

        foreach ( $templates['items'] as $template ) {
            $templateId = absint( $template['id'] ?? 0 );
            if ( ! $templateId || isset( $scheduled[$templateId] ) ) { continue; }
            $data = $this->normalizeTemplateData( $template['data'] ?? array() );
            if ( ! $data ) { continue; }
            $templateIndex[$templateId] = $data;
            $scheduled[$templateId] = true;
            $scanQueue[] = array(
                'template_id' => $templateId,
                'data' => $data,
                'depth' => 0,
                'reference_chain' => array( $templateId ),
                'origin' => 'catalog',
            );
        }

        for ( $queueIndex = 0; $queueIndex < count( $scanQueue ); $queueIndex++ ) {
            if ( $elementsScanned >= self::MAX_ELEMENTS ) { $truncated = true; break; }
            $scan = $scanQueue[$queueIndex];
            $templateId = absint( $scan['template_id'] ?? 0 );
            if ( ! $templateId || isset( $scanned[$templateId] ) ) { continue; }
            $data = isset( $scan['data'] ) && is_array( $scan['data'] ) ? $scan['data'] : array();
            if ( ! $data ) { continue; }
            $scanned[$templateId] = true;
            if ( 'reference' === (string) ( $scan['origin'] ?? '' ) ) { $referencedTemplatesScanned++; }

            $referenceCandidates = array();
            $this->walkElements(
                $data,
                $templateId,
                $queryIndex,
                $bindings,
                $providerIds,
                $querySurfaces,
                $querySurfaceCount,
                $elementsScanned,
                $truncated,
                $referenceCandidates,
                $templateReferenceCount,
                $templateReferencesTruncated
            );
            if ( $truncated ) { break; }

            foreach ( $referenceCandidates as $candidate ) {
                $targetId = absint( $candidate['target_template_id'] ?? 0 );
                $chain = isset( $scan['reference_chain'] ) && is_array( $scan['reference_chain'] ) ? array_values( array_map( 'absint', $scan['reference_chain'] ) ) : array( $templateId );
                $nextDepth = (int) ( $scan['depth'] ?? 0 ) + 1;
                $status = 'template_reference_invalid';
                $resolved = false;

                if ( $targetId ) {
                    if ( in_array( $targetId, $chain, true ) ) {
                        $status = 'cycle_detected';
                    } elseif ( $nextDepth > self::MAX_TEMPLATE_REFERENCE_DEPTH ) {
                        $status = 'depth_limit_exceeded';
                        $templateReferencesTruncated = true;
                    } elseif ( isset( $scheduled[$targetId] ) || isset( $scanned[$targetId] ) ) {
                        $status = 'resolved_catalogued';
                        $resolved = true;
                    } elseif ( $referencedTemplatesScanned + $this->queuedReferencedTemplateCount( $scanQueue, $queueIndex + 1 ) >= self::MAX_REFERENCED_TEMPLATES ) {
                        $status = 'template_budget_exceeded';
                        $templateReferencesTruncated = true;
                    } else {
                        $targetData = $this->referencedTemplateData( $targetId, $templateIndex );
                        if ( $targetData ) {
                            $status = 'resolved_reference';
                            $resolved = true;
                            $scheduled[$targetId] = true;
                            $templateIndex[$targetId] = $targetData;
                            $scanQueue[] = array(
                                'template_id' => $targetId,
                                'data' => $targetData,
                                'depth' => $nextDepth,
                                'reference_chain' => array_merge( $chain, array( $targetId ) ),
                                'origin' => 'reference',
                            );
                        } else {
                            $status = 'template_reference_unresolved';
                        }
                    }
                }

                $edge = array(
                    'source_template_id' => $templateId,
                    'source_node_id' => (string) ( $candidate['node_id'] ?? '' ),
                    'target_template_id' => $targetId,
                    'depth' => $nextDepth,
                    'status' => $status,
                    'resolved' => $resolved,
                    'reference_chain' => array_merge( $chain, $targetId ? array( $targetId ) : array() ),
                    'severity_hint' => $resolved ? 'info' : 'warning',
                    'authorizing' => false,
                );
                if ( count( $templateReferences ) < self::MAX_TEMPLATE_REFERENCES ) { $templateReferences[] = $edge; }
                else { $templateReferencesTruncated = true; }
            }
        }

        usort( $templateReferences, static function ( $a, $b ) {
            $ak = sprintf( '%010d|%s|%010d|%s', (int) ( $a['source_template_id'] ?? 0 ), (string) ( $a['source_node_id'] ?? '' ), (int) ( $a['target_template_id'] ?? 0 ), (string) ( $a['status'] ?? '' ) );
            $bk = sprintf( '%010d|%s|%010d|%s', (int) ( $b['source_template_id'] ?? 0 ), (string) ( $b['source_node_id'] ?? '' ), (int) ( $b['target_template_id'] ?? 0 ), (string) ( $b['status'] ?? '' ) );
            return strcmp( $ak, $bk );
        } );

        $bindings = $this->normalizeBindings( $bindings );
        $drift = $this->providerGroupDrift( $bindings, $querySurfaces, $queryIndex );
        $result = array(
            'contract' => self::CONTRACT,
            'authorizing' => false,
            'read_only' => true,
            'profile_mutation' => false,
            'available' => ! empty( $templates['available'] ) && ! empty( $queries['available'] ),
            'sources' => array( 'templates' => $templates['source'], 'query_builder' => $queries['source'] ),
            'templates_scanned' => count( $templates['items'] ),
            'templates_processed' => count( $scanned ),
            'root_templates_scanned' => count( $templates['items'] ),
            'referenced_templates_scanned' => $referencedTemplatesScanned,
            'template_references' => array_slice( $templateReferences, 0, self::MAX_TEMPLATE_REFERENCES ),
            'template_reference_count' => $templateReferenceCount,
            'template_references_truncated' => $templateReferencesTruncated || $templateReferenceCount > self::MAX_TEMPLATE_REFERENCES,
            'template_reference_depth_limit' => self::MAX_TEMPLATE_REFERENCE_DEPTH,
            'referenced_template_limit' => self::MAX_REFERENCED_TEMPLATES,
            'query_builder_records_observed' => count( $queries['items'] ),
            'elements_scanned' => $elementsScanned,
            'truncated' => $truncated || $templateReferencesTruncated || ! empty( $templates['truncated'] ) || ! empty( $queries['truncated'] ),
            'provider_query_ids' => array_values( array_keys( $providerIds ) ),
            'bindings' => array_slice( $bindings, 0, self::MAX_BINDINGS ),
            'binding_count' => count( $bindings ),
            'bindings_truncated' => count( $bindings ) > self::MAX_BINDINGS,
            'query_surfaces' => array_slice( $querySurfaces, 0, self::MAX_QUERY_SURFACES ),
            'query_surface_count' => $querySurfaceCount,
            'query_surfaces_truncated' => $querySurfaceCount > self::MAX_QUERY_SURFACES,
            'provider_group_drift' => array_slice( $drift, 0, self::MAX_PROVIDER_GROUP_DRIFT ),
            'provider_group_drift_count' => count( $drift ),
            'provider_group_drift_truncated' => count( $drift ) > self::MAX_PROVIDER_GROUP_DRIFT,
        );
        self::$memoryCache = $result;
        if ( function_exists( 'set_transient' ) ) { set_transient( 'etg_dfsb_runtime_topology_v1', $result, self::CACHE_TTL ); }
        return $result;
    }

    private function templates(): array {
        if ( $this->templateProvider ) {
            try {
                $items = call_user_func( $this->templateProvider );
                if ( $items instanceof \Traversable ) { $items = iterator_to_array( $items, false ); }
                return array( 'available' => is_array( $items ), 'source' => 'injected_template_provider', 'items' => is_array( $items ) ? array_slice( $items, 0, self::MAX_TEMPLATES ) : array(), 'truncated' => is_array( $items ) && count( $items ) > self::MAX_TEMPLATES );
            } catch ( \Throwable $error ) {
                return array( 'available' => false, 'source' => 'injected_template_provider_exception', 'items' => array(), 'truncated' => false );
            }
        }
        if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
            return array( 'available' => false, 'source' => 'elementor_template_api_unavailable', 'items' => array(), 'truncated' => false );
        }
        try {
            $ids = get_posts( array(
                'post_type' => 'elementor_library',
                'post_status' => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => self::MAX_TEMPLATES + 1,
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ids',
                'no_found_rows' => true,
                'suppress_filters' => true,
            ) );
            $ids = is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
            $truncated = count( $ids ) > self::MAX_TEMPLATES;
            $items = array();
            foreach ( array_slice( $ids, 0, self::MAX_TEMPLATES ) as $id ) {
                $raw = get_post_meta( $id, '_elementor_data', true );
                if ( is_string( $raw ) && '' !== trim( $raw ) ) { $items[] = array( 'id' => $id, 'data' => $raw ); }
            }
            return array( 'available' => true, 'source' => 'elementor_library_elementor_data', 'items' => $items, 'truncated' => $truncated );
        } catch ( \Throwable $error ) {
            return array( 'available' => false, 'source' => 'elementor_template_scan_exception', 'items' => array(), 'truncated' => false );
        }
    }

    private function referencedTemplateData( int $templateId, array $templateIndex ): array {
        if ( isset( $templateIndex[$templateId] ) && is_array( $templateIndex[$templateId] ) ) { return $templateIndex[$templateId]; }
        if ( $this->templateReferenceProvider ) {
            try { return $this->normalizeTemplateData( call_user_func( $this->templateReferenceProvider, $templateId ) ); }
            catch ( \Throwable $error ) { return array(); }
        }
        if ( ! function_exists( 'get_post_meta' ) ) { return array(); }
        try {
            if ( function_exists( 'get_post_type' ) && 'elementor_library' !== (string) get_post_type( $templateId ) ) { return array(); }
            if ( function_exists( 'get_post_status' ) && ! in_array( (string) get_post_status( $templateId ), array( 'publish', 'draft', 'private' ), true ) ) { return array(); }
            return $this->normalizeTemplateData( get_post_meta( $templateId, '_elementor_data', true ) );
        } catch ( \Throwable $error ) { return array(); }
    }

    private function normalizeTemplateData( $data ): array {
        if ( is_string( $data ) ) {
            $decoded = json_decode( $data, true );
            $data = is_array( $decoded ) ? $decoded : array();
        }
        return is_array( $data ) ? $data : array();
    }

    private function queuedReferencedTemplateCount( array $scanQueue, int $startIndex ): int {
        $count = 0;
        for ( $i = max( 0, $startIndex ); $i < count( $scanQueue ); $i++ ) {
            if ( 'reference' === (string) ( $scanQueue[$i]['origin'] ?? '' ) ) { $count++; }
        }
        return $count;
    }

    private function queries(): array {
        try {
            if ( $this->queryProvider ) { $items = call_user_func( $this->queryProvider ); $source = 'injected_query_provider'; }
            else {
                $class = '\\Jet_Engine\\Query_Builder\\Manager';
                if ( ! class_exists( $class ) || ! method_exists( $class, 'instance' ) ) { return array( 'available'=>false, 'source'=>'query_builder_manager_unavailable', 'items'=>array(), 'truncated'=>false ); }
                $manager = $class::instance();
                if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_queries' ) ) { return array( 'available'=>false, 'source'=>'query_builder_inventory_unavailable', 'items'=>array(), 'truncated'=>false ); }
                $items = $manager->get_queries(); $source = 'jet_engine_query_builder_manager_get_queries';
            }
            if ( $items instanceof \Traversable ) { $items = iterator_to_array( $items, false ); }
            if ( ! is_array( $items ) ) { return array( 'available'=>false, 'source'=>$source . '_invalid', 'items'=>array(), 'truncated'=>false ); }
            $truncated = count( $items ) > 2000;
            return array( 'available'=>true, 'source'=>$source, 'items'=>array_slice( array_values( $items ), 0, 2000 ), 'truncated'=>$truncated );
        } catch ( \Throwable $error ) {
            return array( 'available'=>false, 'source'=>'query_builder_inventory_exception', 'items'=>array(), 'truncated'=>false );
        }
    }

    private function queryIndex( array $queries ): array {
        $byInternal = array();
        $byCustom = array();
        foreach ( $queries as $query ) {
            if ( ! is_object( $query ) ) { continue; }
            $internal = isset( $query->id ) && is_scalar( $query->id ) ? trim( (string) $query->id ) : '';
            $custom = isset( $query->query_id ) && is_scalar( $query->query_id ) ? QueryId::normalize( $query->query_id ) : '';
            $type = method_exists( $query, 'get_query_type' ) ? sanitize_key( (string) $query->get_query_type() ) : '';
            $postTypes = array();
            if ( 'posts' === $type && method_exists( $query, 'get_query_args' ) ) {
                $args = $query->get_query_args();
                if ( is_array( $args ) ) { $postTypes = $this->postTypes( $args['post_type'] ?? null ); }
            }
            $record = array( 'internal_id'=>$internal, 'custom_query_id'=>$custom, 'query_type'=>$type, 'post_types'=>$postTypes, 'query'=>$query );
            if ( '' !== $internal ) { if ( ! isset( $byInternal[$internal] ) ) { $byInternal[$internal] = array(); } $byInternal[$internal][] = $record; }
            if ( '' !== $custom ) { if ( ! isset( $byCustom[$custom] ) ) { $byCustom[$custom] = array(); } $byCustom[$custom][] = $record; }
        }
        return array( 'internal'=>$byInternal, 'custom'=>$byCustom );
    }

    private function walkElements( array $nodes, int $templateId, array $queryIndex, array &$bindings, array &$providerIds, array &$querySurfaces, int &$querySurfaceCount, int &$elementsScanned, bool &$truncated, array &$templateReferenceCandidates = array(), int &$templateReferenceCount = 0, bool &$templateReferencesTruncated = false ): void {
        foreach ( $nodes as $node ) {
            if ( $elementsScanned >= self::MAX_ELEMENTS ) { $truncated = true; return; }
            if ( ! is_array( $node ) ) { continue; }
            $elementsScanned++;
            $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
            $nodeId = isset( $node['id'] ) && is_scalar( $node['id'] ) ? sanitize_text_field( (string) $node['id'] ) : '';
            $elType = isset( $node['elType'] ) && is_scalar( $node['elType'] ) ? sanitize_key( (string) $node['elType'] ) : '';
            $widgetType = isset( $node['widgetType'] ) && is_scalar( $node['widgetType'] ) ? sanitize_key( (string) $node['widgetType'] ) : '';

            if ( 'template' === $widgetType && array_key_exists( 'template_id', $settings ) ) {
                $templateReferenceCount++;
                if ( $templateReferenceCount <= self::MAX_TEMPLATE_REFERENCES ) {
                    $templateReferenceCandidates[] = array(
                        'node_id' => $nodeId,
                        'target_template_id' => is_scalar( $settings['template_id'] ) ? absint( $settings['template_id'] ) : 0,
                    );
                } else { $templateReferencesTruncated = true; }
            }

            foreach ( array( 'query_id', '_element_id' ) as $key ) {
                if ( isset( $settings[$key] ) && is_scalar( $settings[$key] ) ) {
                    $candidate = QueryId::normalize( $settings[$key] );
                    if ( '' !== $candidate ) {
                        $providerIds[$candidate] = true;
                        if ( 'query_id' === $key ) {
                            $querySurfaceCount++;
                            if ( count( $querySurfaces ) < self::MAX_QUERY_SURFACES ) {
                                $querySurfaces[] = array(
                                    'template_id'=>$templateId,
                                    'node_id'=>$nodeId,
                                    'el_type'=>$elType,
                                    'widget_type'=>$widgetType,
                                    'setting'=>$key,
                                    'query_id'=>$candidate,
                                    'content_provider'=>isset( $settings['content_provider'] ) && is_scalar( $settings['content_provider'] ) ? sanitize_key( (string) $settings['content_provider'] ) : '',
                                );
                            }
                        }
                    }
                }
            }
            $providerQueryId = isset( $settings['_element_id'] ) && is_scalar( $settings['_element_id'] ) ? QueryId::normalize( $settings['_element_id'] ) : '';
            $locator = isset( $settings['custom_query_id'] ) && is_scalar( $settings['custom_query_id'] ) ? trim( (string) $settings['custom_query_id'] ) : '';
            $customQueryEnabled = ! empty( $settings['custom_query'] ) && in_array( strtolower( trim( (string) $settings['custom_query'] ) ), array( '1','yes','true','on' ), true );
            if ( '' !== $providerQueryId && '' !== $locator && $customQueryEnabled ) {
                $matches = isset( $queryIndex['internal'][$locator] ) ? (array) $queryIndex['internal'][$locator] : array();
                if ( ! $matches ) {
                    $customLocator = QueryId::normalize( $locator );
                    $matches = '' !== $customLocator && isset( $queryIndex['custom'][$customLocator] ) ? (array) $queryIndex['custom'][$customLocator] : array();
                }
                $record = array(
                    'provider'=>'jet-engine', 'provider_query_id'=>$providerQueryId, 'template_id'=>$templateId,
                    'element_id'=>$providerQueryId, 'query_builder_locator'=>$locator, 'status'=>'blocked', 'reason'=>'query_builder_locator_not_found',
                    'query_builder_internal_id'=>'', 'query_builder_custom_query_id'=>'', 'query_type'=>'', 'post_types'=>array(),
                );
                if ( 1 === count( $matches ) ) {
                    $match = $matches[0];
                    $record['query_builder_internal_id'] = (string) $match['internal_id'];
                    $record['query_builder_custom_query_id'] = (string) $match['custom_query_id'];
                    $record['query_type'] = (string) $match['query_type'];
                    $record['post_types'] = (array) $match['post_types'];
                    if ( '' === $record['query_builder_custom_query_id'] ) { $record['reason'] = 'query_builder_custom_id_missing'; }
                    elseif ( 'posts' !== $record['query_type'] ) { $record['reason'] = 'query_builder_query_not_posts'; }
                    elseif ( ! $record['post_types'] ) { $record['reason'] = 'query_builder_post_type_unbounded'; }
                    else { $record['status'] = 'verified'; $record['reason'] = 'verified'; }
                } elseif ( count( $matches ) > 1 ) { $record['reason'] = 'query_builder_locator_ambiguous'; }
                $bindings[] = $record;
            }
            if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
                $this->walkElements( $node['elements'], $templateId, $queryIndex, $bindings, $providerIds, $querySurfaces, $querySurfaceCount, $elementsScanned, $truncated, $templateReferenceCandidates, $templateReferenceCount, $templateReferencesTruncated );
            }
            if ( $truncated ) { return; }
        }
    }

    private function providerGroupDrift( array $bindings, array $querySurfaces, array $queryIndex ): array {
        $anchors = array();
        $providerPostTypes = array();
        foreach ( $bindings as $binding ) {
            if ( ! is_array( $binding ) || 'verified' !== (string) ( $binding['status'] ?? '' ) ) { continue; }
            $providerQueryId = QueryId::normalize( $binding['provider_query_id'] ?? '' );
            if ( '' === $providerQueryId ) { continue; }
            $postTypes = $this->postTypes( $binding['post_types'] ?? array() );
            if ( ! isset( $providerPostTypes[$providerQueryId] ) ) { $providerPostTypes[$providerQueryId] = array(); }
            $providerPostTypes[$providerQueryId] = array_values( array_unique( array_merge( $providerPostTypes[$providerQueryId], $postTypes ) ) );
            sort( $providerPostTypes[$providerQueryId], SORT_STRING );
            foreach ( (array) ( $binding['template_ids'] ?? array() ) as $templateId ) {
                $templateId = absint( $templateId );
                if ( ! $templateId ) { continue; }
                if ( ! isset( $anchors[$templateId] ) ) { $anchors[$templateId] = array(); }
                $anchors[$templateId][$providerQueryId] = array( 'post_types'=>$postTypes );
            }
        }

        $out = array();
        $seen = array();
        foreach ( $querySurfaces as $surface ) {
            if ( ! is_array( $surface ) ) { continue; }
            $widgetType = sanitize_key( (string) ( $surface['widget_type'] ?? '' ) );
            if ( 0 !== strpos( $widgetType, 'jet-smart-filters-' ) ) { continue; }
            $templateId = absint( $surface['template_id'] ?? 0 );
            $observed = QueryId::normalize( $surface['query_id'] ?? '' );
            if ( ! $templateId || '' === $observed || empty( $anchors[$templateId] ) || isset( $anchors[$templateId][$observed] ) ) { continue; }

            $expectedIds = array_keys( $anchors[$templateId] );
            sort( $expectedIds, SORT_STRING );
            $expectedPostTypes = array();
            foreach ( $anchors[$templateId] as $record ) { $expectedPostTypes = array_merge( $expectedPostTypes, (array) ( $record['post_types'] ?? array() ) ); }
            $expectedPostTypes = array_values( array_unique( $this->postTypes( $expectedPostTypes ) ) );

            $observedPostTypes = isset( $providerPostTypes[$observed] ) ? (array) $providerPostTypes[$observed] : array();
            if ( ! $observedPostTypes && isset( $queryIndex['custom'][$observed] ) && 1 === count( (array) $queryIndex['custom'][$observed] ) ) {
                $match = reset( $queryIndex['custom'][$observed] );
                if ( is_array( $match ) && 'posts' === (string) ( $match['query_type'] ?? '' ) ) { $observedPostTypes = $this->postTypes( $match['post_types'] ?? array() ); }
            }
            $crossPostType = ! empty( $expectedPostTypes ) && ! empty( $observedPostTypes ) && ! array_intersect( $expectedPostTypes, $observedPostTypes );
            $key = implode( '|', array( $templateId, (string) ( $surface['node_id'] ?? '' ), $observed ) );
            if ( isset( $seen[$key] ) ) { continue; }
            $seen[$key] = true;
            $out[] = array(
                'status'=>'drift',
                'reason'=>$crossPostType ? 'provider_group_post_type_mismatch' : 'provider_group_unbound_in_template',
                'severity_hint'=>$crossPostType ? 'blocking' : 'warning',
                'template_id'=>$templateId,
                'node_id'=>(string) ( $surface['node_id'] ?? '' ),
                'widget_type'=>$widgetType,
                'observed_query_id'=>$observed,
                'observed_post_types'=>$observedPostTypes,
                'expected_provider_query_ids'=>$expectedIds,
                'expected_post_types'=>$expectedPostTypes,
                'authorizing'=>false,
            );
        }
        usort( $out, static function ( $a, $b ) {
            $ak = sprintf( '%010d|%s|%s', (int) ( $a['template_id'] ?? 0 ), (string) ( $a['node_id'] ?? '' ), (string) ( $a['observed_query_id'] ?? '' ) );
            $bk = sprintf( '%010d|%s|%s', (int) ( $b['template_id'] ?? 0 ), (string) ( $b['node_id'] ?? '' ), (string) ( $b['observed_query_id'] ?? '' ) );
            return strcmp( $ak, $bk );
        } );
        return $out;
    }

    private function normalizeBindings( array $bindings ): array {
        $groups = array();
        foreach ( $bindings as $binding ) {
            $key = implode( '|', array(
                (string) ( $binding['provider'] ?? '' ), (string) ( $binding['provider_query_id'] ?? '' ),
                (string) ( $binding['query_builder_internal_id'] ?? '' ), (string) ( $binding['query_builder_custom_query_id'] ?? '' ),
                (string) ( $binding['reason'] ?? '' )
            ) );
            if ( ! isset( $groups[$key] ) ) { $binding['template_ids'] = array(); $binding['evidence_count'] = 0; $groups[$key] = $binding; }
            $groups[$key]['evidence_count']++;
            $templateId = (int) ( $binding['template_id'] ?? 0 );
            if ( $templateId ) { $groups[$key]['template_ids'][] = $templateId; }
            unset( $groups[$key]['template_id'] );
        }
        foreach ( $groups as &$binding ) {
            $binding['template_ids'] = array_values( array_unique( array_map( 'absint', (array) $binding['template_ids'] ) ) );
            sort( $binding['template_ids'], SORT_NUMERIC );
        }
        unset( $binding );
        $out = array_values( $groups );
        usort( $out, static function ( $a, $b ) {
            $ak = (string) ($a['provider_query_id'] ?? '') . '|' . (string) ($a['query_builder_custom_query_id'] ?? '') . '|' . (string) ($a['query_builder_internal_id'] ?? '');
            $bk = (string) ($b['provider_query_id'] ?? '') . '|' . (string) ($b['query_builder_custom_query_id'] ?? '') . '|' . (string) ($b['query_builder_internal_id'] ?? '');
            return strcmp( $ak, $bk );
        } );
        return $out;
    }

    private function postTypes( $value ): array {
        $items = is_array( $value ) ? $value : ( is_scalar( $value ) ? array( $value ) : array() );
        $out = array();
        foreach ( $items as $item ) { $item = sanitize_key( (string) $item ); if ( '' === $item || 'any' === $item ) { return array(); } $out[] = $item; }
        $out = array_values( array_unique( array_filter( $out ) ) ); sort( $out, SORT_STRING ); return $out;
    }
}
