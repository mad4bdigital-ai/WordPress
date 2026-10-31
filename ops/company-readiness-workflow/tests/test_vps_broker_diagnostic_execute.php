<?php
/** Offline fixed Broker execution safety and signed readback tests. */
declare(strict_types=1);
define('ABSPATH',__DIR__);
$GLOBALS['store']=[];
function add_action(...$a){}
function get_option($key,$default=false){return $GLOBALS['store'][$key]??$default;}
function add_option($key,$value,$dep='',$autoload=false){if(isset($GLOBALS['store'][$key]))return false;$GLOBALS['store'][$key]=$value;return true;}
function update_option($key,$value,$autoload=false){$GLOBALS['store'][$key]=$value;return true;}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error{public function __construct(private string $code,$message=''){}public function get_error_code(){return $this->code;}}
class MAD4B_Central_Dual_Lane_Registry_V1{static function central(){return true;}}
class MAD4B_Central_VPS_Broker_V1{const JOB='mad4b_central_broker_job_v1_';const CLAIM='mad4b_central_broker_claim_v1_';const PUBKEY='mad4b_central_broker_signing_public_v1';}
class MAD4B_Central_SSH_Command_Intake_V1{const PREFIX='mad4b_central_ssh_grant_v1_';const TRANSPORT_PREFIX='mad4b_central_ssh_transport_v1_';}
class MAD4B_Company_Operation_Admission_V1{static function manager_id(){return 'manager1';}}
class MAD4B_Company_Portfolio_Manager_V1{static function has_delegation($m,$c,$right,$uid){return $GLOBALS['delegated']??true;}}
require __DIR__.'/../staging-mu/310-mad4b-vps-broker-diagnostic-execute.php';
function check($v,$why){if(!$v)throw new RuntimeException($why);}
$id=bin2hex(random_bytes(16));$gid=bin2hex(random_bytes(16));
$job=['job_id'=>$id,'company'=>'wayfatours','site_uuid'=>MAD4B_VPS_Broker_Diagnostic_Execute_V1::SITE,
 'canonical_origin'=>'https://wayfatours.mad4b.com','environment'=>'production',
 'target_host_id'=>'srv2030908','target_ssh_principal'=>'root','operation'=>'maintenance.shell',
 'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY','executed'=>false,
 'command_sha256'=>hash('sha256',MAD4B_VPS_Broker_Diagnostic_Execute_V1::COMMAND),
 'expires_epoch'=>time()+360,'central_principal_id'=>1,'grant_id'=>$gid];
$claim=['job_id'=>$id,'host_id'=>'srv2030908','state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY','claimed_epoch'=>time()-2];
$grant=['state'=>'CENTRAL_OPERATION_SCOPE_GRANTED','company'=>'wayfatours',
 'site_uuid'=>MAD4B_VPS_Broker_Diagnostic_Execute_V1::SITE,'operation'=>'maintenance.shell',
 'central_principal_id'=>1,'grant_mode'=>'one_time','expires_epoch'=>time()+360];
$bp=MAD4B_Central_VPS_Broker_V1::JOB.$id;
$cp=MAD4B_Central_VPS_Broker_V1::CLAIM.$id;
$gp=MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$gid;
$GLOBALS['store'][$bp]=$job;$GLOBALS['store'][$cp]=$claim;$GLOBALS['store'][$gp]=$grant;
check(!is_wp_error(MAD4B_VPS_Broker_Diagnostic_Execute_V1::inspect($id)),'valid claimed job eligible');
$GLOBALS['store'][$gp]['expires_epoch']=time()-1;
check(is_wp_error(MAD4B_VPS_Broker_Diagnostic_Execute_V1::inspect($id)),'expired grant denied');
$GLOBALS['store'][$gp]=$grant;$GLOBALS['store'][$bp]['command_sha256']=str_repeat('a',64);
check(is_wp_error(MAD4B_VPS_Broker_Diagnostic_Execute_V1::inspect($id)),'arbitrary command denied');
$GLOBALS['store'][$bp]=$job;$GLOBALS['delegated']=false;
check(is_wp_error(MAD4B_VPS_Broker_Diagnostic_Execute_V1::inspect($id)),'revoked company delegation denied');
$GLOBALS['delegated']=true;
$keys=sodium_crypto_sign_keypair();
$GLOBALS['store'][MAD4B_Central_VPS_Broker_V1::PUBKEY]=base64_encode(sodium_crypto_sign_publickey($keys));
$nonce=bin2hex(random_bytes(16));$iat=time();$exp=$iat+35;
$sha=hash('sha256',MAD4B_VPS_Broker_Diagnostic_Execute_V1::OUTPUT);
$values=[MAD4B_VPS_Broker_Diagnostic_Execute_V1::CONTRACT,'receipt',$id,
 MAD4B_VPS_Broker_Diagnostic_Execute_V1::SITE,'production','srv2030908',
 $nonce,(string)$iat,(string)$exp,$sha,$sha];
$args=['job'=>$id,'nonce'=>$nonce,'issued'=>$iat,'expires'=>$exp,
 'output_sha256'=>$sha,'readback_sha256'=>$sha,
 'signature'=>base64_encode(sodium_crypto_sign_detached(implode("\n",$values),
 sodium_crypto_sign_secretkey($keys)))];
$reflection=new ReflectionMethod(MAD4B_VPS_Broker_Diagnostic_Execute_V1::class,'receipt');
$bad=$args;$bad['output_sha256']=str_repeat('b',64);
check(is_wp_error($reflection->invoke(null,$bad)),'tampered output denied');
$receipt=$reflection->invoke(null,$args);
check(is_array($receipt) && $receipt['state']==='DIAGNOSTIC_EXECUTION_READBACK_VERIFIED',
 'exact signed diagnostic receipt');
check($GLOBALS['store'][$bp]['state']==='DIAGNOSTIC_EXECUTION_READBACK_VERIFIED',
 'original job transitioned only after signed receipt');
check(is_wp_error($reflection->invoke(null,$args)),'replay receipt denied');
echo "PASS: fresh exact claims, expired/revoked/shell substitution fail-closed, signed one-time readback receipt\n";
