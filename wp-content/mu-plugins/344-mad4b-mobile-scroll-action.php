<?php
/**
 * Plugin Name: MAD4B Mobile Scroll and Share Action
 * Description: Site-bound archive mobile dock geometry, touch-only state and original Jet Dropbar share action resilience.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Mobile_Scroll_Action {
 const HOST='tours.dev.mad4b.com';
 const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 public static function scope():bool {
  if(wp_get_environment_type()!=='staging'
   ||strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))!==self::HOST)return false;
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  return is_array($p)&&($p['site_uuid']??'')===self::UUID;
 }
 public static function archive():bool {
  return self::scope()&&!is_admin()&&!is_preview()
   &&is_post_type_archive('tours-and-activities');
 }
 public static function assets():void {
  if(!self::archive())return;
  $base=WP_CONTENT_DIR.'/mu-plugins/assets/';
  foreach(['css','js'] as $type) {
   $name='mad4b-mobile-scroll-action.'.$type;$file=$base.$name;
   if(!is_readable($file))continue;
   $url=content_url('mu-plugins/assets/'.$name);
   if($type==='css')wp_enqueue_style('mad4b-mobile-scroll-action',$url,[],(string)filemtime($file));
   else wp_enqueue_script('mad4b-mobile-scroll-action',$url,[],(string)filemtime($file),['in_footer'=>true]);
  }
 }
 public static function no_optimize(string $tag,string $handle,string $src):string {
  if(!self::archive()||$handle!=='mad4b-mobile-scroll-action')return $tag;
  return str_replace('id="mad4b-mobile-scroll-action-js"',
   'data-no-optimize="1" data-no-defer="1" data-cfasync="false" id="mad4b-mobile-scroll-action-js"',$tag);
 }
 public static function exclusions($list) {
  if(!self::scope()||!is_array($list))return $list;
  $list[]='mad4b-mobile-scroll-action.js';
  return array_values(array_unique($list));
 }
 public static function boot():void {
  if(!self::scope())return;
  add_action('wp_enqueue_scripts',[__CLASS__,'assets'],115);
  add_filter('script_loader_tag',[__CLASS__,'no_optimize'],550,3);
  add_filter('litespeed_optimize_js_excludes',[__CLASS__,'exclusions'],110);
 }
}
add_action('init',['MAD4B_Mobile_Scroll_Action','boot'],24);
