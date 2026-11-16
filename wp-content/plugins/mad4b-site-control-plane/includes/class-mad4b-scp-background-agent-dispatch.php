<?php
if(!defined('ABSPATH')) exit;
/**
 * First-party durable lease/fencing only. Never invokes a provider, grants
 * an execution principal, publishes content, or retries an uncertain claim.
 */
final class MAD4B_SCP_Background_Agent_Dispatch {
 private static function uncertain($phase){
  return new WP_Error('agent_durable_receipt_uncertain',
   'Durable worker operation may have completed without an exact readback.',
   array('phase'=>$phase,'recovery_required'=>true,
     'reconciliation_required'=>true,'blind_retry_allowed'=>false));
 }
 private static function valid_lease($lease,array $admission,$work_id,$epoch=0){
  return is_array($lease)
   &&class_exists('MAD4B_SCP_Durable_Execution',false)
   &&($lease['contract']??null)===MAD4B_SCP_Durable_Execution::LEASE_CONTRACT
   &&($lease['work_id']??null)===$work_id
   &&($lease['aggregate_type']??null)==='content_job'
   &&($lease['aggregate_id']??null)===$admission['job_id']
   &&($lease['worker_id']??null)===$admission['worker_id']
   &&($lease['status']??null)==='active'
   &&is_int($lease['lease_epoch']??null)
   &&$lease['lease_epoch']>0
   &&($epoch===0||$lease['lease_epoch']===$epoch)
   &&is_int($lease['expected_aggregate_revision']??null)
   &&$lease['expected_aggregate_revision']===$admission['revision'];
 }
 private static function admitted(array $request){
  $admission=MAD4B_SCP_Background_Agent_Admission::check($request);
  if(is_wp_error($admission))return $admission;
  if(!is_array($admission)||($admission['admitted']??null)!==true
     ||!class_exists('MAD4B_SCP_Durable_Execution',false))
   return new WP_Error('agent_original_lease_unavailable',
     'Verified background admission and durable execution are required.');
  return $admission;
 }
 public static function claim(array $request) {
  $a=self::admitted($request);
  if(is_wp_error($a))return $a;
  $work_id=hash('sha256',$a['job_id'].'|'.$a['plan_sha256']);
  try {
   $lease=MAD4B_SCP_Durable_Execution::acquire_lease(
    $work_id,'content_job',$a['job_id'],$a['worker_id'],(int)$a['revision'],120);
  } catch(Throwable $error) {return self::uncertain('lease_acquire');}
  if(is_wp_error($lease))return $lease;
  if(!self::valid_lease($lease,$a,$work_id))
   return self::uncertain('lease_acquire_readback');
  return array('work_id'=>$work_id,'lease'=>$lease,'job_id'=>$a['job_id'],
   'provider_execution_authorized'=>false,'publication_authorized'=>false);
 }
 public static function heartbeat(array $request) {
  $a=self::admitted($request);
  if(is_wp_error($a))return $a;
  $epoch=absint($request['lease_epoch']??0);
  if($epoch<1)return new WP_Error('agent_lease_epoch_invalid',
    'Positive original durable fencing epoch is required.');
  $work_id=hash('sha256',$a['job_id'].'|'.$a['plan_sha256']);
  try {
   $lease=MAD4B_SCP_Durable_Execution::heartbeat($work_id,$a['worker_id'],$epoch,120);
  } catch(Throwable $error) {return self::uncertain('lease_heartbeat');}
  if(is_wp_error($lease))return $lease;
  return self::valid_lease($lease,$a,$work_id,$epoch)
   ?$lease:self::uncertain('lease_heartbeat_readback');
 }
 public static function verify_commit(array $request) {
  $a=self::admitted($request);
  if(is_wp_error($a))return $a;
  $epoch=absint($request['lease_epoch']??0);
  if($epoch<1)return new WP_Error('agent_lease_epoch_invalid',
    'Positive original durable fencing epoch is required.');
  $work_id=hash('sha256',$a['job_id'].'|'.$a['plan_sha256']);
  try {
   $f=MAD4B_SCP_Durable_Execution::assert_fencing_token(
    $work_id,$a['worker_id'],$epoch,(int)$a['revision']);
  } catch(Throwable $error) {
   return new WP_Error('agent_fence_unavailable',
    'Original immutable fencing token could not be verified.',
    array('blind_retry_allowed'=>false));
  }
  if(is_wp_error($f))return $f;
  if(!self::valid_lease($f,$a,$work_id,$epoch))
   return new WP_Error('agent_fence_unverified',
    'An exact active durable lease receipt is required.',
    array('blind_retry_allowed'=>false));
  return array('verified'=>true,'fence'=>$f,'mutation_performed'=>false);
 }
}
