<?php
define('ABSPATH',__DIR__.'/');
class WP_Error {private $code;public function __construct($c,$m=''){$this->code=$c;}public function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
$GLOBALS['cfg']=array();$GLOBALS['records']=array();$GLOBALS['admin']=true;
$GLOBALS['ai_mode']='ai_only';$GLOBALS['rev']=1;$GLOBALS['grants']=array();$GLOBALS['audit_ok']=true;
function get_option($name,$default=false){return $GLOBALS['cfg'][$name]??$default;}
function update_option($name,$value,$autoload=false){$different=!isset($GLOBALS['cfg'][$name]) ||$GLOBALS['cfg'][$name]!==$value;$GLOBALS['cfg'][$name]=$value;return $different;}
function add_option($name,$value,$deprecated='',$autoload=false){if(isset($GLOBALS['cfg'][$name]))return false;$GLOBALS['cfg'][$name]=$value;return true;}
function delete_option($name){unset($GLOBALS['cfg'][$name]);return true;}
function current_user_can($name){return !empty($GLOBALS['admin']);}
function get_current_user_id(){return !empty($GLOBALS['admin'])?1:0;}
function wp_verify_nonce($token,$action){return $token==='correct-nonce' &&$action==='mad4b_ai_grant_mode_change';}
function wp_get_environment_type(){return $GLOBALS['env']??'local';}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$s));}
function add_action($hook,$call){}
class MAD4B_SCP_Site_Profile {
 public static function configured(){return true;}
 public static function origin_enrolled(){return true;}
 public static function site_urls_match_enrollment(){return true;}
 public static function current_environment(){return 'local';}
 public static function site_uuid(){return '123e4567-e89b-12d3-a456-426614174000';}
 public static function agent_slug(){return 'site-ai';}
 public static function user_is_enrolled($id){return $id===1;}
}
class MAD4B_SCP_Context_Authority {
 public static function review_policy(){return array('mode'=>$GLOBALS['ai_mode'],'ai_agent_public_id'=>'987e4567-e89b-12d3-a456-426614174000');}
 public static function ai_review_policy_status(){return array('ready'=>!empty($GLOBALS['existing_native_ready']));}
}
class MAD4B_SCP_Audit {
 public static function storage_status(){return array('ready'=>!empty($GLOBALS['audit_ok']));}
 public static function record($ability,$meta,$status='ok'){
    if(empty($GLOBALS['audit_ok']))return new WP_Error('audit_failed');
    $GLOBALS['records'][]=$ability;return true;
 }
}
class MAD4B_SCP_Agent_Registry {
 public static function get_agent_by_public_id($id){
    if($id==='987e4567-e89b-12d3-a456-426614174000')return array('id'=>5,'public_id'=>$id,'status'=>'enabled','environment'=>!empty($GLOBALS['use_stage_agent'])?'staging':'local','slug'=>'site-ai');
    return false;
 }
 public static function exact_grant($agent_id,$server,$ability,$provider){
    if(!empty($GLOBALS['denied']))return new WP_Error('mad4b_nhi_grant_denied');
    foreach($GLOBALS['grants'] as $row)if($row['agent_id']===$agent_id &&$row['server_id']===$server &&$row['ability_name']===$ability)
         return $row;
    return new WP_Error('mad4b_nhi_grant_missing');
 }
 public static function grant_ability($public,$server,$ability,$provider='core',$constraints=array(),$effect='allow',$environment='all'){
   if($ability!=='mad4b/context-ai-review'||$server!=='mad4b-write'||$environment!=='local'||$constraints||$effect!=='allow')
       return new WP_Error('unsafe_grant_attempted');
   $GLOBALS['grants'][]=array('id'=>100+count($GLOBALS['grants']),'agent_id'=>5,'server_id'=>$server,
       'ability_name'=>$ability,'provider'=>'core','effect'=>'allow','environment'=>$environment);
   return true;
 }
 public static function grants_for_agent($agent_id,$server){return $GLOBALS['grants'];}
 public static function revoke_allow_grant_by_id($public,$id,$server) {
    foreach($GLOBALS['grants'] as $i=>$r)if($r['id']===$id &&$r['server_id']===$server){unset($GLOBALS['grants'][$i]);$GLOBALS['grants']=array_values($GLOBALS['grants']);return true;}
    return new WP_Error('grant_not_found');
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-grant-mode.php';
$pass=0;
function ok($msg,$cond){global $pass;if(!$cond){fwrite(STDERR,"FAIL $msg\n");exit(1);}echo "$msg: PASS\n";++$pass;}
$x=MAD4B_SCP_AI_Grant_Mode::status();
ok('default_mode_is_off',$x['mode']==='off'&&!$x['ready']);
ok('requires_enrolled_admin_nonce',is_wp_error(MAD4B_SCP_AI_Grant_Mode::apply('ai_agent_only','wrong')));
ok('does_not_grant_on_status_only',count($GLOBALS['grants'])===0);
$GLOBALS['admin']=false;
ok('nonadmin_cannot_mint',is_wp_error(MAD4B_SCP_AI_Grant_Mode::apply('ai_agent_only','correct-nonce')));
$GLOBALS['admin']=true;
$GLOBALS['ai_mode']='human_only';
ok('ai_only_review_policy_required',is_wp_error(MAD4B_SCP_AI_Grant_Mode::apply('ai_agent_only','correct-nonce')));
$GLOBALS['ai_mode']='ai_only';$GLOBALS['env']='production';
ok('production_denied',is_wp_error(MAD4B_SCP_AI_Grant_Mode::apply('ai_agent_only','correct-nonce')));
$GLOBALS['env']='local';
$GLOBALS['audit_ok']=false;
ok('audit_required_before_grant',is_wp_error(MAD4B_SCP_AI_Grant_Mode::apply('ai_agent_only','correct-nonce')));
$GLOBALS['audit_ok']=true;$GLOBALS['denied']=true;
ok('explicit_deny_remains_authoritative',is_wp_error(MAD4B_SCP_AI_Grant_Mode::apply('ai_agent_only','correct-nonce')));
$GLOBALS['denied']=false;
ok('unrecognized_privilege_mode_denied',is_wp_error(MAD4B_SCP_AI_Grant_Mode::apply('breakglass','correct-nonce')));
$x=MAD4B_SCP_AI_Grant_Mode::apply('ai_agent_only','correct-nonce');
ok('owner_selected_exact_grant_registered',is_array($x)&&$x['ready']&&count($GLOBALS['grants'])===1);
ok('only_review_ability_created',$GLOBALS['grants'][0]['ability_name']==='mad4b/context-ai-review');
ok('original_grant_id_tracked',$x['managed_grant_id']===100);
$x=MAD4B_SCP_AI_Grant_Mode::apply('ai_agent_only','correct-nonce');
ok('repeat_is_idempotent',is_array($x)&&count($GLOBALS['grants'])===1);
$x=MAD4B_SCP_AI_Grant_Mode::apply('off','correct-nonce');
ok('owner_can_revoke_only_created_grant',is_array($x)&&$x['mode']==='off'&&count($GLOBALS['grants'])===0);
$GLOBALS['grants'][]=array('id'=>109,'agent_id'=>5,'server_id'=>'mad4b-write','ability_name'=>'mad4b/context-ai-review',
 'provider'=>'core','effect'=>'allow','environment'=>'local');
$x=MAD4B_SCP_AI_Grant_Mode::apply('ai_agent_only','correct-nonce');
ok('preexisting_review_grant_not_taken_over',is_array($x)&&$x['managed_grant_id']===0);
$x=MAD4B_SCP_AI_Grant_Mode::apply('off','correct-nonce');
ok('preexisting_external_grant_not_deleted',is_array($x)&&count($GLOBALS['grants'])===1);
ok('audit_intent_and_commit_recorded',in_array('mad4b/ai-grant-mode-owner-intent',$GLOBALS['records'],true)
   &&in_array('mad4b/ai-grant-mode-enabled',$GLOBALS['records'],true));
ok('no_developer_breakglass_issued',!$x['issues_breakglass_grants']&&!$x['issues_developer_grants']);
$GLOBALS['use_stage_agent']=true;
$GLOBALS['existing_native_ready']=true;
$GLOBALS['grants'][0]['environment']='all';
$x=MAD4B_SCP_AI_Grant_Mode::apply('ai_agent_only','correct-nonce');
ok('staging_agent_existing_all_grant_accepted_local',is_array($x)&&$x['ready']&&count($GLOBALS['grants'])===1);
$x=MAD4B_SCP_AI_Grant_Mode::apply('off','correct-nonce');
ok('stage_grant_retained_by_original_owner',is_array($x)&&count($GLOBALS['grants'])===1);
$GLOBALS['grants'][0]['environment']='local';
$x=MAD4B_SCP_AI_Grant_Mode::apply('ai_agent_only','correct-nonce');
ok('staging_agent_cannot_mint_cross_env_grant',is_wp_error($x));
$GLOBALS['use_stage_agent']=false;

echo "SUMMARY pass=$pass fail=0\n";
echo "PASS: AI_ONLY_GRANT_MODE_EXACT_OWNER_CONSENT\n";
