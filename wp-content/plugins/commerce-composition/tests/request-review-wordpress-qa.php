<?php
$_SERVER['HTTP_HOST']='localhost:8094';
require '/var/www/html/wp-load.php';
use Commerce\Composition\RequestReview;
global $wpdb;
$passed=0;$failed=[];
$check=static function($v,$label)use(&$passed,&$failed){$v?$passed++:$failed[]=$label;};
$original=get_current_user_id();
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$t=$wpdb->prefix.'commerce_request_batches';
$states=$wpdb->prefix.'commerce_request_review_states';
$events=$wpdb->prefix.'commerce_request_review_events';
$orders=$wpdb->prefix.'wc_orders';
$bookings=$wpdb->posts;
$before=[
 'registry'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t"),
 'states'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $states"),
 'events'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $events"),
 'orders'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $orders"),
 'bookings'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $bookings WHERE post_type='tour_bookings'")
];
$requestId=0;
$savedOriginalId=0;
$enabled=static fn($prev)=>true;
try{
 $check(RequestReview::ready(),'review_schema_ready');
 $off=static fn($v)=>false;
 add_filter('commerce_composition_enable_operator_request_review',$off,999);
 $check(RequestReview::enabled()===false,'explicit_qa_test_disable_blocks_review');
 remove_filter('commerce_composition_enable_operator_request_review',$off,999);
 $stored=$wpdb->insert($wpdb->prefix.'fluentform_submissions',[
    'form_id'=>6,'status'=>'unread','user_id'=>0,
    'response'=>'{}','source_url'=>'http://example.invalid/operator-review-qa',
    'created_at'=>gmdate('Y-m-d H:i:s'),
    'updated_at'=>gmdate('Y-m-d H:i:s')
 ]);
 $savedOriginalId=(int)$wpdb->insert_id;
 $check($stored===1&&$savedOriginalId>0,'original_saved_fluent_form_fixture');
 $wp=wp_json_encode([[
  'source_id'=>60645,'rate_id'=>61158,'form_id'=>6,
  'source_submission_id'=>$savedOriginalId,
  'source_digest'=>str_repeat('a',64),
  'components'=>[['source_type'=>'properties','source_id'=>36474,
    'group'=>'stay','role'=>'included']]
 ]],JSON_UNESCAPED_SLASHES);
 $insert=$wpdb->insert($t,[
  'basket_sha256'=>hash('sha256','QA-REVIEW-'.bin2hex(random_bytes(16))),
  'session_sha256'=>hash('sha256','QA-REVIEW-session'),
  'sales_order_id'=>null,'state'=>'pending_review',
  'pricing_state'=>'not_quoted',
  'snapshot_json'=>$wp,
  'created_utc'=>gmdate('Y-m-d H:i:s')
 ]);
 $requestId=(int)$wpdb->insert_id;
 $check($insert===1&&$requestId>0,'synthetic_nonfinancial_registry_source');
 wp_set_current_user(0);
 $anon=RequestReview::read($requestId);
 $check(is_wp_error($anon)&&$anon->get_error_code()==='review_access_denied',
  'anonymous_read_forbidden_even_before_flag');
 add_filter('commerce_composition_enable_operator_request_review',$enabled,70);
 $anonWrite=RequestReview::decide($requestId,'under_review',0,
   'review_started','qa-review-anonymous-20261026');
 $check(is_wp_error($anonWrite),'anonymous_decision_forbidden');
 wp_set_current_user($admin);
 $initial=RequestReview::read($requestId);
 $check(!is_wp_error($initial)&&$initial['revision']===0&&
  $initial['review_state']==='pending_review','original_read_pending_immutable');
 $wrong=RequestReview::decide($requestId,'accepted_for_processing',0,
  'review_completed','qa-review-skip-20261026');
 $check(is_wp_error($wrong)&&
  $wrong->get_error_code()==='review_transition_not_allowed',
  'cannot_accept_without_review');
 $one=RequestReview::decide($requestId,'under_review',0,
   'review_started','qa-review-start-20261026');
 $check(!is_wp_error($one)&&$one['revision']===1&&
  $one['supplier_authorized']===false,'operator_starts_review_only');
 $repeat=RequestReview::decide($requestId,'under_review',0,
   'review_started','qa-review-start-20261026');
 $check(!is_wp_error($repeat)&&$repeat['status']==='already_applied',
  'identical_command_key_is_idempotent');
 $conflict=RequestReview::decide($requestId,'rejected',1,
   'source_unavailable','qa-review-start-20261026');
 $check(is_wp_error($conflict)&&
   $conflict->get_error_code()==='review_idempotency_conflict',
   'same_key_different_command_denied');
 $stale=RequestReview::decide($requestId,'needs_information',0,
   'information_missing','qa-review-stale-20261026');
 $check(is_wp_error($stale)&&
  $stale->get_error_code()==='review_revision_conflict',
  'stale_revision_denied');
 $need=RequestReview::decide($requestId,'needs_information',1,
   'information_missing','qa-review-need-more-20261026');
 $check(!is_wp_error($need)&&$need['revision']===2,
  'request_more_information_without_mail');
 $resume=RequestReview::decide($requestId,'under_review',2,
   'review_started','qa-review-resume-20261026');
 $check(!is_wp_error($resume)&&$resume['revision']===3,
  'resume_review_without_creating_booking');
 $accept=RequestReview::decide($requestId,'accepted_for_processing',3,
   'review_completed','qa-review-accept-20261026');
 $check(!is_wp_error($accept)&&$accept['revision']===4&&
  $accept['sale_authorized']===false,'accept_for_processing_not_sale');
 $terminal=RequestReview::decide($requestId,'rejected',4,
  'source_unavailable','qa-review-terminal-20261026');
 $check(is_wp_error($terminal)&&
  $terminal->get_error_code()==='review_transition_not_allowed',
  'terminal_state_cannot_be_rejected_after_acceptance');
 $state=RequestReview::read($requestId);
 $check(!is_wp_error($state)&&
  $state['review_state']==='accepted_for_processing'&&
  $state['supplier_booking_id']===null&&$state['woo_order_id']===null,
  'no_fake_supplier_booking_or_woocommerce_order');
 $count=(int)$wpdb->get_var($wpdb->prepare(
  "SELECT COUNT(*) FROM $events WHERE request_id=%d",$requestId));
 $check($count===4,'exact_four_audited_transitions');
 $check((int)$wpdb->get_var("SELECT COUNT(*) FROM $orders")===$before['orders'],
  'hpos_unmodified');
 $check((int)$wpdb->get_var("SELECT COUNT(*) FROM $bookings WHERE post_type='tour_bookings'")===$before['bookings'],
  'original_supplier_bookings_unmodified');
}finally{
 if($requestId>0){
  $wpdb->delete($events,['request_id'=>$requestId],['%d']);
  $wpdb->delete($states,['request_id'=>$requestId],['%d']);
  $wpdb->delete($t,['id'=>$requestId],['%d']);
 }
 if($savedOriginalId>0)
   $wpdb->delete($wpdb->prefix.'fluentform_submissions',
     ['id'=>$savedOriginalId],['%d']);
 remove_filter('commerce_composition_enable_operator_request_review',$enabled,70);
 wp_set_current_user($original);
}
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")===$before['registry'],
  'registry_fixture_cleaned');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $states")===$before['states'],
 'review_state_fixture_cleaned');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $events")===$before['events'],
 'audit_event_fixture_cleaned');
echo wp_json_encode(['result'=>$failed?'FAIL':'PASS','passed'=>$passed,
 'failed'=>$failed,'no_real_sale_or_supplier_effects'=>true,
 'test_cleanup'=>true,'feature_default_off'=>true]).PHP_EOL;
if($failed)exit(2);
