<?php
/** Hermetic feature evidence: versions do not grant or deny MCP transport. */
define('ABSPATH', __DIR__ . '/');
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)$s)); }
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
require dirname(__DIR__).'/includes/class-mad4b-scp-dependency-manager.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-runtime-release-set.php';
$n=0;
function pass_case($label,$ok){global $n;if(!$ok){fwrite(STDERR,'FAIL '.$label."\n");exit(3);}$n++;echo "PASS $label\n";}
$base=array(
 'installed'=>true,'plugin_identity_ambiguous'=>false,
 'runtime_loaded'=>true,'runtime_from_official_plugin'=>true,
 'abilities_api_available'=>true,'network_unsupported'=>false
);
foreach (array('0.6.1','0.7.0','0.7.1','0.8.0','99.2.7','future-version','') as $version) {
 $observed=$base;
 $observed['installed_version']=$version;
 pass_case('compatible_'.$version, MAD4B_SCP_Dependency_Manager::adapter_read_runtime_ready($observed)===true);
}
foreach (array('installed'=>false,'plugin_identity_ambiguous'=>true,'runtime_loaded'=>false,
 'runtime_from_official_plugin'=>false,'abilities_api_available'=>false,'network_unsupported'=>true) as $key=>$bad) {
 $obs=$base;$obs[$key]=$bad;
 pass_case('denied_'.$key, MAD4B_SCP_Dependency_Manager::adapter_read_runtime_ready($obs)===false);
}
pass_case('global_adapter_target_version_not_pinned',
 MAD4B_SCP_Runtime_Release_Set::target_adapter_version()==='');
$reflect=new ReflectionMethod('MAD4B_SCP_Runtime_Release_Set','release_set_from_control_target');
$reflect->setAccessible(true);
$target=array('version'=>'0.4.0-rc.129','source_commit_sha'=>str_repeat('1',40),
 'archive_sha256'=>str_repeat('2',64),'package_manifest_digest'=>str_repeat('3',64),
 'build_fingerprint'=>str_repeat('4',64));
$policy=array('target_adapter_version'=>'0.7.1');
$set=$reflect->invoke(null,$target,$policy);
pass_case('no_implicit_candidate_version_from_legacy_policy',
 ($set['mcp_adapter']['version']??'')==='');
pass_case('no_implicit_candidate_sha_from_legacy_policy',
 ($set['mcp_adapter']['archive_sha256']??'')==='');
$sha=new ReflectionMethod('MAD4B_SCP_Runtime_Release_Set','release_set_digest');
$sha->setAccessible(true);
pass_case('unbound_artifact_not_a_signed_release_set',$sha->invoke(null,$set)==='');
$target['runtime_release_set']=array('mcp_adapter'=>array(
 'version'=>'0.8.4','archive_sha256'=>str_repeat('a',64),
 'archive_bytes'=>987654,'package_url'=>'https://github.com/WordPress/mcp-adapter/releases/download/v0.8.4/mcp-adapter.zip'));
$set=$reflect->invoke(null,$target,array());
pass_case('explicit_newer_release_target_retains_exact_artifact',
 ($set['mcp_adapter']['version']??'')==='0.8.4' &&
 ($set['mcp_adapter']['archive_sha256']??'')===str_repeat('a',64));
pass_case('explicit_release_set_binds_to_unique_manifest_digest',
 preg_match('/^[a-f0-9]{64}$/',$sha->invoke(null,$set))===1);
echo json_encode(array('contract'=>'mad4b.capability-first-mcp-adapter-gate-runtime.v1',
 'state'=>'PASS','checks'=>$n,'installed_version_gate'=>false,'grant_mutation'=>false,
 'release_apply'=>false))."\n";
