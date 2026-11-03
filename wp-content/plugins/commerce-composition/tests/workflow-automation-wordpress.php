<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\WorkflowJobs;
use Commerce\Composition\ContactBridge;
use Commerce\Composition\WorkflowActions;
global $wpdb;
$pass=0;$failed=[];
$ok=static function($v,$label)use(&$pass,&$failed){$v?$pass++:$failed[]=$label;};
$j=$wpdb->prefix.'commerce_workflow_jobs';
$l=$wpdb->prefix.'commerce_contact_links';
$b=$wpdb->prefix.'commerce_request_batches';
$before=[
 'jobs'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $j"),
 'links'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $l"),
 'batch'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $b"),
 'hpos'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"),
 'crm'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers")
];
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$contact=(int)$wpdb->get_var("SELECT MIN(id) FROM {$wpdb->prefix}fc_subscribers");
$sub=$wpdb->get_row("SELECT id,form_id FROM {$wpdb->prefix}fluentform_submissions
 WHERE form_id IN (3,6) ORDER BY id DESC LIMIT 1",ARRAY_A);
$formId=(int)($sub['id']??0);
$was=get_current_user_id();
$ok(WorkflowJobs::ready(),'durable_queue_ready');
$ok($admin>0&&$contact>0&&$formId>0,'original_test_actors_sources_found');
$payload=['contact_id'=>$contact,'source_key'=>'inquiry',
 'source_id'=>$formId,'role'=>'requester'];
wp_set_current_user(0);
$anon=new WP_REST_Request('POST','/commerce-composition/v1/workflow-jobs');
$anon->set_header('Idempotency-Key','test-qa-job-20261026-0001');
$anon->set_param('action_key','link_contact_record');
$anon->set_param('payload',$payload);
$anonRes=rest_do_request($anon);
$ok(in_array($anonRes->get_status(),[401,403],true),'anonymous_post_forbidden');
wp_set_current_user($admin);
$wpdb->query('START TRANSACTION');
try {
 $noIdem=new WP_REST_Request('POST','/commerce-composition/v1/workflow-jobs');
 $noIdem->set_param('action_key','link_contact_record');$noIdem->set_param('payload',$payload);
 $missing=rest_do_request($noIdem);
 $ok($missing->get_status()===400,'idempotency_header_required');
 $first=rest_do_request($anon);
 $data=$first->get_data();
 $job=(int)($data['job']['job_id']??0);
 $ok($first->get_status()===202&&$job>0,'new_post_accepted_as_async_job');
 $ok(($data['job']['status']??'')==='queued','queued_not_falsely_completed');
 $duplicate=rest_do_request($anon);
 $ok($duplicate->get_status()===200&&
   (int)$duplicate->get_data()['job']['job_id']===$job,
   'same_key_returns_same_job');
 $different=$payload;$different['source_id']=$formId+400000;
 $changed=new WP_REST_Request('POST','/commerce-composition/v1/workflow-jobs');
 $changed->set_header('Idempotency-Key','test-qa-job-20261026-0001');
 $changed->set_param('action_key','link_contact_record');
 $changed->set_param('payload',$different);
 $conflict=rest_do_request($changed);
 $ok($conflict->get_status()===409,'same_idempotency_key_changed_payload_conflicts');
 $forbidden=new WP_REST_Request('POST','/commerce-composition/v1/workflow-jobs');
 $forbidden->set_header('Idempotency-Key','test-qa-financial-20261026');
 $forbidden->set_param('action_key','convert_request_to_certified_sale');
 $forbidden->set_param('payload',[]);
 $denied=rest_do_request($forbidden);
 $ok($denied->get_status()===422,'remote_paid_sale_not_enqueueable');
 $process=WorkflowJobs::process($job);
 $ok(!is_wp_error($process)&&$process['status']==='succeeded',
   'background_worker_creates_exact_verified_relation');
 $already=WorkflowJobs::process($job);
 $ok(is_wp_error($already)&&$already->get_error_code()==='workflow_job_not_claimable',
   'parallel_duplicate_execution_claim_denied');
 $status=new WP_REST_Request('GET','/commerce-composition/v1/workflow-jobs/'.$job);
 $status->set_param('job_id',$job);
 $result=rest_do_request($status);
 $record=$result->get_data()['job']??[];
 $ok($result->get_status()===200&&$record['status']==='succeeded'&&
   $record['attempts']===1,'remote_poll_returns_durable_status');
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $l")===$before['links']+1,
  'exactly_one_original_form_to_crm_link_created');
 // A new Request Registry record, saved AFTER an explicitly verified Form
 // relationship, triggers an independent automatic follow-up job.
 $snapshot=wp_json_encode([[
  'source_id'=>60645,'source_submission_id'=>$formId,'form_id'=>(int)$sub['form_id'],
  'source_digest'=>hash('sha256','automatic-link-qa')
 ]],JSON_UNESCAPED_SLASHES);
 $wpdb->insert($b,[
  'basket_sha256'=>hash('sha256','qa-basket-automation-20261026'),
  'session_sha256'=>hash('sha256','qa-customer-automation-20261026'),
  'sales_order_id'=>null,'state'=>'pending_review',
  'pricing_state'=>'not_quoted','snapshot_json'=>$snapshot,
  'created_utc'=>gmdate('Y-m-d H:i:s')
 ]);
 $requestId=(int)$wpdb->insert_id;
 $ok($requestId>0,'temporary_existing_nonfinancial_request_stored');
 do_action('commerce_composition_request_submitted',[
  'status'=>'request_submitted','request_id'=>$requestId
 ],[['source_submission_id'=>$formId]]);
 $queued=$wpdb->get_results($wpdb->prepare(
  "SELECT id,action_key,state FROM $j WHERE id>%d ORDER BY id ASC",$job),ARRAY_A);
 $ok(count($queued)===1&&$queued[0]['state']==='queued',
  'source_event_auto_enqueues_relation_no_manual_click');
 $drain=WorkflowJobs::drain(3);
 $ok($drain['succeeded']===1,'dedicated_queue_drains_only_plugin_work');
 $autoRow=$wpdb->get_row($wpdb->prepare(
  "SELECT source_id,role FROM $l WHERE domain='request' AND source_id=%d",
  $requestId),ARRAY_A);
 $ok(is_array($autoRow)&&$autoRow['role']==='requester',
  'verified_inquiry_to_request_auto_relation_committed');
 do_action('commerce_composition_request_submitted',[
  'status'=>'request_submitted','request_id'=>$requestId
 ],[['source_submission_id'=>$formId]]);
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $l")===$before['links']+2,
  'auto_event_replay_dedupes_links');
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$before['hpos'],
  'automated_workflow_never_creates_sale');
}finally{
 $wpdb->query('ROLLBACK');wp_set_current_user($was);
}
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $j")===$before['jobs'],'job_fixture_rolled_back');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $l")===$before['links'],'relation_fixture_rolled_back');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $b")===$before['batch'],'request_fixture_rolled_back');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers")===$before['crm'],'contacts_unmodified');
echo wp_json_encode(['result'=>$failed?'FAIL':'PASS','passed'=>$pass,
 'failed'=>$failed,'remote_api'=>true,'automatic_event'=>true,
 'queue_durable'=>true,'fixtures_rolled_back'=>true]).PHP_EOL;
if($failed)exit(2);
