<?php
/**
 * Plugin Name: MAD4B Dynamic JS Combine Authority (Staging)
 * Description: Device/locale/route-scoped LiteSpeed JS Combine selection with native fallback; no independent JS optimizer.
 * Version: 0.1.1
 */
defined('ABSPATH') || exit;
final class MAD4B_Device_JS_Combine_V1 {
 const HOST='tours.dev.mad4b.com';
 const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 const POLICY=WP_CONTENT_DIR.'/mu-plugins/mad4b-js-autonomous-policy.json';
 private static $choice=null;
 public static function scope():bool {
  if(wp_get_environment_type()!=='staging'||(string)wp_parse_url(home_url(),PHP_URL_HOST)!==self::HOST)return false;
  $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
  return is_array($profile)&&($profile['site_uuid']??'')===self::UUID
   &&defined('LSCWP_V')&&LSCWP_V==='7.9.2'
   &&defined('ELEMENTOR_VERSION')&&ELEMENTOR_VERSION==='4.3.5';
 }
 public static function device(string $ua):string {
  return preg_match('~Mobile|Android|Silk|Kindle|BlackBerry|Opera.Mini|Opera.Mobi~i',$ua)?'mobile':'desktop';
 }
 public static function decision():string {
  if(self::$choice!==null)return self::$choice;
  self::$choice='native';
  if(!self::scope()||is_admin()||is_user_logged_in()||wp_doing_ajax()||is_preview()
   ||($_SERVER['REQUEST_METHOD']??'')!=='GET'||!empty($_SERVER['QUERY_STRING'])
   ||!empty($_SERVER['HTTP_COOKIE'])||!empty($_SERVER['HTTP_AUTHORIZATION'])
   ||(string)get_option('litespeed.conf.optm-js_defer','0')!=='0'
   ||(string)get_option('litespeed.conf.guest_optm','0')==='1'
   ||(string)get_option('litespeed.conf.optm-js_min','0')!=='1')return self::$choice;
  $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
  $ua=(string)($_SERVER['HTTP_USER_AGENT']??'');
  $lang=(string)apply_filters('wpml_current_language',null);
  if(!$ua||strlen($ua)>1024||!preg_match('#^/[a-zA-Z0-9/_~.\-]*$#D',$path)
   ||strlen($path)>250||strpos($path,'..')!==false||strpos($path,'//')!==false
   ||!in_array($lang,['en','ar','fr','de','es','it'],true))return self::$choice;
  // Explicit, isolated A/B only. Nginx bypass + no-store is mandatory.
  $manual=(string)($_SERVER['HTTP_X_MAD4B_JS_POLICY']??'');
  if($manual!==''){
   if(($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']??'')!=='1'
    ||($_SERVER['HTTP_X_MAD4B_DYNAMIC_CSS']??'')!=='native-v1')return self::$choice;
   if(in_array($manual,['native-v1','files-v1','full-v1'],true)){
    self::$choice=$manual==='files-v1'?'files':($manual==='full-v1'?'full':'native');
   }
   return self::$choice;
  }
  if(!is_readable(self::POLICY)||($bytes=@filesize(self::POLICY))===false||$bytes>90000)return self::$choice;
  $data=json_decode((string)@file_get_contents(self::POLICY),true);
  if(!is_array($data)||($data['contract']??'')!=='mad4b.js.route-policy.v1'
   ||($data['site_uuid']??'')!==self::UUID||($data['site_host']??'')!==self::HOST)return self::$choice;
  $item=$data['routes'][hash('sha256',$lang.'|'.$path)]['devices'][self::device($ua)]??null;
  if(!is_array($item)||($item['status']??'')!=='certified'
   ||!in_array($item['mode']??'',['files','full'],true)
   ||(int)($item['expires']??0)<=time()
   ||(int)($item['epoch']??0)!==(int)get_option('mad4b_css_ai_epoch_v1',1)
   ||(string)($item['guest_mode']??'')!==(string)get_option('litespeed.conf.guest','0')
   ||(string)($item['litespeed']??'')!==LSCWP_V
   ||(string)($item['elementor']??'')!==ELEMENTOR_VERSION)return self::$choice;
  self::$choice=$item['mode'];return self::$choice;
 }
 public static function overview():array {
  if(!self::scope()||!is_readable(self::POLICY))return [];
  $data=json_decode((string)@file_get_contents(self::POLICY),true);
  if(!is_array($data)||($data['contract']??'')!=='mad4b.js.route-policy.v1'
   ||($data['site_uuid']??'')!==self::UUID)return [];
  $out=[];
  foreach($data['routes']??[] as $r){
   if(!is_array($r))continue;
   foreach(['desktop','mobile'] as $device){
    $p=$r['devices'][$device]??null;if(!is_array($p))continue;
    $trials=$p['trials']??[];
    $out[]=['path'=>$r['path']??'','locale'=>$r['locale']??'','device'=>$device,
      'status'=>$p['status']??'native','mode'=>$p['mode']??'native',
      'trials'=>is_array($trials)?count($trials):0,
      'reason'=>$p['decision']??implode(', ',array_slice($p['issues']??[],0,2))];
   }
  }
  return array_slice($out,0,200);
 }
 public static function combine($current){
  $d=self::decision();return $d==='native'?$current:'1';
 }
 public static function external_inline($current){
  $d=self::decision();return $d==='full'?'1':($d==='files'?'0':$current);
 }
 public static function combining():bool{return in_array(self::decision(),['files','full'],true);}
 public static function boot():void {
  add_filter('litespeed_conf_load_option_optm-js_comb',[__CLASS__,'combine'],99);
  add_filter('litespeed_conf_load_option_optm-js_comb_ext_inl',[__CLASS__,'external_inline'],99);
  add_action('rest_api_init',static function(){register_rest_route('mad4b/v1','/js-policy',[
   'methods'=>'GET','permission_callback'=>static function(){return current_user_can('manage_options');},
   'callback'=>static function(){return rest_ensure_response([
    'contract'=>'mad4b.js.route-policy.v1','scope'=>self::scope(),
    'global_defer'=>get_option('litespeed.conf.optm-js_defer'),
    'global_combine'=>get_option('litespeed.conf.optm-js_comb'),
    'global_external_inline'=>get_option('litespeed.conf.optm-js_comb_ext_inl'),
    'certification_required'=>true,'devices'=>self::overview()]);}]);});
 }
}
MAD4B_Device_JS_Combine_V1::boot();
