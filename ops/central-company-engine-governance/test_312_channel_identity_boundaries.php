<?php
/** Hermetic 312 channel identity test: no live WP, SSH or grant issuance. */
declare(strict_types=1);
define('ABSPATH','/');
define('WP_CLI',true);
$GLOBALS['items']=[];
class WP_CLI {
 public static array $output=[];
 public static function add_command(...$unused):void {}
 public static function error($message):void {throw new RuntimeException((string)$message);}
 public static function line($message):void {self::$output[]=(string)$message;}
}
class MAD4B_Central_Dual_Lane_Registry_V1 {
 public static function central():bool{return true;}
}
class MAD4B_Central_SSH_Command_Intake_V1 {
 const PREFIX='test_grant_';
 const TRANSPORT_PREFIX='test_transport_';
 const REQUEST_PREFIX='test_request_';
}
class MAD4B_Central_VPS_Broker_V1 {
 const PUBKEY='test_pubkey';
 const JOB='test_job_';
 const CLAIM='test_claim_';
}
class MAD4B_Manager_Auto_Write_V1 {}
class MAD4B_Company_Operation_Admission_V1 {}
class MAD4B_Company_Portfolio_Manager_V1 {}
function get_current_user_id():int{return 0;}
function current_user_can($cap):bool{return false;}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function get_option($k,$default=false){return $GLOBALS['items'][$k]??$default;}
function add_option($k,$value,$deprecated='',$autoload=false) {
 if(array_key_exists($k,$GLOBALS['items']))return false;
 $GLOBALS['items'][$k]=$value;return true;
}
function check($ok,string $message):void {
 if(!$ok)throw new RuntimeException('ASSERTION_FAILED:'.$message);
}
function blocked(callable $fn,string $code):void {
 try{$fn();throw new RuntimeException('UNEXPECTED_ALLOW:'.$code);}
 catch(RuntimeException $e){check($e->getMessage()===$code,'wrong rejection '.$e->getMessage());}
}
require __DIR__.'/original-broker-source/312-mad4b-broker-exact-ssh-authority.php';
$class='MAD4B_Broker_Exact_SSH_Authority_V1';
$class::cli(['status'],[]);
$status=json_decode(WP_CLI::$output[0]??'',true);
check(($status['contract']??'')===$class::CONTRACT,'contract');
check(($status['verifier_registered']??false)===true,'status registered');
check(($status['shell_executed']??true)===false,'status no shell');
check(($status['remote_execution_authority_automatically_granted']??true)===false,'no automatic grant');
blocked(fn()=>$class::cli(['claimed'],[]),'CENTRAL_HOST_AUTHORITY_REQUIRED');
blocked(fn()=>$class::cli(['verify'],[]),'BROKER_SIGNED_PREFLIGHT_REQUIRED');
check(count($GLOBALS['items'])===0,'unauthenticated verify leaves no nonce');
$key=sodium_crypto_sign_keypair();
$GLOBALS['items'][MAD4B_Central_VPS_Broker_V1::PUBKEY]=base64_encode(sodium_crypto_sign_publickey($key));
$now=time();
$payload=[
 'job'=>str_repeat('a',32),'target'=>'hosting-2',
 'command_sha256'=>str_repeat('b',64),
 'nonce'=>str_repeat('c',32),'issued'=>$now,'expires'=>$now+40
];
$sign=implode("\n",[$class::CONTRACT,'verify','srv2030908',
 $payload['job'],$payload['target'],$payload['command_sha256'],
 $payload['nonce'],(string)$now,(string)($now+40)]);
$payload['signature']=base64_encode(sodium_crypto_sign_detached(
 $sign,sodium_crypto_sign_secretkey($key)
));
$class::cli(['verify'],$payload);
$answer=json_decode(end(WP_CLI::$output),true);
check(($answer['contract']??'')===$class::CONTRACT,'signed contract');
check(($answer['eligible']??true)===false,'no grant or claim cannot authorize execution');
check(($answer['reason']??'')==='JOB_OR_CLAIM_MISSING','original 305/306 is mandatory');
check(count($GLOBALS['items'])===2,'signed nonce recorded once only');
blocked(fn()=>$class::cli(['verify'],$payload),'BROKER_PREFLIGHT_NONCE_REPLAY');
check(count($GLOBALS['items'])===2,'replay must not change options');
echo "PASS 312 independent broker-signature identity, read-only status, privileged claim list, nonce replay and no job grant\n";
