<?php
/**
 * MAD4B Generic Native Company Clone Admission — central staging.
 * Dynamic company-scoped planning, exact private QA admission, no shell execution.
 * Existing 341/343/345 Wayfatours signed legacy path remains unchanged.
 */
defined('ABSPATH') || exit;
final class MAD4B_Generic_Native_Clone_V1 {
 const CONTRACT='mad4b.generic-native-clone.v1';
 const PREFIX='mad4b_generic_native_clone_v1_';
 const POLICY='1122e0713807cd5d93019476898ac22794b27d3ffd2d166667bc889c38e45863';
 private static function central():bool {
  return defined('DB_NAME') && DB_NAME==='etg_commerce_staging'
   && wp_get_environment_type()==='staging'
   && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
 }
 private static function find(string $company,string $environment,int $id):array {
  if(!self::central() || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
    || !in_array($environment,['staging','production'],true))
   return ['state'=>'SOURCE_SCOPE_INVALID'];
  if($id>0) {
   $post=get_post($id);
   $r=get_post_meta($id,'_mad4b_company_launch_v1',true);
   $manager=class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
      ?MAD4B_Company_Portfolio_Manager_V1::coolify_service_reservation($company):null;
   if(!$post || $post->post_type!=='mad4b_company_launch'
      || $post->post_status!=='private' || !is_array($r)
      || ($r['data']['company_code']??null)!==$company
      || ($r['target_environment']??null)!==$environment
      || ($r['state']??null)!=='resource_reserved'
      || ($r['public_live']??false)!==false || !is_array($manager)
      || ($r['coolify_resource_uuid']??null)!==($manager['coolify_resource_uuid']??null)
      || ($r['reserved_target_site_uuid']??null)!==($manager['site_uuid']??null)
      || ($manager['environment']??null)!==$environment
      || !wp_is_uuid((string)($manager['site_uuid']??'')))
    return ['state'=>'ORIGINAL_APPLICATION_OR_RECEIPT_MISMATCH'];
   if(($r['origin']??null)!=='vps_synthetic_qa_fixture'
      || ($r['qa_fixture_contract']??null)!=='mad4b.internal-qa-reservation.v1'
      || !str_starts_with($company,'qa-') || $environment!=='staging')
    return ['state'=>'GENERAL_COMPANY_REQUIRES_SIGNED_341_343_345_GRANT'];
   return [
    'state'=>'PRIVATE_QA_RESERVATION_MATCHED','company'=>$company,
    'environment'=>$environment,'application_id'=>$id,
    'site_uuid'=>$manager['site_uuid'],
    'resource_uuid'=>$manager['coolify_resource_uuid'],
    'origin'=>'https://'.($r['data']['default_bootstrap_domain']??''),
    'source_origin'=>'https://tours.dev.mad4b.com',
    'source_db_policy_sha256'=>self::POLICY
   ];
  }
  $target=class_exists('MAD4B_Company_Environment_Registry_V2',false)
    ?MAD4B_Company_Environment_Registry_V2::target($company,$environment):null;
  return is_array($target)
   ?['state'=>'EXISTING_COMPANY_USE_REGISTERED_SIGNED_ADMISSION',
     'company'=>$company,'environment'=>$environment,
     'site_uuid'=>$target['site_uuid']??null]
   :['state'=>'COMPANY_NOT_REGISTERED_FOR_NATIVE_CLONE'];
 }
 private static function key(array $plan):string {
   return self::PREFIX.$plan['company'].'_'.$plan['environment'].'_'.$plan['application_id'];
 }
 private static function mac(array $v):string {
  return hash_hmac('sha256',wp_json_encode($v,JSON_UNESCAPED_SLASHES),wp_salt('auth'));
 }
 private static function digest(array $plan):string {
  return hash('sha256',wp_json_encode($plan,JSON_UNESCAPED_SLASHES));
 }
 public static function plan(string $company,string $env,int $id):array {
  $v=self::find($company,$env,$id);
  if(($v['state']??'')!=='PRIVATE_QA_RESERVATION_MATCHED')
   return ['contract'=>self::CONTRACT,'state'=>$v['state']??'NOT_READY',
    'admitted'=>false,'public_live'=>false,'execution_performed'=>false];
  $core=['contract'=>self::CONTRACT,
    'company'=>$company,'environment'=>$env,'application_id'=>$id,
    'site_uuid'=>$v['site_uuid'],'resource_uuid'=>$v['resource_uuid'],
    'source_origin'=>$v['source_origin'],'target_origin'=>$v['origin'],
    'source_db_policy_sha256'=>self::POLICY,
    'clone_mode'=>'full_database_plus_files_preserve_native_ids',
    'execution_target'=>'vps_exact_qa_private', 'public_live'=>false];
  return $core+['state'=>'QA_PRIVATE_CLONE_PLAN','plan_sha256'=>self::digest($core),
    'production_mutation_allowed'=>false,'worker_execution_started'=>false];
 }
 public static function status(string $company,string $env,int $id=0):array {
  $p=self::plan($company,$env,$id);
  if(($p['state']??null)!=='QA_PRIVATE_CLONE_PLAN')return $p;
  $key=self::key($p);
  $saved=get_option($key,false);
  if($saved===false)return $p+['admitted'=>false,'ready_to_start_public'=>false];
  if(!is_array($saved)||!is_array($saved['entry']??null)
    || !is_string($saved['mac']??null)
    || !hash_equals(self::mac($saved['entry']),$saved['mac'])
    || ($saved['entry']['plan_sha256']??null)!==$p['plan_sha256'])
   return $p+['state'=>'ADMISSION_INTEGRITY_INVALID','admitted'=>false];
  return array_merge($p,['state'=>'PRIVATE_QA_NATIVE_CLONE_ADMITTED',
   'admitted'=>true,'source_export_sha256'=>$saved['entry']['source_export_sha256'],
   'ready_to_start_public'=>false,'execution_performed'=>false]);
 }
 /** Queue only a specific private QA job; never execute a VPS command in HTTP. */
 public static function queue(string $company,string $env,int $id,array $input):array {
  if(!self::actor())return ['contract'=>self::CONTRACT,'state'=>'EXACT_STAGING_WPCLI_ADMIN_REQUIRED'];
  $plan=self::plan($company,$env,$id);
  if(($plan['state']??'')!=='QA_PRIVATE_CLONE_PLAN')return $plan;
  $expected=(string)($input['expected_plan_sha256']??'');
  $sha=(string)($input['source_export_sha256']??'');
  if(!hash_equals($plan['plan_sha256'],$expected)
     || preg_match('/^[a-f0-9]{64}$/D',$sha)!==1)
   return $plan+['state'=>'SOURCE_EXPORT_OR_PLAN_EVIDENCE_REQUIRED'];
  $admit=self::admit($company,$env,$id,[
    'expected-plan-sha256'=>$expected,'source-export-sha256'=>$sha]);
  if(($admit['state']??'')!=='PRIVATE_QA_NATIVE_CLONE_ADMITTED'
    || ($admit['source_export_sha256']??'')!==$sha)
   return $admit;
  $key=self::key($plan).'_job';
  $job=['contract'=>self::CONTRACT,'state'=>'PRIVATE_QA_CLONE_WORKER_QUEUED',
    'company'=>$company,'environment'=>$env,'application_id'=>$id,
    'site_uuid'=>$plan['site_uuid'],'resource_uuid'=>$plan['resource_uuid'],
    'plan_sha256'=>$expected,'source_export_sha256'=>$sha,
    'job_id'=>hash('sha256',$company.'|'.$id.'|'.$expected.'|'.$sha),
    'queued_by'=>get_current_user_id(),'queued_at'=>gmdate('c'),
    'public_live'=>false,'host_execution_authorized'=>false];
  $old=get_option($key,false);
  if($old!==false) {
    if(!is_array($old)||!is_array($old['entry']??null)
       || !is_string($old['mac']??null)
       || !hash_equals(self::mac($old['entry']),$old['mac'])
       || ($old['entry']['job_id']??null)!==$job['job_id'])
       return ['contract'=>self::CONTRACT,'state'=>'QUEUE_CONFLICT_OR_INTEGRITY_ERROR'];
    return $old['entry']+['idempotent_replay'=>true,'host_execution_started'=>false];
  }
  if(!add_option($key,['entry'=>$job,'mac'=>self::mac($job)],'',false))
   return ['contract'=>self::CONTRACT,'state'=>'QUEUE_ATOMIC_RESERVATION_FAILED'];
  return $job+['idempotent_replay'=>false,'host_execution_started'=>false];
 }
 public static function job_status(string $company,string $env,int $id):array {
  $p=self::status($company,$env,$id);
  if(!in_array($p['state']??'',[
     'QA_PRIVATE_CLONE_PLAN','PRIVATE_QA_NATIVE_CLONE_ADMITTED'],true))
   return $p;
  $saved=get_option(self::key($p).'_job',false);
  if($saved===false)return $p+['job_queued'=>false,'host_execution_started'=>false];
  if(!is_array($saved)||!is_array($saved['entry']??null)
   || !is_string($saved['mac']??null)
   || !hash_equals(self::mac($saved['entry']),$saved['mac'])
   || ($saved['entry']['plan_sha256']??'')!==$p['plan_sha256'])
   return array_merge($p,['state'=>'QUEUE_INTEGRITY_INVALID','job_queued'=>false]);
  return array_merge($p,$saved['entry'],['job_queued'=>true,
     'host_execution_started'=>false,'public_live'=>false]);
 }
 private static function actor():bool {
  if(!self::central() || !defined('WP_CLI') || !WP_CLI
     || (defined('REST_REQUEST') && REST_REQUEST)
     || get_current_user_id()!==25
     || !current_user_can('manage_options')
     || !current_user_can('activate_plugins'))return false;
  if(!class_exists('MAD4B_Tourism_Workflow_REST',false))return false;
  return MAD4B_Tourism_Workflow_REST::write_permission()===true;
 }
 public static function admit(string $company,string $env,int $id,array $assoc):array {
  if(!self::actor())return ['contract'=>self::CONTRACT,'state'=>'EXACT_STAGING_WPCLI_ADMIN_REQUIRED'];
  $p=self::plan($company,$env,$id);
  if(($p['state']??'')!=='QA_PRIVATE_CLONE_PLAN')return $p;
  $expected=$assoc['expected-plan-sha256']??'';
  $source=$assoc['source-export-sha256']??'';
  if($expected!==$p['plan_sha256'] ||
     !is_string($source)||!preg_match('/^[a-f0-9]{64}$/D',$source))
   return $p+['state'=>'EXACT_PLAN_OR_EXPORT_PROOF_REQUIRED','admitted'=>false];
  $record=['contract'=>self::CONTRACT,'plan_sha256'=>$expected,
    'source_export_sha256'=>$source,'company'=>$company,
    'application_id'=>$id,'site_uuid'=>$p['site_uuid'],
    'resource_uuid'=>$p['resource_uuid'],
    'actor'=>25,'admitted_at'=>gmdate('c'),
    'state'=>'PRIVATE_QA_NATIVE_CLONE_ADMITTED','public_live'=>false];
  $key=self::key($p);
  $prior=get_option($key,false);
  if($prior!==false) {
    $status=self::status($company,$env,$id);
    return $status+['idempotent_replay'=>
      ($status['source_export_sha256']??null)===$source];
  }
  if(!add_option($key,['entry'=>$record,'mac'=>self::mac($record)],'',false))
   return $p+['state'=>'ATOMIC_ADMISSION_ALREADY_EXISTS_OR_FAILED'];
  return self::status($company,$env,$id)+['idempotent_replay'=>false];
 }
 public static function cli(string $entry,array $args,array $assoc):void {
  $action=(string)($args[0]??'');
  $allowed=['company','environment','application-id'];
  if($action==='admit')$allowed=array_merge($allowed,['expected-plan-sha256','source-export-sha256']);
  if($action==='queue')$allowed=array_merge($allowed,['expected-plan-sha256','source-export-sha256']);
  if(array_diff(array_keys($assoc),$allowed))
    WP_CLI::error('GENERIC_CLONE_UNSUPPORTED_PARAMETERS');
  $company=(string)($assoc['company']??'');
  $environment=(string)($assoc['environment']??'');
  $id=ctype_digit((string)($assoc['application-id']??''))?(int)$assoc['application-id']:0;
  if(!$company||!$environment)WP_CLI::error('GENERIC_CLONE_SCOPE_REQUIRED');
  if($action==='status')$out=self::status($company,$environment,$id);
  elseif($action==='plan')$out=self::plan($company,$environment,$id);
  elseif($action==='admit')$out=self::admit($company,$environment,$id,$assoc);
  elseif($action==='queue')$out=self::queue($company,$environment,$id,[
     'expected_plan_sha256'=>$assoc['expected-plan-sha256']??'',
     'source_export_sha256'=>$assoc['source-export-sha256']??'']);
  elseif($action==='readback')$out=self::job_status($company,$environment,$id);
  else WP_CLI::error('GENERIC_CLONE_SUPPORTED_ACTIONS_status_plan_admit_queue_readback');
  WP_CLI::line(wp_json_encode(['command_family'=>$entry]+$out,JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b company-native-clone',[__CLASS__,'direct_cli']);
 }
 public static function direct_cli(array $args,array $assoc):void {
  self::cli('company-native-clone',$args,$assoc);
 }
}
MAD4B_Generic_Native_Clone_V1::boot();

