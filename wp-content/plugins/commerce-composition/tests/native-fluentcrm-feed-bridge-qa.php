<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\FluentCrmFeedBridge;
use Commerce\Composition\WorkflowJobs;
global $wpdb;
$pass=0;$fail=[];$ok=static function($v,$name)use(&$pass,&$fail){
 $v?$pass++:$fail[]=$name;
};
$before=[];
foreach(['fc_subscribers','fluentform_submissions','fluentform_form_meta',
  'commerce_workflow_jobs','commerce_contact_links','commerce_request_batches',
  'wc_orders'] as $t)$before[$t]=(int)$wpdb->get_var(
 "SELECT COUNT(*) FROM {$wpdb->prefix}$t"
);
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$contact=(int)$wpdb->get_var("SELECT MIN(id) FROM {$wpdb->prefix}fc_subscribers");
$original=get_current_user_id();
$entryId=0;$feedId=0;$jobId=0;
$ok($admin>0&&$contact>0,'original_contact_and_admin_exist');
$ok(has_action('fluent_crm/contact_added_by_fluentform',
 [FluentCrmFeedBridge::class,'onFeed'])!==false,'native_added_hook_registered');
$ok(has_action('fluent_crm/contact_updated_by_fluentform',
 [FluentCrmFeedBridge::class,'onFeed'])!==false,'native_updated_hook_registered');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_form_meta WHERE meta_key='fluentcrm_feeds'")===0,
 'no_existing_marketing_feed_enabled');
wp_set_current_user($admin);
$actor=static fn($prior,$form,$sub,$id)=>$admin;
try{
 $wpdb->query('START TRANSACTION');
 $now=gmdate('Y-m-d H:i:s');
 $wpdb->insert($wpdb->prefix.'fluentform_submissions',[
  'form_id'=>3,'status'=>'unread',
  'response'=>wp_json_encode(['dropdown'=>'Mr']),
  'source_url'=>'http://example.invalid/native-feed-callback-only',
  'created_at'=>$now,'updated_at'=>$now
 ]);
 $entryId=(int)$wpdb->insert_id;
 $entry=(object)['id'=>$entryId,'form_id'=>3,'status'=>'unread'];
 $form=(object)['id'=>3];
 $subscriber=(object)['id'=>$contact];
 $ok($entryId>0,'original_fluent_form_fixture_saved');
 $missing=FluentCrmFeedBridge::verify($subscriber,$entry,$form,['id'=>999000]);
 $ok(is_wp_error($missing)&&$missing->get_error_code()==='native_crm_feed_not_registered',
 'unregistered_feed_cannot_assert_contact_relation');
 $formMeta=$wpdb->prefix.'fluentform_form_meta';
 $wpdb->insert($formMeta,[
  'form_id'=>3,'meta_key'=>'fluentcrm_feeds',
  'value'=>wp_json_encode(['enabled'=>false,'name'=>'QA disabled feed'])
 ]);
 $feedId=(int)$wpdb->insert_id;
 $feed=['id'=>$feedId,'form_id'=>3,'meta_key'=>'fluentcrm_feeds',
  'settings'=>['enabled'=>false]];
 $inactive=FluentCrmFeedBridge::verify($subscriber,$entry,$form,$feed);
 $ok(is_wp_error($inactive)&&$inactive->get_error_code()==='native_crm_feed_not_active',
  'disabled_native_feed_not_authoritative');
 $wpdb->update($formMeta,['value'=>wp_json_encode([
  'enabled'=>true,'name'=>'QA synthetic callback only'
 ])],['id'=>$feedId]);
 $feed['settings']['enabled']=true;
 $wrong=FluentCrmFeedBridge::verify($subscriber,$entry,(object)['id'=>6],$feed);
 $ok(is_wp_error($wrong),'wrong_native_form_id_denied');
 $ok(!is_wp_error(FluentCrmFeedBridge::verify($subscriber,$entry,$form,$feed)),
  'matching_stored_original_and_active_feed_proof_validated');
 $beforeJobs=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_workflow_jobs");
 // No approved principal: callback must not create any relation or job.
 do_action('fluent_crm/contact_added_by_fluentform',
     $subscriber,$entry,$form,$feed);
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_workflow_jobs")===$beforeJobs,
   'without_approved_actor_no_automation');
 add_filter('commerce_composition_native_crm_feed_actor_id',$actor,90,4);
 do_action('fluent_crm/contact_added_by_fluentform',
     $subscriber,$entry,$form,$feed);
 $jobs=$wpdb->prefix.'commerce_workflow_jobs';
 $row=$wpdb->get_row("SELECT id,state,action_key FROM $jobs ORDER BY id DESC LIMIT 1",ARRAY_A);
 $jobId=(int)($row['id']??0);
 $ok($jobId>0 && $row['state']==='queued' &&
    $row['action_key']==='link_contact_record' &&
    (int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs")===$beforeJobs+1,
  'native_add_feed_event_produced_exact_idempotent_link_job');
 do_action('fluent_crm/contact_updated_by_fluentform',
     $subscriber,$entry,$form,$feed);
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs")===$beforeJobs+1,
  'native_update_event_dedupes_original_job');
 $finished=WorkflowJobs::process($jobId);
 $ok(!is_wp_error($finished)&&$finished['status']==='succeeded',
  'background_worker_links_original_fluentcrm_identity');
 $linked=$wpdb->get_row($wpdb->prepare(
  "SELECT contact_id,source_id,role FROM {$wpdb->prefix}commerce_contact_links
  WHERE source_key=%s AND source_id=%d",
  'inquiry',$entryId
 ),ARRAY_A);
 $ok(is_array($linked)&&(int)$linked['contact_id']===$contact &&
   $linked['role']==='requester',
  'original_saved_inquiry_references_existing_crm_contact');
 $other=(int)$wpdb->get_var($wpdb->prepare(
  "SELECT id FROM {$wpdb->prefix}fc_subscribers WHERE id<>%d ORDER BY id LIMIT 1",
  $contact
 ));
 $conflict=FluentCrmFeedBridge::verify(
     (object)['id'=>$other],$entry,$form,$feed);
 $ok($other>0&&is_wp_error($conflict)&&
   $conflict->get_error_code()==='native_crm_feed_requester_conflict',
  'same_original_form_cannot_be_reassigned_to_second_contact');
 $legacy=FluentCrmFeedBridge::verify($subscriber,(object)[
  'id'=>$entryId,'form_id'=>6,'status'=>'unread'
 ],$form,$feed);
 $ok(is_wp_error($legacy),'cross_form_fake_entry_object_denied');
 $missingContact=FluentCrmFeedBridge::verify(
  (object)['id'=>999999999],$entry,$form,$feed);
 $ok(is_wp_error($missingContact)&&
   $missingContact->get_error_code()==='native_crm_feed_contact_invalid',
  'unknown_contact_id_denied');
 remove_filter('commerce_composition_native_crm_feed_actor_id',$actor,90);
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers")===$before['fc_subscribers'],
  'native_adapter_does_not_create_subscribe_or_modify_contacts');
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$before['wc_orders'],
  'never_creates_payment_or_commercial_order');
}finally{
 $wpdb->query('ROLLBACK');
 remove_filter('commerce_composition_native_crm_feed_actor_id',$actor,90);
 wp_set_current_user($original);
}
foreach($before as $name=>$n)
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$name")===$n,
 'qa_rollback_'.$name);
echo wp_json_encode(['result'=>$fail?'FAIL':'PASS','checks'=>$pass,
 'failed'=>$fail,'native_events'=>true,'real_feed_mutated'=>false,
 'consent_statuses_unchanged'=>true,'qa_rolled_back'=>true]).PHP_EOL;
if($fail)exit(2);
