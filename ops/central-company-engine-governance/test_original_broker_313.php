<?php
/** Hermetic 313 receipt contract: no live WordPress, SSH, or native execution. */
declare(strict_types=1);
define('ABSPATH','/');
$GLOBALS['fixture_options']=[];
function get_option($key,$default=false){return $GLOBALS['fixture_options'][$key]??$default;}
function add_option($key,$value,$deprecated='',$autoload=false){
 if(array_key_exists($key,$GLOBALS['fixture_options']))return false;
 $GLOBALS['fixture_options'][$key]=$value;return true;
}
function must($condition,$label){if(!$condition){fwrite(STDERR,"FAIL 313 $label\n");exit(1);}}
class MAD4B_Central_VPS_Broker_V1 {
 const PUBKEY='test_broker_public';
 const JOB='test_job_';
}
class MAD4B_Broker_Exact_SSH_Authority_V1 {
 public static bool $eligible=true;
 public static function evaluate($job,$target,$digest):array {
  return ['eligible'=>self::$eligible,'owner_enrolled_wpcli'=>true,
   'operation'=>'company.remote.wpcli.full','target_scope'=>'wordpress',
   'company'=>'company-a','site_uuid'=>'site-a','origin'=>'https://staging.example.test',
   'environment'=>'staging','grant_id'=>'grant-a','transport_id'=>'transport-a'];
 }
}
require __DIR__.'/original-broker-source/313-mad4b-broker-remote-ssh-diagnostic.php';
must(function_exists('sodium_crypto_sign_detached'),'libsodium required');
$pair=sodium_crypto_sign_keypair();
$public=sodium_crypto_sign_publickey($pair);
$secret=sodium_crypto_sign_secretkey($pair);
$GLOBALS['fixture_options'][MAD4B_Central_VPS_Broker_V1::PUBKEY]=base64_encode($public);
$now=time();$id=str_repeat('a',32);$target='hosting-2';
$digest=str_repeat('b',64);$out=str_repeat('c',64);$read=str_repeat('d',64);
$nonce=str_repeat('e',32);$expires=$now+40;
function signedArgs($pair,$id,$target,$digest,$out,$read,$nonce,$now,$expires,$exit='0'):array {
 $fields=['mad4b.broker-remote-wpcli-receipt.v1','receipt','srv2030908',
  $id,$target,$nonce,(string)$now,(string)$expires,$digest,$out,$read,(string)$exit];
 return ['job'=>$id,'target'=>$target,'command_sha256'=>$digest,
  'output_sha256'=>$out,'readback_sha256'=>$read,'nonce'=>$nonce,
  'issued'=>$now,'expires'=>$expires,'exit_code'=>(string)$exit,
  'signature'=>base64_encode(sodium_crypto_sign_detached(implode("\n",$fields),$pair))];
}
$method=new ReflectionMethod('MAD4B_Broker_Remote_SSH_Receipt_V1','wpcli_receipt');
$bad=signedArgs($secret,$id,$target,$digest,$out,$read,$nonce,$now,$expires);
$bad['signature']=base64_encode(str_repeat('x',64));
$denied=$method->invoke(null,$bad);
must(($denied['verified']??false)===false,'invalid signature was admitted');
must(($denied['reason']??'')==='INVALID_WPCLI_BROKER_SIGNATURE','signature denial reason');
$valid=signedArgs($secret,$id,$target,$digest,$out,$read,$nonce,$now,$expires);
MAD4B_Broker_Exact_SSH_Authority_V1::$eligible=false;
$revoked=$method->invoke(null,$valid);
must(($revoked['reason']??'')==='OWNER_WPCLI_GRANT_OR_CLAIM_NOT_ELIGIBLE','revoked 312 denied');
MAD4B_Broker_Exact_SSH_Authority_V1::$eligible=true;
$row=$method->invoke(null,$valid);
must(($row['state']??'')==='PENDING_INDEPENDENT_NATIVE_READBACK','pending receipt state');
must(($row['verified']??true)===false,'pending receipt never certified');
must(($row['native_executor_certified']??true)===false,'native approval not minted');
must(($row['independent_host_readback_verified']??true)===false,'independent readback not forged');
foreach(['job_id'=>$id,'target_id'=>$target,'company'=>'company-a',
 'site_uuid'=>'site-a','origin'=>'https://staging.example.test','environment'=>'staging',
 'grant_id'=>'grant-a','transport_id'=>'transport-a',
 'command_sha256'=>$digest,'output_sha256'=>$out,'readback_sha256'=>$read,
 'exit_code'=>0] as $key=>$value)
 must(($row[$key]??null)===$value,'scope mismatch '.$key);
$replay=$method->invoke(null,$valid);
must(($replay['reason']??'')==='WPCLI_RECEIPT_ALREADY_EXISTS','repeated 313 cannot reissue');
must(count($GLOBALS['fixture_options'])===2,'unexpected state mutation');
echo "BROKER_313_BOUNDED_PENDING_READBACK: PASS\n";
