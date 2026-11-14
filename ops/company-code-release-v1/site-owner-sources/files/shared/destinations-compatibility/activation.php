<?php
/** Destination availability managed by the native JetEngine Options Page. */
if (!defined('ABSPATH')) exit;
function destinations_activation_allowed($term_id) {
 $id=absint($term_id);
 static $memo=[];
 // Frontend public-read memo only. Settings writes, WP-CLI, REST and AJAX
 // must always check fresh term state and cannot inherit a GET decision.
 $useMemo=($id>0 && ($_SERVER['REQUEST_METHOD']??'')==='GET'
  && !is_admin() && !wp_doing_ajax() && !is_user_logged_in()
  && !(defined('REST_REQUEST')&&REST_REQUEST)
  && !(defined('WP_CLI')&&WP_CLI));
 $prefix=$useMemo?(get_current_blog_id().':'):'';
 $original=$id;
 if($useMemo && array_key_exists($prefix.$id,$memo))return $memo[$prefix.$id];
 $seen=[]; $chain=[]; $allowed=false;
 for($depth=0;$id && $depth<30;$depth++) {
  if(isset($seen[$id]))break;
  if($useMemo && array_key_exists($prefix.$id,$memo)){
   $allowed=$memo[$prefix.$id]; break;
  }
  $seen[$id]=1;
  $chain[]=$id;
  // Raw persisted taxonomy identity: get_term() may remap a foreign-language
  // WPML term into the *current* locale before reading its parent/visibility.
  // Availability is governed by this exact term and its exact ancestor chain.
  $term=WP_Term::get_instance($id,'destinations');
  if(!$term instanceof WP_Term ||
     in_array((string)get_term_meta($id,'is_public',true),
              ['0','false','no','disabled'],true))break;
  $id=(int)$term->parent;
  if($id===0){$allowed=true;break;}
 }
 if($useMemo){
  foreach($chain as $item)$memo[$prefix.$item]=$allowed;
  $memo[$prefix.$original]=$allowed;
 }
 return $allowed;
}
function destinations_activation_groups() {
 $types=['active_continents'=>'continent','active_countries'=>'country','active_governorates'=>'state_governorate'];
 $result=array_fill_keys(array_keys($types),[]);
 global $wpdb;
 $terms=get_terms(['taxonomy'=>'destinations','hide_empty'=>false,'suppress_filter'=>true]);
 if(is_wp_error($terms))return $result;
 foreach($terms as $t){
  $type=get_term_meta($t->term_id,'destination_type',true);
  $field=array_search($type,$types,true);if(!$field)continue;
  $lang=$wpdb->get_var($wpdb->prepare("SELECT tr.language_code FROM {$wpdb->prefix}icl_translations tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.element_id WHERE tt.term_id=%d AND tr.element_type='tax_destinations'",$t->term_id));
  if($lang!=='en')continue;
  $ancestors=array_map('intval',get_ancestors($t->term_id,'destinations','taxonomy'));
  $result[$field][]=['id'=>(int)$t->term_id,'name'=>$t->name,'ancestors'=>$ancestors];
 }
 foreach($result as &$items)usort($items,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
 return $result;
}
add_filter('jet-engine/options-pages/raw-fields',static function($fields,$page){
 if(($page->slug??'')!=='destination-activation')return $fields;
 // Option choices are for editing, not passive public frontend rendering.
 // REST_REQUEST is defined after init on some WordPress versions, so identify
 // a REST URL or Elementor preview early as well as admin/AJAX requests.
 $route=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
 $rest_prefix=function_exists('rest_get_url_prefix')?rest_get_url_prefix():'wp-json';
 $rest_request=(defined('REST_REQUEST')&&REST_REQUEST)
   || isset($_GET['rest_route'])
   || (bool)preg_match('~/(?:'.preg_quote($rest_prefix,'~').')(?:/|$)~',$route);
 $preview=isset($_GET['elementor-preview']) || isset($_GET['preview']) || isset($_GET['customize_changeset_uuid']);
 if(!is_admin() && !wp_doing_ajax() && !$rest_request && !$preview)return $fields;
 $groups=destinations_activation_groups();
 foreach($fields as &$f){$name=$f['name']??'';if(!isset($groups[$name]))continue;
  $f['options']=array_map(static fn($t)=>['key'=>(string)$t['id'],'value'=>$t['name']],$groups[$name]);
  $f['options_source']='manual';
 }
 return $fields;
},20,2);
add_action('jet-engine/options-pages/after-save/destination-activation',static function(){
 if(!current_user_can('manage_options'))return;
 $data=get_option('destination-activation',[]);if(!is_array($data))return;
 $groups=destinations_activation_groups();$allowed=[];
 foreach($groups as $field=>$items){$selected=array_map('intval',(array)($data[$field]??[]));
  $valid=array_column($items,'id');$allowed[$field]=array_values(array_intersect($selected,$valid));
 }
 // Dependent checklists cannot enable a child of a disabled ancestor.
 foreach(['active_countries','active_governorates'] as $field){
  foreach($groups[$field] as $term){
   if(!in_array($term['id'],$allowed[$field],true))continue;
   foreach($groups['active_continents'] as $root)if(in_array($root['id'],$term['ancestors'],true)&&!in_array($root['id'],$allowed['active_continents'],true))$allowed[$field]=array_diff($allowed[$field],[$term['id']]);
   if($field==='active_governorates')foreach($groups['active_countries'] as $country)if(in_array($country['id'],$term['ancestors'],true)&&!in_array($country['id'],$allowed['active_countries'],true))$allowed[$field]=array_diff($allowed[$field],[$term['id']]);
  }
 }
 // Change the full WPML group together; retain hidden-language records.
 global $wpdb;
 foreach($groups as $field=>$items)foreach($items as $item){
  $selected=in_array($item['id'],$allowed[$field],true);
  $ttid=(int)get_term($item['id'],'destinations')->term_taxonomy_id;
  $trid=$wpdb->get_var($wpdb->prepare("SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_type='tax_destinations' AND element_id=%d",$ttid));
  $ids=$trid?$wpdb->get_col($wpdb->prepare("SELECT tt.term_id FROM {$wpdb->prefix}icl_translations tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.element_id WHERE tr.element_type='tax_destinations' AND tr.trid=%d",$trid)):[$item['id']];
  foreach($ids as $id)update_term_meta((int)$id,'is_public',$selected?'1':'0');
 }
 update_option('destination-activation',array_map('array_values',$allowed),false);
 if(class_exists('RankMath\\Sitemap\\Cache'))\RankMath\Sitemap\Cache::invalidate_storage();
},30);
add_filter('rank_math/sitemap/entry',static function($entry,$type,$term){
 return $type==='term' && $term instanceof WP_Term && $term->taxonomy==='destinations' && !destinations_activation_allowed($term->term_id)?false:$entry;
},1200,3);
// Management lists always retain disabled terms. Only public filter sources are constrained.
function destinations_activation_is_options_request(){
 if(!is_admin())return false;
 $page=isset($_REQUEST['page'])?sanitize_key(wp_unslash($_REQUEST['page'])):'';
 return $page==='destination-activation';
}
add_filter('get_terms',static function($terms,$taxonomies){
 if((is_admin()&&!wp_doing_ajax())||destinations_activation_is_options_request()||!is_array($terms)||!in_array('destinations',(array)$taxonomies,true)||!$terms||!isset($terms[0])||!$terms[0] instanceof WP_Term)return $terms;
 // WordPress primes relationships for multiple taxonomies in one request.
 // Keep unrelated owner terms; apply destination visibility only to destinations.
 return array_values(array_filter($terms,static fn($t)=>!$t instanceof WP_Term || $t->taxonomy!=='destinations' || destinations_activation_allowed($t->term_id)));
},1200,2);
add_filter('rank_math/frontend/robots',static function($robots){
 return is_tax('destinations')&&!destinations_activation_allowed(get_queried_object_id())?['noindex','follow']:$robots;
},1200);
// Enforce term visibility before theme/WPML template redirects.
add_action('wp',static function(){
 if(!is_tax('destinations')||destinations_activation_allowed(get_queried_object_id()))return;
 global $wp_query;$wp_query->set_404();status_header(404);nocache_headers();
 $file=get_404_template();if($file&&is_file($file))require $file;else echo 'Not Found';exit;
},999);
// Dynamic cascading controls inside JetEngine's own Options Page.
add_action('admin_enqueue_scripts',static function(){
 if(!isset($_GET['page'])||sanitize_key(wp_unslash($_GET['page']))!=='destination-activation')return;
 $groups=destinations_activation_groups();
 wp_register_script('destination-activation-cascade',plugins_url('../assets/activation.js',__FILE__),[], '0.2.0',true);
 wp_enqueue_script('destination-activation-cascade');
 wp_add_inline_script('destination-activation-cascade','window.destinationActivationTree='.wp_json_encode($groups).';','before');
});

// JetSmartFilters taxonomy options (checkbox, select, radio and hierarchy).
add_filter('jet-smart-filters/data/terms-objects',static function($terms,$tax){
 if($tax!=='destinations'||destinations_activation_is_options_request()||!is_array($terms))return $terms;
 return array_values(array_filter($terms,static fn($t)=>!$t instanceof WP_Term||destinations_activation_allowed($t->term_id)));
},1200,2);
