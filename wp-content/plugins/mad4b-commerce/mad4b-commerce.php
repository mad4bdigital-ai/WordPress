<?php
/**
 * Plugin Name: MAD4B Commerce
 * Description: Pricing, travel booking and WooCommerce modules; internal staged loading.
 * Version: 0.1.1
 * Requires PHP: 8.0
 */
defined('ABSPATH')||exit;
// Runtime modules are loaded early from the one MU bootstrap, in original
// order, to preserve authority and inter-module dependencies. This plugin
// supplies the inventory/Settings surface, not a second module-loader.
// The native price unit resolver is shared presentation infrastructure.
// It must remain available even when checkout/supplier Commerce Runtime is
// disabled. Never load a financial mutation adapter from this boot path.
add_action('plugins_loaded', static function (): void {
    $presentation = defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED === true;
    $commerce = defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED === true;
    if ((!$presentation && !$commerce) || function_exists('shared_product_resolve_price_unit')) return;
    $file = WPMU_PLUGIN_DIR . '/shared-product-core/pricing/unit-resolver.php';
    if (is_readable($file)) require_once $file;
}, 10);

require_once __DIR__ . '/modules/presentation/extra-selection-ui.php';
require_once __DIR__ . '/modules/presentation/company-top-cities-eligibility.php';
require_once __DIR__ . '/modules/presentation/company-listing-card-actions.php';
require_once __DIR__ . '/modules/presentation/company-global-offer-empty-wrapper.php';
require_once __DIR__ . '/modules/presentation/company-responsive-source-visual-v1.php';
require_once __DIR__ . '/modules/presentation/extra-quote-readonly-runtime-v1.php';
require_once __DIR__ . '/modules/presentation/company-presentation-form6-price-readonly-v1.php';
require_once __DIR__ . '/modules/compat/local-presentation-fluent-projection-v1.php';
require_once __DIR__ . '/modules/presentation/company-price-preview-reconcile-provider-v1.php';
require_once __DIR__ . '/modules/presentation/company-system-appearance-v1.php';
require_once __DIR__ . '/modules/presentation/company-admin-ui-translation-contentjobs-v1.php';
require_once __DIR__ . '/modules/presentation/local-presentation-selection-registry-v1.php';
add_action('plugins_loaded', static function (): void {
    $presentation = defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')
        && MAD4B_PRESENTATION_RUNTIME_ENABLED === true;
    $commerce = defined('MAD4B_COMMERCE_RUNTIME_ENABLED')
        && MAD4B_COMMERCE_RUNTIME_ENABLED === true;
    if (!$presentation || $commerce) return;
    $root = WPMU_PLUGIN_DIR . '/shared-product-core/';
    // No Woo Cart, Checkout, Order, payment, supplier or inventory mutation.
    foreach ([
        'pricing/audience-resolver.php',
        'pricing/strategy-registry.php',
        'pricing/quote-snapshot-readonly-v1.php',
        'pricing/rate-date-eligibility-readonly-v1.php',
        'pricing/extra-source-quote.php'
    ] as $module) {
        $file = $root . $module;
        if (!is_readable($file)) return; // Fail closed; status will show missing providers.
        require_once $file;
    }
}, 12);
// Currency rendering is presentation functionality: independent of checkout.
// Preserve the source-native owner when the original Commerce bootstrap loaded it.
add_action('plugins_loaded', static function (): void {
    if (shortcode_exists('pricing_header_switcher')) return;
    $presentation = defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED === true;
    $commerce = defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED === true;
    if (!$presentation && !$commerce) return;
    require_once __DIR__ . '/modules/commerce/pricing-header-switcher.php';
}, 15);


// Load the original public price renderer in tenant Presentation-only mode.
// It owns just the public shortcode and its CSS, never checkout or pricing writes.
add_action('plugins_loaded', static function (): void {
    $presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
    $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED===true;
    if (!$presentation || $commerce) return;
    require_once __DIR__.'/modules/commerce/local-tour-public-price-v1.php';
}, 25);

final class MAD4B_Commerce_Modules_V1 {
 public const VERSION='0.1.1';
 public static function installed():bool {return true;}
}
