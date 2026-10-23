<?php
if (!defined('ABSPATH'))exit;
/** Read-only Rank Math Search Console/GA4 configuration discovery; never exports OAuth secrets. */
final class MAD4B_Google_Measurement_RankMath {
 const CONTRACT='mad4b.google-measurement.rankmath-diagnostics.v1';
 public static function run($input=array()) {
  if (!MAD4B_Google_Measurement_Bridge::gate())return new WP_Error('measurement_forbidden','Owner required.');
  $active=(array)get_option('active_plugins',array());$rank=false;
  foreach($active as $plugin)if(strpos((string)$plugin,'seo-by-rank-math/')===0){$rank=true;break;}
  $analytics=get_option('rank_math_analytics',array());
  $general=get_option('rank-math-options-general',array());
  if (!is_array($analytics))$analytics=array();
  if (!is_array($general))$general=array();
  $public_properties=array();
  foreach(array($analytics,$general) as $settings)foreach($settings as $key=>$value) {
   if (!is_string($key)||!preg_match('/search_console|console|analytics|property|site_url/i',$key))continue;
   if (!is_string($value))continue;
   $v=trim($value);
   if (preg_match('~^(?:https?://[A-Za-z0-9.\-]+/?|sc-domain:[A-Za-z0-9.\-]+|properties/[0-9]+|G-[A-Z0-9]+)$~D',$v))
    $public_properties[$key]=$v;
  }
  $bridge=MAD4B_Google_Measurement_Admin::binding();
  return array('contract'=>self::CONTRACT,'rankmath_plugin_active'=>$rank,
   'rankmath_analytics_module_observed'=>isset($analytics['analytics_active'])||!empty($analytics),
   'safe_public_property_hints'=>$public_properties,
   'bridge_ga4_property'=>!empty($bridge['ready'])?$bridge['ga4_property']:null,
   'search_console_google_authorization_verified'=>false,
   'rankmath_token_reused'=>false,
   'rankmath_connection_usable_by_bridge'=>false,
   'verification_steps'=>array('check_rank_math_analytics_module_enabled',
     'open_rank_math_general_settings_analytics',
     'compare_selected_search_console_site',
     'run_rank_math_test_connections',
     'verify_GSC_site_with_owner_google_oauth_readonly'),
   'read_only'=>true);
 }
 public static function register() {
  if (!function_exists('wp_register_ability'))return;
  $n='mad4b/google-measurement-rankmath-audit';
  if (function_exists('wp_has_ability')&&wp_has_ability($n))return;
  wp_register_ability($n,array('label'=>'Rank Math Google Search Console Audit',
   'description'=>'Redacted read-only inspection of Rank Math module/selected public Google property hints.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
     'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
