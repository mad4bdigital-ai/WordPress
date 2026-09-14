<?php
namespace ETG\DynamicFilterSEOBridge\Runtime;

final class AdapterRegistry {
    public const CONTRACT = 'etg.dfsb.adapter-registry.v1';

    public static function definitions(): array {
        return array(
            'filter.url_state' => array(
                'adapter' => 'jet_smart_filters',
                'dependencies' => array( 'jet_smart_filters' ),
                'vendor_capabilities' => array( 'jsf_get_current_provider' ),
            ),
            'filter.ajax_transport' => array(
                'adapter' => 'jet_smart_filters',
                'dependencies' => array( 'jet_smart_filters' ),
                'vendor_capabilities' => array( 'jsf_get_query_from_request' ),
            ),
            'query.filtered_dataset' => array(
                'adapter' => 'jet_engine',
                'dependencies' => array( 'jet_engine' ),
                'vendor_capabilities' => array( 'jet_engine_query_manager' ),
            ),
            'query.result_count' => array(
                'adapter' => 'jet_engine',
                'dependencies' => array( 'jet_engine' ),
                'vendor_capabilities' => array( 'jet_engine_query_manager' ),
            ),
            'seo.metadata' => array(
                'adapter' => 'rank_math',
                'dependencies' => array( 'rank_math' ),
                'vendor_capabilities' => array(),
            ),
            'seo.schema' => array(
                'adapter' => 'rank_math',
                'dependencies' => array( 'rank_math' ),
                'vendor_capabilities' => array(),
            ),
            'seo.social' => array(
                'adapter' => 'rank_math',
                'dependencies' => array( 'rank_math' ),
                'vendor_capabilities' => array(),
            ),
            'seo.sitemap' => array(
                'adapter' => 'rank_math',
                'dependencies' => array( 'rank_math' ),
                'vendor_capabilities' => array(
                    'rank_math_sitemap_provider_interface',
                    'rank_math_sitemap_router',
                    'rank_math_sitemap_cache',
                ),
            ),
            'language.hreflang' => array(
                'adapter' => 'wpml',
                'dependencies' => array( 'wpml' ),
                'vendor_capabilities' => array(
                    'wpml_current_language_filter',
                    'wpml_object_id_filter',
                    'wpml_active_languages_filter',
                    'wpml_permalink_filter',
                ),
            ),
            'presentation.dynamic_content' => array(
                'adapter' => 'elementor',
                'dependencies' => array( 'elementor' ),
                'vendor_capabilities' => array(),
            ),
            'presentation.theme_builder' => array(
                'adapter' => 'elementor_pro',
                'dependencies' => array( 'elementor', 'elementor_pro' ),
                'vendor_capabilities' => array(),
            ),
        );
    }

    public static function resolve( array $semanticCapabilities ): array {
        $definitions = self::definitions();
        $semantic = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'cleanCapability' ), $semanticCapabilities ) ) ) );
        sort( $semantic, SORT_STRING );
        $dependencies = array();
        $vendorCapabilities = array();
        $adapters = array();
        $unsupported = array();
        $bindings = array();

        foreach ( $semantic as $capability ) {
            if ( ! isset( $definitions[ $capability ] ) ) {
                $unsupported[] = $capability;
                continue;
            }
            $definition = $definitions[ $capability ];
            $adapter = (string) ( $definition['adapter'] ?? '' );
            if ( '' !== $adapter ) { $adapters[] = $adapter; }
            foreach ( (array) ( $definition['dependencies'] ?? array() ) as $dependency ) { $dependencies[] = (string) $dependency; }
            foreach ( (array) ( $definition['vendor_capabilities'] ?? array() ) as $vendorCapability ) { $vendorCapabilities[] = (string) $vendorCapability; }
            $bindings[ $capability ] = array(
                'adapter' => $adapter,
                'dependencies' => array_values( (array) ( $definition['dependencies'] ?? array() ) ),
                'vendor_capabilities' => array_values( (array) ( $definition['vendor_capabilities'] ?? array() ) ),
            );
        }

        foreach ( array( &$dependencies, &$vendorCapabilities, &$adapters, &$unsupported ) as &$list ) {
            $list = array_values( array_unique( array_filter( $list, 'strlen' ) ) );
            sort( $list, SORT_STRING );
        }
        unset( $list );
        ksort( $bindings, SORT_STRING );

        return array(
            'contract' => self::CONTRACT,
            'semantic_capabilities' => $semantic,
            'dependencies' => $dependencies,
            'vendor_capabilities' => $vendorCapabilities,
            'adapters' => $adapters,
            'unsupported_semantic_capabilities' => $unsupported,
            'bindings' => $bindings,
        );
    }

    private static function cleanCapability( $value ): string {
        $value = strtolower( trim( (string) $value ) );
        return preg_match( '/^[a-z][a-z0-9_.\-]{0,79}$/', $value ) ? $value : '';
    }
}
