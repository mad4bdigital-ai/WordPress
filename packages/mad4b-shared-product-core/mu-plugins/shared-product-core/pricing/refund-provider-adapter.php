<?php
/** Provider evidence validator. No remote calls, webhooks or financial writes. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_refund_provider_evidence_check($order_id,$reservation_hash,$evidence){
 if(!is_array($evidence)||!is_string($reservation_hash)||!preg_match('/^[a-f0-9]{64}$/D',$reservation_hash))return new WP_Error('provider_invalid_evidence');
 $order=wc_get_order(absint($order_id));if(!$order)return new WP_Error('provider_order_missing');
 $envelope=shared_product_order_ledger_read($order_id);if(is_wp_error($envelope))return $envelope;
 $rows=$order->get_meta('_shared_refund_preview_reservations',true);
 if(!is_array($rows)||!isset($rows[$reservation_hash]))return new WP_Error('provider_reservation_missing');
 $reservation=$rows[$reservation_hash];$ledger=$envelope['payload']['ledger'];
 foreach(['provider','provider_refund_id','transaction_id','currency','amount_minor','status','verified'] as $field)if(!array_key_exists($field,$evidence))return new WP_Error('provider_evidence_field_missing');
 if($evidence['verified']!==true)return new WP_Error('provider_evidence_unverified');
 if(!is_string($evidence['provider'])||$evidence['provider']===''||$evidence['provider']!==$order->get_payment_method())return new WP_Error('provider_gateway_mismatch');
 if(!is_string($evidence['transaction_id'])||$evidence['transaction_id']===''||$evidence['transaction_id']!==$order->get_transaction_id())return new WP_Error('provider_transaction_mismatch');
 if(!is_string($evidence['provider_refund_id'])||$evidence['provider_refund_id']==='')return new WP_Error('provider_refund_id_missing');
 if($evidence['currency']!==$ledger['currency'])return new WP_Error('provider_currency_mismatch');
 if(!is_int($evidence['amount_minor'])||$evidence['amount_minor']!==$reservation['total_minor'])return new WP_Error('provider_amount_mismatch');
 if(!in_array($evidence['status'],['pending','succeeded','failed','unknown'],true))return new WP_Error('provider_status_invalid');
 // Caller assertion is not proof of authenticity: the adapter output is only structurally consistent.
 return ['consistent'=>true,'authenticity'=>'not_established','provider_refund_id'=>$evidence['provider_refund_id'],'status'=>$evidence['status'],'automatic_transition_allowed'=>false];
}

/** Lab-only evidence intake. Records consistency, never establishes authenticity. */
function shared_product_refund_provider_evidence_record($order_id,$reservation_hash,$event_id,$evidence){
 if(!is_string($event_id)||!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D',$event_id))return new WP_Error('provider_event_id_invalid');
 return shared_product_refund_with_order_lock($order_id,static function($id)use($reservation_hash,$event_id,$evidence){
  $result=shared_product_refund_provider_evidence_check($id,$reservation_hash,$evidence);
  if(is_wp_error($result))return $result;
  $order=wc_get_order($id);if(!$order)return new WP_Error('provider_order_missing');
  $registry=$order->get_meta('_shared_refund_provider_evidence_registry',true);
  if($registry===''||$registry===null)$registry=[];
  if(!is_array($registry))return new WP_Error('provider_registry_corrupt');
  $key=hash('sha256',$evidence['provider'].'|'.$event_id);
  $fingerprint=hash('sha256',wp_json_encode([$reservation_hash,$evidence]));
  if(isset($registry[$key])){
   if(($registry[$key]['fingerprint']??'')!==$fingerprint)return new WP_Error('provider_event_conflict');
   return ['recorded'=>true,'reused'=>true,'authenticity'=>'not_established'];
  }
  foreach($registry as $row){
   if(($row['provider']??'')===$evidence['provider']&&($row['refund_id']??'')===$evidence['provider_refund_id']&&($row['reservation_hash']??'')!==$reservation_hash)return new WP_Error('provider_refund_identity_conflict');
  }
  $registry[$key]=['provider'=>$evidence['provider'],'refund_id'=>$evidence['provider_refund_id'],'reservation_hash'=>$reservation_hash,'status'=>$evidence['status'],'fingerprint'=>$fingerprint,'created_at'=>gmdate('c'),'authenticity'=>'not_established'];
  $order->update_meta_data('_shared_refund_provider_evidence_registry',$registry);$order->save();
  return ['recorded'=>true,'reused'=>false,'authenticity'=>'not_established'];
 });
}

/** Explicitly report what remains untrusted; never infer gateway verification. */
function shared_product_refund_provider_readiness($order_id){
 $order=wc_get_order(absint($order_id));if(!$order)return new WP_Error('provider_order_missing');
 $rows=$order->get_meta('_shared_refund_preview_reservations',true);
 $registry=$order->get_meta('_shared_refund_provider_evidence_registry',true);
 if($rows===''||$rows===null)$rows=[];
 if($registry===''||$registry===null)$registry=[];
 if(!is_array($rows)||!is_array($registry))return new WP_Error('provider_diagnostics_corrupt');
 $result=[];
 foreach($rows as $hash=>$reservation){
  if(!is_array($reservation))return new WP_Error('provider_reservation_corrupt');
  $claims=[];
  foreach($registry as $entry){
   if(!is_array($entry))return new WP_Error('provider_registry_corrupt');
   if(($entry['reservation_hash']??null)===$hash)$claims[]=['provider_refund_id'=>$entry['refund_id']??null,'claimed_status'=>$entry['status']??null,'authenticity'=>'not_established'];
  }
  $result[]=['reservation_hash'=>$hash,'reservation_status'=>$reservation['status']??'unknown','claims'=>$claims,'gateway_status'=>'not_queried','verified_provider_evidence'=>false,'may_apply_financial_transition'=>false];
 }
 return ['order_id'=>$order->get_id(),'gateway'=>$order->get_payment_method(),'reservations'=>$result,'status'=>'diagnostics_only_no_gateway_calls'];
}
