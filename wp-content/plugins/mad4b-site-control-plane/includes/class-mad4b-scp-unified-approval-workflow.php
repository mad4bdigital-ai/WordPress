<?php
if (!defined('ABSPATH'))exit;
/** Human and AI review stages backed by original-source evidence and Audit.
 * REVIEW ONLY. Completion cannot mutate content or approve execution tickets.
 */
final class MAD4B_SCP_Unified_Approval_Workflow {
 const CONTRACT='mad4b.unified-review-workflow.v1';
 const OPTION='mad4b_unified_review_workflows_v1';
 const LOCK='mad4b_unified_review_workflows_lock_v1';
 const DOMAINS=['content_job','product_catalog','seo_metadata','localization','design_template'];
 private static function load(){ $v=get_option(self::OPTION,[]);return is_array($v)?$v:[]; }
 private static function with_lock($callback){
  if(!add_option(self::LOCK,['time'=>time()],'',false))return new WP_Error('mad4b_review_locked','Review state is locked.');
  try{return call_user_func($callback);}finally{delete_option(self::LOCK);}
 }
 public static function local_scope(){
  return wp_get_environment_type()==='local' && class_exists('MAD4B_SCP_Site_Profile')
    && MAD4B_SCP_Site_Profile::configured() && MAD4B_SCP_Site_Profile::origin_enrolled()
    && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
 }
 private static function human_authorized($nonce){
  return PHP_SAPI!=='cli' && get_current_user_id()>0 && current_user_can('manage_options')
   && MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id())
   && wp_verify_nonce((string)$nonce,'mad4b_unified_review_human_decide');
 }
 private static function agent_authorized($domain){
  if(PHP_SAPI==='cli' || !class_exists('MAD4B_SCP_Identity_Context'))return false;
  $identity=MAD4B_SCP_Identity_Context::current();
  if(is_wp_error($identity)||empty($identity['authenticated'])
    ||!in_array(($identity['auth_method']??''),['wp_application_password','oauth2_bearer','mad4b_passwordless_bearer'],true))return false;
  $mode=MAD4B_SCP_Context_Authority::review_policy();
  $agent=MAD4B_SCP_Agent_Registry::resolve_agent($identity);
  if(is_wp_error($agent)||!is_array($agent)||($agent['status']??'')!=='enabled'
    ||!hash_equals((string)($mode['ai_agent_public_id']??''),(string)($agent['public_id']??'')))return false;
  $ability='mad4b/ai-'.str_replace('_','-',$domain).'-review';
  $grant=MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'],'mad4b-write',$ability,'core');
  return is_array($grant)&&($grant['effect']??'')==='allow'&&($grant['environment']??'')==='local';
 }
 private static function owner_evidence($domain,$id){
  if(!in_array($domain,self::DOMAINS,true)||!preg_match('/^(?:job:[a-f0-9-]{36}|post:[1-9][0-9]{0,9})$/D',$id))
   return new WP_Error('mad4b_review_source_invalid','Only registered original source identifiers accepted.');
  return MAD4B_SCP_AI_Native_Owner_Review::evidence($domain,['item_id'=>$id]);
 }
 public static function inspect($id){
  if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id))
   return new WP_Error('mad4b_review_id_invalid','Exact review identifier required.');
  $all=self::load();$record=$all[$id]??null;
  if(!is_array($record))return new WP_Error('mad4b_review_not_found','Unknown review.');
  // A partial/tampered option row must fail closed rather than leak PHP notices
  // or pass malformed values to hash_equals() in the original owner checks.
  if(($record['contract']??'')!==self::CONTRACT ||($record['review_id']??'')!==$id
    ||!is_string($record['domain']??null)
    ||!is_string($record['item_id']??null)
    ||!is_string($record['content_hash']??null)
    ||!preg_match('/^[a-f0-9]{64}$/D',$record['content_hash'])
    ||!is_string($record['policy_sha256']??null)
    ||!preg_match('/^[a-f0-9]{64}$/D',$record['policy_sha256'])
    ||!is_scalar($record['owner_revision']??null)
    ||!is_numeric($record['expires_at']??null)
    ||(int)$record['expires_at']<=0)
   return new WP_Error('mad4b_review_record_invalid','Stored review integrity is incomplete.');
  if(!self::local_scope()||($record['site_uuid']??'')!==MAD4B_SCP_Site_Profile::site_uuid())
   return new WP_Error('mad4b_review_scope_changed','Review scope is no longer valid.');
  $source=self::owner_evidence($record['domain'],$record['item_id']);
  if(is_wp_error($source))return $source;
  if(!is_array($source)||!is_string($source['content_hash']??null)
    ||!preg_match('/^[a-f0-9]{64}$/D',$source['content_hash'])
    ||!is_scalar($source['owner_revision']??null)
    ||!hash_equals($record['content_hash'],$source['content_hash'])
    ||(string)$source['owner_revision']!==(string)$record['owner_revision'])
   return new WP_Error('mad4b_review_source_stale','Original content changed after review opened.');
  $policy=MAD4B_SCP_Unified_Policy::resolve(['module'=>$record['domain'],'operation'=>'content_review','resource'=>$record['item_id']]);
  if(is_wp_error($policy)||!is_array($policy)
    ||!is_string($policy['policy_sha256']??null)
    ||!hash_equals($record['policy_sha256'],$policy['policy_sha256']))
   return new WP_Error('mad4b_review_policy_stale','Review policy changed.');
  if(time()>(int)$record['expires_at'])return new WP_Error('mad4b_review_expired','Review expired.');
  return $record;
 }
 public static function open($domain,$item_id,$human_nonce=''){
  if(!self::local_scope())return new WP_Error('mad4b_review_local_scope_required','Local enrolled site required.');
  if(!self::agent_authorized($domain)&&!self::human_authorized($human_nonce))
   return new WP_Error('mad4b_review_actor_unverified','Authenticated delegated Agent or enrolled human required.');
  $enrolled=get_option('mad4b_scp_ai_native_owner_review_sources_v1',[]);
  if(!is_array($enrolled)||($enrolled[$domain]??null)!==$item_id)
   return new WP_Error('mad4b_review_unenrolled_source','Original site-owner-enrolled source required.');
  $source=self::owner_evidence($domain,$item_id);if(is_wp_error($source))return $source;
  $policy=MAD4B_SCP_Unified_Policy::resolve(['module'=>$domain,'operation'=>'content_review','resource'=>$item_id]);
  if(is_wp_error($policy))return $policy;
  $site=MAD4B_SCP_Site_Profile::site_uuid();
  $id=hash('sha256',implode('|',[$site,$domain,$item_id,$source['content_hash'],(string)$source['owner_revision'],$policy['policy_sha256']]));
  return self::with_lock(function()use($id,$site,$domain,$item_id,$source,$policy){
   $all=self::load();
   if(isset($all[$id]))return $all[$id];
   if(count($all)>=100)return new WP_Error('mad4b_review_queue_full','Review queue is full.');
   $record=['contract'=>self::CONTRACT,'review_id'=>$id,'site_uuid'=>$site,
    'domain'=>$domain,'item_id'=>$item_id,'content_hash'=>$source['content_hash'],
    'owner_revision'=>$source['owner_revision'],'policy_sha256'=>$policy['policy_sha256'],
    'mode'=>$policy['mode'],'decision_rule'=>$policy['decision_rule'],
    'state'=>$policy['mode']==='human_only'?'WAITING_HUMAN':'PENDING_AI',
    'ai_decision'=>null,'human_decision'=>null,'ai_receipt'=>null,'human_actor'=>null,
    'created_at'=>time(),'expires_at'=>time()+$policy['ttl_seconds'],
    'publication_authorized'=>false,'mutation_authorized'=>false];
   $entry=MAD4B_SCP_Audit::record('mad4b/unified-review-open',[
     'review_id'=>$id,'domain'=>$domain,'source_hash'=>$source['content_hash'],
     'policy_hash'=>$policy['policy_sha256'],'publication_authorized'=>false],'ok');
   if(is_wp_error($entry))return $entry;
   $all[$id]=$record;
   if(!update_option(self::OPTION,$all,false)&&self::load()!==$all)
     return new WP_Error('mad4b_review_open_persist_failed','Failed to persist review.');
   return $record;
  });
 }
 private static function next_state($r){
  if($r['ai_decision']==='reject'||$r['human_decision']==='reject')return 'REJECTED';
  if($r['ai_decision']==='needs_changes'||$r['human_decision']==='needs_changes')return 'NEEDS_CHANGES';
  $ai=$r['ai_decision']==='approve';$human=$r['human_decision']==='approve';
  switch($r['decision_rule']){
   case 'ai_final': return $ai?'REVIEW_APPROVED':'PENDING_AI';
   case 'human_final':return $human?'REVIEW_APPROVED':'WAITING_HUMAN';
   case 'all_required':return $ai&&$human?'REVIEW_APPROVED':($ai?'WAITING_HUMAN':'PENDING_AI');
   case 'ai_then_human':return $ai&&$human?'REVIEW_APPROVED':($ai?'WAITING_HUMAN':'PENDING_AI');
   case 'risk_adaptive':return $ai&&$human?'REVIEW_APPROVED':($ai?'WAITING_HUMAN':'PENDING_AI');
  }
  return 'BLOCKED';
 }
 public static function decide_ai($review_id,$receipt,$confidence=1.0){
  if(!is_array($receipt))return new WP_Error('mad4b_review_receipt_required','Original owner receipt required.');
  return self::with_lock(function()use($review_id,$receipt,$confidence){
   $r=self::inspect($review_id);if(is_wp_error($r))return $r;
   if($r['mode']==='human_only')return new WP_Error('mad4b_review_ai_disabled','AI review is not permitted.');
   if(!self::agent_authorized($r['domain']))return new WP_Error('mad4b_review_agent_unverified','Original Agent authentication required.');
   if($r['ai_decision']!==null)return new WP_Error('mad4b_review_duplicate_ai','AI decision already recorded.');
   if($r['state']!=='PENDING_AI')
    return new WP_Error('mad4b_review_order_violation','AI review must be the first stage and the review must be pending AI.');
   $decision=(string)($receipt['decision']??'');
   if(!in_array($decision,['approve','needs_changes'],true)||!is_numeric($confidence))
    return new WP_Error('mad4b_review_decision_invalid','Invalid AI verdict.');
   $p=MAD4B_SCP_Unified_Policy::resolve(['module'=>$r['domain'],'operation'=>'content_review','resource'=>$r['item_id']]);
   if($decision==='approve'&&(float)$confidence<$p['min_confidence'])$decision='needs_changes';
   // A changed decision must not be disguised as the owner's original verdict.
   if($decision!==$receipt['decision'])return new WP_Error('mad4b_review_low_confidence','An insufficient-confidence approval needs changes.');
   $item=['item_id'=>$r['item_id'],'content_hash'=>$r['content_hash'],'owner_revision'=>$r['owner_revision']];
   $binding=['content_hash'=>$r['content_hash'],'owner_revision'=>$r['owner_revision']];
   if(MAD4B_SCP_AI_Native_Owner_Review::verify($r['domain'],$item,$receipt,$binding)!==true)
    return new WP_Error('mad4b_review_original_receipt_invalid','Original owner Audit receipt not independently valid.');
   $r['ai_decision']=$decision;$r['ai_receipt']=['event_id'=>$receipt['event_id'],'entry_hash'=>$receipt['entry_hash']];
   $r['state']=self::next_state($r);
   $audit=MAD4B_SCP_Audit::record('mad4b/unified-review-ai-stage',[
    'review_id'=>$review_id,'original_event_id'=>$receipt['event_id'],'state'=>$r['state'],
    'decision'=>$decision,'publication_authorized'=>false],'ok');
   if(is_wp_error($audit))return $audit;
   $all=self::load();$all[$review_id]=$r;
   if(!update_option(self::OPTION,$all,false)&&self::load()!==$all)
    return new WP_Error('mad4b_review_ai_persist_failed','AI stage could not persist.');
   return $r;
  });
 }
 public static function decide_human($review_id,$decision,$nonce){
  if(!self::local_scope()||!self::human_authorized($nonce))
   return new WP_Error('mad4b_review_human_unverified','Real enrolled human session and nonce required.');
  if(!in_array($decision,['approve','needs_changes','reject'],true))
   return new WP_Error('mad4b_review_human_invalid_decision','Invalid human decision.');
  return self::with_lock(function()use($review_id,$decision){
   $r=self::inspect($review_id);if(is_wp_error($r))return $r;
   if($r['mode']==='ai_only')return new WP_Error('mad4b_review_human_disabled','Human stage disabled by original AI-only mode.');
   if($r['human_decision']!==null)return new WP_Error('mad4b_review_duplicate_human','Human stage already completed.');
   if($r['state']!=='WAITING_HUMAN'
      ||($r['mode']==='human_and_ai' && $r['ai_decision']!=='approve'))
    return new WP_Error('mad4b_review_order_violation','AI approval is required before the human stage for hybrid reviews.');
   $r['human_decision']=$decision;$r['human_actor']=get_current_user_id();
   $r['state']=self::next_state($r);
   $audit=MAD4B_SCP_Audit::record('mad4b/unified-review-human-stage',[
    'review_id'=>$review_id,'human_actor'=>$r['human_actor'],'decision'=>$decision,
    'state'=>$r['state'],'publication_authorized'=>false],'ok');
   if(is_wp_error($audit))return $audit;
   $all=self::load();$all[$review_id]=$r;
   if(!update_option(self::OPTION,$all,false)&&self::load()!==$all)
    return new WP_Error('mad4b_review_human_persist_failed','Human stage could not persist.');
   return $r;
  });
 }
}
