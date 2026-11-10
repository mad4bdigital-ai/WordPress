<?php
/**
 * Plugin Name: MAD4B Navigation Lazy Listings
 * Description: Brand-neutral JetEngine signed navigation and Elementor Nested Tabs on-demand loading.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (defined('MAD4B_NAVIGATION_LAZY_BOOTSTRAPPED')) return;
define('MAD4B_NAVIGATION_LAZY_BOOTSTRAPPED',true);
final class MAD4B_Navigation_Lazy_Listings_V1 {
 public static function signed_options($options,$settings) {
  if (!is_array($settings) || !is_array($options) || !function_exists('jet_engine')) return $options;
  $classes=preg_split('/\s+/',trim((string)($settings['_css_classes']??'')));
  if (!in_array('navigation-editor-grid',$classes,true)) return $options;
  $handler=jet_engine()->listings->ajax_handlers??null;
  if (!$handler || !method_exists($handler,'maybe_add_settings_signature')) return $options;
  $signed=$handler->maybe_add_settings_signature($settings);
  if (!is_array($signed) || empty($signed['_jet_engine_settings_signature'])) return $options;
  if (!hash_equals((string)($options['settings_signature']??''),(string)$signed['_jet_engine_settings_signature'])) return $options;
  $strip=static function(array $data) use (&$strip):array {
   foreach($data as $key=>$value){
    if (!is_array($value))continue;
    $value=$strip($value);
    if($value===[])unset($data[$key]);else $data[$key]=$value;
   }
   return $data;
  };
  $compact=$strip($signed);
  $resigned=$handler->maybe_add_settings_signature($compact);
  if (is_array($resigned) && hash_equals((string)$signed['_jet_engine_settings_signature'],(string)($resigned['_jet_engine_settings_signature']??''))) $signed=$compact;
  $options['signed_widget_settings']=$signed;
  return $options;
 }
 public static function enqueue():void {
  if(is_admin() || !function_exists('jet_engine'))return;
  $file=__DIR__.'/assets/mad4b-navigation-lazy-listings.js';
  if(!is_readable($file))return;
  wp_enqueue_script('mad4b-navigation-lazy-listings',plugin_dir_url(__FILE__).'assets/mad4b-navigation-lazy-listings.js',[],(string)filemtime($file),false);
 }
 public static function script_tag($tag,$handle) {
  return $handle==='mad4b-navigation-lazy-listings'
   ? preg_replace('/<script\b/i','<script data-no-optimize="1" data-no-defer="1"',$tag,1):$tag;
 }
}
add_filter('jet-engine/listing/grid/lazy-load/options',[MAD4B_Navigation_Lazy_Listings_V1::class,'signed_options'],20,2);
add_action('wp_enqueue_scripts',[MAD4B_Navigation_Lazy_Listings_V1::class,'enqueue'],15);
add_filter('script_loader_tag',[MAD4B_Navigation_Lazy_Listings_V1::class,'script_tag'],50,2);
