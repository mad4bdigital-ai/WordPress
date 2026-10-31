<?php
/** Standalone native owner integration contract, no WP bootstrap or writes. */
define('ABSPATH',__DIR__);
class WP_Error {private $code; public function __construct($code,$message){$this->code=$code;} public function get_error_code(){return $this->code;}}
require dirname(__DIR__).'/includes/class-mad4b-scp-global-runtime-lifecycle.php';
function check_native($ok,$name){if(!$ok){fwrite(STDERR,"FAIL ".$name.PHP_EOL);exit(1);}}
$cls='MAD4B_SCP_Global_Runtime_Lifecycle';
$cases=array(
 array('scheduler_admission',array('decision'=>'QUEUE','reasons'=>array('provider_rate_limited')),'QUEUED'),
 array('scheduler_admission',array('decision'=>'QUEUE','reasons'=>array('concurrency_quota_reached')),'QUEUED'),
 array('scheduler_admission',array('decision'=>'DENY','reasons'=>array('queued_quota_exhausted')),'RECOVERING'),
 array('scheduler_admission',array('decision'=>'FAIL_CLOSED','reasons'=>array('central_governance_unavailable_for_high_risk')),'BLOCKED_AUTHORITY'),
 array('scheduler_admission',array('decision'=>'ADMIT'),'WAITING_PREFLIGHT'),
 array('scheduler_backlog',array('effective_status'=>'queued'),'QUEUED'),
 array('scheduler_backlog',array('effective_status'=>'leased'),'RUNNING'),
 array('scheduler_backlog',array('effective_status'=>'reconciliation_required'),'NEEDS_RECONCILIATION'),
 array('scheduler_backlog',array('effective_status'=>'completed'),'WAITING_VERIFICATION'),
 array('remote_work_queue',array('effective_status'=>'pending'),'QUEUED'),
 array('remote_work_queue',array('effective_status'=>'claimed'),'RUNNING'),
 array('remote_work_queue',array('effective_status'=>'reconciling'),'NEEDS_RECONCILIATION'),
 array('remote_work_queue',array('effective_status'=>'cancelled_no_effect'),'CANCELLED'),
 array('adaptive_dispatcher',array('route_eligible'=>true),'WAITING_PREFLIGHT'),
 array('adaptive_dispatcher',array('route_eligible'=>false),'WAITING_DEPENDENCY'),
 array('adaptive_dispatcher',array('route_eligible'=>true,'write_uncertain'=>true),'NEEDS_RECONCILIATION'),
 array('adaptive_dispatcher',array('failure_state'=>'HOST_SAFETY_REJECTION_NO_ALTERNATE_EXECUTION'),'BLOCKED_AUTHORITY'),
 array('adaptive_dispatcher',array('failure_state'=>'TRANSPORT_RESULT_INDETERMINATE'),'NEEDS_RECONCILIATION'),
 array('recovery_route',array('recommended_route'=>'native_read'),'RECOVERING'),
 array('recovery_route',array('authority_blockers'=>array('permission_denied'),'recommended_route'=>'native_read'),'BLOCKED_AUTHORITY'),
 array('recovery_route',array(),'READY'),
);
foreach($cases as $i=>$case) {
    $out=$cls::observe_native_lane($case[0],$case[1]);
    check_native(is_array($out) && $out['state']===$case[2],'case '.$i.' expected '.$case[2]);
    check_native($out['dispatch_performed']===false && $out['blind_write_retry_allowed']===false
        && $out['alternate_mutation_authorized']===false,'unauthorized side effects case '.$i);
}
check_native($cls::observe_native_lane('arbitrary_executor',array()) instanceof WP_Error,'unknown owner rejected');
$root=dirname(__DIR__).'/includes/';
foreach(array(
 'class-mad4b-scp-scheduler-admission.php'=>"observe_native_lane('scheduler_admission'",
 'class-mad4b-scp-scheduler-backlog.php'=>"observe_native_lane( 'scheduler_backlog'",
 'class-mad4b-scp-remote-work-queue.php'=>"observe_native_lane('remote_work_queue'",
 'class-mad4b-scp-adaptive-operation-router.php'=>"observe_native_lane('adaptive_dispatcher'",
 'class-mad4b-scp-recovery-route-resolver.php'=>"observe_native_lane('recovery_route'",
) as $file=>$needle) {
    $src=file_get_contents($root.$file);
    check_native(str_contains($src,$needle),'native owner wired '.$file);
}
// Simulated owner-state transitions: no model-synthesized provider receipt.
$states=array();
foreach(array('queued','leased','completed','reconciliation_required') as $status) {
    $states[$status]=$cls::observe_native_lane('scheduler_backlog',array('effective_status'=>$status))['state'];
}
check_native($states['queued']==='QUEUED','queued is routine');
check_native($states['leased']==='RUNNING','native claim progresses without manual classification');
check_native($states['completed']==='WAITING_VERIFICATION','queue terminal does not imply provider verification');
check_native($states['reconciliation_required']==='NEEDS_RECONCILIATION','expired lease freezes blind replay');
$graph=array(
 array('id'=>'queue','kind'=>'read','depends_on'=>array(),'ability_registered'=>true),
 array('id'=>'native_execute','kind'=>'write','depends_on'=>array('queue'),'ability_registered'=>true),
 array('id'=>'readback','kind'=>'verify','depends_on'=>array('native_execute'),'ability_registered'=>true),
);
$base=array('status'=>'verified','native_source_confirmed'=>true,'readback_confirmed'=>true,
 'exact_target_bound'=>true,'receipt_digest'=>str_repeat('b',64));
$unconfirmed=$cls::evaluate($graph,array('queue'=>$base,
 'native_execute'=>array('status'=>'queued','native_source_confirmed'=>true)));
check_native($unconfirmed['steps'][1]['state']==='QUEUED' && $unconfirmed['steps'][2]['state']==='WAITING_DEPENDENCY',
 'provider queue observation cannot falsely complete dependent verification');
$unconfirmed=$cls::evaluate($graph,array('queue'=>$base,'native_execute'=>$base));
check_native($unconfirmed['steps'][2]['state']==='READY' && $unconfirmed['state']!=='VERIFIED',
 'native mutation receipt advances verifier but does not complete workflow');
$confirmed=$cls::evaluate($graph,array('queue'=>$base,'native_execute'=>$base,'readback'=>$base));
check_native($confirmed['state']==='VERIFIED','only independently source-bound readback finishes workflow');
echo "PASS global runtime 21 native lane cases, 5 owner anchors, 7 progression checks\n";
