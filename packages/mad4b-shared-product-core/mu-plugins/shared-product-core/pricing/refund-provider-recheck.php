<?php
/** Strict provider integration boundary: read-only and fail-closed in Lab. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_refund_provider_recheck($order_id,$reservation_hash){
 $order=wc_get_order(absint($order_id));if(!$order)return new WP_Error('provider_order_missing');
 if(!is_string($reservation_hash)||!preg_match('/^[a-f0-9]{64}$/D',$reservation_hash))return new WP_Error('provider_reservation_hash_invalid');
 $rows=$order->get_meta('_shared_refund_preview_reservations',true);
 if(!is_array($rows)||!isset($rows[$reservation_hash]))return new WP_Error('provider_reservation_missing');
 $gateway=$order->get_payment_method();
 // No live gateway connectors are enrolled. No network or state mutation is permitted.
 $adapters=apply_filters('shared_product_refund_readonly_provider_adapters',[]);
 if(!is_array($adapters)||!isset($adapters[$gateway])||!is_callable($adapters[$gateway]))return new WP_Error('provider_adapter_not_configured');
 $result=shared_product_refund_with_order_lock($order_id,static function($id)use($reservation_hash,$adapters,$gateway){
  $order=wc_get_order($id);
  $evidence=call_user_func($adapters[$gateway],$order,$reservation_hash);
  if(is_wp_error($evidence))return $evidence;
  if(!is_array($evidence))return new WP_Error('provider_adapter_invalid_response');
  $checked=shared_product_refund_provider_evidence_check($id,$reservation_hash,$evidence);
  if(is_wp_error($checked))return $checked;
  // Consistency does not establish authenticity. No automatic transition, write or refund.
  return ['status'=>'consistent_but_unverified','provider_refund_id'=>$checked['provider_refund_id'],'financial_action'=>false];
 });
 return $result;
}
