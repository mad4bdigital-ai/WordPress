<?php
/** Isolated original 324 evidence acceptance tests; no database and no host shell. */
define('ABSPATH','/tmp/mad4b-test/');
$GLOBALS['opts']=[];
class WP_CLI {public static function add_command($k,$v){}}
function get_option($k,$v=false){return array_key_exists($k,$GLOBALS['opts'])?$GLOBALS['opts'][$k]:$v;}
function add_option($k,$v,$x='',$y=false){if(array_key_exists($k,$GLOBALS['opts']))return false;$GLOBALS['opts'][$k]=$v;return true;}
function update_option($k,$v,$a=false){if(!array_key_exists($k,$GLOBALS['opts']))return false;$GLOBALS['opts'][$k]=$v;return true;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
class MAD4B_Central_Dual_Lane_Registry_V1 {public static function central(){return true;}}
class MAD4B_Central_VPS_Broker_V1 {
 const JOB='job:';const CLAIM='broker_claim:';const PUBKEY='broker_pub:';
}
class MAD4B_Central_SSH_Command_Intake_V1 {
 const PREFIX='grant:';const TRANSPORT_PREFIX='transport:';
 const REQUEST_PREFIX='request:';const CLAIM_PREFIX='consumed:';
}
class MAD4B_Broker_General_Shell_Receipt_V1 {const PREFIX='receipt:';}
class MAD4B_Native_VPS_Principal_V1 {
 public static function valid_job_chain($job,$grant,$transport,$req) {
  return ($job['principal_type']??'')==='vps_native'
   && ($grant['state']??'')==='CENTRAL_OPERATION_SCOPE_GRANTED'
   && ($transport['state']??'')==='PENDING_INDEPENDENT_SSH_VERIFICATION'
   && ($req['principal_type']??'')==='vps_native';
 }
}
require dirname(__DIR__).'/central-mu/324-mad4b-broker-late-native-reconciliation.php';
function must($yes,$name){if(!$yes)throw new RuntimeException($name);}
$now=time();$keypair=sodium_crypto_sign_keypair();
$GLOBALS['opts'][MAD4B_Central_VPS_Broker_V1::PUBKEY]=base64_encode(sodium_crypto_sign_publickey($keypair));
$secret=sodium_crypto_sign_secretkey($keypair);
$id=str_repeat('a',32);$grantid=str_repeat('b',32);$transportid=str_repeat('c',32);
$correlation='central-native-test-20261101';
$sha=hash('sha256','printf TEST');
$site='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
$base=[
 'job_id'=>$id,'grant_id'=>$grantid,'transport_id'=>$transportid,
 'company'=>'tours-central','site_uuid'=>$site,'environment'=>'staging',
 'principal_type'=>'vps_native','principal_id'=>'vps-native:srv2030908',
 'correlation_id'=>$correlation,'command_sha256'=>$sha,
 'host_policy_sha256'=>hash('sha256','host-policy-test'),
 'canonical_origin'=>'https://tours.dev.mad4b.com'];
$job=$base+[
 'operation'=>'maintenance.shell','broker_host_id'=>'srv2030908',
 'target_host_id'=>'srv2030908','target_scope'=>'host',
 'grant_mode'=>'persistent','central_principal_id'=>0,
 'created_epoch'=>$now-350,'expires_epoch'=>$now-100,
 'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY','executed'=>false];
$req=$base+[
 'request_id'=>$id,'target_host_id'=>'srv2030908','target_scope'=>'host',
 'central_principal_id'=>0,'status'=>'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'];
$claim=['job_id'=>$id,'host_id'=>'srv2030908',
 'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY','claimed_epoch'=>$now-290];
$grant=$base+['state'=>'CENTRAL_OPERATION_SCOPE_GRANTED','grant_mode'=>'persistent','operation'=>'maintenance.shell'];
$transport=$base+['state'=>'PENDING_INDEPENDENT_SSH_VERIFICATION'];
$GLOBALS['opts']['job:'.$id]=$job;
$GLOBALS['opts']['request:'.$id]=$req;
$GLOBALS['opts']['grant:'.$grantid]=$grant;
$GLOBALS['opts']['transport:'.$transportid]=$transport;
$GLOBALS['opts']['broker_claim:'.$id]=$claim;
$GLOBALS['opts']['consumed:'.$grantid.'_'.hash('sha256',$correlation)]=$id;
$proof=[
 'contract'=>MAD4B_Broker_Late_Native_Reconciliation_V1::CONTRACT,
 'host_id'=>'srv2030908','job_id'=>$id,'grant_id'=>$grantid,'transport_id'=>$transportid,
 'company'=>'tours-central','site_uuid'=>$site,'origin'=>'https://tours.dev.mad4b.com',
 'environment'=>'staging','principal_type'=>'vps_native','principal_id'=>'vps-native:srv2030908',
 'correlation_id'=>$correlation,'command_sha256'=>$sha,
 'host_policy_sha256'=>$base['host_policy_sha256'],
 'native_receipt_sha256'=>hash('sha256','exact native bytes'),
 'readback_sha256'=>hash('sha256','verified output'),'expected_readback_sha256'=>hash('sha256','verified output'),
 'intent'=>'diagnostic','native_state'=>'VERIFIED','started_epoch'=>$now-220,
 'finished_epoch'=>$now-218,'command_exit_code'=>0,'readback_exit_code'=>0,
 'issued'=>$now,'expires'=>$now+60,'nonce'=>bin2hex(random_bytes(16))
];
function signed($p,$secret){
 $p['signature']='';
 $s=MAD4B_Broker_Late_Native_Reconciliation_V1::message($p);
 must(is_string($s),'shape');
 $p['signature']=base64_encode(sodium_crypto_sign_detached($s,$secret));
 return $p;
}
$p=signed($proof,$secret);
$bad=$p;$bad['origin']='https://evil.invalid';
must(MAD4B_Broker_Late_Native_Reconciliation_V1::reconcile($bad)['verified']===false,'tampering denied');
$bad=$proof;$bad['finished_epoch']=$now-30;
must(MAD4B_Broker_Late_Native_Reconciliation_V1::reconcile(signed($bad,$secret))['verified']===false,'execution after expiry denied');
$bad=$proof;$bad['native_state']='FAILED';
must(MAD4B_Broker_Late_Native_Reconciliation_V1::reconcile(signed($bad,$secret))['verified']===false,'bad native state denied');
$bad=$proof;$bad['principal_type']='wordpress_owner';
must(MAD4B_Broker_Late_Native_Reconciliation_V1::reconcile(signed($bad,$secret))['verified']===false,'wrong principal denied');
$bad=$proof;$bad['started_epoch']=$now-400;
must(MAD4B_Broker_Late_Native_Reconciliation_V1::reconcile(signed($bad,$secret))['verified']===false,'preclaim denied');
$bad=$proof;$bad['nonce']=bin2hex(random_bytes(16));$bad['readback_sha256']=str_repeat('f',64);
must(MAD4B_Broker_Late_Native_Reconciliation_V1::reconcile(signed($bad,$secret))['verified']===false,'readback mismatch denied');
$bad=$proof;$bad['nonce']=bin2hex(random_bytes(16));$bad['issued']=$now-130;
must(MAD4B_Broker_Late_Native_Reconciliation_V1::reconcile(signed($bad,$secret))['verified']===false,'stale signature denied');
$changed=$GLOBALS['opts']['grant:'.$grantid];$GLOBALS['opts']['grant:'.$grantid]['state']='REVOKED';
must(MAD4B_Broker_Late_Native_Reconciliation_V1::reconcile($p)['verified']===false,'revoked grant denied');
$GLOBALS['opts']['grant:'.$grantid]=$changed;
$ok=MAD4B_Broker_Late_Native_Reconciliation_V1::reconcile($p);
must($ok['verified']===true && $ok['central_completion_recorded']===true,'signed late receipt accepted');
must($GLOBALS['opts']['job:'.$id]['state']==='GENERAL_SHELL_HISTORICAL_EXECUTION_RECONCILED','job historical state');
must($GLOBALS['opts']['job:'.$id]['historical_reconciliation']===true,'historical marker');
must($GLOBALS['opts']['job:'.$id]['automatic_execution_claimed']===false,'not falsely automatic');
must(($GLOBALS['opts']['receipt:'.$id]['contract']??null)===MAD4B_Broker_Late_Native_Reconciliation_V1::CONTRACT,'receipt in original 314 registry');
must(MAD4B_Broker_Late_Native_Reconciliation_V1::reconcile($p)['verified']===false,'replay denied');
echo "PASS: signed historical Native 324 closure, original 314 receipt registry, expiry, revocation, tampering, replay\n";
