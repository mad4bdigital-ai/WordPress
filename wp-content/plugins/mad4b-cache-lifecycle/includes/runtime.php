<?php
/** Plugin Name: MAD4B Targeted Cache Lifecycle
 * Version: 1.0.5
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
 private static bool $membership_change=false;
 private static bool $taxonomy_change=false;
 public static function boot(){
  add_action('save_post',[__CLASS__,'post'],40);
  add_action('before_delete_post',[__CLASS__,'removed'],40);
  add_action('transition_post_status',static function($new,$old,$p){if($new!==$old&&($new==='publish'||$old==='publish')){self::$membership_change=true;if($old==='publish'&&$new!=='publish')self::removed($p->ID);else self::post($p->ID,true);}},40,3);
  foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)add_action($hook,static function($mid,$id,$key){
   if(self::metadata_changes_public_html($key))self::post($id);
  },40,3);
  add_action('set_object_terms',[__CLASS__,'post_terms_changed'],40,6);
  foreach(['update_post_metadata','delete_post_metadata'] as $hook)add_filter($hook,static function($check,$id,$key){if($key==='related_tour_id'){ $old=(int)get_post_meta($id,$key,true);if($old&&get_post_type($old)==='tours-and-activities')self::post($old);}return $check;},10,3);
  add_action('pre_delete_term',static function($id,$taxonomy){self::term($id,0,$taxonomy);},40,2);
  foreach(['created_term','edited_term','delete_term'] as $hook)add_action($hook,[__CLASS__,'term'],40,3);
  add_action('shutdown',[__CLASS__,'flush'],15);
  add_action('init',[__CLASS__,'register_cct'],99);
  foreach(['jet-engine/relation/update/after','jet-engine/relation/delete/after'] as $hook)add_action($hook,static function($parent,$child,$unused,$relation){if(!is_object($relation)||!method_exists($relation,'get_args'))return;$a=$relation->get_args();if((0===strpos((string)($a['parent_object']??''),'posts::')))self::post((int)$parent);if((0===strpos((string)($a['child_object']??''),'posts::')))self::post((int)$child);},40,4);
  add_action('template_redirect',[__CLASS__,'headers'],0);
  add_filter('rest_post_dispatch',[__CLASS__,'rest_headers'],999,3);
  add_filter('wp_headers',[__CLASS__,'early_headers'],999);
 }
 /**
  * Non-rendering, runtime-derived metadata is NOT a content edit.
  *
  * Elementor automatically rewrites element/usage cache entries during
  * frontend rendering and cron, including for shared Elementor templates.
  * Those writes were rotating the entire homepage generation repeatedly.
  * The actual Elementor document, widget/settings, post and taxonomy edits
  * continue through existing post/meta/term hooks and MUST invalidate.
  *
  * Unknown metadata is always treated as content-affecting (fail safe).
  * Future derived metadata must be explicitly classified and tested.
  * No generic wildcard can suppress public content invalidation.
  */
 public static function metadata_changes_public_html($key): bool {
  if(!is_string($key)||$key==='')return true;
  $non_rendering=[
   '_edit_lock','_edit_last','_wp_old_slug',
   '_elementor_css','_elementor_css_version',
   '_elementor_controls_usage','_elementor_element_cache'
  ];
  return !in_array($key,$non_rendering,true);
 }
 public static function post($id,$force=false){
  if($force)self::$membership_change=true;
  $p=get_post((int)$id);if(isset(self::$posts[(int)$id]))return;if(!$p||wp_is_post_revision($id)||wp_is_post_autosave($id))return;
  $type=get_post_type_object($p->post_type);
  if(!$type||!$type->public||in_array($p->post_type,['attachment','jet-form-builder','tour_orders','tour_bookings','jet-engine-booking'],true))return;
  // Tour rates are internal pricing records, not independently renderable
  // public pages. Invalidate their related tour, not /tour-rates/ URLs.
  if($p->post_type==='tour-rates'){
   if($p->post_status==='publish'||$force){
    $tour=(int)get_post_meta($id,'related_tour_id',true);
    if($tour&&get_post_type($tour)==='tours-and-activities')self::post($tour);
   }
   return;
  }
  if($p->post_status==='publish'||$force){self::$posts[(int)$id]=true;$link=get_permalink($id);if(self::safe_url($link))self::$extra[]=$link;
  }
 }
 public static function post_terms_changed($id,$terms,$tt_ids,$taxonomy,$append=false,$old_tt_ids=[]){
  // JetEngine can re-save the same membership on every content edit.
  // Preserve home invalidation only for a real taxonomy membership delta.
  $newIds=array_values(array_unique(array_map('intval',(array)$tt_ids)));
  $oldIds=array_values(array_unique(array_map('intval',(array)$old_tt_ids)));
  sort($newIds,SORT_NUMERIC);sort($oldIds,SORT_NUMERIC);
  if($newIds!==$oldIds)self::$taxonomy_change=true;
  self::post($id);
 }
 public static function removed($id){self::post($id,true);if(get_post_type((int)$id)==='tour-rates')return;$url=get_permalink($id);if(self::safe_url($url))self::$removed[self::key($url)]=true;}
 public static function term($id,$tt=0,$taxonomy=''){
  self::$taxonomy_change=true;
  $tax=get_taxonomy($taxonomy);if($tax&&$tax->public){self::$terms[(int)$id]=$taxonomy;$link=get_term_link((int)$id,$taxonomy);if(!is_wp_error($link)&&self::safe_url($link))self::$extra[]=$link;}
 }

 public static function register_cct(){
  if(!function_exists('jet_engine')||!isset(jet_engine()->relations)||!method_exists(jet_engine()->relations,'get_active_relations'))return;
  $slugs=[];foreach(jet_engine()->relations->get_active_relations() as $relation){
   if(!method_exists($relation,'get_args'))continue;$a=$relation->get_args();
   if(!(0===strpos((string)($a['child_object']??''),'cct::'))||!(0===strpos((string)($a['parent_object']??''),'posts::')))continue;
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
   if(($a['child_object']??'')!=='cct::'.$slug||!(0===strpos((string)($a['parent_object']??''),'posts::')))continue;
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
 /**
  * Read-only homepage impact gate. A content-only update to an unrelated
  * public CPT must not evict the entire mobile/desktop home cache.
  *
  * The gate is deliberately conservative: status, term-membership, template,
  * front-page changes and an incomplete/stale per-locale/device dependency
  * inventory keep the original all-language home invalidation behavior.
  * It never trusts a negative result unless the homepage query IDs are
  * inspectable and all expected home variants have fresh graph snapshots.
  */
 public static function home_impact_preview(array $post_ids,bool $membership_or_taxonomy=false):array {
  if($membership_or_taxonomy)return ['purge'=>true,'reason'=>'membership_or_taxonomy_change'];
  if(!$post_ids)return ['purge'=>true,'reason'=>'unknown_change'];
  $changed=[];
  foreach($post_ids as $id){
   $id=(int)$id;$type=$id>0?get_post_type($id):false;
   if(!$type)return ['purge'=>true,'reason'=>'unknown_post'];
   // Shared templates and WordPress pages may be referenced from any home.
   if(in_array($type,['page','elementor_library','elementor-hf'],true))
     return ['purge'=>true,'reason'=>'document_or_template_change'];
   $changed[$type]=true;
  }
  if(!class_exists('MAD4B_Page_Dependency_Cache',false)
    ||!MAD4B_Page_Dependency_Cache::ready())
    return ['purge'=>true,'reason'=>'dependency_graph_unavailable'];
  $languages=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
  if(!is_array($languages)||!$languages)return ['purge'=>true,'reason'=>'language_inventory_missing'];
  $root=home_url('/');
  $paths=[];
  foreach(array_keys($languages) as $code){
   $url=apply_filters('wpml_permalink',$root,(string)$code);
   $path=is_string($url)?wp_parse_url($url,PHP_URL_PATH):null;
   if(!is_string($path)||!preg_match('~^/(?:[a-z]{2}/)?$~iD',$path))
     return ['purge'=>true,'reason'=>'unexpected_home_path'];
   $paths[$path]=true;
  }
  if(!$paths||count($paths)!==count($languages))
    return ['purge'=>true,'reason'=>'ambiguous_locale_path'];
  global $wpdb;
  $pages=$wpdb->prefix.'mad4b_pd_pages';$edges=$wpdb->prefix.'mad4b_pd_edges';
  $qs=implode(',',array_fill(0,count($paths),'%s'));
  $sql=$wpdb->prepare("SELECT p.page_path,p.device,p.coverage,p.page_hash,e.dep_key
      FROM $pages p LEFT JOIN $edges e
        ON p.page_hash=e.page_hash AND e.expires_at>UTC_TIMESTAMP()
      WHERE p.node_kind='page' AND p.expires_at>UTC_TIMESTAMP()
        AND p.page_path IN ($qs) LIMIT 2400",...array_keys($paths));
  $rows=$wpdb->get_results($sql,ARRAY_A);
  if(!is_array($rows)||$wpdb->last_error!==''||count($rows)>=2400)
    return ['purge'=>true,'reason'=>'dependency_inventory_unavailable'];
  $seen=[];$observed_types=[];$query_ids=[];$direct_posts=[];
  foreach($rows as $row){
   if(!in_array($row['device']??'',['desktop','mobile'],true))continue;
   $seen[($row['page_path']??'').'|'.$row['device']]=true;
   $key=(string)($row['dep_key']??'');
   if(preg_match('/^post:([0-9]+)$/D',$key,$m))$direct_posts[(int)$m[1]]=true;
   if(preg_match('/^query_pt:([a-z0-9_-]+)$/D',$key,$m))$observed_types[$m[1]]=true;
   if(preg_match('/^query_id:([0-9]+)$/D',$key,$m))$query_ids[(int)$m[1]]=true;
  }
  foreach(array_keys($paths) as $path)foreach(['desktop','mobile'] as $device)
   if(!isset($seen[$path.'|'.$device]))
     return ['purge'=>true,'reason'=>'incomplete_home_variant_graph'];
  // Directly observed home cards establish currently relevant source types.
  foreach(array_keys($direct_posts) as $id){
   $type=get_post_type($id);
   if(is_string($type))$observed_types[$type]=true;
  }
  // Query Builder may contain future members that are absent from today's
  // homepage. Inspect its registered source types, not only rendered posts.
  if($query_ids){
   if(!class_exists('Jet_Engine\Query_Builder\Manager'))
      return ['purge'=>true,'reason'=>'query_registry_unavailable'];
   $manager=\Jet_Engine\Query_Builder\Manager::instance();
   foreach(array_keys($query_ids) as $qid){
    $query=$manager->get_query_by_id($qid);
    if(!$query)return ['purge'=>true,'reason'=>'query_definition_missing'];
    $kind=(string)($query->query_type??'');
    if($kind==='terms')continue;
    if($kind!=='posts')return ['purge'=>true,'reason'=>'unsupported_home_query_type'];
    $settings=(array)($query->query??[]);
    $types=$settings['post_type']??[];
    if(is_string($types))$types=array_filter(preg_split('/[,:\\s]+/',$types));
    if(!is_array($types)||!$types)return ['purge'=>true,'reason'=>'dynamic_home_post_query'];
    foreach($types as $type)if(is_string($type))$observed_types[$type]=true;
   }
  }
  if(!$direct_posts && !$query_ids)return ['purge'=>true,'reason'=>'graph_has_no_render_evidence'];
  foreach($post_ids as $id)if(isset($direct_posts[(int)$id]))
    return ['purge'=>true,'reason'=>'direct_home_card'];
  foreach(array_keys($changed) as $type)if(isset($observed_types[$type]))
    return ['purge'=>true,'reason'=>'homepage_post_type_dependency'];
  // Negative dependency observations are not certified as complete by the
  // Elementor/JetEngine graph. A site-owned audited policy must ALSO allow a
  // content-only skip for this type. New/unknown types stay fail-open (purge).
  $policy=get_option('mad4b_cache_lifecycle_home_content_only_exclusions_v1',[]);
  $policy=is_array($policy)?$policy:[];
  $host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
  foreach(array_keys($changed) as $type){
   $rule=$policy[$type]??null;
   if(!is_array($rule)||($rule['mode']??'')!=='content_only'
      ||($rule['host']??'')!==$host
      ||(int)($rule['expires_at']??0)<=time())
     return ['purge'=>true,'reason'=>'unapproved_unrelated_type','type'=>$type];
  }
  return ['purge'=>false,'reason'=>'audited_unrelated_content_only',
    'types'=>array_keys($changed),'observed_types'=>array_keys($observed_types),
    'home_variants'=>count($seen)];
 }
 public static function dependencies(){
  $homeImpact=self::home_impact_preview(array_keys(self::$posts),self::$membership_change||self::$taxonomy_change||self::$dirty);
  $urls=self::$extra;$langs=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);if(!is_array($langs)||!$langs)$langs=['default'=>[]];
  $original=apply_filters('wpml_current_language',null);
  foreach($langs as $code=>$unused){
   if($code!=='default')do_action('wpml_switch_language',$code);
   if($homeImpact['purge'])$urls[]=home_url('/');foreach(array_keys(self::$archive_types) as $type){$archive=get_post_type_archive_link($type);if($archive)$urls[]=$archive;}$blog=(int)get_option('page_for_posts');
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
 public static function flush(){if(!self::$posts&&!self::$terms&&!self::$dirty)return;$urls=self::dependencies();self::$posts=[];self::$terms=[];self::$extra=[];self::queue_urls($urls);self::$removed=[];self::$dirty=false;self::$archive_types=[];self::$membership_change=false;self::$taxonomy_change=false;}
 public static function context(){
  foreach(array_keys($_COOKIE) as $key)if(preg_match('/(?:currency|market|passport|traveller|traveler|woocommerce_items_in_cart|wp_woocommerce_session_)/i',(string)$key))return 'personalized_cookie';
  if(isset($_GET['_mad4b_cache_delivery']))return 'native_purge_delivery';
  // Unified Date Intent and WCML's URL currency selector are visitor context.
  // Protect them at WordPress origin even when the edge already bypasses queries.
  foreach(array_keys($_GET) as $key)if(preg_match('/^(?:currency|market|country|passport|travel_date|start_date|adults|children|infants|wc-ajax|wcmlc|mad4b_date_(?:mode|from|to|clear))$/i',(string)$key))return 'personalized_query';
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
  header('X-MAD4B-Content-Revision: '.self::revision($url));header('X-MAD4B-Cache-Lifecycle: 1.0.4');
 }
 public static function rest_headers($response,$server,$request){
  $route=$request->get_route();if(preg_match('~(?:quote|booking|checkout|payment|availability|pricing|calculate|cart)~i',$route)||self::context()){$response=rest_ensure_response($response);$response->header('Cache-Control','private, no-store, no-cache, max-age=0, must-revalidate');$response->header('X-MAD4B-Cache-Policy','private-commerce');}return $response;
 }
}
MAD4B_Cache_Lifecycle_V1::boot();

}
