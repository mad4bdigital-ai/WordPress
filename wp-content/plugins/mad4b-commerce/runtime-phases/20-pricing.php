<?php
// Original ordered Shared Product Core source lines 41–65; eager until separately certified.
if (!defined('ABSPATH')) exit;
require_once $root.'pricing/rate-locale-policy.php';
foreach (['currency-provider.php','price-resolver.php','price-presenter.php','occupancy-presenter.php','audience-resolver.php','rate-field-presenter.php'] as $module) {
 require_once $root.'pricing/'.$module;
}
require_once $root.'pricing/occupancy-policy-v1.php';
// Both class-only rate generation providers autoload at first authorized use.
// Preserve early housing assignment declarations that previously loaded as
// a dependency of the Rate Generator class declaration.
require_once $root.'pricing/accommodation-assignment.php';
spl_autoload_register(static function (string $name) use ($root): void {
 if ($name === 'Shared_Product_Rate_Generation') {
  require_once $root.'pricing/rate-generation.php';
 } elseif ($name === 'Shared_Product_Rate_Source_Batch') {
  if (!class_exists('Shared_Product_Rate_Generation', false)) {
   require_once $root.'pricing/rate-generation.php';
  }
  require_once $root.'pricing/rate-source-batch.php';
 }
}, true, false);
if ($mad4b_shared_core_admin_registrars) require_once $root.'admin/rate-generation.php';
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
if ($mad4b_shared_core_admin_registrars) require_once $root.'admin/pricing-policy-readiness.php';
require_once $root.'adapters/woocommerce-booking-configuration.php';
