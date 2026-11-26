<?php
defined('ABSPATH')||exit;
$site=parse_url(home_url('/'),PHP_URL_HOST);
$expected=$site==='tours.dev.mad4b.com'?
 [71495=>71495,71501=>71501]:
 ($site==='wayfatours.com'?[71495=>71980,71501=>71981]:[]);
if(!$expected)WP_CLI::error('unexpected site');
$checks=[];$fail=[];
$validate=static function(string $label,bool $ok)use(&$checks,&$fail){
 $checks[$label]=$ok;if(!$ok)$fail[]=$label;
};
$orig=apply_filters('wpml_current_language',null);
$langs=(array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
foreach(array_keys($langs) as $lang){
 do_action('wpml_switch_language',$lang);
 $r=MAD4B_Package_Component_Readiness_V1::report('2026-12-05','2026-12-19');
 $validate("$lang:report",is_array($r)&&($r['totals']['price_ready_tier_reviews']??-1)===0);
 $target=null;
 foreach((array)($r['tours']??[]) as $tour)if((int)$tour['tour_id']===60437)$target=$tour;
 $validate("$lang:target_tour_found",is_array($target));
 foreach(['Gold','Diamond'] as $tierName){
  $tier=$target['levels'][$tierName]??null;
  $stage=null;
  foreach((array)($tier['stages']??[]) as $s)if(($s['key']??'')==='hotel-aswan-02')$stage=$s;
  $drafts=(array)($stage['aswan_editorial_draft_review']??[]);
  $validate("$lang:$tierName:stage_found",is_array($stage));
  $validate("$lang:$tierName:strict_no_eligibles",
   is_array($stage)&&($stage['eligible_properties']??-1)===0);
  $validate("$lang:$tierName:two_drafts_reported",count($drafts)===2);
  foreach($expected as $sourceId=>$id){
   $matches=array_values(array_filter($drafts,
       static fn($d)=>(int)$d['canonical_source_id']===(int)$sourceId));
   $d=$matches[0]??null;
   $validate("$lang:$tierName:$sourceId:mapped_independent_wp_id",
      is_array($d)&&(int)$d['id']===(int)$id);
   $validate("$lang:$tierName:$sourceId:draft_no_commercial",
      is_array($d)&&$d['post_status']==='draft'&&
       $d['qualified']===false&&$d['assigned']===false&&
       $d['checkout_authorized']===false&&
       $d['cost_approved']===false&&$d['unit_authorized']===false);
   $validate("$lang:$tierName:$sourceId:blocked_contract_stock",
      is_array($d)&&in_array('supplier_cost_receipt_missing',$d['reasons'],true)&&
      in_array('unit_stock_and_occupancy_missing',$d['reasons'],true));
   $validate("$lang:$tierName:$sourceId:publisher_image_blockers",
      is_array($d)&&in_array('no_cleared_featured_image',$d['reasons'],true)&&
      in_array('commercial_tier_not_approved',$d['reasons'],true));
   $validate("$lang:$tierName:$sourceId:original_source_link",
      is_array($d)&&str_starts_with($d['official_source_url'],'https://')&&
      filter_var($d['edit_url'],FILTER_VALIDATE_URL)!==false);
   if($sourceId===71501)$validate("$lang:$tierName:$sourceId:island_resort_mismatch",
      is_array($d)&&in_array('island_transfer_not_verified',$d['reasons'],true)&&
      in_array('stage_is_hotel_and_property_is_resort',$d['reasons'],true));
   else $validate("$lang:$tierName:$sourceId:mainland_hotel_no_resort_flag",
      is_array($d)&&!in_array('island_transfer_not_verified',$d['reasons'],true)&&
      !in_array('stage_is_hotel_and_property_is_resort',$d['reasons'],true));
  }
 }
 $validate("$lang:admin_locale_restored",apply_filters('wpml_current_language',null)===$lang);
}
if(is_string($orig)&&$orig!=='')do_action('wpml_switch_language',$orig);
foreach($expected as $source=>$id){
 $post=get_post($id);
 $src=get_post_meta($id,'_mad4b_property_official_site_source_v1',true);
 $validate("native:$id:draft",get_post_type($id)==='properties'&&
   get_post_status($id)==='draft'&&!get_post_thumbnail_id($id));
 $validate("native:$id:source_unapproved",is_array($src)&&
   ($src['commercial_tier_approved']??null)===false);
 if($site==='wayfatours.com')$validate("native:$id:cross_company_canonical_relation",
    get_post_meta($id,'_mad4b_company_source_aswan_property_id_v1',true)===(string)$source);
}
$before=get_current_user_id();
wp_set_current_user(0);
$forbidden=MAD4B_Package_Component_Readiness_V1::report('2026-12-05','2026-12-19');
wp_set_current_user($before);
$validate("access_admin_only",is_wp_error($forbidden));
echo wp_json_encode(['contract'=>'mad4b.aswan-editorial-review-regression.v1',
 'site'=>home_url(),'locales'=>array_keys($langs),'passed'=>count($checks)-count($fail),
 'total'=>count($checks),'failures'=>$fail,
 'post_published'=>false,'tour_rate_activated'=>false,
 'cost_approved'=>false,'checkout_authorized'=>false],
 JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
if($fail&&class_exists('WP_CLI'))WP_CLI::halt(1);

