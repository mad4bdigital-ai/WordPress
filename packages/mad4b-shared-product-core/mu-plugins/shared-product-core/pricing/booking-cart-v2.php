<?php
/** Validate a WooCommerce-native multi-booking cart draft. No cart writes. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_booking_v2_cart_draft($configurations) {
 if (!is_array($configurations)||!array_is_list($configurations)||count($configurations)<1||count($configurations)>20)
  return new WP_Error('booking_v2_cart_lines_invalid');
 $lines=[];
 foreach($configurations as $configuration){
  $native=shared_product_booking_v2_cart_identity($configuration,(int)($configuration['tour_id']??0));
  if(is_wp_error($native))return $native;
  if(!in_array((string)get_post_meta($native['product_id'],'tour_mode',true),['tour','cruise_tour','activity'],true))
   return new WP_Error('booking_v2_independent_product_mode_invalid');
  $lines[]=['product_id'=>$native['product_id'],'quantity'=>1,'configuration'=>$native['configuration']];
 }
 return ['schema_version'=>2,'cart_lines'=>$lines,'line_count'=>count($lines),'status'=>'draft_only_no_cart_write'];
}

/** Compose independent server-priced booking lines without touching WC Cart/Checkout. */
function shared_product_booking_v2_cart_quote_preview($configurations){
 $draft=shared_product_booking_v2_cart_draft($configurations);
 if(is_wp_error($draft))return $draft;
 $currency=null;$sum=0;$lines=[];
 foreach($draft['cart_lines'] as $index=>$line){
  // Revalidate the original public shape, not the normalized internal draft shape.
  $quote=shared_product_booking_v2_preview($configurations[$index]);
  if(is_wp_error($quote))return $quote;
  if($currency===null)$currency=$quote['currency'];
  if($currency!==$quote['currency'])return new WP_Error('booking_v2_cart_currency_mismatch');
  if(!is_numeric($quote['total'])||(float)$quote['total']<0)return new WP_Error('booking_v2_cart_invalid_amount');
  $sum+=(float)$quote['total'];
  $lines[]=['product_id'=>$line['product_id'],'cart_quantity'=>1,'booking_quote'=>$quote];
 }
 return ['currency'=>$currency,'total'=>round($sum,2),'lines'=>$lines,'status'=>'preview_only_no_cart_mutation'];
}
