<?php
/** Hermetic true 305 + 306 signed recovery handoff. No WordPress/SSH writes. */
declare(strict_types=1);
define('ABSPATH','/');
$GLOBALS['options']=[];
function add_action(...$a){}
function wp_verify_nonce($nonce,$action){return $nonce==='fixture-nonce'&&$action==='wp_rest';}
function get_current_user_id(){return 7;}
function sanitize_text_field($s){return (string)$s;}
function wp_json_encode($data,$opts=0){return json_encode($data,$opts);}
function is_wp_error($x){return $x instanceof WP_Error;}
function rest_ensure_response($v){return new WP_REST_Response($v);}
function get_option($k,$default=false){return array_key_exists($k,$GLOBALS['options'])?$GLOBALS['options'][$k]:$default;}
function add_option($k,$v,$dep='',$autoload=false){
 if(array_key_exists($k,$GLOBALS['options']))return false;
 $GLOBALS['options'][$k]=$v;return true;
}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;return true;}
class WP_Error{
 public function __construct(public $code,public $message,public $data=[]){}
 public function get_error_code(){return $this->code;}
}
class WP_REST_Response{public function __construct(public $data,public $status=200){}}
class WP_REST_Request implements ArrayAccess{
 private $params=[],$headers=[],$body='';
 public function __construct($method='POST',$route='/'){}
 public function get_header($key){return $this->headers[strtolower($key)]??'';}
 public function set_header($key,$value){$this->headers[strtolower($key)]=$value;}
 public function set_param($key,$value){$this->params[$key]=$value;}
 public function set_body($body){$this->body=$body;}
 public function get_json_params(){return json_decode($this->body,true);}
 public function offsetExists(mixed $k):bool{return isset($this->params[$k]);}
 public function offsetGet(mixed $k):mixed{return $this->params[$k]??null;}
 public function offsetSet(mixed $k,mixed $v):void{$this->params[$k]=$v;}
 public function offsetUnset(mixed $k):void{unset($this->params[$k]);}
}
class MAD4B_Company_Manager_Unified_Administrator_Principal{
 public static function verified(){return false;}
}
class MAD4B_Central_Dual_Lane_Registry_V1{
 const HOST_MAINTENANCE=['maintenance.shell'];
 public static function central(){return true;}
 public static function allowed(){return true;}
}
class MAD4B_Company_Operation_Admission_V1{
 public static function manager_id(){return 'manager-1';}
 public static function assess($company,$operation){return [
  'policy_delegation_admitted'=>$company==='egypt-tour-gates',
  'company_site_uuid'=>'staging-site'];}
}
class MAD4B_Company_Portfolio_Manager_V1{
 public static function has_delegation(...$a){return true;}
}
class MAD4B_Manager_Auto_Write_V1{
 public static function company_target($company){return [
  'state'=>$company==='egypt-tour-gates'?'REGISTERED':'UNKNOWN',
  'site_uuid'=>'staging-site','canonical_origin'=>'https://tours.dev.mad4b.com',
  'environment'=>'staging'];}
 public static function entitlement(...$a){return [
  'assignment_authorized'=>true,'assignment_revision'=>5];}
}
require __DIR__.'/original-broker-source/305-mad4b-central-ssh-command-intake.php';
require __DIR__.'/original-broker-source/306-mad4b-central-vps-broker.php';
function ensure($yes,$label){if(!$yes){fwrite(STDERR,"FAIL: $label\n");exit(1);}}
function signed(string $contract,string $purpose,array $fields,string $secret):string{
 return base64_encode(sodium_crypto_sign_detached(
  implode("\n",array_merge([$contract,$purpose],$fields)),$secret));
}
$id=str_repeat('a',32);$grantId=str_repeat('b',32);$transportId=str_repeat('c',32);
$command='printf fixed-staging-audit';
$hash=hash('sha256',$command);
$job=['contract'=>MAD4B_Central_VPS_Broker_V1::CONTRACT,
 'job_id'=>$id,'company'=>'egypt-tour-gates',
 'operation'=>'maintenance.shell','site_uuid'=>'staging-site',
 'canonical_origin'=>'https://tours.dev.mad4b.com','environment'=>'staging',
 'broker_host_id'=>'srv2030908','target_host_id'=>'srv2030908',
 'target_ssh_principal'=>'www-data','grant_id'=>$grantId,
 'grant_mode'=>'persistent','transport_id'=>$transportId,
 'central_principal_id'=>7,'command_sha256'=>$hash,
 'command_format'=>'maintenance.shell.text.v1',
 'target_scope'=>'wordpress','state'=>'QUEUED_FOR_VPS_BROKER_CLAIM',
 'executed'=>false,'expires_epoch'=>time()+300];
$request=$job;
$request['request_id']=$id;
$request['status']='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY';
$request['execution_performed']=false;
$grant=['company'=>'egypt-tour-gates','operation'=>'maintenance.shell',
 'site_uuid'=>'staging-site','canonical_origin'=>'https://tours.dev.mad4b.com',
 'environment'=>'staging','assignment_revision'=>5,'central_principal_id'=>7,
 'target_host_id'=>'srv2030908','target_ssh_principal'=>'www-data',
 'grant_mode'=>'persistent','transport_id'=>$transportId,
 'grant_id'=>$grantId,'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED',
 'expires_epoch'=>null];
$transport=['company'=>'egypt-tour-gates','site_uuid'=>'staging-site',
 'canonical_origin'=>'https://tours.dev.mad4b.com','environment'=>'staging',
 'assignment_revision'=>5,'central_principal_id'=>7,'host_id'=>'srv2030908',
 'host_principal'=>'www-data','transport_id'=>$transportId,
 'state'=>'PENDING_INDEPENDENT_SSH_VERIFICATION'];
$opts=&$GLOBALS['options'];
$opts[MAD4B_Central_VPS_Broker_V1::JOB.$id]=$job;
$opts[MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id]=$request;
$opts[MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$grantId]=$grant;
$opts[MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$transportId]=$transport;
$opts[MAD4B_Central_VPS_Broker_V1::HOST_ID]='srv2030908';
$pair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($pair);
$opts[MAD4B_Central_VPS_Broker_V1::PUBKEY]=base64_encode(sodium_crypto_sign_publickey($pair));
$proof=['contract'=>MAD4B_Original_Core_Recovery_V1::CONTRACT,
 'job_id'=>$id,'provider'=>'container_exec','reason'=>'TRANSPORT_UNREACHABLE',
 'nonce'=>str_repeat('e',32),'issued'=>time(),'expires'=>time()+50,
 'dispatch_started'=>false,'claim_committed'=>false,'effects_possible'=>false];
$proof['signature']=signed($proof['contract'],'before-dispatch-failure',[
 $id,$job['company'],$job['site_uuid'],$job['canonical_origin'],
 $job['environment'],$job['target_host_id'],$grantId,$transportId,$hash,
 '7',$proof['provider'],$proof['reason'],$proof['nonce'],
 (string)$proof['issued'],(string)$proof['expires'],
 'dispatch_started=false','claim_committed=false','effects_possible=false'
],$secret);
$record=MAD4B_Original_Core_Recovery_V1::recordOriginalPreDispatchFailure($id,$proof);
ensure(($record['state']??'')==='RECORDED_PRE_DISPATCH_FAILURE','old before-dispatch journal');
$sealed=['contract'=>MAD4B_Original_Sealed_Command_Rebind_V1::CONTRACT,
 'original_job_id'=>$id,'provider'=>'vps_ssh','command_sha256'=>$hash,
 'nonce'=>str_repeat('f',32),'issued'=>time(),'expires'=>time()+50,
 'command'=>$command];
$sealed['signature']=signed($sealed['contract'],'original-core-rebind',[
 $id,$job['company'],$job['site_uuid'],$job['canonical_origin'],$job['environment'],
 $job['target_host_id'],$grantId,$transportId,'7',$job['operation'],
 $sealed['provider'],$hash,$sealed['nonce'],
 (string)$sealed['issued'],(string)$sealed['expires']
],$secret);
$rest=new WP_REST_Request();
$rest->set_param('company','egypt-tour-gates');
$rest->set_header('X-WP-Nonce','fixture-nonce');
$rest->set_body(json_encode(['sealed_payload'=>$sealed]));
$result=MAD4B_Original_Core_Recovery_V1::create($rest);
ensure($result instanceof WP_REST_Response,'full original 305/306 recovery failed: '.(
 $result instanceof WP_Error?$result->get_error_code():'other'));
ensure(($result->data['state']??'')==='ORIGINAL_CORE_RECOVERY_JOB_COMMITTED','recovery not committed');
$newId=$result->data['new_job_id'];
ensure(preg_match('/^[a-f0-9]{32}$/D',$newId)&&$newId!==$id,'fresh core job id required');
$newJob=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$newId);
ensure(is_array($newJob)&&$newJob['state']==='QUEUED_FOR_VPS_BROKER_CLAIM',
 'actual original broker did not queue replacement');
ensure($newJob['grant_id']===$grantId&&$newJob['transport_id']===$transportId&&
 $newJob['command_sha256']===$hash&&$newJob['executed']===false,
 'new job must retain immutable original grant, transport and digest');
ensure(get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id)['state']==='TRANSPORT_FAILED_BEFORE_DISPATCH',
 'old job must remain non-dispatchable');
ensure(!str_contains(serialize($opts),$command),
 'raw signed command bytes must not be persisted into options');
$retry=MAD4B_Original_Core_Recovery_V1::create($rest);
ensure($retry instanceof WP_Error&&
 str_contains($retry->get_error_code(),'recovery_already_reserved'),
 'duplicate recovery must fail closed');
echo "PASS true original 305 + 306 signed recovery, one new pending job, no raw command persistence or duplicate execution\n";
