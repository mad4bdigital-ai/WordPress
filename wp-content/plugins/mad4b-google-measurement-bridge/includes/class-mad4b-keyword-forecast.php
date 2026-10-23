<?php
if(!defined('ABSPATH'))exit;
/** No campaign mutation: bounded inline Google Ads Keyword Planner forecast. */
final class MAD4B_Keyword_Forecast {
 const CONTRACT='mad4b.keyword-forecast.v1';
 public static function run($input=array()) {
  if(!MAD4B_Google_Measurement_Bridge::gate())return new WP_Error('forecast_denied','Owner read access required.');
  $binding=MAD4B_Google_Measurement_Ads::binding();
  if(empty($binding['ready']))return new WP_Error('forecast_unbound','Bind an Ads customer.');
  $words=$input['keywords']??array();
  if(!is_array($words)||count($words)<1||count($words)>50)return new WP_Error('forecast_keywords','Supply 1..50 keywords.');
  $keywords=array();
  $match=(string)($input['match_type']??'PHRASE');
  if(!in_array($match,array('BROAD','PHRASE','EXACT'),true))return new WP_Error('forecast_match','Invalid match type.');
  foreach($words as $word) {
   if(!is_string($word)||strlen(trim($word))<2||strlen($word)>80||preg_match('/[\x00-\x1f<>]/',$word))
    return new WP_Error('forecast_keyword','Invalid keyword.');
   $keywords[]=array('text'=>trim($word),'matchType'=>$match);
  }
  $bid=(int)($input['max_cpc_bid_micros']??1000000);
  $daily=(int)($input['daily_budget_micros']??10000000);
  if($bid<10000||$bid>1000000000||$daily<100000||$daily>100000000000)
   return new WP_Error('forecast_budget','Simulation bid/budget out of bounds.');
  $geo=array();
  foreach(($input['geo_target_ids']??array()) as $id){
   if(!preg_match('/^[0-9]{1,10}$/D',(string)$id))return new WP_Error('forecast_geo','Invalid geo criterion.');
   $geo[]='geoTargetConstants/'.$id;
  }
  if(count($geo)>10)return new WP_Error('forecast_geo','Too many locations.');
  $lang=(string)($input['language_id']??'');
  if($lang!==''&&!preg_match('/^[0-9]{1,10}$/D',$lang))return new WP_Error('forecast_lang','Invalid language.');
  $campaign=array('adGroups'=>array(array('keywords'=>$keywords)),
   'biddingStrategy'=>array('manualCpcBiddingStrategy'=>array(
     'maxCpcBidMicros'=>(string)$bid,'dailyBudgetMicros'=>(string)$daily)));
  if($geo)$campaign['geoTargetConstants']=$geo;
  if($lang!=='')$campaign['languageConstants']=array('languageConstants/'.$lang);
  $token=MAD4B_Google_Measurement_API::token(MAD4B_Google_Measurement_Ads::SCOPE);
  if(is_wp_error($token))return $token;
  $headers=array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Accept'=>'application/json');
  if($binding['login_customer_id']!=='')$headers['login-customer-id']=$binding['login_customer_id'];
  $res=wp_remote_post('https://googleads.googleapis.com/v26/customers/'.$binding['customer_id'].':generateKeywordForecastMetrics',
   array('timeout'=>18,'redirection'=>0,'headers'=>$headers,'body'=>wp_json_encode(array('campaign'=>$campaign))));
  unset($token);
  if(is_wp_error($res))return new WP_Error('forecast_transport','Ads forecast unavailable.');
  $status=(int)wp_remote_retrieve_response_code($res);$raw=(string)wp_remote_retrieve_body($res);
  if($status!==200||strlen($raw)>524288)return new WP_Error('forecast_provider_error','Forecast rejected.',array('http_status'=>$status));
  $data=json_decode($raw,true);
  if(!is_array($data))return new WP_Error('forecast_json','Unexpected forecast response.');
  return array('contract'=>self::CONTRACT,'customer_id'=>$binding['customer_id'],
   'forecast'=>$data['campaignForecastMetrics']??array(),'simulation'=>array('bid_micros'=>$bid,
    'daily_budget_micros'=>$daily,'keywords'=>count($keywords)),
   'estimated_only'=>true,'campaign_created'=>false,'budget_changed'=>false,'read_only'=>true);
 }
 public static function register(){
  if(!function_exists('wp_register_ability'))return;
  $n='mad4b/google-keyword-forecast';
  if(function_exists('wp_has_ability')&&wp_has_ability($n))return;
  wp_register_ability($n,array('label'=>'Google Keyword Forecast Simulation',
   'description'=>'Read-only inline Google Ads forecast for selected keywords and simulated budget.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,'required'=>array('keywords'),
    'properties'=>array('keywords'=>array('type'=>'array','minItems'=>1,'maxItems'=>50,'items'=>array('type'=>'string','maxLength'=>80)),
      'match_type'=>array('type'=>'string','enum'=>array('BROAD','PHRASE','EXACT')),
      'max_cpc_bid_micros'=>array('type'=>'integer','minimum'=>10000),
      'daily_budget_micros'=>array('type'=>'integer','minimum'=>100000),
      'geo_target_ids'=>array('type'=>'array','maxItems'=>10,'items'=>array('type'=>'string')),
      'language_id'=>array('type'=>'string'))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
