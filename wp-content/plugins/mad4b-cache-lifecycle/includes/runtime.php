<?php
/** Plugin Name: MAD4B Targeted Cache Lifecycle
 * Version: 1.0.0
 * Uses native LiteSpeed URL invalidation; never flushes Object Cache.
 */
if (!defined('ABSPATH')) exit;
if(!class_exists('MAD4B_Cache_Lifecycle_V1',false)){
final class MAD4B_Cache_Lifecycle_V1 {
 const OPTION='mad4b_cache_lifecycle_routes_v1';
 private static $posts=[];
 private static $terms=[];
 private static $extra=[];
 private static $removed=[];
 private static $dirty=false;
 private static $archive_types=[];
 public static function boot(){
  add_action('save_post',[__CLASS__,'post'],40);
  add_action('before_delete_post',[__CLASS__,'removed'],40);
  add_action('transition_post_status',static function($new,$old,$p){if($new!==$old&&($new==='publish'||$old==='publish')){if($old==='publish'&&$new!=='publish')self::removed($p->ID);else self::post($p->ID,true);}},40,3);
  foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)add_action($hook,static function($mid,$id,$key){if(!in_array($key,['_edit_lock','_edit_last','_wp_old_slug','_elementor_css','_elementor_css_version'],true))self::post($id);},40,3);
  add_action('set_object_terms',[__CLASS__,'post'],40);
  foreach(['update_post_metadata','delete_post_metadata'] as $hook)add_filter($hook,static function($check,$id,$key){if($key==='related_tour_id'){ $old=(int)get_post_meta($id,$key,true);if($old&&get_post_type($old)==='tours-and-activities')self::post($old);}return $check;},10,3);
  add_action('pre_delete_term',static function($id,$taxonomy){self::term($id,0,$taxonomy);},40,2);
  foreach(['created_term','edited_term','delete_term'] as $hook)add_action($hook,[__CLASS__,'term'],40,3);
  add_action('shutdown',[__CLASS__,'flush'],15);
  add_action('init',[__CLASS__,'register_cct'],99);
  foreach(['jet-engine/relation/update/after','jet-engine/relation/delete/after'] as $hook)add_action($hook,static function($parent,$child,$unused,$relation){if(!is_object($relation)||!method_exists($relation,'get_args'))return;$a=$relation->get_args();if(str_starts_with((string)($a['parent_object']??''),'posts::'))self::post((int)$parent);if(str_starts_with((string)($a['child_object']??''),'posts::'))self::post((int)$child);},40,4);
  add_action('template_redirect',[__CLASS__,'headers'],0);
  add_filter('rest_post_dispatch',[__CLASS__,'rest_headers'],999,3);
  add_filter('wp_headers',[__CLASS__,'early_headers'],999);
 }
 public static function post($id,$force=false){
  $p=get_post((int)$id);if(isset(self::$posts[(int)$id]))return;if(!$p||wp_is_post_revision($id)||wp_is_post_autosave($id))return;
  $type=get_post_type_object($p->post_type);
  if(!$type||!$type->public||in_array($p->post_type,['attachment','jet-form-builder','tour_orders','tour_bookings','jet-engine-booking'],true))return;
  if($p->post_status==='publish'||$force){self::$posts[(int)$id]=true;$link=get_permalink($id);if(self::safe_url($link))self::$extra[]=$link;
   if($p->post_type==='tour-rates'){$tour=(int)get_post_meta($id,'related_tour_id',true);if($tour&&get_post_type($tour)==='tours-and-activities')self::post($tour);}
  }
 }
 public static function removed($id){self::post($id,true);$url=get_permalink($id);if(self::safe_url($url))self::$removed[self::key($url)]=true;}
 public static function term($id,$tt=0,$taxonomy=''){
  $tax=get_taxonomy($taxonomy);if($tax&&$tax->public){self::$terms[(int)$id]=$taxonomy;$link=get_term_link((int)$id,$taxonomy);if(!is_wp_error($link)&&self::safe_url($link))self::$extra[]=$link;}
 }

 public static function register_cct(){
  if(!function_exists('jet_engine')||!isset(jet_engine()->relations)||!method_exists(jet_engine()->relations,'get_active_relations'))return;
  $slugs=[];foreach(jet_engine()->relations->get_active_relations() as $relation){
   if(!method_exists($relation,'get_args'))continue;$a=$relation->get_args();
   if(!str_starts_with((string)($a['child_object']??''),'cct::')||!str_starts_with((string)($a['parent_object']??''),'posts::'))continue;
   $type=substr($a['parent_object'],7);$pt=get_post_type_object($type);if(!$pt||!$pt->public||in_array($type,['tour_orders','tour_bookings','jet-engine-booking'],true))continue;
   $slug=substr($a['child_object'],5);if(preg_match('/^[a-z0-9_-]+$/D',$slug))$slugs[$slug]=true;
  }
  foreach(array_keys($slugs) as $slug){
   add_action('jet-engine/custom-content-types/updated-item/'.$slug,static function($item,$prev)use($slug){self::cct_changed($slug,$item,$prev);},40,2);
   add_action('jet-engine/custom-content-types/delete-item/'.$slug,static function($id,$item)use($slug){$item['_ID']=$id;self::cct_changed($slug,$item,[]);},40,2);
  }
 }
 public static function cct_changed($slug,$item,$prev=[]){
  if(!is_array($item)||!function_exists('jet_engine')||!isset(jet_engine()->relations))return;
  foreach(jet_engine()->relations->get_active_relations() as $relation){
   if(!method_exists($relation,'get_args')||!method_exists($relation,'get_parents'))continue;$a=$relation->get_args();
   if(($a['child_object']??'')!=='cct::'.$slug||!str_starts_with((string)($a['parent_object']??''),'posts::'))continue;
   $type=substr($a['parent_object'],7);$pt=get_post_type_object($type);if(!$pt||!$pt->public||in_array($type,['tour_orders','tour_bookings','jet-engine-booking'],true))continue;
   self::$dirty=true;self::$archive_types[$type]=true;
   foreach(array_unique([(int)($item['_ID']??0),(int)($prev['_ID']??0)]) as $id){if(!$id)continue;foreach(array_slice((array)$relation->get_parents($id,'ids'),0,100) as $parent)self::post((int)$parent);}
  }
 }
 public static function safe_url($url){
  if(!is_string($url)||!$url)return false;$u=wp_parse_url($url);$home=wp_parse_url(get_option('home'));
  if(!$u||($u['scheme']??'')!=='https'||($u['host']??'')!==($home['host']??'')||isset($u['query'])||isset($u['fragment'])||isset($u['user'])||isset($u['pass'])||isset($u['port']))return false;
  return !preg_match('~/(?:wp-admin|wp-json|cart|checkout|account|my-account|booking|reserve|order-pay)(?:/|$)~i',rawurldecode($u['path']??'/'));
 }
 public static function key($url){return hash('sha256',rawurldecode((string)(wp_parse_url($url,PHP_URL_PATH)?:'/')));}
 public static function revision($url){$rows=get_option(self::OPTION,[]);return $rows[self::key($url)]['revision']??substr(hash('sha256',get_option('home').'|'.self::key($url).'|v1'),0,24);}
 public static function dependencies(){
  $urls=self::$extra;$langs=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);if(!is_array($langs)||!$langs)$langs=['default'=>[]];
  $original=apply_filters('wpml_current_language',null);
  foreach($langs as $code=>$unused){
   if($code!=='default')do_action('wpml_switch_language',$code);
   $urls[]=home_url('/');foreach(array_keys(self::$archive_types) as $type){$archive=get_post_type_archive_link($type);if($archive)$urls[]=$archive;}$blog=(int)get_option('page_for_posts');
   if($blog&&$code!=='default')$blog=(int)apply_filters('wpml_object_id',$blog,'page',false,$code);
   if($blog&&get_post_status($blog)==='publish')$urls[]=get_permalink($blog);
   foreach(array_keys(self::$posts) as $id){
    $p=get_post($id);if(!$p)continue;$translated=$code==='default'?$id:(int)apply_filters('wpml_object_id',$id,$p->post_type,false,$code);
    if($translated&&get_post_status($translated)==='publish')$urls[]=get_permalink($translated);
    $archive=get_post_type_archive_link($p->post_type);if($archive)$urls[]=$archive;
    foreach(get_object_taxonomies($p->post_type,'objects') as $tax){
     if(!$tax->public)continue;$terms=wp_get_object_terms($translated?:$id,$tax->name,['fields'=>'ids']);if(is_wp_error($terms))continue;
     foreach(array_slice($terms,0,30) as $tid){$link=get_term_link((int)$tid,$tax->name);if(!is_wp_error($link))$urls[]=$link;}
    }
   }
   foreach(self::$terms as $id=>$tax){$tid=$code==='default'?$id:(int)apply_filters('wpml_object_id',$id,$tax,false,$code);if($tid){$link=get_term_link($tid,$tax);if(!is_wp_error($link))$urls[]=$link;}}
  }
  do_action('wpml_switch_language',$original);
  return array_values(array_unique(array_filter($urls,[__CLASS__,'safe_url'])));
 }
 public static function queue_urls($urls,$reason='content_change'){
  global $wpdb;$safe=array_values(array_unique(array_filter($urls,[__CLASS__,'safe_url'])));if(!$safe)return [];
  add_option(self::OPTION,[],'','no');$name=self::OPTION;
  // Serialize changes to this one option; concurrent edits retain each other's URLs.
  $wpdb->query('START TRANSACTION');
  try{
   $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",$name));
   $rows=maybe_unserialize($raw);if(!is_array($rows))$rows=[];$now=time();
   foreach($safe as $url){$key=self::key($url);$rows[$key]=['url'=>$url,'revision'=>substr(hash('sha256',wp_generate_uuid4()),0,24),'state'=>'PENDING_INVALIDATION','updated'=>$now,'reason'=>sanitize_key($reason),'expect_absent'=>isset(self::$removed[$key])];}
   foreach($rows as $key=>$row)if(($row['state']??'')==='VERIFIED'&&($row['updated']??0)<$now-30*DAY_IN_SECONDS)unset($rows[$key]);
   if(false===$wpdb->update($wpdb->options,['option_value'=>maybe_serialize($rows)],['option_name'=>$name]))throw new RuntimeException('CACHE_QUEUE_WRITE_FAILED');
   $wpdb->query('COMMIT');wp_cache_delete($name,'options');
  }catch(Throwable $e){$wpdb->query('ROLLBACK');error_log('MAD4B_CACHE_LIFECYCLE_QUEUE_FAILED');return [];}
  foreach($safe as $url)do_action('litespeed_purge_url',$url);
  return $safe;
 }
 public static function flush(){if(!self::$posts&&!self::$terms&&!self::$dirty)return;$urls=self::dependencies();self::$posts=[];self::$terms=[];self::$extra=[];self::queue_urls($urls);self::$removed=[];self::$dirty=false;self::$archive_types=[];}
 public static function context(){
  foreach(array_keys($_COOKIE) as $key)if(preg_match('/(?:currency|market|passport|traveller|traveler|woocommerce_items_in_cart|wp_woocommerce_session_)/i',(string)$key))return 'personalized_cookie';
  if(isset($_GET['_mad4b_cache_delivery']))return 'native_purge_delivery';
  foreach(array_keys($_GET) as $key)if(preg_match('/^(?:currency|market|country|passport|travel_date|start_date|adults|children|infants|wc-ajax)$/i',(string)$key))return 'personalized_query';
  return null;
 }
 public static function early_headers($headers){if(self::context()){$headers['Cache-Control']='private, no-store, no-cache, max-age=0, must-revalidate';$headers['X-MAD4B-Cache-Policy']='private-context';}return $headers;}
 public static function headers(){
  if(headers_sent())return;
  $private=self::context()||is_user_logged_in()||is_preview()||is_search()||($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
  if($private){if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);do_action('litespeed_control_set_nocache','MAD4B private context');nocache_headers();header('Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate',true);header('X-MAD4B-Cache-Policy: private-context');return;}
  if(is_404()||is_feed()||is_admin())return;
  $url='https://'.(wp_parse_url(get_option('home'),PHP_URL_HOST)).(wp_parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');
  if(!self::safe_url($url))return;
  // Native page cache stays enabled; shared edge HTML requires proven context isolation.
  if(defined('LITESPEED_ON')&&LITESPEED_ON&&get_option('mad4b_cache_lifecycle_edge_isolation_verified_v1',false)!==true){header('Cache-Control: private, max-age=0, must-revalidate',true);header('X-MAD4B-Edge-Policy: origin-page-cache');}
  header('Vary: Cookie',false);
  header('X-MAD4B-Content-Revision: '.self::revision($url));header('X-MAD4B-Cache-Lifecycle: 1.0.2');
 }
 public static function rest_headers($response,$server,$request){
  $route=$request->get_route();if(preg_match('~(?:quote|booking|checkout|payment|availability|pricing|calculate|cart)~i',$route)||self::context()){$response=rest_ensure_response($response);$response->header('Cache-Control','private, no-store, no-cache, max-age=0, must-revalidate');$response->header('X-MAD4B-Cache-Policy','private-commerce');}return $response;
 }
}
MAD4B_Cache_Lifecycle_V1::boot();

}
