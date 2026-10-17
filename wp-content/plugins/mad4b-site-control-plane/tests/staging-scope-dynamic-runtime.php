<?php
define('ABSPATH',__DIR__.'/');
class MAD4B_SCP_Staging_Certification_Test_Helper {}
function apply_filters($name,$value) {
  if($name==='mad4b_scp_staging_functional_scope_dependencies') return [
    'jetengine_relations'=>['write_runtime'],
    'unverified_provider'=>['nonexistent_unknown_gate'],
    'staging_functional'=>['browser_runtime'], // override must be ignored.
  ];
  return $value;
}
function ok($condition,$why){if(!$condition){fwrite(STDERR,'FAIL '.$why."\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-staging-certification.php';
$r = new ReflectionMethod('MAD4B_SCP_Staging_Certification','scoped_readiness');
$r->setAccessible(true);
$gates=[];
foreach(['exact_build','safe_boot','write_runtime','write_authority','browser_runtime',
'context_authority','brand_core_context_coverage','google_provider_connection',
'managed_google_broker','skills_runtime','wp_import_export_exact_artifact',
'performance_budget','query_monitor_db_attribution'] as $k)$gates[$k]=['ready'=>true];
$gates['browser_runtime']['ready']=false;
$gates['google_provider_connection']['ready']=false;
$functional=['staging_acceptance_ready'=>true];
$state=$r->invoke(null,$gates,$functional);
ok($state['staging_functional']['ready']===true,'unrelated external browser and optional Google cannot block local Staging functional');
ok($state['governed_write']['ready']===true,'unrelated Google cannot block write readiness');
ok($state['full_release']['ready']===false,'full release still requires all gates');
ok($state['jetengine_relations']['ready']===true,'plugin-registered exact gate discovery supported');
ok(!isset($state['unverified_provider']),'unknown gates cannot be self-declared ready');
ok($state['staging_functional']['required_gates']===['exact_build','safe_boot'],'plugins cannot overwrite built-in safety');
$gates['write_runtime']['ready']=false;
$state=$r->invoke(null,$gates,$functional);
ok($state['jetengine_relations']['ready']===false,'plugin scope derives live gate dynamically');
ok($state['staging_functional']['ready']===true,'unrelated write gate does not block frontend acceptance');
$functional['staging_acceptance_ready']=false;
$state=$r->invoke(null,$gates,$functional);
ok($state['staging_functional']['ready']===false,'missing functional evidence fails closed');
echo "STAGING_SCOPE_DYNAMIC_RUNTIME_PASS\n";
