<?php
/**
 * Plugin Name: MAD4B Mobile Archive Sticky Dock
 * Description: Site-bound tours archive dock geometry and touch-only navigation state. Shared social rail is owned by MU 345.
 * Source module: live WordPress implementation, generalized by site profile.
 */
defined('ABSPATH') || exit;
final class MAD4B_Mobile_Scroll_Action {
 public static function scope():bool { return MAD4B_Presentation_Experience::enabled('mobile-scroll-action'); }
 public static function archive():bool {
  return self::scope()&&!is_admin()&&!is_preview()
   &&is_post_type_archive('tours-and-activities');
 }
 public static function assets():void {
  if(!self::archive())return;
  $base=MAD4B_Presentation_Experience::asset_path('');
  foreach(['css','js'] as $type) {
   $name='mad4b-mobile-scroll-action.'.$type;$file=$base.$name;
   if(!is_readable($file))continue;
   $url=MAD4B_Presentation_Experience::asset_url($name);
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
