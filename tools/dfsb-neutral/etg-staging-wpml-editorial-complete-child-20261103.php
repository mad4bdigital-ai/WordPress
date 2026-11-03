<?php
/** Exact, original WPML ContentJob review and completion, ETG Staging only. */
if(!defined('ABSPATH')||wp_get_environment_type()!=='staging'||
 rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com'||
 get_current_user_id()!==26||!current_user_can('manage_options')||
 !MAD4B_SCP_Policy::can_mutate())
 throw new RuntimeException('native_editorial_reviewer_not_authorized');
$locale=(string)getenv('MAD4B_EXACT_EDITORIAL_LOCALE');
$jobs=[
 'ar'=>'45076890-9fc9-410d-9dba-7a4231729647',
 'de'=>'57679eb4-1843-46b4-99a7-294e30e4a67e',
 'es'=>'720cb9e8-ec58-4e0c-8c8c-2540927a1580',
 'it'=>'1c946b72-1cbb-4f5e-b671-484db19cf5e7'];
if(!isset($jobs[$locale]))throw new RuntimeException('exact_native_editorial_locale_required');
$job=$jobs[$locale];
$initial=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job]);
if(is_wp_error($initial)||($initial['job']['state']??'')!=='QUEUED'||
 (int)($initial['job']['job_revision']??0)!==2)
 throw new RuntimeException('native_editorial_job_not_at_original_revision');
$review=MAD4B_WPML_ContentJob_Adapter::binding(['job_id'=>$job]);
if(is_wp_error($review)||($review['readback_verified']??false)!==true||
 ($review['editorial_review_receipt_state']??'')!=='ORIGINAL_WPML_EDITORIAL_REVIEW_VERIFIED'||
 !empty($review['review_blockers'])||
 empty($review['editorial_review_reference']))
 throw new RuntimeException('original_current_wpml_editorial_qa_not_verified');
$intentKey='mad4b_wpml_editorial_job_completion_'.hash('sha256',
 MAD4B_SCP_Site_Profile::site_uuid().'|'.$job);
if(!add_option($intentKey,[
 'contract'=>'mad4b.wpml.original-editorial-job-completion.v1',
 'job_id'=>$job,'locale'=>$locale,'state'=>'IN_PROGRESS',
 'review_reference'=>$review['editorial_review_reference'],'created_at'=>gmdate('c')
 ],'',false))
 throw new RuntimeException('original_editorial_job_completion_attempt_exists');
$r=MAD4B_WPML_ContentJob_Adapter::reconcile_verified(1,$job);
$wait=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job]);
if(is_wp_error($r)||($r['summary']['waiting_review']??0)!==1||
 is_wp_error($wait)||($wait['job']['state']??'')!=='WAITING_REVIEW')
 throw new RuntimeException('native_waiting_review_transition_unconfirmed');
$fresh=MAD4B_WPML_ContentJob_Adapter::binding(['job_id'=>$job]);
if(is_wp_error($fresh)||($fresh['readback_verified']??false)!==true||
 ($fresh['editorial_review_reference']??'')!==$review['editorial_review_reference'])
 throw new RuntimeException('original_editorial_receipt_moved_after_transition');
$approve=MAD4B_WPML_ContentJob_Adapter::approve_verified($job,
 $fresh['editorial_review_reference']);
$done=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job]);
$pass=!is_wp_error($approve) &&
 ($approve['state']??'')==='COMPLETED' &&
 !is_wp_error($done)&&($done['job']['state']??'')==='COMPLETED' &&
 (int)$done['job']['job_revision']===5;
$receipt=[
 'contract'=>'mad4b.wpml.original-editorial-job-completion.v1',
 'job_id'=>$job,'locale'=>$locale,'state'=>$pass?'COMPLETED_VERIFIED':'UNKNOWN',
 'review_reference'=>$fresh['editorial_review_reference'],
 'native_job_state'=>$done['job']['state']??'unread',
 'native_job_revision'=>$done['job']['job_revision']??0,
 'wpml_translation_reconciliation'=>true,
 'published_content_changed'=>false,'finished_at'=>gmdate('c')];
$prior=(array)get_option($intentKey,[]);
if(($prior['state']??'')!=='IN_PROGRESS')
 throw new RuntimeException('original_job_completion_claim_moved');
$updated=$prior;$updated['result']=$receipt;$updated['state']=$pass?'COMPLETED_VERIFIED':'UNKNOWN';
update_option($intentKey,$updated,false);
if(get_option($intentKey,[])!==$updated)
 throw new RuntimeException('original_completion_receipt_writeback_failed');
echo wp_json_encode($receipt,JSON_UNESCAPED_SLASHES),PHP_EOL;
if(!$pass)throw new RuntimeException('native_completion_uncertain_no_blind_retry');