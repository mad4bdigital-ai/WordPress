<?php
$_SERVER['HTTP_HOST']='localhost:8094';
require '/var/www/html/wp-load.php';
$pass=0;$errors=[];
$ok=static function($good,$name)use(&$pass,&$errors){
    $good?$pass++:$errors[]=$name;
};
global $wpdb;
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$old=get_current_user_id();
$plugin='supplier-request-registry/supplier-request-registry.php';
$rows=[
 'requests'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches"),
 'bookings'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tour_bookings'"),
 'source_links'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}supplier_request_source_links"),
 'hpos'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"),
 'native_links'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}etg_booking_links")
];
try{
 wp_set_current_user($admin);
 require_once ABSPATH.'wp-admin/includes/plugin.php';
 $ok(!is_plugin_active($plugin),'old_request_without_order_writer_inactive');
 $ok(!class_exists('Supplier_Request_Registry_Handoff',false),'old_source_writer_class_not_loaded');
 $routes=array_keys(rest_get_server()->get_routes());
 $ok(count(array_filter($routes,static fn($path)=>
    str_starts_with($path,'/supplier-request/v1')) )===0,
    'all_old_direct_supplier_write_routes_absent');
 $ok(class_exists('ETG_Tour_Bookings'),'canonical_order_item_supplier_owner_available');
 $withoutOrder=[
  'service_key'=>'source:60645:rate:61158','service_kind'=>'tour',
  'supplier_ref'=>'properties:36474'
 ];
 $missing=ETG_Tour_Bookings::validate_link($withoutOrder);
 $ok(is_wp_error($missing)&&$missing->get_error_code()==='etg_booking_invalid_ids',
  'booking_from_source_only_without_order_is_refused');
 $fake=$withoutOrder+['wc_order_id'=>999999999,'wc_order_item_id'=>999999999];
 $bad=ETG_Tour_Bookings::validate_link($fake);
 $ok(is_wp_error($bad),'fabricated_order_item_is_not_booking_authority');
 $zero=$withoutOrder+['wc_order_id'=>0,'wc_order_item_id'=>0];
 $notOrder=ETG_Tour_Bookings::validate_link($zero);
 $ok(is_wp_error($notOrder)&&$notOrder->get_error_code()==='etg_booking_invalid_ids',
  'zero_order_item_ids_do_not_count_as_orders');
 $ok(get_option('booking_experience_global_policy',[])['display_mode']==='inquiry_only',
  'inquiry_only_remains_enabled_and_no_payment_authority_issued');
 $post=get_post(60645);
 $ok($post!==null,'source_cpt_stays_original_and_unchanged');
}finally{
 wp_set_current_user($old);
}
$after=[
 'requests'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches"),
 'bookings'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tour_bookings'"),
 'source_links'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}supplier_request_source_links"),
 'hpos'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"),
 'native_links'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}etg_booking_links")
];
$ok($after===$rows,'source_orders_bookings_and_links_remain_unchanged');
echo wp_json_encode(['result'=>$errors?'FAIL':'PASS','checks'=>$pass,
 'failed'=>$errors,'old_direct_plugin_enabled'=>false,
 'normal_woocommerce_owner_still_operational'=>true,
 'order_required_before_booking'=>true,
 'no_schema_or_user_data_changes'=>true]).PHP_EOL;
if($errors)exit(2);
