<?php
/**
 * Plugin Name: MAD4B JS CPU Intelligence (Staging)
 * Description: Bounded, source-scoped CPU diagnostics and WP notifications; never bundles or delays JS itself.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_JS_CPU_Intelligence {
 const HOST='tours.dev.mad4b.com';
 const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 const OPTION='mad4b_js_cpu_dirty_routes_v1';
 const REPORT=WP_CONTENT_DIR.'/mu-plugins/mad4b-js-cpu-intelligence.json';
 public static function scope():bool {
  if(wp_get_environment_type()!=='staging'||(string)wp_parse_url(home_url(),PHP_URL_HOST)!==self::HOST)return false;
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  return is_array($p)&&($p['site_uuid']??'')===self::UUID;
 }
 public static function valid_path($path):bool {
  return is_string($path)&&strlen($path)<=240&&preg_match('#^/[A-Za-z0-9/_~.\-]*$#D',$path)
   &&strpos($path,'..')===false&&strpos($path,'//')===false
   &&!preg_match('#/(?:cart|checkout|booking|account|my-account|wp-admin|wp-json)(?:/|$)#i',$path);
 }
 public static function queue():array {
  if(!self::scope())return [];
  $q=get_option(self::OPTION,[]);return is_array($q)?array_slice($q,0,30):[];
 }
 public static function on_notification($event):void {
  if(!self::scope()||!is_array($event)||!in_array($event['type']??'',['queued','invalidate'],true))return;
  $path=$event['path']??null;if(!self::valid_path($path))return;
  $queue=self::queue();
  // Bounded, one work slot per route; retain freshness without repeated jobs.
  $queue=array_values(array_filter($queue,static function($x)use($path){return is_array($x)&&($x['path']??'')!==$path;}));
  array_unshift($queue,['path'=>$path,'updated'=>time(),'reason'=>sanitize_key($event['type'])]);
  update_option(self::OPTION,array_slice($queue,0,30),false);
 }
 public static function report():array {
  if(!self::scope()||!is_readable(self::REPORT))return [];
  $size=@filesize(self::REPORT);if($size===false||$size>160000)return [];
  $v=json_decode((string)@file_get_contents(self::REPORT),true);
  if(!is_array($v)||($v['contract']??'')!=='mad4b.js.cpu-plans.v1'||($v['site_uuid']??'')!==self::UUID||!is_array($v['pages']??null))return [];
  return $v;
 }
 public static function summary():array {
  $report=self::report();$rows=[];
  foreach(($report['pages']??[]) as $entry){
   if(!is_array($entry)||!self::valid_path($entry['path']??null))continue;
   foreach(['desktop','mobile'] as $device){
    $d=$entry['devices'][$device]??[];
    if(!is_array($d))continue;
    $rows[]=['path'=>$entry['path'],'device'=>$device,'state'=>$d['status']??'NO_VALID_EVIDENCE',
     'samples'=>(int)($d['samples']??0),'recommendation'=>$d['recommended_action']??'retain_native_wait_for_evidence',
     'measured_at'=>(int)($entry['created_at']??0)];
   }
  }
  return ['contract'=>'mad4b.js.cpu-intelligence.v1','runtime_mode'=>'native',
   'automatic_js_changes'=>false,'last_updated'=>(int)($report['updated_at']??0),
   'pending_routes'=>count(self::queue()),'rows'=>array_slice($rows,0,80)];
 }
 public static function rest():void {
  register_rest_route('mad4b/v1','/js-cpu-intelligence',[
   'methods'=>'GET',
   'permission_callback'=>static function(){return current_user_can('manage_options')||current_user_can('mad4b_manage_company_operations');},
   'callback'=>static function(){return rest_ensure_response(self::summary());}]);
 }
 public static function boot():void {
  if(!self::scope())return;
  add_action('mad4b/notifications/recorded',[__CLASS__,'on_notification'],10,1);
  add_action('rest_api_init',[__CLASS__,'rest']);
 }
}
add_action('init',['MAD4B_JS_CPU_Intelligence','boot'],15);
