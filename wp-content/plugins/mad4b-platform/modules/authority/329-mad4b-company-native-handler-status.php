<?php
/**
 * Plugin Name: MAD4B Central Staging Native Handler Status
 * Description: Read-only admin status for the registered VPS handler supervisor; no execution abilities.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Central_Company_Native_Handler_Status_V1 {
 const OPTION = 'mad4b_company_native_handler_staging_status_v1';
 const CONTRACT = 'mad4b.company-native-handler-staging-runtime.v1';
 private static function is_central_staging():bool {
  return defined('DB_NAME') && DB_NAME==='etg_commerce_staging'
   && function_exists('wp_get_environment_type')
   && wp_get_environment_type()==='staging'
   && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
 }
 public static function readback():array {
  if (!self::is_central_staging())return [
   'contract'=>self::CONTRACT,'state'=>'EXACT_CENTRAL_STAGING_REQUIRED',
   'staging_supervisor_current'=>false,'execution_authorized'=>false
  ];
  $raw=get_option(self::OPTION,false);
  $r=is_string($raw)?json_decode($raw,true):null;
  if (!is_array($r)||($r['contract']??null)!==self::CONTRACT
   || ($r['central_origin']??null)!=='https://tours.dev.mad4b.com'
   || !is_int($r['observed_epoch']??null)
   || !is_int($r['valid_until_epoch']??null))
   return ['contract'=>self::CONTRACT,'state'=>'SUPERVISOR_READBACK_MISSING',
    'staging_supervisor_current'=>false,'execution_authorized'=>false];
  $fresh=$r['observed_epoch']<=time()+10 && $r['valid_until_epoch']>time()
   && ($r['valid_until_epoch']-$r['observed_epoch'])<=1200;
  return ['contract'=>self::CONTRACT,
   'state'=>$fresh?'STAGING_SUPERVISOR_ACTIVE_READ_ONLY':'STAGING_SUPERVISOR_STALE',
   'staging_supervisor_current'=>$fresh,
   'source_tests_passed'=>($r['source_tests_passed']??null),
   'registered_staging_targets'=>($r['registered_staging_targets']??null),
   'original_staging_native_command_eligible_targets'=>($r['original_staging_native_command_eligible_targets']??null),
   'signed_job_producer_certified'=>false,
   'original_313_independent_readback_verified'=>false,
   'execution_authorized'=>false,
   'full_wpcli_active'=>false,
   'production_mutated'=>false,
   'observed_epoch'=>$r['observed_epoch']];
 }
 public static function register_rest():void {
  if (!self::is_central_staging())return;
  register_rest_route('mad4b/v1','/company-native-handler/status',[
   'methods'=>'GET',
   'callback'=>static function(){return new WP_REST_Response(self::readback(),200);},
   'permission_callback'=>static function(){return current_user_can('manage_options');}
  ]);
 }
 public static function cli($args,$assoc):void {
  if ($args!==['status']||$assoc)WP_CLI::error('READ_ONLY_STATUS_ONLY');
  WP_CLI::line(wp_json_encode(self::readback(),JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  add_action('rest_api_init',[__CLASS__,'register_rest']);
  if (defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b company-native-handler',[__CLASS__,'cli']);
 }
}
MAD4B_Central_Company_Native_Handler_Status_V1::boot();
