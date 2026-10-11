<?php
/** Independent Ed25519 Staging CI-outage claim validator — no WordPress boot. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
 private $code;
 public function __construct($code,$message=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
$GLOBALS['offline_key']='';
function get_option($name,$default=''){return $GLOBALS['offline_key'];}
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-ci-outage-attestation.php';
function expect($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL: ".$msg."\n");exit(1);}}
$c='MAD4B_SCP_CI_Outage_Attestation';
$pair=sodium_crypto_sign_keypair();
$sk=sodium_crypto_sign_secretkey($pair);
$GLOBALS['offline_key']=base64_encode(sodium_crypto_sign_publickey($pair));
$sha=str_repeat('a',40);
$hash=str_repeat('b',64);
$bundle=str_repeat('c',64);
$manifest=array('source_commit_sha'=>$sha,'archive_sha256'=>$hash,
 'build_fingerprint'=>str_repeat('d',64),'package_manifest_digest'=>str_repeat('e',64),
 'size_bytes'=>12000,'evidence_bundle_sha256'=>$bundle);
$site=array('configured_environment'=>'staging','site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb',
 'canonical_origin'=>'https://staging.example.test','origin_match'=>true,'authority_ready'=>true);
$now=1770000000;
$gates=array_fill_keys($c::REQUIRED_GATES,'PASS');
$claims=array('contract'=>$c::CONTRACT,'repository'=>'mad4bdigital-ai/WordPress',
 'source_commit_sha'=>$sha,'archive_sha256'=>$hash,
 'build_fingerprint'=>$manifest['build_fingerprint'],
 'package_manifest_digest'=>$manifest['package_manifest_digest'],
 'size_bytes'=>12000,'site_uuid'=>$site['site_uuid'],
 'site_origin'=>$site['canonical_origin'],'environment'=>'staging',
 'issued_at'=>$now-60,'expires_at'=>$now+3600,
 'evidence_bundle_sha256'=>$bundle,'test_gates'=>$gates,'owner_reviewed'=>true);
function sign_claim($c,$claims,$sk) {
 $raw=json_encode($claims,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
 return array('claims_b64'=>base64_encode($raw),
              'signature_b64'=>base64_encode(sodium_crypto_sign_detached($raw,$sk)));
}
$a=sign_claim($c,$claims,$sk);
$result=$c::verify($a,$manifest,$site,$now);
expect(!is_wp_error($result)&&$result['verified']&&!$result['ci_result_required'],
 'valid trusted outage receipt independent of GitHub CI');
expect($result['production_allowed']===false,'Production denied');
$bad=$a;$bad['signature_b64']=base64_encode(str_repeat("\x00",64));
expect(is_wp_error($c::verify($bad,$manifest,$site,$now)),'untrusted signature denied');
expect(is_wp_error($c::verify($a,$manifest,$site,$now,'bad')),'unenrolled signer denied');
$badmanifest=$manifest;$badmanifest['archive_sha256']=str_repeat('f',64);
expect(is_wp_error($c::verify($a,$badmanifest,$site,$now)),'package hash mismatch denied');
$badmanifest=$manifest;$badmanifest['evidence_bundle_sha256']=str_repeat('f',64);
expect(is_wp_error($c::verify($a,$badmanifest,$site,$now)),'test bundle mismatch denied');
$badsite=$site;$badsite['site_uuid']='foreign';
expect(is_wp_error($c::verify($a,$manifest,$badsite,$now)),'foreign site denied');
$badsite=$site;$badsite['configured_environment']='production';
expect(is_wp_error($c::verify($a,$manifest,$badsite,$now)),'Production site denied');
expect(is_wp_error($c::verify($a,$manifest,$site,$now+4000)),'expired evidence denied');
$badclaims=$claims;$badclaims['expires_at']=$now+90000;
expect(is_wp_error($c::verify(sign_claim($c,$badclaims,$sk),$manifest,$site,$now)),
 'overlong evidence lifetime denied');
$badclaims=$claims;$badclaims['test_gates']['php83_tree_syntax']='FAIL';
expect(is_wp_error($c::verify(sign_claim($c,$badclaims,$sk),$manifest,$site,$now)),
 'failed native PHP test denied');
$badclaims=$claims;unset($badclaims['test_gates']['g9_delivery_contract']);
expect(is_wp_error($c::verify(sign_claim($c,$badclaims,$sk),$manifest,$site,$now)),
 'missing G9 test denied');
$badclaims=$claims;$badclaims['owner_reviewed']=false;
expect(is_wp_error($c::verify(sign_claim($c,$badclaims,$sk),$manifest,$site,$now)),
 'unreviewed package denied');
$badclaims=$claims;$badclaims['environment']='production';
expect(is_wp_error($c::verify(sign_claim($c,$badclaims,$sk),$manifest,$site,$now)),
 'Production receipt denied');
echo "PASS: 13 Ed25519 CI-outage source/site/test/time/trust refusal checks\n";
