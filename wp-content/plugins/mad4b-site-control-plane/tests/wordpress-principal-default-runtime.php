<?php
/** Standalone regression for the default WP user principal; no WP install. */
define('ABSPATH', __DIR__.'/');
define('WP_CLI', true);
define('MAD4B_MCP_MUTATION_ENABLED', true);
$GLOBALS['wpprincipal'] = array(
 'cli_user'=>'2','mode'=>'delegated_user','user_id'=>2,
 'admin'=>true,'edit'=>true,'schema'=>true,'origin'=>true,'gate'=>true,'agent_lookups'=>0
);
class WP_Error {
 private $code;
 public function __construct($c,$m=''){$this->code=$c;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($r){return $r instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function get_option($k,$def=null){return $k==='mad4b_mcp_principal_mode'?$GLOBALS['wpprincipal']['mode']:$def;}
function get_current_user_id(){return $GLOBALS['wpprincipal']['user_id'];}
function get_userdata($id){return (int)$id===2?(object)array('ID'=>2,'user_login'=>'staging-admin'):false;}
function get_user_by($key,$value){return ($key==='login'&&$value==='staging-admin')?get_userdata(2):false;}
function user_can($u,$c){
 if($c==='read')return true;
 if($c==='edit_posts')return $GLOBALS['wpprincipal']['edit'];
 if($c==='manage_options')return $GLOBALS['wpprincipal']['admin'];
 return false;
}
function apply_filters($name,$value){return $value;}
function wp_get_environment_type(){return 'staging';}
class WP_CLI {
 public static function get_config($key){return $key==='user'?$GLOBALS['wpprincipal']['cli_user']:null;}
}
class MAD4B_SCP_Site_Profile {
 public static function origin_enrolled(){return $GLOBALS['wpprincipal']['origin'];}
 public static function configured(){return true;}
 public static function governed_write_ready(){return $GLOBALS['wpprincipal']['gate'];}
 public static function current_environment(){return 'staging';}
}
class MAD4B_SCP_Schema {public static function is_ready(){return $GLOBALS['wpprincipal']['schema'];}}
class MAD4B_SCP_Identity_Context {
 public static function current(){return array('authenticated'=>false,'subject_type'=>'','wp_user_id'=>get_current_user_id());}
}
class MAD4B_SCP_Agent_Registry {
 public static function resolve_agent($identity){$GLOBALS['wpprincipal']['agent_lookups']++;return new WP_Error('agent_absent');}
}
class MAD4B_SCP_Delegation_Mode_Fixture {}
require dirname(__DIR__).'/includes/class-mad4b-scp-delegation-mode.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-policy.php';
function verify($c,$message){if(!$c)throw new RuntimeException($message);}
$I=MAD4B_SCP_Identity_Context::current();
verify(MAD4B_SCP_Delegation_Mode::selected_mode()==='delegated_user','default mode');
verify(MAD4B_SCP_Delegation_Mode::native_write_abilities()===array(
    'mad4b/content-job-create','mad4b/content-job-transition','mad4b/content-job-cancel'
), 'native route set is limited to reviewed WordPress ContentJob abilities');
verify(MAD4B_SCP_Delegation_Mode::mode_for_identity($I)==='delegated_user','native mode selected');
verify(MAD4B_SCP_Policy::can_mutate(),'admin WPCLI allowed');
verify($GLOBALS['wpprincipal']['agent_lookups']===0,'native path must not look up NHI');
verify(MAD4B_SCP_Delegation_Mode::authorize_ability($I,'mad4b/content-job-create',array())===true,'contentjob capability');
$GLOBALS['wpprincipal']['admin']=false;
verify(!MAD4B_SCP_Policy::can_mutate(),'non-admin global mutation denied');
verify(is_wp_error(MAD4B_SCP_Delegation_Mode::authorize_ability($I,'mad4b/content-job-create',array())),'editor cannot use admin ContentJob');
$GLOBALS['wpprincipal']['admin']=true;
$GLOBALS['wpprincipal']['cli_user']='';
verify(MAD4B_SCP_Delegation_Mode::verified_wordpress_user($I)===null,'wp_set_current_user without explicit CLI user forbidden');
verify(!MAD4B_SCP_Policy::can_mutate(),'forged local user cannot mutate');
$GLOBALS['wpprincipal']['cli_user']='3';
verify(MAD4B_SCP_Delegation_Mode::verified_wordpress_user($I)===null,'mismatched CLI principal denied');
$GLOBALS['wpprincipal']['cli_user']='staging-admin';
verify(MAD4B_SCP_Delegation_Mode::verified_wordpress_user($I)!==null,'explicit CLI login works');
$GLOBALS['wpprincipal']['cli_user']='2';
$GLOBALS['wpprincipal']['mode']='independent_nhi';
verify(MAD4B_SCP_Delegation_Mode::verified_wordpress_user($I)===null,'explicit NHI mode keeps WordPress lane closed');
verify(!MAD4B_SCP_Policy::can_mutate(),'independent NHI requires actual Agent');
$GLOBALS['wpprincipal']['mode']='delegated_user';
$GLOBALS['wpprincipal']['gate']=false;
verify(!MAD4B_SCP_Policy::can_mutate(),'site mutation gate still enforced');
$GLOBALS['wpprincipal']['gate']=true;
$GLOBALS['wpprincipal']['origin']=false;
verify(!MAD4B_SCP_Policy::can_mutate(),'site origin mismatch denied');
$GLOBALS['wpprincipal']['origin']=true;
$untrusted=array('authenticated'=>true,'subject_type'=>'webhook','wp_user_id'=>2);
verify(MAD4B_SCP_Delegation_Mode::verified_wordpress_user($untrusted)===null,'untrusted request subject denied');
echo "WORDPRESS_PRINCIPAL_DEFAULT: PASS (explicit CLI, cap, native/NHI isolation, site gate, no principal spoof)\n";
