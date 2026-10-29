<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\ContactBridge;
use Commerce\Composition\WorkflowJobs;
global $wpdb;
$checkCount=0;$errors=[];$check=static function($v,$name)use(&$checkCount,&$errors){
 $v?$checkCount++:$errors[]=$name;
};
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$contacts=$wpdb->get_col("SELECT id FROM {$wpdb->prefix}fc_subscribers ORDER BY id LIMIT 2");
$forms=$wpdb->get_col("SELECT id FROM {$wpdb->prefix}fluentform_submissions
 WHERE form_id IN (3,6) ORDER BY id LIMIT 2");
$links=$wpdb->prefix.'commerce_contact_links';
$requests=$wpdb->prefix.'commerce_request_batches';
$jobs=$wpdb->prefix.'commerce_workflow_jobs';
$base=(int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs");
$original=get_current_user_id();
$check(count($contacts)===2&&count($forms)===2,'two_distinct_contacts_and_submissions_available');
wp_set_current_user($admin);
$wpdb->query('START TRANSACTION');
try{
 $a=ContactBridge::link((int)$contacts[0],'inquiry',(int)$forms[0],'requester');
 $b=ContactBridge::link((int)$contacts[1],'inquiry',(int)$forms[1],'requester');
 $check(!is_wp_error($a)&&!is_wp_error($b),'independent_original_form_links_verified');
 $snapshot=wp_json_encode([
  ['source_id'=>60645,'source_submission_id'=>(int)$forms[0]],
  ['source_id'=>66133,'source_submission_id'=>(int)$forms[1]]
 ],JSON_UNESCAPED_SLASHES);
 $wpdb->insert($requests,[
  'basket_sha256'=>hash('sha256','ambiguous-qa-request-v1'),
  'session_sha256'=>hash('sha256','ambiguous-qa-session-v1'),
  'state'=>'pending_review','pricing_state'=>'not_quoted',
  'snapshot_json'=>$snapshot,'created_utc'=>gmdate('Y-m-d H:i:s')
 ]);
 $id=(int)$wpdb->insert_id;
 $check($id>0,'independent_request_fixture_created');
 do_action('commerce_composition_request_submitted',[
  'request_id'=>$id,'status'=>'request_submitted'
 ],[['source_submission_id'=>(int)$forms[0]],
   ['source_submission_id'=>(int)$forms[1]]]);
 $check((int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs")===$base,
  'two_different_people_no_automatic_contact_assignment');
 // Missing proof for a second traveler also blocks autopropagation.
 $unlinked=(int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}fluentform_submissions
 WHERE id NOT IN (".implode(',',array_map('intval',$forms)).")
 AND form_id IN (3,6) LIMIT 1");
 $check($unlinked>0,'unlinked_second_submission_fixture_available');
 do_action('commerce_composition_request_submitted',[
  'request_id'=>$id,'status'=>'request_submitted'
 ],[['source_submission_id'=>(int)$forms[0]],
   ['source_submission_id'=>$unlinked]]);
 $check((int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs")===$base,
  'partially_linked_travelers_not_auto_assigned');
}finally{
 $wpdb->query('ROLLBACK');wp_set_current_user($original);
}
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs")===$base,
 'no_auto_workflow_job_leaked');
echo wp_json_encode(['result'=>$errors?'FAIL':'PASS','passed'=>$checkCount,
 'failed'=>$errors,'ambiguous_identity'=>'fail_closed']).PHP_EOL;
if($errors)exit(2);
