<?php
/** Read-only MAD4B media lifecycle regression for connected tenants. */
defined('ABSPATH')||exit;
if(!defined('WP_CLI')||!WP_CLI)exit(2);
foreach(['MAD4B_Universal_Media_Readiness_V1','MAD4B_Tour_Photo_Quality_V1',
    'MAD4B_Universal_Media_Apply_V1','MAD4B_Media_Review_Hierarchy_V1',
    'MAD4B_Media_Visual_Lineage_V1'] as $name)
 if(!class_exists($name,false))WP_CLI::error('Native media module absent: '.$name);
$site=rtrim(home_url(),'/');
if(!in_array($site,['https://wayfatours.com','https://tours.dev.mad4b.com'],true))
 WP_CLI::error('Unreviewed target site');
$policy=MAD4B_Media_Review_Hierarchy_V1::effective();
if(empty($policy['policy_ready']))WP_CLI::error('Company inherited media review unavailable');
$adapter=MAD4B_Universal_Media_Apply_V1::adapters();
$composite=class_exists('MAD4B_Composite_Media_Adapter_V1',false);
if(count($adapter)!==($composite?7:5)||
  isset($adapter['post_registered_composite'])!==$composite)
 WP_CLI::error('Dynamic adapter provider enrollment mismatch');

$profile=MAD4B_Universal_Media_Readiness_V1::object('post','tours-and-activities',60439);
if(is_wp_error($profile)||
   ($profile['quality_policy']['registered_type']??'')!=='post:tours-and-activities'||
   ($profile['commercial_media_rights_review_required']??null)!==true||
   ($profile['rights_verified']??null)!==false||
   ($profile['auto_apply_authorized']??null)!==false)
 WP_CLI::error('Commercial media readiness incorrectly conflates file availability with license rights');

$quality=MAD4B_Tour_Photo_Quality_V1::audit(60439);
if(empty($quality['contract'])||!isset($quality['issue_count']))
 WP_CLI::error('Quality audit unavailable');
$codes=array_count_values(array_column($quality['issues'],'code'));
$fields=['image_vertical','grid_image_1_middle_upper','grid_image_2',
  'grid_image_3_middle_bottom','grid_image_4_'];
$origin=[];
foreach($fields as $key) {
 $url=get_post_meta(60439,$key,true);
 $id=is_string($url)?attachment_url_to_postid($url):0;
 $o=$id?MAD4B_Media_Visual_Lineage_V1::origin($id):[];
 if(empty($o['verified'])||empty($o['origin_sha256']))WP_CLI::error('Unverified media origin: '.$key);
 $origin[$key]=$o['origin_sha256'];
}
if($site==='https://wayfatours.com'){
 if(count(array_unique($origin))!==5||!empty($codes['media_hero_original_duplicate']))
  WP_CLI::error('Wayfatours hero duplicate regressed');
 foreach([60439,60609] as $tour) {
  $config=get_post_meta($tour,'_mad4b_media_focal_points_v1',true);
  if(($config['grid_image_4_']['x']??null)!==50||
      ($config['grid_image_4_']['y']??null)!==100)
   WP_CLI::error('Localized subject-focused crop drift');
 }
}
if($site==='https://tours.dev.mad4b.com'){
 $g=(string)get_post_meta(60439,'gallery',true);
 $parts=array_filter(array_map('trim',explode(',',$g)));
 $host=(string)wp_parse_url($parts[0]??'',PHP_URL_HOST);
 if(count($parts)>=30 && $host!==(string)wp_parse_url(home_url('/'),PHP_URL_HOST)
    &&empty($codes['media_gallery_mass_reuse_requires_reselection']))
    WP_CLI::error('Foreign bulk-source review regression');
}
$filter=static function($x,$kind,$type){
 if($kind==='post'&&$type==='tours-and-activities')$x['commercial']=false;
 return $x;
};
add_filter('mad4b/universal_media/policy',$filter,999,3);
$test=MAD4B_Universal_Media_Readiness_V1::object('post','tours-and-activities',60439);
remove_filter('mad4b/universal_media/policy',$filter,999);
if(is_wp_error($test)||$test['commercial_media_rights_review_required']!==false)
 WP_CLI::error('Dynamic type policy filter not active');
// Simulate a company-supplied per-type option in-memory. This MUST NOT
// write the WordPress option or change the original company policy.
$option_override=static fn($pre)=>['type_overrides'=>[
  'post:tours-and-activities'=>['commercial'=>false,'scan'=>true],
  'term:destinations'=>['required'=>false,'field'=>'featured_image']
]];
add_filter('pre_option_company_media_governance_policy_v1',$option_override,999,1);
$companyProfile=MAD4B_Universal_Media_Readiness_V1::object('post','tours-and-activities',60439);
remove_filter('pre_option_company_media_governance_policy_v1',$option_override,999);
if(is_wp_error($companyProfile)||
   ($companyProfile['quality_policy']['commercial']??null)!==false ||
   ($companyProfile['commercial_media_rights_review_required']??null)!==false ||
   ($companyProfile['rights_verified']??null)!==false ||
   ($companyProfile['auto_apply_authorized']??null)!==false)
 WP_CLI::error('Company dynamic per-type policy is not scoped or incorrectly claims rights');
$afterOverride=MAD4B_Universal_Media_Readiness_V1::object('post','tours-and-activities',60439);
if(is_wp_error($afterOverride)||$afterOverride['commercial_media_rights_review_required']!==true)
 WP_CLI::error('Transient policy simulation leaked into live behavior');
$tax=get_taxonomies(['public'=>true],'names');
if(!in_array('destinations',$tax,true))WP_CLI::error('Expected native destination taxonomy missing');
global $wpdb;
$term=(int)$wpdb->get_var("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy='destinations' ORDER BY term_id LIMIT 1");
$termReady=MAD4B_Universal_Media_Readiness_V1::object('term','destinations',$term);
if(is_wp_error($termReady)||($termReady['quality_policy']['registered_type']??'')!=='term:destinations')
 WP_CLI::error('Registered taxonomy not covered');
WP_CLI::success(wp_json_encode([
 'site'=>$site,'native_policy_mode'=>$policy['mode'],'dynamic_public_types'=>count(get_post_types(['public'=>true],'names')),
 'dynamic_public_taxonomies'=>count($tax),'media_adapters'=>count($adapter),
 'original_hero_origins'=>count(array_unique($origin)),'issue_codes'=>$codes,
 'source_data_mutated'=>false,'rights_certified'=>false,
 'company_option_override_isolated'=>true,'test'=>'PASS'],JSON_UNESCAPED_SLASHES));
