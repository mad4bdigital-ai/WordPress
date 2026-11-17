<?php
/**
 * Plugin Name: MAD4B Commerce
 * Description: Pricing, travel booking and WooCommerce modules; internal staged loading.
 * Version: 0.1.2
 * Requires PHP: 8.0
 */
defined('ABSPATH')||exit;
// Original read-only Rate UI and fingerprint-based Featured price recovery.
// Ownership and Commerce readiness guards are inside this first-party module.
require_once __DIR__.'/modules/presentation/native-rate-runtime-owner-v1.php';
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
require_once __DIR__ . '/modules/presentation/unified-date-presentation-v1.php';
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
// The native Fluent Forms and Woo travel-logistics adapter is part of the
// original MAD4B Commerce plugin, not a second checkout or shipping engine.
// Earlier versions left its existing module unregistered in Presentation mode.
add_action('plugins_loaded', static function (): void {
    if (class_exists('MAD4B_Booking_Pickup_Fields', false)) return;
    if (!class_exists('WooCommerce', false) || !defined('FLUENTFORM')) {
        // Fluent Forms may not define FLUENTFORM on every edition; inspect
        // the plugin's actual public class/function below as well.
        if (!function_exists('wpFluentForm') || !class_exists('WooCommerce', false)) return;
    }
    $file=__DIR__.'/modules/commerce/362-mad4b-booking-pickup-fields.php';
    if (is_readable($file)) require_once $file;
}, 25);

// Site-bound Rail supplier review settings are safe in Presentation-only mode:
// registration exposes WooCommerce settings but cannot create a Rate, Cart,
// Order, supplier approval or payment. The original Commerce loader remains
// authoritative when full Commerce is enabled.
add_action('plugins_loaded', static function (): void {
    if (class_exists('MAD4B_Rail_Woo_Settings_V1', false)) return;
    $presentation = defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')
        && MAD4B_PRESENTATION_RUNTIME_ENABLED === true;
    $commerce = defined('MAD4B_COMMERCE_RUNTIME_ENABLED')
        && MAD4B_COMMERCE_RUNTIME_ENABLED === true;
    if (!$presentation && !$commerce) return;
    require_once __DIR__ . '/modules/commerce/374-mad4b-rail-woocommerce-settings.php';
}, 14);

// Source Commerce may already own this module; require_once preserves it.
// Presentation-only companies get a bound, stale-only fallback, not a second
// pricing or checkout system.
add_action('plugins_loaded', static function (): void {
    if (function_exists('mad4b_fx_snapshot_file')) return;
    $presentation = defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')
        && MAD4B_PRESENTATION_RUNTIME_ENABLED === true;
    $commerce = defined('MAD4B_COMMERCE_RUNTIME_ENABLED')
        && MAD4B_COMMERCE_RUNTIME_ENABLED === true;
    if (!$presentation && !$commerce) return;
    require_once __DIR__ . '/modules/compat/pricing-fx-auto-refresh.php';
}, 14);

// Currency rendering is presentation functionality: independent of checkout.
// Preserve the source-native owner when the original Commerce bootstrap loaded it.
add_action('plugins_loaded', static function (): void {
    if (shortcode_exists('pricing_header_switcher')) return;
    $presentation = defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED === true;
    $commerce = defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED === true;
    if (!$presentation && !$commerce) return;
    require_once __DIR__ . '/modules/commerce/pricing-header-switcher.php';
}, 15);


// Engine-priced Elementor cards are read-only Presentation contracts.
// Central Commerce loads this from its original module map; companies without
// the Commerce executor must still register the identical native Dynamic Tag.
// Do not activate checkout, payment, supplier or order mutation from this path.
add_action('plugins_loaded', static function (): void {
    $presentation = defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED === true;
    $commerce = defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED === true;
    if ((!$presentation && !$commerce) || function_exists('tour_component_structured_price_value')) return;
    $module = __DIR__ . '/modules/commerce/tour-component-structured-price-tags.php';
    if (is_readable($module) && function_exists('tour_component_price_evidence')) require_once $module;
}, 23);

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

require_once __DIR__.'/modules/presentation/company-mode-pricing-defaults-v1.php';
