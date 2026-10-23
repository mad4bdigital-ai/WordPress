<?php
if (!defined('ABSPATH')) exit;
/**
 * Bounded Google Ads Keyword Research. No campaign writes.
 * KeywordPlanIdeaService v26: ideas, historical metrics and ad-group themes.
 */
final class MAD4B_Google_Keyword_Research {
 const CONTRACT='mad4b.google-ads-keyword-research.v1';
 const ENDPOINT='https://googleads.googleapis.com/v26/customers/';
 const SCOPE='https://www.googleapis.com/auth/adwords';
 private static function bad($code,$message){return new WP_Error($code,$message);}
 private static function normalize($input) {
  if (!is_array($input)) return self::bad('keyword_bad_request','Expected an object.');
  $action=(string)($input['action']??'ideas');
  if (!in_array($action,array('ideas','historical','themes'),true)) return self::bad('keyword_invalid_action','Invalid keyword operation.');
  $words=$input['keywords']??array();
  if (!is_array($words)||count($words)>1000) return self::bad('keyword_invalid_words','Invalid keyword collection.');
  $words=array_values(array_unique(array_map('trim',$words)));
  foreach($words as $word)
   if (!is_string($word)||strlen($word)<2||strlen($word)>80||
       preg_match('/[\x00-\x1f<>]/',$word))
    return self::bad('keyword_invalid_word','Keywords must be safe bounded phrases.');
  $max=$action==='ideas'?20:($action==='themes'?20:1000);
  if (count($words)>$max) return self::bad('keyword_too_many','Seed/metrics keyword limit exceeded.');
  $url=trim((string)($input['page_url']??''));
  if ($url!=='' && ($action!=='ideas' || strlen($url)>700 ||
    !filter_var($url,FILTER_VALIDATE_URL) || !preg_match('~^https://~i',$url) ||
    parse_url($url,PHP_URL_USER)!==null || parse_url($url,PHP_URL_PASS)!==null))
    return self::bad('keyword_invalid_url','Only public HTTPS URL seeds are supported for ideas.');
  if (!$words && !$url) return self::bad('keyword_seed_required','Supply keywords or a URL.');
  if ($action!=='ideas'&&$url!=='') return self::bad('keyword_url_not_supported','URL seeds only supported for ideas.');
  $lang=(string)($input['language_id']??'');
  if ($lang!==''&&!preg_match('/^[0-9]{1,10}$/D',$lang)) return self::bad('keyword_language','Invalid language ID.');
  $locations=$input['geo_target_ids']??array();
  if (!is_array($locations)||count($locations)>10) return self::bad('keyword_geo','Maximum 10 geographic criteria.');
  $geo=array();
  foreach($locations as $id) {
   $id=(string)$id;
   if (!preg_match('/^[0-9]{1,10}$/D',$id))return self::bad('keyword_geo','Invalid location ID.');
   $geo[]='geoTargetConstants/'.$id;
  }
  $network=(string)($input['network']??'GOOGLE_SEARCH');
  if (!in_array($network,array('GOOGLE_SEARCH','GOOGLE_SEARCH_AND_PARTNERS'),true))
    return self::bad('keyword_network','Unsupported search network.');
  $page=(int)($input['page_size']??100);
  if ($page<1||$page>200)return self::bad('keyword_page_limit','Page size must be 1..200.');
  $cursor=(string)($input['page_token']??'');
  if (strlen($cursor)>2000||($cursor!==''&&!preg_match('/^[A-Za-z0-9_\-=\/+.]+$/D',$cursor)))
    return self::bad('keyword_bad_cursor','Invalid pagination token.');
  return array('action'=>$action,'keywords'=>$words,'url'=>$url,'language'=>$lang,'geo'=>$geo,
   'network'=>$network,'page_size'=>$page,'page_token'=>$cursor);
 }
 private static function body($n) {
  $body=array('keywordPlanNetwork'=>$n['network'],'includeAdultKeywords'=>false);
  if ($n['language']!=='')$body['language']='languageConstants/'.$n['language'];
  if ($n['geo'])$body['geoTargetConstants']=$n['geo'];
  if ($n['action']==='ideas') {
   if ($n['keywords']&&$n['url'])$body['keywordAndUrlSeed']=array('keywords'=>$n['keywords'],'url'=>$n['url']);
   elseif ($n['keywords'])$body['keywordSeed']=array('keywords'=>$n['keywords']);
   else $body['urlSeed']=array('url'=>$n['url']);
   $body['pageSize']=$n['page_size'];
   if ($n['page_token']!=='')$body['pageToken']=$n['page_token'];
  } elseif ($n['action']==='historical') $body['keywords']=$n['keywords'];
  else $body['keywords']=$n['keywords'];
  return $body;
 }
 private static function call_provider($customer,$manager,$action,$body) {
  $route=$action==='ideas'?'generateKeywordIdeas':
   ($action==='historical'?'generateKeywordHistoricalMetrics':'generateAdGroupThemes');
  $token=MAD4B_Google_Measurement_API::token(self::SCOPE);
  if (is_wp_error($token))return $token;
  $headers=array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Accept'=>'application/json');
  if ($manager!=='')$headers['login-customer-id']=$manager;
  $result=wp_remote_post(self::ENDPOINT.$customer.':'.$route,array(
   'timeout'=>16,'redirection'=>0,'headers'=>$headers,'body'=>wp_json_encode($body)));
  unset($token);
  if (is_wp_error($result))return self::bad('keyword_api_network_error','Google Ads Keyword API transport failed.');
  $status=(int)wp_remote_retrieve_response_code($result);
  $raw=(string)wp_remote_retrieve_body($result);
  if ($status<200||$status>=300||strlen($raw)>2097152)
   return new WP_Error('keyword_api_rejected','Google Ads Keyword API rejected request.',array('http_status'=>$status));
  $json=json_decode($raw,true);
  if (!is_array($json))return self::bad('keyword_api_invalid_json','Invalid Google API response.');
  return $json;
 }
 private static function project($json,$action) {
  $result=array();
  foreach((array)($json['results']??array()) as $row) {
   if (!is_array($row))continue;
   $metrics=$row['keywordIdeaMetrics']??$row['keywordMetrics']??array();
   $result[]=array('keyword'=>(string)($row['text']??$row['keyword']??''),
    'avg_monthly_searches'=>$metrics['avgMonthlySearches']??null,
    'competition'=>$metrics['competition']??null,
    'competition_index'=>$metrics['competitionIndex']??null,
    'low_bid_micros'=>$metrics['lowTopOfPageBidMicros']??null,
    'high_bid_micros'=>$metrics['highTopOfPageBidMicros']??null,
    'monthly_search_volumes'=>$metrics['monthlySearchVolumes']??array(),
    'close_variants'=>$row['closeVariants']??array());
   if (count($result)>=1000)break;
  }
  if ($action==='themes')return array('themes'=>$json['adGroupThemes']??$json['results']??array());
  return array('keywords'=>$result,'next_page_token'=>(string)($json['nextPageToken']??''),
    'total_size'=>$json['totalSize']??null);
 }
 public static function run($input=array()) {
  if (!MAD4B_Google_Measurement_Bridge::gate())
   return self::bad('keyword_permission_denied','Site owner authority required.');
  $binding=MAD4B_Google_Measurement_Ads::binding();
  if (empty($binding['ready']))return self::bad('keyword_account_binding_required','Bind an exact Google Ads customer first.');
  $n=self::normalize($input);
  if (is_wp_error($n))return $n;
  $body=self::body($n);
  $scope=array('customer'=>$binding['customer_id'],'manager'=>$binding['login_customer_id'],
   'body'=>$body,'action'=>$n['action']);
  // The cache stores only research results and site/account scope; never OAuth credentials.
  $key='mad4b_kw_'.hash('sha256',wp_json_encode($scope,JSON_UNESCAPED_SLASHES));
  $cached=get_transient($key);
  if (is_array($cached)) { $cached['cache_hit']=true;return $cached; }
  $raw=self::call_provider($binding['customer_id'],$binding['login_customer_id'],$n['action'],$body);
  if (is_wp_error($raw))return $raw;
  $res=array('contract'=>self::CONTRACT,'action'=>$n['action'],
    'customer_id'=>$binding['customer_id'],'targeting'=>array('language_id'=>$n['language'],
      'geo_target_constants'=>$n['geo'],'network'=>$n['network']),
    'data'=>self::project($raw,$n['action']),
    'ad_campaign_modified'=>false,'read_only'=>true,'cache_hit'=>false,
    'note'=>'Search volumes are historical estimates, not guaranteed traffic or exact SEO difficulty.');
  set_transient($key,$res,6*HOUR_IN_SECONDS);
  return $res;
 }
 public static function register() {
  if (!function_exists('wp_register_ability'))return;
  $n='mad4b/google-ads-keyword-research';
  if (function_exists('wp_has_ability')&&wp_has_ability($n))return;
  wp_register_ability($n,array('label'=>'Google Ads Keyword Research',
   'description'=>'Discover keyword ideas, historic demand/competition and grouping themes from explicitly consented Ads customer.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,
    'properties'=>array('action'=>array('type'=>'string','enum'=>array('ideas','historical','themes')),
      'keywords'=>array('type'=>'array','maxItems'=>1000,'items'=>array('type'=>'string','minLength'=>2,'maxLength'=>80)),
      'page_url'=>array('type'=>'string','maxLength'=>700),
      'language_id'=>array('type'=>'string','pattern'=>'^[0-9]+$'),
      'geo_target_ids'=>array('type'=>'array','maxItems'=>10,'items'=>array('type'=>'string','pattern'=>'^[0-9]+$')),
      'network'=>array('type'=>'string','enum'=>array('GOOGLE_SEARCH','GOOGLE_SEARCH_AND_PARTNERS')),
      'page_size'=>array('type'=>'integer','minimum'=>1,'maximum'=>200),
      'page_token'=>array('type'=>'string','maxLength'=>2000))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,
    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
