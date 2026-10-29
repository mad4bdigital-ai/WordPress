<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
global $wpdb;
$pass=0;$fail=[];$ok=static function($v,$name)use(&$pass,&$fail){
 $v?$pass++:$fail[]=$name;
};
$before=[];
foreach(['fluentform_submissions','fluentform_submission_meta',
 'fluentform_entry_details','fc_subscribers','commerce_workflow_jobs',
 'commerce_submission_contexts','commerce_contact_links','wc_orders'] as $t){
 $table=$wpdb->prefix.$t;
 if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table)
  $before[$t]=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
}
$sub=0;$sent=0;$nativeHook=0;$completed=0;
$blockMail=static function($pre)use(&$sent){$sent++;return true;};
$forceSync=static fn($enabled,$form)=>false;
$nativeListener=static function($id,$payload,$form)use(&$nativeHook){
 if((int)($form->id??0)===3)$nativeHook++;
};
$done=static function($id,$form)use(&$completed){
 if((int)($form->id??0)===3)$completed++;
};
add_filter('pre_wp_mail',$blockMail,PHP_INT_MAX);
add_filter('fluentform/notifying_async_email',$forceSync,PHP_INT_MAX,2);
add_filter('fluentform/notifying_async_notifications',$forceSync,PHP_INT_MAX,2);
add_action('fluentform/submission_inserted',$nativeListener,9999,3);
add_action('fluentform/global_notify_completed',$done,9999,2);
$previous=get_current_user_id();wp_set_current_user(0);
$wpdb->query('START TRANSACTION');
try {
 $handler=new \FluentForm\App\Services\Form\SubmissionHandlerService();
 $input=[
  'dropdown'=>'Mr',
  'names'=>['first_name'=>'QA','last_name'=>'Isolated'],
  'email'=>'qa-example-no-delivery@example.invalid',
  'description'=>'Isolated lifecycle verification only; no booking or marketing approval'
 ];
 try {
  $result=$handler->handleSubmission($input,3);
  $sub=(int)($result['insert_id']??0);
  $ok($sub>0,'native_fluent_form_handler_inserted_saved_original');
  $ok($nativeHook===1,'native_saved_submission_event_was_dispatched');
  $ok($completed>0,'native_notifications_completed_listener_dispatched');
  $row=$wpdb->get_row($wpdb->prepare(
   "SELECT id,form_id,status FROM {$wpdb->prefix}fluentform_submissions WHERE id=%d",$sub
  ),ARRAY_A);
  $ok(is_array($row)&&(int)$row['form_id']===3,'native_source_stored_in_fluent_table');
  $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers")===$before['fc_subscribers'],
   'native_lifecycle_does_not_create_marketing_subscribers');
  $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches")===0,
   'inquiry_only_never_creates_commercial_or_request_registry_records');
  $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$before['wc_orders'],
   'no_wc_order_created');
 }catch(\Throwable $e){
   $fail[]='native_handler_threw_'.get_class($e).
    '_'.substr($e->getMessage(),0,100);
 }
}finally{
 $wpdb->query('ROLLBACK');
 remove_filter('pre_wp_mail',$blockMail,PHP_INT_MAX);
 remove_filter('fluentform/notifying_async_email',$forceSync,PHP_INT_MAX);
 remove_filter('fluentform/notifying_async_notifications',$forceSync,PHP_INT_MAX);
 remove_action('fluentform/submission_inserted',$nativeListener,9999);
 remove_action('fluentform/global_notify_completed',$done,9999);
 wp_set_current_user($previous);
}
foreach($before as $t=>$n)
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$t")===$n,
  'qa_rollback_original_'.$t);
echo wp_json_encode(['result'=>$fail?'FAIL':'PASS','passed'=>$pass,
 'failed'=>$fail,'original_fluent_submission_id'=>$sub,
 'native_hook_count'=>$nativeHook,'notify_completed'=>$completed,
 'mail_intercept_count'=>$sent,
 'egress_blocked'=>true,'qa_db_rollback'=>true]).PHP_EOL;
if($fail)exit(2);
