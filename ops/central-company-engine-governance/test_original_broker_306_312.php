<?php
/** In-memory 306->312 job/transport regression; no real WP, SSH, or grants. */
declare(strict_types=1);
define('ABSPATH', '/');
$GLOBALS['fixture_options'] = [];
function add_action(...$args) {}
function get_current_user_id(){return 7;}
function get_option($key,$default=false){return $GLOBALS['fixture_options'][$key] ?? $default;}
function add_option($key,$value,$deprecated='',$autoload=false){
 if(array_key_exists($key,$GLOBALS['fixture_options']))return false;
 $GLOBALS['fixture_options'][$key]=$value;return true;
}
function rest_ensure_response($val){return new WP_REST_Response($val,200);}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error {
 public function __construct(public $code,public $message,public $data=[]){}
 public function get_error_code(){return $this->code;}
}
class WP_REST_Response {public function __construct(public $data,public $status=200){}}
class WP_REST_Request implements ArrayAccess {
 public function __construct(private $fields,private $json){}
 public function offsetExists(mixed $k):bool{return isset($this->fields[$k]);}
 public function offsetGet(mixed $k):mixed{return $this->fields[$k]??null;}
 public function offsetSet(mixed $k,mixed $v):void{$this->fields[$k]=$v;}
 public function offsetUnset(mixed $k):void{unset($this->fields[$k]);}
 public function get_json_params(){return $this->json;}
}
class MAD4B_Central_SSH_Command_Intake_V1 {
 const PREFIX='fixture_grant_';
 const TRANSPORT_PREFIX='fixture_transport_';
 const REQUEST_PREFIX='fixture_request_';
 const CLAIM_PREFIX='fixture_claim_';
 public static function permitted($r){return true;} // Hermetic fixture ONLY.
}
class MAD4B_Central_Dual_Lane_Registry_V1{public static function central(){return true;}}
class MAD4B_Company_Operation_Admission_V1{public static function manager_id(){return 'manager';}}
class MAD4B_Manager_Auto_Write_V1{
 public static function company_target($company){return [
  'state'=>'REGISTERED','site_uuid'=>'test-site',
  'canonical_origin'=>'https://staging.example.test','environment'=>'staging'];}
}
class MAD4B_Company_Portfolio_Manager_V1{
 public static function has_delegation(...$args){return true;}
}
require __DIR__.'/original-broker-source/306-mad4b-central-vps-broker.php';
require __DIR__.'/original-broker-source/312-mad4b-broker-exact-ssh-authority.php';
function must($v,string $message){if(!$v){fwrite(STDERR,"FAIL $message\n");exit(1);}}
$id=str_repeat('a',32);$grant_id=str_repeat('b',32);$transport_id=str_repeat('c',32);
$digest=str_repeat('f',64);$expires=time()+360;
$req=[
 'request_id'=>$id,'company'=>'wayfatours','status'=>'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
 'execution_performed'=>false,'expires_epoch'=>$expires,'grant_id'=>$grant_id,
 'central_principal_id'=>7,'operation'=>'company.remote.wpcli.full',
 'grant_mode'=>'persistent','transport_id'=>$transport_id,
 'target_host_id'=>'hosting-3','target_ssh_principal'=>'siteuser',
 'site_uuid'=>'test-site','canonical_origin'=>'https://staging.example.test',
 'environment'=>'staging','target_scope'=>'wordpress','command_format'=>'wpcli.argv.json.v1','correlation_id'=>'test-123456',
 'command_sha256'=>$digest];
$grant=[
 'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED','company'=>'wayfatours',
 'central_principal_id'=>7,'operation'=>'company.remote.wpcli.full',
 'expires_epoch'=>null,'grant_mode'=>'persistent','site_uuid'=>'test-site',
 'canonical_origin'=>'https://staging.example.test','environment'=>'staging',
 'transport_id'=>$transport_id,'assignment_revision'=>3,'target_host_id'=>'hosting-3',
 'target_ssh_principal'=>'siteuser'];
$transport=[
 'state'=>'PENDING_INDEPENDENT_SSH_VERIFICATION','company'=>'wayfatours',
 'host_id'=>'hosting-3','transport_id'=>$transport_id,
 'site_uuid'=>'test-site','canonical_origin'=>'https://staging.example.test',
 'environment'=>'staging','central_principal_id'=>7,'assignment_revision'=>3,
 'host_principal'=>'siteuser','host_key_fingerprint'=>'SHA256:fixture',
 'credential_ref'=>'vault:registry/hosting-3','full_wpcli_grant_id'=>$grant_id,
 'full_wpcli_pending_native_enrollment'=>true,'capabilities'=>['wpcli.full']];
$opts=&$GLOBALS['fixture_options'];
$opts[MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id]=$req;
$opts[MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$grant_id]=$grant;
$opts[MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$transport_id]=$transport;
$opts[MAD4B_Central_VPS_Broker_V1::HOST_ID]='srv2030908';
$dispatch=MAD4B_Central_VPS_Broker_V1::dispatch(new WP_REST_Request(
 ['company'=>'wayfatours'],['request_id'=>$id]));
must($dispatch instanceof WP_REST_Response&&$dispatch->status===202,'306 dispatch rejected');
$job=$dispatch->data;
must($job['transport_id']===$transport_id,'306 lost transport id');
must($job['target_scope']==='wordpress','306 lost target scope');
must($job['grant_id']===$grant_id,'306 lost grant binding');
must(($job['job_type']??null)==='signed_vps_wpcli','306 must record signed WP-CLI job type');
$repeated=MAD4B_Central_VPS_Broker_V1::dispatch(new WP_REST_Request(
 ['company'=>'wayfatours'],['request_id'=>$id]));
must($repeated instanceof WP_REST_Response&&$repeated->status===200,'306 repeat unsafe');
// Only the test fixture upgrades the central job to a signed-claim-shape;
// real Broker 306 must verify a real signature before doing so.
$job['state']='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY';
// The fixture models the consumed 305 per-correlation claim; no claim is minted by 312.
$opts[MAD4B_Central_VPS_Broker_V1::JOB.$id]=$job;
$opts[MAD4B_Central_SSH_Command_Intake_V1::CLAIM_PREFIX.$grant_id.'_'.hash('sha256',$job['correlation_id'])]=$id;
$opts[MAD4B_Central_VPS_Broker_V1::CLAIM.$id]=[
 'job_id'=>$id,'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
 'host_id'=>'srv2030908','claimed_epoch'=>time()];
$eval=fn()=>MAD4B_Broker_Exact_SSH_Authority_V1::evaluate($id,'hosting-3',$digest);
$pass=$eval();
must(($pass['eligible']??false)===true,'312 rejected exact scoped job');
must(($pass['owner_enrolled_wpcli']??false)===true,'312 missing owner-enrollment binding');
must($pass['transport_id']===$transport_id,'312 lost transport reference');
$neg=0;
function reject_change(&$opts,$key,$property,$value,$evaluate,&$neg){
 $before=$opts[$key];$opts[$key][$property]=$value;
 must(($evaluate()['eligible']??false)===false,"312 admitted $property drift");
 $opts[$key]=$before;$neg++;
}
reject_change($opts,MAD4B_Central_VPS_Broker_V1::JOB.$id,'transport_id','different',$eval,$neg);
reject_change($opts,MAD4B_Central_VPS_Broker_V1::JOB.$id,'site_uuid','different',$eval,$neg);
reject_change($opts,MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$transport_id,'state','REVOKED',$eval,$neg);
reject_change($opts,MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$transport_id,'company','different',$eval,$neg);
reject_change($opts,MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$transport_id,'full_wpcli_grant_id','different',$eval,$neg);
reject_change($opts,MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$grant_id,'state','REVOKED',$eval,$neg);
reject_change($opts,MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id,'target_scope','host',$eval,$neg);
reject_change($opts,MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id,'command_format','maintenance.shell.text.v1',$eval,$neg);
echo json_encode(['suite'=>'original-306-312-job-handoff','status'=>'PASS',
 'assertions'=>6+$neg,'real_wordpress_mutated'=>false]).PHP_EOL;
