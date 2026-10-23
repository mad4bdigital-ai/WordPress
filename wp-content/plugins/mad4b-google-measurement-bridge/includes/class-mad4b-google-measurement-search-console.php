<?php
if (!defined('ABSPATH')) exit;
/** Search Console account listing only; no Rank Math credentials or hidden site selection. */
final class MAD4B_Google_Measurement_Search_Console {
 const CONTRACT='mad4b.google-measurement.search-console.v1';
 const SCOPE='https://www.googleapis.com/auth/webmasters.readonly';
 public static function run($input=array()) {
  if (!MAD4B_Google_Measurement_Bridge::gate())
   return new WP_Error('measurement_forbidden','Site owner permission required.');
  $binding=MAD4B_Google_Measurement_Admin::binding();
  if (empty($binding['ready']))
   return new WP_Error('measurement_binding_required','Exact WordPress site binding required.');
  $token=MAD4B_Google_Measurement_API::token(self::SCOPE);
  if (is_wp_error($token))return $token;
  $r=wp_remote_get('https://www.googleapis.com/webmasters/v3/sites',array(
   'timeout'=>10,'redirection'=>0,
   'headers'=>array('Authorization'=>'Bearer '.$token,'Accept'=>'application/json')));
  unset($token);
  if (is_wp_error($r))return new WP_Error('measurement_gsc_transport','Search Console unavailable.');
  $status=(int)wp_remote_retrieve_response_code($r);
  $raw=(string)wp_remote_retrieve_body($r);
  if ($status!==200||strlen($raw)>262144)
   return new WP_Error('measurement_gsc_response','Search Console request rejected.',array('http_status'=>$status));
  $json=json_decode($raw,true);
  if (!is_array($json))return new WP_Error('measurement_gsc_json','Unexpected response.');
  $items=array();
  foreach((array)($json['siteEntry']??array()) as $entry) {
   $url=isset($entry['siteUrl'])?(string)$entry['siteUrl']:'';
   if (!preg_match('~^(?:https?://[A-Za-z0-9.\-]+/|sc-domain:[A-Za-z0-9.\-]+)$~D',$url))continue;
   $items[]=array('siteUrl'=>$url,'permissionLevel'=>isset($entry['permissionLevel'])?
     sanitize_key($entry['permissionLevel']):'');
  }
  return array('contract'=>self::CONTRACT,'properties'=>$items,
   'search_analytics_query_enabled'=>false,
   'selected_gsc_property_verified'=>false,
   'rankmath_token_accessed'=>false,'read_only'=>true);
 }
 public static function register() {
  if (!function_exists('wp_register_ability'))return;
  $n='mad4b/google-search-console-sites';
  if (function_exists('wp_has_ability')&&wp_has_ability($n))return;
  wp_register_ability($n,array('label'=>'Google Search Console Available Sites',
   'description'=>'List accessible Google Search Console sites with explicitly consented Search Console OAuth read scope.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
   'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
