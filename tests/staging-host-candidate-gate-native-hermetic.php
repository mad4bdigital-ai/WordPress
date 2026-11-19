<?php
/** Exercise original Candidate Binding host admission without a WP site/DB. */
define('ABSPATH',__DIR__.'/');
define('WP_CLI',true);
define('MAD4B_MCP_MUTATION_ENABLED',true);
class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=null){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$x));}
function get_current_user_id(){return $GLOBALS['host_uid'];}
function current_user_can($cap){return false;}
function wp_parse_url($url,$part=0){return parse_url($url,$part);}
final class MAD4B_SCP_Environment {
 public static function effective(){return $GLOBALS['host_env'];}
}
final class MAD4B_SCP_CLI_Execution_Context {
 public static function resolve($op,$apply){return $GLOBALS['host_facts'];}
}
final class MAD4B_SCP_Execution_Authority {
 public static function resolve($op){return $GLOBALS['host_actor'];}
}
final class MAD4B_SCP_Site_Profile {
 public static function site_uuid(){return '11111111-1111-4111-8111-111111111111';}
 public static function current_environment(){return $GLOBALS['host_env'];}
 public static function current_origin(){return 'https://tours.example.invalid';}
 public static function configured(){return true;}
 public static function origin_enrolled(){return true;}
 public static function site_urls_match_enrollment(){return true;}
 public static function write_enabled(){return true;}
 public static function agent_slug(){return 'chatgpt-governed-write';}
 public static function oauth_user_ids(){return $GLOBALS['host_owners'];}
 public static function user_is_enrolled($uid){return in_array($uid,$GLOBALS['host_owners'],true);}
}
final class MAD4B_SCP_Policy {
 public static function can_breakglass(){return false;}
}
final class MAD4B_SCP_Agent_Registry {
 public static function get_agent_by_slug($slug){return $GLOBALS['host_write_agent'];}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-write-candidate-binding.php';
function native_host_check($ok,$why){if(!$ok){fwrite(STDERR,"FAIL: ".$why."\n");exit(1);}}
function host_reset(){
 $GLOBALS['host_uid']=0;
 $GLOBALS['host_env']='staging';
 $GLOBALS['host_owners']=array();
 $GLOBALS['host_facts']=array('local_operation_authorized'=>true,
    'host_site_binding_verified'=>true,'blockers'=>array(),
    'production_mutation_authorized'=>false);
 $GLOBALS['host_actor']=array(
    'authenticated'=>true,'registry_bound'=>true,
    'transport'=>'host_cli','subject_type'=>'host_operator',
    'wp_user_id'=>0,'registered_host_agent_id'=>7,
    'registered_host_agent_public_id'=>'11111111-1111-4111-8111-111111111111',
    'site_uuid'=>'11111111-1111-4111-8111-111111111111',
    'subject_fingerprint'=>str_repeat('a',64)
 );
 $GLOBALS['host_write_agent']=array('id'=>11,
    'public_id'=>'22222222-2222-4222-8222-222222222222',
    'slug'=>'chatgpt-governed-write',
    'wp_user_id'=>128,'status'=>'enabled','environment'=>'staging');
}
$reflection=new ReflectionMethod('MAD4B_SCP_Staging_Write_Candidate_Binding','verified_host_actor');
$agent_ref=new ReflectionMethod('MAD4B_SCP_Staging_Write_Candidate_Binding','current_agent_or_error');
$binding_active=new ReflectionProperty('MAD4B_SCP_Staging_Write_Candidate_Binding','host_binding_active');
$host_actor_ref=new ReflectionProperty('MAD4B_SCP_Staging_Write_Candidate_Binding','host_actor');
if(PHP_VERSION_ID<80100){
    $reflection->setAccessible(true);$agent_ref->setAccessible(true);
    $binding_active->setAccessible(true);$host_actor_ref->setAccessible(true);
}
$gate=static function()use($reflection){return $reflection->invoke(null);};
$root=function_exists('posix_geteuid')&&posix_geteuid()===0;
host_reset();
if($root) {
    $actor=$gate();
    native_host_check(is_array($actor)&&$actor['wp_user_id']===0,
        'Registered Host NHI can be verified without any human WP owner.');
    $binding_active->setValue(null,true);
    $host_actor_ref->setValue(null,$actor);
    native_host_check(MAD4B_SCP_Staging_Write_Candidate_Binding::can_execute()===true,
        'Host candidate permission uses NHI rather than an OAuth user.');
    $agent=$agent_ref->invoke(null);
    native_host_check(is_array($agent)&&$agent['id']===11
        && $actor['registered_host_agent_id']===7,
        'Canonical target Write Agent is separate from Host NHI agent.');
    $binding_active->setValue(null,false);$host_actor_ref->setValue(null,array());
}
$changes=array(
 'wp_user_impersonation'=>static function(){ $GLOBALS['host_actor']['wp_user_id']=128;},
 'host_cli_user_switch'=>static function(){ $GLOBALS['host_uid']=128;},
 'production'=>static function(){ $GLOBALS['host_env']='production';},
 'missing_host_agent'=>static function(){unset($GLOBALS['host_actor']['registered_host_agent_id']);},
 'bad_host_public_id'=>static function(){ $GLOBALS['host_actor']['registered_host_agent_public_id']='bad';},
 'host_registry_not_bound'=>static function(){ $GLOBALS['host_actor']['registry_bound']=false;},
 'foreign_host_site'=>static function(){ $GLOBALS['host_actor']['site_uuid']='22222222-2222-4222-8222-222222222222';},
 'foreign_host_transport'=>static function(){ $GLOBALS['host_actor']['transport']='oauth_mcp';},
 'host_approval_scope_missing'=>static function(){ $GLOBALS['host_facts']['local_operation_authorized']=false;},
 'host_context_conflict'=>static function(){ $GLOBALS['host_facts']['blockers']=array('site_binding_mismatch');},
 'production_authorized_in_staging'=>static function(){ $GLOBALS['host_facts']['production_mutation_authorized']=true;},
);
foreach($changes as $label=>$change){
    host_reset();$change();
    native_host_check(is_wp_error($gate()),'Host binding must fail closed: '.$label);
}
host_reset();$binding_active->setValue(null,true);
$host_actor_ref->setValue(null,$GLOBALS['host_actor']);
$GLOBALS['host_write_agent']['status']='disabled';
native_host_check(is_wp_error($agent_ref->invoke(null)),
   'Canonical Write Agent must be enabled before Host binds its candidate.');
$binding_active->setValue(null,false);$host_actor_ref->setValue(null,array());
echo "PASS: HOST_CANDIDATE_NHI_GATE 15 native first-party acceptance/denial cases\n";
