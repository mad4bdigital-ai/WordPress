<?php
/** Original stored Unified Review integrity, Site Profile CAS and policy binding. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['environment']='local';$GLOBALS['bound']=true;
$GLOBALS['source_hash']=str_repeat('a',64);$GLOBALS['owner_revision']=7;
$GLOBALS['policy_hash']=str_repeat('b',64);
$GLOBALS['evidence_type']='array';$GLOBALS['policy_type']='array';
$GLOBALS['writes']=0;$GLOBALS['source_reads']=0;
class WP_Error{private $code;function __construct($code,$msg=''){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_get_environment_type(){return $GLOBALS['environment'];}
function get_option($key,$default=[]){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['writes']++;return true;}
class MAD4B_SCP_Site_Profile {
 static function configured(){return $GLOBALS['bound'];}
 static function origin_enrolled(){return $GLOBALS['bound'];}
 static function site_urls_match_enrollment(){return $GLOBALS['bound'];}
 static function site_uuid(){return '123e4567-e89b-12d3-a456-426614174000';}
}
class MAD4B_SCP_AI_Native_Owner_Review {
 static function evidence($domain,$item){
  $GLOBALS['source_reads']++;
  if($GLOBALS['evidence_type']==='wp_error')return new WP_Error('upstream_missing');
  if($GLOBALS['evidence_type']==='corrupt')return ['content_hash'=>['bad'],'owner_revision'=>null];
  return ['content_hash'=>$GLOBALS['source_hash'],'owner_revision'=>$GLOBALS['owner_revision']];
 }
}
class MAD4B_SCP_Unified_Policy {
 static function resolve($input){
  if($GLOBALS['policy_type']==='wp_error')return new WP_Error('upstream_policy_missing');
  if($GLOBALS['policy_type']==='corrupt')return ['policy_sha256'=>['bad']];
  return ['policy_sha256'=>$GLOBALS['policy_hash']];
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-unified-approval-workflow.php';
$id=str_repeat('c',64);
$r=['contract'=>'mad4b.unified-review-workflow.v1','review_id'=>$id,
 'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
 'domain'=>'product_catalog','item_id'=>'post:1',
 'content_hash'=>$GLOBALS['source_hash'],'owner_revision'=>7,
 'policy_sha256'=>$GLOBALS['policy_hash'],'state'=>'PENDING_AI',
 'expires_at'=>time()+300,'decision_rule'=>'ai_final',
 'ai_decision'=>null,'human_decision'=>null];
$GLOBALS['options']=[MAD4B_SCP_Unified_Approval_Workflow::OPTION=>[$id=>$r]];
$n=0;
function ck($name,$ok){global $n;if(!$ok){fwrite(STDERR,'FAIL '.$name.PHP_EOL);exit(1);}$n++;}
function code_is($x,$expected){return is_wp_error($x)&&$x->get_error_code()===$expected;}
$owner='MAD4B_SCP_Unified_Approval_Workflow';
$good=$owner::inspect($id);
ck('original review inspect exact record',is_array($good)&&$good['review_id']===$id);
ck('no mutation during read',$GLOBALS['writes']===0);
ck('invalid id nonstring safely denied',code_is($owner::inspect(['bad']),'mad4b_review_id_invalid'));
ck('invalid id string safely denied',code_is($owner::inspect('???'),'mad4b_review_id_invalid'));
ck('unknown hash rejected',code_is($owner::inspect(str_repeat('f',64)),'mad4b_review_not_found'));
$GLOBALS['options'][$owner::OPTION][$id]['contract']='unverified';
ck('invalid record contract denied',code_is($owner::inspect($id),'mad4b_review_record_invalid'));
$GLOBALS['options'][$owner::OPTION][$id]=$r;
$GLOBALS['options'][$owner::OPTION][$id]['review_id']=str_repeat('d',64);
ck('foreign stored review ID denied',code_is($owner::inspect($id),'mad4b_review_record_invalid'));
$GLOBALS['options'][$owner::OPTION][$id]=$r;
$GLOBALS['options'][$owner::OPTION][$id]['content_hash']=['wrong type'];
ck('malformed content hash denied',code_is($owner::inspect($id),'mad4b_review_record_invalid'));
$GLOBALS['options'][$owner::OPTION][$id]=$r;
unset($GLOBALS['options'][$owner::OPTION][$id]['owner_revision']);
ck('missing owner CAS denied',code_is($owner::inspect($id),'mad4b_review_record_invalid'));
$GLOBALS['options'][$owner::OPTION][$id]=$r;
$GLOBALS['options'][$owner::OPTION][$id]['policy_sha256']=null;
ck('missing policy hash denied',code_is($owner::inspect($id),'mad4b_review_record_invalid'));
$GLOBALS['options'][$owner::OPTION][$id]=$r;
$GLOBALS['options'][$owner::OPTION][$id]['expires_at']=null;
ck('missing expiry denied',code_is($owner::inspect($id),'mad4b_review_record_invalid'));
$GLOBALS['options'][$owner::OPTION][$id]=$r;
$GLOBALS['environment']='production';
ck('environment scope denial',code_is($owner::inspect($id),'mad4b_review_scope_changed'));
$GLOBALS['environment']='local';$GLOBALS['bound']=false;
ck('enrolled Site Profile required',code_is($owner::inspect($id),'mad4b_review_scope_changed'));
$GLOBALS['bound']=true;
$GLOBALS['options'][$owner::OPTION][$id]['site_uuid']='other';
ck('foreign site review denied',code_is($owner::inspect($id),'mad4b_review_scope_changed'));
$GLOBALS['options'][$owner::OPTION][$id]=$r;
$GLOBALS['source_hash']=str_repeat('f',64);
ck('changed original content denied',code_is($owner::inspect($id),'mad4b_review_source_stale'));
$GLOBALS['source_hash']=str_repeat('a',64);
$GLOBALS['owner_revision']=8;
ck('changed original owner revision denied',code_is($owner::inspect($id),'mad4b_review_source_stale'));
$GLOBALS['owner_revision']=7;$GLOBALS['evidence_type']='corrupt';
ck('malformed original source denied safely',code_is($owner::inspect($id),'mad4b_review_source_stale'));
$GLOBALS['evidence_type']='wp_error';
ck('original owner error kept fail closed',code_is($owner::inspect($id),'upstream_missing'));
$GLOBALS['evidence_type']='array';
$GLOBALS['policy_hash']=str_repeat('e',64);
ck('policy CAS drift denied',code_is($owner::inspect($id),'mad4b_review_policy_stale'));
$GLOBALS['policy_hash']=str_repeat('b',64);
$GLOBALS['policy_type']='corrupt';
ck('malformed policy denied safely',code_is($owner::inspect($id),'mad4b_review_policy_stale'));
$GLOBALS['policy_type']='wp_error';
ck('original policy error stays denied',code_is($owner::inspect($id),'mad4b_review_policy_stale'));
$GLOBALS['policy_type']='array';
$GLOBALS['options'][$owner::OPTION][$id]['expires_at']=time()-1;
ck('expired approval evidence denied',code_is($owner::inspect($id),'mad4b_review_expired'));
$GLOBALS['options'][$owner::OPTION][$id]=$r;
ck('original record accepted after test resets',is_array($owner::inspect($id)));
ck('no database/WordPress mutation across inspections',$GLOBALS['writes']===0);
$method=(new ReflectionClass($owner))->getMethod('next_state');$method->setAccessible(true);
ck('AI final review approval only',$method->invoke(null,['ai_decision'=>'approve','human_decision'=>null,'decision_rule'=>'ai_final'])==='REVIEW_APPROVED');
ck('human rejection terminal',$method->invoke(null,['ai_decision'=>'approve','human_decision'=>'reject','decision_rule'=>'ai_then_human'])==='REJECTED');
ck('AI-first hybrid waits for human',$method->invoke(null,['ai_decision'=>'approve','human_decision'=>null,'decision_rule'=>'ai_then_human'])==='WAITING_HUMAN');
ck('unknown policy blocks review',$method->invoke(null,['ai_decision'=>null,'human_decision'=>null,'decision_rule'=>'unknown'])==='BLOCKED');
echo 'PASS: '.$n.' original review record and AI-first state checks'.PHP_EOL;
