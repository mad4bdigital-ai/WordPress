<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\RequestReview;
global $wpdb;
$pass=0;$errors=[];$ok=static function($yes,$label)use(&$pass,&$errors){
 $yes?$pass++:$errors[]=$label;
};
$table=$wpdb->prefix.'commerce_request_batches';
$states=$wpdb->prefix.'commerce_request_review_states';
$events=$wpdb->prefix.'commerce_request_review_events';
$old=get_current_user_id();
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$enabled=static fn($v)=>true;
$before=[
 'registry'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $table"),
 'states'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $states"),
 'events'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $events")
];
$id=0;
$savedOriginalId=0;
try{
 $stored=$wpdb->insert($wpdb->prefix.'fluentform_submissions',[
    'form_id'=>6,'status'=>'unread','user_id'=>0,
    'response'=>'{}','source_url'=>'http://example.invalid/operator-review-qa',
    'created_at'=>gmdate('Y-m-d H:i:s'),
    'updated_at'=>gmdate('Y-m-d H:i:s')
 ]);
 $savedOriginalId=(int)$wpdb->insert_id;
 $ok($stored===1&&$savedOriginalId>0,'original_saved_fluent_form_fixture');
 $new=$wpdb->insert($table,[
   'basket_sha256'=>hash('sha256','rest-review-'.bin2hex(random_bytes(16))),
   'session_sha256'=>hash('sha256','rest-operator-session'),
   'state'=>'pending_review','pricing_state'=>'not_quoted',
   'sales_order_id'=>null,
   'snapshot_json'=>wp_json_encode([[
     'source_id'=>60645,'form_id'=>6,'source_submission_id'=>$savedOriginalId,
     'rate_id'=>61158,'components'=>[]
   ]]),
   'created_utc'=>gmdate('Y-m-d H:i:s')
 ]);
 $id=(int)$wpdb->insert_id;
 $ok($new===1&&$id>0,'fixture_source_created');
 wp_set_current_user(0);
 $request=new WP_REST_Request('GET','/commerce-composition/v1/request-reviews/'.$id);
 $noAuth=rest_do_request($request);
 $ok($noAuth->get_status()===401||$noAuth->get_status()===403,
  'anonymous_operator_rest_denied');
 wp_set_current_user($admin);
 $offFilter=static fn($v)=>false;
 add_filter('commerce_composition_enable_operator_request_review',$offFilter,999);
 $off=rest_do_request($request);
 $ok($off->get_status()===403,'feature_gate_blocks_even_admin');
 remove_filter('commerce_composition_enable_operator_request_review',$offFilter,999);
 add_filter('commerce_composition_enable_operator_request_review',$enabled,90);
 $read=rest_do_request($request);
 $data=$read->get_data();
 $ok($read->get_status()===200&&
  ($data['result']['review_state']??'')==='pending_review',
  'auth_admin_receives_source_only_no_pii');
 $list=rest_do_request(new WP_REST_Request('GET',
  '/commerce-composition/v1/request-reviews'));
 $ok($list->get_status()===200&&
  ($list->get_data()['result']['pagination']['total']??0)>=1,
  'bounded_operator_inbox_lists_pending_requests');
 $uri='/commerce-composition/v1/request-reviews/'.$id.'/decide';
 $sendReviewDecision=static function($values,$key)use($uri){
   $req=new WP_REST_Request('POST',$uri);
   $req->set_header('Content-Type','application/json');
   $req->set_header('Idempotency-Key',$key);
   $req->set_body(wp_json_encode($values));
   return rest_do_request($req);
 };
 $body=['to_state'=>'under_review','expected_revision'=>0,
   'reason_code'=>'review_started'];
 $missing=$sendReviewDecision($body,'');
 $ok($missing->get_status()===400,'missing_idempotency_key_denied');
 $withExtra=$sendReviewDecision($body+['customer_email'=>'fake@example.invalid'],
     'qa-operator-rest-extra-20261026');
 $ok($withExtra->get_status()===400,'extra_pii_payload_denied');
 $first=$sendReviewDecision($body,'qa-operator-rest-start-20261026');
 $ok($first->get_status()===200 &&
  ($first->get_data()['result']['review_state']??'')==='under_review',
  'rest_authorized_review_transition');
 $same=$sendReviewDecision($body,'qa-operator-rest-start-20261026');
 $ok($same->get_status()===200 &&
  ($same->get_data()['result']['status']??'')==='already_applied',
  'replay_with_same_idempotency_key_succeeds_without_duplicate');
 $stale=$sendReviewDecision(['to_state'=>'accepted_for_processing',
   'expected_revision'=>0,'reason_code'=>'review_completed'],
   'qa-operator-rest-stale-20261026');
 $ok($stale->get_status()===409,'stale_revision_conflict_http_409');
 $accepted=$sendReviewDecision(['to_state'=>'accepted_for_processing',
   'expected_revision'=>1,'reason_code'=>'review_completed'],
   'qa-operator-rest-accept-20261026');
 $ok($accepted->get_status()===200 &&
  ($accepted->get_data()['result']['supplier_authorized']??true)===false,
  'rest_accepted_request_is_not_supplier_committed');
 $after=rest_do_request($request);
 $ok($after->get_status()===200&&
   ($after->get_data()['result']['revision']??0)===2,
   'rest_readback_revision_two');
 $count=(int)$wpdb->get_var($wpdb->prepare(
  "SELECT COUNT(*) FROM $events WHERE request_id=%d",$id));
 $ok($count===2,'exact_two_event_ledger_entries');
}finally{
 if($id){
  $wpdb->delete($events,['request_id'=>$id]);
  $wpdb->delete($states,['request_id'=>$id]);
  $wpdb->delete($table,['id'=>$id]);
 }
 if($savedOriginalId>0)
   $wpdb->delete($wpdb->prefix.'fluentform_submissions',
     ['id'=>$savedOriginalId],['%d']);
 remove_filter('commerce_composition_enable_operator_request_review',$enabled,90);
 wp_set_current_user($old);
}
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$before['registry'],
  'registry_clean');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $states")===$before['states'],
 'state_clean');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $events")===$before['events'],
 'audit_clean');
// WordPress exposes the global $post at script scope. A test-local callable
// must never shadow it; that previously caused a silent PHP shutdown 255.
$ok(!is_callable($GLOBALS['post']??null),'wordpress_post_global_not_overwritten');
echo wp_json_encode(['result'=>$errors?'FAIL':'PASS',
 'passed'=>$pass,'failed'=>$errors,
 'operator_rest_access_control'=>true,'idempotency_and_cas'=>true,
 'fixtures_removed'=>true]).PHP_EOL;
if($errors)exit(2);
