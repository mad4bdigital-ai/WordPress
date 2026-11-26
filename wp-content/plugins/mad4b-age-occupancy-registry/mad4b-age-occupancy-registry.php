<?php
/**
 * Plugin Name: Age-Aware Occupancy Registry
 * Description: Brand-neutral, read-only age bands and occupancy policy resolution; legacy booking prices remain authoritative.
 * Version: 0.2.0
 * Requires PHP: 8.1
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Age_Occupancy_Registry {
 const VERSION='age-occupancy.v1';
 /**
  * Ages are decimal years with at most two fractional digits (0..120).
  * Counts of travellers remain integers. Strict parsing avoids rounding,
  * scientific notation and truncation of age evidence from Fluent Forms.
  */
 public static function normalize_age($age){
  if(!is_scalar($age)||is_bool($age))return new WP_Error('age_value_invalid');
  $str=(string)$age;
  if(!preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\\.[0-9]{1,2})?$/D',$str))return new WP_Error('age_value_invalid');
  $number=(float)$str;
  if(!is_finite($number)||$number<0||$number>120)return new WP_Error('age_value_invalid');
  return (floor($number)===$number)?(int)$number:$number;
 }
 public static function age_cents($age){
  $parsed=self::normalize_age($age);
  return is_wp_error($parsed)?$parsed:(int)round((float)$parsed*100);
 }
 public static function policy($context=[]){
  if(!is_array($context))return new WP_Error('age_context_invalid');
  $levels=['global','product_type','supplier','product','unit','rate'];
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
   $lo=self::age_cents($min);$hi=self::age_cents($max);
   if(is_wp_error($lo)||is_wp_error($hi)||$lo>$hi)return new WP_Error('age_band_range_invalid');
   foreach($used as [$otherMin,$otherMax])if($lo<=$otherMax&&$otherMin<=$hi)
    return new WP_Error('age_band_overlap');
   $used[]=[$lo,$hi];
  }
  return ['version'=>self::VERSION,'policy'=>$rules,'overrides'=>$trace,'pricing_authorized'=>false];
 }
 public static function classify($ages,$context=[]){
  if(!is_array($ages))return new WP_Error('age_input_invalid');
  $p=self::policy($context);if(is_wp_error($p))return $p;
  $bands=$p['policy']['bands'];$out=[];
  foreach($ages as $age){
   $age=self::normalize_age($age);if(is_wp_error($age))return $age;
   $id=null;foreach($bands as $band){if($age>=$band['min_age']&&$age<=$band['max_age']){$id=$band['id'];break;}}
   if($id===null)return new WP_Error('age_band_missing');
   $out[]=['age'=>$age,'band'=>$id];
  }
  return ['version'=>self::VERSION,'travellers'=>$out,'count'=>count($out),'pricing_authorized'=>false];
 }

 public static function booking_evidence($raw,array $context,int $persons){
  if(!is_array($raw))return new WP_Error('age_context_invalid');
  $allowed=['version','children','infants','stay_children','child_ages','infant_ages','age_reference','pricing_authorized','source','context_mode','classification_status'];
  if(array_diff(array_keys($raw),$allowed))return new WP_Error('age_context_unexpected_keys');
  // Form receipt metadata is informational. Recompute policy and classification from the trusted booking context.
  $counts=[];
  foreach(['children','infants'] as $key){
   $v=$raw[$key]??null;
   if(!is_scalar($v)||is_bool($v)||!preg_match('/^(0|[1-9][0-9]?)$/D',(string)$v)||(int)$v>50)return new WP_Error('age_context_count_invalid');
   $counts[$key]=(int)$v;
  }
  $n=$counts['children']+$counts['infants'];
  $limit=class_exists('MAD4B_Age_Fluent_Forms')?MAD4B_Age_Fluent_Forms::limit():50;
  if($n>$persons||$n>$limit)return new WP_Error('age_context_person_count_invalid');
  if(isset($raw['stay_children'])&&(int)$raw['stay_children']!==$n)return new WP_Error('age_context_total_invalid');
  if(!empty($raw['pricing_authorized']))return new WP_Error('age_context_pricing_not_authorized');
  $ages=MAD4B_Age_Fluent_Forms::clean_ages($raw['child_ages']??null,$n);
  $infants=MAD4B_Age_Fluent_Forms::clean_ages($raw['infant_ages']??null,$counts['infants']);
  if(is_wp_error($ages))return $ages;
  if(is_wp_error($infants))return $infants;
  if($counts['infants']&&array_slice($ages,-$counts['infants'])!==$infants)return new WP_Error('age_context_infant_mismatch');
  $policy=self::policy($context);if(is_wp_error($policy))return $policy;
  if($n && ($context['domain']??'tour')==='tour'){
   $bucket=MAD4B_Age_Fluent_Policy::validate($context,array_slice($ages,0,$counts['children']),$infants);
   if(is_wp_error($bucket))return $bucket;
  }
  // Apply the same classification rules used by Accommodation when bands exist.
  // If no age policy bands exist, preserve an indicative, non-commercial receipt.
  if($n && !empty($policy['policy']['bands'])){
   $classified=self::classify($ages,$context);
   if(is_wp_error($classified))return $classified;
  }
  $hash=hash('sha256',wp_json_encode(['policy'=>$policy['policy'],'overrides'=>$policy['overrides']]));
  $e=['version'=>'age-request.v1','children'=>$counts['children'],'infants'=>$counts['infants'],
    'stay_children'=>$n,'child_ages'=>$ages,'infant_ages'=>$infants,
    'domain'=>$context['domain']??'tour','product_id'=>absint($context['product_id']??0),
    'rate_id'=>absint($context['rate_id']??0),
    'age_reference'=>$policy['policy']['age_reference'],'policy_version'=>$policy['version'],
    'policy_sha256'=>$hash,'sources'=>$policy['overrides'],'pricing_authorized'=>false,
    'age_pricing_status'=>$n?'not_certified':'not_required'];
  $e['sha256']=hash('sha256',wp_json_encode($e));
  return $e;
 }
 public static function verify_booking_evidence($receipt,array $context,int $persons): bool{
  if(!is_array($receipt)||!is_string($receipt['sha256']??null))return false;
  $input=array_intersect_key($receipt,array_flip(['children','infants','stay_children','child_ages','infant_ages']));
  $fresh=self::booking_evidence($input,$context,$persons);
  return is_array($fresh)&&hash_equals($fresh['sha256'],$receipt['sha256'])&&$fresh===$receipt;
 }

}

require_once __DIR__ . '/includes/age-aware-stay.php';
require_once __DIR__ . '/includes/fluent-age-policy.php';
if (is_admin()) require_once __DIR__ . '/includes/admin.php';
if (class_exists('MAD4B_Age_Occupancy_Registry')) require_once __DIR__ . '/includes/fluent-forms.php';
