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

    public static function bridgeSeoFallbackAllowed(): bool {
        return self::fallbackForAvailability(self::jetSmartFiltersAvailable());
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
            'overlapping_seo_owner' => $available ? 'jet_smart_filters' : 'dfsb_fallback_via_rank_math',
            'overlapping_seo_fallback_allowed' => !$available,
            'metadata_publisher' => 'rank_math',
            'sitemap_publisher' => 'rank_math',
            'unique_context_and_presentation_preserved' => true,
            'authorizing' => false,
            'mutation_exposed' => false,
        );
    }
}
