<?php
/**
 * Plugin Name: MAD4B Public Legal Pages
 * Description: Keep configured, published legal documents accessible even while WooCommerce's store-only Coming Soon overlay is enabled.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
add_filter('woocommerce_coming_soon_exclude', static function($excluded) {
    if ($excluded || is_admin() || !is_page()) return $excluded;
    $current=(int)get_queried_object_id();
    if($current<1 || get_post_type($current)!=='page' || get_post_status($current)!=='publish')return $excluded;
    $lang=(string)apply_filters('wpml_current_language','en');
    $cfg=(array)get_option('look-and-feel',[]);
    $bases=array_unique(array_filter([
        (int)get_option('wp_page_for_privacy_policy'),
        (int)get_option('woocommerce_terms_page_id'),
        (int)($cfg['footer_privacy_page']??0),
        (int)($cfg['footer_terms_page']??0)
    ]));
    foreach($bases as $base){
        if($current===$base)return true;
        $translated=(int)apply_filters('wpml_object_id',$base,'page',false,$lang);
        if($translated===$current)return true;
    }
    return $excluded;
},40,1);
