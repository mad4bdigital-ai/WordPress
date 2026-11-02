<?php
/**
 * MAD4B Dynamic ContentJob Group Orchestration.
 * Original WPML family, original ContentJobs, and per-target CWA only.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Content_Job_Group_Orchestrator {
 const CONTRACT='mad4b.contentjob-group.v1';
 const RUNS='mad4b_cj_group_run_';
 const MAX_CHILDREN=25;
 private static function hold(string $reason):array {
  return ['contract'=>self::CONTRACT,'state'=>'HOLD','reason'=>$reason,
   'mutation_performed'=>false];
 }
 public static function plan($input=[]):array {
  $source=absint(((array)$input)['source_post_id']??0);
  if(!$source||!class_exists('MAD4B_WPML_Design_Reconciliation_V1',false)||
     !class_exists('MAD4B_WPML_ContentJob_Adapter',false))
   return self::hold('original_wpml_group_owner_unavailable');
  $scope=MAD4B_SCP_Central_Write_Acceptance::site_scope();
  if(empty($scope['site_uuid'])||get_post_type($source)!=='elementor_library')
   return self::hold('group_site_or_source_invalid');
  $preview=MAD4B_WPML_Design_Reconciliation_V1::reconcile($source,false);
  if(is_wp_error($preview)||!is_array($preview))
   return self::hold('original_elementor_group_preview_denied');
  $translations=(array)($preview['translations']??[]);
  if(!$translations||count($translations)>self::MAX_CHILDREN)
   return self::hold('group_empty_or_exceeds_bounded_size');
  $sourceHash=hash('sha256',(string)get_post_meta($source,'_elementor_data',true));
  $registry=(array)get_option(MAD4B_WPML_ContentJob_Adapter::MAP,[]);
  $children=[];$counts=['ready'=>0,'hold'=>0,'already_synced'=>0];
  foreach($translations as $item) {
   $target=(int)($item['target']??0);$locale=(string)($item['lang']??'');
   if(!$target||!preg_match('/^[a-z]{2}$/D',$locale))
    return self::hold('invalid_original_member');
   $pair=MAD4B_SCP_Content_Relationship_Graph::resolve(
     'post',$source,'elementor_library',$locale);
   if(($pair['state']??'')!=='RESOLVED'||(int)($pair['target_id']??0)!==$target)
    return self::hold('group_wpml_identity_drift');
   $jobs=[];
   foreach($registry as $record) {
    if((int)($record['source_post_id']??0)===$source &&
       (int)($record['target_post_id']??0)===$target &&
       ($record['lang']??'')===$locale &&
       ($record['type']??'')==='elementor_library' &&
       !empty($record['job_id']))$jobs[]=(string)$record['job_id'];
   }
   $reason='';$owner=[];$jobId=count($jobs)===1?$jobs[0]:'';
   if(empty($item['would_update'])) {
    $state='ALREADY_SYNCED';$counts['already_synced']++;
   }elseif(count($jobs)!==1) {
    $state='HOLD';$reason=count($jobs)>1?'ambiguous_original_contentjobs':'native_child_contentjob_missing';
    $counts['hold']++;
   }else{
    $owner=MAD4B_SCP_Content_Relationship_Writeback::plan([
      'job_id'=>$jobId,'relationship_kind'=>'elementor_template_refs']);
    $state=($owner['state']??'')==='READY'?'READY':'HOLD';
    $reason=$state==='HOLD'?(string)($owner['reason']??'original_owner_not_certified'):'';
    $counts[$state==='READY'?'ready':'hold']++;
   }
   $structural=$jobId!==''?
     MAD4B_SCP_Content_Job_Structural_QA::inspect($jobId):[];
   $attested=$jobId!==''?
     MAD4B_SCP_Content_Job_Structural_QA::receipt($jobId):[];
   $children[]=['target_id'=>$target,'locale'=>$locale,'job_id'=>$jobId,
    'state'=>$state,'reason'=>$reason,
    'structural_qa_state'=>$structural['state']??'NOT_SCANNED',
    'structural_qa_evidence_sha256'=>$structural['evidence_sha256']??null,
    'structural_delta_count'=>$structural['changed_path_count']??0,
    'structural_attestation_state'=>$attested['state']??'NOT_ATTESTED',
    'structural_qa_render_verified'=>false,
    'expected_source_sha256'=>$owner['owner_plan']['source_sha256']??null,
    'current_target_document_sha256'=>hash('sha256',
      (string)get_post_meta($target,'_elementor_data',true)),
    'would_update'=>!empty($item['would_update'])];
  }
  usort($children,fn($a,$b)=>strcmp($a['locale'],$b['locale']));
  $group=hash('sha256',implode('|',[
    self::CONTRACT,$scope['site_uuid'],$scope['environment'],
    'elementor_template_refs',$source]));
  $snapshot=hash('sha256',wp_json_encode([
    $group,$sourceHash,$children],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  $state=$counts['ready']?($counts['hold']?'PARTIAL':'READY'):
   ($counts['hold']?'HOLD':'ALREADY_SYNCED');
  return ['contract'=>self::CONTRACT,'group_id'=>$group,
   'site_uuid'=>$scope['site_uuid'],'environment'=>$scope['environment'],
   'source_post_id'=>$source,'source_document_sha256'=>$sourceHash,
   'snapshot_sha256'=>$snapshot,'state'=>$state,'counts'=>$counts,
   'children'=>$children,'mutation_performed'=>false,
   'parent_job_created'=>false,'child_jobs_reused'=>true,
   'default_apply_disabled'=>true];
 }
 public static function apply($input=[]):array {
  $input=(array)$input;
  if(!MAD4B_SCP_Central_Write_Acceptance::permitted()||
      get_option('mad4b_contentjob_group_apply_enabled','no')!=='yes')
   return self::hold('group_write_requires_original_site_authority_and_opt_in');
  $source=absint($input['source_post_id']??0);
  $plan=self::plan(['source_post_id'=>$source]);
  if(empty($plan['group_id'])||!hash_equals($plan['group_id'],
        (string)($input['group_id']??''))||
     !hash_equals((string)$plan['snapshot_sha256'],
        (string)($input['expected_snapshot_sha256']??'')))
   return self::hold('group_identity_or_snapshot_changed');
  $selected=$input['target_ids']??[];
  if(!is_array($selected)||!$selected||count($selected)>8||
     count(array_unique(array_map('intval',$selected)))!==count($selected))
   return self::hold('group_target_selection_invalid');
  $chosen=[];
  foreach($selected as $id){
   if(!is_int($id)||$id<=0)return self::hold('group_target_ids_must_be_integers');
   $matches=array_values(array_filter($plan['children'],
     fn($c)=>$c['target_id']===$id&&$c['state']==='READY'));
   if(count($matches)!==1)return self::hold('group_child_not_original_owner_ready');
   $chosen[]=$matches[0];
  }
  $scope=MAD4B_SCP_Central_Write_Acceptance::site_scope();
  $key=self::RUNS.hash('sha256',implode('|',[$scope['site_uuid'],
    $plan['snapshot_sha256'],implode(',',array_map('intval',$selected))]));
  if(!add_option($key,['state'=>'IN_PROGRESS','group_id'=>$plan['group_id'],
       'started_at'=>gmdate('c'),'targets'=>$selected],'',false))
   return self::hold('original_group_attempt_exists_reconcile_before_retry');
  $out=[];$completed=0;$stopped=false;
  foreach($chosen as $one){
   $current=MAD4B_SCP_Content_Relationship_Writeback::plan([
     'job_id'=>$one['job_id'],'relationship_kind'=>'elementor_template_refs']);
   if(($current['state']??'')!=='READY'||
      !hash_equals((string)$one['expected_source_sha256'],
       (string)($current['owner_plan']['source_sha256']??''))){
    $out[]=['target_id'=>$one['target_id'],'state'=>'HOLD',
      'reason'=>'original_child_evidence_changed'];$stopped=true;break;
   }
   $write=MAD4B_SCP_Content_Relationship_Writeback::apply([
     'job_id'=>$one['job_id'],'relationship_kind'=>'elementor_template_refs',
     'expected_source_sha256'=>$one['expected_source_sha256']]);
   $r=$write['owner_result']??[];
   $ok=in_array(($r['reason']??''),[
     'VERIFIED_WRITE_AND_READBACK','ALREADY_VERIFIED'],true);
   $out[]=['target_id'=>$one['target_id'],'job_id'=>$one['job_id'],
     'state'=>$ok?'VERIFIED':'HOLD',
     'reason'=>$r['reason']??($write['reason']??'unverified'),
     'receipt_sha256'=>$r['receipt_sha256']??null];
   if(!$ok){$stopped=true;break;}
   $completed++;
  }
  $result=['contract'=>self::CONTRACT,'group_id'=>$plan['group_id'],
    'state'=>$stopped?'PARTIAL_HOLD':'VERIFIED','verified_children'=>$completed,
    'selected_children'=>count($chosen),'children'=>$out,
    'replay_allowed'=>false,'completed_at'=>gmdate('c')];
  update_option($key,$result,false);
  if(get_option($key,[])!==$result)
   return self::hold('group_execution_receipt_readback_failed');
  return $result;
 }
}
