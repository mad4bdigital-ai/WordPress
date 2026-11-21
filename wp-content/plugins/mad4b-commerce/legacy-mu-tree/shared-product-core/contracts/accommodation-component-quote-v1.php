<?php
/** Project a native accommodation quote, never a client-provided total.
 * No booking, payment, CPT or price modification.
 */
defined('ABSPATH') || exit;
if(!function_exists('mad4b_accommodation_component_quote_v1')){
function mad4b_accommodation_component_quote_v1(array $input){
 if(!function_exists('abk_accommodation_shadow_quote')||!function_exists('shared_product_component_contract_v1'))
  return new WP_Error('accommodation_component_native_engine_unavailable');
 $allowed=['rate_id','checkin','checkout','rooms','adults','children','inclusion_mode'];
 if(array_diff(array_keys($input),$allowed))return new WP_Error('accommodation_component_unexpected_fields');
 $id=filter_var($input['rate_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if(!$id||get_post_type($id)!=='property-rates')return new WP_Error('accommodation_component_rate_invalid');
 $mode=$input['inclusion_mode']??'optional';
 if(!in_array($mode,['included','optional','alternative'],true))
  return new WP_Error('accommodation_component_mode_invalid');
 // A saved QA fixture is never a commercially trusted preview, even if a WP-CLI
 // testing filter temporarily allows its native shadow quote to calculate.
 if(get_post_status($id)!=='publish')return new WP_Error('accommodation_component_rate_not_published');
 if(preg_match('/\bQA\s+ONLY\b/i',(string)get_the_title($id)))
  return new WP_Error('accommodation_component_fixture_rejected');
 $quote=abk_accommodation_shadow_quote([
   'rate_id'=>$id,'checkin'=>$input['checkin']??null,'checkout'=>$input['checkout']??null,
   'rooms'=>$input['rooms']??1,'adults'=>$input['adults']??1,'children'=>$input['children']??0,
   'availability_mode'=>'price_only'
 ]);
 if(is_wp_error($quote))return $quote;
 $property=(int)get_post_meta($id,'prop_related_property_id',true);
 $unit=(int)get_post_meta($id,'prop_related_unit_id',true);
 if(($quote['status']??'')!=='shadow_preview_only' || ($quote['checkout_ready']??null)!==false
   ||(int)($quote['rate_id']??0)!==$id
   ||(int)($quote['property_id']??0)!==$property
   ||(int)($quote['unit_id']??0)!==$unit
   ||!is_numeric($quote['total']??null)
   ||!is_string($quote['currency']??null))
    return new WP_Error('accommodation_component_quote_contract_invalid');
 $component=shared_product_component_contract_v1([
  'component_key'=>'accommodation:'.$property.':'.$unit.':'.$id.':'
    .($input['checkin']??'').':'.($input['checkout']??''),
  'kind'=>'accommodation','product_id'=>$property,'unit_id'=>$unit,'unit_kind'=>'room',
  'rate_id'=>$id,'source'=>['type'=>'rate','rate_id'=>$id],
  'inclusion_mode'=>$mode,'price_unit'=>$quote['price_basis'],
  'currency'=>$quote['currency'],'amount'=>$quote['total'],'readiness'=>'preview_priced'
 ]);
 if(is_wp_error($component))return $component;
 return ['contract'=>'mad4b.accommodation-component-quote.v1',
  'component'=>$component,'native_quote'=>$quote,
  'source_status'=>'native_rate_quote_price_only',
  'inventory_status'=>$quote['inventory_status']??'unknown',
  'rate_authority'=>'native_preview_only','checkout_authority'=>false];
}
}
