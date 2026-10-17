<?php
define('ABSPATH',__DIR__.'/');
function add_action($hook,$callback,$priority=10){}
require dirname(__DIR__) . '/includes/class-mad4b-scp-runtime-convergence.php';
function verify($ok,$name){if(!$ok){fwrite(STDERR,"FAIL ".$name."\n");exit(1);}}
$class='MAD4B_SCP_Runtime_Convergence';
$checkpoint=array('source'=>'plugin_activation','state'=>'pending_manual_resume',
 'resume_blocker'=>'mad4b_automation_automation_kill_switch','automatic_retry_allowed'=>false);
$paused=array('integrity_valid'=>true,'scopes'=>array('*'=>true));
$clear=array('integrity_valid'=>true,'scopes'=>array('*'=>false));
$a=$class::opt_in_recovery_preflight($checkpoint,$paused,true);
verify(!$a['eligible']&&'automation_scope_still_paused'===$a['reason'],'globally paused remains paused');
$a=$class::opt_in_recovery_preflight($checkpoint,$clear,true);
verify($a['eligible']&&'SCHEDULE_SAFE_PHASES_ELIGIBLE'===$a['state']&&!$a['authorizing']&&!$a['mutation_performed'],'exact safe after owner opt in');
foreach(array('runtime-convergence:*','runtime-convergence:safe-phases') as $scope){
 $s=array('integrity_valid'=>true,'scopes'=>array('*'=>false,$scope=>true));
 verify(!$class::opt_in_recovery_preflight($checkpoint,$s,true)['eligible'],'narrower kill switches still block');
}
foreach(array(
 'unknown_external_effect','mad4b_automation_worker_exception','mad4b_automation_admission_exception',
 'wp_cron_unavailable'
) as $reason){
 $c=$checkpoint;$c['resume_blocker']=$reason;
 verify(!$class::opt_in_recovery_preflight($c,$clear,true)['eligible'],'only exact previous kill switch pause can retry');
}
verify(!$class::opt_in_recovery_preflight($checkpoint,$clear,false)['eligible'],'source identity drift');
verify(!$class::opt_in_recovery_preflight($checkpoint,array('integrity_valid'=>false,'scopes'=>array()),true)['eligible'],'invalid switch seal');
foreach(array('blocked','pending_restart','pending_safe_phases') as $state){
 $c=$checkpoint;$c['state']=$state;
 verify(!$class::opt_in_recovery_preflight($c,$clear,true)['eligible'],'only paused checkpoint can be requeued');
}
echo "RUNTIME_SAFE_OPT_IN_REDISCOVERY_FAIL_CLOSED: PASS\n";
