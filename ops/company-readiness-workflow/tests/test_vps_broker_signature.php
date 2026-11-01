<?php
/** Standalone cryptographic checks for MAD4B VPS Broker host claims. */
declare(strict_types=1);
define('ABSPATH', __DIR__);
$GLOBALS['test_options']=array();
function add_action(...$args) {}
function get_option($key,$default=false) {return $GLOBALS['test_options'][$key]??$default;}
function fail_test($msg) {throw new RuntimeException($msg);}
function expect_true($x,$msg) {if (!$x) fail_test($msg);}
require __DIR__.'/../staging-mu/306-mad4b-central-vps-broker.php';
$pair=sodium_crypto_sign_keypair();
$public=sodium_crypto_sign_publickey($pair);
$secret=sodium_crypto_sign_secretkey($pair);
$GLOBALS['test_options'][MAD4B_Central_VPS_Broker_V1::PUBKEY]=base64_encode($public);
$GLOBALS['test_options'][MAD4B_Central_VPS_Broker_V1::HOST_ID]='srv2030908';
$nonce=bin2hex(random_bytes(16));$iat=time();$exp=$iat+40;
$message=implode("\n",array(MAD4B_Central_VPS_Broker_V1::CONTRACT,
    'heartbeat','srv2030908',$nonce,(string)$iat,(string)$exp));
$proof=array('host'=>'srv2030908','nonce'=>$nonce,
    'issued'=>$iat,'expires'=>$exp,
    'signature'=>base64_encode(sodium_crypto_sign_detached($message,$secret)));
$reflect=new ReflectionMethod(MAD4B_Central_VPS_Broker_V1::class,'verify_heartbeat');
expect_true($reflect->invoke(null,$proof)===true,'valid Ed25519 heartbeat');
$wrong=$proof;$wrong['host']='other-host';
expect_true($reflect->invoke(null,$wrong)===false,'host binding required');
$wrong=$proof;$wrong['nonce']=bin2hex(random_bytes(16));
expect_true($reflect->invoke(null,$wrong)===false,'nonce binding required');
$wrong=$proof;$wrong['expires']=time()-1;
expect_true($reflect->invoke(null,$wrong)===false,'expired heartbeat denied');
$job=array(
 'job_id'=>bin2hex(random_bytes(16)),'company'=>'wayfatours',
 'site_uuid'=>'b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b',
 'environment'=>'production','target_host_id'=>'srv2030908');
$cnonce=bin2hex(random_bytes(16));$ciat=time();$cexp=$ciat+40;
$cm=implode("\n",array(MAD4B_Central_VPS_Broker_V1::CONTRACT,'claim',
    $job['job_id'],$job['company'],$job['site_uuid'],$job['environment'],
    $job['target_host_id'],$cnonce,(string)$ciat,(string)$cexp));
$csig=base64_encode(sodium_crypto_sign_detached($cm,$secret));
$cproof=array('signature'=>$csig,'nonce'=>$cnonce,'issued'=>$ciat,'expires'=>$cexp);
$claim=new ReflectionMethod(MAD4B_Central_VPS_Broker_V1::class,'verify_host_claim');
expect_true($claim->invoke(null,$job,$cproof)===true,'valid exact site signed claim');
$wrong=$job;$wrong['environment']='staging';
expect_true($claim->invoke(null,$wrong,$cproof)===false,'environment substitution denied');
$wrong=$job;$wrong['site_uuid']='00000000-0000-0000-0000-000000000000';
expect_true($claim->invoke(null,$wrong,$cproof)===false,'site substitution denied');
$wrong=$job;$wrong['target_host_id']='other-host';
expect_true($claim->invoke(null,$wrong,$cproof)===false,'VPS host substitution denied');
$wrong=$cproof;$wrong['nonce']=bin2hex(random_bytes(16));
expect_true($claim->invoke(null,$job,$wrong)===false,'claim nonce substitution denied');
$wrong=$cproof;$wrong['expires']=time()-1;
expect_true($claim->invoke(null,$job,$wrong)===false,'expired claim denied');
echo "PASS: signed host heartbeat and exact-company signed claim; 10 positive/negative cases\n";
