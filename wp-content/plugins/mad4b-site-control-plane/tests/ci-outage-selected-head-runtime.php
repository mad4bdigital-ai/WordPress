<?php
/** Offline exact-HEAD resolver acceptance without GitHub CI, network or WP. */
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
class WP_Error {
 private $code;
 public function __construct($code,$msg=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
$GLOBALS['offline_key']='';$GLOBALS['manifest_body']='';
function get_option($name,$fallback=''){return $GLOBALS['offline_key'];}
function wp_safe_remote_get($url,$args){return array('url'=>$url,'status'=>200,'body'=>$GLOBALS['manifest_body']);}
function wp_remote_retrieve_response_code($r){return $r['status'];}
function wp_remote_retrieve_body($r){return $r['body'];}
class MAD4B_SCP_Site_Profile {
 public static $environment='staging';
 public static function status() {return array('configured_environment'=>self::$environment,
  'site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb',
  'canonical_origin'=>'https://staging.example.test','origin_match'=>true,'authority_ready'=>true);}
}
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-selected-head-update.php';
function expect($ok,$reason){if(!$ok){fwrite(STDERR,"FAIL: ".$reason."\n");exit(1);}}
$sha=str_repeat('a',40);$ziphash=str_repeat('b',64);
$pair=sodium_crypto_sign_keypair();$private=sodium_crypto_sign_secretkey($pair);
$GLOBALS['offline_key']=base64_encode(sodium_crypto_sign_publickey($pair));
$issued=time()-30;
$gates=array_fill_keys(array('g9_delivery_contract','php83_tree_syntax',
 'canonical_package_receipt','isolated_zip_runtime_integrity','exact_source_verification'),'PASS');
$claims=array('contract'=>'mad4b.staging-ci-outage-attestation.v1',
 'repository'=>'mad4bdigital-ai/WordPress','source_commit_sha'=>$sha,
 'archive_sha256'=>$ziphash,'build_fingerprint'=>str_repeat('c',64),
 'package_manifest_digest'=>str_repeat('d',64),'size_bytes'=>11000,
 'site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb',
 'site_origin'=>'https://staging.example.test','environment'=>'staging',
 'issued_at'=>$issued,'expires_at'=>$issued+3600,
 'evidence_bundle_sha256'=>str_repeat('e',64),'test_gates'=>$gates,'owner_reviewed'=>true);
$raw=json_encode($claims,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$manifest=array('contract'=>'mad4b.control-plane-update-channel.v1',
 'repository'=>'mad4bdigital-ai/WordPress','release_tag'=>'mad4b-site-control-plane-update-channel',
 'version'=>'0.4.0-rc.96','source_commit_sha'=>$sha,'archive_sha256'=>$ziphash,
 'build_fingerprint'=>str_repeat('c',64),'package_manifest_digest'=>str_repeat('d',64),
 'size_bytes'=>11000,
 'package_url'=>'https://github.com/mad4bdigital-ai/WordPress/releases/download/mad4b-site-control-plane-update-channel/mad4b-site-control-plane-'.$sha.'.zip',
 'staging_offline_candidate'=>true,'staging_candidate_certified'=>false,
 'release_verdict_success'=>false,'release_verdict_run_id'=>0,
 'production_authorized'=>false,'production_auto_update_enabled'=>false,
 'published_from_master'=>false,'evidence_bundle_sha256'=>str_repeat('e',64),
 'ci_outage_attestation'=>array('claims_b64'=>base64_encode($raw),
  'signature_b64'=>base64_encode(sodium_crypto_sign_detached($raw,$private))));
$reflection=new ReflectionMethod('MAD4B_SCP_Selected_Head_Update','manifest');
$GLOBALS['manifest_body']=json_encode($manifest);
$accepted=$reflection->invoke(null,$sha);
expect(!is_wp_error($accepted)&&$accepted['evidence_mode']==='owner_signed_ci_outage',
 'valid signer allows offline selected HEAD without CI run');
expect(!$accepted['github_ci_terminal_required']&&$accepted['release_verdict_run_id']===0,
 'zero CI run ID explicitly accepted for signed offline Staging');
$manifest['ci_outage_attestation']['signature_b64']=base64_encode(str_repeat("\0",64));
$GLOBALS['manifest_body']=json_encode($manifest);
expect(is_wp_error($reflection->invoke(null,$sha)),'invalid signer blocks update');
$manifest['ci_outage_attestation']=null;$GLOBALS['manifest_body']=json_encode($manifest);
expect(is_wp_error($reflection->invoke(null,$sha)),'missing signature and missing CI blocks update');
$manifest['staging_candidate_certified']=true;
$manifest['release_verdict_success']=true;$manifest['release_verdict_run_id']=456;
$GLOBALS['manifest_body']=json_encode($manifest);
$ci=$reflection->invoke(null,$sha);
expect(!is_wp_error($ci)&&$ci['evidence_mode']==='github_ci_verdict',
 'normal successful CI remains accepted without offline signer');
echo "PASS: signed offline CI-outage selection / bad-signature denial / normal CI compatibility\n";
