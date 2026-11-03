<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\RequestReview;
use Commerce\Composition\FluentBoardsTaskBridge;
global $wpdb;
$passed=0;$errors=[];$assert=static function($ok,$label)use(&$passed,&$errors){$ok?$passed++:$errors[]=$label;};
$tables=[
 'forms'=>$wpdb->prefix.'fluentform_submissions',
 'requests'=>$wpdb->prefix.'commerce_request_batches',
 'links'=>$wpdb->prefix.'commerce_request_task_links',
 'states'=>$wpdb->prefix.'commerce_request_review_states',
 'audit'=>$wpdb->prefix.'commerce_request_review_events',
 'tasks'=>$wpdb->prefix.'fbs_tasks',
 'activities'=>$wpdb->prefix.'fbs_activities',
 'relations'=>$wpdb->prefix.'fbs_relations',
 'contacts'=>$wpdb->prefix.'fc_subscribers'
];
$before=[];foreach($tables as $k=>$t)$before[$k]=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
$old=get_current_user_id();$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$opt=static fn($v)=>['enabled'=>true,'board_id'=>1,'stage_id'=>6,'auto_create'=>false];
$id=0;$submission=0;$taskId=0;
try {
 $assert(FluentBoardsTaskBridge::ready(),'schema_ready');
 $assert(get_option('commerce_request_review_board',null)===null,'live_board_opt_in_is_off');
 wp_set_current_user($admin);
 $disabled=FluentBoardsTaskBridge::ensure(1);
 $assert(is_wp_error($disabled)&&$disabled->get_error_code()==='boards_target_not_approved',
  'default_no_task_creation');
 $wpdb->query('START TRANSACTION');
 $wpdb->insert($tables['forms'],[
  'form_id'=>6,'user_id'=>0,'status'=>'unread','response'=>'{}',
  'source_url'=>'http://example.invalid/board-bridge-qa',
  'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
 ]);
 $submission=(int)$wpdb->insert_id;
 $wpdb->insert($tables['requests'],[
  'basket_sha256'=>hash('sha256','qa-native-board-'.bin2hex(random_bytes(16))),
  'session_sha256'=>hash('sha256','qa-native-board-session'),
  'sales_order_id'=>null,'state'=>'pending_review','pricing_state'=>'not_quoted',
  'snapshot_json'=>wp_json_encode([[
   'source_id'=>60645,'rate_id'=>61158,'form_id'=>6,
   'source_submission_id'=>$submission,'components'=>[]
  ]]),'created_utc'=>gmdate('Y-m-d H:i:s')
 ]);
 $id=(int)$wpdb->insert_id;
 $assert($id>0&&$submission>0,'verified_source_fixture');
 add_filter('pre_option_commerce_request_review_board',$opt,99);
 $tooEarly=FluentBoardsTaskBridge::ensure($id);
 $assert(is_wp_error($tooEarly)&&$tooEarly->get_error_code()==='boards_review_not_actionable',
  'unreviewed_request_does_not_create_task');
 // The native RequestReview transition owns its own SQL transaction and
 // is tested separately. Seed an operator-review state in THIS transaction
 // to test FluentBoards with guaranteed rollback of temporary QA records.
 $reviewState=$wpdb->insert($tables['states'],[
    'request_id'=>$id,'review_state'=>'under_review','revision'=>1,
    'actor_id'=>$admin,'updated_utc'=>gmdate('Y-m-d H:i:s')
 ]);
 $assert($reviewState===1,'original_operator_review_source_state');
 $created=FluentBoardsTaskBridge::ensure($id);
 if(is_wp_error($created)){
  $errors[]='native_provider_'. $created->get_error_code();
  $taskId=0;
 }else{
  $taskId=(int)($created['task_id']??0);
 }
 $assert(!is_wp_error($created)&&$taskId>0&&(!is_wp_error($created)&&($created['linked']??false)),
  'native_fluentboards_task_created');
 $native=$taskId?$wpdb->get_row($wpdb->prepare(
  "SELECT id,board_id,stage_id,crm_contact_id FROM {$tables['tasks']} WHERE id=%d",
  $taskId),ARRAY_A):null;
 $assert(is_array($native)&&(int)$native['board_id']===1&&(int)$native['stage_id']===6,
  'actual_fluentboards_board_and_stage_match');
 $assert(is_array($native)&&$native['crm_contact_id']===null,
  'no_fluentcrm_subscription_side_effect');
 $again=FluentBoardsTaskBridge::ensure($id);
 $assert(!is_wp_error($again)&&(!is_wp_error($again)&&($again['reused']??false))===true,
  'native_board_task_replay_idempotent');
 $route='/commerce-composition/v1/request-reviews/'.$id.'/task';
 $viaGet=rest_do_request(new WP_REST_Request('GET',$route));
 $assert($viaGet->get_status()===200&&
   (int)($viaGet->get_data()['result']['task_id']??0)===$taskId,
   'rest_reads_native_task_by_request');
 $viaPost=rest_do_request(new WP_REST_Request('POST',$route));
 $assert($viaPost->get_status()===200&&
   ($viaPost->get_data()['result']['reused']??false)===true,
   'rest_post_idempotently_reuses_same_task');
 $withEmail=new WP_REST_Request('POST',$route);
 $withEmail->set_header('Content-Type','application/json');
 $withEmail->set_body(wp_json_encode(['contact_email'=>'fake@example.invalid']));
 $refused=rest_do_request($withEmail);
 $assert($refused->get_status()===400,
   'rest_refuses_client_supplied_customer_contact');
}finally {
 $wpdb->query('ROLLBACK');
 remove_filter('pre_option_commerce_request_review_board',$opt,99);
 wp_set_current_user($old);
}
foreach($tables as $k=>$t) {
 $n=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
 $assert($n===$before[$k],'all_original_'.$k.'_rows_unchanged');
}
echo wp_json_encode(['result'=>$errors?'FAIL':'PASS',
 'passed'=>$passed,'failed'=>$errors,
 'qa_temporary_task_id'=>$taskId,'crm_unchanged'=>true]).PHP_EOL;
if($errors)exit(2);
