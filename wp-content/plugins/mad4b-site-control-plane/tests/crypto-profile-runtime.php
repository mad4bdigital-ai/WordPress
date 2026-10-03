<?php
$tmp=sys_get_temp_dir().'/mad4b-crypto-profile-'.getmypid();
@mkdir($tmp.'/www',0777,true);@mkdir($tmp.'/keys',0777,true);
define('ABSPATH',$tmp.'/www/');
define('MAD4B_SCP_DIR',dirname(__DIR__).'/');
define('MAD4B_SCP_CRYPTO_KEYRING_DIR',$tmp.'/keys');
define('MAD4B_SCP_TEST_RUNTIME',true);
$_SERVER['DOCUMENT_ROOT']=$tmp.'/www';

class WP_Error{private $c;private $m;private $d;public function __construct($c='',$m='',$d=null){$this->c=(string)$c;$this->m=(string)$m;$this->d=$d;}public function get_error_code(){return$this->c;}public function get_error_data(){return$this->d;}}
function is_wp_error($v){return$v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
function wp_mkdir_p($d){return is_dir($d)||mkdir($d,0777,true);}
function trailingslashit($v){return rtrim((string)$v,'/\\').'/';}
function wp_normalize_path($v){return str_replace('\\','/',(string)$v);}

require dirname(__DIR__).'/includes/class-mad4b-scp-time-policy.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-crypto-profile.php';
$fail=static function($m,$v=null){fwrite(STDERR,'FAIL crypto-profile-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};

$clock=MAD4B_SCP_Time_Policy::set_test_clock(2000000000,500000);
$check(is_array($clock),'crypto test clock unavailable',$clock);
$defaults=array(
 'execution_receipt'=>'execution-receipt-rs256-v1',
 'preparation_receipt'=>'preparation-receipt-rs256-v1',
 'context_receipt'=>'context-receipt-rs256-v1',
);
foreach($defaults as$purpose=>$expected){
 $actual=MAD4B_SCP_Crypto_Profile::default_profile($purpose);
 $check($expected===$actual,'default crypto profile mismatch for '.$purpose,$actual);
 $profile=MAD4B_SCP_Crypto_Profile::profile($actual);
 $check(is_array($profile)&&$purpose===$profile['purpose'],'profile purpose binding mismatch',$profile);
}

$p1='execution-receipt-rs256-v1';$p2='execution-receipt-rs512-v2';
$provision=MAD4B_SCP_Crypto_Profile::provision_for_lifecycle($p1);
$check(is_array($provision)&&!empty($provision['current_kid'])&&'RS256'===$provision['algorithm'],'RS256 profile provisioning failed',$provision);
$old_kid=$provision['current_kid'];$digest=hash('sha256','receipt-a');
$sig=MAD4B_SCP_Crypto_Profile::sign_digest_for_purpose('execution_receipt',$digest);
$check(is_array($sig)&&$old_kid===$sig['kid'],'RS256 purpose-bound signing failed',$sig);
$verify=MAD4B_SCP_Crypto_Profile::verify_digest_for_purpose($sig,$digest,'execution_receipt');
$check(is_array($verify)&&!empty($verify['valid'])&&'execution_receipt'===$verify['purpose'],'purpose-bound local verification failed',$verify);
$check('mad4b_crypto_signature_purpose_mismatch'===$code(MAD4B_SCP_Crypto_Profile::verify_digest_for_purpose($sig,$digest,'context_receipt')),'cross-purpose signature was accepted');
$pub=MAD4B_SCP_Crypto_Profile::public_key($p1,$old_kid);
$external=MAD4B_SCP_Crypto_Profile::verify_with_public_key($sig,$digest,$pub);
$check(is_array($external)&&!empty($external['valid']),'independent public-key verification failed',$external);

$rot=MAD4B_SCP_Crypto_Profile::rotate($p1);
$check(is_array($rot)&&$old_kid!==$rot['current_kid'],'key rotation did not advance kid',$rot);
$old_verify=MAD4B_SCP_Crypto_Profile::verify_digest_for_purpose($sig,$digest,'execution_receipt');
$check(is_array($old_verify)&&!empty($old_verify['valid']),'old signature failed during rotation overlap',$old_verify);
MAD4B_SCP_Time_Policy::advance_test_clock(86401,86401000);
$check('mad4b_crypto_signature_overlap_expired'===$code(MAD4B_SCP_Crypto_Profile::verify_digest_for_purpose($sig,$digest,'execution_receipt')),'expired rotation overlap still verified');
$new_sig=MAD4B_SCP_Crypto_Profile::sign_digest_for_purpose('execution_receipt',hash('sha256','receipt-b'));
$check(is_array($new_sig)&&$rot['current_kid']===$new_sig['kid'],'rotated current key not used for signing',$new_sig);
$rev=MAD4B_SCP_Crypto_Profile::revoke($p1,$old_kid);
$check(is_array($rev),'old key revocation failed',$rev);
$check('mad4b_crypto_signature_key_revoked'===$code(MAD4B_SCP_Crypto_Profile::verify_digest($sig,$digest)),'revoked key still verified');

$provision2=MAD4B_SCP_Crypto_Profile::provision_for_lifecycle($p2);
$check(is_array($provision2)&&'RS512'===$provision2['algorithm'],'RS512 profile provisioning failed',$provision2);
$sig2=MAD4B_SCP_Crypto_Profile::sign_digest($p2,hash('sha256','receipt-c'));
$check(is_array($sig2)&&'RS512'===$sig2['algorithm'],'RS512 algorithm agility failed',$sig2);
$bad=$sig2;$bad['algorithm']='RS256';
$check('mad4b_crypto_signature_profile_mismatch'===$code(MAD4B_SCP_Crypto_Profile::verify_digest($bad,$sig2['signed_sha256'])),'algorithm downgrade/mismatch did not fail closed');

$status=MAD4B_SCP_Crypto_Profile::status($p1);
$check(is_array($status)&&empty($status['private_key_exposed'])&&empty($status['private_key_stored_in_database']),'crypto status exposes private-key material',$status);
$manifest=$tmp.'/keys/keyring-'.$p1.'.json';$raw=is_file($manifest)?file_get_contents($manifest):'';
$check(false===strpos((string)$raw,'PRIVATE KEY'),'private key leaked into keyring manifest');
foreach(glob($tmp.'/keys/private-*.pem')?:array() as$pem)$check(false!==strpos((string)file_get_contents($pem),'PRIVATE KEY'),'private key file missing key material');
MAD4B_SCP_Time_Policy::reset_test_clock();
echo "mad4b.crypto-profile.runtime.v2: PASS\n";
