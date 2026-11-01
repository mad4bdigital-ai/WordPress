<?php
/** Age-aware stay occupancy and per-person pricing. Only server-owned policies can alter amounts. */
if (!defined('ABSPATH')) exit;
final class MAD4B_Age_Aware_Stay {
 const OPTION='mad4b_age_occupancy_policy_global_v1';
 const META='_mad4b_age_occupancy_policy_v1';
 public static function source($current,$level,$context){
  if(!is_array($context))return $current;
  if($level==='global')$candidate=get_option(self::OPTION,[]);
  elseif($level==='product_type'){
   $all=get_option('mad4b_age_occupancy_policy_product_types_v1',[]);
   $candidate=is_array($all)?($all[$context['domain']??'']??[]):[];
  }else{
   $key=match($level){'supplier'=>'supplier_id','product'=>'product_id','unit'=>'unit_id','rate'=>'rate_id',default=>null};
   $id=$key?absint($context[$key]??0):0;
   $candidate=$id?get_post_meta($id,self::META,true):[];
  }
  if(!$candidate)return $current;
  if(!is_array($candidate))return $candidate; // Fail closed in the original policy schema validation.
  return array_merge($current,$candidate);
 }
 public static function resolve(array $context){
  if(!class_exists('MAD4B_Age_Occupancy_Registry'))return new WP_Error('age_registry_not_loaded');
  return MAD4B_Age_Occupancy_Registry::policy($context);
 }
 /** Pricing rules affect per-person units only; room/stay units retain original amounts. */
 public static function multiplier($basis,$nights,$rooms,$adults,$children,$receipt){
  if(!function_exists('abk_price_multiplier'))return new WP_Error('stay_price_engine_missing');
  $standard=abk_price_multiplier($basis,$nights,$rooms,$adults,$children);
  if(is_wp_error($standard)||!is_array($receipt)||empty($receipt['pricing_enabled']))return $standard;
  if(!in_array($basis,['per_person','per_person_night'],true))return $standard;
  $units=$receipt['child_price_units']??null;
  if(!is_numeric($units)||!is_finite((float)$units)||(float)$units<0||(float)$units>$children*2)return new WP_Error('stay_age_units_invalid');
  return ($adults+(float)$units)*($basis==='per_person_night'?$nights:1);
 }
 public static function preview($input,$property,$unit,$rate,$adults,$children,$rooms){
  $policy=self::resolve(['domain'=>'accommodation','product_id'=>$property,'unit_id'=>$unit,'rate_id'=>$rate]);
  if(is_wp_error($policy))return $policy;
  $bands=$policy['policy']['bands'];
  $input_ages=array_key_exists('child_ages',$input);
  $alloc=$input['room_occupancy']??null;
  $has_room_ages=false;
  if(is_array($alloc))foreach($alloc as $row)if(is_array($row)&&array_key_exists('child_ages',$row))$has_room_ages=true;
  if(!$bands){
   if($input_ages||$has_room_ages)return new WP_Error('stay_age_policy_unconfigured');
   return null; // Original count-based occupancy and pricing remain untouched.
  }
  if(!$children){
   if($input_ages&&$input['child_ages']!==[])return new WP_Error('stay_age_count_mismatch');
   if($has_room_ages)foreach($alloc as $row)if(!empty($row['child_ages']))return new WP_Error('stay_age_count_mismatch');
   return ['policy'=>$policy,'child_price_units'=>0.0,'capacity_children'=>array_fill(0,$rooms,0),'age_bands'=>[],'applied'=>true];
  }
  if($rooms>1 && !$has_room_ages)return new WP_Error('stay_room_ages_required');
  if($rooms===1){
   $ages=$input_ages?$input['child_ages']:($alloc[0]['child_ages']??null);
   $lists=[$ages];
  }else{
   $lists=[];
   foreach($alloc as $row)$lists[]=is_array($row)?($row['child_ages']??null):null;
  }
  if(!is_array($lists)||count($lists)!==$rooms)return new WP_Error('stay_age_assignment_invalid');
  $flat=[];$counts=[];$units=0.0;$receipt=[];
  foreach($lists as $room=>$ages){
   if(!is_array($ages)||!array_is_list($ages)||count($ages)>50)return new WP_Error('stay_age_assignment_invalid');
   if($alloc!==null){
    if(!isset($alloc[$room]['children'])||!is_int($alloc[$room]['children'])||count($ages)!==$alloc[$room]['children'])return new WP_Error('stay_age_assignment_mismatch');
   }
   $capacity=0;
   foreach($ages as $age){
    if(!is_int($age)||$age<0||$age>120)return new WP_Error('stay_age_value_invalid');
    $band=null;foreach($bands as $candidate)if($age>=$candidate['min_age']&&$age<=$candidate['max_age']){$band=$candidate;break;}
    if(!$band)return new WP_Error('stay_age_band_missing');
    $factor=$band['price_factor']??1;
    if(!is_numeric($factor)||!is_finite((float)$factor)||(float)$factor<0||(float)$factor>2)return new WP_Error('stay_age_price_factor_invalid');
    $counts_capacity=$band['count_in_capacity']??$policy['policy']['count_in_capacity'];
    if(!is_bool($counts_capacity))return new WP_Error('stay_age_capacity_rule_invalid');
    if($counts_capacity)$capacity++;
    $units+=(float)$factor;
    $flat[]=$age;
    $receipt[]=['room'=>$room,'band'=>$band['id'],'age'=>$age,'price_factor'=>(float)$factor,'count_in_capacity'=>$counts_capacity];
   }
   $counts[]=$capacity;
  }
  if(count($flat)!==$children)return new WP_Error('stay_age_count_mismatch');
  if($input_ages && $rooms>1){
   if(!is_array($input['child_ages'])||!array_is_list($input['child_ages']))return new WP_Error('stay_age_assignment_invalid');
   $global=$input['child_ages'];sort($global);sort($flat);
   if($global!==$flat)return new WP_Error('stay_age_assignment_mismatch');
  }
  if($input_ages&&$rooms===1&&(!is_array($input['child_ages'])||count($input['child_ages'])!==$children))return new WP_Error('stay_age_count_mismatch');
  $fingerprint=hash('sha256',wp_json_encode(['policy'=>$policy['policy'],'overrides'=>$policy['overrides']]));
  return ['policy_version'=>$policy['version'],'policy_sha256'=>$fingerprint,'sources'=>$policy['overrides'],'age_reference'=>$policy['policy']['age_reference'],'pricing_enabled'=>$policy['policy']['age_pricing_enabled'],'child_price_units'=>$units,'capacity_children'=>$counts,'age_bands'=>$receipt,'applied'=>true];
 }
}
add_filter('mad4b/age_occupancy/policy_level',['MAD4B_Age_Aware_Stay','source'],10,3);
