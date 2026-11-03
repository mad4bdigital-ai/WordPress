<?php
/**
 * ETG Staging-only, one-shot native ContentJob Group followup provisioning.
 * Original WordPress author + original MAD4B ContentJobs for all changes.
 * Never writes Elementor, WPML translated content, or supplier data.
 */
if (!defined('ABSPATH') || get_current_user_id() !== 26 ||
    wp_get_environment_type() !== 'staging' ||
    rtrim(home_url(),'/') !== 'https://tours.dev.mad4b.com' ||
    !MAD4B_SCP_Policy::can_mutate()) throw new RuntimeException('group_provision_original_actor_or_site_denied');

$source=25909;
$expectedGroup='d5063d4ffd16d30021bada593ff74b94605322a2a477ae54a64de51582319665';
$expectedSnapshot='65be216ea10aa4074f2a84f09531c1b135dd96905bf3a083ddbed1e97ac37901';
$group=MAD4B_SCP_Content_Job_Group_Orchestrator::plan(['source_post_id'=>$source]);
if(($group['group_id']??'')!==$expectedGroup || ($group['snapshot_sha256']??'')!==$expectedSnapshot ||
   ($group['counts']['hold']??null)!==5 || count($group['children']??[])!==5)
 throw new RuntimeException('group_exact_snapshot_changed_replan_required');
$scope=MAD4B_SCP_Operational_Integrity::capture();
if(is_wp_error($scope)||($scope['scope']['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a')
 throw new RuntimeException('original_site_integrity_unavailable');
$author=$scope['scope'];
if(!current_user_can('edit_post',$source))throw new RuntimeException('original_source_edit_denied');
global $wpdb;
$lock='mad4b_wpml_jobs_native_intake';
if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,4)',$lock))!==1)
 throw new RuntimeException('original_wpml_intake_lock_busy');
$optionName=MAD4B_WPML_ContentJob_Adapter::MAP;
$historyKey='mad4b_contentjob_group_followup_history_v1';
$results=[];
try {
 foreach($group['children'] as $child) {
   $target=(int)$child['target_id'];$lang=(string)$child['locale'];
   if(($child['state']??'')!=='HOLD'||!$target||!current_user_can('edit_post',$target))
    throw new RuntimeException('child_scope_or_policy_changed_'.$lang);
   $link=MAD4B_SCP_Content_Relationship_Graph::resolve('post',$source,'elementor_library',$lang);
   if(($link['state']??'')!=='RESOLVED'||(int)$link['target_id']!==$target)
    throw new RuntimeException('child_original_wpml_identity_changed_'.$lang);
   $map=(array)get_option($optionName,[]);
   $nativeKey=hash('sha256',$author['site_uuid'].'|elementor_library|'.$source.'|'.$lang);
   $before=$map[$nativeKey]??null;
   if($before && (
     (int)($before['source_post_id']??0)!==$source||
     (int)($before['target_post_id']??0)!==$target||
     ($before['lang']??'')!==$lang||($before['type']??'')!=='elementor_library'))
     throw new RuntimeException('child_original_wpml_map_drift_'.$lang);
   $priorId=(string)($before['job_id']??'');
   $prior=$priorId!==''?MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$priorId]):null;
   if($before && (is_wp_error($prior)||!in_array($prior['job']['state']??'',['CANCELLED','COMPLETED'],true)))
     throw new RuntimeException('child_has_nonterminal_original_job_'.$lang);
   $markerKey='mad4b_cj_group_followup_'.hash('sha256',$author['site_uuid'].'|'.$source.'|'.$target.'|'.$lang.'|'.$expectedGroup);
   if(get_option($markerKey,false)!==false)
     throw new RuntimeException('child_provision_marker_exists_no_blind_retry_'.$lang);
   $marker=['contract'=>'mad4b.contentjob-group.child-provision.v1',
     'group_id'=>$expectedGroup,'source_post_id'=>$source,'target_post_id'=>$target,
     'locale'=>$lang,'prior_job_id'=>$priorId,'state'=>'CLAIMED',
     'claimed_at'=>gmdate('c'),'blind_retry_allowed'=>false];
   if(!add_option($markerKey,$marker,'',false))
     throw new RuntimeException('child_provision_claim_not_persisted_'.$lang);
   $subject='WPML translation — Elementor structural followup #'.$source.' ['.$lang.']'.
     ' wpml-ref:'.$nativeKey.' group-followup-ref:'.$expectedGroup.' target:'.$target;
   $created=MAD4B_SCP_Content_Jobs::create_job([
     'brand_id'=>$author['brand_ref'],'language'=>$lang,'country'=>'global',
     'content_type'=>'translation','target_post_type'=>'elementor_library',
     'automation_level'=>'assisted','subject'=>$subject,
     'reason'=>'New original-target structural design followup; WPML child review, native rights certificate and CWA required.'
   ]);
   if(is_wp_error($created)||empty($created['job']['job_id'])) {
     $marker['state']='UNKNOWN_CREATION';$marker['error']=is_wp_error($created)?$created->get_error_code():'missing_native_readback';
     update_option($markerKey,$marker,false);
     throw new RuntimeException('original_contentjob_create_uncertain_'.$lang.'_'.($marker['error']??''));
   }
   $native=$created['job'];$jobId=$native['job_id'];
   $marker['job_id']=$jobId;$marker['state']='CREATED_UNLINKED';
   update_option($markerKey,$marker,false);
   if($priorId!=='') {
     $archive=(array)get_option($historyKey,[]);
     if(isset($archive[$nativeKey]) && ($archive[$nativeKey]['prior_job_id']??'')!==$priorId)
      throw new RuntimeException('original_history_conflict_'.$lang);
     $archive[$nativeKey]=['prior_job_id'=>$priorId,'replacement_job_id'=>$jobId,
       'group_id'=>$expectedGroup,'target_post_id'=>$target,'locale'=>$lang,
       'prior_state'=>$prior['job']['state'],'recorded_at'=>gmdate('c')];
     update_option($historyKey,$archive,false);
     if((get_option($historyKey,[])[$nativeKey]['replacement_job_id']??'')!==$jobId)
      throw new RuntimeException('original_history_readback_failed_'.$lang);
   }
   $map[$nativeKey]=[
     'job_id'=>$jobId,'source_post_id'=>$source,'target_post_id'=>$target,
     'type'=>'elementor_library','lang'=>$lang,
     'native_wpml_job_ids'=>(array)($before['native_wpml_job_ids']??[]),
     'gap_codes'=>(array)($before['gap_codes']??[]),
     'state'=>'NEW','group_id'=>$expectedGroup,'followup_of'=>$priorId,
     'updated_at'=>gmdate('c')
   ];
   update_option($optionName,$map,false);
   $actual=(array)get_option($optionName,[]);
   if(($actual[$nativeKey]['job_id']??'')!==$jobId)
     throw new RuntimeException('original_wpml_map_readback_failed_'.$lang);
   $marker['state']='LINKED_READY_FOR_ARTIFACT';
   update_option($markerKey,$marker,false);
   $bound=MAD4B_SCP_Content_Jobs::transition_job([
     'job_id'=>$jobId,'expected_revision'=>(int)$native['job_revision'],
     'state'=>'QUEUED','stage'=>'INTAKE','artifact_id'=>(string)$target,
     'reason'=>'Exact WPML localized Elementor artifact verified; independent owner certificate pending.'
   ]);
   if(is_wp_error($bound)||!is_array($bound['job']??null)) {
     $marker['state']='ARTIFACT_OUTCOME_UNCERTAIN';
     $marker['error']=is_wp_error($bound)?$bound->get_error_code():'missing_transition_readback';
     update_option($markerKey,$marker,false);
     throw new RuntimeException('original_artifact_binding_not_verified_'.$lang);
   }
   $read=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$jobId]);
   if(is_wp_error($read)||($read['job']['state']??'')!=='QUEUED'||
      (string)($read['job']['current_artifact_id']??'')!==(string)$target||
      (int)$read['job']['job_revision']!==2)
     throw new RuntimeException('original_artifact_native_readback_changed_'.$lang);
   $marker['state']='QUEUED_ARTIFACT_VERIFIED';
   $marker['job_revision']=2;$marker['completed_at']=gmdate('c');
   update_option($markerKey,$marker,false);
   $results[]=['locale'=>$lang,'target_post_id'=>$target,'job_id'=>$jobId,
     'followup_of'=>$priorId,'state'=>'QUEUED_ARTIFACT_VERIFIED','revision'=>2];
 }
 echo wp_json_encode(['contract'=>'mad4b.contentjob-group.followup-provision.v1',
 'group_id'=>$expectedGroup,'created'=>count($results),'children'=>$results,
 'elementor_writes'=>0,'translation_publications'=>0],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),PHP_EOL;
} finally {
 $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
}
