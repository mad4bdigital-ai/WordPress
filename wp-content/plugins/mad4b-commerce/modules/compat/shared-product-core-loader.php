<?php
/** Plugin Name: Shared Product Core Loader (Lab) */
if (!defined('ABSPATH')) exit;
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) || !defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) return;
$root=WPMU_PLUGIN_DIR.'/shared-product-core/';
// Admin-only registrar deferral. No change to any price, refund or booking handler.
// WP-CLI and Cron retain the original eager declarations for compatibility.
$mad4b_shared_core_admin_registrars = is_admin()
 || (defined('WP_CLI') && WP_CLI)
 || (function_exists('wp_doing_cron') && wp_doing_cron());
// Source-owned phases: the loader preserves every require_once and its order.
require __DIR__ . '/../../runtime-phases/00-foundation.php';
require __DIR__ . '/../../runtime-phases/10-financial.php';
require __DIR__ . '/../../runtime-phases/20-pricing.php';
require __DIR__ . '/../../runtime-phases/30-presentation.php';
require __DIR__ . '/../../runtime-phases/40-booking-and-filters.php';
