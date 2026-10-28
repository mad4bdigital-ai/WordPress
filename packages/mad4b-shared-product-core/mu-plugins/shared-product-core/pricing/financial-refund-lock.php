<?php
/** Lab-only DB advisory lock for refund validation/preview; never triggers a refund. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_refund_with_order_lock($order_id,$callback,$timeout=0){
 global $wpdb;
 $id=absint($order_id);
 if(!$id||!is_callable($callback))return new WP_Error('refund_lock_invalid_input');
 // Advisory lock is connection scoped, released in finally. No implicit retry.
 $name='shared_refund_lab_'.$id;
 $acquired=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$name,max(0,min(3,(int)$timeout))));
 if((string)$acquired!=='1')return new WP_Error('refund_lock_busy');
 try{return $callback($id);}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));}
}
function shared_product_refund_locked_preview($order_id,$request){
 return shared_product_refund_with_order_lock($order_id,static function($id)use($request){
  $reconcile=shared_product_order_ledger_reconcile($id);if(is_wp_error($reconcile))return $reconcile;
  $history=shared_product_order_refund_reconcile($id);if(is_wp_error($history))return $history;
  $envelope=shared_product_order_ledger_read($id);if(is_wp_error($envelope))return $envelope;
  return shared_product_ledger_policy_refund($envelope['payload']['ledger'],$request,[
   'lines'=>$history['refunded_lines_minor'],'fee_minor'=>$history['refunded_fee_minor']]);
 });
}

/** Lab-only idempotent reservation of a refund preview. Never sends money. */
function shared_product_refund_reserve_preview($order_id,$idempotency_key,$request){
 if(!is_string($idempotency_key)||!preg_match('/^[A-Za-z0-9_-]{12,100}$/D',$idempotency_key)||!is_array($request))return new WP_Error('refund_invalid_idempotency_request');
 return shared_product_refund_with_order_lock($order_id,static function($id)use($idempotency_key,$request){
  $order=wc_get_order($id);if(!$order)return new WP_Error('ledger_order_missing');
  $envelope=shared_product_order_ledger_read($id);if(is_wp_error($envelope))return $envelope;
  $digest=hash('sha256',wp_json_encode(['snapshot'=>$envelope['sha256'],'request'=>$request]));
  $reservations=$order->get_meta('_shared_refund_preview_reservations',true);
  if($reservations===''||$reservations===null)$reservations=[];
  if(!is_array($reservations))return new WP_Error('refund_reservation_corrupt');
  $keyhash=hash('sha256',$idempotency_key);
  if(isset($reservations[$keyhash])){
   if(!hash_equals($reservations[$keyhash]['request_sha256']??'',$digest))return new WP_Error('refund_idempotency_conflict');
   return ['order_id'=>$id,'reservation'=>$reservations[$keyhash],'reused'=>true];
  }
  $reconciled=shared_product_order_ledger_reconcile($id);if(is_wp_error($reconciled))return $reconciled;
  $history=shared_product_order_refund_reconcile($id);if(is_wp_error($history))return $history;
  $reserved_lines=$history['refunded_lines_minor'];$reserved_fee=$history['refunded_fee_minor'];
  $linked=shared_product_refund_linked_reservations($id);if(is_wp_error($linked))return $linked;
  foreach($reservations as $reservation_hash=>$reservation){
   if(isset($linked[$reservation_hash]))continue;
   if(!in_array($reservation['status']??'',['reserved','processing','unknown','completed'],true))continue;
   foreach(($reservation['lines']??[]) as $line_id=>$minor)$reserved_lines[$line_id]=($reserved_lines[$line_id]??0)+$minor;
   $reserved_fee+=(int)($reservation['fee_minor']??0);
  }
  $preview=shared_product_ledger_policy_refund($envelope['payload']['ledger'],$request,['lines'=>$reserved_lines,'fee_minor'=>$reserved_fee]);
  if(is_wp_error($preview))return $preview;
  $reservation=['request_sha256'=>$digest,'snapshot_sha256'=>$envelope['sha256'],'lines'=>$request['lines'],'fee_minor'=>$request['fee_minor']??0,'status'=>'reserved','total_minor'=>$preview['total_refund_minor']];
  $reservations[$keyhash]=$reservation;
  $order->update_meta_data('_shared_refund_preview_reservations',$reservations);$order->save();
  return ['order_id'=>$id,'reservation'=>$reservation,'reused'=>false];
 });
}

/** Explicit, locked, laboratory-only reservation state transitions. */
function shared_product_refund_reservation_transition($order_id,$key,$target,$evidence=''){
 if(!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{12,100}$/D',$key))return new WP_Error('refund_invalid_key');
 $allowed=['reserved'=>['processing','released'],'processing'=>['unknown','completed','failed'],'unknown'=>['completed','failed'],'completed'=>[],'failed'=>[],'released'=>[]];
 if(!isset($allowed[$target]))return new WP_Error('refund_unknown_state');
 return shared_product_refund_with_order_lock($order_id,static function($id)use($key,$target,$evidence,$allowed){
  $order=wc_get_order($id);if(!$order)return new WP_Error('ledger_order_missing');
  $rows=$order->get_meta('_shared_refund_preview_reservations',true);
  $hash=hash('sha256',$key);
  if(!is_array($rows)||!isset($rows[$hash]))return new WP_Error('refund_reservation_missing');
  $current=$rows[$hash]['status']??'reserved';
  if($current===$target)return ['status'=>$current,'reused'=>true];
  if(!isset($allowed[$current])||!in_array($target,$allowed[$current],true))return new WP_Error('refund_transition_denied');
  if(in_array($target,['completed','failed'],true)&&(!is_string($evidence)||trim($evidence)===''))return new WP_Error('refund_transition_evidence_required');
  $rows[$hash]['status']=$target;
  $rows[$hash]['state_events'][]=['from'=>$current,'to'=>$target,'at'=>gmdate('c'),'evidence'=>sanitize_text_field($evidence)];
  $order->update_meta_data('_shared_refund_preview_reservations',$rows);$order->save();
  return ['status'=>$target,'reused'=>false];
 });
}

/** Read-only mapping from tagged WooCommerce refunds to reservation keys. Fail closed on ambiguity. */
function shared_product_refund_linked_reservations($order_id){
 $order=wc_get_order(absint($order_id));if(!$order)return new WP_Error('ledger_order_missing');
 $rows=$order->get_meta('_shared_refund_preview_reservations',true);
 if($rows===''||$rows===null)$rows=[];
 if(!is_array($rows))return new WP_Error('refund_reservation_corrupt');
 $linked=[];
 foreach($order->get_refunds() as $refund){
  $allocation=$refund->get_meta('_shared_ledger_refund_allocation',true);
  $hash=$allocation['reservation_hash']??null;
  if($hash===null)continue;
  if(!is_string($hash)||!isset($rows[$hash]))return new WP_Error('refund_orphan_reservation_link');
  if(isset($linked[$hash]))return new WP_Error('refund_duplicate_reservation_link');
  $row=$rows[$hash];$total=array_sum($allocation['lines']??[])+($allocation['fee_minor']??0);
  if(!is_array($allocation['lines']??null)||!is_int($allocation['fee_minor']??null)||
   ($row['snapshot_sha256']??'')!==($allocation['snapshot_sha256']??'')||
   ($row['lines']??null)!==$allocation['lines']||($row['fee_minor']??null)!==$allocation['fee_minor']||
   ($row['total_minor']??null)!==$total)return new WP_Error('refund_reservation_link_mismatch');
  if(($row['status']??'')!=='completed')return new WP_Error('refund_reservation_not_completed');
  $linked[$hash]=$refund->get_id();
 }
 return $linked;
}

/** Read-only recovery report. Age never authorizes releasing funds. */
function shared_product_refund_recovery_diagnostics($order_id,$stale_after_seconds=3600){
 $order=wc_get_order(absint($order_id));if(!$order)return new WP_Error('ledger_order_missing');
 $rows=$order->get_meta('_shared_refund_preview_reservations',true);
 if($rows===''||$rows===null)$rows=[];
 if(!is_array($rows))return new WP_Error('refund_reservation_corrupt');
 $linked=shared_product_refund_linked_reservations($order_id);if(is_wp_error($linked))return $linked;
 $history=shared_product_order_refund_reconcile($order_id);if(is_wp_error($history))return $history;
 $diagnostics=[];
 foreach($rows as $hash=>$row){
  if(!is_array($row)||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))return new WP_Error('refund_reservation_corrupt');
  $status=$row['status']??'reserved';
  $events=$row['state_events']??[];$last=is_array($events)&&$events?end($events):null;
  $at=is_array($last)?strtotime($last['at']??''):false;
  $stale=$at!==false&&(time()-$at)>=max(60,(int)$stale_after_seconds);
  $linked_refund=$linked[$hash]??null;
  $action=match($status){
   'completed'=>$linked_refund?'linked_verified':'reconcile_provider_and_refund_record',
   'processing','unknown'=>'manual_provider_lookup_no_release',
   'reserved'=>'confirm_never_dispatched_before_release',
   'failed'=>'failed_evidence_review',
   'released'=>'released_no_action',
   default=>'invalid_status_review'};
  $diagnostics[]=['reservation_hash'=>$hash,'status'=>$status,'stale'=>$stale,'linked_refund_id'=>$linked_refund,'next_action'=>$action,'automatic_release_allowed'=>false];
 }
 // Untagged refunds are untrusted and cannot be silently mapped to a reservation.
 $unlinked=0;foreach($order->get_refunds() as $refund){$a=$refund->get_meta('_shared_ledger_refund_allocation',true);if(!is_array($a)||empty($a['reservation_hash']))$unlinked++;}
 return ['order_id'=>(int)$order_id,'reservations'=>$diagnostics,'unlinked_refund_count'=>$unlinked,'status'=>'read_only_recovery_diagnostics'];
}
