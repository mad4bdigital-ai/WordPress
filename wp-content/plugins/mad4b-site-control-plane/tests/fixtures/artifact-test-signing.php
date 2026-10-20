<?php
require __DIR__.'/production-automation-bootstrap.php';
require dirname(__DIR__,2).'/includes/class-mad4b-scp-production-automation.php';
require dirname(__DIR__,2).'/includes/class-mad4b-scp-governed-runtime-gates.php';
require dirname(__DIR__,2).'/includes/class-mad4b-scp-crypto-profile.php';
require dirname(__DIR__,2).'/includes/class-mad4b-scp-test-attestation.php';
function check($v,$m){if(!$v)throw new Exception($m);echo 'PASS '.$m.PHP_EOL;}
$private=openssl_pkey_new(array('private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA));
$public=openssl_pkey_get_details($private)['key'];
$key=array('enabled'=>true,'issuer_site_uuid'=>'22222222-2222-4222-8222-222222222222','issuer_origin'=>'https://staging.example.test','issuer_environment'=>'staging','target_environments'=>array('local','development','staging','production'),'required_tests'=>array('search','booking'),'not_before'=>time()-3600,'not_after'=>time()+86400,'public_key'=>array('profile_id'=>'test-artifact-rs256','profile_sha256'=>str_repeat('a',64),'algorithm'=>'RS256','kid'=>'fixture-test-key','public_key_pem'=>$public));
$artifact=array('plugin_file'=>'demo/demo.php','package_sha256'=>str_repeat('b',64),'archive_manifest_sha256'=>str_repeat('c',64),'target_version'=>'2.0');
function cert($key,$artifact,$private){
 $p=$artifact+array('contract'=>MAD4B_SCP_Test_Attestation::CONTRACT,'issuer_site_uuid'=>$key['issuer_site_uuid'],'issuer_origin'=>$key['issuer_origin'],'issuer_environment'=>$key['issuer_environment'],'target_site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),'target_origin'=>MAD4B_SCP_Site_Profile::current_origin(),'target_environment'=>$GLOBALS['env'],'issued_at'=>time()-1,'expires_at'=>time()+300,'source_commit_sha'=>str_repeat('d',40),'tests'=>array('install'=>'PASS','smoke'=>'PASS','rollback'=>'PASS','search'=>'PASS','booking'=>'PASS'));
 return sign_payload($p,$key,$private);
}
function sign_payload($p,$key,$private){$sha=MAD4B_SCP_Production_Automation::digest($p);openssl_sign($sha,$sig,$private,OPENSSL_ALGO_SHA256);$s=$key['public_key'];unset($s['public_key_pem']);$s+=array('contract'=>'mad4b.detached-signature.v1','signed_sha256'=>$sha,'signature_b64url'=>rtrim(strtr(base64_encode($sig),'+/','-_'),'='));return array('payload'=>$p,'signature'=>$s);}
