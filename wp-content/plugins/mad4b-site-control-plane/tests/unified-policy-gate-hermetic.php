<?php
/** Network-free original Policy + Gate result; does not grant a ticket or execute. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['options']=[];
$GLOBALS['mode']='ai_only';
$GLOBALS['env']='staging';
$GLOBALS['has_ability']=false;
$GLOBALS['writes']=0;
function get_option($key,$fallback=[]){return $GLOBALS['options'][$key]??$fallback;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_get_environment_type(){return $GLOBALS['env'];}
function wp_has_ability($name){return $GLOBALS['has_ability'];}
function update_option($k,$v,$autoload=false){$GLOBALS['writes']++;return true;}
function current_user_can($cap){return false;}
class WP_Error {private $code;function __construct($code,$msg=''){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
class MAD4B_SCP_Site_Profile{static function current_environment(){return $GLOBALS['env'];}}
class MAD4B_SCP_Context_Authority{static function review_policy(){return ['mode'=>$GLOBALS['mode']];}}
class MAD4B_SCP_Content_Jobs{}
class MAD4B_SCP_Impact_Policy{
 static function classify($ability,$provider,$context=[]){
  return ['risk_tier'=>'P0','approval_required'=>true,'approval_lane'=>'human_owner'];
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-unified-policy.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-unified-gate-bridge.php';
$n=0;
function ok($s,$v){global $n;if(!$v){fwrite(STDERR,'FAIL '.$s.PHP_EOL);exit(1);}$n++;}
$pol='MAD4B_SCP_Unified_Policy';$gate='MAD4B_SCP_Unified_Gate_Bridge';
ok('three original independent modes',$pol::MODES===['human_only','human_and_ai','ai_only']);
ok('AI-first without human_then_ai',!in_array('human_then_ai',$pol::RULES,true)&&!in_array('either_authorized',$pol::RULES,true));
$content=$pol::resolve(['module'=>'content_job','operation'=>'content_review','resource'=>'job:123e4567-e89b-12d3-a456-426614174000']);
ok('AI only content is review only',$content['mode']==='ai_only'&&$content['decision_rule']==='ai_final'&&!$content['publication_authorized']&&!$content['mutation_authorized']);
ok('policy source is read-only and site aware',$content['selector']['environment']==='staging'&&$content['original_owner_required']);
$pricing=$pol::resolve(['module'=>'pricing','operation'=>'price_change']);
ok('pricing human still required',$pricing['protected_operation']&&$pricing['mode']==='human_and_ai'&&$pricing['decision_rule']==='ai_then_human');
$deploy=$pol::resolve(['module'=>'deployment','operation'=>'apply']);
ok('deployment cannot be AI only',$deploy['mode']==='human_only'&&$deploy['decision_rule']==='human_final');
$breakglass=$pol::resolve(['module'=>'breakglass','operation'=>'apply']);
ok('Breakglass requires human',$breakglass['mode']==='human_only'&&$breakglass['decision_rule']==='human_final');
$GLOBALS['mode']='human_only';
$human=$pol::resolve(['module'=>'content_job','operation'=>'content_review']);
ok('human only original floor wins',$human['mode']==='human_only');
$GLOBALS['mode']='human_and_ai';
$hybrid=$pol::resolve(['module'=>'product_catalog','operation'=>'content_review']);
ok('hybrid is AI first then human',$hybrid['decision_rule']==='ai_then_human'&&$hybrid['mode']==='human_and_ai');
$GLOBALS['mode']='ai_only';
$rule=[
'environment'=>'staging','module'=>'content_job','operation'=>'content_review',
'resource'=>'*','mode'=>'human_and_ai','decision_rule'=>'ai_then_human','min_confidence'=>0.95,'ttl_seconds'=>600];
ok('valid review-specific rule accepted',!is_wp_error($pol::validate_rule($rule)));
$invalid=$rule;$invalid['decision_rule']='human_then_ai';
ok('new human-first rule rejected',is_wp_error($pol::validate_rule($invalid)));
$invalid=$rule;$invalid['decision_rule']='either_authorized';
ok('new either-actor rule rejected',is_wp_error($pol::validate_rule($invalid)));
$invalid=$rule;$invalid['min_confidence']=1.4;
ok('invalid confidence rejected',is_wp_error($pol::validate_rule($invalid)));
$invalid=$rule;$invalid['ttl_seconds']=99;
ok('short TTL denied',is_wp_error($pol::validate_rule($invalid)));
$invalid=$rule;$invalid['escalate_grant']=true;
ok('unknown capability field rejected',is_wp_error($pol::validate_rule($invalid)));
$legacy=$rule;$legacy['decision_rule']='either_authorized';
$GLOBALS['options'][$pol::OPTION]=['contract'=>$pol::CONTRACT,'revision'=>8,'rules'=>[$legacy]];
$resolved=$pol::resolve(['module'=>'content_job','operation'=>'content_review','resource'=>'post:7']);
ok('legacy policy projected AI-first',$resolved['decision_rule']==='ai_then_human');
ok('legacy stored record unchanged', $GLOBALS['options'][$pol::OPTION]['rules'][0]['decision_rule']==='either_authorized'&&$GLOBALS['writes']===0);
ok('CLI cannot issue policy changes',is_wp_error($pol::save([],8,'nonce')));
ok('gate rejects unknown owner',is_wp_error($gate::plan(['module'=>'not_real','ability'=>'mad4b/execute'])));
ok('gate rejects invalid ability',is_wp_error($gate::plan(['module'=>'content_job','ability'=>'*'])));
ok('gate rejects unsafe provider',is_wp_error($gate::plan(['module'=>'content_job','ability'=>'mad4b/read','provider'=>'invalid%26'])));
$GLOBALS['has_ability']=true;
$p=$gate::plan(['module'=>'content_job','ability'=>'mad4b/content-review','operation'=>'content_review','resource'=>'post:7']);
ok('real owner ready but ticket not issued',is_array($p)&&$p['original_owner_class_ready']&&$p['registered_ability']
 && !$p['grant_issued']&&!$p['execution_authorized']&&!$p['publication_authorized']&&$p['read_only']);
ok('registered QA still requires original receipt',$p['next_requirement']==='original_owner_receipt_required');
$priceGate=$gate::plan(['module'=>'pricing','ability'=>'mad4b/pricing-update','operation'=>'price_change']);
ok('pricing gate still needs approval ticket',is_array($priceGate)&&$priceGate['next_requirement']==='original_approval_ticket_required'
 && $priceGate['original_ticket_required']&&$priceGate['policy_mode']==='human_and_ai');
$deployGate=$gate::plan(['module'=>'deployment','ability'=>'mad4b/deployment-apply','operation'=>'apply']);
ok('deployment gate cannot execute',$deployGate['policy_mode']==='human_only' && !$deployGate['execution_authorized']);
$GLOBALS['has_ability']=false;
$missing=$gate::plan(['module'=>'content_job','ability'=>'mad4b/content-review','operation'=>'content_review']);
ok('unregistered QA cannot imply receipt',!$missing['registered_ability']&&$missing['next_requirement']==='original_approval_ticket_required');
ok('no option mutation from plans',$GLOBALS['writes']===0);
echo 'PASS: '.$n.' original Unified Policy and Gate checks'.PHP_EOL;
