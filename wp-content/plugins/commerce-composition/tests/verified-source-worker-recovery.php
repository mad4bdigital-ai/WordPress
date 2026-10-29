<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\AutomationEvents;
use Commerce\Composition\WorkflowJobs;
global $wpdb;
$pass=0;$bad=[];
$ok=static function($v,$name)use(&$pass,&$bad){$v?$pass++:$bad[]=$name;};
$jobs=$wpdb->prefix.'commerce_workflow_jobs';
$links=$wpdb->prefix.'commerce_contact_links';
$beforeJobs=(int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs");
$beforeLinks=(int)$wpdb->get_var("SELECT COUNT(*) FROM $links");
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$contact=(int)$wpdb->get_var("SELECT MIN(id) FROM {$wpdb->prefix}fc_subscribers");
$submission=$wpdb->get_row("SELECT id,form_id FROM {$wpdb->prefix}fluentform_submissions
 WHERE form_id IN (3,6) ORDER BY id DESC LIMIT 1",ARRAY_A);
$booking=(int)$wpdb->get_var("SELECT MAX(m.post_id) FROM {$wpdb->postmeta} m
 JOIN {$wpdb->posts} p ON p.ID=m.post_id
 WHERE p.post_type='tour_bookings' AND m.meta_key='tour_booking_property'");
$oldUser=get_current_user_id();
$verified=static function($prior,$source,$context)use($contact,$admin,$submission,$booking){
 if(($source['domain']??null)==='fluent_form'&&
    ($source['source_id']??null)===(int)$submission['id'])
  return ['contact_id'=>$contact,'actor_id'=>$admin,'role'=>'requester'];
 if(($source['domain']??null)==='cpt'&&
    ($source['source_id']??null)===$booking)
  return ['contact_id'=>$contact,'actor_id'=>$admin,'role'=>'related_contact'];
 return null;
};
wp_set_current_user($admin);
add_filter('commerce_composition_verified_event_contact',$verified,90,3);
$wpdb->query('START TRANSACTION');
try{
 $ok($submission&&$booking>0,'genuine_original_sources_available');
 AutomationEvents::onFluentSubmission((int)$submission['id'],[],
  (object)['id'=>(int)$submission['form_id']]);
 $row=$wpdb->get_row($wpdb->prepare(
  "SELECT id,state,attempts FROM $jobs ORDER BY id DESC LIMIT 1"
 ),ARRAY_A);
 $first=(int)($row['id']??0);
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs")===$beforeJobs+1&&
  $row['state']==='queued','trusted_native_event_enqueued_without_form_pii');
 AutomationEvents::onFluentSubmission((int)$submission['id'],[],
  (object)['id'=>(int)$submission['form_id']]);
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs")===$beforeJobs+1,
  'duplicate_original_form_event_returns_same_job');
 $wpdb->update($jobs,[
  'state'=>'running','attempts'=>1,
  'updated_utc'=>gmdate('Y-m-d H:i:s',time()-1800)
 ],['id'=>$first]);
 $result=WorkflowJobs::drain(5);
 $record=$wpdb->get_row($wpdb->prepare(
  "SELECT state,attempts FROM $jobs WHERE id=%d",$first),ARRAY_A);
 $ok($result['succeeded']===1&&$record['state']==='succeeded'&&
    (int)$record['attempts']===2,
  'stalled_running_job_reclaimed_and_completed');
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $links")===$beforeLinks+1,
  'worker_created_one_original_form_crm_relationship');
 do_action('commerce_composition_source_record_verified',[
  'source_key'=>'supplier_booking','source_id'=>$booking,
  'domain'=>'cpt'
 ],['event'=>'verified_supplier_record']);
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs")===$beforeJobs+2,
  'generalized_source_cpt_event_accepted_from_owner_provider');
 $r=WorkflowJobs::drain(5);
 $ok($r['succeeded']===1,'private_booking_source_auto_link_processed');
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $links")===$beforeLinks+2,
  'no_source_meta_copied_into_contact_table');
 $payable=WorkflowJobs::enqueue('convert_request_to_certified_sale',
  ['contact_id'=>$contact,'source_key'=>'inquiry',
   'source_id'=>(int)$submission['id'],'role'=>'requester'],
  'qa-unauthorized-payable-20261026-01',$admin,'internal');
 $ok(is_wp_error($payable)&&
   $payable->get_error_code()==='workflow_action_not_async_certified',
   'even_trusted_provider_cannot_invent_paid_automation');
}finally{
 $wpdb->query('ROLLBACK');
 remove_filter('commerce_composition_verified_event_contact',$verified,90);
 wp_set_current_user($oldUser);
}
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs")===$beforeJobs,
 'all_event_job_test_records_rolled_back');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $links")===$beforeLinks,
 'all_crm_link_test_records_rolled_back');
echo wp_json_encode(['result'=>$bad?'FAIL':'PASS','passed'=>$pass,'failed'=>$bad,
 'fluent_native_source'=>true,'cpt_provider_source'=>true,
 'crash_recovery'=>true,'qa_rollback'=>true]).PHP_EOL;
if($bad)exit(2);
