<?php
/** Run with: php -S 127.0.0.1:18789 tests/http-functional.php */
define('ABSPATH','/');
$GLOBALS['options']=[];$GLOBALS['mode']='ai_only';$GLOBALS['wp_user']=0;$GLOBALS['source_hash']=str_repeat('a',64);$GLOBALS['checks']=[];
class WP_Error{private $code;public function __construct($code,$msg=''){$this->code=$code;}public function get_error_code(){return $this->code;}}
function is_wp_error($r){return $r instanceof WP_Error;}
function get_option($k,$def=null){return $GLOBALS['options'][$k]??$def;}
function add_option($k,$v,$d='',$a=false){if(isset($GLOBALS['options'][$k]))return false;$GLOBALS['options'][$k]=$v;return true;}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['options'][$k]);return true;}
function wp_get_environment_type(){return 'local';}
function wp_json_encode($v,$ops=0){return json_encode($v,$ops);}
function current_user_can($cap){return $GLOBALS['wp_user']===1;}
function get_current_user_id(){return $GLOBALS['wp_user'];}
function wp_verify_nonce($nonce,$action){return $nonce==='valid' && in_array($action,['mad4b_unified_review_human_decide','mad4b_unified_policy_update'],true);}
class MAD4B_SCP_Site_Profile{
 static function current_environment(){return 'local';}
 static function configured(){return true;}
 static function origin_enrolled(){return true;}
 static function site_urls_match_enrollment(){return true;}
 static function site_uuid(){return '123e4567-e89b-12d3-a456-426614174000';}
 static function user_is_enrolled($id){return $id===1;}
}
class MAD4B_SCP_Context_Authority{static function review_policy(){return ['mode'=>$GLOBALS['mode'],'ai_agent_public_id'=>'123e4567-e89b-12d3-a456-426614174099'];}}
class MAD4B_SCP_Identity_Context{static function current(){return ['authenticated'=>true,'auth_method'=>'wp_application_password','subject_fingerprint'=>str_repeat('b',64)];}}
class MAD4B_SCP_Agent_Registry{
 static function resolve_agent($i){return ['id'=>2,'status'=>'enabled','public_id'=>'123e4567-e89b-12d3-a456-426614174099'];}
 static function get_agent_by_public_id($id){return self::resolve_agent([]);}
 static function exact_grant($id,$server,$ability,$provider){return ['effect'=>'allow','environment'=>'local'];}
}
class MAD4B_SCP_AI_Native_Owner_Review{
 static function evidence($domain,$item){return ['content_hash'=>$GLOBALS['source_hash'],'owner_revision'=>3,'item_id'=>$item['item_id']];}
 static function verify($domain,$item,$receipt,$binding){return ($receipt['event_id']??'')==='123e4567-e89b-42d3-a456-426614174000'
   &&($receipt['entry_hash']??'')===str_repeat('c',64)
   &&($receipt['decision']??'')==='approve'
   && $item['content_hash']===$GLOBALS['source_hash'];}
}
class MAD4B_SCP_Audit{static function record($name,$data,$status){return ['event_id'=>'123e4567-e89b-42d3-a456-426614174001'];}}
function check($name,$condition){$GLOBALS['checks'][$name]=(bool)$condition;}
require __DIR__.'/../includes/class-mad4b-scp-unified-policy.php';
require __DIR__.'/../includes/class-mad4b-scp-unified-approval-workflow.php';
$GLOBALS['options']['mad4b_scp_ai_native_owner_review_sources_v1']=['product_catalog'=>'post:1'];
$receipt=['domain'=>'product_catalog','event_id'=>'123e4567-e89b-42d3-a456-426614174000',
 'entry_hash'=>str_repeat('c',64),'decision'=>'approve'];
check('unenrolled_source_denied',is_wp_error(MAD4B_SCP_Unified_Approval_Workflow::open('product_catalog','post:2')));
$opened=MAD4B_SCP_Unified_Approval_Workflow::open('product_catalog','post:1');
check('service_password_can_open_enrolled_source',is_array($opened)&&$opened['state']==='PENDING_AI');
$bad=$receipt;$bad['entry_hash']=str_repeat('d',64);
check('forged_owner_receipt_denied',is_wp_error(MAD4B_SCP_Unified_Approval_Workflow::decide_ai($opened['review_id'],$bad,0.99)));
check('low_confidence_approval_denied',is_wp_error(MAD4B_SCP_Unified_Approval_Workflow::decide_ai($opened['review_id'],$receipt,0.2)));
$result=MAD4B_SCP_Unified_Approval_Workflow::decide_ai($opened['review_id'],$receipt,0.98);
check('ai_only_original_receipt_completes',is_array($result)&&$result['state']==='REVIEW_APPROVED');
check('completed_review_never_publishes',is_array($result)&&$result['publication_authorized']===false);
check('duplicate_ai_receipt_denied',is_wp_error(MAD4B_SCP_Unified_Approval_Workflow::decide_ai($opened['review_id'],$receipt,0.98)));
$GLOBALS['mode']='human_and_ai';
$opened2=MAD4B_SCP_Unified_Approval_Workflow::open('product_catalog','post:1');
check('hybrid_default_is_ai_first',is_array($opened2)&&$opened2['decision_rule']==='ai_then_human'&&$opened2['state']==='PENDING_AI');
$GLOBALS['wp_user']=1;
check('human_cannot_preempt_ai_first',is_wp_error(MAD4B_SCP_Unified_Approval_Workflow::decide_human($opened2['review_id'],'approve','valid')));
$GLOBALS['wp_user']=1;
check('wrong_human_nonce_denied',is_wp_error(MAD4B_SCP_Unified_Approval_Workflow::decide_human($opened2['review_id'],'approve','invalid')));
$GLOBALS['wp_user']=0;
$result2=MAD4B_SCP_Unified_Approval_Workflow::decide_ai($opened2['review_id'],$receipt,0.99);
check('hybrid_ai_stage_not_final',is_array($result2)&&$result2['state']==='WAITING_HUMAN');
$GLOBALS['wp_user']=1;
$result3=MAD4B_SCP_Unified_Approval_Workflow::decide_human($opened2['review_id'],'approve','valid');
check('hybrid_human_stage_finishes',is_array($result3)&&$result3['state']==='REVIEW_APPROVED');
check('duplicate_human_denied',is_wp_error(MAD4B_SCP_Unified_Approval_Workflow::decide_human($opened2['review_id'],'approve','valid')));
$GLOBALS['source_hash']=str_repeat('f',64);
check('changed_source_invalidates_decision',is_wp_error(MAD4B_SCP_Unified_Approval_Workflow::inspect($opened2['review_id'])));
$GLOBALS['source_hash']=str_repeat('a',64);
$GLOBALS['options'][MAD4B_SCP_Unified_Policy::OPTION]=['contract'=>MAD4B_SCP_Unified_Policy::CONTRACT,'revision'=>2,'rules'=>[
 ['environment'=>'local','module'=>'product_catalog','operation'=>'content_review','resource'=>'post:1',
  'mode'=>'human_and_ai','decision_rule'=>'human_then_ai','min_confidence'=>0.9,'ttl_seconds'=>3600]
]];
$GLOBALS['wp_user']=1;
$opened3=MAD4B_SCP_Unified_Approval_Workflow::open('product_catalog','post:1','valid');
check('legacy_human_first_is_ai_first',is_array($opened3)&&$opened3['state']==='PENDING_AI'&&$opened3['decision_rule']==='ai_then_human');
$preempt=MAD4B_SCP_Unified_Approval_Workflow::decide_human($opened3['review_id'],'approve','valid');
check('legacy_human_must_wait_for_ai',is_wp_error($preempt));
$GLOBALS['wp_user']=0;
$first=MAD4B_SCP_Unified_Approval_Workflow::decide_ai($opened3['review_id'],$receipt,0.99);
check('legacy_first_ai_advances_to_human',is_array($first)&&$first['state']==='WAITING_HUMAN');
$GLOBALS['wp_user']=1;
$second=MAD4B_SCP_Unified_Approval_Workflow::decide_human($opened3['review_id'],'approve','valid');
check('legacy_ai_then_human_completes',is_array($second)&&$second['state']==='REVIEW_APPROVED');
$GLOBALS['options'][MAD4B_SCP_Unified_Policy::OPTION]['revision']=3;
$GLOBALS['options'][MAD4B_SCP_Unified_Policy::OPTION]['rules'][0]['decision_rule']='either_authorized';
$GLOBALS['wp_user']=1;
$opened4=MAD4B_SCP_Unified_Approval_Workflow::open('product_catalog','post:1','valid');
check('legacy_either_review_normalized_ai_first',is_array($opened4)&&$opened4['state']==='PENDING_AI'&&$opened4['decision_rule']==='ai_then_human');
$wrong=MAD4B_SCP_Unified_Approval_Workflow::decide_human($opened4['review_id'],'approve','valid');
check('legacy_either_human_preemption_rejected',is_wp_error($wrong));
$GLOBALS['wp_user']=0;
$ai4=MAD4B_SCP_Unified_Approval_Workflow::decide_ai($opened4['review_id'],$receipt,0.99);
check('legacy_either_ai_first',is_array($ai4)&&$ai4['state']==='WAITING_HUMAN');
$GLOBALS['wp_user']=1;
$human4=MAD4B_SCP_Unified_Approval_Workflow::decide_human($opened4['review_id'],'approve','valid');
check('legacy_either_human_second',is_array($human4)&&$human4['state']==='REVIEW_APPROVED');
check('ai_then_human_never_publishes',is_array($human4)&&$human4['publication_authorized']===false);
$GLOBALS['options'][MAD4B_SCP_Unified_Policy::OPTION]['revision']=4;
$GLOBALS['options'][MAD4B_SCP_Unified_Policy::OPTION]['rules'][0]['decision_rule']='all_required';
$GLOBALS['wp_user']=1;
$all=MAD4B_SCP_Unified_Approval_Workflow::open('product_catalog','post:1','valid');
check('all_required_opens_ai_first',is_array($all)&&$all['state']==='PENDING_AI');
check('all_required_cannot_human_preempt',is_wp_error(MAD4B_SCP_Unified_Approval_Workflow::decide_human($all['review_id'],'approve','valid')));
$GLOBALS['wp_user']=0;
$all_ai=MAD4B_SCP_Unified_Approval_Workflow::decide_ai($all['review_id'],$receipt,0.99);
check('all_required_waits_human_after_ai',is_array($all_ai)&&$all_ai['state']==='WAITING_HUMAN');
$GLOBALS['wp_user']=1;
check('all_required_approved_after_human',MAD4B_SCP_Unified_Approval_Workflow::decide_human($all['review_id'],'approve','valid')['state']==='REVIEW_APPROVED');
$GLOBALS['options'][MAD4B_SCP_Unified_Policy::OPTION]['revision']=5;
$GLOBALS['options'][MAD4B_SCP_Unified_Policy::OPTION]['rules'][0]['decision_rule']='risk_adaptive';
$risk=MAD4B_SCP_Unified_Approval_Workflow::open('product_catalog','post:1','valid');
check('risk_adaptive_opens_ai_first',is_array($risk)&&$risk['state']==='PENDING_AI');
check('risk_adaptive_cannot_human_preempt',is_wp_error(MAD4B_SCP_Unified_Approval_Workflow::decide_human($risk['review_id'],'approve','valid')));
$GLOBALS['mode']='human_only';
$human=MAD4B_SCP_Unified_Approval_Workflow::open('product_catalog','post:1','valid');
check('human_only_still_starts_with_human',is_array($human)&&$human['state']==='WAITING_HUMAN');
check('human_only_still_approves_without_ai',MAD4B_SCP_Unified_Approval_Workflow::decide_human($human['review_id'],'approve','valid')['state']==='REVIEW_APPROVED');
$GLOBALS['wp_user']=0;
check('human_only_rejects_ai',is_wp_error(MAD4B_SCP_Unified_Approval_Workflow::decide_ai($human['review_id'],$receipt,0.99)));
header('Content-Type: application/json');
echo json_encode(['passed'=>count(array_filter($GLOBALS['checks'])),'total'=>count($GLOBALS['checks']),'checks'=>$GLOBALS['checks']]);
