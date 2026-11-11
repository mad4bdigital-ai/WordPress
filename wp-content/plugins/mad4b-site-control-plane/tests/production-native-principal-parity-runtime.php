<?php
/** Hermetic Production native WP principal regression. Zero persistent writes. */
define('ABSPATH', __DIR__ . '/');
define('WP_CLI', ($argv[1] ?? '') !== 'session');
if (($argv[1] ?? '') === 'hard_kill') define('MAD4B_MCP_MUTATION_ENABLED', false);
$GLOBALS['env']='production';$GLOBALS['cli_user']='7';$GLOBALS['user_id']=7;
$GLOBALS['admin']=true;$GLOBALS['enrolled']=true;$GLOBALS['origin']=true;
$GLOBALS['write']=true;$GLOBALS['schema']=true;$GLOBALS['identity']=[];$GLOBALS['writes']=0;$GLOBALS['session_valid']=true;
function sanitize_key($s){return strtolower(preg_replace("/[^a-z0-9_-]/i","",(string)$s));}
function absint($v){return abs((int)$v);}
function get_option($key,$default=null){return $default;}
function get_current_user_id(){return $GLOBALS['user_id'];}
function get_userdata($id){return $id===7?(object)['ID'=>7]:null;}
function get_user_by($what,$value){return $what==='login' && $value==='demo_admin'?get_userdata(7):null;}
function wp_validate_auth_cookie($cookie,$scheme){return $GLOBALS['session_valid'] ? 7 : 0;}
function user_can($user,$cap){return $cap==='read' || ($cap==='manage_options' && $GLOBALS['admin']);}
function apply_filters($hook,$value){return $value;}
function is_wp_error($r){return $r instanceof WP_Error;}
class WP_Error{function __construct($code='error'){$this->code=$code;}}
class WP_CLI{static function get_config($name){return $name==='user'?$GLOBALS['cli_user']:null;}}
class MAD4B_SCP_Site_Profile{
 static function configured(){return true;}
 static function governed_write_ready(){return $GLOBALS['write'] && $GLOBALS['origin'];}
 static function current_environment(){return $GLOBALS['env'];}
 static function origin_enrolled(){return $GLOBALS['origin'];}
 static function user_is_enrolled($id){return $id===7 && $GLOBALS['enrolled'];}
}
class MAD4B_SCP_Governed_Runtime_Gates{
 static function production_mutation_enabled(){return false;}
 static function production_auto_enable(){return false;}
}
class MAD4B_SCP_Schema{static function is_ready(){return $GLOBALS['schema'];}}
class MAD4B_SCP_Identity_Context{static function current(){return $GLOBALS['identity'];}}
class MAD4B_SCP_Agent_Registry{static function resolve_agent($identity){return null;}}
class MAD4B_SCP_OAuth_Resource_Bridge{
 static function verified_bearer_active(){return true;}
 static function verified_bearer_has_scope($scope){return $scope==='mad4b:read';}
}
class MAD4B_SCP_OAuth_Subject_User_Bridge{
 static function mapped_context(){return ['authenticated'=>true,'wp_user_id'=>7,'subject_fingerprint'=>'verified-token'];}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-policy.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-execution-commit-guard.php';
function sanitize_text_field($value){return trim((string)$value);}
$guard=new ReflectionMethod('MAD4B_SCP_Execution_Commit_Guard','actor_mutation_gate_status');
$guard->setAccessible(true);
$kill=new ReflectionMethod('MAD4B_SCP_Execution_Commit_Guard','kill_switch_material');
$kill->setAccessible(true);
$n=0;function ok($what,$valid){global $n;if(!$valid){fwrite(STDERR,'FAIL '.$what."\n");exit(10);} $n++;echo 'PASS '.$what."\n";}
$cls='MAD4B_SCP_Policy';
$gate=$cls::mutation_gate_status();
ok('production_remote_gate_remains_disabled',!$gate['effective'] && !$gate['production_mutation_enabled']);
if (!defined('MAD4B_MCP_MUTATION_ENABLED')) {
 $projected=$guard->invoke(null);
 ok('native_production_guard_matches_policy',!empty($projected['effective']) && $projected['source']==='verified_native_wordpress_production');
 $switch=$kill->invoke(null,'seo/update-meta','rankmath',[],[]);
 ok('native_commit_guard_not_killed',is_array($switch) && empty($switch['active']));
}
if (defined('MAD4B_MCP_MUTATION_ENABLED')) {
 ok('explicit_global_kill_switch_wins',!$cls::can_mutate());
 ok('kill_switch_reflected_at_commit_guard',!empty($kill->invoke(null,'seo/update-meta','rankmath',[],[])['active']));
 ok('kill_switch_no_state_mutations',$GLOBALS['writes']===0);
 echo json_encode(['state'=>'PASS','checks'=>$n,'mode'=>'hard_kill'])."\n";
 exit;
}
if (($argv[1] ?? '')==='session') {
 ok('verified_wordpress_cookie_admin_can_write_without_extra_oauth',$cls::can_mutate());
 $GLOBALS['session_valid']=false;
 ok('forged_wp_user_without_valid_cookie_denied',!$cls::can_mutate());
 $GLOBALS['session_valid']=true;$GLOBALS['admin']=false;
 ok('non_admin_cookie_denied',!$cls::can_mutate());
 ok('cookie_session_never_mints_grants',$GLOBALS['writes']===0);
 echo json_encode(['state'=>'PASS','checks'=>$n,'mode'=>'native_cookie_session'])."\n";
 exit;
}
ok('enrolled_native_wpcli_admin_can_write_without_oauth',$cls::can_mutate()===true);
$GLOBALS['cli_user']='';ok('no_cli_user_must_not_impersonate_admin',!$cls::can_mutate());
$GLOBALS['cli_user']='8';ok('mismatched_cli_user_denied',!$cls::can_mutate());
$GLOBALS['cli_user']='7';$GLOBALS['identity']=['authenticated'=>true,'subject_type'=>'oauth','auth_method'=>'oauth2_bearer','origin'=>'mcp'];
ok('remote_oauth_identity_cannot_borrow_native_exception',!$cls::can_mutate());
$GLOBALS['identity']=['authenticated'=>true,'subject_type'=>'oauth','auth_method'=>'oauth2_bearer','origin'=>'mcp','wp_user_id'=>7,'subject_fingerprint'=>'verified-token','token_scopes'=>['mad4b:read']];
ok('valid_oauth_mapped_wp_user_still_requires_remote_production_gate',!$cls::can_mutate());
ok('mapped_remote_principal_does_not_open_commit_guard',empty($guard->invoke(null)['effective']));
$remote_switch=$kill->invoke(null,'seo/update-meta','rankmath',[],[]);
ok('remote_production_commit_guard_remains_killed',!empty($remote_switch['active']));
$GLOBALS['identity']=[];$GLOBALS['enrolled']=false;ok('native_wp_admin_does_not_require_separate_agent_enrollment',$cls::can_mutate());
$GLOBALS['enrolled']=true;$GLOBALS['admin']=false;ok('reader_without_manage_options_denied',!$cls::can_mutate());
$GLOBALS['admin']=true;$GLOBALS['write']=false;ok('site_write_feature_off_denied',!$cls::can_mutate());
$GLOBALS['write']=true;$GLOBALS['origin']=false;ok('non_enrolled_origin_denied',!$cls::can_mutate());
$GLOBALS['origin']=true;$GLOBALS['schema']=false;ok('schema_not_ready_denied',!$cls::can_mutate());
$GLOBALS['schema']=true;$GLOBALS['env']='staging';ok('staging_admin_baseline_retained',$cls::can_mutate());
$GLOBALS['write']=false;ok('staging_write_off_denied',!$cls::can_mutate());
ok('test_did_not_issue_grants_or_write_options',$GLOBALS['writes']===0);
echo json_encode(['state'=>'PASS','checks'=>$n,'mode'=>'production_native_parity'])."\n";
