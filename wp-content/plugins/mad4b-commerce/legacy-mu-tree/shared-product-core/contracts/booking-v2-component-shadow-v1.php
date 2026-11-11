<?php
/** Read-only projection of a server-created Booking v2 quote. Never changes the original. */
defined('ABSPATH') || exit;
if(!function_exists('mad4b_booking_v2_component_shadow_v1')){
function mad4b_booking_v2_component_shadow_v1(array $quote){
 if(($quote['schema_version']??null)!==2 ||
    ($quote['status']??null)!=='preview_only_not_cart_authority' ||
    ($quote['checkout_ready']??null)!==false ||
    !is_array($quote['lines']??null) || !$quote['lines'] ||
    !is_numeric($quote['total']??null))
    return new WP_Error('booking_component_shadow_quote_untrusted');
 $currency=$quote['currency']??null;
 if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))
    return new WP_Error('booking_component_shadow_currency_invalid');
 if(!function_exists('shared_product_component_contract_v1'))
    return new WP_Error('booking_component_shadow_contract_missing');
 $components=[];$sum=0.0;
 foreach($quote['lines'] as $index=>$line){
   if(!is_array($line)||!isset($line['amount'])||!is_numeric($line['amount'])||
      !is_finite((float)$line['amount'])||(float)$line['amount']<0)
      return new WP_Error('booking_component_shadow_line_invalid');
   if(isset($line['currency'])&&$line['currency']!==$currency)
      return new WP_Error('booking_component_shadow_currency_mismatch');
   $kind=$line['kind']??'';
   if($index===0 && $kind!=='base')return new WP_Error('booking_component_shadow_base_missing');
   if($index>0 && $kind!=='extra')return new WP_Error('booking_component_shadow_line_unsupported');
   $source=$line['source']??($index===0?'':null);
   if(!in_array($source,['direct','rate'],true))return new WP_Error('booking_component_shadow_source_invalid');
   $rate=isset($line['rate_id'])?(int)$line['rate_id']:null;
   if($source==='rate'&&(!$rate||$rate<1))return new WP_Error('booking_component_shadow_rate_missing');
   $tour=(int)($line['tour_id']??($index===0?($quote['tour_id']??0):0));
   if($tour<1)return new WP_Error('booking_component_shadow_product_invalid');
   $component=shared_product_component_contract_v1([
      'component_key'=>($index===0?'booking_base:':'booking_extra:').$index.':'.$tour,
      'kind'=>$index===0?'tour':'extra_tour',
      'product_id'=>$tour,'rate_id'=>$rate,
      'source'=>['type'=>$source,'rate_id'=>$rate],
      'inclusion_mode'=>$index===0?'base':'optional',
      'price_unit'=>$line['unit']??null,
      'amount'=>$line['amount'],'currency'=>$currency,
      'readiness'=>'preview_priced'
   ]);
   if(is_wp_error($component))return $component;
   $components[]=$component;$sum+=(float)$line['amount'];
 }
 $subtotal=round($sum,2);$total=round((float)$quote['total'],2);
 return [
   'contract'=>'mad4b.booking-v2-component-shadow.v1','tour_id'=>(int)$quote['tour_id'],
   'components'=>$components,'currency'=>$currency,
   'line_sum'=>$subtotal,'original_total'=>$total,
   'parity_status'=>abs($subtotal-$total)<=0.01?'match':'mismatch_requires_adjustment_trace',
   'checkout_authority'=>false,'original_quote_unchanged'=>true,
   'status'=>'shadow_only_not_checkout_authority'
 ];
}
}
