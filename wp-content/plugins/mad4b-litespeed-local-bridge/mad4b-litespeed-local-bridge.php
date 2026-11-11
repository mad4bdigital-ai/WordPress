<?php
/**
 * Plugin Name: MAD4B LiteSpeed Local VPI Bridge
 * Description: Portable local VPI compatibility, delegating CSS ownership to MU336. No cloud dependency or vendor file changes.
 * Version: 0.2.0
 * Requires PHP: 7.4
 * Author: MAD4B
 */
defined('ABSPATH') || exit;
final class MAD4B_LiteSpeed_Local_Bridge {
 const VPI_MANIFEST=WP_CONTENT_DIR.'/mad4b-local-vpi-policy.json';
 public static function identity():array {
  return class_exists('MAD4B_LSC_Local_Provider_V1',false)
   ? MAD4B_LSC_Local_Provider_V1::identity() : [];
 }
 public static function scope():bool {return self::identity()!==[];}
 public static function active():bool {return self::scope()&&defined('LSCWP_V')&&LSCWP_V!=='';}
 public static function device(string $ua):string {
  return class_exists('MAD4B_LSC_Local_Provider_V1',false)
   ? MAD4B_LSC_Local_Provider_V1::device($ua)
   : (preg_match('~Mobile|Android|Silk/|Kindle|BlackBerry|Opera Mini|Opera Mobi~i',$ua)?'mobile':'desktop');
 }
 public static function route():array {
  $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
  $lang=(string)apply_filters('wpml_current_language',get_locale());
  $decoded=rawurldecode($path);
  if(strlen($path)>2048
   ||!preg_match('#^/(?:[A-Za-z0-9/_~.\-]|%[a-fA-F0-9]{2})*$#D',$path)
   ||str_contains($decoded,'..')||str_contains($decoded,'//')
   ||str_contains($decoded,'\\')||preg_match('/[\x00-\x1F]/',$decoded)
   ||strlen($lang)>40||!preg_match('/^[a-zA-Z0-9_-]+$/D',$lang))return [];
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
  $identity=self::identity();
  if(!is_array($d)||!$identity||($d['contract']??'')!=='mad4b.local-vpi.v1'
   ||($d['site_uuid']??'')!==$identity['site_uuid']
   ||($d['site_host']??'')!==$identity['host'])return [];
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
    $uploads=wp_upload_dir(null,false);
    $basePath=(string)wp_parse_url((string)($uploads['baseurl']??''),PHP_URL_PATH);
    $base=realpath((string)($uploads['basedir']??''));
    $assetPath=is_string($i['path']??null)?$i['path']:'';
    if(!$base || $basePath==='' || !str_starts_with($assetPath,rtrim($basePath,'/').'/')
      ||!preg_match('~\.(?:jpg|jpeg|png|webp|avif)$~iD',$assetPath)
      ||!preg_match('/^[a-f0-9]{64}$/D',(string)($i['sha256']??'')))return [];
    $file=$base.'/'.ltrim(substr($assetPath,strlen($basePath)),'/');
    $real=realpath($file);
    if(!$real||!$base||strpos($real,$base.DIRECTORY_SEPARATOR)!==0||!is_file($real)
     ||!is_int($i['size']??null)||!is_int($i['mtime']??null)
     ||@filesize($real)!==$i['size']||@filemtime($real)!==$i['mtime'])return [];
    // The offline worker hashes file contents when certifying; the request only checks bound metadata.
   }
  }
  return $item['devices'][self::device($ua)]['images'];
 }
 public static function owner_summary():array {
  $features=['css_js_combine'=>'litespeed_native','page_cache'=>'site_cache_configuration','lazyload'=>'litespeed_native',
   'css_priority'=>'mad4b_mu336_or_litespeed_native', 'ucss'=>'disabled_for_local_overlay','ccss'=>'disabled_for_local_overlay',
   'vpi'=>self::local_vpi()? 'mad4b_local':'native_lazyload',
   'lqip'=>'not_owned','image_optimization'=>'not_owned',
   'cdn'=>'not_owned'];
  $identity=self::identity();
  return ['contract'=>'mad4b.litespeed-provider-router.v1','site_uuid'=>$identity['site_uuid']??null,'scope'=>self::active(),
   'preference'=>'local_only_no_remote_css_or_vpi','features'=>$features,
   'litespeed_version'=>defined('LSCWP_V')?LSCWP_V:null];
 }
 public static function vpi_queue($should,$url) {
  if(!$should||!self::active()||!is_string($url)||$url==='')return $should;
  $host=(string)wp_parse_url($url,PHP_URL_HOST);
  $own=self::identity()['host']??'';
  // Site-local absolute or relative URL: never send VPI to a remote service.
  // Retain the vendor hook result for unrelated domains.
  return $host==='' || strtolower($host)===$own ? false : $should;
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
