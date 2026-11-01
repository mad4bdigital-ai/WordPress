<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\RequestIntake;
use Commerce\Composition\WorkflowJobs;
use Commerce\Composition\ClaimLedger;
use Commerce\Composition\FluentSubmissionAdapter;
use Commerce\Composition\AutomationEvents;
use Commerce\Composition\SubmissionContext;
global $wpdb;
$checked=0;$failed=[];
$ok=static function($v,$label)use(&$checked,&$failed){$checked++;if(!$v)$failed[]=$label;};
$formId=6;$savedIds=[];$jobs=[];$requests=[];
$before=[
 'forms'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions"),
 'claims'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_claims"),
 'registry'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches"),
 'jobs'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_workflow_jobs"),
 'crm'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers"),
 'hpos'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")
];
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$originalMode=get_option('booking_experience_global_policy',[]);
$previousUser=get_current_user_id();
$policy=[
 'schema'=>1,'revision'=>7,'required_kind'=>'package',
 'parent'=>['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>60645,'rate_id'=>61158],
 'groups'=>[['key'=>'stay','role'=>'choose_one_included','options'=>[
  ['service'=>'properties','source_type'=>'properties','source_id'=>36474],
  ['service'=>'properties','source_type'=>'properties','source_id'=>36481]
 ]]]
];
$rule=static fn($old,$pid,$rid)=>$pid===60645&&$rid===61158?$policy:$old;
$mapping=static fn($old,$pid,$rid)=>['schema'=>1,'form_id'=>6,
 'groups'=>[['key'=>'stay','field_name'=>'dropdownitenary','control'=>'select']]];
$policyOpt=$originalMode;$policyOpt['display_mode']='dual_tabs';
$mode=static fn($old)=>$policyOpt;
$context=static function($old,$sid,$formId)use(&$savedIds){
 return in_array($sid,$savedIds,true)&&$formId===6?
 ['parent_id'=>60645,'rate_id'=>61158]:null;
};
$auto=static function($old,$fid,$sid)use(&$savedIds){return $fid===6&&in_array($sid,$savedIds,true);};
$actor=static fn($old,$fid,$sid)=>$fid===6?$admin:null;
add_filter('commerce_composition_resolved_policy',$rule,50,3);
add_filter('commerce_composition_form_field_bindings',$mapping,50,3);
add_filter('pre_option_booking_experience_global_policy',$mode,50);
add_filter('commerce_composition_trusted_submission_context',$context,50,3);
try {
 foreach([0,1] as $i){
  $r=$wpdb->insert($wpdb->prefix.'fluentform_submissions',[
   'form_id'=>$formId,'status'=>'unread',
   'response'=>wp_json_encode(['dropdownitenary'=>$i===0?'36474':'36481']),
   'source_url'=>'http://example.invalid/isolated-fixture',
   'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')
  ],['%d','%s','%s','%s','%s','%s']);
  $ok($r===1,'stored_original_qa_form_'.$i);
  $savedIds[]=(int)$wpdb->insert_id;
 }
 $ok(count(array_filter($savedIds))===2,'real_unique_saved_submission_ids');
 $adminUser=get_current_user_id();
 wp_set_current_user($admin);
 foreach($savedIds as $id){
  $stored=SubmissionContext::saveTrusted(6,$id,60645,61158);
  $ok($stored===true,'durable_server_signed_original_context_'.$id);
 }
 wp_set_current_user($adminUser);
 $first=$savedIds[0];
 // Clear the original in-request context to simulate a separate remote worker.
 // Only durable server-signed evidence is available during intake.
 remove_filter('commerce_composition_trusted_submission_context',$context,50);
 $preparedViaReceipt=FluentSubmissionAdapter::prepareSubmissionIntent($first,
  ['dropdownitenary'=>'36474'],(object)['id'=>6]);
 $ok(!is_wp_error($preparedViaReceipt),
     'original_source_context_survives_request_end');
 $ok(($preparedViaReceipt['source_parent_id']??0)===60645,
     'saved_receipt_revalidates_original_parent_rate');
 $bad=RequestIntake::create(3,$first);
 $ok(is_wp_error($bad),'source_form_id_spoof_rejected');
 $prepared=FluentSubmissionAdapter::prepareSubmissionIntent($first,
  ['dropdownitenary'=>'36474'],(object)['id'=>6]);
 $ok(!is_wp_error($prepared),'same_policy_engine_prepares_server_intent');
 $ok(!is_wp_error($prepared)&&count($prepared['composition']['components'])===1,
  'package_selection_validated_against_linked_source');
 wp_set_current_user($admin);
 $post=new WP_REST_Request('POST','/commerce-composition/v1/workflow-jobs');
 $post->set_header('Idempotency-Key','qa-server-intake-request-v1-20261026-01');
 $post->set_param('action_key','create_request_from_saved_submission');
 $post->set_param('payload',['form_id'=>6,'submission_id'=>$first]);
 $queued=rest_do_request($post);
 $job=(int)($queued->get_data()['job']['job_id']??0);
 $ok($queued->get_status()===202&&$job>0,
  'remote_style_rest_can_enqueue_stored_form_intake');
 $jobs[]=$job;
 $run=WorkflowJobs::process($job);
 $ok(!is_wp_error($run)&&$run['status']==='succeeded',
  'server_worker_created_unpriced_request_without_session');
 $row=$wpdb->get_row($wpdb->prepare(
  "SELECT id,sales_order_id,state,pricing_state,snapshot_json
  FROM {$wpdb->prefix}commerce_request_batches ORDER BY id DESC LIMIT 1"
 ),ARRAY_A);
 $requestId=(int)($row['id']??0);$requests[]=$requestId;
 $sources=json_decode((string)($row['snapshot_json']??''),true);
 $ok($requestId>0 && $row['state']==='pending_review' &&
  $row['pricing_state']==='not_quoted' && $row['sales_order_id']===null,
  'request_independent_of_hpos_with_no_price');
 $ok(is_array($sources)&&count($sources)===1&&
  $sources[0]['source_submission_id']===$first&&
  $sources[0]['components'][0]['source_id']===36474,
  'original_form_and_package_child_captured_without_pii');
 $replay=rest_do_request($post);
 $ok($replay->get_status()===200 &&
  $replay->get_data()['job']['job_id']===$job,
  'same_remote_idempotency_key_reuses_job');
 $other=WorkflowJobs::enqueue('create_request_from_saved_submission',
  ['form_id'=>6,'submission_id'=>$first],
  'qa-server-intake-same-source-new-job-v1-20261026-01',$admin,'internal');
 $ok(!is_wp_error($other),'separate_task_may_replay_original_form');
 if(!is_wp_error($other)){
  $jobs[]=(int)$other['job_id'];
  $rerun=WorkflowJobs::process((int)$other['job_id']);
  $ok(!is_wp_error($rerun),'original_saved_submission_idempotent_across_distinct_jobs');
 }
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches")===$before['registry']+1,
  'one_form_has_one_request_even_across_different_job_ids');
 $originalClaim=$wpdb->get_row($wpdb->prepare(
  "SELECT state FROM {$wpdb->prefix}commerce_request_claims
    WHERE form_id=6 AND submission_id=%d",$first
 ),ARRAY_A);
 $ok(($originalClaim['state']??null)==='intake',
  'global_saved_submission_claim_stored_for_backend');
 $guest=ClaimLedger::stage($prepared);
 $ok(is_wp_error($guest) &&
  $guest->get_error_code()==='request_claim_owned_by_other_session',
  'server_intake_blocks_parallel_guest_double_booking');
 // Automatic original-saved-Form hook after explicit server policy + trusted actor.
 add_filter('commerce_composition_enable_auto_form_request_intake',$auto,50,3);
 add_filter('commerce_composition_auto_form_request_actor_id',$actor,50,3);
 try {
  $second=$savedIds[1];
  AutomationEvents::onFluentSubmission($second,
      ['dropdownitenary'=>'36481'],(object)['id'=>6]);
  $latest=$wpdb->get_row("SELECT id,state,action_key FROM {$wpdb->prefix}commerce_workflow_jobs
   ORDER BY id DESC LIMIT 1",ARRAY_A);
  $automaticJob=(int)($latest['id']??0);
  $ok($automaticJob>max($jobs)&&$latest['state']==='queued'&&
   $latest['action_key']==='create_request_from_saved_submission',
   'opted_in_native_form_event_produces_intake_job');
  $jobs[]=$automaticJob;
  $result=WorkflowJobs::process($automaticJob);
  $ok(!is_wp_error($result),'opted_in_background_form_event_processes_without_guest_session');
  $latestRequest=$wpdb->get_row("SELECT id,snapshot_json FROM {$wpdb->prefix}commerce_request_batches
  ORDER BY id DESC LIMIT 1",ARRAY_A);
  $requests[]=(int)$latestRequest['id'];
  $latestSources=json_decode((string)$latestRequest['snapshot_json'],true);
  $ok($latestSources[0]['source_submission_id']===$second &&
      $latestSources[0]['components'][0]['source_id']===36481,
   'second_separate_form_choice_kept_original');
 }finally{
  remove_filter('commerce_composition_enable_auto_form_request_intake',$auto,50);
  remove_filter('commerce_composition_auto_form_request_actor_id',$actor,50);
 }
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$before['hpos'],
  'no_woocommerce_sale_created');
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers")===$before['crm'],
  'no_fluentcrm_contact_or_subscription_changed');
}finally{
 foreach($jobs as $job){
  if(function_exists('as_unschedule_all_actions'))
   as_unschedule_all_actions('commerce_composition_process_workflow_job',
   [$job],'commerce-composition');
  $wpdb->delete($wpdb->prefix.'commerce_workflow_jobs',['id'=>$job],['%d']);
 }
 foreach($requests as $id)
  if($id>0)$wpdb->delete($wpdb->prefix.'commerce_request_batches',['id'=>$id],['%d']);
 foreach($savedIds as $id){
  $wpdb->delete($wpdb->prefix.'commerce_submission_contexts',
   ['form_id'=>6,'submission_id'=>$id],['%d','%d']);
  $wpdb->delete($wpdb->prefix.'commerce_request_claims',
   ['form_id'=>6,'submission_id'=>$id],['%d','%d']);
  $wpdb->delete($wpdb->prefix.'fluentform_submissions',['id'=>$id],['%d']);
 }
 wp_set_current_user($previousUser);
 remove_filter('commerce_composition_resolved_policy',$rule,50);
 remove_filter('commerce_composition_form_field_bindings',$mapping,50);
 remove_filter('pre_option_booking_experience_global_policy',$mode,50);
 remove_filter('commerce_composition_trusted_submission_context',$context,50);
}
foreach(['fluentform_submissions'=>'forms','commerce_request_claims'=>'claims',
 'commerce_request_batches'=>'registry','commerce_workflow_jobs'=>'jobs',
 'fc_subscribers'=>'crm','wc_orders'=>'hpos'] as $name=>$key){
 $now=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$name");
 $ok($now===$before[$key],'fixture_cleaned_'.$key);
}
$ok(get_option('booking_experience_global_policy',[])===$originalMode,
 'global_inquiry_only_not_changed');
echo wp_json_encode(['result'=>$failed?'FAIL':'PASS',
 'checks'=>$checked,'failed'=>$failed,
 'original_fluent_rows_used'=>true,'manual_guest_session'=>false,
 'monetary_amount_recorded'=>false,'qa_cleaned'=>true]).PHP_EOL;
if($failed)exit(2);
