<?php
declare(strict_types=1);
require dirname(__DIR__).'/host-v106/test_owner_console_render.php';
class WP_REST_Request {}
class WP_REST_Response {
    public array $data;
    public int $status;
    public array $headers=[];
    public function __construct($data,$status=200) {
        $this->data=$data;$this->status=$status;
    }
    public function header($name,$value){$this->headers[$name]=$value;}
}
class MAD4B_Central_VPS_Broker_V1 {
    const JOB='mad4b_test_broker_job_';
}
$actor=7;
$caps=['manage_options','activate_plugins'];
$unified=false;
$wpdb=new class extends wpdb {
    public function get_col($query){return array_keys($GLOBALS['options']);}
};
$g=str_repeat('c',32);
$q=str_repeat('d',32);
$plan=str_repeat('e',64);
$meta=[
    'company'=>'all-royal-egypt','environment'=>'staging',
    'central_principal_id'=>7,'transport_id'=>$host,
    'operation'=>'company.host-authority.anchor.issue',
    'host_v2_bootstrap_only'=>true
];
$options[MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$g]
    =array_merge($meta,[
        'grant_id'=>$g,'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED']);
$options[MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$q]
    =array_merge($meta,[
        'request_id'=>$q,
        'grant_id'=>$g,
        'site_uuid'=>$site,
        'command_format'=>'host.anchor.ref.v2',
        'target_scope'=>'host',
        'execution_performed'=>false,
        'status'=>'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
        'expires_epoch'=>time()+260,
        'host_anchor_identity'=>[
            'authority_mode'=>'bootstrap',
            'operation_id'=>'company.host-authority.anchor.issue',
            'plan_sha256'=>$plan,
            'site_uuid'=>$site,
            'target_host_id'=>'hosting-2'
        ]
    ]);
$options[MAD4B_Central_VPS_Broker_V1::JOB.$q]=[
    'job_id'=>$q,'plan_sha256'=>$plan,
    'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
];
$before=serialize($options);
MAD4B_Host_Authority_Owner_Console_V106::register_read_route();
$route='mad4b/v1/central-execution-lanes/all-royal-egypt/host-authority-owner-state';
if(!isset($GLOBALS['rest_routes'][$route])
 ||$GLOBALS['rest_routes'][$route]['methods']!=='GET'
 ||!is_callable($GLOBALS['rest_routes'][$route]['permission_callback']))
 throw new RuntimeException('OWNER_AJAX_ENDPOINT_MISSING_OR_NOT_GET');
$s=MAD4B_Host_Authority_Owner_Console_V106::snapshot();
if(!$s['owner_verified']
 ||$s['plan_sha256']!==$plan
 ||$s['plan_source']!=='previous_owner_request_reference'
 ||$s['broker_job_state']!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY')
 throw new RuntimeException('OWNER_PLAN_SOURCE_OR_BROKER_CLAIM_MISSING');
if(!MAD4B_Host_Authority_Owner_Console_V106::read_permission())
 throw new RuntimeException('VERIFIED_OWNER_READ_DENIED');
$resp=MAD4B_Host_Authority_Owner_Console_V106::read_state(new WP_REST_Request());
if($resp->status!==200
 ||($resp->data['plan_sha256']??null)!==$plan
 ||($resp->data['broker_job_state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
 ||($resp->data['company']??null)!=='all-royal-egypt'
 ||($resp->data['environment']??null)!=='staging'
 ||!str_contains($resp->headers['Cache-Control']??'','no-store')
 ||!is_int($resp->data['server_epoch']??null)
 ||isset($resp->data['host_principal'],$resp->data['transport_id']))
 throw new RuntimeException('OWNER_AJAX_READ_DATA_INVALID');
ob_start();MAD4B_Host_Authority_Owner_Console_V106::screen();$html=ob_get_clean();
if(!str_contains($html,'"planSha256":"'.$plan.'"')
 ||!str_contains($html,'id="mad4b-ha-plan-editor" hidden')
 ||!str_contains($html,'Root Plan reference loaded automatically')
 ||!str_contains($html,'Live status is loaded automatically'))
 throw new RuntimeException('AUTO_ROOT_PLAN_AND_AJAX_UX_NOT_READY');
$options[MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$q]['expires_epoch']=time()-60;
$stillClaimed=MAD4B_Host_Authority_Owner_Console_V106::snapshot();
if($stillClaimed['phase']!=='ORIGINAL305_REQUEST_STAGED'
 ||!$stillClaimed['broker_job_exists']
 ||$stillClaimed['plan_sha256']!==$plan)
 throw new RuntimeException('CLAIMED_JOB_MUST_SURVIVE_OWNER_EXPIRY_IN_READBACK');
unset($options[MAD4B_Central_VPS_Broker_V1::JOB.$q]);
$expired=MAD4B_Host_Authority_Owner_Console_V106::snapshot();
if($expired['phase']!=='OWNER_REQUEST_EXPIRED'
 ||$expired['plan_sha256']!==$plan)
 throw new RuntimeException('EXPIRED_REFERENCE_SHOULD_LOAD_WITH_FRESH_CONSENT');
$options[MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$q]['host_anchor_identity']['site_uuid']='not-owner-site';
if(MAD4B_Host_Authority_Owner_Console_V106::snapshot()['plan_sha256']!=='')
 throw new RuntimeException('FOREIGN_SITE_PLAN_REFERENCE_EXPOSED');
$options[MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$q]['host_anchor_identity']['site_uuid']=$site;
$options[MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$q]['grant_id']=str_repeat('f',32);
if(MAD4B_Host_Authority_Owner_Console_V106::snapshot()['plan_sha256']!=='')
 throw new RuntimeException('DIFFERENT_GRANT_PLAN_REFERENCE_EXPOSED');
$options[MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$q]['grant_id']=$g;
$caps=['mad4b_manage_company_operations'];
if(MAD4B_Host_Authority_Owner_Console_V106::read_permission())
 throw new RuntimeException('UNVERIFIED_MASTER_ADMIN_EXPOSED_OWNER_STATE');
$denied=MAD4B_Host_Authority_Owner_Console_V106::read_state(new WP_REST_Request());
if($denied->status!==403||isset($denied->data['plan_sha256']))
 throw new RuntimeException('UNAUTHORIZED_OWNER_READ_EXPOSED_PLAN');
echo json_encode(['state'=>'PASS','checks'=>22,
 'private_ajax_read'=>true,'owner_bound_plan_reference'=>true,
 'independent_signature_claimed'=>false,'no_write_from_get'=>true]).PHP_EOL;
