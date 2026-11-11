<?php
/**
 * Plugin Name: MAD4B Distribution Connectors
 * Description: Portable company-local channel and marketplace connection registry, plus Meta Catalog adapter. No external sending until approved by provider and company.
 * Version: 1.6.2
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/includes/class-mad4b-distribution-local-credentials.php';
require_once __DIR__.'/includes/class-mad4b-distribution-channel-registry.php';
require_once __DIR__.'/includes/class-mad4b-distribution-activation.php';
require_once __DIR__.'/includes/class-mad4b-distribution-admin.php';
require_once __DIR__.'/includes/class-mad4b-catalog-data-epoch.php';
require_once __DIR__.'/includes/class-mad4b-catalog-studio.php';
require_once __DIR__.'/includes/class-mad4b-catalog-studio-admin.php';
if (!class_exists('MAD4B_Meta_Catalog_Adapter_V1',false)) {
    require_once __DIR__.'/includes/class-mad4b-meta-catalog-adapter.php';
}
register_activation_hook(__FILE__, static function () {
    if (class_exists('MAD4B_Distribution_Channel_Registry_V1')) {
        MAD4B_Distribution_Channel_Registry_V1::provision_defaults();
    }
    if (class_exists('MAD4B_Meta_Catalog_Adapter_V1') && function_exists('mcfm_get_feeds')) {
        MAD4B_Meta_Catalog_Adapter_V1::provision();
    }
});
add_action('init',static function () {
    // New company sites may activate portable plugins before their mapper dependency.
    // Provision local-only profiles once dependencies arrive, preserving variants.
    $saved=get_option('mad4b_distribution_channels_v1',null);
    $defaults=MAD4B_Distribution_Channel_Registry_V1::catalog();
    if ($saved===null || (is_array($saved) &&
        array_diff(array_keys($defaults),array_keys($saved)))) {
        MAD4B_Distribution_Channel_Registry_V1::provision_defaults();
    }
    if (function_exists('mcfm_get_feeds') && class_exists('MAD4B_Meta_Catalog_Adapter_V1')
        && !get_option('mad4b_distribution_catalog_profiles_v1',false)) {
        MAD4B_Meta_Catalog_Adapter_V1::provision();
    }
},99);
