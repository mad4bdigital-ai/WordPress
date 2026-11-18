<?php
/** Original306 fields() exact original305 owner+328 transport readback.
 * Isolated: no DB, WordPress bootstrap, key access or Broker dispatch.
 */
declare(strict_types=1);
define('ABSPATH', '/source');
define('MAD4B_MU_MODULAR_LOADER_BOOTED', true);
function add_action(...$args) {}
class WP_Error {
    public function __construct(public string $code) {}
    public function get_error_code() { return $this->code; }
}
function get_option($key, $fallback=false) {
    return $GLOBALS['options'][$key] ?? $fallback;
}
class MAD4B_Central_SSH_Command_Intake_V1 {
    const REQUEST_PREFIX='mad4b_central_ssh_request_v1_';
    const PREFIX='mad4b_central_ssh_grant_v1_';
    const TRANSPORT_PREFIX='mad4b_central_ssh_transport_v1_';
}
class MAD4B_Company_Native_WPCLI_Enrollment_V1 {
    const SLOT='mad4b_company_native_wpcli_enrollment_v1_';
}
class MAD4B_Original_Remote_Hostinger_328_V1 {
    public static function active($transport,$parent) {
        return ($GLOBALS['live328']??false)===true
            && ($transport['transport_id']??null)==='4'.str_repeat('4',31)
            && ($parent['grant_id']??null)==='3'.str_repeat('3',31);
    }
}
class MAD4B_Original_Native_Operation_Providers_V1 {
    public static function legacy_admitted($operation,$scope,$format) {
        return ($GLOBALS['signedIssuerReady']??false)===true
            && in_array($operation,['company.host-authority.anchor.issue',
                                     'company.host-authority.anchor.revoke'],true)
            && $scope==='host' && $format==='host.anchor.ref.v2';
    }
}
class MAD4B_Original_Verified_WPCLI_Transport_V1 {
    public static function permitted($transport,$grant) {
        return ($GLOBALS['wpcliTransportReady']??false)===true
            && ($grant['operation']??'')==='company.remote.wpcli.full';
    }
}
class MAD4B_Company_Operation_Admission_V1 {
    public static function manager_id() { return 'manager'; }
}
class MAD4B_Manager_Auto_Write_V1 {
    public static function company_target($company) {
        return ['state'=>'REGISTERED',
            'site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb',
            'canonical_origin'=>'https://staging.allroyalegypt.com',
            'environment'=>'staging'];
    }
    public static function entitlement($m,$company,$intent,$user) {
        return ['assignment_authorized'=>true,
            'manager_membership_active'=>true,
            'company_binding_verified'=>true];
    }
}
require '/source/wp-content/plugins/mad4b-platform/modules/compat/306-mad4b-central-vps-broker.php';
const JOB='1'.'1111111111111111111111111111111';
const GRANT='2'.'2222222222222222222222222222222';
const PARENT='3'.'3333333333333333333333333333333';
const TRANS='4'.'4444444444444444444444444444444';
const SITE='49c562d1-8f2f-456f-b454-26816c6ba4cb';
const ORIGIN='https://staging.allroyalegypt.com';
const OP='company.host-authority.anchor.issue';
$job=[
    'contract'=>'mad4b.central-ssh-command-intake.v1',
    'request_id'=>JOB,'company'=>'all-royal-egypt',
    'status'=>'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
    'execution_performed'=>false,
    'expires_epoch'=>time()+3600,
    'grant_id'=>GRANT,
    'operation'=>OP,'target_scope'=>'host',
    'command_format'=>'host.anchor.ref.v2',
    'central_principal_id'=>59,
    'site_uuid'=>SITE,'canonical_origin'=>ORIGIN,'environment'=>'staging',
    'target_host_id'=>'hosting-2','grant_mode'=>'persistent',
    'transport_id'=>TRANS,
];
$grant=[
    'contract'=>'mad4b.central-ssh-command-intake.v1',
    'grant_id'=>GRANT,'company'=>'all-royal-egypt',
    'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED',
    'operation'=>OP,'central_principal_id'=>59,
    'principal_type'=>'signed_host_principal_v2',
    'target_host_id'=>'hosting-2',
    'original328_parent_grant_id'=>PARENT,
];
$parent=[
    'grant_id'=>PARENT,
    'company'=>'all-royal-egypt','operation'=>'company.remote.wpcli.full',
    'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED',
    'site_uuid'=>SITE,'canonical_origin'=>ORIGIN,
    'environment'=>'staging','central_principal_id'=>59,
    'transport_id'=>TRANS,
];
$transport=[
    'transport_id'=>TRANS,'company'=>'all-royal-egypt',
    'host_id'=>'hosting-2','site_uuid'=>SITE,
    'environment'=>'staging','canonical_origin'=>ORIGIN,
    'state'=>'REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED',
];
$GLOBALS['options']=[
    MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.JOB=>$job,
    MAD4B_Central_SSH_Command_Intake_V1::PREFIX.GRANT=>$grant,
    MAD4B_Central_SSH_Command_Intake_V1::PREFIX.PARENT=>$parent,
    MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.TRANS=>$transport,
    MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.'all-royal-egypt_staging'=>[
        'grant_id'=>PARENT,'transport_id'=>TRANS
    ],
];
$GLOBALS['signedIssuerReady']=true;
$GLOBALS['live328']=true;
$GLOBALS['wpcliTransportReady']=false;
$checks=0;
$method=new ReflectionMethod(MAD4B_Central_VPS_Broker_V1::class,'fields');
function check($yes,$what) {global $checks;$checks++;if(!$yes)throw new Exception("$checks:$what");}
function fields() {global $method;return $method->invoke(null,'all-royal-egypt',JOB);}
function assertAllowed($name) {check(is_array(fields()),"must accept $name");}
function assertDenied($name) {check(fields() instanceof WP_Error,"must reject $name");}

assertAllowed('exact signed original305 host v2 distinct grant with independently active 328');
$GLOBALS['signedIssuerReady']=false;
assertDenied('uncommissioned issuer');
$GLOBALS['signedIssuerReady']=true;
$GLOBALS['live328']=false;
assertDenied('inactive Original328 parent transport');
$GLOBALS['live328']=true;
foreach([
    ['grant','principal_type','signed_hostinger_ssh_wpcli'],
    ['grant','original328_parent_grant_id','f'.str_repeat('f',31)],
    ['grant','central_principal_id',33],
    ['parent','operation','maintenance.shell'],
    ['parent','state','REVOKED'],
    ['parent','central_principal_id',33],
    ['parent','site_uuid','ffffffff-ffff-4fff-8fff-ffffffffffff'],
    ['transport','environment','production'],
    ['transport','state','PENDING_INDEPENDENT_SSH_VERIFICATION'],
    ['request','command_format','wpcli.argv.json.v1'],
    ['request','operation','company.remote.wpcli.full'],
    ['request','target_scope','wordpress'],
    ['request','target_host_id','hosting-3'],
] as [$which,$field,$new]) {
    $key=match($which) {
        'grant'=>MAD4B_Central_SSH_Command_Intake_V1::PREFIX.GRANT,
        'parent'=>MAD4B_Central_SSH_Command_Intake_V1::PREFIX.PARENT,
        'transport'=>MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.TRANS,
        default=>MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.JOB,
    };
    $old=$GLOBALS['options'][$key][$field];
    $GLOBALS['options'][$key][$field]=$new;
    assertDenied('tamper-'.$which.'-'.$field);
    $GLOBALS['options'][$key][$field]=$old;
}
assertAllowed('restored valid host v2');
// Original general WordPress transport remains distinct and unchanged.
$oldRequest=$GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.JOB];
$oldGrant=$GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::PREFIX.GRANT];
$GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.JOB]['operation']
    ='company.remote.wpcli.full';
$GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.JOB]['target_scope']
    ='wordpress';
$GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.JOB]['command_format']
    ='wpcli.argv.json.v1';
$GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::PREFIX.GRANT]['operation']
    ='company.remote.wpcli.full';
$GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::PREFIX.GRANT]['principal_type']
    ='signed_hostinger_ssh_wpcli';
$GLOBALS['wpcliTransportReady']=true;
assertAllowed('existing WPCLI exact transport still admitted');
$GLOBALS['wpcliTransportReady']=false;
assertDenied('revoked original WPCLI still denied');
$GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.JOB]=$oldRequest;
$GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::PREFIX.GRANT]=$oldGrant;
echo json_encode(['state'=>'PASS','checks'=>$checks,
    'host_v2_claimed'=>false,'anchor_issued'=>false,
    'original313_receipt_signed'=>false]).PHP_EOL;
