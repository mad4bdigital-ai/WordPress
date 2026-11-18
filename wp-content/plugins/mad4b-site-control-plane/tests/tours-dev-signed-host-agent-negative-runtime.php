<?php
/** Hermetic Ed25519-only Host Agent guards. No WordPress, network, live grant or key. */
error_reporting(E_ALL);
define('ABSPATH',__DIR__.'/');
define('WP_CLI',true);
define('DB_NAME','fixture_only_database');
class WP_CLI { public static function add_command($route,$callback) { $GLOBALS['registered_host_cmd']=$route; } }
class WP_Error { private $c; public function __construct($code,$msg=''){$this->c=$code;} public function get_error_code(){return $this->c;} }
function is_wp_error($value){return $value instanceof WP_Error;}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function add_option($key,$value,$unused='',$autoload='yes') { if(isset($GLOBALS['nonces'][$key]))return false;$GLOBALS['nonces'][$key]=$value;return true; }
function get_current_user_id(){return $GLOBALS['actor_id'];}
function home_url($path='/'){return 'https://staging.example.test'.($path==='/'?'/':$path);}
function sanitize_title($v){return strtolower(preg_replace('/[^a-z0-9-]/i','',(string)$v));}
function check_it($ok,$message){if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
class MAD4B_SCP_Site_Profile {
 static $ready=true;
 static function status(){return array('configured'=>true,'authority_ready'=>self::$ready,'deployment_binding_match'=>true,'origin_match'=>true,'environment_match'=>true,'same_origin_clone_protection'=>true,'mutation_pending_audit'=>false,'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','environment'=>'staging');}
 static function agent_slug(){return 'site-host-agent';}
}
class MAD4B_SCP_CLI_Execution_Context { static function resolve($op,$mutate){return array('read_context_verified'=>true,'blockers'=>array());} }
class MAD4B_SCP_Agent_Registry { static function get_agent_by_slug($s){return null;} }
$GLOBALS['actor_id']=0;
$runtime=sys_get_temp_dir().'/mad4b-host-negative-'.bin2hex(random_bytes(8));
check_it(mkdir($runtime,0700),'Make isolated host source fixture');
$source=dirname(__DIR__).'/includes/class-mad4b-scp-host-agent-bridge.php';
$copy=$runtime.'/class-mad4b-scp-host-agent-bridge.php';
check_it(copy($source,$copy),'Copy source into isolated key fixture');
register_shutdown_function(static function()use($runtime,$copy){@unlink($runtime.'/mad4b-host-agent-public.b64');@unlink($copy);@rmdir($runtime);});
require $copy;
$host='MAD4B_SCP_Host_Agent_Bridge';
check_it($GLOBALS['registered_host_cmd']==='mad4b host-agent','CLI command registered only in WP CLI');
check_it($host::public_key()==='','No fabricated host trust before key enrollment');
check_it($host::active()===false,'Host must not be active without pinned public key');
$pair=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($pair);
$pub=sodium_crypto_sign_publickey($pair);
check_it(file_put_contents($runtime.'/mad4b-host-agent-public.b64',base64_encode($pub))>0,'Write ephemeral PUBLIC key only');
check_it($host::public_key()===$pub,'Pinned public key decodes exactly');
check_it($host::binding_ready()===false,'No agent or exact grant means inactive even with signature key');
check_it($host::active()===false,'Key alone never grants execution');
check_it($host::status()['execution_authorized']===false,'Status never claims execution authorization');
$GLOBALS['actor_id']=12;
check_it(is_wp_error($host::execute(array())),'Impersonated WP user denied');
$GLOBALS['actor_id']=0;
MAD4B_SCP_Site_Profile::$ready=false;
check_it($host::site()===array(),'Missing enrolled Site Profile authority denied');
MAD4B_SCP_Site_Profile::$ready=true;
$payload=array('contract'=>$host::CONTRACT,'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','origin'=>'https://staging.example.test','environment'=>'staging','database_sha256'=>hash('sha256',DB_NAME),'operation'=>'media_intake','body_sha256'=>hash('sha256','[]'),'nonce'=>bin2hex(random_bytes(16)),'issued_at'=>time(),'expires_at'=>time()+45);
$build=static function($input,$body=array())use($secret,$host){$input['body_sha256']=hash('sha256',json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$sign=$input;ksort($sign,SORT_STRING);return array('contract'=>$host::CONTRACT,'payload'=>$input,'body'=>$body,'signature_b64'=>base64_encode(sodium_crypto_sign_detached(json_encode($sign,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$secret)));};
$envelope=$build($payload);
$wrong=$envelope;$wrong['payload']['site_uuid']='00000000-0000-4000-8000-000000000000';
check_it($host::execute($wrong)->get_error_code()==='mad4b_host_site_drift','Cross-tenant signed identity mismatch rejected');
$wrong=$envelope;$wrong['body']=array('tampered'=>true);
check_it($host::execute($wrong)->get_error_code()==='mad4b_host_body_drift','Post-signature operation body tamper rejected');
$wrong=$envelope;$wrong['signature_b64']=base64_encode(str_repeat('X',64));
check_it($host::execute($wrong)->get_error_code()==='mad4b_host_signature_invalid','Invalid signature rejected');
$wrong=$build(array_merge($payload,array('issued_at'=>time()-75,'expires_at'=>time()-30,'nonce'=>bin2hex(random_bytes(16)))));
check_it($host::execute($wrong)->get_error_code()==='mad4b_host_attestation_expired','Expired proof rejected');
$first=$host::execute($envelope);
check_it($first instanceof WP_Error && $first->get_error_code()==='mad4b_optional_tour_media_unavailable','Signed optional Media call must fail closed without provider');
$replay=$host::execute($envelope);
check_it($replay instanceof WP_Error && $replay->get_error_code()==='mad4b_host_proof_replayed','One-time signed host proof cannot replay');
$extra=$build(array_merge($payload,array('nonce'=>bin2hex(random_bytes(16)),'operation'=>'review')));
check_it($host::execute($extra)->get_error_code()==='mad4b_host_review_not_attested','Signed review without exact registry agent+grant denied');
check_it($host::active()===false,'Signed failure cannot leak active host context');
echo "PASS: Ed25519 Host execution proofs, tenant isolation, anti-replay, missing provider and missing exact grant (13+ checks)\n";
