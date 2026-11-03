<?php
/** Plugin Name: Shared Product Core Loader (Lab) */
if (!defined('ABSPATH')) exit;
if (wp_get_environment_type() !== 'local' || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
$root=__DIR__.'/shared-product-core/';
require_once $root.'core.php';
require_once $root.'contracts/field-shape-contract-v1.php';
require_once $root.'contracts/primary-tour-query-context-v1.php';
require_once $root.'scheduling/policy-settings.php';
require_once $root.'scheduling/advance-notice.php';
require_once $root.'scheduling/program-calendar.php';
require_once $root.'pricing/page-options-provider.php';
require_once $root.'pricing/jetengine-policy-provider.php';
require_once $root.'pricing/strategy-registry.php';
require_once $root.'pricing/strategy-evaluators.php';
require_once $root.'pricing/unit-resolver.php';
require_once $root.'pricing/booking-addons.php';
require_once $root.'pricing/booking-modifiers.php';
require_once $root.'pricing/composite-quote.php';
require_once $root.'pricing/payment-offers-visa.php';
// JetEngine Options Page owns financial-policy administration.
require_once $root.'pricing/visa-matrix.php';
require_once $root.'pricing/dynamic-option-sources.php';
require_once $root.'pricing/gateway-availability.php';
require_once $root.'pricing/travel-scope.php';
require_once $root.'pricing/tour-origin-options.php';
require_once $root.'pricing/financial-inheritance.php';
require_once $root.'pricing/financial-ledger.php';
require_once $root.'pricing/financial-order-snapshot.php';
require_once $root.'pricing/global-offer-verifier.php';
require_once $root.'pricing/global-offer-price-evidence.php';
require_once $root.'pricing/global-offer-checkout.php';
require_once $root.'pricing/global-offer-coupon.php';
require_once $root.'pricing/financial-order-adapter.php';
require_once $root.'pricing/financial-refund-lock.php';
require_once $root.'pricing/refund-provider-adapter.php';
require_once $root.'pricing/refund-provider-recheck.php';
require_once $root.'admin/woocommerce-refund-recheck.php';
require_once $root.'admin/woocommerce-financial-panel.php';
foreach (['currency-provider.php','price-resolver.php','price-presenter.php','occupancy-presenter.php','audience-resolver.php','rate-field-presenter.php'] as $module) {
 require_once $root.'pricing/'.$module;
}
require_once $root.'pricing/rate-generation.php';
require_once $root.'pricing/rate-source-batch.php';
require_once $root.'admin/rate-generation.php';
foreach (['elementor-pricing.php','jetengine-pricing.php','woocommerce-pricing.php','form-pricing.php'] as $adapter) {
 require_once $root.'adapters/'.$adapter;
}

require_once $root.'pricing/taxonomy-pricing-context.php';
require_once $root.'pricing/market-price-policy.php';
require_once $root.'adapters/nationality-source.php';
require_once $root.'pricing/booking-configuration-v2.php';
require_once $root.'scheduling/eligibility.php';
require_once $root.'pricing/group-tier-policy.php';
require_once $root.'pricing/package-level-policy.php';
require_once $root.'pricing/booking-cart-v2.php';
require_once $root.'pricing/rules-registry-v2.php';
require_once $root.'pricing/rules-evaluators-v2.php';
require_once $root.'pricing/financial-readiness-v2.php';
require_once $root.'admin/pricing-policy-readiness.php';
require_once $root.'adapters/woocommerce-booking-configuration.php';

require_once $root.'adapters/form-booking-intent.php';
// Original Fluent Form #6 + native Tour Rate selections, local read-only.
require_once $root.'adapters/presentation-form6-candidate-v1.php';
// Optional non-booking subtotal/total REST quote for existing Fluent Form #6.
require_once $root.'adapters/presentation-form6-price-quote-v1.php';

require_once $root.'adapters/tour-rate-dynamic-search.php';

require_once $root.'adapters/tour-rate-related-options.php';

require_once $root.'adapters/elementor-visual-pickers.php';
// Read-only compatibility introspection; no vendor hooks or writes.
require_once $root.'adapters/presentation-compatibility-manifest-v1.php';
require_once $root.'adapters/presentation-source-field-guard-v1.php';
// Read-only, local-only native display adapter arbitration.
require_once $root.'adapters/presentation-adapter-registry-v1.php';
// Read-only WPML/Listing context inspection for Local Lab.
require_once $root.'adapters/presentation-context-v1.php';
// One canonical catalog contract for WPML facets, signed JSF and WCML presentation.
require_once $root.'adapters/presentation-context-registry-v2.php';
require_once $root.'adapters/presentation-native-facet-discovery-v1.php';
require_once $root.'adapters/presentation-company-destination-facet-v1.php';
require_once $root.'adapters/presentation-native-consumer-policy-v1.php';
require_once $root.'adapters/presentation-native-filter-policy-v1.php';
require_once $root.'adapters/presentation-native-typed-source-registry-v1.php';
require_once $root.'adapters/presentation-native-fluent-form-sources-v1.php';
require_once $root.'adapters/presentation-native-commercial-source-registry-v2.php';
require_once $root.'adapters/presentation-native-filter-diagnostics-v1.php';
require_once $root.'adapters/presentation-query-consumer-registry-v1.php';
require_once $root.'adapters/presentation-native-jetengine-price-v1.php';
require_once $root.'adapters/presentation-tour-rate-date-facet-v1.php';
require_once $root.'adapters/presentation-blog-native-facets-v1.php';
require_once $root.'adapters/presentation-catalog-context-v1.php';
require_once $root.'adapters/presentation-native-destination-query-guard-v1.php';
require_once $root.'adapters/presentation-destination-commercial-listing-guard-v1.php';
require_once $root.'adapters/presentation-native-rate-eligibility-preview-v1.php';
require_once $root.'adapters/presentation-catalog-price-facet-v1.php';
require_once $root.'adapters/presentation-jsf-pagination-v2.php';
// Tour creation screen: type-dependent Direct vs Seed Rate UI, no rate mutations.
require_once $root.'adapters/presentation-tour-pricing-entry-v1.php';
// Authoritative saved-CPT seed -> existing native Draft Rate Generator.
require_once $root.'adapters/tour-rate-seed-bridge-v1.php';
// Native Tour Transportation display mode, taxonomy and signed Listing context.
require_once $root.'adapters/presentation-transport-context-v1.php';
// Read-only unified discovery search presentation: WP Posts OR Taxonomy Terms.
require_once $root.'adapters/presentation-discovery-search-v1.php';
require_once $root.'adapters/presentation-destination-term-v1.php';
require_once $root.'adapters/presentation-taxonomy-archive-v1.php';

require_once $root.'pricing/property-layer-policy.php';

require_once $root.'adapters/package-tier-index.php';

require_once $root.'adapters/tour-rate-package-filter-options.php';


require_once $root.'adapters/tour-rate-eligible-query.php';

require_once $root.'adapters/tour-rate-ajax-context.php';

require_once $root.'adapters/tour-rate-filter-url-context.php';


require_once $root.'adapters/tour-rate-season-url-context.php';

require_once $root.'adapters/tour-rate-season-filter-options.php';
require_once $root.'adapters/tour-program-source-contract-v1.php';
require_once $root.'adapters/tour-rate-facets-from-query.php';
require_once $root.'adapters/pricing-legacy-template-tags.php';

require_once $root.'adapters/booking-config-validation-guard.php';

require_once $root.'adapters/cpt-product-price-compat.php';
require_once $root.'adapters/cpt-woo-rate-guard.php';
require_once $root.'admin/cpt-woo-rate-link.php';
require_once $root.'adapters/booking-preview-bridge.php';

require_once $root.'adapters/rate-actions-styles.php';

require_once $root.'adapters/rate-selection-actions.php';
require_once $root.'adapters/extra-selection-actions.php';
require_once $root.'pricing/source-resolution-policy.php';
