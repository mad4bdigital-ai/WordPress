<?php
/** Plugin Name: Company Settings Navigator */
defined('ABSPATH') || exit;
add_action('admin_enqueue_scripts', static function () {
    $groups = [
        'etg-dynamic-pricing-controls' => [
            'Price sources'=>['Direct price weight'=>'shared_price_source_direct_weight','Rate weight'=>'shared_price_source_rate_weight','Equal weights'=>'shared_price_source_tie_break'],
            'Product policies'=>['Tour mode'=>'shared_mode_policy_rows','Group tiers'=>'shared_group_tier_pricing_rows','Package levels'=>'shared_package_pricing_rows'],
            'Sale conditions'=>['Availability'=>'require_availability','Booking lead time'=>'minimum_booking_lead_hours','Quote lifetime'=>'quote_ttl_minutes'],
        ],
        'service-activation' => [
            'Service availability'=>['Hotel channel'=>'properties_channel_mode','Transport channel'=>'transportations_channel_mode'],
            'Discovery'=>['Enable discovery'=>'discovery_enabled','Display surfaces'=>'discovery_surfaces','Results per type'=>'discovery_default_limit'],
            'Audience'=>['Destination matching'=>'discovery_location_policy','Market'=>'discovery_market_gate','Passport'=>'discovery_nationality_gate'],
        ],
        'destination-activation' => [
            'Destination terms'=>['Continents'=>'active_continents','Countries'=>'active_countries','Governorates'=>'active_governorates'],
        ],
    ];
    $slug=$_GET['page']??'';
    if (!is_string($slug)||!isset($groups[$slug])) return;
    $base=plugin_dir_url(__FILE__).'company-settings-navigator/';
    wp_enqueue_style('company-settings-navigator',$base.'panel.css',[],filemtime(__DIR__.'/company-settings-navigator/panel.css'));
    wp_enqueue_script('company-settings-navigator',$base.'panel.js',[],filemtime(__DIR__.'/company-settings-navigator/panel.js'),true);
    wp_add_inline_script('company-settings-navigator','window.companySettingsGroups='.wp_json_encode($groups[$slug]).';','before');
});
