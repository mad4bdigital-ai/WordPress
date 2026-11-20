<?php
/**
 * Plugin Name: Header Context Popover
 * Description: Keeps native WordPress market and passport widgets interactive in Elementor Theme Builder.
 * Version: 0.1.0
 */
if(!defined('ABSPATH'))exit;
add_action('wp_enqueue_scripts',static function(){
 if(!class_exists('\Elementor\Plugin'))return;
 $base=content_url('/mu-plugins/assets/');
 wp_enqueue_style('company-header-context-controls',$base.'header-context-controls.css',[],(string)filemtime(WPMU_PLUGIN_DIR.'/assets/header-context-controls.css'));
 wp_enqueue_style('company-native-mobile-navigation',$base.'mobile-native-navigation.css',[],(string)filemtime(WPMU_PLUGIN_DIR.'/assets/mobile-native-navigation.css'));
 wp_enqueue_script('company-header-context-controls',$base.'header-context-controls.js',['mad4b-browser-journey-v1'],(string)filemtime(WPMU_PLUGIN_DIR.'/assets/header-context-controls.js'),true);
 wp_enqueue_script('company-header-market-country-search',$base.'header-market-country-search.js',['company-header-context-controls'],(string)filemtime(WPMU_PLUGIN_DIR.'/assets/header-market-country-search.js'),true);
},30);
