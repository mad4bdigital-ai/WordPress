<?php
/** Shared Product Core: nonfinancial occupancy price-generation policy v1. */
defined('ABSPATH') || exit;

/** These percentages describe selling-price variation per PERSON, not profit margin. */
function shared_product_occupancy_policy_validate($raw) {
 if(!is_array($raw)||array_diff(array_keys($raw),['reference','single_pct','double_pct','triple_pct']))
  return new WP_Error('occupancy_policy_invalid_fields');
 $reference=$raw['reference']??'double';
 if(!in_array($reference,['single','double','triple'],true))
  return new WP_Error('occupancy_policy_invalid_reference');
 $result=['reference'=>$reference];
 foreach(['single','double','triple'] as $tier){
  $value=$raw[$tier.'_pct']??0;
  if(!is_scalar($value)||is_bool($value)||!is_numeric($value)||!is_finite((float)$value)
     ||(float)$value< -80||(float)$value>300)
    return new WP_Error('occupancy_policy_invalid_adjustment');
  $result[$tier.'_pct']=number_format((float)$value,2,'.','');
 }
 if((float)$result[$reference.'_pct']!==0.0)
   return new WP_Error('occupancy_policy_reference_must_be_zero');
 return $result;
}
function shared_product_occupancy_policy_resolve(int $tourId): array {
 $none=['status'=>'unconfigured','source'=>'existing_generator','policy'=>null];
 if($tourId<1||get_post_type($tourId)!=='tours-and-activities')return $none;
 $candidates=[
  'tour'=>get_post_meta($tourId,'_shared_product_occupancy_policy_v1',true)
 ];
 // Missing specific override inherits the Tour-type group and then global.
 $mode=(string)get_post_meta($tourId,'tour_mode',true);
 $byType=get_option('shared_product_occupancy_policy_by_type_v1',[]);
 if(is_array($byType)&&$mode!=='')$candidates['tour_type']=$byType[$mode]??null;
 $candidates['global']=get_option('shared_product_occupancy_policy_global_v1',[]);
 foreach($candidates as $source=>$policy){
  if(!is_array($policy)||$policy===[])continue;
  $valid=shared_product_occupancy_policy_validate($policy);
  // An explicitly configured but invalid policy must not be silently skipped.
  if(is_wp_error($valid))return ['status'=>'invalid','source'=>$source,'policy'=>null,
    'reason'=>$valid->get_error_code()];
  return ['status'=>'configured','source'=>$source,'policy'=>$valid];
 }
 return $none;
}
function shared_product_occupancy_policy_factors(array $policy): array {
 $validated=shared_product_occupancy_policy_validate($policy);
 if(is_wp_error($validated))return [];
 $factors=[];
 foreach(['single','double','triple'] as $tier)
  $factors[$tier]=number_format(1+(float)$validated[$tier.'_pct']/100,4,'.','');
 return $factors;
}
/**
 * Legacy callers unchanged unless opting into occupancy_factor_mode=inherit.
 * 'override' respects explicitly passed multipliers, never modifying policy.
 */
function shared_product_occupancy_generation_factors(int $tourId, array $legacyFactors,string $mode='legacy') {
 if(!in_array($mode,['legacy','inherit','override'],true))
  return new WP_Error('occupancy_generation_mode_invalid');
 if($mode!=='inherit')return ['source'=>$mode,'factors'=>$legacyFactors,'policy'=>null];
 $resolved=shared_product_occupancy_policy_resolve($tourId);
 if($resolved['status']==='invalid')return new WP_Error('occupancy_generation_policy_invalid');
 if($resolved['status']!=='configured')return ['source'=>'existing_generator','factors'=>$legacyFactors,'policy'=>null];
 return ['source'=>$resolved['source'],'factors'=>shared_product_occupancy_policy_factors($resolved['policy']),
   'policy'=>$resolved['policy']];
}
