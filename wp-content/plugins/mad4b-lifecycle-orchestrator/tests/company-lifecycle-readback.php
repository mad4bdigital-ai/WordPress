<?php
/**
 * MAD4B source Lifecycle evidence only. WP-CLI readback: no mutation APIs.
 * Deliberately excludes task payloads, ContentJob bodies, tokens and PII.
 */
if(!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='staging'||
 untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com'){
 fwrite(STDERR,"LIFECYCLE_READBACK_EXACT_SOURCE_STAGING_REQUIRED\n");exit(2);
}
global $wpdb;
$table=$wpdb->prefix.'mad4b_hub_bulk_tasks';
$batch=$wpdb->prefix.'mad4b_hub_bulk_batches';
$groups=$wpdb->get_results("SELECT q.state,q.operation,COUNT(*) count,
 MIN(q.created_at) oldest,MAX(q.updated_at) newest FROM $table q
 JOIN $batch b ON b.batch_id=q.batch_id
 WHERE b.state='ACTIVE' GROUP BY q.state,q.operation
 ORDER BY count DESC LIMIT 75",ARRAY_A);
if($wpdb->last_error){fwrite(STDERR,"QUEUE_SQL_READBACK_FAILED\n");exit(2);}
$assigned=$wpdb->get_results("SELECT q.state,
 COALESCE(JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.assigned_executor')),'unset') executor,
 COUNT(*) count
 FROM $table q JOIN $batch b ON b.batch_id=q.batch_id
 WHERE b.state='ACTIVE' GROUP BY q.state,executor ORDER BY count DESC LIMIT 30",ARRAY_A);
if($wpdb->last_error){fwrite(STDERR,"QUEUE_EXECUTOR_SQL_FAILED\n");exit(2);}
// Source-scoped, non-mutating proof partition. Equality of a job revision
// does NOT imply the original Context Evidence certificate remains current.
$jobs=$wpdb->prefix.'mad4b_content_jobs';
$revision=$wpdb->get_row("SELECT
 COUNT(*) AS total,
 SUM(j.job_id IS NULL) AS source_missing,
 SUM(j.job_id IS NOT NULL AND
     (j.stage<>q.stage OR j.state NOT IN ('NEW','QUEUED','RUNNING')))
     AS source_stage_or_state_mismatch,
 SUM(j.job_id IS NOT NULL AND j.stage=q.stage
     AND j.state IN ('NEW','QUEUED','RUNNING')
     AND JSON_EXTRACT(q.payload_json,'$.content_job_revision') IS NULL)
     AS revision_missing,
 SUM(j.job_id IS NOT NULL AND j.stage=q.stage
     AND j.state IN ('NEW','QUEUED','RUNNING')
     AND JSON_EXTRACT(q.payload_json,'$.content_job_revision') IS NOT NULL
     AND j.job_revision=CAST(JSON_UNQUOTE(
         JSON_EXTRACT(q.payload_json,'$.content_job_revision')) AS UNSIGNED))
     AS current_revision,
 SUM(j.job_id IS NOT NULL AND j.stage=q.stage
     AND j.state IN ('NEW','QUEUED','RUNNING')
     AND JSON_EXTRACT(q.payload_json,'$.content_job_revision') IS NOT NULL
     AND j.job_revision<>CAST(JSON_UNQUOTE(
         JSON_EXTRACT(q.payload_json,'$.content_job_revision')) AS UNSIGNED))
     AS stale_revision
 FROM $table q JOIN $batch b ON b.batch_id=q.batch_id
 LEFT JOIN $jobs j ON j.job_id=q.content_job_id
 WHERE b.state='ACTIVE' AND q.state IN ('QUEUED','RETRY')
 AND q.operation='runtime_resolve'
 AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.assigned_executor')),
   'mcp_client')='vps_runner'",ARRAY_A);
if(!is_array($revision)||$wpdb->last_error){
 fwrite(STDERR,"QUEUE_REVISION_READBACK_FAILED\n");exit(2);
}
$revisionCounts=[];
foreach(['total','source_missing','source_stage_or_state_mismatch',
         'revision_missing','current_revision','stale_revision'] as $field)
 $revisionCounts[$field]=(int)($revision[$field]??0);
if($revisionCounts['total']!==array_sum(array_diff_key($revisionCounts,['total'=>1]))){
 fwrite(STDERR,"QUEUE_REVISION_PROOF_PARTITION_DRIFT\n");exit(2);
}
$revisionCounts['contract']='mad4b.hub-runtime-revision-readback.v1';
$revisionCounts['scope']='tours-central:staging:active:vps_runner:runtime_resolve';
$revisionCounts['original_context_certificates_verified']=null;
$revisionCounts['source_job_body_read']=false;
$revisionCounts['tasks_mutated']=false;
$revisionCounts['queue_claims_created']=false;
$revisionCounts['original_certification_required']=true;

$options=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options}
 WHERE option_name LIKE %s LIMIT 10000",$wpdb->esc_like('mad4b_lifecycle_event_v1_').'%'));
$byState=[];$scope=[];$bad=0;
foreach($options as $key){
 $row=get_option($key,[]);
 if(!is_array($row)){$bad++;continue;}
 $state=(string)($row['state']??'UNKNOWN');
 $byState[$state]=($byState[$state]??0)+1;
 if($state==='VERIFIED'){
  $name=(string)($row['verification_scope']??'none');
  $scope[$name]=($scope[$name]??0)+1;
 }
}
$cache=class_exists('MAD4B_Cache_Lifecycle_Control_V1',false)
  ?MAD4B_Cache_Lifecycle_Control_V1::status():null;
$selected=[];
foreach(['contract','version','home','environment','pending','verified','failed',
    'total_routes','oldest_unverified_seconds','observed_not_cacheable','verification_scope',
    'full_lifecycle_accepted','last_run'] as $key)
 if(is_array($cache) && array_key_exists($key,$cache))$selected[$key]=$cache[$key];
echo wp_json_encode([
 'contract'=>'mad4b.lifecycle-source-evidence-readback.v1',
 'observed_utc'=>gmdate('c'),'origin'=>home_url('/'),
 'environment'=>wp_get_environment_type(),
 'readonly'=>true,'execution_performed'=>false,'job_state_modified'=>false,
 'content_job_data_read'=>false,
 'lifecycle_records'=>count($options),'lifecycle_state_counts'=>$byState,
 'lifecycle_verified_scopes'=>$scope,'malformed_lifecycle_records'=>$bad,
 'queue_active_groups'=>$groups,'queue_active_by_executor'=>$assigned,
 'queue_runtime_revision_proof'=>$revisionCounts,
 'cache'=> $selected,
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
