<?php
/**
 * Plugin Name: MAD4B Featured Listing Banner Parity
 * Description: Match injected Elementor CTA banner dimensions to its own JetEngine listing card design without altering unrelated CTA widgets.
 * Source module: live WordPress implementation, generalized by site profile.
 */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts',static function(){
 if(!MAD4B_Presentation_Experience::enabled('featured-listing-banner') || is_admin() || !is_front_page())return;
 $file=MAD4B_Presentation_Experience::asset_path('mad4b-featured-listing-banner-parity.css');
 if(!is_readable($file))return;
 wp_enqueue_style('mad4b-featured-listing-banner-parity',
  MAD4B_Presentation_Experience::asset_url('mad4b-featured-listing-banner-parity.css'),
  [],(string)filemtime($file));
},120);
