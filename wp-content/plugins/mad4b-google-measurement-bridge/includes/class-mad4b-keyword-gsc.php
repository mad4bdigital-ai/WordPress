<?php
if(!defined('ABSPATH'))exit;
/** Search Console performance by query/page; site URL must belong to enrolled origin. */
final class MAD4B_Keyword_GSC {
 const CONTRACT='mad4b.keyword-gsc.v1';
 public static function run($input=array()) {
  if(!MAD4B_Google_Measurement_Bridge::gate())return new WP_Error('gsc_permission','Denied.');
  $site=class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::status():array();
  if(!is_array($site)||empty($site['origin_match'])||empty($site['environment_match']))
   return new WP_Error('gsc_site_unverified','Enrolled Site Profile required.');
  $origin=(string)($site['current_origin']??$site['canonical_origin']??'');
  $host=wp_parse_url($origin,PHP_URL_HOST);
  if(!$host)return new WP_Error('gsc_site_unverified','Host cannot be verified.');
  $property=(string)($input['site_url']??'');
  if(!preg_match('~^(?:https?://[A-Za-z0-9.\-]+/|sc-domain:[A-Za-z0-9.\-]+)$~D',$property))
   return new WP_Error('gsc_property_invalid','Explicit URL-prefix or domain property required.');
  $property_host=strpos($property,'sc-domain:')===0?substr($property,10):wp_parse_url($property,PHP_URL_HOST);
  if($property_host!==$host && substr($host,-strlen('.'.$property_host))!=='.'.$property_host)
   return new WP_Error('gsc_property_mismatch','Search Console property is outside enrolled site domain.');
  $days=(int)($input['days']??28);if($days<1||$days>90)return new WP_Error('gsc_date_range','Choose 1..90 days.');
  $dimension=(string)($input['dimension']??'query');
  if(!in_array($dimension,array('query','page','date','country','device'),true))
   return new WP_Error('gsc_dimension','Invalid dimension.');
  $token=MAD4B_Google_Measurement_API::token(MAD4B_Google_Measurement_Search_Console::SCOPE);
  if(is_wp_error($token))return $token;
  $date_end=gmdate('Y-m-d',strtotime('-3 days'));
  $date_start=gmdate('Y-m-d',strtotime('-'.($days+2).' days'));
  $url='https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($property).'/searchAnalytics/query';
  $res=wp_remote_post($url,array('timeout'=>12,'redirection'=>0,
   'headers'=>array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Accept'=>'application/json'),
   'body'=>wp_json_encode(array('startDate'=>$date_start,'endDate'=>$date_end,
    'dimensions'=>array($dimension),'rowLimit'=>1000,'startRow'=>0,'dataState'=>'final'))));
  unset($token);
  if(is_wp_error($res))return new WP_Error('gsc_transport','Search Console transport failed.');
  $status=(int)wp_remote_retrieve_response_code($res);$raw=(string)wp_remote_retrieve_body($res);
  if($status!==200||strlen($raw)>524288)
   return new WP_Error('gsc_api_failed','Search Console rejected request.',array('http_status'=>$status));
  $data=json_decode($raw,true);
  if(!is_array($data))return new WP_Error('gsc_bad_response','Invalid GSC response.');
  $rows=array();
  foreach((array)($data['rows']??array()) as $row) {
   $rows[]=array('value'=>(string)($row['keys'][0]??''),'clicks'=>(float)($row['clicks']??0),
    'impressions'=>(float)($row['impressions']??0),'ctr'=>(float)($row['ctr']??0),
    'position'=>(float)($row['position']??0));
   if(count($rows)>=1000)break;
  }
  return array('contract'=>self::CONTRACT,'site_url'=>$property,'dimension'=>$dimension,
   'date_start'=>$date_start,'date_end'=>$date_end,'rows'=>$rows,
   'partial_top_rows_possible'=>true,'read_only'=>true);
 }
 public static function register() {
  if(!function_exists('wp_register_ability'))return;
  $n='mad4b/keyword-gsc-opportunities';
  if(function_exists('wp_has_ability')&&wp_has_ability($n))return;
  wp_register_ability($n,array('label'=>'Search Console Keyword Opportunities',
   'description'=>'Read bounded, site-scoped organic query and page performance for research.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,'required'=>array('site_url'),
    'properties'=>array('site_url'=>array('type'=>'string','maxLength'=>255),
     'days'=>array('type'=>'integer','minimum'=>1,'maximum'=>90),
     'dimension'=>array('type'=>'string','enum'=>array('query','page','date','country','device')))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
     'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
