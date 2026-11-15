<?php
/** First-party AI task routing: local WordPress planning; no inference calls. */
define('ABSPATH', __DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
class ExpectedDeny extends Exception {}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action($name,$cb){$GLOBALS['actions'][$name]=$cb;}
function get_option($name,$default=[]){return $GLOBALS['options'][$name]??$default;}
function current_user_can($cap){return $GLOBALS['manage_options'];}
function get_current_user_id(){return 17;}
function wp_get_environment_type(){return $GLOBALS['env'];}
function wp_die($msg,$title='',$args=[]){throw new ExpectedDeny((string)$msg);}
function router_check($condition,$why){
 if(!$condition){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}
 $GLOBALS['cases']=($GLOBALS['cases']??0)+1;
}
class MAD4B_SCP_Site_Profile {
 static $enrolled=true;
 static function user_is_enrolled($id){return self::$enrolled;}
}
class MAD4B_SCP_AI_Model_Router {
 static $openai='';
 static $google='';
 static function openai_api_key(){return self::$openai;}
 static function gemini_api_key(){return self::$google;}
}
$GLOBALS['manage_options']=true;$GLOBALS['env']='production';
$GLOBALS['options']=[];$GLOBALS['actions']=[];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-ai-task-routing.php';
$c='MAD4B_SCP_AI_Task_Routing';
router_check(isset($GLOBALS['actions']['admin_post_mad4b_ai_task_routes_save']),
 'Only the original first-party admin form owns task planning updates');
$default=$c::default_profiles();
router_check($c::normalize($default)===$default,
 'Default first-party model routing is valid but disabled');
foreach($c::TASKS as $task){
 $plan=$c::plan($task);
 router_check($plan['can_execute']===false &&$plan['execution_authorized']===false
  &&$plan['requires_native_approval']===true &&$plan['read_only']===true,
  'Enabled preferences never self-issue AI API credentials or source grants');
}
router_check(is_wp_error($c::plan('arbitrary_tool_call')),
 'Unknown task cannot become an executable unregistered operation');
$bad=$default;$bad['code_proposal']['provider']='local_ollama';
router_check(is_wp_error($c::normalize($bad)),
 'A model provider not certified for this task cannot be selected');
$bad=$default;$bad['content_draft']['model']='gemini--../secret';
router_check(is_wp_error($c::normalize($bad)),
 'Model identifier cannot contain path traversal');
$bad=$default;$bad['content_draft']['enabled']='yes';
router_check(is_wp_error($c::normalize($bad)),
 'Unrecognized enabled value cannot grant an extra execution preference');
$bad=$default;$bad['content_draft']['enabled']=1;
$normalized=$c::normalize($bad);
router_check(is_array($normalized) &&$normalized['content_draft']['enabled']===true
 &&$c::plan('content_draft')['can_execute']===false,
 'WordPress checkbox preference remains a non-executing preview');
MAD4B_SCP_AI_Model_Router::$google=new WP_Error('api_key_unknown');
$GLOBALS['options'][$c::OPTION]=$normalized;
$google=$c::plan('content_draft');
router_check($google['credential_source']==='none'
 &&in_array('gemini_api_key_missing',$google['reason_codes'],true),
 'Unknown Google API credentials may not be reported as available');
MAD4B_SCP_AI_Model_Router::$google='';
MAD4B_SCP_AI_Model_Router::$openai=new WP_Error('secret_provider_error');
$openai=$c::plan('code_review');
router_check($openai['credential_source']==='none',
 'An unknown OpenAI API key provider cannot be treated as a usable credential');
foreach([new WP_Error('site_unverified'),null,false,1,'true'] as $invalid) {
 MAD4B_SCP_Site_Profile::$enrolled=$invalid;
 try { $c::save(); $denied=false;}catch(ExpectedDeny $e){$denied=true;}
 router_check($denied,
  'WP_Error/nonboolean Site Profile enrollment cannot authorize owner settings POST');
}
MAD4B_SCP_Site_Profile::$enrolled=true;
$GLOBALS['manage_options']=false;
try { $c::save(); $denied=false;}catch(ExpectedDeny $e){$denied=true;}
router_check($denied,'Non-admin WordPress user cannot change original AI routing options');
echo 'PASS: AI_TASK_ROUTING '.($GLOBALS['cases']??0).
 ' original admin enrollment and no-inference grant tests'.PHP_EOL;
