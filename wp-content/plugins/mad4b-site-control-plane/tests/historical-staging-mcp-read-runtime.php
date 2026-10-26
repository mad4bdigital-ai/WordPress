<?php
/** Hermetic test: historical Staging restart gate may admit MCP reads, never writes. */
define('ABSPATH', '/test/');
function sanitize_text_field($text) { return (string) $text; }
require dirname(__DIR__) . '/includes/class-mad4b-scp-runtime-convergence.php';
function verify($condition, $label) {
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
}
$old = array('version'=>'0.4.0-rc.102','source_commit_sha'=>str_repeat('a',40));
$new = array('version'=>'0.4.0-rc.104','source_commit_sha'=>str_repeat('b',40),
    'build_fingerprint'=>str_repeat('c',64),'package_manifest_digest'=>str_repeat('d',64));
$checkpoint = array('source'=>'self_update','state'=>'pending_manual_resume',
    'resume_blocker'=>'mad4b_automation_automation_kill_switch','target_identity'=>$old);
$phases = array(
    'deployment'=>array('required'=>true,'state'=>'ready'),
    'schema'=>array('required'=>true,'state'=>'ready'),
    'local_oauth_store'=>array('required'=>true,'state'=>'ready'),
    'managed_skills'=>array('required'=>true,'state'=>'pending'),
    'authority_binding'=>array('required'=>true,'state'=>'owner_gate'),
);
$graph = array('environment'=>'staging','phase_graph_valid'=>true,'current_identity'=>$new,
    'checkpoint'=>$checkpoint,'phases'=>$phases,
    'checkpoint_operator_state'=>array('state'=>'historical_checkpoint_independent_blockers',
        'replay_old_checkpoint_allowed'=>false),
    'required_blockers'=>array('managed_skills:pending','authority_binding:owner_gate'));
$reflection = new ReflectionMethod('MAD4B_SCP_Runtime_Convergence', 'superseded_checkpoint_mcp_read_safe');
$reflection->setAccessible(true);
$decision = function($c, $n, $g, $quiet=0) use ($reflection) {
    return $reflection->invoke(null, $c, $n, $quiet, $g);
};
verify($decision($checkpoint,$new,$graph)===true,'Historical Staging read admission');
$bad=$graph; $bad['environment']='production';
verify(!$decision($checkpoint,$new,$bad),'Production stays closed');
$bad=$graph; $bad['required_blockers'][]='schema:pending';
verify(!$decision($checkpoint,$new,$bad),'Extra required blocker stays closed');
$bad=$graph; $bad['phases']['managed_skills']['state']='blocked';
verify(!$decision($checkpoint,$new,$bad),'Blocked skills are not admitted');
$bad=$graph; $bad['phases']['schema']['state']='pending';
verify(!$decision($checkpoint,$new,$bad),'Other required phase stays closed');
$bad=$graph; $bad['phase_graph_valid']=false;
verify(!$decision($checkpoint,$new,$bad),'Invalid graph stays closed');
$bad=$graph; $bad['checkpoint_operator_state']['replay_old_checkpoint_allowed']=true;
verify(!$decision($checkpoint,$new,$bad),'Old checkpoint never replayed');
$bad=$checkpoint; $bad['state']='blocked';
verify(!$decision($bad,$new,$graph),'Blocked checkpoint stays closed');
$bad=$graph; $bad['required_blockers']=array('authority_binding:owner_gate');
$bad['phases']['managed_skills']['state']='ready';
verify($decision($checkpoint,$new,$bad),'Existing owner-only exception retained');
verify(!$decision($checkpoint,$new,$graph,time()+30),'Quiet period stays closed');
echo "PASS historical Staging MCP read-only checkpoint decision\n";