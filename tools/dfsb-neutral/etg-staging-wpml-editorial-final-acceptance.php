<?php
/** Read-only Staging acceptance of native WPML editorial completion. */
if(!defined('ABSPATH')||wp_get_environment_type()!=='staging'||
 rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com')
 throw new RuntimeException('original_wpml_staging_scope_required');
$jobs=['ar'=>['45076890-9fc9-410d-9dba-7a4231729647',40547],
'de'=>['57679eb4-1843-46b4-99a7-294e30e4a67e',34597],
'es'=>['720cb9e8-ec58-4e0c-8c8c-2540927a1580',34605],
'fr'=>['4a302e58-4e06-42b3-9563-4b052d21a511',34599],
'it'=>['1c946b72-1cbb-4f5e-b671-484db19cf5e7',39502]];
$site=MAD4B_SCP_Site_Profile::site_uuid();
$group=MAD4B_SCP_Content_Job_Group_Orchestrator::plan(['source_post_id'=>25909]);
$check=[
 'five_original_wpml_group_members'=>count($group['children']??[])===5,
 'group_already_synced'=>($group['state']??'')==='ALREADY_SYNCED',
 'five_structural_postwrite_receipts'=>($group['counts']['postwrite_verified']??0)===5,
 'five_original_editorial_completions'=>($group['counts']['editorial_completed']??0)===5,
 'no_pending_child_holds'=>($group['counts']['hold']??-1)===0,
 'group_write_disabled'=>get_option('mad4b_contentjob_group_apply_enabled','no')==='no',
 'structural_write_disabled'=>get_option('mad4b_contentjob_structural_cwa_enabled','no')==='no',
 'no_exact_structural_write_job'=>get_option('mad4b_contentjob_structural_cwa_exact_job','')===''];
$map=(array)get_option(MAD4B_WPML_ContentJob_Adapter::MAP,[]);
global $wpdb;
foreach($jobs as $lang=>[$jobId,$target]){
 $key=hash('sha256',$site.'|elementor_library|25909|'.$lang);
 $c=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$jobId]);
 $binding=MAD4B_WPML_ContentJob_Adapter::binding(['job_id'=>$jobId]);
 $review=MAD4B_WPML_ContentJob_Adapter::current_editorial_receipt(
   $jobId,25909,$target,$lang);
 $status=$wpdb->get_row($wpdb->prepare(
 "SELECT t.translation_id,s.rid,s.status,s.needs_update,s.md5
 FROM {$wpdb->prefix}icl_translations t
 JOIN {$wpdb->prefix}icl_translation_status s ON s.translation_id=t.translation_id
 WHERE t.element_id=%d AND t.element_type=%s",$target,'post_elementor_library'),ARRAY_A);
 $check[$lang.'_native_job_completed']=!is_wp_error($c)&&
   ($c['job']['state']??'')==='COMPLETED'&&(int)$c['job']['job_revision']===5&&
   (int)$c['job']['current_artifact_id']===$target;
 $check[$lang.'_map_state_reconciled']=($map[$key]['job_id']??'')===$jobId&&
   ($map[$key]['state']??'')==='COMPLETED';
 $check[$lang.'_wpml_completed_not_stale']=$status&&
   (int)$status['status']===ICL_TM_COMPLETE&&
   (int)$status['needs_update']===0&&
   (string)$status['md5']===apply_filters('wpml_tm_element_md5',get_post(25909));
 $check[$lang.'_verified_current_review']=is_array($review)&&
   ($review['state']??'')==='ORIGINAL_WPML_EDITORIAL_REVIEW_VERIFIED'&&
   ($review['review_method']??'')==='AI_ASSISTED_EDITORIAL_VERIFICATION'&&
   ($review['manual_human_editor_attestation_claimed']??true)===false;
 $check[$lang.'_binding_ready']=!is_wp_error($binding)&&
   ($binding['readback_verified']??false)===true&&
   empty($binding['review_blockers'])&&
   ($binding['editorial_review_reference']??'')===($review['review_reference']??null);
 $check[$lang.'_post_content_still_source_verified']=is_array($review)&&
   ($review['source_elementor_sha256']??'')===hash('sha256',
     (string)get_post_meta(25909,'_elementor_data',true))&&
   ($review['target_elementor_sha256']??'')===hash('sha256',
     (string)get_post_meta($target,'_elementor_data',true))&&
   get_post_status($target)==='publish';
}
$history=(array)get_option('mad4b_contentjob_group_followup_history_v1',[]);
$check['old_original_cancelled_jobs_historically_preserved']=
 count(array_filter($history,static fn($x)=>
 ($x['group_id']??'')===($group['group_id']??'')))>=2;
$outbox=(array)get_option('mad4b_elementor_localization_outbox_v1',[]);
$legacy=0;foreach($outbox as $record){
 if((int)($record['source_post_id']??0)!==25909||
 !in_array(($record['target_language']??''),['ar','de','fr'],true))continue;
 if(($record['status']??'')==='resolved_after_readback' &&
   empty($record['receipt_id']) &&
   ($record['readback_passed']??null)!==true)$legacy++;
}
$check['historical_outbox_not_falsely_recertified']=$legacy===3;
$failed=array_keys(array_filter($check,static fn($x)=>$x!==true));
echo wp_json_encode(['contract'=>'mad4b.wpml.editorial-five-final-acceptance.v1',
 'passed'=>count($check)-count($failed),'total'=>count($check),
 'success'=>!$failed,'failed'=>$failed,
 'group_state'=>$group['state']??'UNKNOWN',
 'editorial_completed'=>$group['counts']['editorial_completed']??0,
 'no_content_mutation_performed'=>true,
 'production_modified'=>false],JSON_UNESCAPED_SLASHES),PHP_EOL;
if($failed)throw new RuntimeException('original_wpml_editorial_final_acceptance_failed');
