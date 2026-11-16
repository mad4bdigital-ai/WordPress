<?php
if (!defined('ABSPATH')) exit;
/**
 * Fail-closed read-only admission for existing background ContentJobs.
 * A Plan is not authorization: real Policy, live Funnel, exact job and
 * a later original Durable Execution lease all remain mandatory.
 */
final class MAD4B_SCP_Background_Agent_Admission {
 const CONTRACT='mad4b.background-agent-admission.v1';

 public static function check($request){
  if(!is_array($request))
   return new WP_Error('agent_request_invalid','Invalid request.');
  $worker=isset($request['worker_id'])?(string)$request['worker_id']:'';
  $job=isset($request['job_id'])?(string)$request['job_id']:'';
  $digest=isset($request['plan_sha256'])?(string)$request['plan_sha256']:'';
  if(!preg_match('/^[a-z0-9][a-z0-9._-]{2,95}$/D',$worker)
     ||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$job)
     ||!preg_match('/^[a-f0-9]{64}$/D',$digest))
   return new WP_Error('agent_identity_invalid','Worker, job, or plan identity invalid.');

  if(!class_exists('MAD4B_SCP_Policy',false))
   return new WP_Error('agent_mutation_not_authorized',
     'Authenticated mutation authority required.');
  try {
   if(true!==MAD4B_SCP_Policy::can_mutate())
    return new WP_Error('agent_mutation_not_authorized',
      'Authenticated mutation authority required.');
  } catch(Throwable $error) {
   return new WP_Error('agent_mutation_policy_unavailable',
     'Original write authority could not be verified.');
  }
  if(!class_exists('MAD4B_SCP_Unified_Gate_Funnel',false)
     ||!class_exists('MAD4B_SCP_Content_Jobs',false))
   return new WP_Error('agent_original_gate_unavailable',
     'Original gate and ContentJob providers are required.');

  try {
   $plan=MAD4B_SCP_Unified_Gate_Funnel::plan();
   if(!is_array($plan)
      ||($plan['contract']??null)!==MAD4B_SCP_Unified_Gate_Funnel::CONTRACT
      ||!is_string($plan['plan_sha256']??null)
      ||!preg_match('/^[a-f0-9]{64}$/D',$plan['plan_sha256']))
    return new WP_Error('agent_plan_unavailable',
      'An exact first-party dynamic gate plan is required.');
   if(!hash_equals($plan['plan_sha256'],$digest))
    return new WP_Error('agent_plan_stale',
      'Current gate registry and checkpoint do not match requested plan.');

   $ready=MAD4B_SCP_Unified_Gate_Funnel::readiness();
   if(!is_array($ready)
      ||($ready['contract']??null)!==MAD4B_SCP_Unified_Gate_Funnel::CONTRACT
      ||($ready['plan_sha256']??null)!==$digest
      ||($ready['execution_authorized']??null)!==false
      ||!is_array($ready['mandatory_blockers']??null))
    return new WP_Error('agent_readiness_unavailable',
      'The exact original gate readiness is unverified.');
   $blockers=$ready['mandatory_blockers'];
   foreach($blockers as $blocker)
    if(!is_string($blocker)||''===$blocker)
     return new WP_Error('agent_gates_invalid',
       'Original gate readiness returned malformed blockers.');
   // content.execution is the only deliberate worker-claim phase: this
   // admission checks prerequisites, while original lease and permission
   // checks own execution. Every other dynamic required blocker stays active.
   if(!in_array('content.execution',$blockers,true))
    return new WP_Error('agent_execution_gate_missing',
      'Original execution admission is not recorded in gate readiness.');
   $preflight_blockers=array_values(array_diff($blockers,array('content.execution')));
   if($preflight_blockers)
    return new WP_Error('agent_gates_blocked',
      'Required prerequisite gates are blocked.',
      array('blockers'=>$preflight_blockers));

   $found=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$job));
   if(is_wp_error($found))return $found;
   if(!is_array($found)||($found['contract']??null)!==MAD4B_SCP_Content_Jobs::CONTRACT
      ||!is_array($found['job']??null))
    return new WP_Error('agent_job_unverified',
      'Original ContentJob readback did not return a registered job.');
   $item=$found['job'];
   if(!hash_equals($job,(string)($item['job_id']??'')))
    return new WP_Error('agent_job_identity_conflict',
      'Original ContentJob identity changed during admission.');
   if(!in_array((string)($item['state']??''),array('QUEUED','RUNNING'),true))
    return new WP_Error('agent_job_not_runnable','ContentJob is not runnable.');
   if(!is_int($item['job_revision']??null)||$item['job_revision']<1)
    return new WP_Error('agent_job_revision_unverified',
      'Current ContentJob revision must be verified before acquiring a lease.');
   return array('contract'=>self::CONTRACT,'admitted'=>true,
    'worker_id'=>$worker,'job_id'=>$job,'revision'=>$item['job_revision'],
    'plan_sha256'=>$digest,'mutation_performed'=>false);
  } catch(Throwable $error) {
   return new WP_Error('agent_original_gate_exception',
     'Original dynamic Gate/ContentJob preflight could not be verified.');
  }
 }
}
