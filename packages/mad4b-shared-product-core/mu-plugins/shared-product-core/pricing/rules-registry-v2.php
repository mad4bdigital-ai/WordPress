<?php
/** Central rule catalog and traceable Booking v2 quote. Lab, read-only. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_pricing_rules_catalog_v2(){
 return [
 'source'=>['phase'=>'base','authority'=>'source-resolution-policy','state'=>'partial'],
 'supplier_cost_margin'=>['phase'=>'base','authority'=>'financial-inheritance','state'=>'not_integrated'],
 'tour_mode'=>['phase'=>'eligibility','authority'=>'tour_mode','state'=>'integrated'],
 'rate_date'=>['phase'=>'eligibility','authority'=>'tour-rates','state'=>'integrated'],
 'audience'=>['phase'=>'base','authority'=>'rate/direct audience','state'=>'integrated'],
 'occupancy'=>['phase'=>'base','authority'=>'rate occupancy','state'=>'integrated'],
 'travelers_age'=>['phase'=>'base','authority'=>'quantities','state'=>'partial'],
 'season'=>['phase'=>'eligibility','authority'=>'season taxonomy + travel-date + Tour Rate','state'=>'integrated_no_extra_multiplier'],
 'booking_mode'=>['phase'=>'eligibility','authority'=>'tour_categories taxonomy','state'=>'integrated_fail_closed'],
 'private_pricing'=>['phase'=>'base_modifier','authority'=>'explicit Tour/Rate private percent policy','state'=>'opt_in_fail_closed'],
 'market_pricing'=>['phase'=>'base_modifier','authority'=>'site-approved Market Profile + Country switcher + declared Nationality + Woo policy','state'=>'opt_in_preview_only'],
 'optional_presets'=>['phase'=>'base_modifier','authority'=>'versioned WooCommerce Preset template + explicit scope assignment','state'=>'optional_disabled_by_default'],
 'price_unit'=>['phase'=>'base','authority'=>'unit-resolver','state'=>'integrated'],
 'package_level'=>['phase'=>'base_modifier','authority'=>'package_levels/option-page-356','state'=>'opt_in_preview'],
 'group_tier'=>['phase'=>'base_modifier','authority'=>'group-size-tiers/option-page-356','state'=>'opt_in_preview'],
 'accommodation'=>['phase'=>'modifier','authority'=>'property-layer-policy','state'=>'partial'],
 'transport'=>['phase'=>'modifier','authority'=>'booking-modifiers','state'=>'partial'],
 'guide'=>['phase'=>'modifier','authority'=>'booking-modifiers','state'=>'partial'],
 'duration'=>['phase'=>'modifier','authority'=>'unit-resolver','state'=>'partial'],
 'extras'=>['phase'=>'addon','authority'=>'booking-configuration-v2','state'=>'integrated'],
 'availability'=>['phase'=>'eligibility','authority'=>'rate-date + limits','state'=>'partial'],
 'fx'=>['phase'=>'presentation','authority'=>'currency-provider','state'=>'integrated'],
 'offers_coupons'=>['phase'=>'commercial','authority'=>'global-offer-coupon','state'=>'not_integrated_v2'],
 'tax_fee'=>['phase'=>'ledger','authority'=>'financial-ledger','state'=>'not_integrated_v2'],
 'manual_approval'=>['phase'=>'approval','authority'=>'financial-policy-admin','state'=>'not_integrated_v2']
 ];
}
function shared_product_pricing_quote_contract_v2($configuration){
 $validated=shared_product_booking_v2_validate($configuration);
 if(is_wp_error($validated))return $validated;
 $quote=shared_product_booking_v2_preview($configuration);
 if(is_wp_error($quote))return $quote;
 $catalog=shared_product_pricing_rules_catalog_v2();$used=[];$components=[];$sum=0;
 foreach($quote['lines'] as $index=>$line){
  $amount=$line['amount']??null;
  if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<0)return new WP_Error('rules_v2_invalid_line_amount');
  $id=($line['kind']??'line').':'.(int)($line['tour_id']??$validated['tour_id']).':'.$index;
  $components[]=['id'=>$id,'kind'=>$line['kind']??'unknown','amount'=>(float)$amount,'currency'=>$quote['currency']];$sum+=(float)$amount;
  foreach($line['strategy_presets']??[] as $receipt){
   if(!is_array($receipt))return new WP_Error('rules_v2_invalid_preset_receipt');
   $used[]=['factor'=>'optional_presets','component_id'=>$id,'receipt'=>$receipt];
  }
  foreach(['group_tier'=>'group_tier','package_level'=>'package_level','private_pricing'=>'private_pricing','market_pricing'=>'market_pricing','season'=>'season'] as $key=>$factor)if(isset($line[$key])){
   $receipt=$line[$key];if(!is_array($receipt))return new WP_Error('rules_v2_invalid_receipt');
   $used[]=['factor'=>$factor,'component_id'=>$id,'receipt'=>$receipt];
  }
 }
 if(abs(round($sum,2)-(float)$quote['total'])>0.009)return new WP_Error('rules_v2_components_not_balanced');
 foreach(['source','tour_mode','audience','occupancy','price_unit','fx'] as $factor)$used[]=['factor'=>$factor,'status'=>'delegated_to_existing_resolver'];
 if($validated['booking_mode']!==null)$used[]=['factor'=>'booking_mode','selected'=>$validated['booking_mode'],
  'taxonomy'=>$validated['booking_taxonomy']['taxonomy'],'terms'=>$validated['booking_taxonomy']['terms'],'status'=>'validated'];
 $mkt=shared_product_market_current_context();
 $used[]=['factor'=>'market_context','status'=>$mkt['status']??'unavailable',
  'profile_id'=>$mkt['profile_id']??null,'country'=>$mkt['country']??null,
  'language'=>$mkt['language']??null,'nationality'=>$mkt['nationality']??null,
  'nationality_source'=>$mkt['nationality_source']??'none',
  'price_status'=>count(array_filter($used,static fn($r)=>($r['factor']??'')==='market_pricing'))?'approved_rule_applied':'base_price_unmodified'];
 if($validated['travel_date']!==null)$used[]=['factor'=>'rate_date','status'=>'checked_if_rate_source'];
 if($validated['extras'])$used[]=['factor'=>'extras','count'=>count($validated['extras'])];
 $evaluations=shared_product_evaluate_registered_rules_v2($validated,$quote,$components);if(is_wp_error($evaluations))return $evaluations;
 $readiness=shared_product_financial_readiness_v2($validated['tour_id'],$validated['rate_id']);if(is_wp_error($readiness))return $readiness;
 return ['schema_version'=>2,'tour_id'=>$validated['tour_id'],'currency'=>$quote['currency'],'total'=>$quote['total'],'components'=>$components,'applied_rules'=>$used,'evaluator_receipts'=>$evaluations,'financial_readiness'=>$readiness,'registry'=>$catalog,'availability_status'=>$quote['availability_status']??'not_checked_price_only','inventory_required_for_price'=>false,'checkout_ready'=>false,'quote_status'=>$quote['status'],'status'=>'traceable_preview_only_no_cart_or_checkout_authority'];
}
