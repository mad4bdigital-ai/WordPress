<?php
/** Original runtime state projection, no WordPress backend, no provider execution. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$msg=''){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function lifecycle_check($yes,$why){
 if(!$yes){fwrite(STDERR,"FAIL: ".$why.PHP_EOL);exit(1);}
 $GLOBALS['lifecycle_passes']=($GLOBALS['lifecycle_passes']??0)+1;
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-global-runtime-lifecycle.php';
$c='MAD4B_SCP_Global_Runtime_Lifecycle';
$native=['native_source_confirmed'=>true,'status'=>'verified',
 'readback_confirmed'=>true,'exact_target_bound'=>true,
 'receipt_digest'=>str_repeat('a',64)];
$steps=[
 ['id'=>'discover','kind'=>'read'],
 ['id'=>'apply','kind'=>'write','depends_on'=>['discover']],
 ['id'=>'verify','kind'=>'verify','depends_on'=>['apply']],
 ['id'=>'publish','kind'=>'release','depends_on'=>['verify']],
];
$without=$c::evaluate($steps);
lifecycle_check(is_array($without)&&$without['contract']===$c::CONTRACT
 && $without['state']==='ACTIVE'
 && $without['steps'][1]['state']==='WAITING_DEPENDENCY'
 && $without['native_write_authority_granted']===false,
 'Missing native source readback must not elevate a write');
$spoof=$c::evaluate($steps,['discover'=>array_merge($native,['native_source_confirmed'=>false])]);
lifecycle_check($spoof['steps'][0]['state']!=='VERIFIED',
 'Caller claims without native source ownership never verify stage');
$verified=$c::evaluate($steps,['discover'=>$native,'apply'=>$native,
 'verify'=>$native,'publish'=>$native]);
lifecycle_check($verified['state']==='VERIFIED'
 && count($verified['verified_steps'])===4
 && $verified['native_write_authority_granted']===false
 && $verified['automatic_provider_write_performed']===false,
 'Independent, exact, native source readbacks can complete a projected graph only');
foreach(array('native_source_confirmed','readback_confirmed','exact_target_bound') as $field){
 $forged=$native;$forged[$field]=new WP_Error('source_not_verified');
 $result=$c::evaluate([['id'=>'verify','kind'=>'verify']],['verify'=>$forged]);
 lifecycle_check($result['steps'][0]['state']!=='VERIFIED',
  'Truthy error object cannot substitute exact native first-party bool: '.$field);
}
$bad=$native;$bad['receipt_digest']='not-an-exact-sha256';
$out=$c::evaluate([['id'=>'verify','kind'=>'verify']],['verify'=>$bad]);
lifecycle_check($out['steps'][0]['state']!=='VERIFIED',
 'Malformed receipt digest cannot count as original verified readback');
foreach(['readback_confirmed','exact_target_bound','native_source_confirmed'] as $key){
 $bad=$native;$bad[$key]=false;
 $row=$c::evaluate([['id'=>'verify','kind'=>'verify']],['verify'=>$bad]);
 lifecycle_check($row['steps'][0]['state']!=='VERIFIED',
  'Native evidence missing '.$key.' cannot verify stage');
}
$state=$c::evaluate($steps,['discover'=>$native,'apply'=>array_merge($native,[
 'status'=>'commit_uncertain'])]);
lifecycle_check(in_array('apply',$state['blocked_steps'],true)
 && $state['steps'][1]['state']==='NEEDS_RECONCILIATION'
 && !$state['steps'][1]['write_authorized'],
 'Unknown original write requires journal reconciliation rather than alternate dispatch');
$denied=$c::evaluate([['id'=>'grant','kind'=>'write']],['grant'=>[
 'native_source_confirmed'=>true,'status'=>'denied']]);
lifecycle_check($denied['steps'][0]['state']==='BLOCKED_AUTHORITY',
 'Native authority refusal never becomes an available write');
$queued=$c::evaluate([['id'=>'queue','kind'=>'write']],['queue'=>[
 'native_source_confirmed'=>true,'status'=>'queued']]);
lifecycle_check($queued['steps'][0]['state']==='QUEUED'
 && !$queued['steps'][0]['write_authorized'],
 'Queued write is not an executed or authorized write');
$optional=$c::evaluate([['id'=>'optional','required'=>false,'ability_registered'=>false]]);
lifecycle_check($optional['steps'][0]['state']==='PENDING',
 'Missing optional native ability is not a hard blocker');
$missing=$c::evaluate([['id'=>'required','required'=>true,'ability_registered'=>false]]);
lifecycle_check($missing['steps'][0]['state']==='BLOCKED_DEPENDENCY',
 'Absent required runtime ability is not silently ignored');
$skip=$c::evaluate([['id'=>'optional','required'=>false]],['optional'=>[
 'native_source_confirmed'=>true,'status'=>'not_applicable',
 'exact_target_bound'=>true,'not_applicable_proven'=>true]]);
lifecycle_check($skip['state']==='VERIFIED'
 && count($skip['skipped_steps'])===1
 && !$skip['native_write_authority_granted'],
 'Verified optional not-applicable stage may be skipped, never executed');
foreach([
 [],
 array_fill(0,129,['id'=>'many']),
 [['id'=>'invalid stage']],
 [['id'=>'repeat'],['id'=>'repeat']],
 [['id'=>'write','kind'=>'unknown']],
 [['id'=>'stage','depends_on'=>['missing']]],
 [['id'=>'a','depends_on'=>['b']],['id'=>'b']],
] as $invalid){
 lifecycle_check(is_wp_error($c::evaluate($invalid)),
  'Malformed, duplicate, oversized or non-topological stage graph is rejected');
}
$long=array();for($i=0;$i<128;$i++)$long[]=['id'=>'step_'.$i];
$grown=$c::evaluate($long);
lifecycle_check(is_array($grown) && count($grown['steps'])===128
 && $grown['native_preflight_not_substituted']===true,
 'Original dynamic graph admits 128 stages without bypassing provider authority');
foreach(['commit_uncertain'=>'NEEDS_RECONCILIATION','grant_denied'=>'BLOCKED_AUTHORITY',
 'wpcli_busy'=>'RECOVERING','unexpected_dependency'=>'BLOCKED_DEPENDENCY'] as $error=>$expected){
 $classified=$c::classify_error(new WP_Error($error));
 lifecycle_check($classified['state']===$expected
  && $classified['retry_write']===false,
  'Original error classification must never authorize write retry: '.$error);
}
foreach([
 ['remote_work_queue',['status'=>'reconciling'],'NEEDS_RECONCILIATION'],
 ['remote_work_queue',['status'=>'completed'],'WAITING_VERIFICATION'],
 ['scheduler_admission',['decision'=>'ADMIT'],'WAITING_PREFLIGHT'],
 ['scheduler_admission',['decision'=>'DENY'],'BLOCKED_AUTHORITY'],
 ['adaptive_dispatcher',['write_uncertain'=>true],'NEEDS_RECONCILIATION'],
 ['adaptive_dispatcher',['route_eligible'=>true],'WAITING_PREFLIGHT'],
 ['recovery_route',['authority_blockers'=>['grant_missing']],'BLOCKED_AUTHORITY'],
 ['recovery_route',[],'READY'],
] as $scenario){
 list($source,$facts,$expected)=$scenario;
 $row=$c::observe_native_lane($source,$facts);
 lifecycle_check(is_array($row)&&$row['state']===$expected
  && $row['alternate_mutation_authorized']===false
  && $row['blind_write_retry_allowed']===false
  && $row['mutation_performed']===false,
  'Canonical native lane readback never self-grants writes: '.$source.' '.$expected);
}
lifecycle_check(is_wp_error($c::observe_native_lane('foreign_provider',[])),
 'Unregistered provider lane is never promoted into lifecycle authority');
echo 'PASS: GLOBAL_RUNTIME_LIFECYCLE '.($GLOBALS['lifecycle_passes']??0).
 ' first-party dynamic stage/readback denial cases'.PHP_EOL;
