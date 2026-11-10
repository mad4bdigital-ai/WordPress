<?php
/** Standalone Local-only Ollama Ability regression. No model/network or domain write. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['env'] = 'production';
$GLOBALS['admin'] = false;
$GLOBALS['ability_registered'] = array();
$GLOBALS['model_settings'] = array('enabled'=>false,'mode'=>'off','model'=>'');
$GLOBALS['generated_count'] = 0;
class WP_Error {
    private $code;
    public function __construct($code,$message='') {$this->code=(string)$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
function wp_get_environment_type(){return $GLOBALS['env'];}
function current_user_can($cap){return 'manage_options'===$cap && !empty($GLOBALS['admin']);}
function wp_has_ability($id){return isset($GLOBALS['ability_registered'][$id]);}
function wp_register_ability($id,$args){$GLOBALS['ability_registered'][$id]=$args;return true;}
function add_action($hook,$callback,$priority=10){}
class MAD4B_SCP_Local_AI_Governance {
    const MODEL = 'qwen2.5:1.5b';
    public static function allowed_tasks(){return array('short_classification','structured_review','general_short');}
    public static function native_agent_authorized($task){return false;}
}
class MAD4B_SCP_AI_Model_Router {
    public static function settings(){return $GLOBALS['model_settings'];}
    public static function generate_text($prompt,$override=array()){
        ++$GLOBALS['generated_count'];
        return 'fixture local model text';
    }
}
require dirname(__DIR__) . '/includes/class-mad4b-scp-local-ollama-ability.php';
function guard($yes,$why){if(!$yes){fwrite(STDERR,"FAIL local Ollama: ".$why."\n");exit(1);}}
$c='MAD4B_SCP_Local_Ollama_Ability';
$input=array('task'=>'short_classification','prompt'=>'classify example');
$c::register();
guard(!$GLOBALS['ability_registered'], 'Production registered Local Ability');
$GLOBALS['admin']=true;
guard(!$c::permission($input), 'Production admin bypassed Local fence');
guard(is_wp_error($c::execute($input)) && $GLOBALS['generated_count']===0,
    'Production executed model despite environment denial');
$GLOBALS['env']='staging';
guard(!$c::permission($input), 'Staging admin bypassed Local fence');
$GLOBALS['env']='local';
$GLOBALS['admin']=false;
guard(!$c::permission($input), 'Anonymous Local caller bypassed principal check');
guard(!$c::permission(array('task'=>'unreviewed_task','prompt'=>'x')),
    'Unreviewed task was admitted');
guard(is_wp_error($c::execute($input)) && $GLOBALS['generated_count']===0,
    'Anonymous caller invoked model');
$c::register();
guard(isset($GLOBALS['ability_registered'][$c::ABILITY]), 'Local Ability unavailable');
$ability=$GLOBALS['ability_registered'][$c::ABILITY];
guard(isset($ability['input_schema']['additionalProperties'])
    && $ability['input_schema']['additionalProperties']===false
    && $ability['meta']['annotations']['readonly']===true
    && $ability['meta']['mcp']['public']===false,
    'Local Ability schema or private MCP contract widened');
$GLOBALS['admin']=true;
guard($c::permission($input), 'Local WordPress admin with allowed task denied');
guard(is_wp_error($c::execute($input)) && $GLOBALS['generated_count']===0,
    'Disabled router was invoked');
$GLOBALS['model_settings']=array('enabled'=>true,'mode'=>'remote','model'=>MAD4B_SCP_Local_AI_Governance::MODEL);
guard(is_wp_error($c::execute($input)) && $GLOBALS['generated_count']===0,
    'Nonlocal router route executed');
$GLOBALS['model_settings']=array('enabled'=>true,'mode'=>'local_ollama','model'=>'wrong-model');
guard(is_wp_error($c::execute($input)) && $GLOBALS['generated_count']===0,
    'Unpinned Ollama model executed');
$GLOBALS['model_settings']=array('enabled'=>true,'mode'=>'local_ollama','model'=>MAD4B_SCP_Local_AI_Governance::MODEL);
guard(is_wp_error($c::execute(array('task'=>'short_classification','prompt'=>array())))
    && $GLOBALS['generated_count']===0, 'Non-string prompt executed');
$out=$c::execute($input);
guard(is_array($out)&&$GLOBALS['generated_count']===1
    && $out['contract']===$c::CONTRACT
    && $out['model']===MAD4B_SCP_Local_AI_Governance::MODEL
    && $out['domain_mutation_authorized']===false
    && $out['publication_authorized']===false,
    'Local inference returned write or publication authority');
echo "LOCAL_OLLAMA_ABILITY: PASS (hermetic, no external model calls)\n";
