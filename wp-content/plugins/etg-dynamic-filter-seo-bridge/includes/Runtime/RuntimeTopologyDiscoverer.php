<?php
namespace ETG\DynamicFilterSEOBridge\Runtime;

final class RuntimeTopologyDiscoverer {
    const CONTRACT = 'etg.dfsb.runtime-topology.v1';
    const MAX_TEMPLATES = 250;
    const MAX_ELEMENTS = 10000;
    const MAX_BINDINGS = 500;
    const CACHE_TTL = 300;

    private $templateProvider;
    private $queryProvider;
    private static $memoryCache = null;

    public function __construct( callable $templateProvider = null, callable $queryProvider = null ) {
        $this->templateProvider = $templateProvider;
        $this->queryProvider = $queryProvider;
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
        if ( ! $refresh && null !== self::$memoryCache ) { return self::$memoryCache; }
        if ( ! $refresh && function_exists( 'get_transient' ) ) {
            $cached = get_transient( 'etg_dfsb_runtime_topology_v1' );
            if ( is_array( $cached ) && self::CONTRACT === (string) ( $cached['contract'] ?? '' ) ) {
                self::$memoryCache = $cached;
                return $cached;
            }
        }

        $queries = $this->queries();
        $templates = $this->templates();
        $queryIndex = $this->queryIndex( $queries['items'] );
        $bindings = array();
        $providerIds = array();
        $elementsScanned = 0;
        $truncated = false;

        foreach ( $templates['items'] as $template ) {
            if ( $elementsScanned >= self::MAX_ELEMENTS ) { $truncated = true; break; }
            $templateId = (int) ( $template['id'] ?? 0 );
            $data = $template['data'] ?? array();
            if ( is_string( $data ) ) {
                $decoded = json_decode( $data, true );
                $data = is_array( $decoded ) ? $decoded : array();
            }
            if ( ! is_array( $data ) ) { continue; }
            $this->walkElements( $data, $templateId, $queryIndex, $bindings, $providerIds, $elementsScanned, $truncated );
            if ( $truncated ) { break; }
        }

        $bindings = $this->normalizeBindings( $bindings );
        $result = array(
            'contract' => self::CONTRACT,
            'authorizing' => false,
            'read_only' => true,
            'profile_mutation' => false,
            'available' => ! empty( $templates['available'] ) && ! empty( $queries['available'] ),
            'sources' => array( 'templates' => $templates['source'], 'query_builder' => $queries['source'] ),
            'templates_scanned' => count( $templates['items'] ),
            'query_builder_records_observed' => count( $queries['items'] ),
            'elements_scanned' => $elementsScanned,
            'truncated' => $truncated || ! empty( $templates['truncated'] ) || ! empty( $queries['truncated'] ),
            'provider_query_ids' => array_values( array_keys( $providerIds ) ),
            'bindings' => array_slice( $bindings, 0, self::MAX_BINDINGS ),
            'binding_count' => count( $bindings ),
            'bindings_truncated' => count( $bindings ) > self::MAX_BINDINGS,
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
            $custom = isset( $query->query_id ) && is_scalar( $query->query_id ) ? sanitize_key( (string) $query->query_id ) : '';
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

    private function walkElements( array $nodes, int $templateId, array $queryIndex, array &$bindings, array &$providerIds, int &$elementsScanned, bool &$truncated ): void {
        foreach ( $nodes as $node ) {
            if ( $elementsScanned >= self::MAX_ELEMENTS ) { $truncated = true; return; }
            if ( ! is_array( $node ) ) { continue; }
            $elementsScanned++;
            $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
            foreach ( array( 'query_id', '_element_id' ) as $key ) {
                if ( isset( $settings[$key] ) && is_scalar( $settings[$key] ) ) {
                    $candidate = sanitize_key( (string) $settings[$key] );
                    if ( '' !== $candidate ) { $providerIds[$candidate] = true; }
                }
            }
            $providerQueryId = isset( $settings['_element_id'] ) && is_scalar( $settings['_element_id'] ) ? sanitize_key( (string) $settings['_element_id'] ) : '';
            $locator = isset( $settings['custom_query_id'] ) && is_scalar( $settings['custom_query_id'] ) ? trim( (string) $settings['custom_query_id'] ) : '';
            $customQueryEnabled = ! empty( $settings['custom_query'] ) && in_array( strtolower( trim( (string) $settings['custom_query'] ) ), array( '1','yes','true','on' ), true );
            if ( '' !== $providerQueryId && '' !== $locator && $customQueryEnabled ) {
                $matches = isset( $queryIndex['internal'][$locator] ) ? (array) $queryIndex['internal'][$locator] : array();
                if ( ! $matches ) {
                    $customLocator = sanitize_key( $locator );
                    $matches = isset( $queryIndex['custom'][$customLocator] ) ? (array) $queryIndex['custom'][$customLocator] : array();
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
            if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) { $this->walkElements( $node['elements'], $templateId, $queryIndex, $bindings, $providerIds, $elementsScanned, $truncated ); }
            if ( $truncated ) { return; }
        }
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
