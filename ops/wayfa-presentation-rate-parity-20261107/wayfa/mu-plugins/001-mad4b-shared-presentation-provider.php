<?php
/**
 * Plugin Name: Shared Product Core — Native Presentation Providers
 * Description: Load original WordPress, WPML, JetEngine and JetSmartFilters public presentation adapters without enabling financial or supplier actions.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

// The normal Shared Product Core loader is the single owner when commerce is
// already operational. Never register a second copy of the original adapters.
if (defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED) return;

// A host may explicitly suspend this presentation-only runtime. No condition
// on staging, production, brand, environment name, or tenant domain.
if (defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && !MAD4B_PRESENTATION_RUNTIME_ENABLED) return;
if (!defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')) define('MAD4B_PRESENTATION_RUNTIME_ENABLED', true);

// Use the original core presentation policy wherever its source module is installed.
$originalPolicy = __DIR__ . '/098-presentation-policy-context.php';
if (is_readable($originalPolicy) && !class_exists('Presentation_Policy_Context_v1', false)) {
    require_once $originalPolicy;
}
$root = __DIR__ . '/shared-product-core/';
$modules = [
    'core.php',
    'adapters/presentation-context-registry-v2.php',
    'adapters/presentation-native-facet-discovery-v1.php',
    'adapters/presentation-native-consumer-policy-v1.php',
    'adapters/presentation-company-destination-facet-v1.php',
    'adapters/presentation-native-filter-policy-v1.php',
    'adapters/presentation-catalog-context-v1.php',
    'adapters/presentation-native-destination-query-guard-v1.php',
    // Source-native read-only quote/projection providers. No cart, order,
    // payment, refund, or supplier executor is loaded by these modules.
    'pricing/currency-provider.php',
    'pricing/rate-locale-policy.php',
    'pricing/financial-field-choices-readonly.php',
    'pricing/jetengine-policy-provider.php',
    'pricing/source-resolution-policy.php',
    'pricing/group-tier-policy.php',
    'pricing/price-presenter.php',
    'pricing/price-resolver.php',
    // Read-only source-native eligibility and JetSmartFilters facets;
    // evaluate final display quotes but do not enable booking/supplier actions.
    'adapters/tour-rate-eligible-query.php',
    'adapters/tour-rate-facets-from-query.php',
];
foreach ($modules as $relative) {
    $file = $root . $relative;
    if (!is_file($file) || !is_readable($file)) {
        // Keep the original WordPress site alive if a company bundle is
        // incomplete. Do not attempt any commercial fallback.
        return;
    }
}
foreach ($modules as $relative) require_once $root . $relative;

// The original WPML archive URL filter may be unregistered by another native
// integration during bootstrap. Reattach WPML itself (never rewrite URLs or
// invent locale slugs), and only when it is currently absent.
add_action('wp_loaded', static function (): void {
    $sitepress = $GLOBALS['sitepress'] ?? null;
    $callback = [$sitepress, 'post_type_archive_link_filter'];
    if (is_object($sitepress) && is_callable($callback)
        && has_filter('post_type_archive_link', $callback) === false) {
        add_filter('post_type_archive_link', $callback, 10, 2);
    }
}, 1000);
