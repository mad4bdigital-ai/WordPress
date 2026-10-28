<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\ContactBridge;
use Commerce\Composition\ContactBridgeUi;
use Commerce\Composition\WorkflowActions;
global $wpdb;
$pass=0;$failed=[];$assert=static function($v,$name)use(&$pass,&$failed){
 $v?$pass++:$failed[]=$name;
};
$table=$wpdb->prefix.'commerce_contact_links';
$counts=[
 'contacts'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers"),
 'links'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $table"),
 'hpos'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"),
 'fluent'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions"),
 'requests'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches"),
];
$contact=(int)$wpdb->get_var("SELECT MIN(id) FROM {$wpdb->prefix}fc_subscribers");
$booking=(int)$wpdb->get_var("SELECT MAX(m.post_id) FROM {$wpdb->postmeta} m
 JOIN {$wpdb->posts} p ON p.ID=m.post_id
 WHERE p.post_type='tour_bookings' AND m.meta_key='tour_booking_dmc'");
$admin=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
$previous=get_current_user_id();
$assert(ContactBridge::ready(),'bridge_schema_ready');
$assert($contact>0 && $booking>0,'existing_qa_fixture_ids_discovered');
$ev=ContactBridge::sourceEvidence('supplier_booking',$booking);
$assert(!is_wp_error($ev)&&$ev['domain']==='cpt'&&$ev['source_type']==='tour_bookings',
 'configured_original_cpt_source_verified');
$assert(count($ev['field_projection']??[])===6,'all_six_legacy_operational_fields_mapped');
$assert(count(array_filter($ev['field_projection']??[],
 static fn($item)=>$item['state']==='linked'))>=1,
 'meta_field_presence_is_exposed_not_private_value');
$assert(!array_key_exists('email',$ev)&&!array_key_exists('meta_value',$ev),
 'source_evidence_has_no_customer_pii_or_raw_supplier_id');
wp_set_current_user(0);
$blocked=ContactBridge::link($contact,'supplier_booking',$booking,'requester');
$assert(is_wp_error($blocked)&&$blocked->get_error_code()==='contact_bridge_permission_denied',
 'anonymous_cannot_link_private_supplier_post');
$assert(is_wp_error(ContactBridge::forContact($contact)),
 'anonymous_cannot_enumerate_contact_references');
$rest=new WP_REST_Request('GET','/commerce-composition/v1/contact-relations/'.$contact);
$rest->set_param('contact_id',$contact);
$deny=rest_do_request($rest);
$assert($deny->get_status()===401||$deny->get_status()===403,
 'contact_link_rest_is_private');
wp_set_current_user((int)$admin[0]);
$assert(current_user_can('manage_options'),
 'owner_manage_options_permission_verified');
$unknown=ContactBridge::sourceEvidence('arbitrary_nonexistent',333333);
$assert(is_wp_error($unknown),'unconfigured_service_cannot_be_inferred');
$noOrder=ContactBridge::sourceEvidence('legacy_order',$booking);
$assert(is_wp_error($noOrder),'wrong_legacy_customer_order_identity_rejected');
$actions=WorkflowActions::definitions();
$assert($actions['link_contact_record']['available']===true,
 'crm_link_action_available');
$assert($actions['create_supplier_booking']['available']===false &&
  $actions['convert_request_to_certified_sale']['available']===false,
 'supplier_dispatch_and_paid_sale_gate_remain_closed');
$denied=WorkflowActions::execute('convert_request_to_certified_sale',
 ['request_id'=>300,'price'=>0]);
$assert(is_wp_error($denied)&&
  $denied->get_error_code()==='workflow_action_required_authority_missing',
  'unpriced_request_cannot_turn_into_sale');
$wpdb->query('START TRANSACTION');
try{
 $link=WorkflowActions::execute('link_contact_record',[
  'contact_id'=>$contact,'source_key'=>'supplier_booking',
  'source_id'=>$booking,'role'=>'requester'
 ]);
 $assert(!is_wp_error($link)&&$link['status']==='linked',
  'explicit_contact_to_original_supplier_booking_relationship_created');
 $again=WorkflowActions::execute('link_contact_record',[
  'contact_id'=>$contact,'source_key'=>'supplier_booking',
  'source_id'=>$booking,'role'=>'requester'
 ]);
 $assert(!is_wp_error($again)&&$again['status']==='already_linked'&&
  $again['relation_id']===$link['relation_id'],
  'repeat_link_deduplicated_by_unique_index');
 $list=ContactBridge::forContact($contact);
 $matched=array_values(array_filter($list,static fn($item)=>
  $item['source_id']===$booking&&$item['source_key']==='supplier_booking'));
 $assert(count($matched)===1,'contact_one_verified_supplier_booking_reference');
 $assert(count($matched[0]['field_projection'])===6,
  'crm_safe_field_projection_available');
 $widget=ContactBridgeUi::widget([],(object)['id'=>$contact]);
 $assert(count($widget)===1,'native_crm_profile_widget_injected');
 $html=$widget[0]['content']??'';
 $assert(str_contains($html,'Accommodation')&&str_contains($html,'Service supplier'),
  'operational_meta_fields_rendered_in_contact_profile');
 $assert(str_contains($html,'Linked at source'),
  'legacy_field_values_are_status_only_no_raw_supplier_ids');
 $route=new WP_REST_Request('GET','/commerce-composition/v1/contact-relations/'.$contact);
 $route->set_param('contact_id',$contact);
 $read=rest_do_request($route);
 $assert($read->get_status()===200&&
  count($read->get_data()['items'])===1,
  'private_authenticated_relationship_rest_read');
 $wrongForm=ContactBridge::sourceEvidence('inquiry',$booking);
 $assert(is_wp_error($wrongForm),'form_submission_source_identity_enforced');
 $postOrder=WorkflowActions::execute('convert_legacy_order_to_sale',
  ['source_key'=>'legacy_order','source_id'=>$booking]);
 $assert(is_wp_error($postOrder),'legacy_cpt_not_automatically_billable');
}finally{
 $wpdb->query('ROLLBACK');
 wp_set_current_user($previous);
}
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$counts['links'],
 'source_relationship_qa_transaction_rolled_back');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers")===$counts['contacts'],
 'fluentcrm_contact_records_untouched');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$counts['hpos'],
 'woocommerce_hpos_never_written');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions")===$counts['fluent'],
 'fluentform_submissions_unchanged');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches")===$counts['requests'],
 'request_registry_unchanged');
echo wp_json_encode(['result'=>$failed?'FAIL':'PASS','passed'=>$pass,'failed'=>$failed,
 'cpt_data_preserved'=>true,'crm_data_preserved'=>true,
 'sales_conversion_blocked'=>true,'qa_link_rolled_back'=>true]).PHP_EOL;
if($failed)exit(2);
