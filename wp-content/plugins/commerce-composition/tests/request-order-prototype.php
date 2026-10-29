<?php
$_SERVER['HTTP_HOST']='localhost:8094';
require '/var/www/html/wp-load.php';
global $wpdb;
class QABookingRequest extends WC_Order {
    public function get_type(){return 'booking_request';}
}
add_filter('wc_order_statuses',static function($statuses){
 $statuses['wc-request-review']='Request review';
 return $statuses;
});
$registered=wc_register_order_type('booking_request',[
 'label'=>'Booking Requests',
 'public'=>false,'show_ui'=>false,
 'exclude_from_order_count'=>true,'exclude_from_order_views'=>true,
 'exclude_from_order_webhooks'=>true,'exclude_from_order_reports'=>true,
 'exclude_from_order_sales_reports'=>true,
 'add_order_meta_boxes'=>false,'class_name'=>'QABookingRequest'
]);
register_post_status('wc-request-review',[
 'label'=>'Request review','public'=>false,'internal'=>true,
 'show_in_admin_status_list'=>false,'show_in_admin_all_list'=>false
]);
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders");
$checks=0;$fail=[];$check=static function($v,$n)use(&$checks,&$fail){$checks++;if(!$v)$fail[]=$n;};
$check($registered===true,'separate_order_type_registered');
$check(in_array('booking_request',wc_get_order_types(),true),'woocommerce_knows_booking_request');
$wpdb->query('START TRANSACTION');
$id=0;
try{
 $order=new QABookingRequest();
 $order->set_status('request-review');
 $order->set_created_via('request_intake');
 $order->add_meta_data('_nonfinancial_request', 'yes',true);
 $order->add_meta_data('_pricing_state','pending_approval',true);
 $order->add_meta_data('_request_components',wp_json_encode([
  ['source_id'=>60645,'components'=>[['source_id'=>36474]]]
 ]),true);
 $id=$order->save();
 $row=$wpdb->get_row($wpdb->prepare(
  "SELECT id,type,status,total_amount FROM {$wpdb->prefix}wc_orders WHERE id=%d",$id),ARRAY_A);
 $check($id>0,'request_order_saved_via_wc_data_store');
 $check(($row['type']??null)==='booking_request','hpos_type_separate_from_shop_order');
 $check(($row['status']??null)==='wc-request-review','nonfinancial_custom_status');
 $check($order->get_meta('_pricing_state')==='pending_approval','price_pending_marker');
 $check($order->get_items('line_item')===[],'no_fake_woocommerce_paid_line');
 $restored=wc_get_order($id);
 $check($restored instanceof QABookingRequest,'factory_restores_specialized_request_class');
 $check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===($before+1),'one_temporary_hpos_request');
}finally{
 $wpdb->query('ROLLBACK');
}
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$before,'qa_transaction_rolled_back');
echo wp_json_encode(['result'=>$fail?'FAIL':'PASS','checks'=>$checks,'failed'=>$fail,
'prototype_order_id'=>$id,'row'=>$row??null,'hpos_original_count'=>$before,
 'qa_rollback'=>true]).PHP_EOL;
if($fail)exit(2);
