<?php
/**
 * Plugin Name: MAD4B Site Company Types and Scoped Local Readback
 * Description: Optional site-scoped integration for registered travel models; core remains site neutral.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
add_filter('mad4b/company/approved_post_types',static function($types){
    foreach(['tours-and-activities','properties','transportations'] as $type)
        if(post_type_exists($type))$types[]=$type;
    return array_values(array_unique((array)$types));
},20,1);
add_filter('mad4b/company/migration_inventory_types',static function($types){
    foreach(['tours-and-activities','properties','transportations','tour-rates',
             'tour_orders','tour_bookings'] as $type)
        if(post_type_exists($type))$types[]=$type;
    return array_values(array_unique((array)$types));
},20,1);
add_filter('mad4b/company/legacy_tour_type_taxonomy',static function($type){
    return taxonomy_exists('tour_type') ? 'tour_type' : (string)$type;
},20,1);
add_filter('mad4b/company/local_wpcli_identity',static function($scope){
    if(wp_get_environment_type()!=='staging')return $scope;
    if(home_url()!=='https://tours.dev.mad4b.com')return $scope;
    return ['site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
            'site_url'=>'https://tours.dev.mad4b.com'];
},20,1);
