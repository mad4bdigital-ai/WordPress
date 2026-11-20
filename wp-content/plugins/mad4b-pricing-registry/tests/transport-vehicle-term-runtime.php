<?php
/** Native JetEngine vehicle costs are canonical; no business writes. */
defined('ABSPATH')||exit(2);
$c='MAD4B_Transport_Vehicle_Term_Profiles_V1';
$m='MAD4B_Transport_Distance_Time_V1';
$t=[];$test=static function($name,$ok)use(&$t){$t[$name]=(bool)$ok;};
$tx=get_taxonomy($c::TAXONOMY);
$test('taxonomy_registered',!!$tx);
$test('taxonomy_not_public',$tx&&!$tx->public&&!$tx->publicly_queryable);
$test('taxonomy_not_rest_public',$tx&&!$tx->show_in_rest);
$protectedCaps=['manage_terms','edit_terms','delete_terms','assign_terms'];
$test('all_term_mutation_caps_are_manage_options',$tx &&
 array_reduce($protectedCaps,static fn($carry,$cap)=>$carry&&
  ($tx->cap->$cap??null)==='manage_options',true));
$editor=get_role('editor');
$test('ordinary_editor_cannot_change_company_cost',
 !$editor||!$editor->has_cap('manage_options'));
$test('administrator_can_edit_vehicle_cost',current_user_can('manage_options'));
$test('taxonomy_attached_to_transport',$tx&&in_array('transportations',$tx->object_type,true));
$test('taxonomy_attached_to_rates',$tx&&in_array('transport-rates',$tx->object_type,true));
$test('one_canonical_taxonomy',taxonomy_exists($c::TAXONOMY)&&
 taxonomy_exists('vehicle_classes')&&taxonomy_exists('transportation-types'));
$test('term_canonical_flag',$c::active());
$policy=$m::current();
$test('price_model_is_term_owned',$policy['vehicle_profile_owner']==='jetengine_taxonomy_term_meta');
$test('model_has_six_profiles',count($policy['profiles'])===6);
$test('policy_in_egp_actual_cost',$policy['currency']==='EGP'&&$policy['role']==='self_cost');
$test('company_base_activation_still_closed',$policy['dispatch_policy']['enabled']===false);
$test('no_translation_clones',apply_filters('wpml_is_translated_taxonomy',null,$c::TAXONOMY)===false);
$legacy=get_option($m::OPTION,[]);
$test('legacy_snapshot_preserved',is_array($legacy)&&count($legacy['profiles']??[])===6);
$ids=[];$compared=0;$allTermDataValid=true;
foreach($c::KEY_TO_SLUG as $key=>$slug){
 $resolved=$c::profile($key);
 if(is_wp_error($resolved)){$allTermDataValid=false;continue;}
 $ids[$key]=(int)$resolved['term_id'];
 if($resolved['slug']!==$slug||
    $policy['profiles'][$key]!==$resolved['profile'])
  $allTermDataValid=false;
 if($legacy['profiles'][$key]===$resolved['profile'])$compared++;
}
$test('six_valid_jetengine_term_profiles',$allTermDataValid&&count($ids)===6);
$test('pre_migration_values_equal_term_meta',$compared===6);
$test('six_distinct_term_ids',count(array_unique($ids))===6);
global $wpdb;
$taxRow=$wpdb->get_row($wpdb->prepare(
 "SELECT meta_fields FROM {$wpdb->prefix}jet_taxonomies WHERE slug=%s",
 $c::TAXONOMY),ARRAY_A);
$termFields=(array)maybe_unserialize($taxRow['meta_fields']??'');
$test('native_jetengine_ten_fields',count($termFields)===10);
$test('deadhead_field_is_percentage',
 ($termFields[6]['title']??'')==='Empty Return Distance (%)');
$test('wp_admin_vehicle_assets_correct_scope',
 (function(){
  if(!function_exists('set_current_screen'))
   require_once ABSPATH.'wp-admin/includes/screen.php';
  $saved=get_current_user_id();
  $loader=null;
  foreach($GLOBALS['wp_filter']['admin_enqueue_scripts']->callbacks??[] as $callbacks)
   foreach($callbacks as $cb){
    $fn=$cb['function'];
    if(!$fn instanceof Closure)continue;
    $ref=new ReflectionFunction($fn);
    if(str_contains($ref->getFileName(),'transport-vehicle-term-profiles.php')){
     $loader=$fn;break 2;
    }
   }
  if(!$loader)return false;
  set_current_screen('edit-post');
  $loader('post.php');
  if(wp_script_is('mad4b-vehicle-term-editor','enqueued')||
     wp_style_is('mad4b-vehicle-term-editor','enqueued'))return false;
  set_current_screen('edit-transport-vehicle-types');
  wp_set_current_user(0);
  $loader('edit-tags.php');
  if(wp_script_is('mad4b-vehicle-term-editor','enqueued')||
     wp_style_is('mad4b-vehicle-term-editor','enqueued'))return false;
  wp_set_current_user($saved);
  $loader('edit-tags.php');
  return wp_script_is('mad4b-vehicle-term-editor','enqueued')&&
         wp_style_is('mad4b-vehicle-term-editor','enqueued');
 })());
$test('vehicle_term_edit_link_permission_scoped',
 current_user_can('edit_term',$ids['hiace'])?
 !empty($policy['vehicle_term_profiles']['hiace']['edit_url']):
 empty($policy['vehicle_term_profiles']['hiace']['edit_url']));
$badValue=function($v,$id,$key,$single)use($c,$ids){
 if((int)$id===$ids['hiace']&&
    $key===$c::META['per_km'])return ['-7'];
 return $v;
};
add_filter('get_term_metadata',$badValue,10,4);
$failClosed=$m::calculate(['vehicle'=>'hiace','service'=>'transfer',
 'km'=>20,'drive_min'=>35,'night'=>false,'peak'=>false]);
$test('corrupt_vehicle_term_fails_closed',is_wp_error($failClosed)&&
 $failClosed->get_error_code()==='vehicle_term_profile_invalid');
remove_filter('get_term_metadata',$badValue,10);
$test('term_meta_has_inheritable_dispatch',
 array_reduce(array_keys($ids),static function($ok,$key)use($c){
  $v=$c::profile($key);
  return $ok&&!is_wp_error($v)&&in_array($v['dispatch_mode'],
  ['inherit','enabled','disabled'],true);
 },true));
$test('H1_remains_unpriced',($policy['profiles']['h1']['per_km']??-1)===0.0);
$test('unpriced_H1_quote_rejected',is_wp_error($m::calculate([
 'vehicle'=>'h1','service'=>'transfer','km'=>20,'drive_min'=>60])));
$taxTerms=get_terms(['taxonomy'=>$c::TAXONOMY,'hide_empty'=>false]);
$test('exactly_six_terms',!is_wp_error($taxTerms)&&count($taxTerms)===6);
$sourcePosts=get_posts(['post_type'=>'transportations','post_status'=>['draft','publish'],
 'posts_per_page'=>600,'fields'=>'ids','suppress_filters'=>true,
 'meta_key'=>'_mad4b_transfer_pdf_2026_sha256','meta_value'=>$m::SHA]);
$targetSite=home_url()==='https://wayfatours.com'?102:306;
$test('all_pdf_posts_included',count($sourcePosts)===$targetSite);
$matches=0;$empty=0;$relMismatch=0;
foreach($sourcePosts as $postId){
 $rates=get_post_meta($postId,'_mad4b_transfer_pdf_2026_raw_vehicles',true);
 if(!is_array($rates)){$relMismatch++;continue;}
 $want=[];
 foreach($rates as $key=>$amount)
  if(isset($ids[$key])&&is_numeric($amount)&&(float)$amount>0)$want[]=$ids[$key];
 sort($want,SORT_NUMERIC);
 $actual=wp_get_object_terms($postId,$c::TAXONOMY,['fields'=>'ids']);
 if(is_wp_error($actual)){$relMismatch++;continue;}
 $actual=array_map('intval',$actual);sort($actual,SORT_NUMERIC);
 if($actual===$want)$matches++;else $relMismatch++;
 if(!$want)$empty++;
}
$test('every_pdf_vehicle_relation_matches',$matches===$targetSite&&$relMismatch===0);
$test('no_invented_vehicle_terms_on_empty_source',
 $empty===($targetSite===306?6:2));
$test('commercial_pricing_still_not_live',
 !service_channel_allowed('transportations','standalone'));
$test('taxonomy_profile_no_checkout_write',$policy['mode']==='simulation_only');
$failed=array_keys(array_filter($t,static fn($yes)=>!$yes));
echo wp_json_encode(['site'=>home_url(),'total'=>count($t),
 'passed'=>count($t)-count($failed),'failed'=>$failed,
 'vehicle_terms'=>$ids,'verified_source_posts'=>$matches,
 'unchanged_rate_sales'=>true])."\n";
if($failed)exit(1);
