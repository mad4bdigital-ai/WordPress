<?php
define('ABSPATH','/tmp/');
class WP_Error {public function __construct(public $code,public $message=''){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action(...$args){}
function get_option($key,$default=[]){return $GLOBALS['options'][$key]??$default;}
function wp_get_environment_type(){return 'staging';}
class MAD4B_SCP_AI_Model_Router {public static function gemini_api_key(){return '';}public static function openai_api_key(){return '';}}
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-ai-task-routing.php';
function check($v,$label){if(!$v)throw new Exception($label);}
$c='MAD4B_SCP_AI_Task_Routing';
$defaults=$c::normalize([]);
check(is_array($defaults)&&count($defaults)===5,'default tasks missing');
foreach($defaults as $task=>$entry){
 check($entry['enabled']===false,'task enabled by default');
 $plan=$c::plan($task);
 check(!$plan['can_execute']&&!$plan['execution_authorized']&&$plan['read_only'],'task planning enabled execution');
}
check(is_wp_error($c::normalize(['unknown_task'=>[]])),'unknown task allowed');
check(is_wp_error($c::normalize(['content_draft'=>['provider'=>'local_ollama','model'=>'qwen2.5:1.5b','enabled'=>true]])),'cross-provider option allowed');
check(is_wp_error($c::normalize(['code_review'=>['provider'=>'openai_direct','model'=>'gpt-../../unsafe','enabled'=>true]])),'model path traversal allowed');
$GLOBALS['options'][$c::OPTION]=['code_review'=>['provider'=>'bad','model'=>'oops','enabled'=>true]];
$clean=$c::profiles();
check($clean['code_review']['enabled']===false,'corrupt persisted task enabled');
check(is_wp_error($c::plan('unregistered_action')),'unknown task admitted');
echo "AI_TASK_ROUTING_NONEXECUTION PASS disabled default, strict providers, no plan execution\n";
