<?php
/**
 * Plugin Name: MAD4B Staging Home Flag Preload Trim
 * Description: Remove redundant high-priority WPML flag preloads from optimized HTML without removing flag images.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Home_Flag_Preload_Trim_V1 {

 public static function eligible():bool {
  if(PHP_SAPI==='cli'||!MAD4B_Presentation_Experience::enabled('wpml-flag-preload-trim')
   ||is_admin()||is_user_logged_in()||wp_doing_ajax()||is_preview()||!is_front_page())return false;
  $sitehost=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
  if($sitehost==='' ||($_SERVER['HTTP_HOST']??'')!==$sitehost
   ||($_SERVER['REQUEST_METHOD']??'')!=='GET'
   ||!empty($_SERVER['QUERY_STRING'])||!empty($_SERVER['HTTP_COOKIE'])
   ||!empty($_SERVER['HTTP_AUTHORIZATION'])
   ||($_SERVER['HTTP_X_MAD4B_FLAG_PRELOAD_CONTROL']??'')==='native-v1')return false;
  if(!defined('ICL_SITEPRESS_VERSION'))return false;
  $active=apply_filters('wpml_active_languages',null,'skip_missing=0');
  $locale=(string)apply_filters('wpml_current_language',null);
  $default=(string)apply_filters('wpml_default_language','en');
  if(!is_array($active)||!isset($active[$locale])||$default==='')return false;
  $path=(string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
  $expected=$locale===$default?'/':'/'.sanitize_key($locale).'/';
  return $path===$expected;
 }

 public static function transform($html){
  if(!is_string($html)||strlen($html)>8*1024*1024)return $html;
  $end=stripos($html,'</head>');
  if($end===false||$end>512*1024)return $html;
  $head=substr($html,0,$end);
  $sitehost=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
  if($sitehost==='')return $html;
  $head=preg_replace_callback('~<link\b[^>]{0,700}>~i',static function($match)use($sitehost){
   $tag=$match[0];
   if(!preg_match('~\brel\s*=\s*["\x27]preload["\x27]~i',$tag)
    ||!preg_match('~\bas\s*=\s*["\x27]image["\x27]~i',$tag))return $tag;
   $pattern='~\bhref\s*=\s*["\x27]https://'.preg_quote($sitehost,'~').'/wp-content/plugins/sitepress-multilingual-cms/res/flags/[a-z]{2}\.svg["\x27]~i';
   if(!preg_match($pattern,$tag))return $tag;
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
