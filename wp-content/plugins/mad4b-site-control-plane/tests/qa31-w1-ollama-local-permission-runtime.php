<?php
/**
 * Original Local Ollama Ability: WP registration, environment/actor fencing,
 * pinned-model admission and non-authorizing output. No network, DB or Ollama.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['ollama_env']='production';
$GLOBALS['ollama_admin']=false;
$GLOBALS['ollama_registered']=array();
$GLOBALS['ollama_settings']=array('enabled'=>false);
$GLOBALS['ollama_calls']=array();
function add_action($hook,$handler,$priority=10) {}
function wp_get_environment_type() {return $GLOBALS['ollama_env'];}
function current_user_can($cap) {
 return $cap==='manage_options' && $GLOBALS['ollama_admin'];
}
function wp_register_ability($name,$settings) {
 $GLOBALS['ollama_registered'][$name]=$settings;
}
function wp_has_ability($name) {
 return isset($GLOBALS['ollama_registered'][$name]);
}
class WP_Error {
 private $code;
 public function __construct($code){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
class MAD4B_SCP_AI_Model_Router {
 public static function settings(){return $GLOBALS['ollama_settings'];}
 public static function generate_text($text,$options) {
  $GLOBALS['ollama_calls'][]=array($text,$options);
  return 'verified-hermetic-result';
 }
}
function qa31_local_assert($ok,$reason) {
 if(!$ok)throw new RuntimeException('FAIL Ollama authority: '.$reason);
}
require dirname(__DIR__).'/includes/class-mad4b-scp-local-ai-governance.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-local-ollama-ability.php';
$c='MAD4B_SCP_Local_Ollama_Ability';
$c::register();
qa31_local_assert(count($GLOBALS['ollama_registered'])===0,
 'Production registered Local inference Ability');
qa31_local_assert(!$c::permission(array('task'=>'short_classification')),
 'Non-local environment allowed inference');
$GLOBALS['ollama_env']='local';
$c::register();
qa31_local_assert(array_keys($GLOBALS['ollama_registered'])===array($c::ABILITY),
 'Local Ability registration is not exact');
$config=$GLOBALS['ollama_registered'][$c::ABILITY];
qa31_local_assert(($config['permission_callback']??null)===array($c,'permission'),
 'Original permission callback changed');
qa31_local_assert(($config['execute_callback']??null)===array($c,'execute'),
 'Original execute callback changed');
qa31_local_assert(($config['input_schema']['additionalProperties']??null)===false
 && ($config['input_schema']['properties']['prompt']['maxLength']??0)===4500,
 'Original closed schema or bounded prompt length changed');
qa31_local_assert(($config['input_schema']['properties']['task']['enum']??null)
 === MAD4B_SCP_Local_AI_Governance::allowed_tasks(),
 'Allowed tasks became an unreviewed static allowlist');
qa31_local_assert(!empty($config['meta']['annotations']['readonly'])
 && empty($config['meta']['annotations']['destructive'])
 && empty($config['meta']['mcp']['public']),
 'Local inference became public or destructive');
$denied=$c::execute(array('task'=>'short_classification','prompt'=>'Hello'));
qa31_local_assert(is_wp_error($denied)
 && $denied->get_error_code()==='mad4b_local_ollama_ability_denied',
 'Anonymous inference bypassed original exact grant checks');
qa31_local_assert(count($GLOBALS['ollama_calls'])===0,
 'Provider called before authorizing actor');
$GLOBALS['ollama_admin']=true;
qa31_local_assert(!$c::permission(array('task'=>'unreviewed_task')),
 'Uncertified task became authorized for administrator');
$wrong=$c::execute(array('task'=>'short_classification','prompt'=>'Hello'));
qa31_local_assert(is_wp_error($wrong)
 && $wrong->get_error_code()==='mad4b_local_ollama_pinned_router_required',
 'Unpinned/disabled router executed model request');
qa31_local_assert(!$GLOBALS['ollama_calls'],'Disabled model generated content');
$GLOBALS['ollama_settings']=array('enabled'=>true,'mode'=>'local_ollama',
 'model'=>MAD4B_SCP_Local_AI_Governance::MODEL);
$missing=$c::execute(array('task'=>'short_classification'));
qa31_local_assert(is_wp_error($missing)
 && $missing->get_error_code()==='mad4b_local_ollama_prompt_invalid',
 'Missing prompt reached provider');
$ok=$c::execute(array('task'=>'structured_review','prompt'=>'Review this original request'));
qa31_local_assert(is_array($ok) && ($ok['contract']??'')===$c::CONTRACT
 && $ok['domain_mutation_authorized']===false
 && $ok['publication_authorized']===false,
 'Model-generated text minted authority or publication');
qa31_local_assert(count($GLOBALS['ollama_calls'])===1
 && ($GLOBALS['ollama_calls'][0][1]['model']??'')===MAD4B_SCP_Local_AI_Governance::MODEL
 && ($GLOBALS['ollama_calls'][0][1]['response_format']??'')==='review_verdict_v1',
 'Original pinned model/task schema was not used');
echo "QA31_W1_LOCAL_OLLAMA_GATE: PASS\n";
