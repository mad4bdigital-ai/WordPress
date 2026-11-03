<?php
namespace DynamicFilterSEOBridge\Runtime;

use DynamicFilterSEOBridge\Diagnostics\BuildIdentity;

/**
 * Independent, non-authorizing capability gates.
 *
 * Presentation and filter-context reads require verified installed source bytes,
 * but NOT SEO publication attestation. Full boot and all publishing permissions
 * remain exclusively under BootGuard and the existing publication policy.
 */
final class RuntimeLayers {
    private static $decision = null;

    public static function decide(array $identity, array $boot): array {
        $sourceValid = !empty($identity['valid'])
            && !empty($identity['installed_content_valid'])
            && !empty($identity['provenance_complete'])
            && ($identity['exact_build_reason'] ?? '') === 'ok';
        $safeRead = $sourceValid && empty($boot['faulted'])
            && !empty($boot['build_matches']);
        return array(
            'contract' => 'dfsb.runtime-layers.v1',
            'source_verified' => $sourceValid,
            'presentation_read' => $safeRead,
            'filter_context_read' => $safeRead,
            // Reporting only. Never authorizes any SEO write or route.
            'seo_full_boot_held' => !empty($boot['effective_hold']),
            'read_only' => true,
            'authorizing' => false,
        );
    }

    public static function status(): array {
        if (null === self::$decision) {
            self::$decision = self::decide(
                BuildIdentity::collect(), BootGuard::status()
            );
        }
        return self::$decision;
    }

    public static function canPresent(): bool {
        return !empty(self::status()['presentation_read']);
    }

    public static function canReadFilterContext(): bool {
        return !empty(self::status()['filter_context_read']);
    }

    // Deliberately delegates; no independent SEO publication path is created.
    public static function seoPublicationBlocked(): bool {
        return BootGuard::shouldHold() || !BootGuard::releaseReadiness()['ready'];
    }
}
