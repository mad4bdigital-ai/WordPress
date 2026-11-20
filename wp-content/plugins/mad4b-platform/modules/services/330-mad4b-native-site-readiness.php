<?php
/**
 * Plugin Name: MAD4B Dynamic Company Native Readiness (Central Staging)
 * Description: Live per-company/environment native readiness and next-step diagnostics; never issues grants or dispatches jobs.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Dynamic_Company_Native_Readiness_V1 {
 const CONTRACT='mad4b.dynamic-company-native-readiness.v1';
 const ROUTE='mad4b/v1';
 public static function enabled():bool {
   return defined('DB_NAME') && DB_NAME==='etg_commerce_staging'
    && function_exists('wp_get_environment_type') && wp_get_environment_type()==='staging'
    && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
 }
 private static function scope_status(string $company,string $env,array $site):array {
   $identity=(array)($site['identity_evidence']??[]);
   $core=class_exists('MAD4B_Company_Native_WPCLI_Enrollment_V1',false)
    ? MAD4B_Company_Native_WPCLI_Enrollment_V1::readback($company,$env):[];
   $original=(($core['state']??'')==='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED');
   $transport=(string)($core['transport_state']??'');
   $trusted=($identity['signed_v4_centrally_verified']??false)===true;
   $observed=(($identity['site_profile_observed']??false)===true);
   $row=get_option('mad4b_portfolio_environment_sites_v1',[]);
   $candidate=is_array($row)?($row[$company.':'.$env]??null):null;
   $readOnlyCandidate=is_array($candidate)
     && ($candidate['source']??'')==='registered_pinned_ssh_wpcli_read_only'
     && ($candidate['remote_writes_authorized']??null)===false
     && ($candidate['site_uuid']??null)===($site['site_uuid']??null)
     && ($candidate['canonical_origin']??null)===($site['canonical_origin']??null);
   $uuid=wp_is_uuid((string)($site['site_uuid']??''))?(string)$site['site_uuid']:null;
   // Native host transport is distinct from company-specific write authority.
   // A valid original 305/328 pair must not be displayed as inactive simply
   // because operation-specific production admission has not been granted.
   $native_active=$original && ($core['host_authority_verified']??false)===true
      && ($core['full_wpcli_active']??false)===true;
   $ready=$native_active && ($site['write_authorized']??false)===true;
   if ($uuid===null) $next='REGISTER_EXACT_SITE_IDENTITY';
   elseif (!$trusted) $next='VERIFY_ENVIRONMENT_SCOPED_SIGNED_SITE_PROFILE';
   elseif (!$original) $next='ENROLL_OWNER_APPROVED_ORIGINAL_305_TRANSPORT_AND_GRANT';
   elseif (!$native_active) $next='CERTIFY_NATIVE_EXECUTOR_WITH_306_312_313_INDEPENDENT_READBACK';
   elseif (!$ready) $next='VERIFY_COMPANY_WRITE_AUTHORITY_FOR_SPECIFIC_OPERATION';
   else $next='VERIFY_OPERATION_SPECIFIC_ADMISSION_BEFORE_APPLY';
   return [
    'company'=>$company,'environment'=>$env,
    'site_uuid'=>$uuid,'canonical_origin'=>$site['canonical_origin']??null,
    'registration'=>'registered',
    'profile_observation'=>$observed?'AUTHENTICATED_PROFILE_OBSERVED':
       ($readOnlyCandidate?'PINNED_SSH_READ_ONLY_CANDIDATE':'UNVERIFIED'),
    'signed_site_identity_verified'=>$trusted,
    'signed_staging_identity_verified'=>$trusted, // backward-compatible alias
    'original_305_persistent_pair'=>$original,
    'native_transport_state'=>$original?$transport:'NOT_ENROLLED',
    'full_wpcli_active'=>$native_active,
    'company_write_scope_active'=>($site['write_authorized']??false)===true,
    'native_313_certified_for_company'=>false, // not inferred from central host receipts
    'next_action'=>$next,
    'remote_write_authorized'=>$ready,
    'production_mutation'=>false
   ];
 }
 public static function snapshot():array {
   if (!self::enabled()||!class_exists('MAD4B_Company_Environment_Registry_V2',false))
     return ['contract'=>self::CONTRACT,'state'=>'EXACT_CENTRAL_STAGING_REQUIRED','targets'=>[]];
   $companies=MAD4B_Company_Environment_Registry_V2::discover();
   $sites=[];
   foreach($companies as $code=>$row) {
     if(!is_string($code)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))continue;
     foreach((array)($row['environments']??[]) as $env=>$site) {
       if(!is_string($env)||!preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$env)||!is_array($site))continue;
       $sites[]=self::scope_status($code,$env,$site);
     }
   }
   usort($sites,static fn($a,$b)=>strcmp($a['company'].':'.$a['environment'],
                                        $b['company'].':'.$b['environment']));
   $counts=['identity_missing'=>0,'signed_identity_pending'=>0,
     'original_305_pending'=>0,'native_executor_pending'=>0,
     'company_write_scope_pending'=>0,'ready_for_operation_specific_admission'=>0];
   foreach($sites as $site) {
     $key=match($site['next_action']) {
       'REGISTER_EXACT_SITE_IDENTITY'=>'identity_missing',
       'VERIFY_ENVIRONMENT_SCOPED_SIGNED_SITE_PROFILE'=>'signed_identity_pending',
       'ENROLL_OWNER_APPROVED_ORIGINAL_305_TRANSPORT_AND_GRANT'=>'original_305_pending',
       'CERTIFY_NATIVE_EXECUTOR_WITH_306_312_313_INDEPENDENT_READBACK'=>'native_executor_pending',
       'VERIFY_COMPANY_WRITE_AUTHORITY_FOR_SPECIFIC_OPERATION'=>'company_write_scope_pending',
       default=>'ready_for_operation_specific_admission'
     };
     ++$counts[$key];
   }
   return ['contract'=>self::CONTRACT,'state'=>'DYNAMIC_READ_ONLY_READINESS_OBSERVED',
    'central_origin'=>'https://tours.dev.mad4b.com','company_count'=>count($companies),
    'target_count'=>count($sites),'counts'=>$counts,'targets'=>$sites,
    'grant_issued'=>false,'job_dispatched'=>false,'production_mutated'=>false];
 }
 public static function rest():void {
   if(!self::enabled())return;
   register_rest_route(self::ROUTE,'/company-native-sites/readiness',[
     'methods'=>'GET',
     'callback'=>static fn()=>new WP_REST_Response(self::snapshot(),200),
     'permission_callback'=>static fn()=>current_user_can('manage_options')
   ]);
 }
 public static function cli($args,$assoc):void {
   if($args!==['status'] || $assoc)WP_CLI::error('READ_ONLY_STATUS_ONLY',77);
   WP_CLI::line(wp_json_encode(self::snapshot(),JSON_UNESCAPED_SLASHES));
 }
 public static function page():void {
   if(!self::enabled()||!current_user_can('manage_options'))wp_die('Not permitted.');
   $r=self::snapshot();
   echo '<div class="wrap"><h1>MAD4B — Native Site Readiness</h1>';
   echo '<p>Live read-only status. Site identity and SSH diagnostics do not grant execution authority.</p>';
   echo '<p><strong>'.esc_html((string)$r['company_count']).' companies · '.
     esc_html((string)$r['target_count']).' environments</strong></p>';
   echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Environment</th>';
   echo '<th>Site profile</th><th>Signed identity</th><th>Original 305</th>';
   echo '<th>Transport</th><th>Next verified step</th></tr></thead><tbody>';
   foreach($r['targets'] as $v) {
     echo '<tr><td>'.esc_html($v['company']).'</td><td>'.esc_html($v['environment']).
       '</td><td>'.esc_html($v['profile_observation']).'</td><td>'.
       esc_html($v['signed_site_identity_verified']?'Verified':'Pending').
       '</td><td>'.esc_html($v['original_305_persistent_pair']?'Enrolled':'Pending').
       '</td><td>'.esc_html($v['native_transport_state']).'</td><td><code>'.
       esc_html($v['next_action']).'</code></td></tr>';
   }
   echo '</tbody></table><p>Native jobs, company grants and production mutation performed by this page: none.</p></div>';
 }
 public static function admin():void {
   if(self::enabled())
     add_management_page('MAD4B Native Readiness','MAD4B Native Readiness',
       'manage_options','mad4b-native-sites-readiness',[__CLASS__,'page']);
 }
 public static function boot():void {
   add_action('rest_api_init',[__CLASS__,'rest']);
   add_action('admin_menu',[__CLASS__,'admin']);
   if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
     WP_CLI::add_command('mad4b company-native-sites',[__CLASS__,'cli']);
 }
}
MAD4B_Dynamic_Company_Native_Readiness_V1::boot();
