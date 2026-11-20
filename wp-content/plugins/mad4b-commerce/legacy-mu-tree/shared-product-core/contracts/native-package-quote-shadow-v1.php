<?php
/** Tour Booking v2 + Accommodation native domain quote composer, shadow-only.
 * This adapter makes no claim of availability, seller/provider ownership or checkout authority.
 */
defined('ABSPATH') || exit;
if(!function_exists('mad4b_native_package_quote_shadow_v1')){
function mad4b_native_package_quote_shadow_v1(array $input) {
 if(!function_exists('shared_product_booking_v2_preview')
    ||!function_exists('mad4b_booking_v2_component_shadow_v1')
    ||!function_exists('mad4b_accommodation_component_quote_v1')
    ||!function_exists('shared_product_component_package_shadow_v1'))
    return new WP_Error('native_package_dependencies_unavailable');
 $allowed=['tour_quote','accommodation','selected_alternatives'];
 if(array_diff(array_keys($input),$allowed))return new WP_Error('native_package_input_unknown_fields');
 $tourInput=$input['tour_quote']??null;
 if(!is_array($tourInput)||!$tourInput)return new WP_Error('native_package_tour_input_missing');
 $tourQuote=shared_product_booking_v2_preview($tourInput);
 if(is_wp_error($tourQuote))return $tourQuote;
 $tourShadow=mad4b_booking_v2_component_shadow_v1($tourQuote);
 if(is_wp_error($tourShadow))return $tourShadow;
 if(($tourShadow['parity_status']??null)!=='match')
    return new WP_Error('native_package_tour_price_parity_mismatch');
 $components=$tourShadow['components'];
 $sourceCurrency=$tourQuote['currency']??null;
 $stayResults=[];$stays=$input['accommodation']??[];
 if(!is_array($stays)||!array_is_list($stays)||count($stays)>20)
    return new WP_Error('native_package_accommodation_invalid');
 foreach($stays as $stay) {
   if(!is_array($stay))return new WP_Error('native_package_stay_invalid');
   $result=mad4b_accommodation_component_quote_v1($stay);
   if(is_wp_error($result))return $result;
   if(($result['component']['currency']??null)!==$sourceCurrency)
     return new WP_Error('native_package_currency_requires_conversion');
   $policy=mad4b_native_package_stay_policy_v1(
     absint($tourInput['rate_id']??0),
     (int)$result['component']['product_id'],
     (string)$result['component']['inclusion_mode']
   );
   if(is_wp_error($policy))return $policy;
   $components[]=$result['component'];
   $stayResults[]=['rate_id'=>$result['component']['rate_id'],
     'property_id'=>$result['component']['product_id'],
     'unit_id'=>$result['component']['unit_id'],
     'inclusion_mode'=>$result['component']['inclusion_mode'],
     'native_quote_total'=>$result['native_quote']['total'],
     'currency'=>$result['native_quote']['currency'],
     'inventory_status'=>$result['inventory_status']];
 }
 $selected=$input['selected_alternatives']??[];
 if(!is_array($selected)||!array_is_list($selected))
   return new WP_Error('native_package_alternatives_invalid');
 $package=shared_product_component_package_shadow_v1($components,$selected);
 if(is_wp_error($package))return $package;
 if(($package['complete']??false)!==true)return new WP_Error('native_package_incomplete_components');
 $base=(float)$tourQuote['total'];
 $total=(float)$package['priced_subtotal'];
 if($total+0.01<$base)return new WP_Error('native_package_base_not_preserved');
 return [
  'contract'=>'mad4b.native-package-quote-shadow.v1',
  'base_tour_quote'=>['tour_id'=>$tourQuote['tour_id']??null,
    'total'=>$base,'currency'=>$sourceCurrency,
    'line_count'=>count($tourQuote['lines']??[])],
  'accommodation_quotes'=>$stayResults,
  'package'=>$package,
  'total'=>$total,'currency'=>$sourceCurrency,
  'price_parity_status'=>$tourShadow['parity_status'],
  'status'=>'shadow_only_not_checkout_authority',
  'includes_inventory_certification'=>false,
  'provider_authority'=>false,
  'checkout_authority'=>false,
  'original_tour_quote_unchanged'=>true
 ];
}
}

/** Verify saved native stay policy before interpreting "included" or "optional". */
if(!function_exists('mad4b_native_package_stay_policy_v1')){
function mad4b_native_package_stay_policy_v1(int $tourRateId,int $propertyId,string $mode){
 if($tourRateId<1||get_post_type($tourRateId)!=='tour-rates'
   ||get_post_status($tourRateId)!=='publish'||$propertyId<1
   ||get_post_type($propertyId)!=='properties')
   return new WP_Error('native_package_stay_identity_invalid');
 if(!in_array($mode,['included','optional','alternative'],true))
   return new WP_Error('native_package_stay_mode_invalid');
 if(!function_exists('BookingExperience'.chr(92).'tour_stay_rate_plan'))
   return new WP_Error('native_package_rate_stay_policy_unavailable');
 $plan=BookingExperience\tour_stay_rate_plan($tourRateId);
 if(is_wp_error($plan))return $plan;
 $policies=[];
 if(($plan['mode']??'')==='simple_or'){
   if(in_array($propertyId,(array)($plan['properties']??[]),true))
     $policies[]=(string)($plan['price_policy']??'inquiry');
 }elseif(($plan['mode']??'')==='multi_stage'){
   foreach((array)($plan['stages']??[]) as $stage)
     if(in_array($propertyId,(array)($stage['properties']??[]),true))
       $policies[]=(string)($stage['price_policy']??'inquiry');
 }
 if(!$policies)return new WP_Error('native_package_property_not_linked_to_tour_rate');
 if(count(array_unique($policies))!==1)
   return new WP_Error('native_package_stay_price_policy_ambiguous');
 $policy=$policies[0];
 if($mode==='included'&&$policy==='included')return ['verified'=>true,'policy'=>$policy,'charge_as_extra'=>false];
 if(in_array($mode,['optional','alternative'],true)&&$policy==='additional')
   return ['verified'=>true,'policy'=>$policy,'charge_as_extra'=>true];
 return new WP_Error('native_package_stay_price_policy_mismatch');
}
}

/** Preview-only presentation derived from the quote composer, never browser prices. */
if(!function_exists('mad4b_native_package_presentation_shadow_v1')){
function mad4b_native_package_presentation_shadow_v1(array $input){
 $receipt=mad4b_native_package_quote_shadow_v1($input);
 if(is_wp_error($receipt))return $receipt;
 if(($receipt['contract']??'')!=='mad4b.native-package-quote-shadow.v1'
    ||($receipt['checkout_authority']??null)!==false
    ||($receipt['price_parity_status']??null)!=='match')
   return new WP_Error('native_package_presentation_receipt_untrusted');
 $lines=[['kind'=>'tour','label'=>'Tour base (Booking v2)',
    'amount'=>$receipt['base_tour_quote']['total'],
    'currency'=>$receipt['currency'],'included_in_total'=>true]];
 foreach($receipt['accommodation_quotes'] as $stay){
   $mode=$stay['inclusion_mode'];
   $lines[]=['kind'=>'accommodation','label'=>'Stay / '.($mode==='included'?'Included':ucfirst($mode)),
    'rate_id'=>$stay['rate_id'],'property_id'=>$stay['property_id'],'unit_id'=>$stay['unit_id'],
    'amount'=>$stay['native_quote_total'],'currency'=>$stay['currency'],
    'included_in_total'=>$mode!=='included',
    'inventory_status'=>$stay['inventory_status']];
 }
 return ['contract'=>'mad4b.native-package-presentation-shadow.v1',
  'lines'=>$lines,'total'=>$receipt['total'],'currency'=>$receipt['currency'],
  'price_parity_status'=>$receipt['price_parity_status'],
  'customer_price_display_authorized'=>false,
  'checkout_authority'=>false,'status'=>'internal_shadow_preview_only'];
}
}
