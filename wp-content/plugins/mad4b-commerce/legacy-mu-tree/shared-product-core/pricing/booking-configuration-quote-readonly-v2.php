<?php
/** Native Booking V2 read-only Rate configuration: single amount authority.
 * Shared by full Commerce and the explicitly admitted tenant preview;
 * no cart, WooCommerce, order, payment or supplier hooks exist here.
 */
defined('ABSPATH')||exit;
$commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED')&&MAD4B_COMMERCE_RUNTIME_ENABLED===true;
$preview=defined('MAD4B_BOOKING_V2_READONLY_ENABLED')&&MAD4B_BOOKING_V2_READONLY_ENABLED===true;
if(!$commerce&&!$preview)return;
function shared_product_booking_configuration_quote($rate_id,$audience,$occupancy,$selection){
 if(get_post_type($rate_id)!=='tour-rates'||get_post_status($rate_id)!=='publish'||!is_array($selection))return new WP_Error('booking_invalid_rate_or_options');
 $allowed=['persons','rooms','cabins','vehicles','days','trips','tables'];
 $quantities=$selection['quantities']??[];
 if(!is_array($quantities)||array_diff(array_keys($quantities),$allowed))return new WP_Error('booking_invalid_unit_quantities');
 foreach($quantities as $v)if(!filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]))return new WP_Error('booking_invalid_unit_quantity');
 $age_receipt=null;
 if(array_key_exists('age_context',$selection)){
  if(!class_exists('MAD4B_Age_Occupancy_Registry'))return new WP_Error('booking_age_registry_unavailable');
  $tour=absint(get_post_meta($rate_id,'related_tour_id',true));
  $age_receipt=MAD4B_Age_Occupancy_Registry::booking_evidence($selection['age_context'],
   ['domain'=>'tour','product_id'=>$tour,'rate_id'=>(int)$rate_id],(int)($quantities['persons']??0));
  if(is_wp_error($age_receipt))return $age_receipt;
 }
 $unit=shared_product_resolve_price_unit($rate_id);if(is_wp_error($unit))return $unit;
 $base=shared_product_quote_snapshot($rate_id,$audience,$occupancy);if(is_wp_error($base))return $base;
 $extended=shared_product_unit_extended_amount($base['display_amount'],$unit['unit'],$quantities);if(is_wp_error($extended))return $extended;
 $options=$selection['options']??[];
 if(!is_array($options)||array_diff(array_keys($options),['travelers','optional_rate_ids','guide_language','transportation','visual_selection']))return new WP_Error('booking_invalid_options');
 $visual=$options['visual_selection']??[];
 if(!is_array($visual)||array_diff(array_keys($visual),['property_id','transport_id','extra_tour_id','package_term_id']))return new WP_Error('invalid_visual_selection');
 foreach($visual as $key=>$value){if(!is_scalar($value)||!ctype_digit((string)$value)||absint($value)<1)return new WP_Error('invalid_visual_selection_id');}
 if($visual){
  $related=shared_product_rate_related_options($rate_id);if(is_wp_error($related))return $related;
  $available=['property_id'=>array_column($related['properties'],'id'),'extra_tour_id'=>array_column($related['optional_tours'],'id'),'transport_id'=>[]];
  foreach($related['transportation'] as $group)foreach($group as $entry)$available['transport_id'][]=$entry['id'];
  foreach($visual as $key=>$value){if($key==='package_term_id')continue;if(!in_array(absint($value),$available[$key],true))return new WP_Error('visual_option_not_linked_to_rate');}
  if(isset($visual['property_id'])){
   $choice=shared_product_select_package_accommodation($rate_id,$visual['property_id'],$visual['package_term_id']??null);
   if(is_wp_error($choice))return $choice;
  }elseif(isset($visual['package_term_id'])){
   $tier=shared_product_package_accommodation_options($rate_id,$visual['package_term_id']);
   if(is_wp_error($tier))return $tier;
  }
 }
 $composite=shared_product_composite_quote($rate_id,$audience,$occupancy,$options);if(is_wp_error($composite))return $composite;
 // Composite quote base represents one pricing unit. Extend only the base; child options are quoted independently.
 $total=round($extended['amount']+(float)$composite['optional_total']+(float)$composite['modifiers_total'],2);
 if($total<=0)return new WP_Error('booking_invalid_total');
 return ['schema'=>1,'rate_id'=>(int)$rate_id,'audience'=>$audience,'occupancy'=>$occupancy,'unit'=>$unit['unit'],
  'unit_label'=>$unit['label'],'unit_quantity'=>$extended['quantity'],'quantities'=>$quantities,'options'=>$options,'currency'=>$base['display_currency'],
  'base_unit_amount'=>$base['display_amount'],'base_extended_amount'=>$extended['amount'],
  'optional_rate_lines'=>$composite['optional_rate_lines'],'modifier_lines'=>$composite['modifier_lines'],
  'total'=>$total,'quoted_at_utc'=>gmdate('c'),'status'=>'lab_cart_quote_not_financial_ledger','age_policy_receipt'=>$age_receipt];
}
