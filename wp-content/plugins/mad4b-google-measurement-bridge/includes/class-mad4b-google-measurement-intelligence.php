<?php
if (!defined('ABSPATH')) exit;
/** Read-only aggregated GA4 monitoring, and a strictly non-mutating GTM change inventory. */
final class MAD4B_Google_Measurement_Intelligence {
 const CONTRACT='mad4b.google-measurement-intelligence.v1';
 const DATA='https://analyticsdata.googleapis.com/v1beta/';
 private static function error($code,$message){return new WP_Error($code,$message);}
 private static function report($property,$start,$end) {
  $token=MAD4B_Google_Measurement_API::token(MAD4B_Google_Measurement_Bridge::SCOPE_GA4_READ);
  if (is_wp_error($token))return $token;
  $r=wp_remote_post(self::DATA.$property.':runReport',array(
   'timeout'=>12,'redirection'=>0,
   'headers'=>array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Accept'=>'application/json'),
   'body'=>wp_json_encode(array('dateRanges'=>array(array('startDate'=>$start,'endDate'=>$end)),
     'dimensions'=>array(array('name'=>'eventName')),
     'metrics'=>array(array('name'=>'eventCount')),'limit'=>300))));
  unset($token);
  if (is_wp_error($r))return self::error('measurement_ga4_transport','Analytics report unavailable.');
  $status=(int)wp_remote_retrieve_response_code($r);$raw=(string)wp_remote_retrieve_body($r);
  if ($status!==200||strlen($raw)>1048576)return new WP_Error('measurement_ga4_report_failed',
    'Google Analytics report rejected.',array('http_status'=>$status));
  $data=json_decode($raw,true);
  if (!is_array($data))return self::error('measurement_ga4_json','Invalid report response.');
  $counts=array();
  foreach((array)($data['rows']??array()) as $row) {
   $name=(string)($row['dimensionValues'][0]['value']??'');
   if ($name!==''&&strlen($name)<=80)$counts[$name]=max(0,(int)($row['metricValues'][0]['value']??0));
  }
  return $counts;
 }
 public static function run($input=array()) {
  if (!MAD4B_Google_Measurement_Bridge::gate())return self::error('measurement_forbidden','Owner access required.');
  $binding=MAD4B_Google_Measurement_Admin::binding();
  if (empty($binding['ready']))return self::error('measurement_binding_required','Exact selected Google account required.');
  $action=(string)($input['action']??'');
  if ($action==='anomaly_check') {
   $now=self::report($binding['ga4_property'],'7daysAgo','yesterday');
   if (is_wp_error($now))return $now;
   $previous=self::report($binding['ga4_property'],'14daysAgo','8daysAgo');
   if (is_wp_error($previous))return $previous;
   $watch=array('view_item_list','select_item','view_item','generate_lead','purchase');
   $events=array();
   foreach($watch as $name) {
    $a=(int)($now[$name]??0);$b=(int)($previous[$name]??0);
    $change=$b>0?round(100*($a-$b)/$b,1):null;
    $events[$name]=array('last_7_days'=>$a,'previous_7_days'=>$b,'change_percent'=>$change,
      'flag'=>$b>=20&&$a*2<$b?'greater_than_50_percent_drop':($b===0&&$a===0?'no_events_in_either_window':'none'));
   }
   return array('contract'=>self::CONTRACT,'operation'=>'anomaly_check',
    'property'=>$binding['ga4_property'],'events'=>$events,
    'notifications_sent'=>false,'conversion_duplicates_proven'=>false,
    'caveat'=>'Timezone, data latency, seasonality and low baseline volumes require review. Read-only.');
  }
  if ($action==='gtm_diff') {
   $workspace=(string)($input['workspace_id']??'');
   if (!preg_match('/^[0-9]{1,20}$/D',$workspace))return self::error('measurement_workspace_invalid','Explicit workspace ID required.');
   $status=MAD4B_Google_Measurement_Advanced::run(array('action'=>'gtm_status','workspace_id'=>$workspace));
   if (is_wp_error($status))return $status;
   $changes=array();
   foreach((array)($status['workspaceChange']??array()) as $entry) {
    $type=(string)($entry['changeStatus']??'');
    if (!in_array($type,array('added','changed','deleted'),true))$type='unknown';
    $target=(string)($entry['entity']['tagId']??$entry['entity']['triggerId']??$entry['entity']['variableId']??'');
    $changes[]=array('change'=>$type,'entity_id'=>$target);
    if (count($changes)>=200)break;
   }
   return array('contract'=>self::CONTRACT,'operation'=>'gtm_diff',
    'workspace'=>$workspace,'changes'=>$changes,'raw_change_count'=>count((array)($status['workspaceChange']??array())),
    'merge_conflict_count'=>count((array)($status['mergeConflict']??array())),
    'publish_allowed'=>false,'write_performed'=>false,
    'review_required'=>true,'warning'=>'GTM status is not a rendered browser acceptance or rollback proof.');
  }
  if ($action==='tracking_contract_audit') {
   $current=self::report($binding['ga4_property'],'7daysAgo','yesterday');
   if (is_wp_error($current))return $current;
   $pairs=array('tour_catalog_view'=>'view_item_list','tour_card_click'=>'select_item',
    'tour_product_view'=>'view_item','lead_form_submitted'=>'generate_lead');
   $rows=array();
   foreach($pairs as $internal=>$ga4) $rows[]=array('business_event'=>$internal,'ga4_event'=>$ga4,
    'observed_count'=>(int)($current[$ga4]??0),'detected_in_ga4'=>!empty($current[$ga4]));
   return array('contract'=>self::CONTRACT,'operation'=>'tracking_contract_audit',
    'events'=>$rows,'browser_payloads_verified'=>false,'consent_verified'=>false,
    'gtm4wp_duplicates_verified'=>false,'read_only'=>true);
  }
  return self::error('measurement_unknown_intelligence','Unknown operation.');
 }
 public static function register() {
  if (!function_exists('wp_register_ability'))return;
  $n='mad4b/google-measurement-intelligence';
  if (function_exists('wp_has_ability')&&wp_has_ability($n))return;
  wp_register_ability($n,array('label'=>'Measurement Quality and Change Intelligence',
   'description'=>'Read-only GA4 event trends, contract audit and GTM workspace changes.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,'required'=>array('action'),
    'properties'=>array('action'=>array('type'=>'string','enum'=>array('anomaly_check','gtm_diff','tracking_contract_audit')),
      'workspace_id'=>array('type'=>'string','pattern'=>'^[0-9]+$'))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
   'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
