<?php
/** QA31 hermetic negative admission tests for semantic company stage jobs. */
define('ABSPATH', '/fixture/');
function add_action(...$args) {}
function add_filter(...$args) {}
function wp_is_uuid($value) { return (bool) preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $value); }
function get_option($key, $default = array()) { return $GLOBALS['qa31_options'][$key] ?? $default; }
require dirname(__DIR__) . '/includes/class-mad4b-scp-start-your-company-runtime.php';
function check($ok, $message) { if (!$ok) {fwrite(STDERR, "FAIL: $message\n"); exit(1);} }
$code='demo-company';
$id='11111111-1111-4111-8111-111111111111';
$origin='https://demo.example.com';
$stage=array_key_first(MAD4B_Start_Company_Runtime::STAGES);
$payload=array(
    'contract'=>MAD4B_SCP_Company_Provisioning_Work_Operations::CONTRACT,
    'company_code'=>$code,'site_uuid'=>$id,'origin'=>$origin,
    'mode'=>'repair_existing','stage'=>$stage,
    'action'=>MAD4B_Start_Company_Runtime::ACTIONS[$stage],
    'plan_sha256'=>str_repeat('a',64),'stage_revision'=>str_repeat('b',64),
    'run_id'=>str_repeat('c',64),'preserve_existing'=>true,
    'mutation_authorized'=>false,
);
$GLOBALS['qa31_options']=array(
    MAD4B_Start_Company_Runtime::REGISTRY=>array(
        $code=>array('company_site_uuid'=>$id,'canonical_origin'=>$origin,'host_binding_verified'=>true),
    ),
);
$validate=array('MAD4B_SCP_Company_Provisioning_Work_Operations','validate_payload');
check(call_user_func($validate,$payload), 'exact registered stage payload admitted');
foreach (array(
    'unlisted key'=>array('shell_command'=>'echo bad'),
    'implicit mutation authority'=>array('mutation_authorized'=>true),
    'tenant mismatch'=>array('company_code'=>'other-company'),
    'origin mismatch'=>array('origin'=>'https://other.example.com'),
    'stage action mismatch'=>array('action'=>'unknown'),
    'mode preservation mismatch'=>array('preserve_existing'=>false),
    'unbound site'=>array('site_uuid'=>'22222222-2222-4222-8222-222222222222'),
    'untrusted plan'=>array('plan_sha256'=>'invalid'),
) as $name=>$change) {
    check(!call_user_func($validate,array_replace($payload,$change)), $name . ' denied');
}
$GLOBALS['qa31_options'][MAD4B_Start_Company_Runtime::REGISTRY][$code]['host_binding_verified']=false;
check(!call_user_func($validate,$payload), 'unenrolled company denied');
$executor=array(
    'provider'=>'vps_mcp_client', 'contract'=>'mad4b.company-vps-executor.v1',
    'site_uuid'=>$id, 'authority_profile'=>'company_stage_v1',
    'bound_to_exact_company'=>true, 'health'=>'ready',
    'operation_adapter'=>'registered_wp_cli_stage_executor',
    'apply_handler'=>'verified', 'rollback_handler'=>'verified',
    'readback_handler'=>'verified', 'restoration_ready'=>true,
    'probe_evidence_sha256'=>str_repeat('e',64),
    'supported_actions'=>array('company.identity.verify'),
    'executor_id'=>'qa31-executor', 'verified_at'=>gmdate('c'),
);
$GLOBALS['qa31_options'][MAD4B_Start_Company_Runtime::REGISTRY][$code]['host_binding_verified']=true;
$GLOBALS['qa31_options'][MAD4B_SCP_Company_Provisioning_Work_Operations::EXECUTORS]=array($code=>$executor);
$identity=array('company_code'=>$code,'site_uuid'=>$id);
check(MAD4B_SCP_Company_Provisioning_Work_Operations::executor_status($identity)['registered'],
    'subset executor is registered');
$unsupported=$payload;
$unsupported['stage']='backup';
$unsupported['action']=MAD4B_Start_Company_Runtime::ACTIONS['backup'];
$job=array('operation_id'=>MAD4B_SCP_Company_Provisioning_Work_Operations::JOB,
    'payload'=>$unsupported,'executor_id'=>'qa31-executor','status'=>'claimed',
    'lease_expires_at_epoch'=>time()+60);
check(!MAD4B_SCP_Company_Provisioning_Work_Operations::provider_entry_allowed($job),
    'subset executor cannot accept an unregistered stage action');
// Executor discovery must not imply mutation eligibility from a ready heartbeat.
foreach (array(
    'untrusted provider'=>array('provider'=>'untrusted'),
    'wrong tenant site'=>array('site_uuid'=>'22222222-2222-4222-8222-222222222222'),
    'missing rollback'=>array('rollback_handler'=>'missing'),
    'unverified readback'=>array('readback_handler'=>'pending'),
    'no restoration proof'=>array('restoration_ready'=>false),
    'invalid probe'=>array('probe_evidence_sha256'=>'invalid'),
    'duplicate actions'=>array('supported_actions'=>array('company.identity.verify','company.identity.verify')),
    'unknown action'=>array('supported_actions'=>array('system.shell.run')),
    'stale heartbeat'=>array('verified_at'=>gmdate('c',time()-3600)),
    'future heartbeat'=>array('verified_at'=>gmdate('c',time()+3600)),
) as $name=>$changes) {
    $GLOBALS['qa31_options'][MAD4B_SCP_Company_Provisioning_Work_Operations::EXECUTORS][$code]=array_replace($executor,$changes);
    check(!MAD4B_SCP_Company_Provisioning_Work_Operations::executor_status($identity)['registered'],
        'executor ' . $name . ' denied');
}
$GLOBALS['qa31_options'][MAD4B_SCP_Company_Provisioning_Work_Operations::EXECUTORS][$code]=$executor;
check(MAD4B_SCP_Company_Provisioning_Work_Operations::executor_status($identity)['registered'],
    'known healthy executor remains eligible after denial fixtures');
echo "QA31_COMPANY_STAGE_ADMISSION: PASS\n";
