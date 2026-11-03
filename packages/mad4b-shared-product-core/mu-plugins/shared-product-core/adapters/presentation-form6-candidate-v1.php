<?php
/**
 * Local Lab: validate original Fluent Form #6 plus native Rate/Stay/Extra
 * selections. Read-only intake evidence, never a booking/price authority.
 */
defined('ABSPATH') || exit;
if (function_exists('wp_get_environment_type') && wp_get_environment_type()==='local'
 && defined('SHARED_PRODUCT_LAB_ONLY') && SHARED_PRODUCT_LAB_ONLY) {
if(is_file(__DIR__.'/presentation-stay-selection-v1.php'))require_once __DIR__.'/presentation-stay-selection-v1.php';
final class Shared_Product_Presentation_Form6_Candidate_v1 {
 private static function fail(string $code): WP_Error {
  return new WP_Error($code,'Selection not valid for preview');
 }
 private static function integer($value): int {
  if(!is_int($value) && !(is_string($value)&&preg_match('/^[1-9][0-9]*$/D',$value)))return 0;
  return (int)$value>0?(int)$value:0;
 }
 public static function validate(array $form,array $selection) {
  $f=shared_product_form_booking_intent(6,$form);
  if(is_wp_error($f))return $f;
  $allowed=['tour_id','rate_id','occupancy','audience','stay_stages','extras','transportations','booking_intent'];
  if(array_diff(array_keys($selection),$allowed))return self::fail('presentation_candidate_unexpected_keys');
  $tour=self::integer($selection['tour_id']??0);
  $rate=self::integer($selection['rate_id']??0);
  if(!$tour||get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish'
    ||!$rate||get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish'
    ||(int)get_post_meta($rate,'related_tour_id',true)!==$tour)
    return self::fail('presentation_candidate_rate_tour_mismatch');
  $occ=$selection['occupancy']??'double';
  if(!is_string($occ)||!in_array($occ,['single','double','triple'],true))
    return self::fail('presentation_candidate_occupancy_invalid');
  $aud=$selection['audience']??'general';
  if(!is_string($aud)||!in_array($aud,['general','egyptians'],true))
    return self::fail('presentation_candidate_audience_invalid');
  $intent=$selection['booking_intent']??'quote';
  if(!in_array($intent,['quote','inquiry'],true))
    return self::fail('presentation_candidate_no_financial_intent');
  if(($f['dates']['flexible']??false)===true)
    return self::fail('presentation_candidate_flexible_date_needs_manual_review');
  if(($f['mode']??'')!=='booking_candidate')
    return self::fail('presentation_candidate_request_mode_needs_review');
  if(!class_exists('MAD4B_Age_Fluent_Forms')||!is_callable(['MAD4B_Age_Fluent_Forms','validated_receipt']))
    return self::fail('presentation_candidate_age_provider_unavailable');
  $age=MAD4B_Age_Fluent_Forms::validated_receipt(6,$form,
    ['domain'=>'tour','product_id'=>$tour,'rate_id'=>$rate],$form);
  if(is_wp_error($age))return $age;
  if(!function_exists('shared_presentation_validate_stay_selection'))return self::fail('presentation_candidate_stay_provider_unavailable');
  $stay=shared_presentation_validate_stay_selection($rate,$selection['stay_stages']??[],
    ['arrival_date'=>$f['dates']['start'],'counts'=>$f['counts'],'age_context'=>$age,
     'transportations'=>$selection['transportations']??[]]);
  if(is_wp_error($stay))return $stay;
  $stages=$stay['stages'];$missing=$stay['missing'];
  $extras=$selection['extras']??[];
  if(!is_array($extras)||!array_is_list($extras)||count($extras)>30)
    return self::fail('presentation_candidate_extras_shape');
  $linked_ids=$extras?shared_product_allowed_optional_tour_ids($rate):[];
  if(is_wp_error($linked_ids))return $linked_ids;
  $prepared=[];$extra_seen=[];
  foreach($extras as $extra){
   if(!is_array($extra)||array_diff(array_keys($extra),['tour_id','rate_id','persons']))
    return self::fail('presentation_candidate_extra_shape');
   $eid=self::integer($extra['tour_id']??0);
   if(!$eid||isset($extra_seen[$eid])||!in_array($eid,$linked_ids,true)
    ||get_post_type($eid)!=='tours-and-activities'||get_post_status($eid)!=='publish')
    return self::fail('presentation_candidate_extra_unlinked_or_duplicate');
   $extra_seen[$eid]=true;
   $extra_rate=$extra['rate_id']??null;
   if($extra_rate!==null){
    $extra_rate=self::integer($extra_rate);
    if(!$extra_rate||get_post_type($extra_rate)!=='tour-rates'||get_post_status($extra_rate)!=='publish'
      ||(int)get_post_meta($extra_rate,'related_tour_id',true)!==$eid)
      return self::fail('presentation_candidate_extra_rate_mismatch');
   }
   $quantities=[];
   if(array_key_exists('persons',$extra)){
    $num=self::integer($extra['persons']);
    if(!$num||$num>$f['total_travelers'])return self::fail('presentation_candidate_extra_people_exceeded');
    $quantities['persons']=$num;
   }
   $prepared[]=['tour_id'=>$eid,'rate_id'=>$extra_rate,'quantities'=>$quantities];
  }
  $package=(int)get_post_meta($rate,'related_package_term_id',true);
  $v2=['schema_version'=>2,'tour_id'=>$tour,'source'=>'rate','rate_id'=>$rate,
   'travel_date'=>$f['dates']['start']!==''?$f['dates']['start']:null,
   'audience'=>$aud,'occupancy'=>$occ,
   'quantities'=>['persons'=>$f['total_travelers']],
   'extras'=>$prepared,
   'selections'=>$package>0?['package_term_id'=>$package]:[],
   'booking_intent'=>$intent];
  if(is_array($age))$v2['age_context']=$age;
  $validated=shared_product_booking_v2_validate($v2);
  if(is_wp_error($validated))return $validated;
  if($v2['travel_date']!==null && !shared_product_booking_v2_rate_covers_date($rate,$v2['travel_date']))
    return self::fail('presentation_candidate_rate_outside_dates');
  $inquiry=(bool)$stay['requires_supplier_approval'];
  $complete=(bool)$stay['complete'];
  return [
   'schema_version'=>'presentation-form6-candidate.v1',
   'status'=>$complete?'validated_nonfinancial_candidate':'incomplete_stay_selection',
   'source_form_id'=>6,'source_arrival_field'=>'datetime',
   'tour_id'=>$tour,'rate_id'=>$rate,'travel_date'=>$v2['travel_date'],
   'travelers'=>$f['counts'],'total_travelers'=>$f['total_travelers'],
   'occupancy'=>$occ,'audience'=>$aud,
   'package_term_id'=>$package,'stay_stages'=>$stages,
   'missing_stages'=>$missing,'missing_stay_details'=>$stay['detail_missing'],
   'transportations'=>$stay['transportations'],'extras'=>$prepared,
   'age_context'=>$age,'stay_details_complete'=>$complete,
   'requires_supplier_approval'=>$inquiry,
   'needs_manual_review'=>!$complete||$inquiry||$f['counts']['children']>0,
   'canonical_configuration_v2'=>$v2,
   'serverValidated'=>true,'readOnly'=>true,'checkoutAuthorized'=>false,
   'orderCreated'=>false,'bookingCreated'=>false,'supplierConfirmed'=>false
  ];
 }
}
}
