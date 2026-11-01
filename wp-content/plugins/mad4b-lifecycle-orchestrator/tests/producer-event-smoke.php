<?php
$job_id='f09a022c-dcfa-4e85-9302-9a2248bd25a4';
$loaded=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
if(is_wp_error($loaded))throw new RuntimeException($loaded->get_error_code());
$job=$loaded['job'];
$event=['job_id'=>$job_id,'job_revision'=>(int)$job['job_revision'],
 'state'=>$job['state'],'stage'=>$job['stage']];
do_action('mad4b_scp_content_job_transitioned',$event);
$key='rev-'.(int)$event['job_revision'].'-'.strtolower($event['state']);
$plan=MAD4B_Lifecycle_Orchestrator::record(['goal'=>'content',
    'target_kind'=>'content_job','target_id'=>0,'job_id'=>$job_id,
    'event_type'=>'content_job_transitioned','event_key'=>$key]);
$status=MAD4B_Lifecycle_Orchestrator::manual_reconcile(['event_id'=>$plan['event_id']]);
$bad=MAD4B_Lifecycle_Orchestrator::resolve([
    'goal'=>'company','target_kind'=>'company','company_code'=>'invalid-company',
    'manager_id'=>'12345678-1234-4123-a123-123456789abc',
    'event_type'=>'manual','event_key'=>'smoke-20261031']);
echo wp_json_encode(['contract'=>'mad4b.lifecycle-producer-smoke.v1',
 'content_job_id'=>$job_id,
 'source_revision'=>$job['job_revision'],
 'producer_event_id'=>$plan['event_id'],
 'canonical_job_unchanged'=>($loaded['job']['job_revision']??null)===MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id])['job']['job_revision'],
 'replay_reuses_event'=>!empty($plan['idempotent_replay']),
 'reconcile_state'=>is_wp_error($status)?$status->get_error_code():$status['state'],
 'not_falsely_verified'=>!is_wp_error($status)&&empty($status['verified']),
 'company_without_registry_binding_denied'=>is_wp_error($bad)?$bad->get_error_code():'FAIL'
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
