<?php
/** Hermetic owner-bound Original305 -> Original306 queued Bootstrap contract.
 * Never connects to WordPress, emits a Grant or queues a real Broker Job.
 */
declare(strict_types=1);
error_reporting(E_ALL);
define('ABSPATH','/source');
define('WP_PLUGIN_DIR','/source/wp-content/plugins');
define('WPMU_PLUGIN_DIR','/source/wp-content/mu-plugins');
define('MAD4B_MU_MODULAR_LOADER_BOOTED',true);
class WP_REST_Request implements ArrayAccess {
  public function __construct(public string $route,public string $method,
     public array $scope=[],public array $params=[]){}
  public function offsetExists(mixed $k):bool{return isset($this->scope[$k]);}
  public function offsetGet(mixed $k):mixed{return $this->scope[$k]??null;}
  public function offsetSet(mixed $k,mixed $v):void{$this->scope[$k]=$v;}
  public function offsetUnset(mixed $k):void{unset($this->scope[$k]);}
  public function get_json_params():array{return $this->params;}
  public function get_header($name):string{return $name==='X-WP-Nonce'?'valid-test-nonce':'';}
  public function get_method():string{return $this->method;}
  public function get_route():string{return $this->route;}
  public function get_body():string{return json_encode($this->params);}
}
class WP_REST_Response {public function __construct(public array $data,public int $status=200){}}
class WP_Error {
 public function __construct(public string $code,public string $message,public array $data=[]){}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v):bool{return $v instanceof WP_Error;}
function get_option($key,$otherwise=false) {return $GLOBALS['options'][$key]??$otherwise;}
function add_option($key,$value,$unused='',$autoload=false):bool {
 if(array_key_exists($key,$GLOBALS['options']))return false;
 $GLOBALS['options'][$key]=$value;
 $GLOBALS['writes'][]=['add',$key];return true;
}
function update_option($key,$value,$unused=false):bool{
 $GLOBALS['options'][$key]=$value;
 $GLOBALS['writes'][]=['update',$key];return true;
}
function get_current_user_id():int{return $GLOBALS['actor'];}
function is_user_logged_in():bool{return $GLOBALS['actor']>0;}
function current_user_can($cap):bool{return $GLOBALS['actor']>0&&in_array($cap,['manage_options','activate_plugins'],true);}
function wp_verify_nonce($nonce,$action):int|false{return $nonce==='valid-test-nonce'&&$action==='wp_rest'?1:false;}
function wp_json_encode($data,$flags=0):string|false{return json_encode($data,$flags);}
function sanitize_text_field($text):string{return trim(strip_tags((string)$text));}
function add_action(...$args):bool{return true;}
function do_action(...$args):bool{return true;}
class MAD4B_Central_Dual_Lane_Registry_V1 {
 const HOST_MAINTENANCE=[];
 public static function allowed():bool{return true;}
 public static function central():bool{return true;}
}
class MAD4B_SCP_Site_Profile {
 public static function user_is_enrolled($id):bool{return $id===59;}
}
class MAD4B_Company_Operation_Admission_V1 {
 public static function assess($company,$intent):array{return ['policy_delegation_admitted'=>true,'company_site_uuid'=>SITE];}
 public static function manager_id():string{return 'manager';}
}
class MAD4B_Manager_Auto_Write_V1 {
 public static function company_target($company):array{
  return ['state'=>'REGISTERED','site_uuid'=>SITE,
      'canonical_origin'=>ORIGIN,'environment'=>'staging'];
 }
 public static function entitlement($manager,$company,$intent,$id):array{
  return ['assignment_authorized'=>$id===59,
   'manager_membership_active'=>true,'company_binding_verified'=>true,
   'assignment_revision'=>5];
 }
}
class MAD4B_Company_Native_WPCLI_Enrollment_V1 {
 const SLOT='mad4b_company_native_wpcli_enrollment_v1_';
}
class MAD4B_Original_Remote_Hostinger_328_V1 {
 public static function active($transport,$parent):bool{
  return ($GLOBALS['active328']??false)===true
    &&($transport['transport_id']??'')===TRANSPORT
    &&($parent['grant_id']??'')===PARENT;
 }
}
const COMPANY='all-royal-egypt';
const SITE='49c562d1-8f2f-456f-b454-26816c6ba4cb';
const ORIGIN='https://staging.allroyalegypt.com';
const TRANSPORT='44444444444444444444444444444444';
const PARENT='33333333333333333333333333333333';
const ISSUE='company.host-authority.anchor.issue';
const REVOKE='company.host-authority.anchor.revoke';
$GLOBALS['actor']=59;
$GLOBALS['active328']=true;
$GLOBALS['options']=[];
$GLOBALS['writes']=[];
$oldParent=[
 'contract'=>'mad4b.central-ssh-command-intake.v1',
 'grant_id'=>PARENT,'operation'=>'company.remote.wpcli.full',
 'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED','company'=>COMPANY,
 'site_uuid'=>SITE,'canonical_origin'=>ORIGIN,'environment'=>'staging',
 'grant_mode'=>'persistent','transport_id'=>TRANSPORT,
 'central_principal_id'=>59,'target_host_id'=>'hosting-2',
 'target_ssh_principal'=>'u948953904',
];
$transport=[
 'transport_id'=>TRANSPORT,'state'=>'REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED',
 'company'=>COMPANY,'host_id'=>'hosting-2','host_principal'=>'u948953904',
 'principal_type'=>'signed_hostinger_ssh_wpcli',
 'central_principal_id'=>59,
 'assignment_revision'=>5,'site_uuid'=>SITE,
 'canonical_origin'=>ORIGIN,'environment'=>'staging',
];
$GLOBALS['options']['mad4b_central_ssh_grant_v1_'.PARENT]=$oldParent;
$GLOBALS['options']['mad4b_central_ssh_transport_v1_'.TRANSPORT]=$transport;
$GLOBALS['options'][MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.COMPANY.'_staging']=[
 'company'=>COMPANY,'environment'=>'staging',
 'transport_id'=>TRANSPORT,'grant_id'=>PARENT,'site_uuid'=>SITE
];
require '/source/wp-content/plugins/mad4b-platform/modules/integrations/344-mad4b-original-native-operation-providers.php';
require '/source/wp-content/plugins/mad4b-platform/modules/compat/305-mad4b-central-ssh-command-intake.php';
require '/source/wp-content/plugins/mad4b-platform/modules/compat/306-mad4b-central-vps-broker.php';
$tests=0;
function check($ok,$label){
 global $tests;$tests++;
 if(!$ok)throw new RuntimeException('CHECK_'.$tests.':'.$label);
}
$cls='MAD4B_Original_Native_Operation_Providers_V1';
check($cls::first_anchor_bootstrap_intake_registered(ISSUE,'host','host.anchor.ref.v2')===true,'bootstrap registrable');
check($cls::first_anchor_bootstrap_intake_registered(REVOKE,'host','host.anchor.ref.v2')===false,'revoke not bootstrap');
check($cls::first_anchor_bootstrap_intake_registered(ISSUE,'wordpress','host.anchor.ref.v2')===false,'cannot inherit WPCLI');
check($cls::legacy_admitted(ISSUE,'host','host.anchor.ref.v2')===false,'issuer not ready');
$base='/mad4b/v1/central-execution-lanes/'.COMPANY;
$grantRequest=new WP_REST_Request($base.'/grants','POST',['company'=>COMPANY],[
 'operation'=>ISSUE,'host_id'=>'hosting-2','host_principal'=>'u948953904',
 'reason'=>'Owner authenticated first Host Anchor bootstrap',
 'grant_mode'=>'persistent','transport_id'=>TRANSPORT
]);
$response=MAD4B_Central_SSH_Command_Intake_V1::issue($grantRequest);
check($response instanceof WP_REST_Response,'bootstrap grant created by real owner mock');
check($response->status===201,'grant accepted as staged');
$grant=$response->data;
check($grant['host_v2_bootstrap_only']===true,'grant cannot be promoted to full authority');
check($grant['host_v2_execution_authorized']===false,'grant never authorizes');
check($grant['original328_parent_grant_id']===PARENT,'independent parent binding');
check(($grant['operation']??null)===ISSUE,'issue only');
$grant_id=$grant['grant_id'];
check(strlen($grant_id)===32,'random distinct grant');
$GLOBALS['actor']=0;
$anonymous=MAD4B_Central_SSH_Command_Intake_V1::issue($grantRequest);
check($anonymous instanceof WP_Error,'anonymous cannot mint grant');
$GLOBALS['actor']=59;
$revocation=new WP_REST_Request($base.'/grants','POST',['company'=>COMPANY],
 array_replace($grantRequest->params,['operation'=>REVOKE]));
$revoked=MAD4B_Central_SSH_Command_Intake_V1::issue($revocation);
check($revoked instanceof WP_Error,'cannot stage revoke before issuer');
$plan=str_repeat('a',64);
$witness=[
 'authority_mode'=>'bootstrap','operation_id'=>ISSUE,
 'plan_sha256'=>$plan,'site_uuid'=>SITE,'target_host_id'=>'hosting-2'
];
$argv=['mad4b','host-anchor','issue','--plan_sha256='.$plan];
$commandRequest=new WP_REST_Request($base.'/commands','POST',['company'=>COMPANY],[
 'grant_id'=>$grant_id,'command'=>null,'argv'=>$argv,
 'verification'=>$witness,'summary'=>'Owner approved exact original305 first anchor bootstrap',
 'correlation_id'=>'first-boot-20261118-1','target_scope'=>'host'
]);
$command=MAD4B_Central_SSH_Command_Intake_V1::accept_command($commandRequest);
check($command instanceof WP_REST_Response,'signed owner can stage command');
check($command->status===202,'request only pending independent root');
check($command->data['execution_performed']===false,'command execution not performed');
check($command->data['host_v2_bootstrap_only']===true,'receipt cannot be operational');
check($command->data['host_anchor_identity']['plan_sha256']===$plan,'immutable plan');
check($command->data['target_scope']==='host','host only');
$job_id=$command->data['request_id'];
$fields=new ReflectionMethod(MAD4B_Central_VPS_Broker_V1::class,'fields');
$validated=$fields->invoke(null,COMPANY,$job_id);
check(is_array($validated),'original306 exact owner request accepted');
check($validated['host_v2_bootstrap_only']===true,'broker retains preflight marker');
$projection=MAD4B_Central_VPS_Broker_V1::original_host_v2_job_projection($validated);
check(is_array($projection),'original306 projection valid');
check($projection['authority_mode']==='bootstrap','never mistaken for operational');
check($projection['host_anchor_issuer_commissioned']===false,'not an operational signer');
check($projection['original313_receipt_signed']===false,'no 313 forged');
check($projection['host_v2_bootstrap_only']===true,'exact queue remains bootstrap only');
$operational=$commandRequest->params;
$operational['correlation_id']='operational-forbidden-1';
$operational['verification']=array_replace($witness,['authority_mode'=>'operational']);
$bad=MAD4B_Central_SSH_Command_Intake_V1::accept_command(
 new WP_REST_Request($base.'/commands','POST',['company'=>COMPANY],$operational));
check($bad instanceof WP_Error,'bootstrap grant cannot rotate or operational issue');
$GLOBALS['active328']=false;
$inactive=$fields->invoke(null,COMPANY,$job_id);
check($inactive instanceof WP_Error,'328 parent revoked blocks broker');
$GLOBALS['active328']=true;
$invalid=$GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$job_id];
$invalid['host_v2_bootstrap_only']=false;
$GLOBALS['options'][MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$job_id]=$invalid;
check($fields->invoke(null,COMPANY,$job_id) instanceof WP_Error,'tampered request marker denied');
echo json_encode(['state'=>'PASS','checks'=>$tests,
 'preflight_grants_written_to_mock'=>1,
 'real_anchors_issued'=>0,'broker_claim_created'=>false,
 'original312_signed'=>false,'original313_receipt_signed'=>false]).PHP_EOL;
