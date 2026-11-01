<?php
if (!defined('ABSPATH')) exit;
/** Central no-prompt-content telemetry and an opt-in, fail-closed Core AI gate. */
final class MAD4B_SCP_Core_AI_Governance {
 const CONTRACT='mad4b.core-ai-governance.v1';
 const OPTION='mad4b_core_ai_daily_usage_v1';
 private static $start=0.0;
 public static function boot(){
  add_action('rest_api_init',array(__CLASS__,'rest'));
  add_filter('wp_ai_client_prevent_prompt',array(__CLASS__,'gate'),PHP_INT_MAX,2);
  add_action('wp_ai_client_before_generate_result',array(__CLASS__,'before'),10,1);
  add_action('wp_ai_client_after_generate_result',array(__CLASS__,'after'),10,1);
 }
 public static function enforced(){return defined('MAD4B_CORE_AI_ENFORCE') && true===constant('MAD4B_CORE_AI_ENFORCE');}
 public static function gate($prevent,$builder){
  if($prevent)return true;
  if(!self::enforced())return false;
  // No implicit background/agent grants. A native actor capability or an
  // explicitly approved owner authorization filter is required.
  $native=current_user_can('manage_options');
  $authorized=apply_filters('mad4b_core_ai_execution_authorized',$native,$builder);
  $allow=true===$authorized;
  if(!$allow && class_exists('MAD4B_SCP_Audit',false))
   MAD4B_SCP_Audit::record('mad4b/core-ai-denied',array('reason'=>'actor_not_authorized','enforced'=>true),'denied');
  return !$allow;
 }
 public static function before($event){
  self::$start=microtime(true); // No messages, request bodies or keys stored.
 }
 private static function identifier($value){
  $s=is_string($value)?$value:'';
  return (strlen($s)<=128 && preg_match('/^[A-Za-z0-9._:\/-]+$/D',$s))?$s:'unknown';
 }
 public static function after($event){
  if(!is_object($event)||!method_exists($event,'getModel')||!method_exists($event,'getResult'))return;
  try {
   $model=$event->getModel();$result=$event->getResult();
   if(!is_object($model)||!is_object($result))return;
   $mid=method_exists($model,'metadata')?$model->metadata():null;
   $pid=method_exists($model,'providerMetadata')?$model->providerMetadata():null;
   $model_id=is_object($mid)&&method_exists($mid,'getId')?self::identifier($mid->getId()):'unknown';
   $provider_id=is_object($pid)&&method_exists($pid,'getId')?self::identifier($pid->getId()):'unknown';
   $usage=method_exists($result,'getTokenUsage')?$result->getTokenUsage():null;
   $in=is_object($usage)&&method_exists($usage,'getPromptTokens')?$usage->getPromptTokens():null;
   $out=is_object($usage)&&method_exists($usage,'getCompletionTokens')?$usage->getCompletionTokens():null;
   self::record($provider_id,$model_id,$in,$out);
  } catch(Throwable $ignored) { /* Logging must not fail a provider response. */ }
 }
 // Rates are never guessed: an operator must supply a versioned, source-bound book.
 private static function quote_cost($provider,$model,$input,$output){
  if(!is_int($input)||!is_int($output))return null;
  $b=get_option('mad4b_core_ai_pricebook_v1',array());
  if(!is_array($b)||($b['contract']??'')!=='mad4b.core-ai-pricebook.v1'
    ||!preg_match('/^[A-Za-z0-9._-]{1,48}$/D',(string)($b['revision']??'')))return null;
  $entry=$b['rates'][$provider.'|'.$model]??null;
  if(!is_array($entry)||!preg_match('#^https://[a-z0-9.-]+/[^ ]+$#iD',(string)($entry['source_url']??''))
    ||!preg_match('/^20[0-9]{2}-[01][0-9]-[0-3][0-9]$/D',(string)($entry['verified_at']??'')))return null;
  $in=$entry['input_usd_per_million']??null;$out=$entry['output_usd_per_million']??null;
  if(!is_numeric($in)||!is_numeric($out)||(float)$in<0||(float)$out<0||(float)$in>10000||(float)$out>10000)return null;
  return array('amount'=>max(0,(int)round($input*(float)$in+$output*(float)$out)),
   'revision'=>(string)$b['revision']); // 1 USD/M tokens = 1 micro-USD/token.
 }
 private static function channel(){
  if(defined('WP_CLI')&&WP_CLI)return 'cli';
  if(function_exists('wp_doing_cron')&&wp_doing_cron())return 'cron';
  if(defined('REST_REQUEST')&&REST_REQUEST)return 'rest';
  if(function_exists('wp_doing_ajax')&&wp_doing_ajax())return 'ajax';
  if(function_exists('is_admin')&&is_admin())return 'admin';
  return 'frontend';
 }
 public static function record($provider,$model,$prompt_tokens=null,$completion_tokens=null){
  $provider=self::identifier($provider);$model=self::identifier($model);
  foreach(array($prompt_tokens,$completion_tokens)as$n)if(null!==$n&&(!is_int($n)||$n<0||$n>100000000))return false;
  $date=gmdate('Y-m-d');$key=$date.'|'.$provider.'|'.$model;
  $all=get_option(self::OPTION,array());if(!is_array($all))$all=array();
  $now=is_array($all[$key]??null)?$all[$key]:array('requests'=>0,'prompt_tokens'=>0,'completion_tokens'=>0,'usage_unreported'=>0);
  $now['requests']=min(100000000,(int)$now['requests']+1);
  $channel=self::channel();$channels=is_array($now['channels']??null)?$now['channels']:array();
  $channels[$channel]=min(100000000,(int)($channels[$channel]??0)+1);$now['channels']=$channels;
  if(null===$prompt_tokens||null===$completion_tokens)$now['usage_unreported']=min(100000000,(int)$now['usage_unreported']+1);
  else {$now['prompt_tokens']=min(1000000000,(int)$now['prompt_tokens']+$prompt_tokens);$now['completion_tokens']=min(1000000000,(int)$now['completion_tokens']+$completion_tokens);}
  $price=self::quote_cost($provider,$model,$prompt_tokens,$completion_tokens);
  if($price!==null){
   $now['estimated_cost_micro_usd']=min(100000000000,(int)($now['estimated_cost_micro_usd']??0)+$price['amount']);
   $current=$now['pricebook_revision']??$price['revision'];
   $now['pricebook_revision']=$current===$price['revision']?$current:'mixed';
  }else $now['unpriced_requests']=min(100000000,(int)($now['unpriced_requests']??0)+1);
  $all[$key]=$now;
  // Bounded 50-bucket retention, newest first; no prompts, actor PII, or prices.
  $cutoff=gmdate('Y-m-d',time()-30*86400);
  foreach($all as $bucket=>$unused)if(substr((string)$bucket,0,10)<$cutoff)unset($all[$bucket]);
  krsort($all,SORT_STRING);$all=array_slice($all,0,50,true);
  update_option(self::OPTION,$all,false);
  return true;
 }
 public static function usage(){
  if(!current_user_can('manage_options'))return new WP_Error('mad4b_core_ai_usage_denied','Owner authorization required.',array('status'=>403));
  return array('contract'=>self::CONTRACT.'.usage.v1','buckets'=>(array)get_option(self::OPTION,array()),
   'cost_unit'=>'micro_usd','cost_status'=>'unpriced_requests_remain_null_without_versioned_source_book',
   'cost_is_estimate_not_invoice'=>true,'no_prompt_content'=>true,'no_keys'=>true);
 }
 public static function rest(){register_rest_route('mad4b/v1','core-ai/usage',array(
  'methods'=>'GET','permission_callback'=>static function(){return current_user_can('manage_options');},
  'callback'=>array(__CLASS__,'usage')));}
 public static function status(){
  return array('contract'=>self::CONTRACT,'interception'=>'wp_ai_client_prevent_prompt',
   'provider_events'=>array('wp_ai_client_before_generate_result','wp_ai_client_after_generate_result'),
   'mode'=>self::enforced()?'enforce':'observe','default_authority'=>'manage_options',
   'usage_buckets'=>count((array)get_option(self::OPTION,array())),
   'prompt_content_logged'=>false,'secrets_logged'=>false,'price_catalog_ready'=>false,
   'currency_cost_estimate'=>null,'cost_status'=>'no_pricebook_preconfigured','attribution'=>'execution_channel_only_not_exact_plugin',
   'limitations'=>array('other direct provider SDKs do not pass this Core hook','requests rejected before event-after are not metered'));
 }
}
