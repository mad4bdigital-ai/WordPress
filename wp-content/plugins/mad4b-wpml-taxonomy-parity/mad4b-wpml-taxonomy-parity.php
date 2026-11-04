<?php
/**
 * Plugin Name: MAD4B WPML Taxonomy Parity
 * Description: Native WPML TRID-based translated term reconciliation for supported JetEngine post types, governed by MAD4B runtime policy.
 * Version: 1.0.0
 * Requires Plugins: sitepress-multilingual-cms
 * Requires PHP: 8.0
 * Text Domain: mad4b-wpml-taxonomy-parity
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_WPML_Term_Parity_V1', false)) {
    require_once __DIR__ . '/includes/class-mad4b-wpml-term-parity-v1.php';
}
