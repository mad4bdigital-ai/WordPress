<?php
/** Booking Configuration v2 schema contract. Lab only. No cart hooks or writes. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
require_once __DIR__.'/extra-source-quote.php';
/** Optional passenger gender for supplier manifests; never a pricing or eligibility authority. */
function shared_product_booking_gender_choices() {
 $default=['male'=>'Male','female'=>'Female','non_binary'=>'Non-binary',
  'prefer_not_to_say'=>'Prefer not to say','unspecified'=>'Unspecified'];
 $choices=apply_filters('shared_product_booking_gender_choices',$default);
 // Keep the public contract deterministic and finite.
 if(!is_array($choices)||!$choices)return $default;
 foreach($choices as $key=>$label)
  if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$key)||
   !is_string($label)||trim($label)==='')return $default;
 return $choices;
}
function shared_product_booking_v2_validate($payload) {
 if(!is_array($payload) || ($payload['schema_version']??null)!==2)return new WP_Error('booking_v2_schema');
 $allowed=['schema_version','tour_id','source','rate_id','travel_date','audience','occupancy','quantities','extras','selections','booking_mode','group_size','tier_id','package_pricing_apply','traveller_nationalities','traveller_genders','booking_intent','age_context','mad4b_age_context'];
 if(array_diff(array_keys($payload),$allowed))return new WP_Error('booking_v2_unexpected_keys');
 $id=filter_var($payload['tour_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if(!$id||get_post_type($id)!=='tours-and-activities'||get_post_status($id)!=='publish')return new WP_Error('booking_v2_invalid_tour');
 $source=$payload['source']??null;if(!in_array($source,['rate','direct'],true))return new WP_Error('booking_v2_source');
 $rate=$payload['rate_id']??null;
 if($source==='rate'){
  $rate=filter_var($rate,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if(!$rate||get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish'||(int)get_post_meta($rate,'related_tour_id',true)!==$id)return new WP_Error('booking_v2_rate_mismatch');
 }elseif($rate!==null)return new WP_Error('booking_v2_direct_rate_forbidden');
 $intent=$payload['booking_intent']??'quote';if(!in_array($intent,['inquiry','quote','request','instant'],true))return new WP_Error('booking_v2_intent_invalid');
 $date=$payload['travel_date']??null;
 if($date!==null && (!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!DateTimeImmutable::createFromFormat('!Y-m-d',$date)||DateTimeImmutable::createFromFormat('!Y-m-d',$date)->format('Y-m-d')!==$date))return new WP_Error('booking_v2_date_invalid');
 $q=$payload['quantities']??null;$unit_fields=['persons','rooms','cabins','vehicles','days','trips','tables'];
 if(!is_array($q)||!$q||array_diff(array_keys($q),$unit_fields))return new WP_Error('booking_v2_quantities');
 foreach($q as $v)if(filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]])===false)return new WP_Error('booking_v2_quantity_invalid');
 $persons=(int)($q['persons']??0);if($persons<1||$persons>999)return new WP_Error('booking_v2_persons_invalid');
 // Booking choice is independent of the multi-valued Private/Group/Mixed taxonomy.
 $booking_mode=$payload['booking_mode']??null;
 if($booking_mode!==null&&!in_array($booking_mode,['private','group'],true))return new WP_Error('booking_v2_booking_mode_invalid');
 $group_size=$payload['group_size']??null;
 if($group_size!==null){
  $group_size=filter_var($group_size,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]);
  if($group_size===false)return new WP_Error('booking_v2_group_size_invalid');
  if($booking_mode!=='group')return new WP_Error('booking_v2_group_size_requires_group_booking');
 }
 $tier_id=$payload['tier_id']??null;
 // Only Tours that explicitly carry enabled Group-tier terms use automatic
 // tier resolution. Never apply the generic preset catalogue to every Tour.
 if($booking_mode==='group' && $source==='rate' &&
    function_exists('BookingExperience\\commercial_group_classification')){
  $decision=\BookingExperience\commercial_group_classification($id,$group_size??$persons);
  if(is_wp_error($decision))return $decision;
  if(($decision['status']??'')==='below_group_minimum')
   return new WP_Error(($decision['mode']??null)==='private'
     ?'booking_private_mode_required':'booking_group_minimum_not_met');
  if(($decision['status']??'')==='no_matching_tier')
   return new WP_Error('booking_group_tier_gap');
  if(($decision['status']??'')==='within_group_tier'){
   if($tier_id!==null && (int)$tier_id!==(int)$decision['tier_id'])
    return new WP_Error('booking_group_tier_selection_mismatch');
   $tier_id=(int)$decision['tier_id'];
   $group_size=$group_size??$persons;
  }
 }

 if($group_size!==null && $tier_id===null && function_exists('shared_product_group_tier_from_taxonomy')){
  $tier_id=shared_product_group_tier_from_taxonomy($id,$group_size);
  if(is_wp_error($tier_id))return $tier_id;
 }
 if($tier_id!==null){
  $tier_id=filter_var($tier_id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if(!$tier_id)return new WP_Error('booking_v2_tier_id_invalid');
  if($booking_mode!=='group'||$source!=='rate'||$group_size===null)return new WP_Error('booking_v2_tier_requires_group_rate');
  if($group_size!==(int)$q['persons'])return new WP_Error('booking_v2_group_size_persons_mismatch');
 }
 $package_apply=$payload['package_pricing_apply']??false;
 if(!is_bool($package_apply))return new WP_Error('booking_v2_package_pricing_flag_invalid');
 if($package_apply&&empty($payload['selections']['package_term_id']))return new WP_Error('booking_v2_package_level_required');
 $extras=$payload['extras']??[];if(!is_array($extras)||count($extras)>30)return new WP_Error('booking_v2_extras');
 $linked=get_post_meta($id,'tour_optional_extras',true);
 $linked_ids=array_map('intval',is_array($linked)?$linked:[]);
 $seen=[];$rates=[];$clean=[];
 foreach($extras as $extra){
  if(!is_array($extra)||array_diff(array_keys($extra),['tour_id','rate_id','quantities']))return new WP_Error('booking_v2_extra_shape');
  $eid=filter_var($extra['tour_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if(!$eid||isset($seen[$eid])||get_post_type($eid)!=='tours-and-activities'||get_post_status($eid)!=='publish')return new WP_Error('booking_v2_extra_duplicate_or_invalid');
  if(!in_array($eid,$linked_ids,true))return new WP_Error('booking_v2_extra_not_linked');
  $seen[$eid]=true;
  $erate=$extra['rate_id']??null;
  if($erate!==null){
   $erate=filter_var($erate,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$erate||isset($rates[$erate])||get_post_type($erate)!=='tour-rates'||(int)get_post_meta($erate,'related_tour_id',true)!==$eid)return new WP_Error('booking_v2_extra_rate_mismatch');
   $rates[$erate]=true;
  }
  $eq=$extra['quantities']??[];if(!is_array($eq)||array_diff(array_keys($eq),$unit_fields))return new WP_Error('booking_v2_extra_quantities');
  foreach($eq as $v)if(filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]])===false)return new WP_Error('booking_v2_extra_quantity_invalid');
  if(isset($eq['persons'])&&(int)$eq['persons']>$persons)return new WP_Error('booking_v2_extra_persons_exceed_booking');
  $clean[]=['tour_id'=>$eid,'rate_id'=>$erate,'quantities'=>array_map('intval',$eq)];
 }
 $selections=$payload['selections']??[];
 if(!is_array($selections)||array_diff(array_keys($selections),['package_term_id','property_id','unit_id','transport_id','season_term_id','guide_language_term_id']))return new WP_Error('booking_v2_selections');
 foreach($selections as $value)if(filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)return new WP_Error('booking_v2_selection_id');
 if(isset($selections['unit_id']) &&
    (empty($selections['property_id']) ||
     !Shared_Product_Advance_Notice::linked_unit((int)$selections['unit_id'],(int)$selections['property_id'],(int)($rate??0))))
   return new WP_Error('booking_v2_unit_property_relation_unverified');
 $guide=(int)($selections['guide_language_term_id']??0);
 if($guide){
  $assigned=wp_get_post_terms($id,'guide-languages',['fields'=>'ids']);
  if(is_wp_error($assigned)||!in_array($guide,array_map('intval',(array)$assigned),true))
   return new WP_Error('booking_v2_guide_language_not_assigned');
 }
 $modes=shared_product_booking_modes_from_taxonomy($id);
 if(is_wp_error($modes))return $modes;
 if($booking_mode!==null && !in_array($booking_mode,$modes['modes'],true))
  return new WP_Error('booking_mode_not_offered_by_taxonomy');
 $season=shared_product_taxonomy_season_context($id,$source==='rate'?$rate:null,$date,$selections['season_term_id']??null);
 if(is_wp_error($season))return $season;
 $audience=$payload['audience']??'general';$occupancy=$payload['occupancy']??'double';
 if(!is_string($audience)||sanitize_key($audience)!==$audience||!is_string($occupancy)||sanitize_key($occupancy)!==$occupancy)return new WP_Error('booking_v2_context');
 $passport=[];
 if(array_key_exists('traveller_nationalities',$payload)){
  if(!Shared_Product_Nationality_Source::available())return new WP_Error('booking_v2_nationality_provider_missing');
  $passport=Shared_Product_Nationality_Source::validate_list($payload['traveller_nationalities']);
  if(is_wp_error($passport))return $passport;
  if(count($passport)!==$persons)return new WP_Error('booking_v2_passenger_count_mismatch');
 }elseif($persons===1&&Shared_Product_Nationality_Source::available()){
  $iso=Shared_Product_Nationality_Source::selected();
  if($iso) $passport=[Shared_Product_Nationality_Source::valid($iso)];
 }
 $genders=[];
 if(array_key_exists('traveller_genders',$payload)){
  $v=$payload['traveller_genders'];
  if(!is_array($v)||!array_is_list($v)||count($v)!==$persons)
   return new WP_Error('booking_v2_gender_passenger_count_mismatch');
  $allowed=array_keys(shared_product_booking_gender_choices());
  foreach($v as $gender){
   if(!is_string($gender)||!in_array($gender,$allowed,true))
    return new WP_Error('booking_v2_gender_value_invalid');
   $genders[]=$gender;
  }
 }
 // Gender is voluntary manifest context only. No discounts, fees, or
 // supplier eligibility may be inferred from this field by Booking v2.
 if($audience==='egyptians' && (count($passport)!==$persons || count(array_unique($passport))!==1 || reset($passport)!=='EG'))
  return new WP_Error('booking_v2_egyptian_audience_requires_passport_nationality');


 // Use the active Age Occupancy Registry; legacy Fluent context is an input alias.
 if(array_key_exists('age_context',$payload)&&array_key_exists('mad4b_age_context',$payload))
  return new WP_Error('booking_v2_duplicate_age_context');
 $raw_age=$payload['age_context']??$payload['mad4b_age_context']??null;
 $age_receipt=null;
 if($raw_age!==null){
  if(!class_exists('MAD4B_Age_Occupancy_Registry'))return new WP_Error('booking_v2_age_registry_unavailable');
  $age_receipt=MAD4B_Age_Occupancy_Registry::booking_evidence($raw_age,
   ['domain'=>'tour','product_id'=>$id,'rate_id'=>$rate?:0],$persons);
  if(is_wp_error($age_receipt))return $age_receipt;
  if($age_receipt['stay_children'] && in_array($intent,['request','instant'],true))
   return new WP_Error('booking_v2_age_pricing_not_certified');
 }
 return ['schema_version'=>2,'tour_id'=>$id,'source'=>$source,'rate_id'=>$rate,'travel_date'=>$date,'audience'=>$audience,'occupancy'=>$occupancy,'quantities'=>array_map('intval',$q),'extras'=>$clean,'selections'=>array_map('intval',$selections),'booking_mode'=>$booking_mode,'group_size'=>$group_size,'tier_id'=>$tier_id,'package_pricing_apply'=>$package_apply,'booking_taxonomy'=>$modes,'travel_season'=>$season,'traveller_nationalities'=>$passport,'traveller_genders'=>$genders,'booking_intent'=>$intent,'age_policy_receipt'=>$age_receipt];
}


/**
 * WooCommerce-native cart identity: one tours-and-activities product per booking.
 * This is a structural adapter only; do not attach to cart until server quote is certified.
 */
function shared_product_booking_v2_cart_identity($configuration, $cart_product_id) {
 $validated=shared_product_booking_v2_validate($configuration);
 if(is_wp_error($validated))return $validated;
 if((int)$cart_product_id !== $validated['tour_id'])return new WP_Error('booking_v2_cart_product_mismatch');
 if(!function_exists('wc_get_product') || !wc_get_product($cart_product_id))return new WP_Error('booking_v2_cart_product_unavailable');
 return [
  'product_id'=>$validated['tour_id'],
  'cart_quantity'=>1,
  'configuration'=>$validated,
  'requires_server_quote'=>true,
  'status'=>'structure_only_not_ready_for_cart_write'
 ];
}


/** Inclusive calendar-day Rate eligibility; dates are UTC timestamps in the existing CPT. */
function shared_product_booking_v2_rate_covers_date($rate_id,$travel_date) {
 if(!is_string($travel_date)||!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$travel_date))return false;
 $start=(int)get_post_meta($rate_id,'tour_rate_start_date',true);
 $end=(int)get_post_meta($rate_id,'tour_rate_end_date',true);
 if(!$start||!$end||$end<$start)return false;
 $day=strtotime($travel_date.' 12:00:00 UTC');
 if(!$day)return false;
 return gmdate('Y-m-d',$start)<=$travel_date && gmdate('Y-m-d',$end)>=$travel_date;
}
/** Validate an Extra Rate without bypassing its configured pricing strategy. */
function shared_product_booking_v2_extra_rate_preview($parent,$extra,$audience,$occupancy,$quantities,$date,$currency) {
 $rate=(int)($extra['rate_id']??0);
 if(!$rate||!shared_product_booking_v2_rate_covers_date($rate,$date))return new WP_Error('booking_v2_extra_rate_outside_dates');
 $allowed=shared_product_allowed_optional_tour_ids($parent);
 if(is_wp_error($allowed))return $allowed;
 if(!in_array((int)$extra['tour_id'],$allowed,true))return new WP_Error('booking_v2_extra_not_eligible');
 $strategy=shared_product_strategy_quote($rate,$audience,$occupancy,$currency);
 if(is_wp_error($strategy))return $strategy; // Fail closed on unconfigured activity strategy.
 $unit=shared_product_resolve_price_unit($rate);
 if(is_wp_error($unit))return $unit;
 $amount=shared_product_unit_extended_amount($strategy['display_amount']??null,$unit['unit'],$quantities);
 if(is_wp_error($amount))return $amount;
 return ['kind'=>'extra','tour_id'=>(int)$extra['tour_id'],'rate_id'=>$rate,'source'=>'rate','amount'=>$amount['amount'],'currency'=>$currency,'unit'=>$unit['unit'],'quantity'=>$amount['quantity']];
}
/** Server-only quote for the WooCommerce-native v2 draft; never sets Cart prices. */
function shared_product_booking_v2_preview($payload) {
 $c=shared_product_booking_v2_validate($payload);
 if(is_wp_error($c))return $c;
 if($c['travel_date']===null && in_array($c['booking_intent'],['request','instant'],true))
  return new WP_Error('booking_v2_request_date_required');
 // A date is optional for price-only previews; when supplied it is always checked on the server.
 if($c['travel_date']!==null){
  $eligibility=Shared_Product_Scheduling_Eligibility::decide((int)$c['tour_id'],$c['travel_date'],$c['source'],
   $c['rate_id']===null?null:(int)$c['rate_id'],$c['booking_intent'],$c['audience'],
   array_intersect_key($c['selections'],array_flip(['property_id','unit_id','transport_id'])),$c['extras'],
   apply_filters('shared_product_advance_notice_additional_components',[],$c));
  if(is_wp_error($eligibility))return $eligibility;
  $decision=$eligibility['booking_eligibility'];
  if($decision['status']==='ineligible')return new WP_Error('booking_v2_program_ineligible',$decision['reason_code']);
  if($c['booking_intent']==='instant'||($decision['status']==='unknown'&&$c['booking_intent']!=='quote'&&$c['booking_intent']!=='inquiry'))
   return new WP_Error('booking_v2_eligibility_not_confirmed',$decision['reason_code']);
 }
 if($c['source']==='direct'){
  // Without certified date-sensitive Rate discovery, never bypass a higher-priority Rate.
  $source_policy=shared_product_price_source_policy((string)get_post_meta($c['tour_id'],'tour_mode',true));
  if(is_wp_error($source_policy))return $source_policy;
  $preferred_rate=$source_policy['rate_weight']>$source_policy['direct_weight']||
    ($source_policy['rate_weight']===$source_policy['direct_weight']&&$source_policy['tie_break']==='rate');
  if($preferred_rate){
   $related=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','fields'=>'ids','numberposts'=>1,
     'meta_query'=>[['key'=>'related_tour_id','value'=>(string)$c['tour_id'],'compare'=>'=']]]);
   if($related){
    if($c['travel_date']===null)return new WP_Error('booking_v2_date_required_for_source_resolution');
    // Check every published Rate (not just the first one) for date eligibility.
    $all=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','fields'=>'ids','numberposts'=>-1,
      'meta_query'=>[['key'=>'related_tour_id','value'=>(string)$c['tour_id'],'compare'=>'=']]]);
    foreach($all as $rid)if(shared_product_booking_v2_rate_covers_date($rid,$c['travel_date']))
      return new WP_Error('booking_v2_direct_rate_priority_requires_resolution');
   }
  }
  if($c['selections'])return new WP_Error('booking_v2_direct_selections_not_certified');

  $unit=(string)get_post_meta($c['tour_id'],'tour_price_unit',true);
  $allowed=['per_person','per_cabin','per_room','per_vehicle','per_day','per_trip','table'];
  if(!in_array($unit,$allowed,true))return new WP_Error('booking_v2_direct_unit_invalid');
  if($c['audience']==='egyptians'){
    $raw=get_post_meta($c['tour_id'],'egyptians_start_price',true);
    $source_currency=get_post_meta($c['tour_id'],'tour_egyptians_currency',true);
  }elseif($c['audience']==='general'){
    $raw=get_post_meta($c['tour_id'],'start_price',true);
    $source_currency=get_post_meta($c['tour_id'],'tour_base_currency',true);
  }else return new WP_Error('booking_v2_direct_audience_invalid');
  if(!is_scalar($raw)||!is_numeric($raw)||!is_finite((float)$raw)||(float)$raw<=0||
     !is_string($source_currency)||!preg_match('/^[A-Z]{3}$/D',$source_currency))return new WP_Error('booking_v2_direct_price_or_currency_missing');
  $extended=shared_product_unit_extended_amount($raw,$unit,$c['quantities']);
  if(is_wp_error($extended))return $extended;
  $chosen=shared_product_resolve_price_sources(['direct'=>['amount'=>$extended['amount'],'currency'=>$source_currency]],(string)get_post_meta($c['tour_id'],'tour_mode',true));
  if(is_wp_error($chosen))return $chosen;
  if($chosen['source']!=='direct')return new WP_Error('booking_v2_direct_source_not_selected');
  $currency=pricing_display_currency();
  $converted=pricing_currency_convert($extended['amount'],$source_currency,$currency);
  if($converted===null||!is_finite((float)$converted)||(float)$converted<=0)return new WP_Error('booking_v2_direct_fx_unavailable');
  $amount=round((float)$converted,2);
  $lines=[['kind'=>'base','tour_id'=>$c['tour_id'],'rate_id'=>null,'source'=>'direct','amount'=>$amount,'currency'=>$currency,'unit'=>$unit,'quantity'=>$extended['quantity']]];
  $total=$amount;
  foreach($c['extras'] as $extra){
   $line=shared_product_extra_source_quote($c['tour_id'],$extra,$c['audience'],$c['occupancy'],array_merge($c['quantities'],$extra['quantities']),$c['travel_date'],$currency);
   if(is_wp_error($line))return $line;
   $total+=(float)$line['amount'];$lines[]=$line;
  }
  if($c['booking_mode']==='private'){
   $policy=shared_product_private_price_rule($c['tour_id'],null);
   if(is_wp_error($policy))return $policy;
   $adjusted=round($amount*$policy['percent']/100,2);
   $total=round($total-$amount+$adjusted,2);
   $lines[0]['amount']=$adjusted;
   $lines[0]['private_pricing']=$policy+['base_amount'=>$amount,'adjusted_amount'=>$adjusted];
   $amount=$adjusted;
  }
  if(($c['travel_season']['term_id']??null)!==null)$lines[0]['season']=$c['travel_season'];
  if($c['package_pricing_apply']){
   $level=shared_product_package_level_preview($c['tour_id'],null,$c['selections']['package_term_id'],$amount);
   if(is_wp_error($level))return $level;
   $total=round($total-$amount+(float)$level['adjusted_amount'],2);
   $lines[0]['amount']=(float)$level['adjusted_amount'];$lines[0]['package_level']=$level;
  }
  $market_context=shared_product_market_current_context();
  $passports=$c['traveller_nationalities'];
  if(count($passports)===(int)$c['quantities']['persons']&&count(array_unique($passports))===1){
   $market_context['nationality']=reset($passports);
   $market_context['nationality_source']=count($passports)>1?'self_declared_passenger_list_all_same':'self_declared_passenger_list';
  }
  $market=shared_product_market_price_apply($c['tour_id'],null,$lines[0]['amount'],$currency,$market_context,(int)$c['quantities']['persons']);
  if(is_wp_error($market))return $market;
  if(!empty($market['applied'])){
   $before=(float)$lines[0]['amount'];
   $lines[0]['amount']=(float)$market['amount'];
   $lines[0]['market_pricing']=$market['receipt'];
   $total=round($total-$before+(float)$market['amount'],2);
  }
  if(function_exists('BookingExperience\pricing_preset_apply')){
   $legacy=['season'=>$lines[0]['season']??null,'private_pricing'=>$lines[0]['private_pricing']??null,
    'package_level'=>$lines[0]['package_level']??null,'market_pricing'=>$lines[0]['market_pricing']??null];
   $pr=BookingExperience\pricing_preset_apply($c['tour_id'],null,$lines[0]['amount'],$currency,
      array_merge($c,['legacy_receipts'=>array_filter($legacy)]));
   if(is_wp_error($pr))return $pr;
   if(!empty($pr['applied'])){
    $old=(float)$lines[0]['amount'];$lines[0]['amount']=(float)$pr['amount'];
    $lines[0]['strategy_presets']=$pr['receipts'];
    $total=round($total-$old+(float)$pr['amount'],2);
   }
  }
  return ['schema_version'=>2,'tour_id'=>$c['tour_id'],'currency'=>$currency,'total'=>round($total,2),'lines'=>$lines,'availability_status'=>'not_checked_price_only','inventory_required_for_price'=>false,'checkout_ready'=>false,'status'=>'preview_only_not_cart_authority','schedule_decision'=>$eligibility??null,'age_policy_receipt'=>$c['age_policy_receipt']];
 }
 $date=$c['travel_date'];
 if($date!==null){
  $start=(int)get_post_meta($c['rate_id'],'tour_rate_start_date',true);
  $end=(int)get_post_meta($c['rate_id'],'tour_rate_end_date',true);
  if(!shared_product_booking_v2_rate_covers_date($c['rate_id'],$date))return new WP_Error('booking_v2_rate_outside_dates');
 }
 $visual=[];
 foreach($c['selections'] as $key=>$id)if(in_array($key,['package_term_id','property_id','transport_id'],true))$visual[$key]=(string)$id;
 $base=shared_product_booking_configuration_quote($c['rate_id'],$c['audience'],$c['occupancy'],['quantities'=>$c['quantities'],'options'=>['visual_selection'=>$visual]]);
 if(is_wp_error($base))return $base;
 $currency=$base['currency'];$lines=[['kind'=>'base','tour_id'=>$c['tour_id'],'rate_id'=>$c['rate_id'],'source'=>'rate','amount'=>(float)$base['total'],'currency'=>$currency]];
 $sum=(float)$base['total'];
 $private_base=null;
 if($c['booking_mode']==='private'){
  $policy=shared_product_private_price_rule($c['tour_id'],$c['rate_id']);
  if(is_wp_error($policy))return $policy;
  $old=(float)($base['base_extended_amount']??0);
  if($old<=0)return new WP_Error('private_pricing_base_unavailable');
  $private_base=round($old*$policy['percent']/100,2);
  $sum=round($sum-$old+$private_base,2);
  $lines[0]['amount']=round($lines[0]['amount']-$old+$private_base,2);
  $lines[0]['private_pricing']=$policy+['base_amount'=>$old,'adjusted_amount'=>$private_base];
 }
 if(($c['travel_season']['term_id']??null)!==null)$lines[0]['season']=$c['travel_season'];
 if($c['tier_id']!==null){
  $base_only=(float)($base['base_extended_amount']??0);
  if($base_only<=0)return new WP_Error('booking_v2_tier_base_unavailable');
  $tier=shared_product_group_tier_preview($c['tour_id'],$c['rate_id'],$c['tier_id'],$c['group_size'],$base_only);
  if(is_wp_error($tier))return $tier;
  // Adjust only the base Rate; keep existing optional and modifier amounts untouched.
  $sum=round($sum-$base_only+(float)$tier['amount'],2);
  $lines[0]['amount']=$sum;$lines[0]['group_tier']=$tier+['base_amount'=>$base_only,'adjusted_amount'=>(float)$tier['amount']];
 }

 if($c['package_pricing_apply']){
  $current_base=$c['tier_id']!==null?(float)$tier['amount']:($private_base!==null?$private_base:(float)($base['base_extended_amount']??0));
  if($current_base<=0)return new WP_Error('booking_v2_package_base_unavailable');
  $level=shared_product_package_level_preview($c['tour_id'],$c['rate_id'],$c['selections']['package_term_id'],$current_base);
  if(is_wp_error($level))return $level;
  $sum=round($sum-$current_base+(float)$level['adjusted_amount'],2);
  $lines[0]['amount']=round((float)$lines[0]['amount']-$current_base+(float)$level['adjusted_amount'],2);
  $lines[0]['package_level']=$level;
 }
 foreach($c['extras'] as $extra){
  $line=shared_product_extra_source_quote($c['tour_id'],$extra,$c['audience'],$c['occupancy'],array_merge($c['quantities'],$extra['quantities']),$date,$currency);
  if(is_wp_error($line))return $line;
  $sum+=(float)$line['amount'];$lines[]=$line;
 }
 $market_context=shared_product_market_current_context();
 $passports=$c['traveller_nationalities'];
 if(count($passports)===(int)$c['quantities']['persons']&&count(array_unique($passports))===1){
  $market_context['nationality']=reset($passports);
  $market_context['nationality_source']=count($passports)>1?'self_declared_passenger_list_all_same':'self_declared_passenger_list';
 }
 $market=shared_product_market_price_apply($c['tour_id'],$c['rate_id'],$lines[0]['amount'],$currency,$market_context,(int)$c['quantities']['persons']);
 if(is_wp_error($market))return $market;
 if(!empty($market['applied'])){
  $before=(float)$lines[0]['amount'];
  $lines[0]['amount']=(float)$market['amount'];
  $lines[0]['market_pricing']=$market['receipt'];
  $sum=round($sum-$before+(float)$market['amount'],2);
 }
 if(function_exists('BookingExperience\pricing_preset_apply')){
  $legacy=['season'=>$lines[0]['season']??null,'private_pricing'=>$lines[0]['private_pricing']??null,
   'package_level'=>$lines[0]['package_level']??null,'group_tier'=>$lines[0]['group_tier']??null,
   'market_pricing'=>$lines[0]['market_pricing']??null];
  $pr=BookingExperience\pricing_preset_apply($c['tour_id'],$c['rate_id'],$lines[0]['amount'],$currency,
     array_merge($c,['legacy_receipts'=>array_filter($legacy)]));
  if(is_wp_error($pr))return $pr;
  if(!empty($pr['applied'])){
   $old=(float)$lines[0]['amount'];$lines[0]['amount']=(float)$pr['amount'];
   $lines[0]['strategy_presets']=$pr['receipts'];
   $sum=round($sum-$old+(float)$pr['amount'],2);
  }
 }
 return ['schema_version'=>2,'tour_id'=>$c['tour_id'],'currency'=>$currency,'total'=>round($sum,2),'lines'=>$lines,'availability_status'=>'not_checked_price_only','inventory_required_for_price'=>false,'checkout_ready'=>false,'status'=>'preview_only_not_cart_authority','schedule_decision'=>$eligibility??null,'age_policy_receipt'=>$c['age_policy_receipt']];
}
