<?php
$base=sys_get_temp_dir().'/mad4b-uac-'.bin2hex(random_bytes(4));
mkdir($base.'/www',0700,true);mkdir($base.'/vault',0700,true);chmod($base.'/vault',0700);
define('ABSPATH',$base.'/www/');define('WP_CONTENT_DIR',$base.'/www/wp-content');
define('MAD4B_AGENT_CONNECTION_VAULT_DIR',$base.'/vault');
$GLOBALS['secure']=true;
function get_current_user_id(){return 7;}
function current_user_can($role){return $role==='manage_options';}
function is_ssl(){return $GLOBALS['secure'];}
function wp_get_current_user(){return (object)['ID'=>7,'user_login'=>'tester'];}
function wp_is_application_passwords_available_for_user($user){return true;}
function sanitize_key($s){return strtolower((string)$s);}
function home_url($s=''){return 'https://example.test/'.$s;}
function absint($x){return abs((int)$x);}
function wp_json_encode($x){return json_encode($x);}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error {private $code; function __construct($c,$m){$this->code=$c;} function get_error_message(){return $this->code;}}
class MAD4B_SCP_Policy {static function can_connect_user($id){return $id===7;}}
class MAD4B_SCP_Environment {static function effective(){return $GLOBALS['test_environment'] ?? 'staging';}}
class MAD4B_SCP_Site_Profile {
 static function configured(){return true;}
 static function origin_enrolled(){return true;}
 static function site_urls_match_enrollment(){return true;}
 static function site_uuid(){return '11111111-2222-4333-8444-555555555555';}
 static function current_environment(){return $GLOBALS['test_environment'] ?? 'staging';}
 static function agent_slug(){return 'chatgpt-governed-write';}
}
class WP_Application_Passwords {
 static $issued=0;
 static $revoked=0;
 static function create_new_application_password($id,$a){self::$issued++;return ['vault-only-secret',['uuid'=>'11111111-2222-4333-8444-555555555555']];}
 static function delete_application_password($id,$uuid){self::$revoked++;return true;}
}
require __DIR__.'/../includes/class-mad4b-scp-unified-agent-connection.php';
function check_uac($v,$name){if(!$v){echo 'UNIFIED_CONNECTION_RUNTIME_FAIL:'.$name.PHP_EOL;exit(1);}}
$c='MAD4B_SCP_Unified_Agent_Connection';
check_uac($c::site_ready(),'site binding');
check_uac($c::credential_available(),'secure external vault');
$GLOBALS['secure']=false;check_uac(!$c::credential_available(),'HTTPS required');$GLOBALS['secure']=true;
$f=new ReflectionMethod($c,'issue_credential');$f->setAccessible(true);
$res=$f->invoke(null,['public_id'=>'a1111111-b222-4333-8444-c55555555555']);
$file=$c::vault_path();
check_uac(!is_wp_error($res)&&is_file($file),'credential vault');
check_uac((fileperms($file)&0077)===0,'0600 file mode');
check_uac($f->invoke(null,['public_id'=>'a1111111-b222-4333-8444-c55555555555']) instanceof WP_Error,'no overwrite');
check_uac(WP_Application_Passwords::$issued===1,'no duplicate WP credential');
$rollback=new ReflectionMethod($c,'rollback_issued_credential');$rollback->setAccessible(true);
check_uac(is_wp_error($rollback->invoke(null,'wrong-uuid'))&&is_file($file)
    && WP_Application_Passwords::$revoked===0,'wrong credential rollback denied');
check_uac($rollback->invoke(null,'11111111-2222-4333-8444-555555555555')===true
    && !is_file($file)&&WP_Application_Passwords::$revoked===1,'failed activation removes exact issued secret');
// The separate Context Review delegation can never silently alter permissions.
function sanitize_title($value){return strtolower((string)$value);}
class MAD4B_SCP_Agent_Registry {
 static function get_agent_by_slug($slug){
     if(empty($GLOBALS['test_canonical_agent']) || $slug!=='chatgpt-governed-write')return null;
     return ['public_id'=>'a1111111-b222-4333-8444-c55555555555','slug'=>$slug,'status'=>'enabled','environment'=>'all'];
 }
}
class MAD4B_SCP_Context_Authority {
 static $last=null, $writes=0;
 static function set_review_policy($mode,$agent,$confirmed){
     self::$last=['mode'=>$mode,'agent'=>$agent,'confirmed'=>$confirmed];self::$writes++;
     return self::$last;
 }
}
$policy=$c::configure_review_policy('ai_only',false);
check_uac(is_wp_error($policy)&&MAD4B_SCP_Context_Authority::$writes===0,'AI-only requires explicit separate consent');
check_uac(is_wp_error($c::configure_review_policy('root',true)),'invalid policy rejected');
$GLOBALS['test_canonical_agent']=true;
$GLOBALS['test_environment']='production';
check_uac(is_wp_error($c::configure_review_policy('ai_only',true))&&MAD4B_SCP_Context_Authority::$writes===0,'production refuses delegated AI review');
$GLOBALS['test_environment']='staging';
$GLOBALS['test_canonical_agent']=false;
check_uac(is_wp_error($c::configure_review_policy('ai_only',true)),'canonical Site Profile Agent required');
$GLOBALS['test_canonical_agent']=true;
$delegated=$c::configure_review_policy('ai_only',true);
check_uac(!is_wp_error($delegated)&&$delegated['mode']==='ai_only'
    && $delegated['agent']==='a1111111-b222-4333-8444-c55555555555'
    && MAD4B_SCP_Context_Authority::$writes===1,'AI-only review delegates only through canonical authority');
$human=$c::configure_review_policy('human_only',false);
check_uac(!is_wp_error($human)&&$human['agent']===''&&MAD4B_SCP_Context_Authority::$writes===2,'human fallback independent');

rmdir($base.'/vault');rmdir($base.'/www');rmdir($base);
echo "UNIFIED_CONNECTION_RUNTIME: PASS".PHP_EOL;
