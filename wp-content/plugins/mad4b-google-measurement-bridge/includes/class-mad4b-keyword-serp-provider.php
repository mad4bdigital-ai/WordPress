<?php
if (!defined('ABSPATH')) exit;
/**
 * Explicitly configured DataForSEO provider. Credentials remain in wp-config;
 * requests only reach the fixed vendor endpoint, never a user-supplied URL.
 */
final class MAD4B_Keyword_SERP_Provider {
 const CONTRACT='mad4b.keyword-serp-provider.v1';
 const ENDPOINT='https://api.dataforseo.com/v3/serp/google/organic/live/regular';
 public static function run($input=array()) {
  if(!MAD4B_Google_Measurement_Bridge::gate())
   return new WP_Error('serp_provider_permission','Owner permission required.');
  $keyword=trim((string)($input['keyword']??''));
  $language=(string)($input['language_code']??'en');
  $location=(int)($input['location_code']??0);
  if(strlen($keyword)<2||strlen($keyword)>160||preg_match('/[\x00-\x1f<>]/',$keyword)||
     !preg_match('/^[a-z]{2}$/D',$language)||$location<1||$location>999999999)
   return new WP_Error('serp_provider_input','Invalid keyword, language or location.');
  if(!defined('MAD4B_SERP_PROVIDER_LOGIN')||!defined('MAD4B_SERP_PROVIDER_PASSWORD')||
     trim((string)constant('MAD4B_SERP_PROVIDER_LOGIN'))===''||
     trim((string)constant('MAD4B_SERP_PROVIDER_PASSWORD'))==='')
   return new WP_Error('serp_provider_credentials_missing','Owner-configured SERP provider credentials unavailable.');
  // This endpoint is billable. Limit operations to explicit interactive requests.
  if(!current_user_can('manage_options')||empty($input['owner_nonce'])||
     !wp_verify_nonce((string)$input['owner_nonce'],'mad4b_serp_paid_request'))
   return new WP_Error('serp_paid_request_approval','Explicit owner-paid-request nonce required.');
  $body=array(array('keyword'=>$keyword,'location_code'=>$location,'language_code'=>$language,
   'depth'=>20));
  $auth=base64_encode((string)constant('MAD4B_SERP_PROVIDER_LOGIN').':'.(string)constant('MAD4B_SERP_PROVIDER_PASSWORD'));
  $r=wp_remote_post(self::ENDPOINT,array('timeout'=>20,'redirection'=>0,
   'headers'=>array('Authorization'=>'Basic '.$auth,'Content-Type'=>'application/json',
     'Accept'=>'application/json'),
   'body'=>wp_json_encode($body)));
  unset($auth);
  if(is_wp_error($r))return new WP_Error('serp_provider_transport','SERP provider request failed.');
  $http=(int)wp_remote_retrieve_response_code($r);$raw=(string)wp_remote_retrieve_body($r);
  if($http!==200||strlen($raw)>2097152)return new WP_Error('serp_provider_response','SERP provider returned an error.',array('http_status'=>$http));
  $json=json_decode($raw,true);
  if(!is_array($json))return new WP_Error('serp_provider_invalid_json','Unexpected provider response.');
  $task=$json['tasks'][0]??array();
  if((int)($task['status_code']??0)!==20000)
   return new WP_Error('serp_provider_task_failed','SERP task was not successful.',array('provider_status'=>(int)($task['status_code']??0)));
  $result=$task['result'][0]??array();$rows=array();
  foreach((array)($result['items']??array()) as $item) {
   if(($item['type']??'')!=='organic')continue;
   $url=(string)($item['url']??'');$rank=(int)($item['rank_absolute']??0);
   if(!preg_match('~^https://~i',$url)||$rank<1||$rank>100)continue;
   $rows[]=array('url'=>$url,'rank'=>$rank,'title'=>mb_substr((string)($item['title']??''),0,240));
   if(count($rows)>=20)break;
  }
  if(!$rows)return new WP_Error('serp_provider_no_organic_results','No eligible organic search results returned.');
  $observed=gmdate('Y-m-d');
  $review=MAD4B_Keyword_SERP::analyze(array('keyword'=>$keyword,'provider'=>'dataforseo',
    'locale'=>$language,'observed_at'=>$observed,'results'=>$rows));
  if(is_wp_error($review))return $review;
  return array('contract'=>self::CONTRACT,'evidence'=>$review,
   'provider_task_id'=>(string)($task['id']??''),'provider_cost'=>$task['cost']??null,
   'paid_request_performed'=>true,'wordpress_or_ads_mutation'=>false);
 }
 public static function register(){
  if(!function_exists('wp_register_ability'))return;
  $n='mad4b/keyword-serp-provider-live';
  if(function_exists('wp_has_ability')&&wp_has_ability($n))return;
  wp_register_ability($n,array('label'=>'Live Google Organic SERP Provider',
   'description'=>'Owner-approved paid SERP fetch with explicitly configured credentials.',
   'category'=>'mad4b-admin','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,
    'required'=>array('keyword','location_code','owner_nonce'),
    'properties'=>array('keyword'=>array('type'=>'string','minLength'=>2,'maxLength'=>160),
     'language_code'=>array('type'=>'string','pattern'=>'^[a-z]{2}$'),
     'location_code'=>array('type'=>'integer','minimum'=>1),
     'owner_nonce'=>array('type'=>'string','maxLength'=>100))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,
    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'write','production_mutation_allowed'=>false),
    'annotations'=>array('readonly'=>false,'destructive'=>false,'idempotent'=>false))));
 }
}
