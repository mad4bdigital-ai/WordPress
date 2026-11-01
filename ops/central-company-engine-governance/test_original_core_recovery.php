<?php
/** Isolated signed 306 recovery journal tests. Never loads live WordPress. */
declare(strict_types=1);
define('ABSPATH','/');
$GLOBALS['fixture_options']=[];
function add_action(...$args) {}
function get_current_user_id(){return 7;}
function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['fixture_options'])?$GLOBALS['fixture_options'][$key]:$default;}
function add_option($key,$value,$deprecated='',$autoload=false){
 if(array_key_exists($key,$GLOBALS['fixture_options']))return false;
 $GLOBALS['fixture_options'][$key]=$value;return true;
}
function update_option($key,$value,$autoload=false){
 if(array_key_exists($key,$GLOBALS['fixture_options'])&&$GLOBALS['fixture_options'][$key]===$value)return false;
 $GLOBALS['fixture_options'][$key]=$value;return true;
}
function is_wp_error($x){return $x instanceof WP_Error;}
function rest_ensure_response($x){return new WP_REST_Response($x);}
class WP_Error {
 public function __construct(public $code,public $message,public $data=[]){}
 public function get_error_code(){return $this->code;}
}
class WP_REST_Response {public function __construct(public $data,public $status=200){}}
class WP_REST_Request implements ArrayAccess {
 public function __construct(private $fields,private $json) {}
 public function offsetExists(mixed $k):bool{return isset($this->fields[$k]);}
 public function offsetGet(mixed $k):mixed{return $this->fields[$k]??null;}
 public function offsetSet(mixed $k,mixed $v):void{$this->fields[$k]=$v;}
 public function offsetUnset(mixed $k):void{unset($this->fields[$k]);}
 public function get_json_params(){return $this->json;}
}
class MAD4B_Central_SSH_Command_Intake_V1{
 const PREFIX='test-grant-';
 const REQUEST_PREFIX='test-request-';
 const TRANSPORT_PREFIX='test-transport-';
 public static function permitted($r){return true;} // isolated fixture, not live permission.
}
class MAD4B_Central_Dual_Lane_Registry_V1{public static function central(){return true;}}
class MAD4B_Manager_Auto_Write_V1{
 public static $state='REGISTERED';
 public static function company_target($company){return [
  'state'=>self::$state,'site_uuid'=>'site-staging',
  'canonical_origin'=>'https://tours.dev.mad4b.com','environment'=>'staging'];}
}
require __DIR__.'/original-broker-source/306-mad4b-central-vps-broker.php';
function must($ok,$label){if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}}
function err($x,$part){return $x instanceof WP_Error&&str_contains($x->get_error_code(),$part);}
function evidenceMessage(array $job,array $proof):string {
 return implode("\n",[
 'mad4b.original-core-pre-dispatch-recovery.v1','before-dispatch-failure',
 $job['job_id'],$job['company'],$job['site_uuid'],$job['canonical_origin'],
 $job['environment'],$job['target_host_id'],$job['grant_id'],$job['transport_id'],
 $job['command_sha256'],(string)$job['central_principal_id'],
 $proof['provider'],$proof['reason'],$proof['nonce'],
 (string)$proof['issued'],(string)$proof['expires'],
 'dispatch_started=false','claim_committed=false','effects_possible=false']);
}
function fixture():array{
 $id=str_repeat('a',32);$grant=str_repeat('b',32);$transport=str_repeat('c',32);
 $job=[
  'contract'=>MAD4B_Central_VPS_Broker_V1::CONTRACT,'job_id'=>$id,
  'company'=>'egypt-tour-gates','operation'=>'maintenance.shell',
  'site_uuid'=>'site-staging','canonical_origin'=>'https://tours.dev.mad4b.com',
  'environment'=>'staging','broker_host_id'=>'srv2030908','target_host_id'=>'srv2030908',
  'target_ssh_principal'=>'www-data','grant_id'=>$grant,
  'grant_mode'=>'persistent','transport_id'=>$transport,
  'central_principal_id'=>7,'correlation_id'=>'signed-test',
  'command_sha256'=>str_repeat('d',64),'target_scope'=>'wordpress',
  'command_format'=>'maintenance.shell.text.v1',
  'expires_epoch'=>time()+300,'state'=>'QUEUED_FOR_VPS_BROKER_CLAIM',
  'executed'=>false];
 $request=$job;
 $request['request_id']=$id;
 $request['status']='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY';
 $request['execution_performed']=false;
 $grantRecord=['state'=>'CENTRAL_OPERATION_SCOPE_GRANTED','company'=>$job['company'],
  'operation'=>$job['operation'],'central_principal_id'=>7,
  'expires_epoch'=>null];
 $transportRecord=['state'=>'PENDING_INDEPENDENT_SSH_VERIFICATION',
  'company'=>$job['company'],'site_uuid'=>$job['site_uuid'],
  'canonical_origin'=>$job['canonical_origin'],'host_id'=>$job['target_host_id']];
 $pair=sodium_crypto_sign_keypair();
 $secret=sodium_crypto_sign_secretkey($pair);
 $options=&$GLOBALS['fixture_options'];$options=[];
 $options[MAD4B_Central_VPS_Broker_V1::JOB.$id]=$job;
 $options[MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id]=$request;
 $options[MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$grant]=$grantRecord;
 $options[MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$transport]=$transportRecord;
 $options[MAD4B_Central_VPS_Broker_V1::PUBKEY]=base64_encode(sodium_crypto_sign_publickey($pair));
 $proof=['contract'=>MAD4B_Original_Core_Recovery_V1::CONTRACT,'job_id'=>$id,
  'provider'=>'container_exec','reason'=>'TRANSPORT_UNREACHABLE',
  'nonce'=>str_repeat('e',32),'issued'=>time(),'expires'=>time()+40,
  'dispatch_started'=>false,'claim_committed'=>false,'effects_possible'=>false];
 $proof['signature']=base64_encode(sodium_crypto_sign_detached(evidenceMessage($job,$proof),$secret));
 return [$job,$proof,$id,$grant,$transport];
}
[$job,$proof,$id,$grant,$transport]=fixture();
$forged=$proof;$forged['effects_possible']=true;
must(err(MAD4B_Original_Core_Recovery_V1::recordOriginalPreDispatchFailure($id,$forged),
 'signed_host_evidence_invalid'),'forged claims must deny');
must(get_option(MAD4B_Central_VPS_Broker_V1::ROUTE_GATE.$id,false)===false,
 'invalid signature must not reserve route');
$wrong=$proof;$wrong['reason']='OTHER';
must(err(MAD4B_Original_Core_Recovery_V1::recordOriginalPreDispatchFailure($id,$wrong),
 'signed_host_evidence_invalid'),'wrong failure reason denied');
[$job,$proof,$id,$grant,$transport]=fixture();
$GLOBALS['fixture_options'][MAD4B_Central_VPS_Broker_V1::ROUTE_GATE.$id]=['decision'=>'CLAIM'];
must(err(MAD4B_Original_Core_Recovery_V1::recordOriginalPreDispatchFailure($id,$proof),
 'job_route_already_reserved'),'claimed route cannot recover');
[$job,$proof,$id,$grant,$transport]=fixture();
$accepted=MAD4B_Original_Core_Recovery_V1::recordOriginalPreDispatchFailure($id,$proof);
must(($accepted['state']??'')==='RECORDED_PRE_DISPATCH_FAILURE','signed failure persisted');
must(get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id)['state']==='TRANSPORT_FAILED_BEFORE_DISPATCH',
 'old job must not be claimable again');
$record=MAD4B_Original_Core_Recovery_V1::readOriginalRecoveryEvidence($id);
must(($record['new_core_job_required']??false)===true,'signed journal must read back');
must(($record['claim_committed']??null)===false,'no claim in journal');
must(err(MAD4B_Original_Core_Recovery_V1::createOriginalRecoveryJobOnce(
 ['original_job_id'=>$id,'provider'=>'vps_ssh']),'original_sealed_payload_rebind_provider_required'),
 'no replacement without original signed command rebind');
must(err(MAD4B_Original_Core_Recovery_V1::recordOriginalPreDispatchFailure($id,$proof),
 'original_job_not_pre_dispatch'),'duplicate recovery cannot re-enter');
$GLOBALS['fixture_options'][MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$grant]['state']='REVOKED';
must(err(MAD4B_Original_Core_Recovery_V1::createOriginalRecoveryJobOnce(
 ['original_job_id'=>$id,'provider'=>'vps_ssh']),'original_authority_revoked_or_drifted'),
 'grant revocation must block recovery');
[$job,$proof,$id,$grant,$transport]=fixture();
MAD4B_Manager_Auto_Write_V1::$state='REVOKED';
must(err(MAD4B_Original_Core_Recovery_V1::recordOriginalPreDispatchFailure($id,$proof),
 'site_authority_drift'),'site drift must reject');
MAD4B_Manager_Auto_Write_V1::$state='REGISTERED';
[$job,$proof,$id,$grant,$transport]=fixture();
$GLOBALS['fixture_options'][MAD4B_Central_VPS_Broker_V1::CLAIM.$id]=['state'=>'CLAIMED'];
must(err(MAD4B_Original_Core_Recovery_V1::recordOriginalPreDispatchFailure($id,$proof),
 'already_claimed_or_uncertain'),'already-claimed job cannot recover');
[$job,$proof,$id,$grant,$transport]=fixture();
$GLOBALS['fixture_options'][MAD4B_Central_VPS_Broker_V1::JOB.$id]['environment']='production';
must(err(MAD4B_Original_Core_Recovery_V1::recordOriginalPreDispatchFailure($id,$proof),
 'original_job_not_pre_dispatch'),'production is outside staging recovery');
echo "PASS original Core 306 signed recovery, route fencing, site/grant and sealed-payload safety\n";
