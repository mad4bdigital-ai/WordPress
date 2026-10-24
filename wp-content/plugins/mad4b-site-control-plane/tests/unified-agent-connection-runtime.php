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
class MAD4B_SCP_Environment {static function effective(){return 'staging';}}
class MAD4B_SCP_Site_Profile {
 static function configured(){return true;}
 static function origin_enrolled(){return true;}
 static function site_urls_match_enrollment(){return true;}
 static function site_uuid(){return '11111111-2222-4333-8444-555555555555';}
}
class WP_Application_Passwords {
 static $issued=0;
 static function create_new_application_password($id,$a){self::$issued++;return ['vault-only-secret',['uuid'=>'11111111-2222-4333-8444-555555555555']];}
 static function delete_application_password($id,$uuid){return true;}
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
unlink($file);rmdir($base.'/vault');rmdir($base.'/www');rmdir($base);
echo "UNIFIED_CONNECTION_RUNTIME: PASS".PHP_EOL;
