<?php
define('ABSPATH', __DIR__);
require dirname(__DIR__).'/includes/class-mad4b-scp-runtime-runner-bridge.php';
$key=openssl_pkey_new(array('private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA));
openssl_pkey_export($key,$secret);
$public=openssl_pkey_get_details($key)['key'];
$plan=str_repeat('a',64);
$env=MAD4B_SCP_Runtime_Runner_Bridge::prepare('staging-site-1234',$plan,$secret,1700000000);
if(!$env || openssl_verify(MAD4B_SCP_Runtime_Runner_Bridge::canonical($env['request']),
base64_decode($env['signature']),$public,OPENSSL_ALGO_SHA256)!==1)throw new RuntimeException('request signature');
$request=$env['request'];
$receipt=array('contract'=>'mad4b.runtime-runner.v1.receipt','site'=>$request['site'],
'operation'=>$request['operation'],'nonce'=>$request['nonce'],
'request_sha256'=>hash('sha256',MAD4B_SCP_Runtime_Runner_Bridge::canonical($request)),
'exit_code'=>0,'stdout_sha256'=>str_repeat('b',64),'result'=>'ok','produced_at'=>1700000005,
'production_authorized'=>false,'mutation_performed'=>false);
openssl_sign(MAD4B_SCP_Runtime_Runner_Bridge::canonical($receipt),$sig,$secret,OPENSSL_ALGO_SHA256);
$payload=array('receipt'=>$receipt,'signature'=>base64_encode($sig));
if(!MAD4B_SCP_Runtime_Runner_Bridge::verify_receipt($payload,$env,$public,1700000010))throw new RuntimeException('receipt signature');
$bad=$payload;$bad['receipt']['site']='production-site';
if(MAD4B_SCP_Runtime_Runner_Bridge::verify_receipt($bad,$env,$public,1700000010))throw new RuntimeException('cross-site receipt');
$bad=$payload;$bad['receipt']['mutation_performed']=true;
if(MAD4B_SCP_Runtime_Runner_Bridge::verify_receipt($bad,$env,$public,1700000010))throw new RuntimeException('mutation accepted');
$bad=$payload;$bad['receipt']['result']='forged';
if(MAD4B_SCP_Runtime_Runner_Bridge::verify_receipt($bad,$env,$public,1700000010))throw new RuntimeException('tamper accepted');
echo "PASS: RUNTIME_RUNNER_BRIDGE_RSA_RECEIPTS\n";
