<?php
/** Read-only package readiness. Observability only; never sale or publication authority. */
defined('ABSPATH') || exit;
if (!function_exists('mad4b_package_readiness_shadow_v1')) {
function mad4b_package_readiness_shadow_v1(int $tourRateId, string $serviceDate) {
  if(!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$serviceDate)
     || !($day=DateTimeImmutable::createFromFormat('!Y-m-d',$serviceDate,new DateTimeZone('UTC')))
     || $day->format('Y-m-d')!==$serviceDate)
    return new WP_Error('package_readiness_date_invalid');
  if(get_post_type($tourRateId)!=='tour-rates'||get_post_status($tourRateId)!=='publish')
    return new WP_Error('package_readiness_rate_invalid');
  $tourId=(int)get_post_meta($tourRateId,'related_tour_id',true);
  if(!$tourId||get_post_type($tourId)!=='tours-and-activities'||get_post_status($tourId)!=='publish')
    return new WP_Error('package_readiness_parent_invalid');
  if(!function_exists('mad4b_component_property_references_shadow_v1'))
    return new WP_Error('package_readiness_property_guard_missing');
  $properties=mad4b_component_property_references_shadow_v1($tourRateId);
  if(is_wp_error($properties))return $properties;
  $errors=[];$notices=[];
  if(($properties['status']??'')==='needs_reference_reconciliation')
    $errors[]=['code'=>'property_source_identity_required','count'=>count($properties['invalid'])];
  $rateEligible=function_exists('shared_product_booking_v2_rate_covers_date')
     ? shared_product_booking_v2_rate_covers_date($tourRateId,$serviceDate) : false;
  if(!$rateEligible)$errors[]=['code'=>'base_rate_not_eligible_for_date'];
  $extraEnabled=(string)get_post_meta($tourId,'tour_extras_status',true)==='1';
  $extras=[];
  if($extraEnabled && function_exists('shared_product_optional_tour_ids_for_tour')){
    $extras=shared_product_optional_tour_ids_for_tour($tourId);
    if(is_wp_error($extras)){
      $errors[]=['code'=>'extra_relation_invalid','detail'=>$extras->get_error_code()];
      $extras=[];
    }
  }
  if(!$extraEnabled)$notices[]=['code'=>'optional_extras_not_enabled'];
  // No implicit transport linkage: commercial transport selection is separate.
  $notices[]=['code'=>'transport_requires_explicit_package_relationship_and_valid_rate'];
  $notices[]=['code'=>'property_rate_quote_requires_verified_unit'];
  return [
    'contract'=>'mad4b.package-readiness.shadow.v1','tour_id'=>$tourId,
    'tour_rate_id'=>$tourRateId,'service_date'=>$serviceDate,
    'base_rate_date_eligible'=>(bool)$rateEligible,
    'property_refs_valid'=>count($properties['valid']),
    'property_refs_invalid'=>$properties['invalid'],
    'extra_tours_enabled'=>$extraEnabled,
    'extra_tours_eligible_ids'=>array_values((array)$extras),
    'errors'=>$errors,'notices'=>$notices,
    'status'=>$errors?'needs_reconciliation':'base_only_candidate',
    'multi_component_quote_certified'=>false,
    'checkout_authority'=>false,
    'side_effects'=>false
  ];
}
}
