<?php
define('ABSPATH',__DIR__.'/');
class WP_Error {
    private $code;
    public function __construct($c,$m='',$d=array()){$this->code=$c;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
$GLOBALS['hooks']=array();$GLOBALS['options']=array();$GLOBALS['last_remote']=null;
function add_action($name,$fn,$priority=10,$args=1){$GLOBALS['hooks'][$name]=$fn;}
function get_option($id,$fallback=array()){return $GLOBALS['options'][$id]??$fallback;}
function update_option($id,$value,$autoload=false){$GLOBALS['options'][$id]=$value;return true;}
function wp_parse_url($url){return parse_url($url);}
function wp_get_environment_type(){return $GLOBALS['lab_env']??'local';}
function wp_json_encode($x){return json_encode($x);}
function wp_remote_post($url,$args=array()){
    $GLOBALS['last_remote']=array('url'=>$url,'args'=>$args);
    return array('response'=>array('code'=>200),
        'body'=>json_encode(array('message'=>array('content'=>'{"decision":"needs_changes"}'))));
}
function wp_safe_remote_post($url,$args=array()){
    $GLOBALS['last_remote']=array('url'=>$url,'args'=>$args);
    return array('response'=>array('code'=>200),'body'=>'{"choices":[{"message":{"content":"{\\"decision\\":\\"needs_changes\\"}"}}]}');
}
function wp_remote_retrieve_response_code($x){return $x['response']['code'];}
function wp_remote_retrieve_body($x){return $x['body'];}
class FakeRegistry {
    public $items=array();
    public function is_registered($name){return isset($this->items[$name]);}
    public function register($name,$data){$this->items[$name]=$data;}
}
$GLOBALS['connectors']=array();
function wp_get_connector($id){return $GLOBALS['connectors'][$id]??null;}
function wp_get_connectors(){return $GLOBALS['connectors'];}
function _wp_connectors_get_api_key_source($name,$env='',$constant=''){
    return isset($GLOBALS['options'][$name])?'database':'none';
}
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-model-router.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-review-worker.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-source-repair.php';
$pass=0;
function ok($name,$condition){global $pass;if(!$condition){fwrite(STDERR,"FAILED $name\n");exit(1);}echo $name.": PASS\n";$pass++;}
$r=new FakeRegistry();
MAD4B_SCP_AI_Model_Router::register_connectors($r);
ok('core_registers_two_additive_routes',count($r->items)===3);
$GLOBALS['connectors']=$r->items;
ok('gemini_key_in_same_connector_registry',isset($r->items['mad4b_gemini']) && $r->items['mad4b_gemini']['authentication']['setting_name']===MAD4B_SCP_AI_Model_Router::GEMINI_SETTING);
ok('registered_without_secret_leak',empty(MAD4B_SCP_AI_Model_Router::provider_status()['secrets_exposed']));
$invalid=MAD4B_SCP_AI_Model_Router::normalize_settings(array('mode'=>'custom','custom_endpoint'=>'http://127.0.0.1/run'));
ok('reject_local_http',is_wp_error($invalid));
ok('reject_https_localhost',MAD4B_SCP_AI_Model_Router::validate_endpoint('https://localhost/v1')===false);
ok('reject_private_ip',MAD4B_SCP_AI_Model_Router::validate_endpoint('https://192.168.1.15/v1')===false);
ok('reject_embedded_secret',MAD4B_SCP_AI_Model_Router::validate_endpoint('https://u:pass@example.com/v1')===false);
ok('accept_public_custom_https',MAD4B_SCP_AI_Model_Router::validate_endpoint('https://api.example.org/v1/chat/completions')===true);
$x=MAD4B_SCP_AI_Model_Router::normalize_settings(array('mode'=>'openrouter'));
ok('official_free_router_default',$x['model']==='openrouter/free');
ok('opt_in_disabled_by_default',empty($x['enabled']));
ok('gemini_extraction_off_by_default',empty(MAD4B_SCP_AI_Model_Router::settings()['gemini_extraction_enabled']));
$local=MAD4B_SCP_AI_Model_Router::normalize_settings(array('mode'=>'local_ollama','enabled'=>true));
ok('free_local_ollama_is_fixed_open_model',!is_wp_error($local)
    &&$local['model']===MAD4B_SCP_AI_Model_Router::LOCAL_OLLAMA_MODEL);
ok('free_model_rejects_other_model',is_wp_error(
    MAD4B_SCP_AI_Model_Router::normalize_settings(array('mode'=>'local_ollama','model'=>'qwen2.5:7b'))));
$GLOBALS['lab_env']='production';
ok('free_local_route_denied_production',is_wp_error(
    MAD4B_SCP_AI_Model_Router::normalize_settings(array('mode'=>'local_ollama'))));
$GLOBALS['lab_env']='local';
$GLOBALS['options'][MAD4B_SCP_AI_Model_Router::OPTION]=$local;
ok('no_api_key_needed_for_local_fixed_model',
    MAD4B_SCP_AI_Model_Router::provider_status()['credential_ready']===true);
$result=MAD4B_SCP_AI_Model_Router::generate_text('Test review of bounded evidence');
$schema=MAD4B_SCP_AI_Model_Router::review_verdict_schema();
ok('local_fixed_review_schema_deny_extra_fields',
    ($schema['additionalProperties']??null)===false && count($schema['required'])===4);
$result=MAD4B_SCP_AI_Model_Router::generate_text('Review source',array('response_format'=>'review_verdict_v1'));
ok('local_review_inference_sends_fixed_schema',
    is_string($result)&&isset(json_decode($GLOBALS['last_remote']['args']['body'],true)['format']['properties']['evidence_lines']));
ok('local_inference_routes_only_to_loopback',
    is_string($result)&&$GLOBALS['last_remote']['url']===MAD4B_SCP_AI_Model_Router::LOCAL_OLLAMA_ENDPOINT);
ok('local_model_id_cannot_be_overridden',is_wp_error(
    MAD4B_SCP_AI_Model_Router::generate_text('Test',array('model'=>'qwen2.5:7b'))));
$GLOBALS['options'][MAD4B_SCP_AI_Model_Router::OPTION]=MAD4B_SCP_AI_Model_Router::defaults();

ok('gemini_extraction_requires_own_opt_in',MAD4B_SCP_AI_Model_Router::gemini_extraction_enabled()===false);
$GLOBALS['connectors']['google']=array('type'=>'ai_provider',
    'authentication'=>array('method'=>'api_key','setting_name'=>'connectors_ai_google_api_key'));
$GLOBALS['options']['connectors_ai_google_api_key']='native-google-fixture-only';
ok('native_google_connector_key_reused',MAD4B_SCP_AI_Model_Router::gemini_api_key()==='native-google-fixture-only');
$GLOBALS['options'][MAD4B_SCP_AI_Model_Router::GEMINI_SETTING]='fake-gemini-fixture-only';
ok('gemini_key_uses_single_connector_option',MAD4B_SCP_AI_Model_Router::gemini_api_key()==='fake-gemini-fixture-only');
$GLOBALS['options'][MAD4B_SCP_AI_Model_Router::OPTION]=array_merge(
    MAD4B_SCP_AI_Model_Router::defaults(),
    array('mode'=>'google_dedicated','model'=>'gemini-2.5-flash','enabled'=>true,'gemini_extraction_enabled'=>true));
ok('gemini_extraction_opt_in_active',MAD4B_SCP_AI_Model_Router::gemini_extraction_enabled()===true);
ok('gemini_status_uses_core_key_source',MAD4B_SCP_AI_Model_Router::provider_status()['credential_ready']===true);
$GLOBALS['options'][MAD4B_SCP_AI_Model_Router::OPTION]=MAD4B_SCP_AI_Model_Router::defaults();

$x=MAD4B_SCP_AI_Model_Router::generate_text('test prompt');
ok('disabled_inference_fail_closed',is_wp_error($x)&&$x->get_error_code()==='mad4b_ai_router_disabled');
$GLOBALS['options'][MAD4B_SCP_AI_Model_Router::OPTION]=array_merge(
    MAD4B_SCP_AI_Model_Router::defaults(),array('mode'=>'openrouter','model'=>'openrouter/free','enabled'=>true));
$x=MAD4B_SCP_AI_Model_Router::generate_text('test prompt');
ok('missing_connector_key_denied',is_wp_error($x)&&$x->get_error_code()==='mad4b_ai_connector_key_missing');
$GLOBALS['options'][MAD4B_SCP_AI_Model_Router::OPENROUTER_SETTING]='fixture-key';
$x=MAD4B_SCP_AI_Model_Router::generate_text('test prompt');
ok('openrouter_single_core_secret_used',is_string($x)&&$GLOBALS['last_remote']['url']==='https://openrouter.ai/api/v1/chat/completions');
ok('openrouter_model_routing',(json_decode($GLOBALS['last_remote']['args']['body'],true)['model']??'')==='openrouter/free');
$e=array('normalized_text'=>"Line 1\nLine 2\nLine 3\nLine 4");
$raw='{"decision":"approve","confidence":0.95,"review_note":"Lines describe the four line document, with evidence boundaries validated.","evidence_lines":[1,3]}';
$v=MAD4B_SCP_AI_Review_Worker::parse_verdict($raw,$e);
ok('valid_structured_verdict',is_array($v)&&$v['decision']==='approve');
$v=MAD4B_SCP_AI_Review_Worker::parse_verdict(str_replace('[1,3]','[1,99]',$raw),$e);
ok('reject_fake_line_reference',is_wp_error($v));
$v=MAD4B_SCP_AI_Review_Worker::parse_verdict(str_replace('0.95','0.55',$raw),$e);
ok('reject_low_confidence_approval',is_wp_error($v));
$v=MAD4B_SCP_AI_Review_Worker::parse_verdict(str_replace('approve','publish',$raw),$e);
ok('reject_publication_decision',is_wp_error($v));
$v=MAD4B_SCP_AI_Review_Worker::parse_verdict('[]',$e);
ok('reject_json_array_without_php81_helper',is_wp_error($v));
$v=MAD4B_SCP_AI_Review_Worker::parse_verdict('{}',$e);
ok('reject_empty_verdict_object',is_wp_error($v));

$old="ETG BRAND STRATEGY\nSource: catalog\nEvidence: verified\nSafe editorial context is sufficient for a review. ".str_repeat('This content is sourced and bounded. ',18);
$next=str_replace('Safe editorial context','Revised editorial context',$old);
$v=MAD4B_SCP_AI_Source_Repair::validate_revision($old,$next,'text/plain');
ok('text_revision_accepted',$v===true);
$v=MAD4B_SCP_AI_Source_Repair::validate_revision($old,str_replace('ETG BRAND STRATEGY','OTHER BRAND',$next),'text/plain');
ok('reject_source_identity_change',is_wp_error($v));
$v=MAD4B_SCP_AI_Source_Repair::validate_revision($old,$next."\nhttps://evil.test/fake-evidence",'text/plain');
ok('reject_model_invented_external_link',is_wp_error($v));
echo "SUMMARY pass=$pass fail=0\n";
echo "PASS: AI_MODEL_ROUTING_AND_VERDICT\n";
