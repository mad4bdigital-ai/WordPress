<?php
if (!defined('ABSPATH')) exit;
/** Fail-closed admission contract for background ContentJob workers. No writes. */
final class MAD4B_SCP_Background_Agent_Admission {
 const CONTRACT='mad4b.background-agent-admission.v1';
 public static function check($request){
  if(!is_array($request))return new WP_Error('agent_request_invalid','Invalid request.');
  $worker=isset($request['worker_id'])?(string)$request['worker_id']:'';
  $job=isset($request['job_id'])?(string)$request['job_id']:'';
  $digest=isset($request['plan_sha256'])?(string)$request['plan_sha256']:'';
  if(!preg_match('/^[a-z0-9][a-z0-9._-]{2,95}$/D',$worker)||!preg_match('/^[a-f0-9-]{36}$/D',$job)||!preg_match('/^[a-f0-9]{64}$/D',$digest))return new WP_Error('agent_identity_invalid','Worker, job, or plan identity invalid.');
  if(!class_exists('MAD4B_SCP_Policy')||true!==MAD4B_SCP_Policy::can_mutate())return new WP_Error('agent_mutation_not_authorized','Authenticated mutation authority required.');
  $plan=MAD4B_SCP_Unified_Gate_Funnel::plan();
  if(!hash_equals($plan['plan_sha256'],$digest))return new WP_Error('agent_plan_stale','Current gate registry and checkpoint do not match requested plan.');
  $ready=MAD4B_SCP_Unified_Gate_Funnel::readiness();
  $preflight_blockers=array_values(array_diff($ready['mandatory_blockers'],array('content.execution')));
  if($preflight_blockers)return new WP_Error('agent_gates_blocked','Required prerequisite gates are blocked.',array('blockers'=>$preflight_blockers));
  $found=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$job));
  if(is_wp_error($found))return $found;
  $item=$found['job']??array();
  if(!in_array((string)($item['state']??''),array('QUEUED','RUNNING'),true))return new WP_Error('agent_job_not_runnable','ContentJob is not runnable.');
  return array('contract'=>self::CONTRACT,'admitted'=>true,'worker_id'=>$worker,'job_id'=>$job,'revision'=>$item['job_revision']??null,'plan_sha256'=>$digest,'mutation_performed'=>false);
 }
}
