<?php
/**
 * Plugin Name: MAD4B Central Native Host Scope Resolver
 * Description: Resolve the enrolled central VPS workload separately from customer company targets; read-only identity, no grants.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Central_Host_Scope_V1 {
 const CONTRACT='mad4b.central-host-scope.v1';
 const WORKLOAD='tours-central';
 const HOST='srv2030908';
 const ORIGIN='https://tours.dev.mad4b.com';
 const ENVIRONMENT='staging';
 public static function inspect($workload=self::WORKLOAD):array {
  $base=['contract'=>self::CONTRACT,'workload'=>self::WORKLOAD,
   'scope_kind'=>'central_native_host','company_target'=>false,
   'host_id'=>self::HOST,'environment'=>self::ENVIRONMENT,
   'authorizing'=>false,'host_grant_issued'=>false,'execution_performed'=>false];
  if ($workload!==self::WORKLOAD) return $base+['state'=>'NOT_CENTRAL_HOST_SCOPE'];
  if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
    || !MAD4B_Central_Dual_Lane_Registry_V1::central()
    || !class_exists('MAD4B_SCP_Site_Profile',false)
    || !MAD4B_SCP_Site_Profile::origin_enrolled()
    || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
    || MAD4B_SCP_Site_Profile::current_origin()!==self::ORIGIN)
      return $base+['state'=>'CENTRAL_SITE_PROFILE_NOT_VERIFIED'];
  $uuid=(string)MAD4B_SCP_Site_Profile::site_uuid();
  if (!wp_is_uuid($uuid)) return $base+['state'=>'CENTRAL_SITE_UUID_INVALID'];
  $ids=MAD4B_SCP_Site_Profile::oauth_user_ids();
  if (!is_array($ids) || count($ids)!==1)
      return $base+['state'=>'EXACT_CENTRAL_OWNER_ENROLLMENT_REQUIRED'];
  $uid=(int)$ids[0];$owner=get_user_by('id',$uid);
  if (!$owner || !user_can($owner,'manage_options')
    || !MAD4B_SCP_Site_Profile::user_is_enrolled($uid))
      return $base+['state'=>'CENTRAL_OWNER_REVOKED_OR_UNVERIFIED'];
  return $base+['state'=>'CENTRAL_HOST_IDENTITY_VERIFIED',
    'site_uuid'=>$uuid,'canonical_origin'=>self::ORIGIN,
    'owner_enrolled'=>true];
 }
 public static function matches($workload,$uuid,$origin,$environment,$owner_id):bool {
  $s=self::inspect($workload);
  if (($s['state']??'')!=='CENTRAL_HOST_IDENTITY_VERIFIED'
    || ($s['site_uuid']??'')!==$uuid
    || ($s['canonical_origin']??'')!==$origin
    || $environment!==self::ENVIRONMENT)return false;
  $ids=MAD4B_SCP_Site_Profile::oauth_user_ids();
  return is_array($ids) && count($ids)===1
    && (int)$ids[0]===(int)$owner_id && (int)$owner_id>0;
 }
 public static function current_owner():bool {
  return class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
    && MAD4B_Central_Dual_Lane_Registry_V1::allowed()
    && self::matches(self::WORKLOAD,(string)MAD4B_SCP_Site_Profile::site_uuid(),
      self::ORIGIN,self::ENVIRONMENT,get_current_user_id());
 }
 public static function cli($args,$assoc):void {
  $s=self::inspect();
  WP_CLI::line(wp_json_encode($s,JSON_UNESCAPED_SLASHES));
  if (($s['state']??'')!=='CENTRAL_HOST_IDENTITY_VERIFIED')WP_CLI::halt(2);
 }
 public static function boot():void {
  if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b central-host-scope',[__CLASS__,'cli']);
 }
}
MAD4B_Central_Host_Scope_V1::boot();
