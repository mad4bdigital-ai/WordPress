<?php
/** Hermetic planning contract: no SSH, Production write, mock grant or exec. */
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    function __construct($c,$m=''){$this->code=$c;}
    function get_error_code(){return $this->code;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
class MAD4B_SCP_Central_Company_Rollout {
    const CONTRACT='mad4b.central-company-rollout-plan.v1';
    public static function plan($input){
        return new WP_Error('no_live_production_context');
    }
}
class MAD4B_SCP_Central_Release_Registry {
    public static function verified_readback($hash){return array();}
}
require dirname(__DIR__) . '/includes/class-mad4b-scp-central-operator-handoff.php';
function ck($pass,$msg){if(!$pass){fwrite(STDERR,'FAIL: '.$msg.PHP_EOL);exit(1);}}
$hash=str_repeat('a',64);
$plan=array(
  'contract'=>MAD4B_SCP_Central_Company_Rollout::CONTRACT,
  'central_release_readback_verified'=>true,
  'ready_for_separate_owner_authorization'=>true,
  'blockers'=>array(),
  'central_release_sha256'=>$hash,
  'company_site_uuid'=>'11111111-2222-4333-8444-555555555555',
  'company_origin'=>'https://company.example',
  'company_environment'=>'production'
);
$receipt=array(
  'registry_readback_verified'=>true,
  'artifact_sha256'=>$hash,
  'source_commit'=>str_repeat('b',40),
  'file_manifest_sha256'=>str_repeat('c',64),
  'source_type'=>'local_git',
  'rollout_scope'=>'company_production',
  'requirements'=>array(
    'deployment_role'=>'company_adapter',
    'target_plugin_file'=>'mad4b-tenant-sync/mad4b-tenant-sync.php'
  )
);
$c='MAD4B_SCP_Central_Operator_Handoff';
$handoff=$c::describe($plan,$receipt);
ck($handoff['preflight_eligible']===true,'eligible read-only metadata');
ck($handoff['dispatch_eligible']===false,'planning must NEVER dispatch Shell');
ck($handoff['production_mutation_authorized']===false,'planning must not authorize Production');
ck($handoff['read_only']===true && $handoff['mutation_performed']===false,'no mutation');
ck($handoff['grant_verified']===false && $handoff['command_digest_verified']===false
   && $handoff['remote_executor_registered']===false
   && $handoff['remote_readback_verified']===false,'unknown trust remains unverified');
ck($handoff['company_staging_required']===false,'company Staging optional');
ck($handoff['package_certificate_required']===false,'no package certificate prerequisite');
ck($handoff['target']['site_uuid']===$plan['company_site_uuid'],'tenant identity bound');
ck($handoff['source']['manifest_sha256']===$receipt['file_manifest_sha256'],'source manifest bound');
ck($handoff['broker_authority_contract']==='central_authority_verifier_312'
   && $handoff['remote_readback_contract']==='remote_readback_313','reuse installed native verifier contracts');

$fail=$plan;$fail['ready_for_separate_owner_authorization']=false;$fail['blockers']=array('php_mismatch');
$r=$c::describe($fail,$receipt);
ck(!$r['preflight_eligible'] && !$r['dispatch_eligible'],'blocked company never dispatched');

$forged=$receipt;$forged['artifact_sha256']=str_repeat('d',64);
ck(in_array('immutable_release_identity_mismatch',$c::describe($plan,$forged)['blockers'],true),'different artifact denied');

$forged=$receipt;$forged['registry_readback_verified']=false;
ck(in_array('unverified_central_and_company_evidence',$c::describe($plan,$forged)['blockers'],true),'caller forged evidence cannot qualify');

$forged=$receipt;$forged['rollout_scope']='staging_verified_only';
ck(in_array('non_distributable_release_scope',$c::describe($plan,$forged)['blockers'],true),'Staging canary denied');

$forged=$receipt;$forged['requirements']['deployment_role']='central_controller';
ck(in_array('non_distributable_release_scope',$c::describe($plan,$forged)['blockers'],true),'central controller denied');

$forged=$receipt;$forged['requirements']['target_plugin_file']='mad4b-content-hub/mad4b-content-hub.php';
ck(in_array('target_plugin_not_distributable',$c::describe($plan,$forged)['blockers'],true),'central plugin path denied');

$forged=$plan;$forged['company_origin']='http://company.example';
ck(in_array('target_company_identity_invalid',$c::describe($forged,$receipt)['blockers'],true),'HTTP target denied');

$forged=$plan;$forged['company_site_uuid']='wrong-tenant';
ck(in_array('target_company_identity_invalid',$c::describe($forged,$receipt)['blockers'],true),'wrong tenant denied');

$r=$c::plan(array('release_sha256'=>$hash,'granted'=>true));
ck(is_wp_error($r) && $r->get_error_code()==='mad4b_operator_handoff_input_invalid','caller-supplied grant rejected');
$r=$c::plan(array('release_sha256'=>$hash));
ck(is_wp_error($r) && $r->get_error_code()==='no_live_production_context','planner requires live trust context');
echo 'CENTRAL_NATIVE_OPERATOR_HANDOFF: PASS'.PHP_EOL;
