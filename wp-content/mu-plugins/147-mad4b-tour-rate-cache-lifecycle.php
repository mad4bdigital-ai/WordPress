<?php
/**
 * Plugin Name: MAD4B Tour Rates Presentation Cache Lifecycle
 * Description: Signal scoped invalidation of public Tour-detail HTML after effective rate-view policy changes; no cache purge on reads.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Tour_Rate_Cache_Lifecycle_V1',false)) {
final class MAD4B_Tour_Rate_Cache_Lifecycle_V1 {
 const GLOBAL_OPTION='look-and-feel';
 const SIGNAL='.mad4b-tour-rate-cache-purge.signal';
 const TOUR_TYPE='tours-and-activities';
 const META_KEYS=['tour_mode','tour_rates_display_style','mad4b_rate_view_override_v1'];
 private static function prefs($v):array {
  $out=[];
  foreach ((array)(is_array($v)?$v:[]) as $key=>$val) {
   if (is_string($key)&&preg_match('/^mad4b_rate_view_(?:default_v1|precedence_v1|mode_[a-z0-9_]+)$/D',$key)) {
    $out[$key]=is_scalar($val)?(string)$val:'';
   }
  }
  ksort($out);return $out;
 }
 static function option_saved($key,$old,$new):void {
  if ($key!==self::GLOBAL_OPTION || self::prefs($old)===self::prefs($new))return;
  self::signal('look-and-feel-rate-policy');
 }
 static function option_added($key,$value):void {
  if ($key!==self::GLOBAL_OPTION || self::prefs($value)===[])return;
  self::signal('look-and-feel-rate-policy-initial');
 }
 static function meta_changed($meta_id,$post_id,$key,$value=null):void {
  if (!in_array((string)$key,self::META_KEYS,true))return;
  if (get_post_type((int)$post_id)!==self::TOUR_TYPE)return;
  self::signal('tour-rate-display-meta');
 }
 /** A host-owned watcher receives only a signal; PHP has no Docker or shell authority. */
 static function signal(string $reason):bool {
  $home=(string)get_option('home','');
  if (strtolower((string)wp_parse_url($home,PHP_URL_HOST))!=='wayfatours.com')return false;
  $path=WP_CONTENT_DIR.'/'.self::SIGNAL;
  if (is_link($path) || (file_exists($path)&&!is_file($path)))return false;
  $contents='mad4b-tour-rate-cache-purge.v1 '.gmdate('c').' '.sanitize_key($reason)."\n";
  $ok=@file_put_contents($path,$contents,LOCK_EX);
  if ($ok===false){error_log('MAD4B_RATE_VIEW_PURGE_SIGNAL_FAILED');return false;}
  @chmod($path,0600);
  return true;
 }
 static function boot():void {
  add_action('updated_option',[__CLASS__,'option_saved'],25,3);
  add_action('added_option',[__CLASS__,'option_added'],25,2);
  foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)
    add_action($hook,[__CLASS__,'meta_changed'],25,4);
 }
}
MAD4B_Tour_Rate_Cache_Lifecycle_V1::boot();
}
