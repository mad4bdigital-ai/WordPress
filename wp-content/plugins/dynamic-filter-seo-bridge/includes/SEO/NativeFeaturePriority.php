<?php
namespace DynamicFilterSEOBridge\SEO;

/**
 * Native-first SEO output arbitration, never a license to publish.
 *
 * JetSmartFilters owns its filtering/SEO feature surfaces whenever the
 * plugin is available. DFSB may supply overlapping SEO values to Rank Math
 * only in installations without JetSmartFilters. The unique DFSB context,
 * result parity, policy and Elementor presentation remain independent.
 *
 * Do not treat a disabled SEO setting or an unmatched rule as an absent
 * plugin: that would create competing outputs the moment native rules change.
 */
final class NativeFeaturePriority {
    public const CONTRACT = 'dfsb.seo-native-feature-priority.v1';

    public static function jetSmartFiltersAvailable(): bool {
        return function_exists('jet_smart_filters');
    }

    public static function jetSmartFiltersSeoActive(): bool {
        if ( ! self::jetSmartFiltersAvailable() ) return false;
        try {
            $vendor = jet_smart_filters();
            return isset( $vendor->settings ) && ! empty( $vendor->settings->is_seo_enabled );
        } catch ( \Throwable $e ) {
            // An unavailable native SEO setting never proves that it is disabled.
            return true;
        }
    }

    public static function bridgeSeoFallbackAllowed(): bool {
        // JetSmartFilters may be active for faceting while its separate SEO engine
        // is explicitly OFF. Rank Math remains the single metadata publisher then.
        return ! self::jetSmartFiltersAvailable() || ! self::jetSmartFiltersSeoActive();
    }

    public static function fallbackForAvailability(bool $jetSmartFiltersAvailable): bool {
        return !$jetSmartFiltersAvailable;
    }

    /** Diagnostic output only; cannot enable any functionality. */
    public static function inspect(): array {
        $available = self::jetSmartFiltersAvailable();
        return array(
            'contract' => self::CONTRACT,
            'native_filter_plugin_available' => $available,
            'native_filter_seo_active' => self::jetSmartFiltersSeoActive(),
            'overlapping_seo_owner' => $available && self::jetSmartFiltersSeoActive() ? 'jet_smart_filters' : 'dfsb_fallback_via_rank_math',
            'overlapping_seo_fallback_allowed' => self::bridgeSeoFallbackAllowed(),
            'metadata_publisher' => 'rank_math',
            'sitemap_publisher' => 'rank_math',
            'unique_context_and_presentation_preserved' => true,
            'authorizing' => false,
            'mutation_exposed' => false,
        );
    }
}
