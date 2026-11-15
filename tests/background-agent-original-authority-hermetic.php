<?php
/** First-party Background ContentJob admission and durable fencing, no site or DB. */
define('ABSPATH', __DIR__ . '/');
class WP_Error {
 private $code;
 private $data;
 public function __construct($code,$message='',$data=array()){$this->code=$code;$this->data=$data;}
 public function get_error_code(){return $this->code;}
 public function get_error_data(){return $this->data;}
}
function is_wp_error($result){return $result instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function background_assert($condition,$name) {
 if(!$condition) {fwrite(STDERR,"FAIL: Background agent boundary: ".$name."\n");exit(1);}
}
final class MAD4B_SCP_Policy {
 public static $decision=true;
 public static function can_mutate(){
   if(self::$decision==='throw')throw new DomainException('policy-unavailable');
   return self::$decision;
 }
}
final class MAD4B_SCP_Unified_Gate_Funnel {
 const CONTRACT='mad4b.gate-funnel-plan.v1';
 public static $plan;
 public static $readiness;
 public static function plan(){return self::$plan;}
 public static function readiness(){return self::$readiness;}
}
final class MAD4B_SCP_Content_Jobs {
 const CONTRACT='mad4b.content-job.v1';
 public static $result;
 public static function get_job($request){
  $GLOBALS['job_reads']++;
  return self::$result;
 }
}
final class MAD4B_SCP_Durable_Execution {
 const LEASE_CONTRACT='mad4b.execution-plane.v1';
 public static $result="fixture_default";
 public static $leased=0;
 public static $heartbeats=0;
 public static $fences=0;
 public static function receipt($work,$worker,$revision,$epoch=1){
  return array('contract'=>self::LEASE_CONTRACT,'work_id'=>$work,
   'aggregate_type'=>'content_job','aggregate_id'=>$GLOBALS['job_id'],
   'worker_id'=>$worker,'lease_epoch'=>$epoch,
   'expected_aggregate_revision'=>$revision,'status'=>'active');
 }
 public static function acquire_lease($work,$type,$job,$worker,$revision,$ttl){
  self::$leased++;
  return self::$result==="fixture_default"?self::receipt($work,$worker,$revision):self::$result;
 }
 public static function heartbeat($work,$worker,$epoch,$ttl){
  self::$heartbeats++;
  return self::$result==="fixture_default"?self::receipt($work,$worker,2,$epoch):self::$result;
 }
 public static function assert_fencing_token($work,$worker,$epoch,$revision){
  self::$fences++;
  return self::$result==="fixture_default"?self::receipt($work,$worker,$revision,$epoch):self::$result;
 }
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-background-agent-admission.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-background-agent-dispatch.php';
function background_reset(){
 MAD4B_SCP_Policy::$decision=true;
 $GLOBALS['job_id']='11111111-1111-4111-8111-111111111111';
 $GLOBALS['job_reads']=0;
 MAD4B_SCP_Unified_Gate_Funnel::$plan=array(
   'contract'=>MAD4B_SCP_Unified_Gate_Funnel::CONTRACT,
   'plan_sha256'=>str_repeat('a',64),
 );
 MAD4B_SCP_Unified_Gate_Funnel::$readiness=array(
   'contract'=>MAD4B_SCP_Unified_Gate_Funnel::CONTRACT,
   'plan_sha256'=>str_repeat('a',64),
   'mandatory_blockers'=>array('content.execution'),
   'execution_authorized'=>false,
 );
 MAD4B_SCP_Content_Jobs::$result=array(
   'contract'=>MAD4B_SCP_Content_Jobs::CONTRACT,
   'job'=>array('job_id'=>$GLOBALS['job_id'],
       'state'=>'QUEUED','job_revision'=>2),
 );
 MAD4B_SCP_Durable_Execution::$result="fixture_default";
 MAD4B_SCP_Durable_Execution::$leased=0;
 MAD4B_SCP_Durable_Execution::$heartbeats=0;
 MAD4B_SCP_Durable_Execution::$fences=0;
}
function background_request(){
 return array('worker_id'=>'registered-worker-1',
   'job_id'=>$GLOBALS['job_id'],
   'plan_sha256'=>str_repeat('a',64),'lease_epoch'=>1);
}
background_reset();
$admitted=MAD4B_SCP_Background_Agent_Admission::check(background_request());
background_assert(is_array($admitted)
 && $admitted['admitted']===true
 && $admitted['revision']===2
 && $admitted['mutation_performed']===false,
 'Only exact plan, policy and runnable original job admitted');
foreach(array(false,null,new WP_Error('policy_unavailable'),1) as $choice){
 background_reset();
 MAD4B_SCP_Policy::$decision=$choice;
 background_assert(is_wp_error(MAD4B_SCP_Background_Agent_Admission::check(background_request())),
    'Non-exact permission denied even if truthy WP_Error');
 background_assert($GLOBALS['job_reads']===0,'Policy refusal precedes job inspection');
}
background_reset();
MAD4B_SCP_Policy::$decision='throw';
background_assert(is_wp_error(MAD4B_SCP_Background_Agent_Admission::check(background_request())),
    'Exceptions from original policy must fail closed without a PHP fatal');
background_reset();
MAD4B_SCP_Unified_Gate_Funnel::$plan=new WP_Error('broken_plan');
background_assert(is_wp_error(MAD4B_SCP_Background_Agent_Admission::check(background_request())),
  'Unknown original gate plan cannot admit background worker');
background_reset();
MAD4B_SCP_Unified_Gate_Funnel::$readiness=new WP_Error('broken_readiness');
background_assert(is_wp_error(MAD4B_SCP_Background_Agent_Admission::check(background_request())),
  'Unknown gate readiness cannot bypass prerequisite checks');
$bad=array(
 'plan_sha_drift'=>static function(){MAD4B_SCP_Unified_Gate_Funnel::$plan['plan_sha256']=str_repeat('b',64);},
 'readiness_sha_drift'=>static function(){MAD4B_SCP_Unified_Gate_Funnel::$readiness['plan_sha256']=str_repeat('b',64);},
 'missing_gate_readiness'=>static function(){unset(MAD4B_SCP_Unified_Gate_Funnel::$readiness['mandatory_blockers']);},
 'malformed_gate_readiness'=>static function(){MAD4B_SCP_Unified_Gate_Funnel::$readiness['mandatory_blockers']=null;},
 'extra_schema_blocker'=>static function(){MAD4B_SCP_Unified_Gate_Funnel::$readiness['mandatory_blockers'][]='schema:pending';},
 'missing_execution_gate'=>static function(){MAD4B_SCP_Unified_Gate_Funnel::$readiness['mandatory_blockers']=array();},
 'gate_mismatch'=>static function(){MAD4B_SCP_Unified_Gate_Funnel::$readiness['contract']='foreign';},
 'job_error'=>static function(){MAD4B_SCP_Content_Jobs::$result=new WP_Error('job_not_found');},
 'job_invalid'=>static function(){MAD4B_SCP_Content_Jobs::$result=array();},
 'job_published'=>static function(){MAD4B_SCP_Content_Jobs::$result['job']['state']='PUBLISHED';},
 'job_revision_missing'=>static function(){unset(MAD4B_SCP_Content_Jobs::$result['job']['job_revision']);},
 'job_id_changed'=>static function(){MAD4B_SCP_Content_Jobs::$result['job']['job_id']='foreign';},
);
foreach($bad as $name=>$mutate) {
 background_reset();$mutate();
 background_assert(is_wp_error(MAD4B_SCP_Background_Agent_Admission::check(background_request())),
     'Original first-party gate must deny '.$name);
}
foreach(array(
 array('worker_id'=>'../bad-worker'),array('job_id'=>str_repeat('-',36)),
 array('plan_sha256'=>'x'),
) as $change){
 background_reset();
 background_assert(is_wp_error(MAD4B_SCP_Background_Agent_Admission::check(
    array_replace(background_request(),$change))),
    'Invalid submitted identity is never a registered background agent');
}
background_reset();
$claim=MAD4B_SCP_Background_Agent_Dispatch::claim(background_request());
background_assert(is_array($claim) && $claim['provider_execution_authorized']===false
 && $claim['publication_authorized']===false
 && $claim['lease']['lease_epoch']===1
 && MAD4B_SCP_Durable_Execution::$leased===1,
 'First-party durable lease does not by itself grant provider execution/publication');
background_reset();
$beat=MAD4B_SCP_Background_Agent_Dispatch::heartbeat(background_request());
$fenced=MAD4B_SCP_Background_Agent_Dispatch::verify_commit(background_request());
background_assert(is_array($beat) && is_array($fenced) && $fenced['verified']===true
 && $fenced['mutation_performed']===false
 && MAD4B_SCP_Durable_Execution::$fences===1,
 'Durable epoch remains independently checked, without committing publication');
foreach(array(false,null,array('status'=>'active'),array('lease_epoch'=>0),
    array('work_id'=>'mismatched')) as $lease_result){
 background_reset();
 MAD4B_SCP_Durable_Execution::$result=$lease_result;
 $r=MAD4B_SCP_Background_Agent_Dispatch::claim(background_request());
 background_assert(is_wp_error($r),'Malformed lease result requires recovery, never a claimed worker');
 background_assert(($r->get_error_data()['reconciliation_required']??null)===true
  &&($r->get_error_data()['blind_retry_allowed']??null)===false,
  'Malformed lease after durable acquire must prohibit blind replay');
}
background_reset();
MAD4B_SCP_Durable_Execution::$result=array('status'=>'active');
$bad_heartbeat=MAD4B_SCP_Background_Agent_Dispatch::heartbeat(background_request());
background_assert(is_wp_error($bad_heartbeat)
    &&($bad_heartbeat->get_error_data()['reconciliation_required']??null)===true
    &&($bad_heartbeat->get_error_data()['blind_retry_allowed']??null)===false,
    'Malformed heartbeat readback requires reconciliation instead of blind lease refresh');
background_reset();
MAD4B_SCP_Durable_Execution::$result=array('status'=>'active');
$bad_commit=MAD4B_SCP_Background_Agent_Dispatch::verify_commit(background_request());
background_assert(is_wp_error($bad_commit)
    &&($bad_commit->get_error_data()['blind_retry_allowed']??null)===false,
    'Malformed fencing readback may never authorize commit');
background_reset();
$invalid=background_request();$invalid['lease_epoch']=0;
background_assert(is_wp_error(MAD4B_SCP_Background_Agent_Dispatch::heartbeat($invalid))
 && MAD4B_SCP_Durable_Execution::$heartbeats===0,
 'Missing/zero lease epoch cannot refresh original fencing');
background_reset();
$invalid=background_request();$invalid['lease_epoch']=0;
background_assert(is_wp_error(MAD4B_SCP_Background_Agent_Dispatch::verify_commit($invalid))
 && MAD4B_SCP_Durable_Execution::$fences===0,
 'Missing/zero lease epoch never verifies write commit');
echo "PASS: BACKGROUND_AGENT_ORIGINAL_AUTHORITY 34 gate, revision, lease and fencing checks\n";
