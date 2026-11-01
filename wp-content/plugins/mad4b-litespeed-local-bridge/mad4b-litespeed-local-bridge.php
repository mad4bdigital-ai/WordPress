<?php
/**
 * Plugin Name: MAD4B LiteSpeed Local VPI Bridge
 * Description: Staging-only local VPI interface to LiteSpeed media hooks. CSS has a separate exclusive owner (MU336). No LiteSpeed file modifications.
 * Version: 0.2.0
 * Requires PHP: 7.4
 * Author: MAD4B
 */
defined('ABSPATH') || exit;
final class MAD4B_LiteSpeed_Local_Bridge {
 const HOST='tours.dev.mad4b.com';
 const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 const VPI_MANIFEST=WP_CONTENT_DIR.'/mad4b-local-vpi-policy.json';
 const CSS_MANIFEST=WP_CONTENT_DIR.'/mu-plugins/mad4b-css-autonomous-policy.json';
 private static $vpi=null;
 public static function scope():bool {
  if(wp_get_environment_type()!=='staging'||(string)wp_parse_url(home_url(),PHP_URL_HOST)!==self::HOST)return false;
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  return is_array($p)&&($p['site_uuid']??'')===self::UUID;
 }
 public static function active():bool {return self::scope()&&defined('LSCWP_V')&&LSCWP_V==='7.9.2';}
 public static function device(string $ua):string {
  return preg_match('~Mobile|Android|Silk|Kindle|BlackBerry|Opera.Mini|Opera.Mobi~i',$ua)?'mobile':'desktop';
 }
 public static function route():array {
  $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
  $lang=(string)apply_filters('wpml_current_language',null);
  if(strlen($path)>250||!preg_match('#^/[A-Za-z0-9/_~.\-]*$#D',$path)||strpos($path,'..')!==false||strpos($path,'//')!==false
   ||!in_array($lang,['en','ar','fr','de','es','it'],true))return [];
  return [$lang,$path];
 }
 // Single CSS authority is MU336; this ordinary plugin owns only optional verified local VPI.
 public static function local_css_compatible():bool {
  return class_exists('MAD4B_LSC_Local_Provider_V1',false)
   && MAD4B_LSC_Local_Provider_V1::local_css_eligible();
 }
 public static function local_css_state():array {
  return ['provider'=>self::local_css_compatible()?'mad4b_mu336_eligible_when_certified':'litespeed_native',
   'authority'=>'MAD4B_LSC_Local_Provider_V1'];
 }
 private static function vpi_manifest():array {
  if(!is_readable(self::VPI_MANIFEST))return [];
  $size=@filesize(self::VPI_MANIFEST);if($size===false||$size>110000)return [];
  $d=json_decode((string)@file_get_contents(self::VPI_MANIFEST),true);
  if(!is_array($d)||($d['contract']??'')!=='mad4b.local-vpi.v1'||($d['site_uuid']??'')!==self::UUID||($d['site_host']??'')!==self::HOST)return [];
  return $d;
 }
 public static function local_vpi():array {
  if(!self::active()||!did_action('wp'))return [];
  if(!empty($_SERVER['QUERY_STRING'])||is_user_logged_in()||is_admin()||wp_doing_ajax()||($_SERVER['REQUEST_METHOD']??'')!=='GET')return [];
  $route=self::route();$ua=(string)($_SERVER['HTTP_USER_AGENT']??'');
  if(!$route||!$ua||strlen($ua)>1024)return [];
  [$lang,$path]=$route;$d=self::vpi_manifest();
  $item=$d['routes'][hash('sha256',$lang.'|'.$path)]??[];
  if(($item['path']??'')!==$path||($item['locale']??'')!==$lang||($item['expires']??0)<time()
   ||(int)($item['epoch']??0)!==(int)get_option('mad4b_css_ai_epoch_v1',1)
   ||(string)($item['guest_mode']??'')!==(string)get_option('litespeed.conf.guest','0'))return [];
  // Suppress QUIC VPI only if both viewports have certified lists. The hook is URL-wide.
  foreach(['desktop','mobile'] as $dev){
   $sub=$item['devices'][$dev]??[];
   if(($sub['status']??'')!=='certified'||!is_array($sub['images']??null)||count($sub['images'])>16)return [];
   foreach($sub['images'] as $i){
    if(!is_array($i)||!is_string($i['path']??null)||!preg_match('#^/wp-content/uploads/[A-Za-z0-9/_~.\-]+\.(?:jpg|jpeg|png|webp|avif)$#iD',$i['path'])
      ||!preg_match('/^[a-f0-9]{64}$/D',(string)($i['sha256']??'')))return [];
    $file=ABSPATH.ltrim($i['path'],'/');$real=realpath($file);$base=realpath(WP_CONTENT_DIR.'/uploads');
    if(!$real||!$base||strpos($real,$base.DIRECTORY_SEPARATOR)!==0||!is_file($real)
     ||!is_int($i['size']??null)||!is_int($i['mtime']??null)
     ||@filesize($real)!==$i['size']||@filemtime($real)!==$i['mtime'])return [];
    // The offline worker hashes file contents when certifying; the request only checks bound metadata.
   }
  }
  return $item['devices'][self::device($ua)]['images'];
 }
 public static function owner_summary():array {
  $features=['css_js_combine'=>'litespeed_native','page_cache'=>'nginx_fastcgi_device_partition','lazyload'=>'litespeed_native',
   'css_priority'=>'mad4b_mu336_or_litespeed_native', 'ucss'=>'litespeed_native_off','ccss'=>'litespeed_native_off',
   'vpi'=>self::local_vpi()? 'mad4b_local':'quic_cloud_fallback_if_enabled',
   'lqip'=>'quic_cloud_fallback_if_enabled','image_optimization'=>'quic_cloud_fallback_if_enabled',
   'cdn'=>'not_provided_locally'];
  return ['contract'=>'mad4b.litespeed-provider-router.v1','site_uuid'=>self::UUID,'scope'=>self::active(),
   'preference'=>'local_vpi_when_both_devices_certified_else_litespeed_quic','features'=>$features,
   'litespeed_version'=>defined('LSCWP_V')?LSCWP_V:null];
 }
 public static function vpi_queue($should,$url) {
  if(!$should||!self::active()||!is_string($url))return $should;
  $current=self::route();if(!$current)return $should;
  $urlpath=(string)wp_parse_url($url,PHP_URL_PATH);
  if($urlpath!==$current[1])return $should;
  return self::local_vpi() ? false : $should;
 }
 public static function lazy_exclusions($excludes){
  if(!is_array($excludes))return $excludes;
  foreach(self::local_vpi() as $entry)$excludes[]=$entry['path'];
  return array_values(array_unique($excludes));
 }
 public static function boot():void {
  if(!self::scope())return;
  add_filter('litespeed_vpi_should_queue',[__CLASS__,'vpi_queue'],999,2);
  add_filter('litespeed_media_lazy_img_excludes',[__CLASS__,'lazy_exclusions'],999,1);
  add_action('rest_api_init',static function(){register_rest_route('mad4b/v1','/litespeed-provider',[
   'methods'=>'GET','permission_callback'=>static function(){return current_user_can('manage_options');},
   'callback'=>static function(){return rest_ensure_response(self::owner_summary());}]);});
 }
}
add_action('plugins_loaded',['MAD4B_LiteSpeed_Local_Bridge','boot'],120);
