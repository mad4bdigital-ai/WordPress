<?php
/** Same source as production guard; fake input evidence, no WordPress DB/filesystem writes. */
define('ABSPATH',__DIR__);
require_once __DIR__.'/../includes/class-mad4b-scp-mcp-runtime-conflict-guard.php';
$method=new ReflectionMethod('MAD4B_SCP_MCP_Runtime_Conflict_Guard','canonical_runtime_observed');
$certified=array(
 'runtime_class_loaded'=>true,
 'runtime_from_official_plugin'=>true,
 'runtime_class_provenance_ready'=>true,
 'runtime_class_provenance_state'=>'certified_class_set',
 'runtime_class_provenance_failure_count'=>0,
 'mu_bootstrap_canonical_symbols_pinned'=>true,
 'mu_bootstrap_critical_class_set_pinned'=>true,
);
function mad4b_guard_expect($case,$status,$expected,$fn){
 $found=$fn->invoke(null,$status);
 if ($found!==$expected){fwrite(STDERR,"FAIL: $case\n");exit(1);}
 echo "PASS: $case\n";
}
mad4b_guard_expect('certified canonical after recovery',$certified,true,$method);
foreach(array('runtime_class_loaded','runtime_from_official_plugin','runtime_class_provenance_ready',
 'mu_bootstrap_canonical_symbols_pinned','mu_bootstrap_critical_class_set_pinned') as $key){
 $test=$certified;$test[$key]=false;
 mad4b_guard_expect('uncertified denies '.$key,$test,false,$method);
}
foreach(array('mixed_runtime','') as $s){
 $test=$certified;$test['runtime_class_provenance_state']=$s;
 mad4b_guard_expect('uncertified class state '.$s,$test,false,$method);
}
$test=$certified;$test['runtime_class_provenance_failure_count']=1;
mad4b_guard_expect('foreign class failure denies canonical',$test,false,$method);
$test=$certified;unset($test['runtime_class_provenance_failure_count']);
mad4b_guard_expect('missing failure count denies canonical',$test,false,$method);
echo "MCP_RUNTIME_INSPECT_CANONICAL_SOURCE_ISOLATED_PASS\n";
