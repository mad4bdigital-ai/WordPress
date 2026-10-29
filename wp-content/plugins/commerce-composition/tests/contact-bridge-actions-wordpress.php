<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\ContactBridge;
use Commerce\Composition\ContactBridgeUi;
use Commerce\Composition\ContactBridgeAdmin;
use Commerce\Composition\WorkflowActions;
use Commerce\Composition\WorkflowApi;
global $wpdb;
$count=0;$failed=[];$check=static function($v,$name)use(&$count,&$failed){
 $v?$count++:$failed[]=$name;
};
$contact=(int)$wpdb->get_var("SELECT MIN(id) FROM {$wpdb->prefix}fc_subscribers");
$booking=(int)$wpdb->get_var("SELECT MAX(m.post_id) FROM {$wpdb->postmeta} m
 JOIN {$wpdb->posts} p ON p.ID=m.post_id
 WHERE p.post_type='tour_bookings' AND m.meta_key='tour_booking_property'");
$inquiry=(int)$wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}fluentform_submissions
 WHERE form_id IN (3,6)");
$table=$wpdb->prefix.'commerce_contact_links';
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
$other=[
 'contacts'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers"),
 'orders'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"),
 'forms'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions"),
];
$admin=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
$user=get_current_user_id();
$check($contact>0&&$booking>0&&$inquiry>0,'all_original_source_records_exist');
$check(!in_array('booking_request',wc_get_order_types(),true),'booking_request_not_woocommerce_order');
$check(ContactBridge::ready(),'registry_installed');
$adminHook=has_action('rest_api_init',[ContactBridgeUi::class,'routes']);
$check($adminHook!==false,'crm_bridge_rest_routes_are_registered');
$check(has_action('rest_api_init',[WorkflowApi::class,'routes'])!==false,
 'workflow_rest_routes_are_registered');
wp_set_current_user(0);
$anonymous=new WP_REST_Request('POST','/commerce-composition/v1/workflow-actions');
$anonymous->set_param('action_key','link_contact_record');
$anonymous->set_param('payload',[
 'contact_id'=>$contact,'source_key'=>'supplier_booking',
 'source_id'=>$booking,'role'=>'requester'
]);
$denied=rest_do_request($anonymous);
$check(in_array($denied->get_status(),[401,403],true),'unauthenticated_action_denied');
wp_set_current_user((int)$admin[0]);
$actions=new WP_REST_Request('GET','/commerce-composition/v1/workflow-actions');
$available=rest_do_request($actions);
$check($available->get_status()===200&&
 ($available->get_data()['actions']['link_contact_record']['available']??false)===true,
 'defined_available_contact_link_action');
$check(($available->get_data()['actions']['convert_request_to_certified_sale']['available']??true)===false,
 'sale_conversion_is_explicitly_blocked');
ContactBridgeAdmin::bootstrap();
$check(has_action('admin_post_commerce_contact_link')!==false,
 'native_tools_post_action_enabled');
try {
 $wpdb->query('START TRANSACTION');
 $request=new WP_REST_Request('POST','/commerce-composition/v1/contact-relations');
 foreach(['contact_id'=>$contact,'source_key'=>'supplier_booking',
   'source_id'=>$booking,'role'=>'requester'] as $k=>$v)$request->set_param($k,$v);
 $linked=rest_do_request($request);
 $check($linked->get_status()===200&&
  $linked->get_data()['status']==='linked',
  'admin_restdirect_contact_supplier_relation_created');
 $duplicated=rest_do_request($request);
 $check($duplicated->get_status()===200&&
  $duplicated->get_data()['status']==='already_linked',
  'repeat_rest_post_idempotent');
 $inquiryRequest=new WP_REST_Request('POST','/commerce-composition/v1/workflow-actions');
 $inquiryRequest->set_param('action_key','link_contact_record');
 $inquiryRequest->set_param('payload',[
  'contact_id'=>$contact,'source_key'=>'inquiry',
  'source_id'=>$inquiry,'role'=>'requester'
 ]);
 $linkedInquiry=rest_do_request($inquiryRequest);
 $check($linkedInquiry->get_status()===200&&
  $linkedInquiry->get_data()['result']['status']==='linked',
  'original_fluent_forms_submission_linked_to_same_contact');
 $list=new WP_REST_Request('GET','/commerce-composition/v1/contact-relations/'.$contact);
 $list->set_param('contact_id',$contact);
 $links=rest_do_request($list);
 $items=$links->get_data()['items']??[];
 $check($links->get_status()===200&&count($items)===2,
  'crm_contact_references_two_distinct_original_systems');
 $card=apply_filters('fluent_crm/subscriber_info_widgets',[],(object)['id'=>$contact]);
 $check(count($card)>=1,'native_fluentcrm_profile_widget_registered');
 $html=implode('',array_column($card,'content'));
 $check(str_contains($html,'Accommodation')&&str_contains($html,'Guide assignment'),
  'source_field_projection_present');
 $check(str_contains($html,'Manage linked records')&&str_contains($html,'commerce-contact-relations'),
  'crm_widget_links_to_safe_native_management_interface');
 $check(!str_contains($html,'selection_digest')&&!str_contains($html,'meta_value'),
  'private_payload_omitted');
 $_GET['contact_id']=(string)$contact;
 ob_start();ContactBridgeAdmin::render();$page=ob_get_clean();
 $check(str_contains($page,'name="contact_id"')&&
  str_contains($page,'name="source_key"')&&
  str_contains($page,'name="_wpnonce"'),
  'admin_tools_source_selector_and_wp_nonce');
 $check(str_contains($page,'convert_request_to_certified_sale')&&
  str_contains($page,'approval required'),
  'commercial_transition_shown_as_disabled');
}finally{
 $wpdb->query('ROLLBACK');
 wp_set_current_user($user);
}
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$before,
 'all_contact_link_tests_rolled_back');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers")===$other['contacts'],
 'fluentcrm_contacts_unchanged');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$other['orders'],
 'woocommerce_sales_unchanged');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions")===$other['forms'],
 'source_inquiry_entries_unchanged');
echo wp_json_encode(['result'=>$failed?'FAIL':'PASS','passed'=>$count,'failed'=>$failed,
 'tested_source_types'=>['cpt','fluent_form'],
 'crm_native_admin_and_rest'=>true,'sale_guard'=>true,'qa_rollback'=>true]).PHP_EOL;
if($failed)exit(2);
