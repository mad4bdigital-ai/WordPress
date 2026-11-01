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
 wp_enqueue_style('company-header-context-controls',$base.'header-context-controls.css',[],'20261028-native-4');
 wp_enqueue_style('company-native-mobile-navigation',$base.'mobile-native-navigation.css',[],(string)filemtime(__DIR__.'/assets/mobile-native-navigation.css'));
 wp_enqueue_script('company-header-context-controls',$base.'header-context-controls.js',[],'20261028-native-5',true);
},30);
