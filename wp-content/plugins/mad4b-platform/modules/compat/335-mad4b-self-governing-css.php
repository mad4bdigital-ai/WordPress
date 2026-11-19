<?php
/**
 * Plugin Name: MAD4B Self-Governing CSS Runtime
 * Version: 0.2.1
 * Description: Manifest-driven, page-specific deferred styles with safe native fallbacks; local QA alone certifies a route.
 */
defined('ABSPATH') || exit;
final class MAD4B_Autonomous_CSS_V1 {
 const POLICY=WPMU_PLUGIN_DIR.'/mad4b-css-autonomous-policy.json';
 const SAFE_MODES=['canary-v1','native-v1'];
 private static $route=null;
 private static $assets=[];
 public static function safe_cookie_header(string $raw,string $locale): bool {
  // Reuse the validated existing scope policy; never accept commerce/auth/currency state.
  return class_exists('MAD4B_LSC_Local_Provider_V1', false)
   && MAD4B_LSC_Local_Provider_V1::safe_cookie_header($raw,$locale);
 }
 public static function classify_device(string $ua): string {
  // Share device classification with the local bridge and its Nginx partition.
  return class_exists('MAD4B_LSC_Local_Provider_V1', false)
   ? MAD4B_LSC_Local_Provider_V1::device($ua) : 'desktop';
 }
 private static function guard(): bool {
  if(!class_exists('MAD4B_LSC_Local_Provider_V1', false)
   || !MAD4B_LSC_Local_Provider_V1::local_css_eligible()) return false;
  $identity=MAD4B_LSC_Local_Provider_V1::identity();
  if(!$identity || PHP_SAPI==='cli' || is_admin() || wp_doing_ajax()
    || is_user_logged_in() || is_preview() || !did_action('wp')
    || ($_SERVER['REQUEST_METHOD']??'')!=='GET'
    || !empty($_SERVER['QUERY_STRING']) || !empty($_SERVER['HTTP_AUTHORIZATION'])
    || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY']) || !empty($_SERVER['HTTP_X_GEO_COUNTRY'])
    || !empty($_SERVER['HTTP_CF_IPCOUNTRY']) || !empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY'])) return false;
  $requestHost=(string)wp_parse_url('http://'.(string)($_SERVER['HTTP_HOST']??''),PHP_URL_HOST);
  if(strtolower($requestHost)!==$identity['host']) return false;
  $locale=(string)apply_filters('wpml_current_language',null);
  if($locale==='' || strlen($locale)>40 || !preg_match('/^[a-zA-Z0-9_-]+$/D',$locale)) return false;
  if(defined('ICL_SITEPRESS_VERSION')) {
    $active=apply_filters('wpml_active_languages',null,'skip_missing=0');
    if(is_array($active) && !isset($active[$locale])) return false;
  }
  return self::safe_cookie_header((string)($_SERVER['HTTP_COOKIE']??''),$locale);
 }
 public static function policy_valid($manifest): bool {
  $identity=class_exists('MAD4B_LSC_Local_Provider_V1',false)
    ? MAD4B_LSC_Local_Provider_V1::identity() : [];
  if(!$identity || !is_array($manifest)
    ||($manifest['contract']??'')!=='mad4b.dynamic-css.local.v1'
    ||($manifest['site_uuid']??'')!==$identity['site_uuid']
    ||($manifest['site_host']??'')!==$identity['host']
    ||!isset($manifest['routes'])||!is_array($manifest['routes'])
    ||count($manifest['routes'])>120)return false;
  return true;
 }
 public static function activate():void {
  if(!self::guard())return;
  $mode=(string)($_SERVER['HTTP_X_MAD4B_DYNAMIC_CSS']??'');
  if(!in_array($mode,['','canary-v1','native-v1'],true))return;
  if($mode!==''){
   // Experiment responses must not enter browser or proxy caches under the same public URL.
   if(!headers_sent()){
    nocache_headers();
    header('Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate',true);
   }
  }
  if($mode==='native-v1')return;
  // Do not combine two independent canary optimizations on the same request.
  if(in_array((string)($_SERVER['HTTP_X_MAD4B_CSS_PRIORITY']??''),['candidate-v1','native-v1'],true))return;
  $raw=is_readable(self::POLICY)?@file_get_contents(self::POLICY):false;
  if(!is_string($raw)||strlen($raw)>110000)return;
  $manifest=json_decode($raw,true);
  if(!self::policy_valid($manifest))return;
  $path=(string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
  $locale=(string)apply_filters('wpml_current_language',null);
  $key=hash('sha256',$locale.'|'.$path);
  $parent=$manifest['routes'][$key]??null;
  $ua=(string)($_SERVER['HTTP_USER_AGENT']??'');
  if($ua===''||strlen($ua)>1024||!is_array($parent)||($parent['path']??'')!==$path||($parent['locale']??'')!==$locale)return;
  $device=self::classify_device($ua);
  if(!empty($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']) && $_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']!==$device)return;
  $item=$parent['devices'][$device]??null;
  // No fallback to legacy route-wide optimization: each device needs its own evidence.
  if(!is_array($item)||($item['device']??'')!==$device)return;
  if(($item['status']??'')!=='certified' && !($mode==='canary-v1' && ($item['status']??'')==='candidate'))return;
  if($mode===''&&($item['status']??'')!=='certified')return;
  if(($item['expires']??0)<time()||($item['expires']??0)>time()+86400*14)return;
  if(($item['source_version']??'')!==MAD4B_LSC_Local_Provider_V1::source_version())return;
  if((string)($item['guest_mode']??'__unbound__')!==(string)get_option('litespeed.conf.guest','0'))return;
  // Any WordPress/Elementor/source revision invalidates previously certified CSS immediately.
  if((int)($item['epoch']??0)!==(int)get_option('mad4b_css_ai_epoch_v1',1))return;
  $proof=(int)get_option('mad4b_css_ai_cache_proof_v1',0);
  if($proof<time()-7200 || $proof>time()+300)return;
  $assets=$item['assets']??null;
  if(!is_array($assets)||count($assets)>16||count($assets)<1)return;
  $verified=[];
  foreach($assets as $handle=>$a){
   if(!preg_match('/^[a-z0-9_-]{3,90}$/D',(string)$handle)||!is_array($a))return;
   $rel=$a['relpath']??''; $sha=$a['sha256']??'';
   $contentUrl=(string)wp_parse_url(content_url('/'),PHP_URL_PATH);
   $prefix=rtrim($contentUrl,'/').'/';
   if(!is_string($rel) || $prefix==='/' || !str_starts_with($rel,$prefix)
     ||str_contains($rel,'..') || !is_string($sha)
     ||!preg_match('/^[a-f0-9]{64}$/D',$sha))return;
   $subpath=substr($rel,strlen($prefix));
   if(!preg_match('~^(?:plugins|themes|mu-plugins)/[a-zA-Z0-9/_.-]+\.css$~D',$subpath))return;
   $file=WP_CONTENT_DIR.'/'.$subpath;
   $real=realpath($file);$content=realpath(WP_CONTENT_DIR);
   if(!$real||!$content||strpos($real,$content.DIRECTORY_SEPARATOR)!==0||!is_file($real)||!hash_equals($sha,(string)@hash_file('sha256',$real)))return;
   $verified[$handle]=['relpath'=>$rel,'tier'=>in_array($a['tier']??'',['idle','below-fold'],true)?$a['tier']:'idle'];
  }
  if(!$verified)return;
  self::$route=$path; self::$assets=$verified;
  add_filter('litespeed_optimize_css_excludes',static function($ex){
    if(!is_array($ex))return $ex;
    foreach(self::$assets as $asset)$ex[]=$asset['relpath'];
    return array_values(array_unique($ex));
  },10001);
  add_filter('style_loader_tag',static function($tag,$handle,$href,$media){
    if(!isset(self::$assets[$handle])||!is_string($tag)||!is_string($href))return $tag;
    $asset=self::$assets[$handle];
    if(strtolower((string)wp_parse_url($href,PHP_URL_HOST))!==MAD4B_LSC_Local_Provider_V1::identity()['host']
       ||(string)wp_parse_url($href,PHP_URL_PATH)!==$asset['relpath']
       ||!preg_match('~\\brel=[\'"]stylesheet[\'"]~i',$tag)
       ||preg_match('~\\bonload=|\\bmedia=[\'"]print[\'"]~i',$tag))return $tag;
    $url=esc_url($href);$tier=esc_attr($asset['tier']);
    // Browser starts fetching this CSS immediately; this affects render-blocking only, not availability.
    return '<link rel="stylesheet" data-mad4b-autonomous="'.$tier.'" href="'.$url.'" media="print" fetchpriority="'.($asset['tier']==='idle'?'low':'auto').'" onload="this.onload=null;this.media=\'all\'" />'
      .'<noscript><link rel="stylesheet" href="'.$url.'" media="all" /></noscript>';
  },705,4);
 }
}
add_action('wp',['MAD4B_Autonomous_CSS_V1','activate'],109);
