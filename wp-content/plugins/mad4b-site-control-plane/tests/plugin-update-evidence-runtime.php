<?php
/* Certified plugin-specific, signed CI-independent test evidence verification. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
 private $code;
 public function __construct($code,$message=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-plugin-update-evidence.php';
function demand_evidence($valid,$message){
 if(!$valid){fwrite(STDERR,"FAIL: $message\n");exit(1);}
}
$c='MAD4B_SCP_Plugin_Update_Evidence';
$keypair=sodium_crypto_sign_keypair();
$private=sodium_crypto_sign_secretkey($keypair);
$authority=array('plugin_file'=>'sample/sample.php','version'=>'3.4.1',
 'archive_sha256'=>str_repeat('a',64),
 'offline_update_attestor_public_key'=>base64_encode(sodium_crypto_sign_publickey($keypair)));
$site=array('configured_environment'=>'staging',
 'site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb',
 'canonical_origin'=>'https://staging.example.test','origin_match'=>true,'authority_ready'=>true);
$time=1770000000;
$claims=array(
 'contract'=>$c::CONTRACT,'provider_id'=>'sample','component'=>'',
 'plugin_file'=>'sample/sample.php','version'=>'3.4.1',
 'archive_sha256'=>str_repeat('a',64),'source_commit_sha'=>str_repeat('b',40),
 'site_uuid'=>$site['site_uuid'],'site_origin'=>$site['canonical_origin'],
 'environment'=>'staging','issued_at'=>$time-20,'expires_at'=>$time+1800,
 'evidence_bundle_sha256'=>str_repeat('c',64),
 'test_gates'=>array_fill_keys($c::REQUIRED_GATES,'PASS'),'owner_reviewed'=>true
);
function signed_evidence($claims,$private) {
 $json=json_encode($claims,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
 return array('claims_b64'=>base64_encode($json),'signature_b64'=>base64_encode(sodium_crypto_sign_detached($json,$private)));
}
$valid=$c::verify(signed_evidence($claims,$private),$authority,$site,'sample','',$time);
demand_evidence(!is_wp_error($valid)&&$valid['verified']&&!$valid['ci_terminal_result_required'],'good provider evidence accepted without CI');
$changed=$claims;$changed['archive_sha256']=str_repeat('f',64);
demand_evidence(is_wp_error($c::verify(signed_evidence($changed,$private),$authority,$site,'sample','',$time)),'wrong archive denied');
$changed=$claims;$changed['provider_id']='other';
demand_evidence(is_wp_error($c::verify(signed_evidence($changed,$private),$authority,$site,'sample','',$time)),'cross-provider evidence denied');
$changed=$claims;$changed['plugin_file']='different/file.php';
demand_evidence(is_wp_error($c::verify(signed_evidence($changed,$private),$authority,$site,'sample','',$time)),'cross-plugin evidence denied');
$changed=$claims;$changed['test_gates']['plugin_runtime']='FAIL';
demand_evidence(is_wp_error($c::verify(signed_evidence($changed,$private),$authority,$site,'sample','',$time)),'failed test denied');
$changed=$claims;unset($changed['test_gates']['php_syntax']);
demand_evidence(is_wp_error($c::verify(signed_evidence($changed,$private),$authority,$site,'sample','',$time)),'missing test denied');
$changed=$claims;$changed['evidence_bundle_sha256']='not-digest';
demand_evidence(is_wp_error($c::verify(signed_evidence($changed,$private),$authority,$site,'sample','',$time)),'unsigned bundle hash denied');
$changed=$claims;$changed['expires_at']=$time+90000;
demand_evidence(is_wp_error($c::verify(signed_evidence($changed,$private),$authority,$site,'sample','',$time)),'long validity denied');
demand_evidence(is_wp_error($c::verify(signed_evidence($claims,$private),$authority,$site,'sample','',$time+4000)),'expiry denied');
$production=$site;$production['configured_environment']='production';
demand_evidence(is_wp_error($c::verify(signed_evidence($claims,$private),$authority,$production,'sample','',$time)),'production denied');
$foreign=$site;$foreign['site_uuid']='other';
demand_evidence(is_wp_error($c::verify(signed_evidence($claims,$private),$authority,$foreign,'sample','',$time)),'foreign site denied');
$untrusted=$authority;unset($untrusted['offline_update_attestor_public_key']);
demand_evidence(is_wp_error($c::verify(signed_evidence($claims,$private),$untrusted,$site,'sample','',$time)),'missing enrolled source-owned signer denied');
$bad=signed_evidence($claims,$private);$bad['signature_b64']=base64_encode(str_repeat("\x00",64));
demand_evidence(is_wp_error($c::verify($bad,$authority,$site,'sample','',$time)),'bad signature denied');
echo "PASS: per-provider native Ed25519 evidence and 12 negative boundary checks\n";
