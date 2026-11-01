<?php
/** Explicit lab-only HPOS-compatible snapshot persistence; no payment or refund side effects. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_order_ledger_save($order,$ledger){
 if(!($order instanceof WC_Order)||$order->get_id()<1)return new WP_Error('ledger_invalid_order');
 $existing=$order->get_meta('_shared_financial_snapshot',true);
 if($existing!=='')return new WP_Error('ledger_snapshot_already_exists');
 if($order->is_paid())return new WP_Error('ledger_order_already_paid');
 $prepared=shared_product_ledger_snapshot_prepare($ledger);
 if(is_wp_error($prepared))return $prepared;
 $envelope=['payload'=>$prepared['payload'],'sha256'=>$prepared['sha256']];
 $order->update_meta_data('_shared_financial_snapshot',$envelope);
 $order->save();
 $check=wc_get_order($order->get_id());
 if(!$check)return new WP_Error('ledger_order_readback_missing');
 $stored=$check->get_meta('_shared_financial_snapshot',true);
 $verified=shared_product_ledger_snapshot_verify($stored);
 if(is_wp_error($verified))return $verified;
 return ['order_id'=>$order->get_id(),'sha256'=>$prepared['sha256'],'verified'=>true,'status'=>'stored_snapshot_only_no_payment'];
}
function shared_product_order_ledger_read($order_id){
 $order=wc_get_order(absint($order_id));if(!$order)return new WP_Error('ledger_order_missing');
 $envelope=$order->get_meta('_shared_financial_snapshot',true);
 if(!$envelope)return new WP_Error('ledger_snapshot_missing');
 $verified=shared_product_ledger_snapshot_verify($envelope);
 if(is_wp_error($verified))return $verified;
 return $envelope;
}

/** Validate the persisted WC order against the frozen ledger, with no writes. */
function shared_product_order_ledger_reconcile($order_id){
 $order=wc_get_order(absint($order_id));if(!$order)return new WP_Error('ledger_order_missing');
 $envelope=shared_product_order_ledger_read($order_id);if(is_wp_error($envelope))return $envelope;
 $ledger=$envelope['payload']['ledger'];
 if($order->get_currency()!==$ledger['currency'])return new WP_Error('ledger_order_currency_mismatch');
 $factor=10**$ledger['currency_decimals'];
 $order_total=(int)round((float)$order->get_total()*$factor);
 if($order_total!==$ledger['customer_total_minor'])return new WP_Error('ledger_order_total_mismatch');
 $ledger_ids=[];foreach($ledger['lines'] as $l)$ledger_ids[$l['id']]=$l;
 $order_ids=[];
 foreach($order->get_items('line_item') as $item){
  $id=(string)$item->get_meta('_shared_ledger_line_id',true);
  if($id===''||isset($order_ids[$id])||!isset($ledger_ids[$id]))return new WP_Error('ledger_order_line_identity_invalid');
  $order_ids[$id]=(int)round(((float)$item->get_total()+(float)$item->get_total_tax())*$factor);
  if($order_ids[$id]!==$ledger_ids[$id]['payable_minor'])return new WP_Error('ledger_order_line_amount_mismatch');
  if((int)round((float)$item->get_total_tax()*$factor)!==$ledger_ids[$id]['tax_minor'])return new WP_Error('ledger_order_line_tax_mismatch');
  if((int)round((float)$item->get_subtotal()*$factor)!==$ledger_ids[$id]['net_minor'])return new WP_Error('ledger_order_line_subtotal_mismatch');
  if((int)round(((float)$item->get_subtotal()-(float)$item->get_total())*$factor)!==$ledger_ids[$id]['discount_minor'])return new WP_Error('ledger_order_line_discount_mismatch');
 }
 if(count($order_ids)!==count($ledger_ids))return new WP_Error('ledger_order_line_count_mismatch');
 $expected_fee=(int)$ledger['customer_payment_fee_minor'];
 $expected_tax=(int)$ledger['payment_fee_tax_minor'];
 $fee_items=$order->get_items('fee');
 if($expected_fee===0 && $expected_tax===0 && count($fee_items)>0)return new WP_Error('ledger_unexpected_fee_item');
 if($expected_fee!==0 || $expected_tax!==0){
  if(count($fee_items)!==1)return new WP_Error('ledger_fee_item_count_mismatch');
  $fee=reset($fee_items);
  if((string)$fee->get_meta('_shared_ledger_fee_type',true)!=='payment')return new WP_Error('ledger_fee_identity_mismatch');
  if((int)round((float)$fee->get_total()*$factor)!==$expected_fee)return new WP_Error('ledger_payment_fee_mismatch');
  if((int)round((float)$fee->get_total_tax()*$factor)!==$expected_tax)return new WP_Error('ledger_payment_fee_tax_mismatch');
 }

 return ['order_id'=>$order->get_id(),'reconciled'=>true,'sha256'=>$envelope['sha256'],'status'=>'read_only_reconciled_no_payment'];
}
/** Refund history inventory; never treat unlabelled WooCommerce refunds as validated ledger allocations. */
function shared_product_order_refund_inventory($order_id){
 $order=wc_get_order(absint($order_id));if(!$order)return new WP_Error('ledger_order_missing');
 $refunds=[];
 foreach($order->get_refunds() as $refund){
  $refunds[]=['refund_id'=>$refund->get_id(),'amount'=>(string)$refund->get_amount(),'date'=>$refund->get_date_created()?$refund->get_date_created()->date('c'):null,'ledger_allocation_present'=>(bool)$refund->get_meta('_shared_ledger_refund_allocation',true)];
 }
 return ['order_id'=>$order->get_id(),'refunds'=>$refunds,'count'=>count($refunds),'status'=>'inventory_only_not_authoritative_balance'];
}

/** Read-only authoritative refund reconciliation: never guess allocations for untagged refunds. */
function shared_product_order_refund_reconcile($order_id){
 $order=wc_get_order(absint($order_id));if(!$order)return new WP_Error('ledger_order_missing');
 $envelope=shared_product_order_ledger_read($order_id);if(is_wp_error($envelope))return $envelope;
 $ledger=$envelope['payload']['ledger'];$factor=10**$ledger['currency_decimals'];
 $limits=[];foreach($ledger['lines'] as $l)$limits[$l['id']]=$l['payable_minor'];
 $used=array_fill_keys(array_keys($limits),0);$fee_used=0;$records=[];$seen=[];
 foreach($order->get_refunds() as $refund){
  $id=$refund->get_id();if(isset($seen[$id]))return new WP_Error('refund_duplicate_id');$seen[$id]=true;
  $allocation=$refund->get_meta('_shared_ledger_refund_allocation',true);
  if(!is_array($allocation)||!is_array($allocation['lines']??null)||!isset($allocation['fee_minor'])||!is_int($allocation['fee_minor']))return new WP_Error('refund_allocation_missing');
  if(($allocation['snapshot_sha256']??'')!==$envelope['sha256'])return new WP_Error('refund_snapshot_identity_mismatch');
  $amount=(int)round((float)$refund->get_amount()*$factor);$subtotal=0;
  foreach($allocation['lines'] as $line_id=>$minor){
   if(!isset($limits[$line_id])||!is_int($minor)||$minor<0)return new WP_Error('refund_invalid_line_allocation');
   $used[$line_id]+=$minor;if($used[$line_id]>$limits[$line_id])return new WP_Error('refund_exceeds_line_balance');$subtotal+=$minor;
  }
  $fee=$allocation['fee_minor'];if($fee<0)return new WP_Error('refund_invalid_fee_allocation');
  $fee_used+=$fee;
  if($fee_used>$ledger['customer_payment_fee_minor']+$ledger['payment_fee_tax_minor'])return new WP_Error('refund_exceeds_fee_balance');
  if($subtotal+$fee!==$amount)return new WP_Error('refund_allocation_amount_mismatch');
  $records[]=['refund_id'=>$id,'amount_minor'=>$amount,'lines'=>$allocation['lines'],'fee_minor'=>$fee];
 }
 $refunded=array_sum($used)+$fee_used;
 if($refunded>$ledger['customer_total_minor'])return new WP_Error('refund_exceeds_order_balance');
 return ['order_id'=>$order->get_id(),'refunds'=>$records,'refunded_lines_minor'=>$used,'refunded_fee_minor'=>$fee_used,'remaining_total_minor'=>$ledger['customer_total_minor']-$refunded,'snapshot_sha256'=>$envelope['sha256'],'status'=>'read_only_refund_reconciliation'];
}
