<?php
/**
 * Plugin Name: MAD4B Staging Elementor Listing JSON Request Memo
 * Description: Memoize only immutable, exact-source Elementor template JSON within a single request; never HTML or visitor data.
 * Version: 1.0.0
 */
defined('ABSPATH')||exit;
final class MAD4B_Elementor_Request_JSON_Memo_V1 {
 private const HOST='tours.dev.mad4b.com';
 private const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 private const PINS=[
  'en'=>[25102=>'c45280b5ec2e50757304421f06dc2a845303673f6459e279066823de192977c0',14962=>'c5ecf98755bc4d1a6bd907c1620acae1f3e349259feca94d312bef9b251a0cdc'],
  'fr'=>[35070=>'a7a79bd9a4180f0e9cd002b7c74ec4f1ed8a9210235ebc3d4726ee356f3a9df5',39900=>'a8d86eef31cad317c02c2631a234fe7ad4c8471b5e984536fdf965450b2b8e22'],
  'de'=>[35076=>'65cd5f109ba6ccf64ce873b60f705421604a8290d13373729f7375172dccc08e',39895=>'1427c2be6939dab2035e84bdb962065da70839994c39902cbd99b9c629232ae8'],
  'ar'=>[40683=>'e0ddd788baec161b499f5f9c23e39566adab0f91ddba93c5f56fb58709b5133c',40725=>'051e99735f3ca187056a6042b28ce04afcaf61fe11de7d9a650dbdf631c69ba6'],
  'es'=>[35084=>'00bd7d8b6be73d64e89760df9d6ff92b2f378142501f28187345dd0d87a99beb',39884=>'4090c76fb8bf9e36755ae6c879f6dbd67df5eba3900986263a18fcc78ba5f12f'],
  'it'=>[39902=>'dec17a54f1f9ffc02b99865c92aef7ecbab2a870263a02a4b4409039b671c321',39888=>'7e6513e4fe68bd374ad1f9798dca0caf234f73619a85487acc14188a4cf05db0'],
 ];
 private static array $prepared=[];
 private static array $hashes=[];
 public static function start():void{
  if(PHP_SAPI==='cli'||wp_get_environment_type()!=='staging'
   ||($_SERVER['HTTP_HOST']??'')!==self::HOST
   ||(string)wp_parse_url(home_url(),PHP_URL_HOST)!==self::HOST
   ||($_SERVER['REQUEST_METHOD']??'')!=='GET'
   ||!empty($_SERVER['QUERY_STRING'])||!empty($_SERVER['HTTP_COOKIE'])
   ||!empty($_SERVER['HTTP_AUTHORIZATION'])||!empty($_SERVER['HTTP_CF_IPCOUNTRY'])
   ||!empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])||is_admin()||wp_doing_ajax()
   ||is_user_logged_in()||is_preview()||!is_post_type_archive('tours-and-activities')
   ||($_SERVER['HTTP_X_MAD4B_TEMPLATE_DATA_CONTROL']??'')==='native-v1')return;
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  if(!is_array($p)||($p['site_uuid']??'')!==self::UUID)return;
  if(!function_exists('get_woocommerce_currency')||get_woocommerce_currency()!=='USD')return;
  // Pinned to reviewed Elementor/WPML metadata contracts; newer versions
  // automatically fall back to native rendering until parity is reverified.
  if(!defined('ELEMENTOR_VERSION')||ELEMENTOR_VERSION!=='4.3.5'
   ||!defined('ICL_SITEPRESS_VERSION')||ICL_SITEPRESS_VERSION!=='4.9.5')return;
  $locale=(string)apply_filters('wpml_current_language',null);
  $path=(string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
  $expected=$locale==='en'?'/tours-and-activities/':'/'.$locale.'/tours-and-activities/';
  if(!isset(self::PINS[$locale])||$path!==$expected)return;
  // Restrict to exact current published source templates; a designer's edit
  // disables the entire optimization until the template is reviewed.
  foreach(self::PINS[$locale] as $id=>$sha){
   $post=get_post($id);
   if(!$post||$post->post_type!=='jet-engine'||$post->post_status!=='publish')return;
   $raw=get_post_meta($id,'_elementor_data',true);
   if(!is_string($raw)||!hash_equals($sha,hash('sha256',$raw)))return;
   $decoded=json_decode($raw,true);
   if(!is_array($decoded)||json_last_error()!==JSON_ERROR_NONE)return;
   self::$prepared[$id]=$decoded;
   self::$hashes[$id]=$sha;
  }
  add_filter('get_post_metadata',[__CLASS__,'intercept'],99999,5);
 }
 public static function intercept($check,$id,$key,$single,$type){
  if(!is_string($check)||$key!=='_elementor_data'||!$single||!isset(self::$prepared[(int)$id])
   ||!hash_equals(self::$hashes[(int)$id],hash('sha256',$check)))return $check;
  // Return decoded data ONLY to Elementor Document::get_json_meta,
  // not to CSS generators and other callers requiring the source JSON text.
  $trace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,11);
  foreach($trace as $frame){
   if(($frame['function']??'')==='get_json_meta'
    &&is_string($frame['class']??null)
    &&is_a($frame['class'],'Elementor\\Core\\Base\\Document',true)){
    return [self::$prepared[(int)$id]]; // WordPress returns [0] for single metadata.
   }
  }
  return $check;
 }
}
add_action('wp',['MAD4B_Elementor_Request_JSON_Memo_V1','start'],107);
