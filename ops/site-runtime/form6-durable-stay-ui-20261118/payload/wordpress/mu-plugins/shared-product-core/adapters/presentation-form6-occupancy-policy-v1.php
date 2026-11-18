<?php
/**
 * REV91 — presentation-only accommodation eligibility.
 * Eligibility for room controls must be sourced, never inferred from rate
 * availability, labels, generic package levels or the length of a trip.
 */
defined('ABSPATH') || exit;
/**
 * Native Rate identity: exactly the published Tour's Rate or a vetted
 * default-language WPML fallback already approved by the original Rate
 * language bridge. Never infer a Rate merely from matching a translation ID.
 */
if(!function_exists('shared_form6_verified_rate_parent_v1')){
 function shared_form6_verified_rate_parent_v1(int $tour,int $rate):int {
  if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish'||
     get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish')return 0;
  $parent=absint(get_post_meta($rate,'related_tour_id',true));
  if($parent===$tour)return $parent;
  if(!class_exists('MAD4B_Tour_Rate_Language_Policy_V1',false) ||
     !class_exists('ETG_Tour_Rate_Display',false))return 0;
  $context=MAD4B_Tour_Rate_Language_Policy_V1::resolve_context($tour);
  if(($context['resolved_source']??'')!=='default_language' ||
     $parent!==absint($context['query_tour_id']??0))return 0;
  if(!in_array($parent,array_map('intval',ETG_Tour_Rate_Display::language_ids($tour)),true)||
     !MAD4B_Tour_Rate_Language_Policy_V1::eligible_rate($tour,$rate))return 0;
  return $parent;
 }
}
/**
 * Permanent, source-owned presentation contract. A Rate owns its sole linked
 * property; it does not imply a room selector or an independently priced stay.
 * The client may open room/board controls only after the authoritative stay
 * catalog confirms eligible unit options. This never changes chargeability.
 */
if(!function_exists('shared_form6_stay_ui_contract_v1')){
 function shared_form6_stay_ui_contract_v1(string $mode,array $stage):array {
  $ids=array_values(array_unique(array_filter(array_map('absint',
   (array)($stage['properties']??[])))));
  $sole=count($ids)===1;
  $policy=(string)($stage['price_policy']??'inquiry');
  $nights=max(0,(int)($stage['nights']??0));
  $reviewOnlySole=$mode==='simple_or' && $sole &&
   $policy==='inquiry' && $nights===0;
  return [
   'contract'=>'shared.form6.stay-ui.v1',
   'property_selector'=>$sole?'rate_owned':(count($ids)>1?'traveler':'none'),
   'detail_controls'=>$reviewOnlySole?'none':'verified_catalog_only',
   'reason'=>$reviewOnlySole?'sole_rate_property_review_only':'catalog_verification_required',
  ];
 }
}
if(!function_exists('shared_form6_accommodation_policy_v1')){
 function shared_form6_accommodation_policy_v1(int $tour): array {
  $types=wp_get_post_terms($tour,'tour-types',['fields'=>'slugs']);
  $types=is_wp_error($types)?[]:(array)$types;
  $packageContext=(bool)array_intersect(['packages','combined-tours'],$types);
  $result=['tour_id'=>$tour,'is_package_or_combined'=>$packageContext,
   'default_room_occupancy'=>false,'rates_with_stay'=>[],'plans_by_rate'=>[],
   'source'=>'none','scope'=>'presentation_only'];
  if(get_post_type($tour)!=='tours-and-activities'||
     get_post_status($tour)!=='publish')return $result;
  $siblings=class_exists('ETG_Tour_Rate_Display',false)
    ?ETG_Tour_Rate_Display::language_ids($tour):[$tour];
  $siblings=array_values(array_unique(array_filter(array_map('absint',(array)$siblings))));
  if(!$siblings)return $result;
  global $wpdb;
  $linked=$wpdb->get_col($wpdb->prepare(
   "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
    WHERE meta_key='related_tour_id' AND meta_value IN (".
    implode(',',array_fill(0,count($siblings),'%s')).")",
   ...array_map('strval',$siblings)));
  $planRows=[];
  foreach(array_unique(array_map('absint',$linked)) as $rate){
   if(!$rate||!shared_form6_verified_rate_parent_v1($tour,$rate))continue;
   if(!function_exists('BookingExperience\\tour_stay_rate_plan'))continue;
   $plan=BookingExperience\tour_stay_rate_plan($rate);
   if(is_wp_error($plan)||!is_array($plan))continue;
   $mode=(string)($plan['mode']??'');
   $stages=[];
   if($mode==='simple_or'){
      $properties=array_values(array_unique(array_filter(array_map('absint',
       (array)($plan['properties']??[])),static fn($id)=>
         get_post_type($id)==='properties'&&get_post_status($id)==='publish')));
      if(!$properties||count($properties)>80)continue;
      $policy=(string)($plan['price_policy']??'inquiry');
      if(!in_array($policy,['included','additional','inquiry'],true))$policy='inquiry';
      $stages[]=['key'=>'simple','label'=>'Hotel alternatives','properties'=>$properties,
        'price_policy'=>$policy,'nights'=>0,'day_offset'=>0];
   }elseif($mode==='multi_stage'){
      foreach((array)($plan['stages']??[]) as $stage){
       if(!is_array($stage)||!preg_match('/^[a-z0-9_-]{2,45}$/D',(string)($stage['key']??'')))continue;
       $properties=array_values(array_unique(array_filter(array_map('absint',
        (array)($stage['properties']??[])),static fn($id)=>
          get_post_type($id)==='properties'&&get_post_status($id)==='publish')));
       if(!$properties)continue;
       $stages[]=['key'=>(string)$stage['key'],
        'label'=>(string)($stage['label']??$stage['key']),
        'properties'=>$properties,
        'price_policy'=>in_array(($stage['price_policy']??''),['included','additional','inquiry'],true)
          ?$stage['price_policy']:'inquiry',
        'nights'=>(int)($stage['nights']??0),
        'day_offset'=>(int)($stage['day_offset']??0)];
      }
   }
   if(!$stages)continue;
   foreach($stages as &$stage){
    $stage['ui_contract']=shared_form6_stay_ui_contract_v1($mode,$stage);
   }
   unset($stage);
   $result['rates_with_stay'][(string)$rate]=true;
   $planRows[(string)$rate]=['mode'=>$mode,'stages'=>$stages,
     'source'=>'BookingExperience.tour_stay_rate_plan',
     'price_scope'=>'source_only_not_supplier_certified'];
  }
  $result['plans_by_rate']=$planRows;
  $result['source']=count($result['rates_with_stay'])?'native_bookingexperience_rate_stays':'none';
  return apply_filters('shared_form6_accommodation_policy_v1',$result,$tour);
 }
}
