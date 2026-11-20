<?php
/** Hermetic, locally generated signature tests: does not modify any live site. */
$testroot=sys_get_temp_dir().'/mad4b-source-attest-unit-'.getmypid();
mkdir($testroot,0700,true);
define('ABSPATH',$testroot.'/');define('WP_CONTENT_DIR',$testroot.'/wp-content');
class WP_Error {private $code;function __construct($code,$message=''){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action($a,$b,$p=10){return true;}
function home_url(){return 'https://source.test';}
function untrailingslashit($x){return rtrim($x,'/');}
function current_user_can($x){return true;}
class MAD4B_SCP_Site_Profile {public static function origin_enrolled(){return true;}public static function site_urls_match_enrollment(){return true;}public static function site_uuid(){return 'fixture-site-uuid';}public static function profile_digest(){return str_repeat('f',64);}}
$GLOBALS['request']=['contract'=>'mad4b.release-source-request.v1','type'=>'github_candidate','reference'=>'feature/fixed-source','site_uuid'=>'fixture-site-uuid','profile_digest'=>str_repeat('f',64),'requested_at'=>time()-30,'authorizing'=>false];
function get_option($key,$fallback=[]){return $key==='mad4b_release_source_request_v1'?$GLOBALS['request']:$fallback;}
function fail_test($description){fwrite(STDERR,'FAIL: '.$description."\n");exit(1);}
function yes($value,$desc){if(!$value)fail_test($desc);}
$plugin=WP_CONTENT_DIR.'/plugins/mad4b-site-control-plane';
$mu=WP_CONTENT_DIR.'/mu-plugins';$attestation=$mu.'/mad4b-source-preflight';
mkdir($plugin,0700,true);mkdir($attestation,0700,true);
file_put_contents($plugin.'/test.php','<?php // trusted original');
file_put_contents($mu.'/532-mad4b-release-source-admission.php','<?php // selected runtime');
require __DIR__.'/../../wp-content/mu-plugins/532-mad4b-release-source-admission.php';
$inv=MAD4B_Release_Source_Admission::current_site_inventory();
yes(!is_wp_error($inv)&&$inv['files']===2,'inventory must include plugin and selected MU');
$pair=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
$details=openssl_pkey_get_details($pair);
file_put_contents($attestation.'/release-public.pem',$details['key']);
$payload=[
 'contract'=>'mad4b.host-source-preflight.v1','source_kind'=>'github_candidate',
 'source_reference'=>'feature/fixed-source','source_sha'=>str_repeat('a',40),
 'site_uuid'=>'fixture-site-uuid','profile_digest'=>str_repeat('f',64),'origin'=>'https://source.test',
 'target_inventory_sha256'=>$inv['sha256'],
 'reconciliation_plan_sha256'=>str_repeat('b',64),
 'reconciliation_ready'=>true,'source_manifest_ready'=>true,
 'issued_at'=>time(),'expires_at'=>time()+600,'authorizing'=>false,'mutation_performed'=>false,
];
function sign_evidence($payload,$pair,$path){
 $raw=json_encode($payload,JSON_UNESCAPED_SLASHES);openssl_sign($raw,$signature,$pair,OPENSSL_ALGO_SHA256);
 $e=['contract'=>'mad4b.host-source-preflight-signature.v1','payload_b64'=>base64_encode($raw),'signature_b64'=>base64_encode($signature)];
 file_put_contents($path,json_encode($e));
}
$file=$attestation.'/attestation.json';sign_evidence($payload,$pair,$file);
$a=MAD4B_Release_Source_Admission::check(str_repeat('a',40));
yes($a['preflight_ready']===true&&$a['target_inventory_verified']===true,'valid host preflight should verify');
yes(MAD4B_Release_Source_Admission::require_for_release(str_repeat('a',40))===true,'correct source can pass preflight');
$b=MAD4B_Release_Source_Admission::check(str_repeat('c',40));
yes(!$b['preflight_ready']&&in_array('selected_source_sha_mismatch',$b['blockers'],true),'different source SHA denied');
file_put_contents($plugin.'/test.php','<?php // changed code');
$c=MAD4B_Release_Source_Admission::check(str_repeat('a',40));
yes(!$c['preflight_ready']&&in_array('target_inventory_changed_after_preflight',$c['blockers'],true),'target change denied');
file_put_contents($plugin.'/test.php','<?php // trusted original');
$envelope=json_decode(file_get_contents($file),true);$envelope['signature_b64']=base64_encode('tampered');file_put_contents($file,json_encode($envelope));
$d=MAD4B_Release_Source_Admission::check(str_repeat('a',40));
yes(!$d['preflight_ready']&&in_array('signed_host_preflight_signature_invalid',$d['blockers'],true),'signature tampering denied');
sign_evidence($payload,$pair,$file);
$GLOBALS['request']['reference']='feature/another-source';
$e=MAD4B_Release_Source_Admission::check(str_repeat('a',40));
yes(!$e['preflight_ready']&&in_array('signed_host_preflight_binding_invalid',$e['blockers'],true),'changed source selection denied');
$GLOBALS['request']['reference']='feature/fixed-source';
$payload['issued_at']=time()-1200;$payload['expires_at']=time()-400;
sign_evidence($payload,$pair,$file);
$f=MAD4B_Release_Source_Admission::check(str_repeat('a',40));
yes(!$f['preflight_ready']&&in_array('signed_host_preflight_binding_invalid',$f['blockers'],true),'expired signed proof denied');
$bad=MAD4B_Release_Source_Admission::validate_selection('github_candidate','../../etc');
yes(is_wp_error($bad),'unsafe branch denied');
$good=MAD4B_Release_Source_Admission::validate_selection('local_integration','integration/testing');
yes(is_array($good)&&$good['reference']==='integration/testing','valid local integration branch');
echo 'PASS: 9 signed/source/target/inventory/expiry/selection host-admission assertions'."\n";
