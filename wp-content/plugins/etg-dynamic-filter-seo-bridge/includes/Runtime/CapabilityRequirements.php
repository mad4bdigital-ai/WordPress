<?php
namespace ETG\DynamicFilterSEOBridge\Runtime;

require_once __DIR__ . '/AdapterRegistry.php';

final class CapabilityRequirements {
    public const CONTRACT = 'etg.dfsb.capability-requirements.v2';

    public static function forProfiles( array $profiles, bool $enabledOnly = true ): array {
        $dependencies = array();
        $vendorCapabilities = array();
        $semanticCapabilities = array();
        $adapters = array();
        $unsupportedProviders = array();
        $unsupportedSemantic = array();
        $byProfile = array();

        foreach ( $profiles as $profileId => $profile ) {
            if ( ! is_array( $profile ) ) { continue; }
            $id = self::cleanKey( $profile['id'] ?? $profileId );
            if ( '' === $id ) { $id = self::cleanKey( $profileId ); }
            $enabled = ! empty( $profile['enabled'] );
            if ( $enabledOnly && ! $enabled ) { continue; }

            $semantic = array();
            $profileUnsupportedProviders = array();
            foreach ( (array) ( $profile['routes'] ?? array() ) as $route ) {
                if ( ! is_array( $route ) ) { continue; }
                $provider = self::cleanKey( $route['provider'] ?? '' );
                if ( '' === $provider ) { continue; }
                if ( 'jet-engine' === $provider ) {
                    foreach ( array( 'filter.url_state', 'filter.ajax_transport', 'query.filtered_dataset', 'query.result_count' ) as $capability ) { self::add( $semantic, $capability ); }
                } else {
                    self::add( $profileUnsupportedProviders, $provider );
                }
            }

            $publication = (array) ( $profile['publication'] ?? array() );
            if ( ! empty( $publication['metadata'] ) ) { self::add( $semantic, 'seo.metadata' ); }
            if ( ! empty( $publication['sitemap'] ) ) { self::add( $semantic, 'seo.sitemap' ); }
            if ( ! empty( $publication['schema'] ) ) { self::add( $semantic, 'seo.schema' ); }
            if ( ! empty( $publication['social'] ) ) { self::add( $semantic, 'seo.social' ); }
            if ( ! empty( $publication['hreflang'] ) || ! empty( $publication['multilingual'] ) ) { self::add( $semantic, 'language.hreflang' ); }
            if ( ! empty( $publication['require_elementor_content'] ) || ! empty( $publication['elementor_render_when_global_off'] ) ) { self::add( $semantic, 'presentation.dynamic_content' ); }
            if ( ! empty( $publication['require_elementor_content'] ) ) { self::add( $semantic, 'presentation.theme_builder' ); }

            // Optional future-facing explicit requirements are accepted only
            // when they map to a registered semantic capability. They never
            // grant route or indexing authority by themselves.
            foreach ( (array) ( $profile['required_capabilities'] ?? array() ) as $capability ) {
                $capability = strtolower( trim( (string) $capability ) );
                if ( '' !== $capability ) { self::add( $semantic, $capability ); }
            }

            $resolved = AdapterRegistry::resolve( $semantic );
            $profileDependencies = $resolved['dependencies'];
            $profileVendorCapabilities = $resolved['vendor_capabilities'];
            $profileSemanticCapabilities = $resolved['semantic_capabilities'];
            $profileAdapters = $resolved['adapters'];
            $profileUnsupportedSemantic = $resolved['unsupported_semantic_capabilities'];

            $byProfile[ $id ] = array(
                'enabled' => $enabled,
                'semantic_capabilities' => $profileSemanticCapabilities,
                'adapters' => $profileAdapters,
                'dependencies' => $profileDependencies,
                'capabilities' => $profileVendorCapabilities,
                'vendor_capabilities' => $profileVendorCapabilities,
                'unsupported_providers' => $profileUnsupportedProviders,
                'unsupported_semantic_capabilities' => $profileUnsupportedSemantic,
                'bindings' => $resolved['bindings'],
            );
            $dependencies = array_merge( $dependencies, $profileDependencies );
            $vendorCapabilities = array_merge( $vendorCapabilities, $profileVendorCapabilities );
            $semanticCapabilities = array_merge( $semanticCapabilities, $profileSemanticCapabilities );
            $adapters = array_merge( $adapters, $profileAdapters );
            foreach ( $profileUnsupportedProviders as $provider ) { $unsupportedProviders[] = $id . ':' . $provider; }
            foreach ( $profileUnsupportedSemantic as $capability ) { $unsupportedSemantic[] = $id . ':' . $capability; }
        }

        foreach ( array( &$dependencies, &$vendorCapabilities, &$semanticCapabilities, &$adapters, &$unsupportedProviders, &$unsupportedSemantic ) as &$list ) {
            $list = array_values( array_unique( $list ) );
            sort( $list, SORT_STRING );
        }
        unset( $list );
        ksort( $byProfile, SORT_STRING );

        return array(
            'contract' => self::CONTRACT,
            'semantic_capabilities' => $semanticCapabilities,
            'adapters' => $adapters,
            'dependencies' => $dependencies,
            'capabilities' => $vendorCapabilities,
            'vendor_capabilities' => $vendorCapabilities,
            'unsupported_providers' => $unsupportedProviders,
            'unsupported_semantic_capabilities' => $unsupportedSemantic,
            'profiles' => $byProfile,
        );
    }

    public static function profileNeedsPublication( array $profile ): bool {
        $publication = (array) ( $profile['publication'] ?? array() );
        foreach ( array( 'metadata', 'sitemap', 'multilingual', 'hreflang', 'schema', 'social' ) as $key ) {
            if ( ! empty( $publication[ $key ] ) ) { return true; }
        }
        return false;
    }

    private static function add( array &$items, string $value ): void {
        if ( '' !== $value && ! in_array( $value, $items, true ) ) { $items[] = $value; }
    }

    private static function cleanKey( $value ): string {
        if ( function_exists( 'sanitize_key' ) ) { return sanitize_key( (string) $value ); }
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?: '';
    }
}
