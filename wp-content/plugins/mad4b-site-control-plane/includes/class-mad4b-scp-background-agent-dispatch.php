<?php
if (!defined('ABSPATH')) exit;
/** Governed claim and lease verification; never invokes a provider or publishes. */
final class MAD4B_SCP_Background_Agent_Dispatch {
 public static function claim(array $request) {
  $a=MAD4B_SCP_Background_Agent_Admission::check($request);
  if(is_wp_error($a))return $a;
  $work_id=hash('sha256',$a['job_id'].'|'.$a['plan_sha256']);
  $lease=MAD4B_SCP_Durable_Execution::acquire_lease($work_id,'content_job',$a['job_id'],$a['worker_id'],(int)$a['revision'],120);
  if(is_wp_error($lease))return $lease;
  return array('work_id'=>$work_id,'lease'=>$lease,'job_id'=>$a['job_id'],'provider_execution_authorized'=>false,'publication_authorized'=>false);
 }
 public static function heartbeat(array $request) {
  $a=MAD4B_SCP_Background_Agent_Admission::check($request);
  if(is_wp_error($a))return $a;
  $work_id=hash('sha256',$a['job_id'].'|'.$a['plan_sha256']);
  return MAD4B_SCP_Durable_Execution::heartbeat($work_id,$a['worker_id'],absint($request['lease_epoch']??0),120);
 }
 public static function verify_commit(array $request) {
  $a=MAD4B_SCP_Background_Agent_Admission::check($request);
  if(is_wp_error($a))return $a;
  $work_id=hash('sha256',$a['job_id'].'|'.$a['plan_sha256']);
  $f=MAD4B_SCP_Durable_Execution::assert_fencing_token($work_id,$a['worker_id'],absint($request['lease_epoch']??0),(int)$a['revision']);
  if(is_wp_error($f))return $f;
  return array('verified'=>true,'fence'=>$f,'mutation_performed'=>false);
 }
}
