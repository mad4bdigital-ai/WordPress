<?php
/** Test private first-party grant plan computation without owner/REST/grant execution.
 * All dependencies are in-memory fakes; no Grant, ticket, DB or filesystem write.
 */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code='unknown',$message='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
function add_action($hook,$callback,$priority=10,$args=1){return true;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_get_environment_type(){return 'staging';}
function wp_has_ability($ability){return $GLOBALS['has_ability'];}
function wp_get_ability($ability){return new class {
 function get_input_schema(){return $GLOBALS['ability_schema'];}
};}
function current_user_can($cap){return true;}
function get_current_user_id(){return 17;}
class MAD4B_SCP_Environment {
 static $env='staging';
 static function effective(){return self::$env;}
}
class MAD4B_SCP_Site_Profile {
 static $configured=true,$origin=true,$urls=true,$managed=true,$uuid='11111111-1111-4111-8111-111111111111',
 $revision=9,$digest='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
 static function configured(){return self::$configured;}
 static function origin_enrolled(){return self::$origin;}
 static function site_urls_match_enrollment(){return self::$urls;}
 static function managed_runtime_enabled(){return self::$managed;}
 static function site_uuid(){return self::$uuid;}
 static function revision(){return self::$revision;}
 static function profile_digest(){return self::$digest;}
 static function agent_slug(){return 'approved-local-agent';}
}
class MAD4B_SCP_Audit {
 static $ready=true;
 static function storage_status(){return ['ready'=>self::$ready];}
}
class MAD4B_SCP_Servers {
 static $provider='core';
 static function provider_for_ability($server,$ability){return self::$provider;}
}
class MAD4B_SCP_Approval_Tickets {}
class MAD4B_SCP_Agent_Registry {
 static $agent=[
  'id'=>21,'public_id'=>'11111111-1111-4111-8111-111111111111',
  'status'=>'enabled','slug'=>'approved-local-agent','revision'=>7
 ];
 static $subjects=[
  ['id'=>30,'status'=>'enabled','subject_fingerprint'=>'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd']
 ];
 static $grants=[];
 static $exact;
 static function get_agent_by_public_id($id){return self::$agent;}
 static function subjects_for_agent($id){return self::$subjects;}
 static function grants_for_agent($id,$server){return self::$grants;}
 static function exact_grant($id,$server,$ability,$provider){return self::$exact;}
}
function check_grant($pass,$reason){
 if(!$pass){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
$GLOBALS['has_ability']=true;
$GLOBALS['ability_schema']=['type'=>'object','properties'=>['model'=>['type'=>'string']]];
MAD4B_SCP_Agent_Registry::$exact=new WP_Error('mad4b_nhi_grant_missing');
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-agent-grant-lifecycle.php';
$cls='MAD4B_SCP_Agent_Grant_Lifecycle';
$method=new ReflectionMethod($cls,'build');
if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$input=['agent_public_id'=>'11111111-1111-4111-8111-111111111111',
 'scope'=>'staging','reason'=>'Review single agent inference grant'];
$run=function($v)use($method){return $method->invoke(null,$v);};
$valid=$run($input);
check_grant(is_array($valid)&&strlen($valid['plan_sha256'])===64
 &&$valid['ticket_input']['scope']==='staging'
 &&$valid['basis']['ability_input_schema_sha256']==hash('sha256',json_encode($GLOBALS['ability_schema'])),
 'Original exact NHI plan requires matched site, agent, native inference and schema evidence');
check_grant($run($input)['plan_sha256']===$valid['plan_sha256'],
 'First-party grant plan SHA is deterministic before any ticket or write');
MAD4B_SCP_Agent_Registry::$agent['id']='21';
MAD4B_SCP_Agent_Registry::$agent['revision']='7';
MAD4B_SCP_Agent_Registry::$subjects[0]['id']='30';
$dbshape=$run($input);
check_grant(is_array($dbshape)&&$dbshape['plan_sha256']===$valid['plan_sha256'],
 'Original WPDB ARRAY_A decimal string IDs preserve identical exact grant plan checksum');
MAD4B_SCP_Agent_Registry::$agent['id']=21;
MAD4B_SCP_Agent_Registry::$agent['revision']=7;
MAD4B_SCP_Agent_Registry::$subjects[0]['id']=30;
foreach([new WP_Error('id_unverified'),'21garbage',true,[]] as $bad){
 MAD4B_SCP_Agent_Registry::$agent['id']=$bad;
 check_grant(is_wp_error($run($input)),
  'Unverified native Agent ID cannot be silently cast into an exact original grant');
}
MAD4B_SCP_Agent_Registry::$agent['id']=21;
foreach([new WP_Error('revision_unknown'),'7garbage',true,[]] as $bad){
 MAD4B_SCP_Agent_Registry::$agent['revision']=$bad;
 check_grant(is_wp_error($run($input)),
  'Unverified native Agent revision cannot be used in a signed CAS plan');
}
MAD4B_SCP_Agent_Registry::$agent['revision']=7;
foreach([new WP_Error('fingerprint_unknown'),'bad',1] as $bad){
 MAD4B_SCP_Agent_Registry::$subjects[0]['subject_fingerprint']=$bad;
 check_grant(is_wp_error($run($input)),
  'Unknown native subject SHA-256 cannot become an enrolled verified subject');
}
MAD4B_SCP_Agent_Registry::$subjects[0]['subject_fingerprint']=str_repeat('d',64);
MAD4B_SCP_Environment::$env='production';
$production=$run(array_replace($input,['scope'=>'all']));
check_grant(is_array($production)
 &&$production['basis']['effective_environment']==='production',
 'Exact original Production environment is eligible for source review without a static Staging requirement');
MAD4B_SCP_Environment::$env='staging';
check_grant($cls::owner()===false,
 'Original owner-only grant REST must not authorize a PHP CLI request');
foreach(['agent_public_id','scope','reason'] as $key){
 foreach([new WP_Error('field_unavailable'),[],true,1] as $bad){
  $payload=$input;$payload[$key]=$bad;
  check_grant(is_wp_error($run($payload)),
   'Original grant plan must reject non-string values before casting to signed scope');
 }
}
foreach(['configured','origin','urls'] as $field){
 foreach([new WP_Error('site_unknown'),1,'true',null] as $bad){
  MAD4B_SCP_Site_Profile::$$field=$bad;
  check_grant(is_wp_error($run($input)),
   'Unverified original Site Profile proof must not be used to issue a grant plan');
 }
 MAD4B_SCP_Site_Profile::$$field=true;
}
foreach([new WP_Error('ability_unknown'),1,'true',null] as $bad){
 $GLOBALS['has_ability']=$bad;
 check_grant(is_wp_error($run($input)),
  'Unverified original WordPress Ability registration cannot bootstrap NHI grant plan');
}
$GLOBALS['has_ability']=true;
foreach([new WP_Error('audit_unknown'),1,'true',null] as $bad){
 MAD4B_SCP_Audit::$ready=$bad;
 check_grant(is_wp_error($run($input)),
  'Unverified Audit storage health cannot authorize new exact NHI plan');
}
MAD4B_SCP_Audit::$ready=true;
foreach([new WP_Error('subjects_unknown'),null,1,'true',[
 ['id'=>new WP_Error('subject_unverified'),'status'=>'enabled',
  'subject_fingerprint'=>new WP_Error('fingerprint_unverified')]
]] as $bad){
 MAD4B_SCP_Agent_Registry::$subjects=$bad;
 check_grant(is_wp_error($run($input)),
  'Unverified subject registry must not create a valid exact NHI fingerprint');
}
MAD4B_SCP_Agent_Registry::$subjects=[
 ['id'=>30,'status'=>'enabled','subject_fingerprint'=>str_repeat('d',64)]];
foreach([new WP_Error('schema_unknown'),null,1,'true',[]] as $bad){
 $GLOBALS['ability_schema']=$bad;
 check_grant(is_wp_error($run($input)),
  'Unknown native WordPress input schema cannot create an exact grant fingerprint');
}
$GLOBALS['ability_schema']=['type'=>'object','properties'=>['model'=>['type'=>'string']]];
foreach([new WP_Error('grants_unknown'),null,1,'true'] as $bad){
 MAD4B_SCP_Agent_Registry::$grants=$bad;
 check_grant(is_wp_error($run($input)),
  'Unknown original grant inventory cannot prove absence of a conflicting allow grant');
}
MAD4B_SCP_Agent_Registry::$grants=[];
foreach([new WP_Error('env_unknown'),null,1,'true'] as $bad){
 MAD4B_SCP_Environment::$env=$bad;
 $payload=$input;$payload['scope']='all';
 check_grant(is_wp_error($run($payload)),
  'Unverified original effective environment cannot be used even for all-environment plan');
}
MAD4B_SCP_Environment::$env='staging';
foreach(['uuid','revision','digest'] as $field){
 foreach([new WP_Error('site_unknown'),[],true,null] as $bad){
  MAD4B_SCP_Site_Profile::$$field=$bad;
  check_grant(is_wp_error($run($input)),
   'Unverified original Site UUID/revision/digest cannot create an exact NHI identity proof');
 }
 MAD4B_SCP_Site_Profile::$$field=$field==='uuid'
  ?'11111111-1111-4111-8111-111111111111'
  :($field==='revision'?9:str_repeat('a',64));
}
MAD4B_SCP_Site_Profile::$revision='9';
check_grant(is_array($run($input))
 &&$run($input)['plan_sha256']===$valid['plan_sha256'],
 'Original WordPress Site Profile revision as a verified decimal string retains exact plan identity');
MAD4B_SCP_Site_Profile::$revision=9;
foreach([new WP_Error('row_unknown'),['id'=>new WP_Error('grant_identity_unknown'),
 'ability_name'=>'mad4b/ollama-inference','provider'=>'core','environment'=>'staging','effect'=>'deny']] as $bad){
 MAD4B_SCP_Agent_Registry::$grants=[$bad];
 check_grant(is_wp_error($run($input)),
  'Malformed original grant inventory rows must not be hashed as trusted absence evidence');
}
MAD4B_SCP_Agent_Registry::$grants=[];
check_grant(is_array($run($input))
 &&$run($input)['plan_sha256']===$valid['plan_sha256'],
 'Original grant plan returns to exact source state after negative probes');
echo 'PASS: AGENT_GRANT_PLAN_ORIGINAL '.($GLOBALS['checks']??0).
 ' no-ticket first-party Site, NHI, audit and schema fingerprint cases'.PHP_EOL;
