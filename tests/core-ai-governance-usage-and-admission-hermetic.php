<?php
/**
 * Original first-party WordPress Core AI model admission and per-request
 * aggregate telemetry. No model requests, HTTP, prompts, credentials or
 * persistent WordPress database access. Tests actual code not mocks of logic.
 */
define('ABSPATH',__DIR__.'/');
define('MAD4B_CORE_AI_ENFORCE',true);
$GLOBALS['ai_admin']=false;
$GLOBALS['ai_delegated']=false;
$GLOBALS['ai_hooks']=[];
$GLOBALS['ai_filters']=[];
$GLOBALS['ai_routes']=[];
$GLOBALS['ai_opts']=[];
$GLOBALS['ai_writes']=0;
$GLOBALS['ai_persist_ok']=true;
$GLOBALS['ai_audit']=[];
function add_action($name,$callback,$priority=10,$args=1){$GLOBALS['ai_hooks'][$name][]=$callback;}
function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['ai_filters'][$name][]=$callback;}
function apply_filters($name,$value,$builder=null){
 if($name==='mad4b_core_ai_execution_authorized')return $GLOBALS['ai_delegated']?true:$value;
 return $value;
}
function register_rest_route($namespace,$route,$cfg){$GLOBALS['ai_routes'][$namespace.$route]=$cfg;}
function current_user_can($cap){return $cap==='manage_options'&&$GLOBALS['ai_admin'];}
function is_admin(){return false;}
function get_option($name,$default=[]){return $GLOBALS['ai_opts'][$name]??$default;}
function update_option($name,$value,$autoload=false){
 ++$GLOBALS['ai_writes'];
 if(!$GLOBALS['ai_persist_ok'])return false;
 $GLOBALS['ai_opts'][$name]=$value;return true;
}
class WP_Error{
 private $code;
 public function __construct($code,$msg='',$data=[]){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
class MAD4B_SCP_Audit {
 public static function record($name,$row,$status){$GLOBALS['ai_audit'][]=[$name,$row,$status];return true;}
}
class FakeMetric {
 public function getId(){return 'provider-secret-with-invalid spaces';}
}
class FakeMeta {
 private $id;public function __construct($id){$this->id=$id;}
 public function getId(){return $this->id;}
}
class FakeModel {
 public function metadata(){return new FakeMeta('test-model.v1');}
 public function providerMetadata(){return new FakeMeta('safe-provider');}
}
class FakeUsage {
 private $input;private $output;
 public function __construct($in,$out){$this->input=$in;$this->output=$out;}
 public function getPromptTokens(){return $this->input;}
 public function getCompletionTokens(){return $this->output;}
}
class FakeResult {
 public function getTokenUsage(){return new FakeUsage(100,25);}
}
class FakeEvent {
 public function getModel(){return new FakeModel();}
 public function getResult(){return new FakeResult();}
}
function ai_assert($cond,$message){
 if(!$cond)throw new RuntimeException('FAIL CORE AI GOVERNANCE: '.$message);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-core-ai-governance.php';
$c='MAD4B_SCP_Core_AI_Governance';
$c::boot();
ai_assert(count($GLOBALS['ai_hooks']['rest_api_init'])===1
 && count($GLOBALS['ai_filters']['wp_ai_client_prevent_prompt'])===1
 && count($GLOBALS['ai_hooks']['wp_ai_client_before_generate_result'])===1
 && count($GLOBALS['ai_hooks']['wp_ai_client_after_generate_result'])===1,
 'Native hook ownership must remain exact and Core AI only');
ai_assert($c::enforced()===true && $c::gate(false,new stdClass())===true
 && $GLOBALS['ai_audit'][0][0]==='mad4b/core-ai-denied',
 'Enforced model request is denied without owner or approved delegated grant');
ai_assert($c::gate(true,null)===true,'Preexisting deny cannot be overruled');
$GLOBALS['ai_admin']=true;
ai_assert($c::gate(false,null)===false,'Explicit WordPress admin may proceed');
$GLOBALS['ai_admin']=false;$GLOBALS['ai_delegated']=true;
ai_assert($c::gate(false,null)===false,'Explicit delegated source filter may grant execution');
$GLOBALS['ai_delegated']=false;
$c::rest();
$route=$GLOBALS['ai_routes']['mad4b/v1core-ai/usage']??null;
ai_assert(is_array($route)&&$route['methods']==='GET'
 && call_user_func($route['permission_callback'])===false,
 'AI usage endpoint must be protected, not a public reporting data leak');
$denied=$c::usage();
ai_assert($denied instanceof WP_Error && $denied->get_error_code()==='mad4b_core_ai_usage_denied',
 'Anonymous usage read denied');
$today=gmdate('Y-m-d');
$bad=$c::record('unverified provider','any model',-1,99);
ai_assert($bad===false && $GLOBALS['ai_writes']===0,
 'Invalid usage cannot pollute counts or options');
$GLOBALS['ai_opts']['mad4b_core_ai_pricebook_v1']=[
 'contract'=>'mad4b.core-ai-pricebook.v1',
 'revision'=>'source-v1','rates'=>[
 'safe-provider|test-model.v1'=>[
  'source_url'=>'https://docs.example.invalid/verified-rates',
  'verified_at'=>$today,
  'input_usd_per_million'=>1.5,
  'output_usd_per_million'=>6.0
 ]
]];
$c::before(null);$c::after(new FakeEvent());
$key=$today.'|safe-provider|test-model.v1';
$store=$GLOBALS['ai_opts'][$c::OPTION]??[];
ai_assert(isset($store[$key])
 && $store[$key]['requests']===1
 && $store[$key]['prompt_tokens']===100
 && $store[$key]['completion_tokens']===25
 && $store[$key]['usage_unreported']===0
 && $store[$key]['estimated_cost_micro_usd']===300
 && $store[$key]['pricebook_revision']==='source-v1'
 && !isset($store[$key]['raw_prompt'])
 && !isset($store[$key]['access_token']),
 'Genuine metadata only; one microUSD-priced entry without prompts or keys');
$c::record('safe-provider','test-model.v1',null,null);
$store=$GLOBALS['ai_opts'][$c::OPTION];
ai_assert($store[$key]['requests']===2 && $store[$key]['usage_unreported']===1
 && $store[$key]['unpriced_requests']===1
 && $store[$key]['estimated_cost_micro_usd']===300,
 'Missing token usage must stay unknown, not invented or double counted');
$GLOBALS['ai_admin']=true;
$usage=$c::usage();
ai_assert($usage['cost_unit']==='micro_usd'
 && $usage['cost_is_estimate_not_invoice']===true
 && $usage['no_prompt_content']===true && $usage['no_keys']===true,
 'Usage reporting is private aggregate cost estimate only');
$status=$c::status();
ai_assert($status['mode']==='enforce'
 && $status['prompt_content_logged']===false
 && $status['secrets_logged']===false,
 'Original AI readiness cannot claim exact provider/SDK interception');
$GLOBALS['ai_persist_ok']=false;
$persisted=$GLOBALS['ai_opts'][$c::OPTION];
$result=$c::record('safe-provider','test-model.v1',10,10);
ai_assert($result===false && $GLOBALS['ai_opts'][$c::OPTION]===$persisted,
 'A failed WordPress option write must not be reported as durable metrics');
echo "PASS: CORE_AI_GOVERNANCE_NATIVE owner gate, delegated grants, aggregate only, pricebook provenance\n";
