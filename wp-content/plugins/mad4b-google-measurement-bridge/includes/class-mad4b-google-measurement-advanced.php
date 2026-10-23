<?php
if (!defined('ABSPATH')) exit;
/** Site-bound and bounded advanced GA4/GTM reads, with no direct mutation. */
final class MAD4B_Google_Measurement_Advanced {
 const CONTRACT='mad4b.google-measurement-advanced.v1';
 const DATA='https://analyticsdata.googleapis.com/v1beta/';
 const ADMIN='https://analyticsadmin.googleapis.com/v1alpha/';
 const GTM='https://tagmanager.googleapis.com/tagmanager/v2/';
 private static function binding() {
  if (!MAD4B_Google_Measurement_Bridge::gate()) return new WP_Error('mad4b_measurement_forbidden','Permission denied.');
  $b=MAD4B_Google_Measurement_Admin::binding();
  return !empty($b['ready'])?$b:new WP_Error('mad4b_measurement_binding_missing','Exact site/Google resource selection required.');
 }
 private static function request($url,$scope,$body=null) {
  if (!preg_match('~^https://(?:analyticsdata\.googleapis\.com/v1beta/|analyticsadmin\.googleapis\.com/v1alpha/|tagmanager\.googleapis\.com/tagmanager/v2/)[A-Za-z0-9_:/.-]+$~D',$url))
   return new WP_Error('mad4b_measurement_url_rejected','Unsupported resource path.');
  $token=MAD4B_Google_Measurement_API::token($scope);
  if (is_wp_error($token)) return $token;
  $args=array('timeout'=>12,'redirection'=>0,'headers'=>array('Authorization'=>'Bearer '.$token,'Accept'=>'application/json'));
  if ($body!==null) {$args['method']='POST';$args['headers']['Content-Type']='application/json';$args['body']=wp_json_encode($body);}
  $r=wp_remote_request($url,$args);unset($token);
  if (is_wp_error($r)) return new WP_Error('mad4b_measurement_transport','Google API network error.');
  $status=(int)wp_remote_retrieve_response_code($r);$raw=(string)wp_remote_retrieve_body($r);
  if (strlen($raw)>1048576||$status<200||$status>299)
   return new WP_Error('mad4b_measurement_provider_rejected','Google API request rejected.',array('http_status'=>$status));
  $decoded=json_decode($raw,true);
  return is_array($decoded)?$decoded:new WP_Error('mad4b_measurement_invalid_response','Invalid JSON.');
 }
 public static function run($input=array()) {
  $b=self::binding();if(is_wp_error($b))return $b;
  $action=(string)($input['action']??'');$property=$b['ga4_property'];
  $read=MAD4B_Google_Measurement_Bridge::SCOPE_GA4_READ;
  if ($action==='metadata')return self::request(self::DATA.$property.'/metadata',$read);
  if ($action==='realtime')return self::request(self::DATA.$property.':runRealtimeReport',$read,
   array('dimensions'=>array(array('name'=>'eventName')),
    'metrics'=>array(array('name'=>'eventCount')), 'limit'=>100));
  if ($action==='compatibility') {
   $dims=$input['dimensions']??array('eventName');$metrics=$input['metrics']??array('eventCount');
   if (!self::allow($dims,MAD4B_Google_Measurement_Reports::DIMENSIONS,5) ||
       !self::allow($metrics,MAD4B_Google_Measurement_Reports::METRICS,5))
    return new WP_Error('mad4b_measurement_fields_rejected','Unsupported dimensions or metrics.');
   return self::request(self::DATA.$property.':checkCompatibility',$read,
    array('dimensions'=>array_map(static function($x){return array('name'=>$x);},$dims),
      'metrics'=>array_map(static function($x){return array('name'=>$x);},$metrics)));
  }
  if ($action==='batch') {
   $presets=$input['presets']??array('tour_funnel','acquisition');
   if (!is_array($presets)||count($presets)<1||count($presets)>4)return self::invalid();
   $defs=MAD4B_Google_Measurement_Reports::presets();$requests=array();
   foreach($presets as $name) {
    if (!isset($defs[$name]))return self::invalid();$d=$defs[$name];
    $requests[]=array('dateRanges'=>array(array('startDate'=>'28daysAgo','endDate'=>'yesterday')),
     'dimensions'=>array_map(static function($x){return array('name'=>$x);},$d['dimensions']),
     'metrics'=>array_map(static function($x){return array('name'=>$x);},$d['metrics']),'limit'=>100);
   }
   return self::request(self::DATA.$property.':batchRunReports',$read,array('requests'=>$requests));
  }
  if ($action==='pivot')return self::request(self::DATA.$property.':runPivotReport',$read,
   array('dateRanges'=>array(array('startDate'=>'28daysAgo','endDate'=>'yesterday')),
    'dimensions'=>array(array('name'=>'deviceCategory'),array('name'=>'eventName')),
    'metrics'=>array(array('name'=>'eventCount')),
    'pivots'=>array(array('fieldNames'=>array('deviceCategory'),'limit'=>5),
                   array('fieldNames'=>array('eventName'),'limit'=>20))));
  if ($action==='audience_list')return self::request(self::ADMIN.$property.'/audiences',$read);
  if ($action==='gtm_status'||$action==='gtm_version'||$action==='gtm_workspaces') {
   $container=$b['gtm_container'];
   if ($action==='gtm_version')$suffix='/versions:live';
   elseif($action==='gtm_workspaces')$suffix='/workspaces';
   else {
    $id=(string)($input['workspace_id']??'');
    if (!preg_match('/^[0-9]{1,20}$/D',$id))return self::invalid();
    $suffix='/workspaces/'.$id.'/status';
   }
   return self::request(self::GTM.$container.$suffix,MAD4B_Google_Measurement_Bridge::SCOPE_GTM_READ);
  }
  if ($action==='tracking_quality') {
   $result=self::request(self::DATA.$property.':runReport',$read,array(
    'dateRanges'=>array(array('startDate'=>'7daysAgo','endDate'=>'yesterday')),
    'dimensions'=>array(array('name'=>'eventName')),
    'metrics'=>array(array('name'=>'eventCount'),'limit'=>100)));
   if (is_wp_error($result))return $result;
   $counts=array();
   foreach((array)($result['rows']??array()) as $row)
    $counts[$row['dimensionValues'][0]['value']??'']=max(0,(int)($row['metricValues'][0]['value']??0));
   $required=array('view_item_list','select_item','view_item','generate_lead');
   $missing=array();
   foreach($required as $name)if(empty($counts[$name]))$missing[]=$name;
   return array('contract'=>self::CONTRACT,'property'=>$property,'window'=>'7daysAgo..yesterday',
    'event_counts'=>$counts,'missing_or_zero_events'=>$missing,
    'conversion_duplicates_unverified'=>true,
    'parameter_completeness_unverified'=>true,
    'note'=>'Aggregated GA4 counts do not prove browser event deduplication, consent or parameter-level correctness.');
  }
  return self::invalid();
 }
 private static function allow($x,$allowed,$max) {
  if (!is_array($x)||count($x)<1||count($x)>$max||count(array_unique($x))!==count($x))return false;
  foreach($x as $s)if(!is_string($s)||!in_array($s,$allowed,true))return false;
  return true;
 }
 private static function invalid(){return new WP_Error('mad4b_measurement_invalid_action','Unsupported or malformed operation.');}
 public static function register() {
  if (!function_exists('wp_register_ability'))return;
  $name='mad4b/google-measurement-advanced-read';
  if (function_exists('wp_has_ability')&&wp_has_ability($name))return;
  wp_register_ability($name,array('label'=>'Google Measurement Advanced Analytics',
   'description'=>'Bounded realtime, metadata, compatibility, batch, pivot, audience, GTM status and tracking quality reads.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,'required'=>array('action'),
    'properties'=>array('action'=>array('type'=>'string','enum'=>array('metadata','realtime','compatibility','batch','pivot','audience_list','gtm_status','gtm_version','gtm_workspaces','tracking_quality')),
      'dimensions'=>array('type'=>'array','maxItems'=>5,'items'=>array('type'=>'string')),
      'metrics'=>array('type'=>'array','maxItems'=>5,'items'=>array('type'=>'string')),
      'presets'=>array('type'=>'array','maxItems'=>4,'items'=>array('type'=>'string')),
      'workspace_id'=>array('type'=>'string','pattern'=>'^[0-9]+$'))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
