<?php
/** Non-invasive UI adapter for lab, with no direct JetSmartFilters file changes. */
if (!defined('ABSPATH')) exit;
add_action('wp_enqueue_scripts',function(){
 if(!is_singular('tours-and-activities'))return;
 $tour=get_queried_object_id();if(!$tour)return;
 if(!is_readable(__DIR__.'/woo-lab-assets/rate-dates-adapter.js'))return;
 wp_enqueue_script('etg-lab-rate-dates-ui',content_url('mu-plugins/woo-lab-assets/rate-dates-adapter.js'),[], '0.2.0',true);
 wp_add_inline_script('etg-lab-rate-dates-ui','window.ETGLabRateDates='.wp_json_encode(['tourId'=>(int)$tour,'endpoint'=>rest_url('shared-product/v1/rate-dates')]).';','before');
},30);
