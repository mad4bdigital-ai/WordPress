<?php
defined('ABSPATH') || exit;
/**
 * Exact Wayfatours first-install owner action using ORIGINAL 305/306.
 * This class issues no grants, never executes WP-CLI and never certifies 313.
 * Native 312 runs only after the independently authorized original claim.
 */
final class MAD4B_Company_Experience_Install_Owner_V1 {
 const REST='/company-plugin-install/wayfatours/experience';
 const COMPANY='wayfatours';
 const ENV='production';
 const SITE='a3a7cc36-db7e-4a14-8456-b41857def05c';
 const ORIGIN='https://wayfatours.com';
 const SLUG='mad4b-experience';
 const VERSION='1.2.5';
 const STORE='mad4b_company_exact_experience_install_owner_job_v1';
 const LOCK='mad4b_company_exact_experience_install_owner_lock_v1';

 public static function allowed(WP_REST_Request $r):bool {
  return wp_get_environment_type()==='staging'
   &&untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com'
   &&current_user_can('manage_options')&&current_user_can('activate_plugins')
   &&class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
   &&MAD4B_Central_SSH_Command_Intake_V1::permitted($r);
 }
 private static function plan():array {
  $deny=static fn($why)=>['ready'=>false,'reason'=>$why];
  foreach(['MAD4B_Company_Native_Plugin_Install_Handler_V1',
           'MAD4B_Company_Native_Write_Executor_V1',
           'MAD4B_Company_Plugin_Live_Reconcile',
           'MAD4B_Company_Environment_Registry_V2',
           'MAD4B_Original_Verified_WPCLI_Transport_V1'] as $class)
   if(!class_exists($class,false))return $deny('ORIGINAL_NATIVE_PROVIDER_MISSING');
  $site=MAD4B_Company_Environment_Registry_V2::target(self::COMPANY,self::ENV);
  if(!is_array($site)||($site['site_uuid']??null)!==self::SITE
    ||($site['canonical_origin']??null)!==self::ORIGIN)
   return $deny('EXACT_COMPANY_TARGET_IDENTITY_CHANGED');
  $n=MAD4B_Company_Native_Write_Executor_V1::preflight_status(self::COMPANY,self::ENV,'plugin.install');
  if(($n['state']??null)!=='AUTHORITY_AND_EXECUTOR_READY_FOR_EXACT_JOB'
    ||($n['operation_provider_ready']??false)!==true)
   return $deny('SIGNED_NATIVE_PLUGIN_PROVIDER_NOT_READY');
  $cap=MAD4B_Company_Native_Plugin_Install_Handler_V1::capability_status();
  $h=get_option(MAD4B_Company_Native_Plugin_Install_Handler_V1::OPTION,false);
  if(($cap['handler_ready']??false)!==true||!is_array($h)
    ||($h['company']??null)!==self::COMPANY
    ||($h['environment']??null)!==self::ENV
    ||($h['site_uuid']??null)!==self::SITE
    ||($h['origin']??null)!==self::ORIGIN)
   return $deny('ORIGINAL_SIGNED_CAPABILITY_MISSING');
  $archive=(string)($h['archive_sha256']??'');
  $command=(string)($h['command_sha256']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$archive)
    ||!preg_match('/^[a-f0-9]{64}$/D',$command))
   return $deny('SOURCE_PACKAGE_SIGNATURE_INVALID');
  // Only this exact command is admitted, without caller-provided path or argv.
  $argv=['plugin','install','/tmp/mad4b-experience-1.2.5-'.$archive.'.zip','--activate'];
  $digest=hash('sha256',wp_json_encode($argv,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  if(!hash_equals($command,$digest))return $deny('EXACT_WORKER_COMMAND_HASH_MISMATCH');
  $inventory=MAD4B_Company_Plugin_Live_Reconcile::native_status(self::COMPANY,self::SITE);
  if(is_wp_error($inventory)||($inventory['target_inventory_verified']??false)!==true
    ||($inventory['target_install_missing_count']??null)!==1
    ||($inventory['target_activation_needed_count']??null)!==0
    ||!is_int($inventory['target_inventory_observation_age_seconds']??null)
    ||$inventory['target_inventory_observation_age_seconds']>3600)
   return $deny('FRESH_MATCHING_TARGET_INVENTORY_REQUIRED');
  return ['ready'=>true,'reason'=>null,'archive_sha256'=>$archive,
   'command_sha256'=>$command,'argv'=>$argv,'site_uuid'=>self::SITE,
   'plan_hash'=>$inventory['plan_hash']];
 }
 private static function stored_job():?array {
  $v=get_option(self::STORE,false);
  return is_array($v)&&($v['contract']??null)==='mad4b.experience-owner-original305.v1'
    &&($v['company']??null)===self::COMPANY&&($v['site_uuid']??null)===self::SITE
    ?$v:null;
 }
 /**
  * Rotate an expired, unverified request only when the package command changed.
  * Historical Broker records remain immutable. The current exact plan() has
  * already required a fresh target inventory showing this plugin is absent.
  * Never reuse or reissue the same command hash; any ambiguous older state
  * remains blocked for explicit reconciliation.
  */
 private static function prior_blocks(?array $prior,string $command_sha256):bool {
  if($prior===null)return false;
  $prior_hash=(string)($prior['command_sha256']??'');
  $id=(string)($prior['job_id']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$prior_hash)
    ||!preg_match('/^[a-f0-9]{64}$/D',$command_sha256)
    ||!preg_match('/^[a-f0-9]{32}$/D',$id))return true;
  if(hash_equals($prior_hash,$command_sha256))return true;
  $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
  if(!is_array($job)||($job['job_id']??null)!==$id
    ||($job['company']??null)!==self::COMPANY
    ||($job['environment']??null)!==self::ENV
    ||($job['site_uuid']??null)!==self::SITE
    ||($job['command_sha256']??null)!==$prior_hash)return true;
  // An unexpired 305/306 remains authoritative even after the source changes.
  if((int)($job['expires_epoch']??0)>=time())return true;
  // Do not silently supersede a previously completed or unknown execution.
  return !in_array((string)($job['state']??''),[
    'QUEUED_FOR_VPS_BROKER_CLAIM',
    'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
  ],true);
 }
 private static function pending_original(string $command_sha256):bool {
  global $wpdb;
  $prefix=MAD4B_Central_VPS_Broker_V1::JOB;
  $names=$wpdb->get_col($wpdb->prepare(
   "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 1000",
   $wpdb->esc_like($prefix).'%'));
  foreach((array)$names as $key) {
   $j=get_option($key,false);
   if(!is_array($j)||($j['company']??'')!==self::COMPANY
     ||($j['environment']??'')!==self::ENV
     ||($j['site_uuid']??'')!==self::SITE
     ||($j['command_sha256']??'')!==$command_sha256)continue;
   $state=(string)($j['state']??'');
   if($state==='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
     ||($state==='QUEUED_FOR_VPS_BROKER_CLAIM'
       &&(int)($j['expires_epoch']??0)>time()))return true;
  }
  return false;
 }
 public static function enqueue(WP_REST_Request $r) {
  if(!self::allowed($r))return new WP_Error('original_owner_required','Owner REST session required',['status'=>403]);
  $plan=self::plan();
  if(($plan['ready']??false)!==true)
   return new WP_Error('exact_plugin_install_not_ready','Signed native install not ready',
    ['status'=>409,'reason'=>$plan['reason']??'UNKNOWN']);
  $input=$r->get_json_params();
  $expected=['company'=>self::COMPANY,'environment'=>self::ENV,
   'site_uuid'=>self::SITE,'plugin'=>self::SLUG,'version'=>self::VERSION,
   'archive_sha256'=>$plan['archive_sha256'],
   'command_sha256'=>$plan['command_sha256']];
  if(!is_array($input))return new WP_Error('exact_request_required','Exact request required',['status'=>422]);
  ksort($input,SORT_STRING);ksort($expected,SORT_STRING);
  if($input!==$expected)return new WP_Error('signed_plan_changed','Signed plan mismatch',['status'=>409]);
  $lock=get_option(self::LOCK,false);
  if(is_array($lock)&&(int)($lock['until']??0)>time())
   return new WP_Error('original305_submission_in_progress','Submission active',['status'=>409]);
  if($lock!==false)delete_option(self::LOCK);
  $token=bin2hex(random_bytes(12));
  if(!add_option(self::LOCK,['token'=>$token,'until'=>time()+120],'',false))
   return new WP_Error('original305_submission_locked','Submission busy',['status'=>409]);
  register_shutdown_function(static function()use($token) {
   $row=get_option(self::LOCK,false);
   if(is_array($row)&&($row['token']??null)===$token)delete_option(self::LOCK);
  });
  $prior=self::stored_job();
  if(self::prior_blocks($prior,$plan['command_sha256'])
    ||self::pending_original($plan['command_sha256']))
   return new WP_Error('original305_job_already_recorded',
     'Existing original request requires independent reconciliation',
     ['status'=>409,'job_id'=>$prior['job_id']??null]);
  $slot=get_option('mad4b_company_native_wpcli_slot_v1_'.self::COMPANY.'_'.self::ENV,false);
  $owner='MAD4B_Central_SSH_Command_Intake_V1';
  $grant=is_array($slot)?get_option($owner::PREFIX.($slot['grant_id']??''),false):false;
  $transport=is_array($slot)?get_option($owner::TRANSPORT_PREFIX.($slot['transport_id']??''),false):false;
  if(!is_array($grant)||!is_array($transport)
    ||($slot['site_uuid']??null)!==self::SITE
    ||($grant['operation']??null)!=='company.remote.wpcli.full'
    ||($grant['company']??null)!==self::COMPANY
    ||($grant['central_principal_id']??null)!==get_current_user_id()
    ||!MAD4B_Original_Verified_WPCLI_Transport_V1::active($transport,$grant))
   return new WP_Error('original_owner_grant_ineligible','Original owner grant required',['status'=>403]);
  // The Original 305 persistent grant allows one claim per correlation.
  $correlation='experience.'.substr($plan['command_sha256'],0,40);
  $nonce=(string)$r->get_header('X-WP-Nonce');
  $command=new WP_REST_Request('POST','/mad4b/v1/central-execution-lanes/'.self::COMPANY.'/commands');
  $command->set_url_params(['company'=>self::COMPANY]);
  $command->set_header('X-WP-Nonce',$nonce);
  $command->set_header('Content-Type','application/json');
  $command->set_body(wp_json_encode([
   'grant_id'=>$slot['grant_id'],'argv'=>$plan['argv'],
   'summary'=>'Owner-approved exact mad4b-experience 1.2.5 install for Wayfatours',
   'correlation_id'=>$correlation,'target_scope'=>'wordpress'
  ],JSON_UNESCAPED_SLASHES));
  $accepted=$owner::accept_command($command);
  if(is_wp_error($accepted))return $accepted;
  if(!($accepted instanceof WP_REST_Response))
   return new WP_Error('original305_acceptance_uncertain','Original 305 unavailable',['status'=>503]);
  $data=$accepted->get_data();
  $job=(string)($data['request_id']??'');
  if(!preg_match('/^[a-f0-9]{32}$/D',$job)
    ||($data['company']??null)!==self::COMPANY
    ||($data['site_uuid']??null)!==self::SITE
    ||($data['environment']??null)!==self::ENV
    ||($data['command_sha256']??null)!==$plan['command_sha256'])
   return new WP_Error('original305_receipt_mismatch','Admission uncertain',['status'=>503]);
  // Persist admitted request BEFORE dispatch. Retries never mint a second 305.
  $record=['contract'=>'mad4b.experience-owner-original305.v1',
    'company'=>self::COMPANY,'environment'=>self::ENV,'site_uuid'=>self::SITE,
    'command_sha256'=>$plan['command_sha256'],'plan_hash'=>$plan['plan_hash'],
    'job_id'=>$job,'owner_user_id'=>get_current_user_id(),
    'state'=>'ORIGINAL_305_ADMITTED_DISPATCH_PENDING','at'=>time()];
  // Retain previous failed/expired request linkage for future 313 diagnostics.
  if($prior!==null)$record['superseded_job']=[
    'job_id'=>(string)($prior['job_id']??''),
    'command_sha256'=>(string)($prior['command_sha256']??''),
    'state'=>(string)($prior['state']??''),
  ];
  if(!update_option(self::STORE,$record,false))
   return new WP_Error('original305_tracking_failed','Reconcile original 305 before retry',
     ['status'=>503,'job_id'=>$job]);
  $dispatch=new WP_REST_Request('POST','/mad4b/v1/central-execution-lanes/'.self::COMPANY.'/broker-dispatch');
  $dispatch->set_url_params(['company'=>self::COMPANY]);
  $dispatch->set_header('X-WP-Nonce',$nonce);
  $dispatch->set_header('Content-Type','application/json');
  $dispatch->set_body(wp_json_encode(['request_id'=>$job]));
  $out=MAD4B_Central_VPS_Broker_V1::dispatch($dispatch);
  if(is_wp_error($out)||!($out instanceof WP_REST_Response))
   return new WP_Error('original306_dispatch_needs_reconciliation',
     '305 admitted but Broker dispatch unverified',['status'=>503,'job_id'=>$job]);
  $j=$out->get_data();
  if(!is_array($j)||($j['job_id']??null)!==$job
    ||($j['state']??null)!=='QUEUED_FOR_VPS_BROKER_CLAIM')
   return new WP_Error('original306_dispatch_unverified',
     'Original 306 state mismatched',['status'=>503,'job_id'=>$job]);
  $record['state']='QUEUED_FOR_VPS_BROKER_CLAIM';
  update_option(self::STORE,$record,false);
  return new WP_REST_Response(['state'=>'ORIGINAL_306_QUEUED',
    'job_id'=>$job,'operation'=>'plugin.install',
    'original_313_certified'=>false,'production_mutation_observed'=>false],202);
 }
 public static function status(WP_REST_Request $r) {
  if(!self::allowed($r))return new WP_Error('original_owner_required','Owner REST session required',['status'=>403]);
  $id=(string)$r['job_id'];
  $tracked=self::stored_job();
  $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
  if(!is_array($tracked)||($tracked['job_id']??null)!==$id
    ||!is_array($job)||($job['job_id']??null)!==$id
    ||($job['company']??null)!==self::COMPANY
    ||($job['site_uuid']??null)!==self::SITE
    ||($job['environment']??null)!==self::ENV
    ||($job['command_sha256']??null)!==($tracked['command_sha256']??null))
   return new WP_Error('original_job_scope_mismatch','Exact job not verified',['status'=>404]);
  // Read ORIGINAL signed 313 and its independent native finish. Never turn a
  // queued/claimed Job or the 312 capability heartbeat into a certificate.
  $read=class_exists('MAD4B_Broker_Remote_SSH_Receipt_V1',false)
    ?MAD4B_Broker_Remote_SSH_Receipt_V1::lifecycle_readback($id):[];
  $verified=($read['signed_313_certified']??false)===true
    &&($read['native_receipt_proven_by_finish']??false)===true
    &&($read['job_id']??null)===$id
    &&($read['company']??null)===self::COMPANY
    &&($read['environment']??null)===self::ENV
    &&($job['state']??null)==='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED'
    &&($job['executed']??false)===true
    &&($job['independent_readback_verified']??false)===true
    &&($job['production_mutation']??false)===true;
  return rest_ensure_response(['job_id'=>$id,
    'state'=>$verified?'WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED'
      :(string)($read['state']??($job['state']??'UNKNOWN')),
    'original_313_certified'=>$verified,
    'receipt_verification_required'=>!$verified,
    'receipt_only_recovery_required'=>($read['receipt_only_recovery_required']??false)===true,
    'production_mutation_verified'=>$verified]);
 }
 public static function render_panel(array $target,array $preflight):void {
  if(($target['company_code']??null)!==self::COMPANY
    ||($target['environment']??null)!==self::ENV
    ||($target['site_uuid']??null)!==self::SITE)return;
  $plan=self::plan();$ready=($plan['ready']??false)===true;
  $tracked=self::stored_job();
  $blocked=self::prior_blocks($tracked,(string)($plan['command_sha256']??''))
    ||($ready&&self::pending_original($plan['command_sha256']));
  echo '<h3>Exact mad4b-experience installation · Original 305</h3>';
  echo '<p>Signed package 1.2.5, original enrolled WP-CLI transport and independent 312/313. No separate grant.</p>';
  if($ready)echo '<p><strong>Signed ZIP:</strong> <code>'.esc_html($plan['archive_sha256']).'</code></p>';
  else echo '<p role="status">Not eligible: '.esc_html((string)($plan['reason']??'UNKNOWN')).'</p>';
  if($tracked)echo '<p role="status">Existing Original 305 reference: <code>'.esc_html((string)$tracked['job_id']).'</code> · '.esc_html($tracked['state']).'. '.($blocked?'Reconciliation required before another request.':'Expired prior command; the new package requires a separate owner-authenticated 305.').'</p>';
  if(!current_user_can('manage_options'))return;
  $url=rest_url('mad4b/v1'.self::REST);$nonce=wp_create_nonce('wp_rest');
  $payload=['company'=>self::COMPANY,'environment'=>self::ENV,
    'site_uuid'=>self::SITE,'plugin'=>self::SLUG,'version'=>self::VERSION,
    'archive_sha256'=>$ready?$plan['archive_sha256']:'',
    'command_sha256'=>$ready?$plan['command_sha256']:''];
  echo '<button type="button" class="button button-primary" id="mad4b-experience-owner-305" '.disabled(!$ready||$blocked,true,false).'>Owner: issue exact Original 305 Job</button>';
  echo '<p id="mad4b-experience-owner-305-state" role="status" aria-live="polite"></p>';
  echo '<script>(function(){const b=document.getElementById("mad4b-experience-owner-305"),s=document.getElementById("mad4b-experience-owner-305-state");if(!b)return;'
   .'const url='.wp_json_encode($url).',nonce='.wp_json_encode($nonce).',data='.wp_json_encode($payload).';'
   .'b.addEventListener("click",async()=>{b.disabled=true;s.textContent="Submitting authorized original 305…";'
   .'try{const r=await fetch(url,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},body:JSON.stringify(data)});'
   .'const x=await r.json();if(!r.ok)throw Error(x.code||"Original 305 rejected");'
   .'s.textContent="Original 306 queued · Job "+x.job_id+". Independent 313 pending.";'
   .'for(let i=0;i<75;i++){await new Promise(resolve=>setTimeout(resolve,8000));'
   .'const q=await fetch(url+"/"+x.job_id,{credentials:"same-origin",headers:{"X-WP-Nonce":nonce}});'
   .'if(!q.ok)throw Error("Original 313 readback unavailable");const y=await q.json();'
   .'s.textContent="Job "+x.job_id+": "+y.state;'
   .'if(y.original_313_certified===true){s.textContent="Original 313 VERIFIED · Job "+x.job_id;break;}'
   .'if(y.receipt_only_recovery_required===true){s.textContent="Original 313 receipt recovery required · Job "+x.job_id;break;}'
   .'}'
   .'}catch(e){s.textContent="Not submitted: "+e.message;}});})();</script>';
 }
 public static function boot():void {
  add_action('rest_api_init',static function() {
   register_rest_route('mad4b/v1',self::REST,[
    'methods'=>'POST','permission_callback'=>[__CLASS__,'allowed'],'callback'=>[__CLASS__,'enqueue']]);
   register_rest_route('mad4b/v1',self::REST.'/(?P<job_id>[a-f0-9]{32})',[
    'methods'=>'GET','permission_callback'=>[__CLASS__,'allowed'],'callback'=>[__CLASS__,'status']]);
  },101);
 }
}
MAD4B_Company_Experience_Install_Owner_V1::boot();
