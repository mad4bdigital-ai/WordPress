<?php
/** Isolated 305 sealed payload to original 306 dispatch regression. */
declare(strict_types=1);
define('ABSPATH','/');
$GLOBALS['opts']=[];$GLOBALS['accepted']=0;$GLOBALS['dispatched']=0;
function get_current_user_id(){return 7;}
function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;}
function add_option($k,$v,$depr='',$autoload=false){
 if(array_key_exists($k,$GLOBALS['opts']))return false;
 $GLOBALS['opts'][$k]=$v;return true;
}
function update_option($k,$v,$autoload=false){$GLOBALS['opts'][$k]=$v;return true;}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error {
 public function __construct(public $code,public $message,public $data=[]){}
 public function get_error_code(){return $this->code;}
}
class WP_REST_Response {
 public function __construct(public $data,public $status=200){}
}
class WP_REST_Request implements ArrayAccess {
 private $params=[],$headers=[],$body='';
 public function __construct($method='POST',$route='/'){}
 public function set_param($k,$v){$this->params[$k]=$v;}
 public function set_header($k,$v){$this->headers[strtolower($k)]=$v;}
 public function get_header($k){return $this->headers[strtolower($k)]??'';}
 public function set_body($v){$this->body=$v;}
 public function get_json_params(){return json_decode($this->body,true);}
 public function offsetExists(mixed $k):bool{return isset($this->params[$k]);}
 public function offsetGet(mixed $k):mixed{return $this->params[$k]??null;}
 public function offsetSet(mixed $k,mixed $v):void{$this->params[$k]=$v;}
 public function offsetUnset(mixed $k):void{unset($this->params[$k]);}
}
class MAD4B_Central_VPS_Broker_V1 {
 const PUBKEY='test-host-public-key';
 public static function dispatch($request){
  $body=$request->get_json_params();
  $id=$body['request_id']??'';
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))return new WP_Error('invalid','invalid');
  $GLOBALS['dispatched']++;
  $r=$GLOBALS['opts']['new-request'];
  return new WP_REST_Response([
   'job_id'=>$id,'company'=>$r['company'],'command_sha256'=>$r['command_sha256'],
   'site_uuid'=>'staging-site','environment'=>'staging',
   'state'=>'QUEUED_FOR_VPS_BROKER_CLAIM','executed'=>false
  ],202);
 }
}
class MAD4B_Central_SSH_Command_Intake_V1 {
 const PREFIX='grant-';
 const TRANSPORT_PREFIX='transport-';
 public static function permitted($request){return $request instanceof WP_REST_Request
  && $request->get_header('X-WP-Nonce')==='valid-operator-nonce';}
 public static function accept_command($request){
  if(!self::permitted($request))return new WP_Error('unauthorized','unauthorized');
  $body=$request->get_json_params();
  $GLOBALS['accepted']++;
  $command=$body['command']??null;
  if(!is_string($command))return new WP_Error('wrong_command','wrong_command');
  $receipt=['request_id'=>str_repeat('f',32),
   'command_sha256'=>hash('sha256',$command),'company'=>'egypt-tour-gates'];
  $GLOBALS['opts']['new-request']=$receipt;
  return new WP_REST_Response($receipt,202);
 }
}
require __DIR__.'/original-broker-source/305-mad4b-original-sealed-rebind.php';
function must($ok,$name){if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);}}
function refused($out,$piece){return is_wp_error($out)&&str_contains($out->get_error_code(),$piece);}
$command='printf original-verified-diagnostic';
$id=str_repeat('a',32);
$original=['job_id'=>$id,'company'=>'egypt-tour-gates','site_uuid'=>'staging-site',
 'canonical_origin'=>'https://tours.dev.mad4b.com','environment'=>'staging',
 'target_host_id'=>'srv2030908','grant_id'=>str_repeat('b',32),
 'transport_id'=>str_repeat('c',32),'target_ssh_principal'=>'www-data',
 'central_principal_id'=>7,'operation'=>'maintenance.shell',
 'target_scope'=>'wordpress','command_format'=>'maintenance.shell.text.v1',
 'command_sha256'=>hash('sha256',$command)];
$pair=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($pair);
$GLOBALS['opts'][MAD4B_Central_VPS_Broker_V1::PUBKEY]=base64_encode(
 sodium_crypto_sign_publickey($pair));
$GLOBALS['opts'][MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$original['grant_id']]=[
 'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED','operation'=>'maintenance.shell',
 'company'=>$original['company'],'central_principal_id'=>7];
$GLOBALS['opts'][MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$original['transport_id']]=[
 'state'=>'PENDING_INDEPENDENT_SSH_VERIFICATION',
 'company'=>$original['company'],'site_uuid'=>$original['site_uuid'],
 'host_id'=>$original['target_host_id'],'host_principal'=>$original['target_ssh_principal']];
$envelope=['contract'=>MAD4B_Original_Sealed_Command_Rebind_V1::CONTRACT,
 'original_job_id'=>$id,'provider'=>'vps_ssh','command_sha256'=>$original['command_sha256'],
 'nonce'=>str_repeat('d',32),'issued'=>time(),'expires'=>time()+40,'command'=>$command];
function signEnvelope(array $old,array $envelope,string $secret):array{
 $message=implode("\n",[
 MAD4B_Original_Sealed_Command_Rebind_V1::CONTRACT,'original-core-rebind',
 $old['job_id'],$old['company'],$old['site_uuid'],$old['canonical_origin'],
 $old['environment'],$old['target_host_id'],$old['grant_id'],$old['transport_id'],
 (string)$old['central_principal_id'],$old['operation'],$envelope['provider'],
 $envelope['command_sha256'],$envelope['nonce'],
 (string)$envelope['issued'],(string)$envelope['expires']]);
 $envelope['signature']=base64_encode(sodium_crypto_sign_detached($message,$secret));
 return $envelope;
}
$envelope=signEnvelope($original,$envelope,$secret);
$rest=new WP_REST_Request();
$rest->set_param('company','egypt-tour-gates');
$rest->set_header('X-WP-Nonce','valid-operator-nonce');
$run=fn($sealed)=>MAD4B_Original_Sealed_Command_Rebind_V1::execute($id,
 ['authenticated_request'=>$rest,'sealed_payload'=>$sealed],
 ['original_job'=>$original]);
$bad=$envelope;$bad['command']='rm -rf';
must(refused($run($bad),'signed_payload_or_digest_invalid'),'tampered command refused');
must($GLOBALS['accepted']===0,'tamper cannot start original intake');
$bad=$envelope;$bad['provider']='coolify_service';
must(refused($run($bad),'signed_payload_or_digest_invalid'),'unsigned provider switch refused');
$cool=signEnvelope($original,$bad,$secret);
must(refused($run($cool),'alternate_provider_not_enrolled'),'nonregistered provider refused');
must($GLOBALS['accepted']===0,'no admission before original provider bound');
$good=$run($envelope);
must(($good['state']??'')==='ORIGINAL_CORE_RECOVERY_JOB_COMMITTED','recovery job committed');
must(($good['executed']??null)===false,'new job is queued not executed');
must($GLOBALS['accepted']===1&&$GLOBALS['dispatched']===1,
 'new request must go through original intake and dispatch');
must(refused($run($envelope),'recovery_already_reserved'),'single-use recovery fence');
must($GLOBALS['dispatched']===1,'duplicate command never dispatched');
echo "PASS original sealed payload recovery through 305 intake and 306 dispatch, signed digest, separate provider registry, one-use fence\n";
