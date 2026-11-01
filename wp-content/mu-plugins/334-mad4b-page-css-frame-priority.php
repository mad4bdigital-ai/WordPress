<?php
/**
 * Plugin Name: MAD4B Page and Frame-Aware CSS Priority — Staging
 * Description: Conservative source-bound stylesheet split; preserve viewport-critical and sticky CSS, defer tested noncritical styles only.
 * Version: 0.1.3-staged
 */
defined('ABSPATH') || exit;
final class MAD4B_Page_CSS_Frame_Priority_V1 {
 private const HOST='tours.dev.mad4b.com';
 private const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 private const PATHS=['/'=>'en','/ar/'=>'ar','/fr/'=>'fr','/de/'=>'de','/es/'=>'es','/it/'=>'it',
  '/tours-and-activities/'=>'en','/ar/tours-and-activities/'=>'ar','/fr/tours-and-activities/'=>'fr',
  '/de/tours-and-activities/'=>'de','/es/tours-and-activities/'=>'es','/it/tours-and-activities/'=>'it'];
 // Audited original source handles. Never drop a style: split it from the LS bundle and load it asynchronously.
 private const FILES=[
 'hfe-woo-product-grid'=>['needle'=>'/header-footer-elementor/inc/widgets-css/woo-products.css','tier'=>'idle'],
 'fluentform-elementor-widget'=>['needle'=>'/fluentform/assets/css/fluent-forms-elementor-widget.css','tier'=>'idle'],
 'woocommerce-smallscreen'=>['needle'=>'/astra/assets/css/minified/compatibility/woocommerce/woocommerce-smallscreen-grid.min.css','tier'=>'idle'],
 'fluent-form-styles'=>['needle'=>'/fluentform/assets/css/fluent-forms-public.css','tier'=>'below-fold'],
 'jet-smart-filters'=>['needle'=>'/jet-smart-filters/assets/css/public.css','tier'=>'below-fold-home-only']
 ];
 public static function valid_route(string $path,string $lang): bool {
  return isset(self::PATHS[$path]) && self::PATHS[$path]===$lang;
 }
 public static function selected(string $path): array {
  $out=[];foreach(self::FILES as $handle=>$entry){
   if($handle==='jet-smart-filters' && !in_array($path,['/','/ar/','/fr/','/de/','/es/','/it/'],true))continue;
   $out[$handle]=$entry;
  }return $out;
 }
 public static function safe_cookie_header(string $raw,string $lang):bool {
  if(strlen($raw)>4096)return false;
  $seen=[];
  foreach(explode(';',$raw) as $pair){
   $pair=trim($pair);if($pair==='')continue;
   $split=strpos($pair,'=');if($split===false)return false;
   $name=trim(substr($pair,0,$split));$val=trim(substr($pair,$split+1));
   if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$name)||isset($seen[$name]))return false;
   $seen[$name]=true;
   if($name==='wp-wpml_current_language'){
    if(!hash_equals($lang,rawurldecode($val)))return false;
   } elseif($name==='_lscache_vary'){
    // Built-in LiteSpeed device/cache vary remains the cache authority; no CSS or HTML is shared by this plugin.
    if(!preg_match('/^[A-Za-z0-9._-]{1,180}$/D',$val))return false;
   } elseif(in_array($name,['sbjs_current','sbjs_current_add','sbjs_first','sbjs_first_add','sbjs_migrations','sbjs_session','sbjs_udata','_ga','_gid','_gcl_au','_fbp'],true)){
    continue;
   } elseif(preg_match('/^_ga_[A-Za-z0-9]{4,24}$/D',$name)){
    continue;
   }else return false;
  }
  return true;
 }
 private static function enabled(): bool {
  // Single writer: new bridge owns all active CSS experiments. Retain this file as dormant fallback.
  if(class_exists('MAD4B_LiteSpeed_Local_Bridge',false) && MAD4B_LiteSpeed_Local_Bridge::active())return false;
  if(class_exists('MAD4B_LSC_Local_Provider_V1') && !MAD4B_LSC_Local_Provider_V1::legacy_css_allowed())return false;
  if(PHP_SAPI==='cli'||wp_get_environment_type()!=='staging'||!did_action('wp')||is_admin()||wp_doing_ajax()
   ||is_user_logged_in()||is_preview()||($_SERVER['REQUEST_METHOD']??'')!=='GET'
   ||($_SERVER['HTTP_HOST']??'')!==self::HOST
   ||(string)wp_parse_url(home_url(),PHP_URL_HOST)!==self::HOST
   ||!empty($_SERVER['QUERY_STRING'])||!empty($_SERVER['HTTP_AUTHORIZATION'])
   ||!empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])||!empty($_SERVER['HTTP_X_GEO_COUNTRY'])
   ||!empty($_SERVER['HTTP_CF_IPCOUNTRY'])||!empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY'])
   ||!defined('LSCWP_V')||LSCWP_V!=='7.9.2'
   ||!defined('ELEMENTOR_VERSION')||ELEMENTOR_VERSION!=='4.3.5')return false;
  $path=(string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
  $language=(string)apply_filters('wpml_current_language',null);
  if(!self::valid_route($path,$language)
   ||!self::safe_cookie_header((string)($_SERVER['HTTP_COOKIE']??''),$language))return false;
  $priority_mode=(string)($_SERVER['HTTP_X_MAD4B_CSS_PRIORITY']??'');
  if($priority_mode==='native-v1')return false;
  if($priority_mode!=='candidate-v1' && !($priority_mode==='' && in_array($language,['en','ar'],true)
   && (string)get_option('mad4b_css_priority_staging_en_ar_v1','0')==='1'))return false;

  $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
  return is_array($profile)&&($profile['site_uuid']??'')===self::UUID
   &&(string)get_option('litespeed.conf.optm-css_comb')==='1';
 }
 public static function activate():void {
  if(!self::enabled())return;
  $path=(string)wp_parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
  $list=self::selected($path);
  add_filter('litespeed_optimize_css_excludes',static function($excluded)use($list){
   if(!is_array($excluded))return $excluded;
   foreach($list as $asset)$excluded[]=$asset['needle'];
   return array_values(array_unique($excluded));
  },10000);
  add_filter('style_loader_tag',static function($tag,$handle,$href,$media)use($list){
   if(!isset($list[$handle])||!is_string($tag)||!is_string($href))return $tag;
   $asset=$list[$handle];
   if((string)wp_parse_url($href,PHP_URL_HOST)!==self::HOST
      ||strpos((string)wp_parse_url($href,PHP_URL_PATH),$asset['needle'])===false
      ||!preg_match('~\\brel=[\'\"]stylesheet[\'\"]~i',$tag)
      ||preg_match('~\\bonload=|\\bmedia=[\'\"]print[\'\"]~i',$tag))return $tag;
   // The URL stays unchanged; low-priority CSS loads immediately but is non-blocking.
   // By completion of the network fetch it becomes media=all, before a below-fold section is reached.
   // Preserve a native CSS-only fallback when scripting is disabled.
   $url=esc_url($href);$id=esc_attr($handle.'-css');
   return '<link rel="stylesheet" id="'.$id.'" data-mad4b-css-tier="'.esc_attr($asset['tier']).'" fetchpriority="'.($asset['tier']==='idle'?'low':'auto').'" href="'.$url.'" media="print" onload="this.onload=null;this.media=\'all\'" />'
    .'<noscript><link rel="stylesheet" href="'.$url.'" media="all" /></noscript>';
  },700,4);
 }
}
add_action('wp',['MAD4B_Page_CSS_Frame_Priority_V1','activate'],106);
