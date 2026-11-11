<?php
/** Local-only read-only mapping; no hooks, order writes or inventory transitions. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
function etg_lab_order_inventory_readiness($order_id){
 if(!function_exists('wc_get_order'))return new WP_Error('lab_woocommerce_unavailable');
 $order=wc_get_order(absint($order_id));if(!$order)return new WP_Error('lab_order_not_found');
 $status=$order->get_status();
 $candidate=match($status){'pending','on-hold'=>'hold_review','processing','completed'=>'confirm_review','cancelled','failed','refunded'=>'release_or_refund_review',default=>'manual_review'};
 return ['order_id'=>$order->get_id(),'order_status'=>$status,'inventory_action_candidate'=>$candidate,'scope'=>'read_only',
  'financial_and_stock_binding'=>'not_certified','authority'=>'none','checkout_ready'=>false];
}
