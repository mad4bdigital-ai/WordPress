<?php
/** Standalone test: ChatGPT Client default, optional background, no fake AI. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['options']=array();$GLOBALS['authorized']=true;
class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=array()){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function current_user_can($cap){return $GLOBALS['authorized'];}
function get_current_user_id(){return 2;}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower($value));}
function get_option($name,$default=false){return $GLOBALS['options'][$name]??$default;}
function update_option($name,$value,$autoload=false){$GLOBALS['options'][$name]=$value;return true;}
function has_filter($name){return false;}
class MAD4B_Content_Hub {public static function permission(){return $GLOBALS['authorized'];}}
class MAD4B_SCP_Policy {public static function can_mutate(){return $GLOBALS['authorized'];}}
class MAD4B_SCP_Operational_Integrity {
    public static function capture(){return array('scope'=>array(
        'site_uuid'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'brand_ref'=>'testbrand'));}
}
class MAD4B_SCP_Content_Jobs {
    public static function get_job($input){return array('job'=>array(
        'job_id'=>'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        'site_uuid'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'brand_id'=>'testbrand','state'=>'RUNNING','stage'=>'INFORMATION_GAIN',
        'job_revision'=>5,'automation_level'=>'autonomous'));}
}
class MAD4B_Hub_Workflow_Continuity {
    public static function status($input){return array(
        'job_id'=>'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        'job_state'=>'RUNNING','job_stage'=>'INFORMATION_GAIN');}
}
class MAD4B_SCP_Market_Content_Exchange {
    public static function assistant_route($input){return array(
        'selected_for_research_or_draft'=>array('skill_name'=>'research-provider-routing'));}
}
require dirname(__DIR__).'/includes/class-mad4b-hub-execution-router.php';
function same($actual,$expected,$label){
    if($actual!==$expected)throw new RuntimeException($label);
}
$id='bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
$input=array('operation_key'=>'owned-homepage-control-hub-e2e-20261030-v1');
$start=MAD4B_Hub_Execution_Router::status(array('job_id'=>$id));
same($start['default_executor'],'mcp_client','default_executor');
same($start['selected_executor'],'mcp_client','selected_executor');
same($start['mcp_client_default'],true,'mcp_client_default');
same($start['wpcli_fallback_requires_verified_wp_user'],true,'no_unverified_cli_impersonation');
same($start['background_ai_enabled'],false,'background_off');
same($start['content_review_mode'],'autonomous','review_independent');
$plan=MAD4B_Hub_Execution_Router::handoff($input);
same($plan['state'],'client_action_required','client_handoff');
same($plan['next_ability'],'mad4b/research-keyword-record','next_tool');
same($plan['suggested_skill'],'research-provider-routing','skill_candidate');
same($plan['backend_model_called'],false,'no_fake_model');
$denied=MAD4B_Hub_Execution_Router::select(array('job_id'=>$id,'executor_mode'=>'background_ai'));
same($denied->get_error_code(),'mad4b_hub_executor_background_ai_not_enabled','off_reject');
$enabled=MAD4B_Hub_Execution_Router::configure(array('background_ai_enabled'=>true));
same($enabled['background_ai_enabled'],true,'option_enabled');
$missing=MAD4B_Hub_Execution_Router::select(array('job_id'=>$id,'executor_mode'=>'background_ai'));
same($missing->get_error_code(),'mad4b_hub_executor_background_ai_runner_missing','runner_required');
$not_selected=MAD4B_Hub_Execution_Router::dispatch(array('job_id'=>$id));
same($not_selected->get_error_code(),'mad4b_hub_executor_background_ai_not_selected','no_dispatch');
$disabled=MAD4B_Hub_Execution_Router::configure(array('background_ai_enabled'=>false));
same($disabled['background_ai_enabled'],false,'back_to_default');
$GLOBALS['authorized']=false;
$guest=MAD4B_Hub_Execution_Router::status();
same($guest->get_error_code(),'mad4b_hub_executor_read_permission_denied','guest_denial');
echo "MCP_CLIENT_EXECUTOR_DEFAULT: PASS; optional_background_needs_registered_runner; no_AI_called\n";
