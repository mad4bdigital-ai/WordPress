<?php
/** Company-owned data intake and contracted-provider intake: validation/planning only. */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Property_Source_Intake_V1')){
final class MAD4B_Property_Source_Intake_V1 {
 const SCHEMA='mad4b.property-source-intake.plan.v1';
 private static function error($code){return new WP_Error('property_source_intake_'.$code);}
 private static function text($s,$max=180){return is_string($s)&&trim($s)!==''&&mb_strlen($s)<=$max;}
 public static function plan(array $input) {
  $keys=['mode','platform','company_property_key','source_url','proof_type',
    'proof_reference','title','address','units','rate_observations','connection_id'];
  if(array_diff(array_keys($input),$keys))return self::error('unknown_fields');
  $mode=$input['mode']??'';
  if(!in_array($mode,['contracted_provider','owner_submitted'],true))
    return self::error('mode_invalid');
  $platform=$input['platform']??'';
  if(!in_array($platform,['airbnb','booking_com','direct_owner','channel_manager'],true))
    return self::error('platform_invalid');
  if(!self::text($input['company_property_key']??'',100)
     ||!preg_match('/^[A-Za-z0-9._:-]{1,100}$/D',$input['company_property_key']))
    return self::error('property_key_invalid');
  if(!self::text($input['title']??'',180))return self::error('title_required');
  if(!self::text($input['address']??'',300))return self::error('address_required');
  $proofType=$input['proof_type']??'';
  if(!in_array($proofType,['company_declaration','ownership_document','contract_reference','partner_connection'],true))
    return self::error('proof_type_invalid');
  if(!self::text($input['proof_reference']??'',300))
    return self::error('proof_reference_required');
  if($mode==='contracted_provider' &&
     (!in_array($proofType,['contract_reference','partner_connection'],true)
      ||!self::text($input['connection_id']??'',120)))
    return self::error('connection_reference_required');
  $url=$input['source_url']??null;
  if($url!==null && (!is_string($url)||strlen($url)>1500||!filter_var($url,FILTER_VALIDATE_URL)
    ||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_USER)
    ||parse_url($url,PHP_URL_PASS)||parse_url($url,PHP_URL_FRAGMENT)))
    return self::error('source_url_invalid');
  if($url!==null && in_array($platform,['airbnb','booking_com'],true)) {
    if(!class_exists('MAD4B_OTA_Provider_Source_Profiles_V1'))return self::error('ota_registry_missing');
    $host=strtolower((string)parse_url($url,PHP_URL_HOST));
    if(!in_array($host,MAD4B_OTA_Provider_Source_Profiles_V1::definitions()[$platform]['hosts'],true))
      return self::error('source_host_mismatch');
  }
  $units=$input['units']??[];
  if(!is_array($units)||!array_is_list($units)||count($units)>50)return self::error('units_invalid');
  $prepared=[];$seen=[];
  foreach($units as $unit) {
   if(!is_array($unit)||array_diff(array_keys($unit),['key','name','kind','capacity','amenities']))
     return self::error('unit_fields_invalid');
   $key=$unit['key']??null;$kind=$unit['kind']??null;
   if(!self::text($key,90)||isset($seen[$key])||!self::text($unit['name']??'',180))
     return self::error('unit_identity_invalid');
   $allowed=['room','cabin','bed','seat','table','hall','space','ticket','slot','other'];
   if(!in_array($kind,$allowed,true))return self::error('unit_kind_invalid');
   $capacity=filter_var($unit['capacity']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]]);
   if(!$capacity)return self::error('unit_capacity_invalid');
   $amenities=$unit['amenities']??[];
   if(!is_array($amenities)||!array_is_list($amenities)||count($amenities)>80)return self::error('amenities_invalid');
   foreach($amenities as $a)if(!self::text($a,120))return self::error('amenity_invalid');
   $prepared[]=['key'=>$key,'name'=>$unit['name'],'kind'=>$kind,
     'capacity'=>$capacity,'amenities'=>array_values(array_unique($amenities))];
   $seen[$key]=true;
  }
  $observations=$input['rate_observations']??[];
  if(!is_array($observations)||!array_is_list($observations)||count($observations)>100)
    return self::error('rates_invalid');
  foreach($observations as $rate) {
   if(!is_array($rate)||array_diff(array_keys($rate),
      ['unit_key','date_from','date_to','amount','currency','basis','occupancy','fees_included']))
     return self::error('rate_fields_invalid');
   $key=$rate['unit_key']??null;
   if($key!==null&&!isset($seen[$key]))return self::error('rate_unit_unknown');
   if(!is_numeric($rate['amount']??null)||!is_finite((float)$rate['amount'])||(float)$rate['amount']<0)
     return self::error('rate_amount_invalid');
   $currency=$rate['currency']??'';
   if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))return self::error('currency_invalid');
   foreach(['date_from','date_to'] as $field) {
     $d=$rate[$field]??null;
     if(!is_string($d)||!preg_match('/^20\d\d-\d\d-\d\d$/D',$d)
        ||!($parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$d,new DateTimeZone('UTC')))
        ||$parsed->format('Y-m-d')!==$d)return self::error('date_invalid');
   }
   if($rate['date_from']>$rate['date_to'])return self::error('date_order_invalid');
   if(isset($rate['occupancy'])) {
     $occupancy=filter_var($rate['occupancy'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
     if(!$occupancy)return self::error('rate_occupancy_invalid');
   }
   if(isset($rate['fees_included'])&&!is_bool($rate['fees_included']))return self::error('fees_flag_invalid');
   if(!is_string($rate['basis']??null)||!in_array($rate['basis'],[
    'per_room','per_room_night','per_cabin','per_cabin_night','per_seat','per_table',
    'per_ticket','per_slot','per_unit','per_day','per_hour','per_booking'],true))
    return self::error('basis_invalid');
  }
  $digest=hash('sha256',wp_json_encode([
   $mode,$platform,$input['company_property_key'],$input['proof_reference'],
   $input['title'],$input['address'],$prepared,$observations
  ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  return ['schema'=>self::SCHEMA,'mode'=>$mode,'platform'=>$platform,
   'company_property_key'=>$input['company_property_key'],
   'proposed_title'=>$input['title'],'proposed_address'=>$input['address'],
   'unit_candidates'=>$prepared,'unit_count'=>count($prepared),
   'price_observation_count'=>count($observations),
   'price_observation_candidates'=>array_map(static function($rate) {
     return [
       'unit_key'=>$rate['unit_key']??null,
       'date_from'=>$rate['date_from'],'date_to'=>$rate['date_to'],
       'amount'=>round((float)$rate['amount'],4),'currency'=>$rate['currency'],
       'basis'=>$rate['basis'],'occupancy'=>isset($rate['occupancy'])?(int)$rate['occupancy']:null,
       'fees_included'=>($rate['fees_included']??null)===true,
       'status'=>'unapproved_observation',
     ];
   },$observations),
   'candidate_digest'=>$digest,
   'proof_type'=>$proofType,'proof_reference'=>$input['proof_reference'],
   'connection_reference'=>$input['connection_id']??null,'source_url'=>$url,
   'proof_review_required'=>true,
   'source_capture_allowed'=>false,
   'property_create_authorized'=>false,'unit_create_authorized'=>false,
   'rate_create_authorized'=>false,'checkout_authority'=>false,
   'next_step'=>$mode==='contracted_provider'
      ?'verify_connection_and_scope_then_content_jobs'
      :'company_review_then_content_jobs',
   'state'=>'validated_intake_plan_only'];
 }
}
}
