<?php
/** ETG Local Lab: isolated booking/order binding, no WooCommerce mutation hooks. */
if(!defined('ABSPATH')||wp_get_environment_type()!=='local')return;
function etg_lab_woo_booking_binding_install(){global $wpdb;$t=$wpdb->prefix.'etg_lab_booking_order_bindings';$collate=$wpdb->get_charset_collate();$sql="CREATE TABLE IF NOT EXISTS `$t` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, group_token VARCHAR(60) NOT NULL, order_id BIGINT UNSIGNED NOT NULL, resource_key VARCHAR(150) NOT NULL, created_at DATETIME NOT NULL, UNIQUE KEY one_binding_per_group (group_token), KEY order_lookup (order_id)) ENGINE=InnoDB $collate";return $wpdb->query($sql)===false?new WP_Error('booking_binding_schema_failure',$wpdb->last_error):true;}
function etg_lab_woo_booking_binding_preview($group_token,$order_id){
 global $wpdb;$groups=$wpdb->prefix.'etg_lab_inventory_groups';$bindings=$wpdb->prefix.'etg_lab_booking_order_bindings';
 if(!is_string($group_token)||!preg_match('/^[A-Za-z0-9_-]{12,60}$/',$group_token)||!is_numeric($order_id)||absint($order_id)<1)return new WP_Error('booking_binding_invalid_input');
 $g=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$groups` WHERE group_token=%s",$group_token),ARRAY_A);if(!$g)return new WP_Error('booking_group_missing');
 if(!function_exists('wc_get_order'))return new WP_Error('woocommerce_unavailable');
 $order=wc_get_order(absint($order_id));if(!$order)return new WP_Error('woo_order_missing');
 $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$bindings` WHERE group_token=%s",$group_token),ARRAY_A);
 if($existing&&($existing['group_token']!==$group_token||(int)$existing['order_id']!==absint($order_id)))return new WP_Error('booking_binding_conflict');
 $status=$order->get_status();$paid=$order->is_paid();
 $transaction_id=(string)$order->get_transaction_id();$paid_date=$order->get_date_paid();
 $payment_evidence=($paid && $paid_date!==null && $transaction_id!=='' && $order->get_meta('_etg_lab_fixture')!=='yes');
 return ['group_token'=>$group_token,'order_id'=>absint($order_id),'resource_key'=>$g['resource_key'],'status'=>$status,'paid_status'=>$paid,'payment_evidence_present'=>$payment_evidence,'proposed_action'=>$payment_evidence&&in_array($status,['processing','completed'],true)?'confirm_review':'payment_evidence_review','existing_binding'=>(bool)$existing,'write_authorized'=>false,'checkout_ready'=>false];
}

/** Runtime policy: portable across environments; commercial writes fail closed until certified. */
function etg_accommodation_binding_policy($group_token,$order_id){
 $order=function_exists('wc_get_order')?wc_get_order(absint($order_id)):false;
 if(!$order)return new WP_Error('woo_order_missing');
 // Fixture authority is isolated to test records, not the binding engine itself.
 if(PHP_SAPI==='cli' && wp_get_environment_type()==='local' && is_string($group_token)
    && preg_match('/^etg_bind_fixture_[A-Za-z0-9_-]{1,40}$/',$group_token)
    && $order->get_created_via()==='etg_lab_qa' && $order->get_meta('_etg_lab_fixture')==='yes')return ['authority'=>'fixture'];
 // Future production/staging enrollment requires audited capability, payment verification
 // and explicit readiness. Do not treat WP environment as authorization.
 return new WP_Error('booking_binding_authority_not_certified');
}
/** Environment-neutral transactional core; access is always subject to server-side policy. */
function etg_accommodation_bind_group($group_token,$order_id){
 if(!is_string($group_token)||!preg_match('/^[A-Za-z0-9_-]{12,60}$/',$group_token)||!is_numeric($order_id)||absint($order_id)<1)return new WP_Error('booking_binding_invalid_input');
 $policy=etg_accommodation_binding_policy($group_token,$order_id);
 if(is_wp_error($policy))return $policy;
 global $wpdb;$groups=$wpdb->prefix.'etg_lab_inventory_groups';$bindings=$wpdb->prefix.'etg_lab_booking_order_bindings';
 if($wpdb->query('START TRANSACTION')===false)return new WP_Error('binding_transaction_unavailable');
 try{
  $g=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$groups` WHERE group_token=%s FOR UPDATE",$group_token),ARRAY_A);
  if(!$g)throw new Exception('booking_group_missing');
  $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$bindings` WHERE group_token=%s FOR UPDATE",$group_token),ARRAY_A);
  if($existing){
   if((int)$existing['order_id']!==(int)$order_id||$existing['resource_key']!==$g['resource_key'])throw new Exception('booking_binding_conflict');
   if($wpdb->query('COMMIT')===false)throw new Exception('binding_commit_failed');
   return ['group_token'=>$group_token,'order_id'=>(int)$order_id,'idempotent'=>true,'inventory_mutated'=>false];
  }
  if($wpdb->insert($bindings,['group_token'=>$group_token,'order_id'=>(int)$order_id,'resource_key'=>$g['resource_key'],'created_at'=>gmdate('Y-m-d H:i:s')])!==1)throw new Exception('booking_binding_insert_failed');
  if($wpdb->query('COMMIT')===false)throw new Exception('binding_commit_failed');
  return ['group_token'=>$group_token,'order_id'=>(int)$order_id,'idempotent'=>false,'inventory_mutated'=>false];
 }catch(Throwable $e){$wpdb->query('ROLLBACK');return new WP_Error($e->getMessage());}
}

/** Backward-compatible test facade. */
function etg_lab_woo_bind_fixture_group($group_token,$order_id){return etg_accommodation_bind_group($group_token,$order_id);}
