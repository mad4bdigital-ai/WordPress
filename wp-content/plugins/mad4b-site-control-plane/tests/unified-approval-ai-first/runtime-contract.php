<?php
error_reporting(E_ALL);
define('ABSPATH','/');
$GLOBALS['options']=[];$GLOBALS['env']='local';$GLOBALS['wp_user']=0;$GLOBALS['policy']='ai_only';$GLOBALS['checks']=0;$GLOBALS['fails']=0;
class WP_Error {
 public $code;public $message;
 public function __construct($code,$message='',$details=[]) {$this->code=$code;$this->message=$message;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_option($k,$d=null){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;return true;}
function add_option($k,$v,$deprecated='',$autoload=false){if(array_key_exists($k,$GLOBALS['options']))return false;$GLOBALS['options'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['options'][$k]);return true;}
function wp_get_environment_type(){return $GLOBALS['env'];}
function wp_json_encode($x,$opts=0){return json_encode($x,$opts);}
function current_user_can($x){return (bool)$GLOBALS['wp_user'];}
function get_current_user_id(){return $GLOBALS['wp_user'];}
function wp_verify_nonce($nonce,$action){return $nonce==='valid';}
function sanitize_key($x){return preg_replace('/[^a-z0-9_-]/','',strtolower($x));}
class MAD4B_SCP_Site_Profile {
 public static function current_environment(){return $GLOBALS['env'];}
 public static function site_uuid(){return '123e4567-e89b-12d3-a456-426614174000';}
 public static function configured(){return true;}
 public static function origin_enrolled(){return true;}
 public static function site_urls_match_enrollment(){return true;}
 public static function user_is_enrolled($id){return $id===1;}
}
class MAD4B_SCP_Context_Authority {public static function review_policy(){return ['mode'=>$GLOBALS['policy'],'ai_agent_public_id'=>'123e4567-e89b-12d3-a456-426614174099'];}}
class MAD4B_SCP_Audit {public static function record($ability,$detail,$state){return ['event_id'=>'123e4567-e89b-42d3-a456-426614174001'];}}
class MAD4B_SCP_AI_Native_Owner_Review {
 public static function evidence($d,$item){
  if($item['item_id']!=='post:1')return new WP_Error('bad_source');
  return ['item_id'=>'post:1','content_hash'=>str_repeat('a',64),'owner_revision'=>7,'title'=>'Test'];
 }
 public static function verify($d,$item,$receipt,$binding){return ($receipt['entry_hash']??'')===str_repeat('b',64)
   && ($receipt['event_id']??'')==='123e4567-e89b-42d3-a456-426614174001'
   &&($item['content_hash']??'')===str_repeat('a',64);}
}
function check($label,$value){$GLOBALS['checks']++;if(!$value){$GLOBALS['fails']++;echo "FAIL $label\n";}else echo "PASS $label\n";}
require __DIR__.'/../includes/class-mad4b-scp-unified-policy.php';
require __DIR__.'/../includes/class-mad4b-scp-unified-approval-workflow.php';
require __DIR__.'/../includes/class-mad4b-scp-background-authorization.php';
require __DIR__.'/../includes/class-mad4b-scp-unified-gate-bridge.php';
check('three_original_modes',MAD4B_SCP_Unified_Policy::MODES===['human_only','human_and_ai','ai_only']);
check('five_ai_first_finality_rules',count(MAD4B_SCP_Unified_Policy::RULES)===5 && !in_array('human_then_ai',MAD4B_SCP_Unified_Policy::RULES,true));
check('new_human_first_rule_rejected',is_wp_error(MAD4B_SCP_Unified_Policy::validate_rule(['module'=>'product_catalog','operation'=>'content_review','mode'=>'human_and_ai','decision_rule'=>'human_then_ai'])));
check('new_either_first_rule_rejected',is_wp_error(MAD4B_SCP_Unified_Policy::validate_rule(['module'=>'product_catalog','operation'=>'content_review','mode'=>'human_and_ai','decision_rule'=>'either_authorized'])));
check('reject_unknown_policy_field',is_wp_error(MAD4B_SCP_Unified_Policy::validate_rule(['env'=>'local'])));
check('reject_human_rule_with_ai_mode',is_wp_error(MAD4B_SCP_Unified_Policy::validate_rule(['module'=>'product_catalog','operation'=>'content_review','mode'=>'ai_only','decision_rule'=>'human_final'])));
check('accept_valid_hybrid',!is_wp_error(MAD4B_SCP_Unified_Policy::validate_rule(['module'=>'product_catalog','operation'=>'content_review','mode'=>'human_and_ai','decision_rule'=>'ai_then_human'])));
check('reject_ttl_too_long',is_wp_error(MAD4B_SCP_Unified_Policy::validate_rule(['module'=>'product_catalog','operation'=>'content_review','mode'=>'ai_only','decision_rule'=>'ai_final','ttl_seconds'=>900000])));
check('reject_unknown_module',is_wp_error(MAD4B_SCP_Unified_Policy::resolve(['module'=>'nonexistent'])));
$qa=MAD4B_SCP_Unified_Policy::resolve(['module'=>'product_catalog','operation'=>'content_review','resource'=>'post:1']);
check('qa_inherits_ai_only',$qa['mode']==='ai_only' && $qa['decision_rule']==='ai_final');
check('qa_never_publishes',$qa['publication_authorized']===false && $qa['mutation_authorized']===false);
$pricing=MAD4B_SCP_Unified_Policy::resolve(['module'=>'pricing','operation'=>'price_change']);
check('pricing_requires_ai_then_human',$pricing['decision_rule']==='ai_then_human');
$deployment=MAD4B_SCP_Unified_Policy::resolve(['module'=>'deployment','operation'=>'apply']);
check('deployment_remains_human_only',$deployment['mode']==='human_only');
$GLOBALS['options'][MAD4B_SCP_Unified_Policy::OPTION]=['contract'=>MAD4B_SCP_Unified_Policy::CONTRACT,'revision'=>1,'rules'=>[
 ['environment'=>'local','module'=>'product_catalog','operation'=>'content_review','resource'=>'*','mode'=>'human_and_ai','decision_rule'=>'ai_then_human','min_confidence'=>0.9,'ttl_seconds'=>3600],
 ['environment'=>'local','module'=>'product_catalog','operation'=>'content_review','resource'=>'post:1','mode'=>'human_and_ai','decision_rule'=>'all_required','min_confidence'=>0.98,'ttl_seconds'=>600],
 ['environment'=>'local','module'=>'pricing','operation'=>'price_change','resource'=>'*','mode'=>'ai_only','decision_rule'=>'ai_final','min_confidence'=>0.9,'ttl_seconds'=>3600],
]];
$res=MAD4B_SCP_Unified_Policy::resolve(['module'=>'product_catalog','operation'=>'content_review','resource'=>'post:1']);
check('specific_resource_wins',$res['decision_rule']==='all_required'&&$res['ttl_seconds']===600);
$res=MAD4B_SCP_Unified_Policy::resolve(['module'=>'product_catalog','operation'=>'content_review','resource'=>'post:2']);
check('operation_policy_is_inherited',$res['decision_rule']==='ai_then_human');
$res=MAD4B_SCP_Unified_Policy::resolve(['module'=>'pricing','operation'=>'price_change']);
check('custom_policy_cannot_authorize_pricing_ai_only',$res['decision_rule']==='ai_then_human'&&$res['protected_operation']);
$GLOBALS['policy']='human_only';
$res=MAD4B_SCP_Unified_Policy::resolve(['module'=>'product_catalog','operation'=>'content_review','resource'=>'post:1']);
check('original_human_only_is_floor',$res['mode']==='human_only');
$GLOBALS['policy']='ai_only';
check('cli_cannot_modify_registry',is_wp_error(MAD4B_SCP_Unified_Policy::save([],1,'valid')));
check('cli_cannot_open_workflow',is_wp_error(MAD4B_SCP_Unified_Approval_Workflow::open('product_catalog','post:1')));
check('unknown_source_rejected',is_wp_error(MAD4B_SCP_Unified_Approval_Workflow::inspect('fake')));
$rc=new ReflectionClass('MAD4B_SCP_Unified_Approval_Workflow');$m=$rc->getMethod('next_state');$m->setAccessible(true);
$base=['decision_rule'=>'ai_final','ai_decision'=>'approve','human_decision'=>null];
check('ai_final_approves_review_only',$m->invoke(null,$base)==='REVIEW_APPROVED');
$base['ai_decision']='needs_changes';check('needs_changes_not_approved',$m->invoke(null,$base)==='NEEDS_CHANGES');
$base=['decision_rule'=>'all_required','ai_decision'=>'approve','human_decision'=>null];
check('dual_review_waits_for_human',$m->invoke(null,$base)==='WAITING_HUMAN');
$base['human_decision']='approve';check('dual_review_requires_both',$m->invoke(null,$base)==='REVIEW_APPROVED');
$base['human_decision']='reject';check('human_rejection_is_terminal',$m->invoke(null,$base)==='REJECTED');
$GLOBALS['options'][MAD4B_SCP_Unified_Policy::OPTION]['revision']=2;
$GLOBALS['options'][MAD4B_SCP_Unified_Policy::OPTION]['rules'][0]['decision_rule']='human_then_ai';
$legacy=MAD4B_SCP_Unified_Policy::resolve(['module'=>'product_catalog','operation'=>'content_review','resource'=>'post:2']);
check('legacy_human_first_policy_normalized',$legacy['decision_rule']==='ai_then_human');
check('legacy_db_record_kept_intact_on_read',($GLOBALS['options'][MAD4B_SCP_Unified_Policy::OPTION]['rules'][0]['decision_rule']??'')==='human_then_ai');
check('legacy_registry_projection_ai_first',(MAD4B_SCP_Unified_Policy::registry()['rules'][0]['decision_rule']??'')==='ai_then_human');
$GLOBALS['options'][MAD4B_SCP_Unified_Policy::OPTION]['rules'][0]['decision_rule']='either_authorized';
$legacy2=MAD4B_SCP_Unified_Policy::resolve(['module'=>'product_catalog','operation'=>'content_review','resource'=>'post:2']);
check('legacy_either_first_policy_normalized',$legacy2['decision_rule']==='ai_then_human');
$origin=MAD4B_SCP_Browser_Auth_Broker::plan(['provider'=>'sample_provider','operation'=>'read_profile','origin'=>'https://example.com']);
check('interactive_browser_needs_real_login',$origin['state']==='WAITING_AUTH'&&!$origin['execution_authorized']);
check('browser_broker_denies_model_issued_sessions',is_wp_error(MAD4B_SCP_Browser_Auth_Broker::issue_session_from_model([])));
check('unsafe_browser_origin_rejected',is_wp_error(MAD4B_SCP_Browser_Auth_Broker::plan(['provider'=>'service','operation'=>'read_profile','origin'=>'http://example.com'])));
check('oauth_not_only_supported_auth_method',in_array('wp_application_password',MAD4B_SCP_Background_Authorization::SUPPORTED,true));
check('unverified_client_auth_rejected',is_wp_error(MAD4B_SCP_Background_Authorization::current()));
check('unregistered_gate_owner_denied',is_wp_error(MAD4B_SCP_Unified_Gate_Bridge::plan(['module'=>'unknown','ability'=>'mad4b/item'])));
$gate=MAD4B_SCP_Unified_Gate_Bridge::plan(['module'=>'pricing','ability'=>'mad4b/pricing-update','operation'=>'price_change']);
check('pricing_gate_is_read_only',is_array($gate)&&$gate['read_only']&&!$gate['execution_authorized']);
check('original_ticket_contract_preserved',is_array($gate)&&$gate['next_requirement']==='original_owner_gate_required');
echo "SUMMARY checks={$GLOBALS['checks']} failures={$GLOBALS['fails']}\n";
exit($GLOBALS['fails']>0?1:0);
