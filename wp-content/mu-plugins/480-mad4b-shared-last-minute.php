<?php
/** Plugin Name: MAD4B Shared Product Last Minute Policy Bootstrap */
defined('ABSPATH') || exit;
add_action('plugins_loaded',static function() {
    $presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
    $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED===true;
    if(!$presentation && !$commerce)return;
    $stock=WPMU_PLUGIN_DIR.'/shared-product-core/pricing/inventory-signal-v1.php';
    if(is_readable($stock))require_once $stock;
    $file=WPMU_PLUGIN_DIR.'/shared-product-core/pricing/last-minute-policy-v1.php';
    if(is_readable($file)) require_once $file;
    $events=WPMU_PLUGIN_DIR.'/shared-product-core/pricing/campaign-event-v1.php';
    if(is_readable($events))require_once $events;
},7);
