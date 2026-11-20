<?php
/**
 * Plugin Name: MAD4B Staging Priced-Card Dynamic CSS Cache
 * Description: Short-lived CSS-only acceleration with fresh per-request prices and Elementor output.
 * Version: 1.2.0
 */
defined('ABSPATH')||exit;
final class MAD4B_Priceless_CSS_Cache_V1 {
 private const MARKER='__MAD4B_CURRENT_DCSS_UNIQUE_ID__';

 private const DISK_DIR='/run/mad4b-oauth/mad4b-tour-dcss-v1';
 private static string $disk_dir=self::DISK_DIR;
 private const DISK_MAX_BYTES=262144;
 private const DISK_MAX_AGE=259200; // 72 hours, SHA-key invalidated on edit.

 private static function disk_path(string $key): ?string {
  if(!preg_match('/^[a-f0-9]{64}$/D',$key))return null;
  $dir=self::$disk_dir;
  if(!is_dir($dir)&&!@mkdir($dir,0700,true))return null;
  if(is_link($dir)||!is_dir($dir)||!is_writable($dir)||realpath($dir)!==$dir)return null;
  return $dir.'/css-'.$key.'.snapshot';
 }
 private static function read_css_snapshot(string $key){
  $path=self::disk_path($key);
  if(!$path||is_link($path)||!is_file($path))return false;
  $mtime=@filemtime($path);
  if(!$mtime||$mtime<time()-self::DISK_MAX_AGE)return false;
  $blob=@file_get_contents($path,false,null,0,self::DISK_MAX_BYTES+100);
  if(!is_string($blob)||strlen($blob)>self::DISK_MAX_BYTES+100
   ||strpos($blob,"\n")!==64)return false;
  $css=substr($blob,65);
  if(strlen($css)<60||strlen($css)>self::DISK_MAX_BYTES
   ||!str_starts_with($css,'<style type="text/css">')
   ||!str_ends_with($css,'</style>')
   ||strpos($css,self::MARKER)===false
   ||!hash_equals(substr($blob,0,64),hash('sha256',$css)))return false;
  return $css;
 }
 private static function write_css_snapshot(string $key,string $css):void {
  if(strlen($css)<60||strlen($css)>self::DISK_MAX_BYTES
   ||!str_starts_with($css,'<style type="text/css">')
   ||!str_ends_with($css,'</style>')
   ||strpos($css,self::MARKER)===false
   ||preg_match('~<(?:script|form)\b~i',$css))return;
  $path=self::disk_path($key);
  if(!$path||is_link($path))return;
  $tmp=@tempnam(dirname($path),'.m4css-');
  if(!is_string($tmp)||!str_starts_with($tmp,dirname($path).'/'))return;
  @chmod($tmp,0600);
  $blob=hash('sha256',$css)."\n".$css;
  if(@file_put_contents($tmp,$blob,LOCK_EX)!==strlen($blob)
   ||!@rename($tmp,$path))@unlink($tmp);
 }


 
 public static function serve_cached_css(string $prefix, string $uid, string $fresh_html): string {
  return str_replace(self::MARKER,$uid,$prefix).$fresh_html;
 }
 public static function prepare_css_cache(string $prefix, string $uid) {
  if(!str_starts_with($prefix,'<style type="text/css">')
   ||!str_ends_with($prefix,'</style>')||strpos($prefix,$uid)===false)return false;
  return str_replace($uid,self::MARKER,$prefix);
 }

 public static function schedule_cleanup():void{
  if(wp_get_environment_type()!=='staging'
   ||(string)wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')return;
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  if(!is_array($p)||($p['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a')return;
  if(!wp_next_scheduled('mad4b_css_private_snapshot_prune_v1'))
   wp_schedule_event(time()+3600,'daily','mad4b_css_private_snapshot_prune_v1');
 }
 public static function prune_snapshots():void{
  if(wp_get_environment_type()!=='staging'
   ||(string)wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')return;
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  if(!is_array($p)||($p['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a')return;
  $dir=self::$disk_dir;
  if(is_link($dir)||!is_dir($dir)||realpath($dir)!==$dir)return;
  $files=glob($dir.'/css-*.snapshot');
  if(!is_array($files))return;
  $count=0;
  foreach($files as $path){
   if($count>=250)break;
   if(!preg_match('~/css-[a-f0-9]{64}\.snapshot$~D',$path)
    ||is_link($path)||!is_file($path))continue;
   $mtime=@filemtime($path);
   if($mtime&&$mtime<time()-2*self::DISK_MAX_AGE&&@unlink($path))$count++;
  }
 }

 public static function boot(){
  if(PHP_SAPI==='cli'||wp_get_environment_type()!=='staging'
   ||($_SERVER['REQUEST_METHOD']??'')!=='GET'||!empty($_SERVER['HTTP_COOKIE'])
   ||!empty($_SERVER['HTTP_AUTHORIZATION'])||!empty($_SERVER['QUERY_STRING'])
   ||!empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])||!empty($_SERVER['HTTP_X_GEO_COUNTRY'])
   ||!empty($_SERVER['HTTP_CF_IPCOUNTRY'])
   ||!empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY'])
   ||($_SERVER['HTTP_X_MAD4B_PRICELESS_CSS_CONTROL']??'')==='native-v1'
   ||is_admin()||wp_doing_ajax()||is_user_logged_in()||is_preview()
   ||!is_post_type_archive('tours-and-activities'))return;
  if(($_SERVER['HTTP_HOST']??'')!=='tours.dev.mad4b.com'
   ||(string)wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com'
   ||!function_exists('get_woocommerce_currency')||get_woocommerce_currency()!=='USD')return;
  $paths=['/tours-and-activities/'=>'en','/fr/tours-and-activities/'=>'fr',
   '/de/tours-and-activities/'=>'de','/ar/tours-and-activities/'=>'ar',
   '/es/tours-and-activities/'=>'es','/it/tours-and-activities/'=>'it'];
  $path=(string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
  $locale=apply_filters('wpml_current_language',null);
  if(!isset($paths[$path])||$paths[$path]!==$locale)return;
  // A longer stylesheet-only lifetime needs deterministic invalidation for the
  // Elementor design system as well as each listing and current tour meta.
  $kit_id=absint(get_option('elementor_active_kit'));
  if(!$kit_id||!get_post($kit_id))return;
  $kit_meta=[
   get_post_meta($kit_id,'_elementor_page_settings',true),
   get_post_meta($kit_id,'_elementor_data',true),
   get_post_modified_time('U',true,$kit_id),
   get_option('elementor_css_print_method'),
   defined('ELEMENTOR_VERSION')?ELEMENTOR_VERSION:'',
   defined('JET_ENGINE_VERSION')?JET_ENGINE_VERSION:'',
   defined('ICL_SITEPRESS_VERSION')?ICL_SITEPRESS_VERSION:'',
   defined('WC_VERSION')?WC_VERSION:'',
   (string)wp_get_theme()->get('Version'),
   function_exists('wp_get_custom_css')?hash('sha256',wp_get_custom_css()):'',
  ];
  $kit_hash=hash('sha256',wp_json_encode($kit_meta));
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  if(!is_array($p)||($p['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a')return;
  $all_pins=[
   'en'=>[25102=>'c45280b5ec2e50757304421f06dc2a845303673f6459e279066823de192977c0',14962=>'c5ecf98755bc4d1a6bd907c1620acae1f3e349259feca94d312bef9b251a0cdc'],
   'fr'=>[35070=>'a7a79bd9a4180f0e9cd002b7c74ec4f1ed8a9210235ebc3d4726ee356f3a9df5',39900=>'a8d86eef31cad317c02c2631a234fe7ad4c8471b5e984536fdf965450b2b8e22'],
   'de'=>[35076=>'65cd5f109ba6ccf64ce873b60f705421604a8290d13373729f7375172dccc08e',39895=>'1427c2be6939dab2035e84bdb962065da70839994c39902cbd99b9c629232ae8'],
   'ar'=>[40683=>'e0ddd788baec161b499f5f9c23e39566adab0f91ddba93c5f56fb58709b5133c',40725=>'051e99735f3ca187056a6042b28ce04afcaf61fe11de7d9a650dbdf631c69ba6'],
   'es'=>[35084=>'00bd7d8b6be73d64e89760df9d6ff92b2f378142501f28187345dd0d87a99beb',39884=>'4090c76fb8bf9e36755ae6c879f6dbd67df5eba3900986263a18fcc78ba5f12f'],
   'it'=>[39902=>'dec17a54f1f9ffc02b99865c92aef7ecbab2a870263a02a4b4409039b671c321',39888=>'7e6513e4fe68bd374ad1f9798dca0caf234f73619a85487acc14188a4cf05db0'],
  ];
  $pins=$all_pins[$locale];
  foreach($pins as $id=>$sha){
   $listing=get_post($id);
   if(!$listing||$listing->post_type!=='jet-engine'||$listing->post_status!=='publish')return;
   $raw=get_post_meta($id,'_elementor_data',true);
   if(!is_string($raw)||!hash_equals($sha,hash('sha256',$raw)))return;
  }
  global $wp_filter;
  $h=$wp_filter['jet-engine/elementor-views/frontend/listing-content']??null;
  if(!($h instanceof WP_Hook)||count($h->callbacks[10]??[])!==1)return;
  $prev=null;
  foreach($h->callbacks[10] as $e)if($e['function'] instanceof Closure){
   $r=new ReflectionFunction($e['function']);
   if(basename((string)$r->getFileName())==='322-mad4b-empty-jet-css-fastpath.php')$prev=$e['function'];
  }
  if(!$prev)return;
  remove_filter('jet-engine/elementor-views/frontend/listing-content',$prev,10);
  add_filter('jet-engine/elementor-views/frontend/listing-content',
   static function($content,$listing_id,$inner)use($prev,$pins,$locale,$kit_hash){
    $id=(int)$listing_id;
    if(!isset($pins[$id])||!empty($inner)||!is_string($content)||$content==='')return $prev($content,$listing_id,$inner);
    $post=jet_engine()->listings->data->get_current_object();
    if(!($post instanceof WP_Post)||$post->post_type!=='tours-and-activities'||$post->post_status!=='publish')return $prev($content,$listing_id,$inner);
    $uid=jet_engine()->listings->data->get_current_object_unique_id($post);
    if(!is_string($uid)||!preg_match('/^\d{12,20}$/D',$uid))return $prev($content,$listing_id,$inner);
    // Same tour may appear in both List and Featured on one request:
    // reuse only its source META DIGEST, never price HTML or a cached product.
    static $source_meta_hashes=[];
    $post_id=(int)$post->ID;
    if(!isset($source_meta_hashes[$post_id])){
     $source_meta_hashes[$post_id]=hash('sha256',wp_json_encode(get_post_meta($post_id)));
    }
    $token=hash('sha256',wp_json_encode([
     $pins[$id],$id,$post_id,$post->post_modified_gmt,
     $source_meta_hashes[$post_id],$locale,(bool)wp_is_mobile(),$kit_hash
    ]));
    $key='css-only-'.$token;
    $disk_trial=($_SERVER['HTTP_X_MAD4B_PRICELESS_CSS_DISK']??'')!=='off-v1';
    $disk_only=($_SERVER['HTTP_X_MAD4B_PRICELESS_CSS_DISK']??'')==='disk-v1';
    $cached=$disk_only?false:wp_cache_get($key,'mad4b_priceless_css_v2');
    if(is_string($cached)&&strpos($cached,self::MARKER)!==false){
     return self::serve_cached_css($cached,$uid,$content);
    }
    if($disk_trial||$disk_only){
     $cached=self::read_css_snapshot($token);
     if(is_string($cached)&&strpos($cached,self::MARKER)!==false){
      if(!$disk_only)wp_cache_set($key,$cached,'mad4b_priceless_css_v2',180);
      return self::serve_cached_css($cached,$uid,$content);
     }
    }
    $result=$prev($content,$listing_id,$inner);
    if(!is_string($result)||!str_ends_with($result,$content)||strlen($result)<=strlen($content)){return $result;}
    $prefix=substr($result,0,-strlen($content));
    $canonical=self::prepare_css_cache($prefix,$uid);
    if($canonical===false)return $result;
    wp_cache_set($key,$canonical,'mad4b_priceless_css_v2',180);
    if($disk_trial||$disk_only)self::write_css_snapshot($token,$canonical);
    return $result;
   },10,3);

 }
}
add_action('wp',['MAD4B_Priceless_CSS_Cache_V1','boot'],102);
add_action('init',['MAD4B_Priceless_CSS_Cache_V1','schedule_cleanup'],20);
add_action('mad4b_css_private_snapshot_prune_v1',['MAD4B_Priceless_CSS_Cache_V1','prune_snapshots']);
