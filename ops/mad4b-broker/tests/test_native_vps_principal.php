<?php
/** Isolated acceptance tests: no WordPress database, SSH, server key or live site. */
define('ABSPATH','/tmp/mad4b-native-test/');
$GLOBALS['kv']=[];
class WP_Error {
 private $code;
 public function __construct($code,$message='',$data=[]){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_REST_Response {
 private $payload;
 public function __construct($payload,$status=200){$this->payload=$payload;}
 public function get_data(){return $this->payload;}
}
function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['kv'])?$GLOBALS['kv'][$key]:$default;}
function add_option($key,$value,$deprecated='',$autoload=false){
 if(array_key_exists($key,$GLOBALS['kv']))return false;
 $GLOBALS['kv'][$key]=$value;return true;
}
function delete_option($key){unset($GLOBALS['kv'][$key]);return true;}
function rest_ensure_response($v){return new WP_REST_Response($v);}
function add_action($hook,$callback,$priority=10){}
class MAD4B_Central_Host_Scope_V1 {
 public static function inspect($company='tours-central'){
  return ['state'=>'CENTRAL_HOST_IDENTITY_VERIFIED','scope_kind'=>'central_native_host',
   'host_id'=>'srv2030908','site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
   'canonical_origin'=>'https://tours.dev.mad4b.com','environment'=>'staging'];
 }
}
class MAD4B_Central_VPS_Broker_V1 {
 const PUBKEY='mad4b_central_broker_signing_public_v1';
 public static $dispatched=0;
 public static function dispatch_native($id){
  self::$dispatched++;
  $r=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id);
  if(!is_array($r))throw new Exception('request not persisted');
  return new WP_REST_Response(['job_id'=>$id,'request'=>$r],202);
 }
}
class MAD4B_Central_SSH_Command_Intake_V1 {
 const CONTRACT='mad4b.central-ssh-command-intake.v1';
 const PREFIX='grant:';
 const TRANSPORT_PREFIX='transport:';
 const CLAIM_PREFIX='claim:';
 const REQUEST_PREFIX='request:';
}
require dirname(__DIR__).'/central-mu/323-mad4b-native-vps-principal.php';
function check($x,$why){if(!$x)throw new RuntimeException($why);}
$keypair=sodium_crypto_sign_keypair();
$GLOBALS['kv'][MAD4B_Central_VPS_Broker_V1::PUBKEY]=
 base64_encode(sodium_crypto_sign_publickey($keypair));
$secret=sodium_crypto_sign_secretkey($keypair);
$base=[
 'contract'=>MAD4B_Native_VPS_Principal_V1::CONTRACT,
 'action'=>'enroll','host_id'=>'srv2030908','company'=>'tours-central',
 'site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
 'canonical_origin'=>'https://tours.dev.mad4b.com',
 'environment'=>'staging','operation'=>'maintenance.shell',
 'host_key_fingerprint'=>'SHA256:'.str_repeat('A',43),
 'policy_sha256'=>hash('sha256','pinned-host-policy-test'),
 'command_sha256'=>str_repeat('0',64),'correlation_id'=>'enrollment',
 'issued'=>time(),'expires'=>time()+35,'nonce'=>bin2hex(random_bytes(16))
];
function sign_proof($source,$secret){
 $text=MAD4B_Native_VPS_Principal_V1::signed_text($source+['signature'=>'']);
 return $source+['signature'=>base64_encode(sodium_crypto_sign_detached($text,$secret))];
}
$enroll=sign_proof($base,$secret);
check(!is_wp_error(MAD4B_Native_VPS_Principal_V1::attest($enroll,'enroll')),'valid host signature');
$bad=$enroll;$bad['company']='other-company';
check(is_wp_error(MAD4B_Native_VPS_Principal_V1::attest($bad,'enroll')),'company tamper denied');
$bad=$enroll;$bad['site_uuid']='00000000-0000-4000-8000-000000000000';
check(is_wp_error(MAD4B_Native_VPS_Principal_V1::attest($bad,'enroll')),'site UUID tamper denied');
$bad=$enroll;$bad['environment']='production';
check(is_wp_error(MAD4B_Native_VPS_Principal_V1::attest($bad,'enroll')),'production denied');
$bad=$enroll;$bad['expires']=time()-1;
check(is_wp_error(MAD4B_Native_VPS_Principal_V1::attest($bad,'enroll')),'expired proof denied');
$r=MAD4B_Native_VPS_Principal_V1::enroll($enroll);
check(is_array($r)&&$r['state']==='STANDING_TRANSPORT_AND_GRANT_REGISTERED','enroll exact standing grant');
check(is_wp_error(MAD4B_Native_VPS_Principal_V1::enroll($enroll)),'nonce replay denied');
$slot=MAD4B_Native_VPS_Principal_V1::existing();
check(is_array($slot)&&strlen($slot['grant_id'])===32,'original grant persisted');
$repeat=$base;$repeat['nonce']=bin2hex(random_bytes(16));$again=sign_proof($repeat,$secret);
$reuse=MAD4B_Native_VPS_Principal_V1::enroll($again);
check(is_array($reuse)&&$reuse['state']==='STANDING_ENROLLMENT_EXISTS','standing grant reusable');
check($reuse['grant_issued']===false,'no new grant on reuse');
$command='printf MAD4B_NATIVE_EXACT_READBACK';
$submit=$base;$submit['action']='submit';$submit['nonce']=bin2hex(random_bytes(16));
$submit['command_sha256']=hash('sha256',$command);
$submit['correlation_id']='test-native-20261101';
$submit=sign_proof($submit,$secret);
check(is_wp_error(MAD4B_Native_VPS_Principal_V1::submit($submit,$command.'x')),
 'command digest mismatch denied');
check(MAD4B_Central_VPS_Broker_V1::$dispatched===0,'no dispatch from wrong bytes');
$queued=MAD4B_Native_VPS_Principal_V1::submit($submit,$command);
check($queued instanceof WP_REST_Response,'signed command dispatch');
$data=$queued->get_data();$stored=$data['request'];
check($stored['principal_type']==='vps_native'&&$stored['principal_id']==='vps-native:srv2030908',
 'separate native principal');
check($stored['command_sha256']===hash('sha256',$command)
 && $stored['command_bytes_persisted']===false,'only command digest persisted');
check($stored['target_scope']==='host'&&$stored['environment']==='staging','exact staging scope');
check(is_wp_error(MAD4B_Native_VPS_Principal_V1::submit($submit,$command)),'submit replay denied');
check(MAD4B_Central_VPS_Broker_V1::$dispatched===1,'exactly one broker dispatch');
$grant=get_option('grant:'.$slot['grant_id']);
$transport=get_option('transport:'.$slot['transport_id']);
$job=$stored+['target_scope'=>'host'];
check(MAD4B_Native_VPS_Principal_V1::valid_job_chain($job,$grant,$transport,$stored),
 'original ledger verifies');
$alter=$job;$alter['environment']='production';
check(!MAD4B_Native_VPS_Principal_V1::valid_job_chain($alter,$grant,$transport,$stored),
 'changed environment denied');
$alter=$grant;$alter['state']='REVOKED';
$GLOBALS['kv']['grant:'.$slot['grant_id']]=$alter;
check(!MAD4B_Native_VPS_Principal_V1::valid_job_chain($job,$alter,$transport,$stored),
 'revocation enforced on every job');
echo "mad4b.native-vps-principal.v1: PASS signed, scope, nonce, grant, dispatch, readback and revocation\n";
