<?php
/**
 * Plugin Name: MAD4B Staging Independent Leaf Script Defer
 * Description: WordPress-native defer only for audited noncritical footer scripts without queued dependents.
 * Version: 1.0.0
 */
defined('ABSPATH')||exit;
final class MAD4B_Independent_Leaf_JS_Defer_V1 {
 private const HOST='tours.dev.mad4b.com';
 private const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 private const CANDIDATES=[
  'uael-isotope','uael-element-resize','uael-fancybox',
  'uael-slick','uael-social-share',
  'mad4b-local-gallery-a11y','mad4b-local-archive-a11y',
  'wpml-language-switcher-block-keyboard-navigation',
 ];
 public static function eligible():bool {
  // LiteSpeed owns JS combining; do not independently defer the same scoped scripts.
  if(class_exists('MAD4B_Device_JS_Combine_V1',false) && MAD4B_Device_JS_Combine_V1::combining())return false;
  if(PHP_SAPI==='cli'||wp_get_environment_type()!=='staging'
   ||is_admin()||is_user_logged_in()||wp_doing_ajax()||is_preview()
   ||($_SERVER['REQUEST_METHOD']??'')!=='GET'||!empty($_SERVER['QUERY_STRING'])
   ||!empty($_SERVER['HTTP_COOKIE'])||!empty($_SERVER['HTTP_AUTHORIZATION'])
   ||($_SERVER['HTTP_X_MAD4B_LEAF_DEFER_CONTROL']??'')==='native-v1')return false;
  if(($_SERVER['HTTP_HOST']??'')!==self::HOST||(string)wp_parse_url(home_url(),PHP_URL_HOST)!==self::HOST)return false;
  $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
  if(!is_array($profile)||($profile['site_uuid']??'')!==self::UUID)return false;
  $routes=['/'=>'en','/tours-and-activities/'=>'en'];
  foreach(['fr','de','ar','es','it'] as $l){$routes['/'.$l.'/']=$l;$routes['/'.$l.'/tours-and-activities/']=$l;}
  $path=(string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
  return isset($routes[$path])&&apply_filters('wpml_current_language',null)===$routes[$path];
 }
 public static function apply():void {
  if(!self::eligible())return;
  global $wp_scripts;
  if(!($wp_scripts instanceof WP_Scripts))return;
  // WordPress may change script ordering if a script has inline dependencies,
  // so decline anything with localizations or callbacks and anything consumed.
  $all=array_unique(array_merge($wp_scripts->queue,$wp_scripts->done));
  $dependents=[];
  foreach($all as $handle){
   if(!isset($wp_scripts->registered[$handle]))continue;
   foreach($wp_scripts->registered[$handle]->deps as $dep)$dependents[$dep]=true;
  }
  foreach(self::CANDIDATES as $handle){
   if(!in_array($handle,$wp_scripts->queue,true)||!empty($dependents[$handle]))continue;
   $item=$wp_scripts->registered[$handle]??null;
   if(!$item||!is_string($item->src)||!str_starts_with($item->src,content_url('/'))
    ||(int)($item->extra['group']??0)!==1
    ||!empty($item->extra['before'])||!empty($item->extra['after'])
    ||!empty($item->extra['data'])||!empty($item->extra['strategy']))continue;
   wp_script_add_data($handle,'strategy','defer');
  }
 }
 public static function boot():void {
  if(!self::eligible())return;
  add_action('wp_print_footer_scripts',[__CLASS__,'apply'],1);
 }
}
add_action('wp',['MAD4B_Independent_Leaf_JS_Defer_V1','boot'],105);
