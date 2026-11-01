<?php
/**
 * Plugin Name: MAD4B Staging Home Flag Preload Trim
 * Description: Remove redundant high-priority WPML flag preloads from optimized HTML without removing flag images.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Home_Flag_Preload_Trim_V1 {
 private const HOST='tours.dev.mad4b.com';
 private const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';

 public static function eligible():bool {
  if(PHP_SAPI==='cli'||wp_get_environment_type()!=='staging'
   ||is_admin()||is_user_logged_in()||wp_doing_ajax()||is_preview()||!is_front_page())return false;
  if(($_SERVER['HTTP_HOST']??'')!==self::HOST
   ||(string)wp_parse_url(home_url(),PHP_URL_HOST)!==self::HOST
   ||($_SERVER['REQUEST_METHOD']??'')!=='GET'
   ||!empty($_SERVER['QUERY_STRING'])||!empty($_SERVER['HTTP_COOKIE'])
   ||!empty($_SERVER['HTTP_AUTHORIZATION'])
   ||($_SERVER['HTTP_X_MAD4B_FLAG_PRELOAD_CONTROL']??'')==='native-v1')return false;
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  if(!is_array($p)||($p['site_uuid']??'')!==self::UUID)return false;
  $paths=['/'=>'en','/fr/'=>'fr','/de/'=>'de','/ar/'=>'ar','/es/'=>'es','/it/'=>'it'];
  $path=(string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
  return isset($paths[$path])&&apply_filters('wpml_current_language',null)===$paths[$path];
 }

 public static function transform($html){
  if(!is_string($html)||strlen($html)>8*1024*1024)return $html;
  $end=stripos($html,'</head>');
  if($end===false||$end>512*1024)return $html;
  $head=substr($html,0,$end);
  $head=preg_replace_callback('~<link\b[^>]{0,700}>~i',static function($match){
   $tag=$match[0];
   if(!preg_match('~\brel\s*=\s*["\x27]preload["\x27]~i',$tag)
    ||!preg_match('~\bas\s*=\s*["\x27]image["\x27]~i',$tag))return $tag;
   if(!preg_match('~\bhref\s*=\s*["\x27]https://tours\.dev\.mad4b\.com/wp-content/plugins/sitepress-multilingual-cms/res/flags/[a-z]{2}\.svg["\x27]~i',$tag))return $tag;
   return ''; // The existing <img> / CSS background URLs stay untouched.
  },$head);
  return is_string($head)?$head.substr($html,$end):$html;
 }

 public static function boot():void {
  if(!self::eligible())return;
  add_filter('litespeed_buffer_after',[__CLASS__,'transform'],80,1);
 }
}
add_action('template_redirect',['MAD4B_Home_Flag_Preload_Trim_V1','boot'],20);
