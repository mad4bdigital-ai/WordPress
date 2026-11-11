<?php
/** Native tour stay commercial-readiness inventory; side-effect-free. */
defined('ABSPATH') || exit;
if(!function_exists('mad4b_tour_stay_commercial_readiness_v1')){
function mad4b_tour_stay_commercial_readiness_v1(int $limit=250){
 if($limit<1||$limit>500)return new WP_Error('tour_stay_readiness_limit_invalid');
 if(!function_exists('BookingExperience'.chr(92).'tour_stay_rate_plan'))
  return new WP_Error('tour_stay_native_plan_unavailable');
 $ids=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','posts_per_page'=>$limit+1,
  'fields'=>'ids','orderby'=>'ID','order'=>'DESC','suppress_filters'=>true]);
 if(count($ids)>$limit)return new WP_Error('tour_stay_readiness_limit_exceeded');
 $summary=['published_tour_rates'=>count($ids),'marked_with_accommodation'=>0,
  'native_plan_error'=>0,'simple_or'=>0,'multi_stage'=>0,
  'no_eligible_properties'=>0,'inquiry_policy'=>0,'included_policy'=>0,'additional_policy'=>0,
  'mixed_policy'=>0,'invalid_legacy_property_reference'=>0,
  'legacy_scalar_property_reference'=>0,'candidate_published_property_rates'=>0];
 $rows=[];
 foreach($ids as $rateId){
  $rateId=(int)$rateId;
  $marked=(string)get_post_meta($rateId,'has_properties',true)==='1'
    ||get_post_meta($rateId,'_booking_experience_stay_stages_v1',true);
  if(!$marked)continue;
  $summary['marked_with_accommodation']++;
  $plan=BookingExperience\tour_stay_rate_plan($rateId);
  $issues=[];$properties=[];$policies=[];$mode='unavailable';
  if(is_wp_error($plan)){
   $summary['native_plan_error']++;
   $issues[]=$plan->get_error_code();
  }else{
   $mode=$plan['mode']??'unknown';
   if(isset($summary[$mode]))$summary[$mode]++;
   if($mode==='simple_or'){
    $properties=array_map('intval',(array)($plan['properties']??[]));
    if($properties)$policies[]=(string)($plan['price_policy']??'inquiry');
   }elseif($mode==='multi_stage'){
    foreach((array)($plan['stages']??[]) as $stage){
     $properties=array_merge($properties,array_map('intval',(array)($stage['properties']??[])));
     if(!empty($stage['properties']))$policies[]=(string)($stage['price_policy']??'inquiry');
    }
   }
  }
  $properties=array_values(array_unique(array_filter($properties,static fn($id)=>$id>0)));
  // Diagnose legacy identity independently from native policy; never
  // reinterpret an attachment as a Property or substitute a guessed ID.
  $legacyRaw=get_post_meta($rateId,'related_properties_id',true);
  if(is_scalar($legacyRaw)&&!is_bool($legacyRaw)&&ctype_digit((string)$legacyRaw)){
   $legacyId=(int)$legacyRaw;
   if(get_post_type($legacyId)==='properties'&&get_post_status($legacyId)==='publish'){
    $summary['legacy_scalar_property_reference']++;
   }else{
    $summary['invalid_legacy_property_reference']++;
    $issues[]='invalid_legacy_property_reference';
   }
  }
  if(!$properties){$summary['no_eligible_properties']++;$issues[]='no_native_eligible_property';}
  $uniquePolicies=array_values(array_unique($policies));
  if(count($uniquePolicies)>1){$summary['mixed_policy']++;$issues[]='mixed_stage_price_policies';}
  foreach(['inquiry','included','additional'] as $policy)
   if(in_array($policy,$uniquePolicies,true))$summary[$policy.'_policy']++;
  if(!$uniquePolicies&&$properties)$issues[]='price_policy_missing';
  if(in_array('inquiry',$uniquePolicies,true))$issues[]='inquiry_requires_confirmation';
  // Published Property Rates are counted only as possible sources; they do not
  // establish source ownership, unit readiness, availability, or prices.
  $available=0;
  if($properties){
   global $wpdb;
   $placeholders=implode(',',array_fill(0,count($properties),'%d'));
   $sql="SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
     INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
     WHERE p.post_type='property-rates' AND p.post_status='publish'
       AND pm.meta_key='prop_related_property_id' AND CAST(pm.meta_value AS UNSIGNED) IN ($placeholders)";
   $available=(int)$wpdb->get_var($wpdb->prepare($sql,...$properties));
   $summary['candidate_published_property_rates']+=$available;
  }
  if($available===0)$issues[]='no_published_property_rate_for_native_plan';
  $rows[]=['tour_rate_id'=>$rateId,'tour_id'=>(int)get_post_meta($rateId,'related_tour_id',true),
   'legacy_property_id'=>(int)get_post_meta($rateId,'related_properties_id',true),
   'native_mode'=>$mode,'native_property_ids'=>$properties,'native_price_policies'=>$uniquePolicies,
   'published_property_rate_candidates'=>$available,
   'status'=>!$issues?'candidate_requires_supplier_and_inventory_review':'blocked_for_commercial_quote',
   'blockers'=>array_values(array_unique($issues)),
   'commercial_rate_authority'=>false,'checkout_authority'=>false];
 }
 return ['contract'=>'mad4b.tour-stay-commercial-readiness.v1','summary'=>$summary,
  'rows'=>$rows,'read_only'=>true,'commercial_rate_authority'=>false,'checkout_authority'=>false];
}
}
