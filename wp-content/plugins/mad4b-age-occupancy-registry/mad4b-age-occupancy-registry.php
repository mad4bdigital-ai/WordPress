<?php
/**
 * Plugin Name: MAD4B Age-Aware Occupancy Registry
 * Description: Brand-neutral, read-only age bands and occupancy policy resolution; legacy booking prices remain authoritative.
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Age_Occupancy_Registry {
 const VERSION='age-occupancy.v1';
 public static function policy($context=[]){
  if(!is_array($context))return new WP_Error('age_context_invalid');
  $levels=['global','product_type','product','rate','unit','supplier'];
  $rules=['age_reference'=>'service_start','bands'=>[],'count_in_capacity'=>true,'age_pricing_enabled'=>false];
  $trace=[];
  foreach($levels as $level){
   $record=apply_filters('mad4b/age_occupancy/policy_level',[],$level,$context);
   if(!is_array($record))return new WP_Error('age_policy_invalid');
   foreach($record as $key=>$value){
    if(!in_array($key,['age_reference','bands','count_in_capacity','age_pricing_enabled'],true))return new WP_Error('age_policy_unknown_setting');
    $rules[$key]=$value;
   }
   if($record)$trace[]=$level;
  }
  if(!in_array($rules['age_reference'],['service_start','checkin'],true)||!is_bool($rules['count_in_capacity'])||!is_bool($rules['age_pricing_enabled'])||!is_array($rules['bands']))return new WP_Error('age_policy_schema_invalid');
  $used=[];
  foreach($rules['bands'] as $band){
   if(!is_array($band)||!isset($band['id'],$band['min_age'],$band['max_age'])||!is_string($band['id'])||!preg_match('/^[a-z][a-z0-9_]*$/D',$band['id']))return new WP_Error('age_band_invalid');
   $min=$band['min_age'];$max=$band['max_age'];
   if(!is_int($min)||!is_int($max)||$min<0||$max>120||$min>$max)return new WP_Error('age_band_range_invalid');
   foreach(range($min,$max) as $age){if(isset($used[$age]))return new WP_Error('age_band_overlap');$used[$age]=true;}
  }
  return ['version'=>self::VERSION,'policy'=>$rules,'overrides'=>$trace,'pricing_authorized'=>false];
 }
 public static function classify($ages,$context=[]){
  if(!is_array($ages))return new WP_Error('age_input_invalid');
  $p=self::policy($context);if(is_wp_error($p))return $p;
  $bands=$p['policy']['bands'];$out=[];
  foreach($ages as $age){
   if(!is_int($age)||$age<0||$age>120)return new WP_Error('age_value_invalid');
   $id=null;foreach($bands as $band){if($age>=$band['min_age']&&$age<=$band['max_age']){$id=$band['id'];break;}}
   if($id===null)return new WP_Error('age_band_missing');
   $out[]=['age'=>$age,'band'=>$id];
  }
  return ['version'=>self::VERSION,'travellers'=>$out,'count'=>count($out),'pricing_authorized'=>false];
 }
}
