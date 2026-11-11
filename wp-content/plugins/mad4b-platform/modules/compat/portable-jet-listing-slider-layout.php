<?php
/** Plugin Name: Native Jet Listing Slider Layout Stability */
if(!defined('ABSPATH'))exit;
add_action('wp_enqueue_scripts',static function(){
 if(!is_tax())return;
 $file=WPMU_PLUGIN_DIR.'/assets/jet-listing-slider-layout.js';
 if(!is_file($file))return;
 wp_enqueue_script('native-jet-slider-layout',content_url('mu-plugins/assets/jet-listing-slider-layout.js'),
  [],(string)filemtime($file),true);
},30);
