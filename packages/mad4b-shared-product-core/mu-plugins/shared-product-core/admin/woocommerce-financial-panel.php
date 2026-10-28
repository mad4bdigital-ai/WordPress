<?php
/** Read-only financial diagnostics on the native WooCommerce order screen (HPOS + legacy). Lab only. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
add_action('add_meta_boxes',static function(){
 if(!current_user_can('manage_woocommerce'))return;
 $screen=class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()?'woocommerce_page_wc-orders':'shop_order';
 foreach(array_unique([$screen,'shop_order']) as $target){
  add_meta_box('shared_financial_order_panel',__('Financial Ledger & Refund Diagnostics','shared-product'),'shared_product_woocommerce_financial_order_panel',$target,'normal','default');
 }
},30);
function shared_product_woocommerce_financial_order_panel($object){
 if(!current_user_can('manage_woocommerce'))return;
 $order=$object instanceof WC_Order?$object:(($object instanceof WP_Post)?wc_get_order($object->ID):null);
 if(!$order){echo '<p>Order not available.</p>';return;}
 $id=$order->get_id();$envelope=shared_product_order_ledger_read($id);
 echo '<div class="shared-financial-order-panel"><p><strong>Read-only laboratory diagnostics</strong> — no payments or refunds can be initiated here.</p>';
 if(is_wp_error($envelope)){echo '<p>Snapshot: <code>'.esc_html($envelope->get_error_code()).'</code></p></div>';return;}
 $ledger=$envelope['payload']['ledger'];
 $checks=['Order reconciliation'=>shared_product_order_ledger_reconcile($id),'Refund reconciliation'=>shared_product_order_refund_reconcile($id),'Recovery diagnostics'=>shared_product_refund_recovery_diagnostics($id),'Provider evidence readiness'=>shared_product_refund_provider_readiness($id)];
 echo '<p><strong>Order:</strong> '.esc_html((string)$id).' | <strong>Currency:</strong> '.esc_html($ledger['currency']).' | <strong>Ledger total (minor units):</strong> '.esc_html((string)$ledger['customer_total_minor']).'</p>';
 echo '<p><strong>Snapshot SHA-256:</strong> <code>'.esc_html($envelope['sha256']).'</code></p>';
 echo '<table class="widefat striped"><thead><tr><th>Check</th><th>Result</th></tr></thead><tbody>';
 foreach($checks as $label=>$result){$value=is_wp_error($result)?'Needs review: '.$result->get_error_code():($label==='Provider evidence readiness'?'Available — provider NOT verified':'Checks completed — inspect details');echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html($value).'</td></tr>';}
 echo '</tbody></table>';
 $recovery=$checks['Recovery diagnostics'];
 if(!is_wp_error($recovery)){
  echo '<h4>Reservation status</h4><table class="widefat striped"><thead><tr><th>State</th><th>Next action</th><th>Linked refund</th></tr></thead><tbody>';
  foreach($recovery['reservations'] as $row)echo '<tr><td>'.esc_html($row['status']).'</td><td>'.esc_html($row['next_action']).'</td><td>'.esc_html((string)($row['linked_refund_id']??'—')).'</td></tr>';
  echo '</tbody></table><p>Unlinked refund records: '.esc_html((string)$recovery['unlinked_refund_count']).'</p>';
 }
 if(isset($_GET['shared_provider_recheck_result']))echo '<p><strong>Recheck result:</strong> '.esc_html(sanitize_text_field(wp_unslash($_GET['shared_provider_recheck_result']))).'</p>';
 echo '<h4>Payment Provider Diagnostics</h4>';
 $provider=$order->get_payment_method();
 echo '<p><strong>Gateway:</strong> '.esc_html($provider?:'Not selected').' | <strong>Transaction ID:</strong> '.esc_html($order->get_transaction_id()?:'Not available').'</p>';
 $reservations=$order->get_meta('_shared_refund_preview_reservations',true);
 if(is_array($reservations)&&$reservations){
  echo '<table class="widefat striped"><thead><tr><th>Reservation</th><th>Status</th><th>Provider Refund ID</th><th>Evidence</th></tr></thead><tbody>';
  foreach($reservations as $hash=>$reservation){
   if(!is_array($reservation))continue;
   $events=$reservation['state_events']??[];$last=is_array($events)&&$events?end($events):null;
   $evidence=is_array($last)?($last['evidence']??''):'';
   // A text note is an audit hint, never certified payment-provider evidence.
   $form='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="shared_product_refund_recheck"><input type="hidden" name="order_id" value="'.esc_attr((string)$id).'"><input type="hidden" name="reservation_hash" value="'.esc_attr((string)$hash).'">'.wp_nonce_field('shared_product_recheck_'.$id,'_wpnonce',true,false).'<button type="submit" class="button">Recheck Provider Status</button></form>';
   echo '<tr><td><code>'.esc_html(substr((string)$hash,0,12)).'…</code></td><td>'.esc_html($reservation['status']??'unknown').'</td><td>'.esc_html($reservation['provider_refund_id']??'Not recorded').'</td><td>'.esc_html($evidence?'Unverified operator note':'Not available').$form.'</td></tr>';
  }
  echo '</tbody></table>';
 }else echo '<p>No refund reservations recorded.</p>';
 $evidence_registry=$order->get_meta('_shared_refund_provider_evidence_registry',true);
 echo '<h4>Provider evidence intake (unverified)</h4>';
 if(is_array($evidence_registry)&&$evidence_registry){
  echo '<table class="widefat striped"><thead><tr><th>Gateway</th><th>Refund ID</th><th>Claimed status</th><th>Authenticity</th></tr></thead><tbody>';
  foreach($evidence_registry as $record){if(!is_array($record))continue;
   echo '<tr><td>'.esc_html($record['provider']??'').'</td><td>'.esc_html($record['refund_id']??'').'</td><td>'.esc_html($record['status']??'').'</td><td>Not established</td></tr>';
  }
  echo '</tbody></table>';
 }else echo '<p>No provider evidence recorded.</p>';
 echo '<p><strong>Provider status: NOT QUERIED / NOT VERIFIED.</strong> Logged claims never authorize payment-state transitions. This panel cannot initiate refunds.</p>';
 echo '</div>';
}
