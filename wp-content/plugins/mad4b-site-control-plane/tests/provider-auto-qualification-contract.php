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
// Catalog source contract: provider capability classification cannot silently drop
// risk, evidence inputs, or artifact schema on new providers.
$catalog=json_decode(file_get_contents(dirname(__DIR__).'/config/provider-capability-contracts.json'),true);
verify(is_array($catalog) && ($catalog['contract']??null)==='mad4b.provider-capability-contracts.v1','canonical capability catalog schema');
verify(count($catalog['providers']??array())>=10,'provider inventory retained');
$capability_count=0;$high_risk_count=0;
foreach($catalog['providers'] as $provider_id=>$provider){
 verify(is_string($provider['adapter_id']??null) && preg_match('/^[a-z][a-z0-9_-]+$/D',$provider['adapter_id'])===1,'provider must declare stable native adapter identity');
 verify(!empty($provider['capabilities']) && is_array($provider['capabilities']),'provider capabilities nonempty');
 foreach($provider['capabilities'] as $capability_id=>$definition){
  ++$capability_count;
  verify(in_array($definition['risk']??null,array('read','bounded_write','high_risk_write'),true),'risk classification required');
  verify(isset($definition['probes']) && is_array($definition['probes']),'native evidence probes retained');
  verify(isset($definition['traits']) && is_array($definition['traits']),'runtime traits retained');
  if(($definition['risk']??null)==='high_risk_write'){
   ++$high_risk_count;
   verify(!empty($definition['probes']) && !empty($definition['abilities']),'high risk requires explicit probes and native abilities');
  }
 }
}
verify($capability_count>=40 && $high_risk_count>=12,'high risk source coverage cannot shrink unnoticed');
// Behavioral gate in the original provider certification class, not its caller.
function add_action($name,$callback,$priority=10,$argc=1){return true;}
require dirname(__DIR__).'/includes/class-mad4b-scp-provider-compatibility-certification.php';
$cert='MAD4B_SCP_Provider_Compatibility_Certification';
$activation=new ReflectionMethod($cert,'activation_stage_for');
$level=new ReflectionMethod($cert,'level_for');
$none=array('behavioral_verified'=>false,'rollback_verified'=>false);
$proof=array('behavioral_verified'=>true,'rollback_verified'=>true);
verify($activation->invoke(null,'high_risk_write',$cert::LEVEL_DISCOVERED,$none,false)===$cert::ACTIVATION_SHADOW,'high risk unverified stays shadow');
verify($activation->invoke(null,'high_risk_write',$cert::LEVEL_DISCOVERED,$proof,false)===$cert::ACTIVATION_CANARY,'high risk evidence promotes only to canary');
verify($activation->invoke(null,'high_risk_write',$cert::LEVEL_FULL,$proof,true)!==$cert::ACTIVATION_ACTIVE,'high risk never self-activates');
verify($level->invoke(null,true,true,true,'high_risk_write',true,$proof,true)===$cert::LEVEL_DISCOVERED,'high risk structural evidence cannot silently certify writes');
verify($level->invoke(null,true,true,true,'bounded_write',true,$none,false)===$cert::LEVEL_DISCOVERED,'unbound artifact cannot certify write');
verify($activation->invoke(null,'bounded_write',$cert::LEVEL_DISCOVERED,$none,false)===$cert::ACTIVATION_SHADOW,'unverified bounded write stays shadow');
echo "PASS provider qualification, schema risk preservation and native certification activation gates\n";
