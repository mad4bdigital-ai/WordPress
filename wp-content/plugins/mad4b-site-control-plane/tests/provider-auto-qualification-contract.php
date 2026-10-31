<?php
define('ABSPATH', __DIR__);
require dirname(__DIR__).'/includes/class-mad4b-scp-provider-auto-qualification.php';
function verify($ok, $case) {
    if (!$ok) { fwrite(STDERR, "FAIL $case\n"); exit(1); }
}
$cap = array('capability_contract_digest'=>str_repeat('b',64),
    'mounted_abilities'=>array('sample/write'), 'risk'=>'bounded_write',
    'reversible'=>true, 'structural_compatible'=>true,
    'artifact_authority_bound'=>true, 'write_eligible'=>false,
    'behavioral_evidence'=>array('behavioral_verified'=>false,'rollback_verified'=>false));
$assessment=array('provider_id'=>'sample','available'=>true,
    'artifact'=>array('runtime_artifact_fingerprint'=>str_repeat('a',64)),
    'capabilities'=>array('sample.bounded-write'=>$cap));
$planner='MAD4B_SCP_Provider_Auto_Qualification';
$r=$planner::plan($assessment,'sample/write',array('build_provenance_state'=>'unknown'));
verify($r['capabilities'][0]['state']==='RECOVERING', 'unknown build routes to dependency repair');
verify($r['capabilities'][0]['next_native_action']==='auto_reconcile_native_build_provenance','missing provenance action');
$r=$planner::plan($assessment,'sample/write',array('build_provenance_state'=>'exact_current'));
verify($r['capabilities'][0]['state']==='WAITING_PREFLIGHT', 'provenance ready for native probe');
verify($r['capabilities'][0]['next_native_action']==='native_provider_behavioral_recertify_with_exact_approval','original authority preserved');
verify(!$r['mutation_performed'] && !$r['automatic_new_grants'] && !$r['automatic_unapproved_write_probe'],'no synthetic grants');
$assessment['capabilities']['sample.bounded-write']['artifact_authority_bound']=false;
$r=$planner::plan($assessment,'sample/write');
verify($r['capabilities'][0]['next_native_action']==='auto_resolve_exact_active_provider_artifact','recovery path general');
$assessment['capabilities']['sample.bounded-write']['structural_compatible']=false;
$r=$planner::plan($assessment,'sample/write');
verify($r['capabilities'][0]['state']==='NEEDS_RECONCILIATION','structural mismatch isolated');
$assessment['capabilities']['sample.bounded-write']['structural_compatible']=true;
$assessment['capabilities']['sample.bounded-write']['artifact_authority_bound']=true;
$assessment['capabilities']['sample.bounded-write']['write_eligible']=true;
$r=$planner::plan($assessment,'sample/write');
verify($r['capabilities'][0]['state']==='READY' && $r['capabilities'][0]['reason']==='native_provider_write_certified','native evidence only');
$assessment['capabilities']['sample.bounded-write']['risk']='high_risk_write';
$assessment['capabilities']['sample.bounded-write']['write_eligible']=false;
$r=$planner::plan($assessment,'sample/write',array('build_provenance_state'=>'exact_current'));
verify($r['capabilities'][0]['next_native_action']==='native_canary_and_activation_lifecycle','high risk retains canary');
$r=$planner::plan($assessment,'unregistered/write');
verify($r['matched_count']===0,'unmatched ability cannot inherit another ability authority');
echo "PASS generic provider auto-qualification lifecycle (10 checks)\n";
