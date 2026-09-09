<?php
namespace ETG\DynamicFilterSEOBridge\JetSmartFilters;

require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';

use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;

final class FilterDefinitionInspector {
    const CONTRACT = 'etg.dfsb.jet-smart-filters-definition-inspection.v1';
    const MAX_TEMPLATES = 250;
    const MAX_ELEMENTS = 10000;
    const MAX_SURFACES = 1000;
    const MAX_DRIFT = 200;

    private $templateProvider;
    private $filterProvider;

    public function __construct( callable $templateProvider = null, callable $filterProvider = null ) {
        $this->templateProvider = $templateProvider;
        $this->filterProvider = $filterProvider;
    }

    public function inspect(): array {
        $templates = $this->templates();
        $definitionSourceAvailable = (bool) $this->filterProvider || function_exists( 'get_post_meta' );
        $surfaces = array();
        $definitions = array();
        $elementsScanned = 0;
        $surfaceCount = 0;
        $truncated = false;

        foreach ( $templates['items'] as $template ) {
            if ( $elementsScanned >= self::MAX_ELEMENTS ) { $truncated = true; break; }
            $templateId = absint( $template['id'] ?? 0 );
            $data = $template['data'] ?? array();
            if ( is_string( $data ) ) {
                $decoded = json_decode( $data, true );
                $data = is_array( $decoded ) ? $decoded : array();
            }
            if ( ! is_array( $data ) ) { continue; }
            $this->walk( $data, $templateId, $surfaces, $definitions, $surfaceCount, $elementsScanned, $truncated );
            if ( $truncated ) { break; }
        }

        $drift = array();
        foreach ( $surfaces as $surface ) {
            if ( ! is_array( $surface ) || empty( $surface['definition_available'] ) ) { continue; }
            if ( 'taxonomies' !== (string) ( $surface['data_source'] ?? '' ) ) { continue; }
            $source = sanitize_key( (string) ( $surface['source_taxonomy'] ?? '' ) );
            $target = sanitize_key( (string) ( $surface['target_taxonomy'] ?? '' ) );
            if ( '' === $source || '' === $target || $source === $target ) { continue; }
            $drift[] = array(
                'status' => 'drift',
                'reason' => 'source_taxonomy_query_target_mismatch',
                'severity_hint' => 'blocking',
                'template_id' => absint( $surface['template_id'] ?? 0 ),
                'node_id' => sanitize_text_field( (string) ( $surface['node_id'] ?? '' ) ),
                'widget_type' => sanitize_key( (string) ( $surface['widget_type'] ?? '' ) ),
                'filter_id' => absint( $surface['filter_id'] ?? 0 ),
                'query_id' => QueryId::normalize( $surface['query_id'] ?? '' ),
                'content_provider' => sanitize_key( (string) ( $surface['content_provider'] ?? '' ) ),
                'source_taxonomy' => $source,
                'target_taxonomy' => $target,
                'target_source' => sanitize_key( (string) ( $surface['target_source'] ?? '' ) ),
                'authorizing' => false,
            );
        }
        usort( $drift, static function ( $a, $b ) {
            $ak = sprintf( '%010d|%010d|%s', (int) ( $a['template_id'] ?? 0 ), (int) ( $a['filter_id'] ?? 0 ), (string) ( $a['query_id'] ?? '' ) );
            $bk = sprintf( '%010d|%010d|%s', (int) ( $b['template_id'] ?? 0 ), (int) ( $b['filter_id'] ?? 0 ), (string) ( $b['query_id'] ?? '' ) );
            return strcmp( $ak, $bk );
        } );

        $available = ! empty( $templates['available'] ) && $definitionSourceAvailable;
        return array(
            'contract' => self::CONTRACT,
            'authorizing' => false,
            'read_only' => true,
            'profile_mutation' => false,
            'available' => $available,
            'sources' => array(
                'templates' => (string) ( $templates['source'] ?? '' ),
                'filter_definitions' => $this->filterProvider ? 'injected_filter_provider' : ( $definitionSourceAvailable ? 'wordpress_post_meta' : 'wordpress_post_meta_unavailable' ),
            ),
            'templates_scanned' => count( $templates['items'] ),
            'elements_scanned' => $elementsScanned,
            'truncated' => $truncated || ! empty( $templates['truncated'] ),
            'surface_count' => $surfaceCount,
            'surfaces' => array_slice( $surfaces, 0, self::MAX_SURFACES ),
            'surfaces_truncated' => $surfaceCount > self::MAX_SURFACES,
            'definition_count' => count( $definitions ),
            'drift_count' => count( $drift ),
            'drift' => array_slice( $drift, 0, self::MAX_DRIFT ),
            'drift_truncated' => count( $drift ) > self::MAX_DRIFT,
        );
    }

    private function templates(): array {
        if ( $this->templateProvider ) {
            try {
                $items = call_user_func( $this->templateProvider );
                if ( $items instanceof \Traversable ) { $items = iterator_to_array( $items, false ); }
                if ( ! is_array( $items ) ) { return array( 'available'=>false, 'source'=>'injected_template_provider_invalid', 'items'=>array(), 'truncated'=>false ); }
                return array(
                    'available'=>true,
                    'source'=>'injected_template_provider',
                    'items'=>array_slice( array_values( $items ), 0, self::MAX_TEMPLATES ),
                    'truncated'=>count( $items ) > self::MAX_TEMPLATES,
                );
            } catch ( \Throwable $error ) {
                return array( 'available'=>false, 'source'=>'injected_template_provider_exception', 'items'=>array(), 'truncated'=>false );
            }
        }
        if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
            return array( 'available'=>false, 'source'=>'elementor_template_api_unavailable', 'items'=>array(), 'truncated'=>false );
        }
        try {
            $ids = get_posts( array(
                'post_type'=>'elementor_library',
                'post_status'=>array( 'publish','draft','private' ),
                'posts_per_page'=>self::MAX_TEMPLATES + 1,
                'orderby'=>'ID',
                'order'=>'ASC',
                'fields'=>'ids',
                'no_found_rows'=>true,
                'suppress_filters'=>true,
            ) );
            $ids = is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
            $truncated = count( $ids ) > self::MAX_TEMPLATES;
            $items = array();
            foreach ( array_slice( $ids, 0, self::MAX_TEMPLATES ) as $id ) {
                $raw = get_post_meta( $id, '_elementor_data', true );
                if ( is_string( $raw ) && '' !== trim( $raw ) ) { $items[] = array( 'id'=>$id, 'data'=>$raw ); }
            }
            return array( 'available'=>true, 'source'=>'elementor_library_elementor_data', 'items'=>$items, 'truncated'=>$truncated );
        } catch ( \Throwable $error ) {
            return array( 'available'=>false, 'source'=>'elementor_template_scan_exception', 'items'=>array(), 'truncated'=>false );
        }
    }

    private function walk( array $nodes, int $templateId, array &$surfaces, array &$definitions, int &$surfaceCount, int &$elementsScanned, bool &$truncated ): void {
        foreach ( $nodes as $node ) {
            if ( $elementsScanned >= self::MAX_ELEMENTS ) { $truncated = true; return; }
            if ( ! is_array( $node ) ) { continue; }
            $elementsScanned++;
            $widgetType = sanitize_key( (string) ( $node['widgetType'] ?? '' ) );
            $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
            if ( 0 === strpos( $widgetType, 'jet-smart-filters-' ) ) {
                $filterId = $this->filterId( $settings );
                if ( $filterId > 0 ) {
                    if ( ! array_key_exists( $filterId, $definitions ) ) { $definitions[ $filterId ] = $this->definition( $filterId ); }
                    $definition = is_array( $definitions[ $filterId ] ) ? $definitions[ $filterId ] : array();
                    $surfaceCount++;
                    if ( count( $surfaces ) < self::MAX_SURFACES ) {
                        $surfaces[] = array(
                            'template_id'=>$templateId,
                            'node_id'=>sanitize_text_field( (string) ( $node['id'] ?? '' ) ),
                            'widget_type'=>$widgetType,
                            'filter_id'=>$filterId,
                            'query_id'=>QueryId::normalize( $settings['query_id'] ?? '' ),
                            'content_provider'=>sanitize_key( (string) ( $settings['content_provider'] ?? '' ) ),
                            'definition_available'=>! empty( $definition['available'] ),
                            'data_source'=>sanitize_key( (string) ( $definition['data_source'] ?? '' ) ),
                            'source_taxonomy'=>sanitize_key( (string) ( $definition['source_taxonomy'] ?? '' ) ),
                            'target_taxonomy'=>sanitize_key( (string) ( $definition['target_taxonomy'] ?? '' ) ),
                            'target_source'=>sanitize_key( (string) ( $definition['target_source'] ?? '' ) ),
                            'query_var'=>sanitize_text_field( (string) ( $definition['query_var'] ?? '' ) ),
                            'custom_query_var'=>sanitize_text_field( (string) ( $definition['custom_query_var'] ?? '' ) ),
                        );
                    }
                }
            }
            if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
                $this->walk( $node['elements'], $templateId, $surfaces, $definitions, $surfaceCount, $elementsScanned, $truncated );
            }
            if ( $truncated ) { return; }
        }
    }

    private function filterId( array $settings ): int {
        foreach ( array( 'filter_id', 'filter' ) as $key ) {
            if ( ! array_key_exists( $key, $settings ) ) { continue; }
            $value = $settings[ $key ];
            if ( is_array( $value ) && isset( $value['id'] ) ) { $value = $value['id']; }
            if ( is_scalar( $value ) && is_numeric( $value ) ) {
                $id = absint( $value );
                if ( $id > 0 ) { return $id; }
            }
        }
        return 0;
    }

    private function definition( int $filterId ): array {
        if ( $this->filterProvider ) {
            try {
                $value = call_user_func( $this->filterProvider, $filterId );
                if ( is_array( $value ) ) { return $this->normalizeDefinition( $value ); }
                return array( 'available'=>false );
            } catch ( \Throwable $error ) { return array( 'available'=>false ); }
        }
        if ( ! function_exists( 'get_post_meta' ) ) { return array( 'available'=>false ); }
        try {
            $raw = array(
                '_data_source'=>get_post_meta( $filterId, '_data_source', true ),
                '_source_taxonomy'=>get_post_meta( $filterId, '_source_taxonomy', true ),
                '_query_var'=>get_post_meta( $filterId, '_query_var', true ),
                '_is_custom_query_var'=>get_post_meta( $filterId, '_is_custom_query_var', true ),
                '_custom_query_var'=>get_post_meta( $filterId, '_custom_query_var', true ),
                '_query_builder_query'=>get_post_meta( $filterId, '_query_builder_query', true ),
            );
            return $this->normalizeDefinition( $raw );
        } catch ( \Throwable $error ) { return array( 'available'=>false ); }
    }

    private function normalizeDefinition( array $raw ): array {
        $dataSource = sanitize_key( (string) ( $raw['data_source'] ?? $raw['_data_source'] ?? '' ) );
        $sourceTaxonomy = sanitize_key( (string) ( $raw['source_taxonomy'] ?? $raw['_source_taxonomy'] ?? '' ) );
        $queryVar = trim( (string) ( $raw['query_var'] ?? $raw['_query_var'] ?? '' ) );
        $customQueryVar = trim( (string) ( $raw['custom_query_var'] ?? $raw['_custom_query_var'] ?? '' ) );
        $customEnabled = $this->truthy( $raw['is_custom_query_var'] ?? $raw['_is_custom_query_var'] ?? false );
        $targetSource = '';
        $targetTaxonomy = '';
        if ( $customEnabled && '' !== $customQueryVar ) {
            $targetTaxonomy = $this->taxonomyFromQueryVar( $customQueryVar );
            if ( '' !== $targetTaxonomy ) { $targetSource = 'custom_query_var'; }
        }
        if ( '' === $targetTaxonomy && '' !== $queryVar ) {
            $targetTaxonomy = $this->taxonomyFromQueryVar( $queryVar );
            if ( '' !== $targetTaxonomy ) { $targetSource = 'query_var'; }
        }
        return array(
            'available'=>true,
            'data_source'=>$dataSource,
            'source_taxonomy'=>$sourceTaxonomy,
            'query_var'=>$queryVar,
            'custom_query_var'=>$customQueryVar,
            'custom_query_enabled'=>$customEnabled,
            'query_builder_query'=>sanitize_text_field( (string) ( $raw['query_builder_query'] ?? $raw['_query_builder_query'] ?? '' ) ),
            'target_taxonomy'=>$targetTaxonomy,
            'target_source'=>$targetSource,
        );
    }

    private function taxonomyFromQueryVar( string $value ): string {
        $value = trim( $value );
        if ( ! preg_match( '/\A_tax_query::([A-Za-z0-9_-]+)\z/', $value, $match ) ) { return ''; }
        return sanitize_key( (string) $match[1] );
    }

    private function truthy( $value ): bool {
        if ( is_bool( $value ) ) { return $value; }
        if ( is_numeric( $value ) ) { return 0 !== (int) $value; }
        return in_array( strtolower( trim( (string) $value ) ), array( '1','yes','true','on' ), true );
    }
}
