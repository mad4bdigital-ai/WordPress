<?php
/**
 * Plugin Name: MAD4B Traveller Nationality (Legacy Compatibility)
 * Description: Historical activation basename only. Canonical runtime is MAD4B Traveller Nationality.
 * Version: 0.5.6-legacy
 * Requires Plugins: woocommerce
 * Text Domain: travel-booking-traveller-nationality-switcher
 */
defined('ABSPATH') || exit;
$canonical = dirname(__DIR__) . '/mad4b-traveller-nationality/mad4b-traveller-nationality.php';
if (!is_file($canonical)) return; // Old hosts require canonical-first installation.
require_once $canonical;
