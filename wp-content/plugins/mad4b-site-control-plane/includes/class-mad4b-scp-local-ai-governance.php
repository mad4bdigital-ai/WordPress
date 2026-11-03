<?php
if (!defined('ABSPATH')) exit;
/**
 * Local direct-Ollama guard and prompt-free request telemetry.
 * Native action grants remain owned by MAD4B authorization, never by model output.
 */
final class MAD4B_SCP_Local_AI_Governance {
 const CONTRACT='mad4b.local-ai-governance.v1';
 const OPTION='mad4b_local_ollama_daily_metrics_v1';
 const MODEL='qwen2.5:1.5b';
 private static $limits=array(
  'short_classification'=>2400,
  'structured_review'=>4500,
  'general_short'=>3500,
 );
 public static function allowed_tasks(){return array_keys(self::$limits);}
 public static function native_agent_authorized($task) {
  // A task that can NEVER be authorized must fail before expensive native
  // Ability discovery. This preserves exact deny-first task authority and
  // avoids fetching the entire ability inventory on every anonymous page.
  if($task!=='short_classification')return false;
  // Identity is extracted by the original authenticated transport context,
  // never accepted from prompt/override/HTTP caller-supplied fields.
  if(!class_exists('MAD4B_SCP_Identity_Context')||!class_exists('MAD4B_SCP_Agent_Registry'))return false;
  // A stale/raw database grant is never enough: WordPress must know the
  // read-only Ability and its exact, certified server/provider binding.
  if(!function_exists('wp_has_ability') || !wp_has_ability('mad4b/ollama-inference')
    || !class_exists('MAD4B_SCP_Servers')
    || MAD4B_SCP_Servers::provider_for_ability('mad4b-read','mad4b/ollama-inference')!=='core')return false;
  if(!class_exists('MAD4B_SCP_Site_Profile') || !MAD4B_SCP_Site_Profile::configured()
    || !MAD4B_SCP_Site_Profile::origin_enrolled()
    || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())return false;
  $identity=MAD4B_SCP_Identity_Context::current();
  if(is_wp_error($identity)||!is_array($identity)||empty($identity['authenticated']))return false;
  $agent=MAD4B_SCP_Agent_Registry::resolve_agent($identity);
  if(is_wp_error($agent)||!is_array($agent)||($agent['status']??'')!=='enabled'
    || (string)($agent['slug']??'')!==MAD4B_SCP_Site_Profile::agent_slug())return false;
  // One exact read-only model invocation grant, environment scoped and deny first.
  // It does not convey approval, publication, source edits or domain mutations.
  $grant=MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'],
   'mad4b-read','mad4b/ollama-inference','core');
  // Agent Registry resolves current effective environment or 'all', deny-first.
  // The inference transport may be local without requiring a local-only grant.
  if(is_wp_error($grant)||!is_array($grant)||($grant['effect']??'')!=='allow')return false;
  // Only short_classification reaches this point, after full native identity
  // and exact, deny-first grant verification.
  return true;
 }
 public static function policy($prompt,$override=null) {
  if (!function_exists('wp_get_environment_type') || wp_get_environment_type()!=='local')
   return new WP_Error('mad4b_local_ai_environment_denied');
  if (!is_string($prompt)||trim($prompt)==='')return new WP_Error('mad4b_local_ai_prompt_denied');
  if ($override!==null && (!is_array($override)||array_diff(array_keys($override),array('task','model','response_format'))))
   return new WP_Error('mad4b_local_ai_unknown_override');
  $task=is_array($override)?(string)($override['task']??'general_short'):'general_short';
  if (!isset(self::$limits[$task]))return new WP_Error('mad4b_local_ai_task_not_certified');
  if(strlen($prompt)>self::$limits[$task])return new WP_Error('mad4b_local_ai_prompt_too_long');
  if (($override['response_format']??'')!=='' &&
       ($task!=='structured_review'||$override['response_format']!=='review_verdict_v1'))
   return new WP_Error('mad4b_local_ai_response_format_denied');
  if ($task==='structured_review' && ($override['response_format']??'')!=='review_verdict_v1')
   return new WP_Error('mad4b_local_ai_structured_schema_required');
  // Either an actual WordPress administrator or an authenticated native
  // agent identity with the exact Local inference ability. Never bypass on
  // CLI/cron or by a caller-provided identity/boolean/filter.
  $is_manual_admin=current_user_can('manage_options');
  if(!$is_manual_admin && !self::native_agent_authorized($task))
   return new WP_Error('mad4b_local_ai_native_authority_required');
  // Admission must be committed to the native append-only chain BEFORE
  // any call to the Ollama transport. Missing audit = no inference.
  if (!class_exists('MAD4B_SCP_Audit') || !method_exists('MAD4B_SCP_Audit','record')
      || !method_exists('MAD4B_SCP_Audit','storage_status'))
   return new WP_Error('mad4b_local_ai_audit_unavailable');
  try {
   $storage=MAD4B_SCP_Audit::storage_status();
   if(!is_array($storage) || empty($storage['ready']))
    return new WP_Error('mad4b_local_ai_audit_unavailable');
   $receipt=MAD4B_SCP_Audit::record('mad4b/local-ai-inference-admit',array(
    'contract'=>self::CONTRACT,'provider'=>'local_ollama','model'=>self::MODEL,
    'task'=>$task,'execution'=>$is_manual_admin?'wp_admin':'native_agent'
   ),'ok');
   if(is_wp_error($receipt) || !is_array($receipt))
    return new WP_Error('mad4b_local_ai_audit_receipt_required');
  } catch(Throwable $ignored) {
   return new WP_Error('mad4b_local_ai_audit_receipt_required');
  }
  return array('task'=>$task,'model'=>self::MODEL,'max_prompt_bytes'=>self::$limits[$task],
   'release_authority'=>false,'may_write_domain_data'=>false);
 }
 public static function metric($task,$status,$elapsed_ms,$input=null,$output=null,$reason='') {
  if(!in_array($task,self::allowed_tasks(),true))$task='denied';
  $status=$status==='ok'?'ok':'error';
  $ms=max(0,min(110000,(int)$elapsed_ms));
  $reason=is_string($reason)&&preg_match('/^mad4b_[a-z0-9_]{1,86}$/D',$reason)?$reason:'none';
  $key=gmdate('Y-m-d').'|'.$task.'|'.$status;
  $all=get_option(self::OPTION,array());
  if(!is_array($all))$all=array();
  $row=$all[$key]??array('requests'=>0,'total_duration_ms'=>0,'max_duration_ms'=>0,
   'prompt_tokens'=>0,'completion_tokens'=>0,'usage_unreported'=>0);
  $row['requests']=min(1000000,(int)$row['requests']+1);
  $row['total_duration_ms']=min(1000000000,(int)$row['total_duration_ms']+$ms);
  $row['max_duration_ms']=max((int)$row['max_duration_ms'],$ms);
  if(is_int($input)&&$input>=0&&is_int($output)&&$output>=0){
   $row['prompt_tokens']=min(1000000000,(int)$row['prompt_tokens']+$input);
   $row['completion_tokens']=min(1000000000,(int)$row['completion_tokens']+$output);
  } else $row['usage_unreported']=min(1000000,(int)$row['usage_unreported']+1);
  if($status==='error')$row['last_error_code']=$reason;
  $all[$key]=$row;
  $cutoff=gmdate('Y-m-d',time()-30*86400);
  foreach($all as $k=>$v)if(substr((string)$k,0,10)<$cutoff)unset($all[$k]);
  krsort($all,SORT_STRING);
  update_option(self::OPTION,array_slice($all,0,100,true),false);
  // Append-only event: bounded operational metadata only. Never include prompt,
  // model text, credentials or identifying actor details in the audit summary.
  if(class_exists('MAD4B_SCP_Audit')) {
   MAD4B_SCP_Audit::record('mad4b/local-ai-inference',array(
    'provider'=>'local_ollama','model'=>self::MODEL,'task'=>$task,
    'result'=>$status,'duration_ms'=>$ms,
    'tokens_reported'=>is_int($input)&&is_int($output),
    'error_code'=>$status==='error'?$reason:'none'
   ),$status==='ok'?'ok':'denied');
  }
  if($status==='ok'&&class_exists('MAD4B_SCP_Core_AI_Governance'))
   MAD4B_SCP_Core_AI_Governance::record('local_ollama',self::MODEL,$input,$output);
 }
 public static function route_task($task) {
  switch ($task) {
   case 'short_classification': return array('executor'=>'local_ollama','state'=>'pilot','domain_write'=>false);
   case 'structured_review': return array('executor'=>'local_ollama','state'=>'evaluation_only','domain_write'=>false);
   case 'general_short': return array('executor'=>'local_ollama','state'=>'owner_manual_only','domain_write'=>false);
   case 'code_proposal': return array('executor'=>'isolated_codex_native_ticket_gate','state'=>'hold_stage00_and_login','domain_write'=>false);
   case 'content_longform': return array('executor'=>'qualified_model_not_configured','state'=>'hold','domain_write'=>false);
   case 'owner_approval': return array('executor'=>'mad4b_native_owner_approval','state'=>'human_or_original_policy_only','domain_write'=>false);
   default: return array('executor'=>'none','state'=>'denied','domain_write'=>false);
  }
 }
 public static function status(){
  return array('contract'=>self::CONTRACT,'provider'=>'local_ollama','model'=>self::MODEL,
   'tasks'=>self::allowed_tasks(),
   'routes'=>array('short_classification'=>self::route_task('short_classification'),
    'structured_review'=>self::route_task('structured_review'),
    'code_proposal'=>self::route_task('code_proposal'),
    'content_longform'=>self::route_task('content_longform'),
    'owner_approval'=>self::route_task('owner_approval')),
   'agent_write_grant'=>false,
   'actor_gate'=>'wp_admin_or_authenticated_native_agent_with_exact_read_grant',
   'token_source'=>'ollama_prompt_eval_count_and_eval_count',
   'usage_location'=>'core_ai_daily_usage_v1_plus_local_ollama_daily_metrics_v1',
   'metrics'=>get_option(self::OPTION,array()),
   'no_prompt_or_response_storage'=>true);
 }
}
