<?php
defined('ABSPATH') || exit;
/**
 * Authenticated Company Manager -> Original 305 -> Original Broker 306.
 * This class never executes file writes, signs a Job, or certifies 313.
 * 312 and 313 remain owned by the exact native worker and independent verifier.
 */
final class MAD4B_Company_MU_Deploy_Owner_Launch_V1 {
 const REST='/company-mu-deploy/wayfatours/rate-naming';
 const COMPANY='wayfatours';
 const ENVIRONMENT='production';
 const BASENAME='mad4b-rate-naming-and-note-v1.php';
 const SHA='c57bb35f4718cf34c84d4fcb40166fe8712d6e15790fc98b0444d919346fcafc';
 const COMMAND_SHA='f673a70c489d5641f8f343ed0a980d8658939b4c1f73b45294b081517e471d50';

 public static function allowed(WP_REST_Request $r):bool {
  return current_user_can('manage_options')
   && wp_get_environment_type()==='staging'
   && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com'
   && class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
   && MAD4B_Central_SSH_Command_Intake_V1::permitted($r);
 }
 public static function enqueue(WP_REST_Request $req) {
  if(!self::allowed($req))
   return new WP_Error('mad4b_mu_305_owner_session_required',
    'Original authenticated Company Manager owner is required',['status'=>403]);
  $input=$req->get_json_params();
  $expected=[
   'company'=>self::COMPANY,'environment'=>self::ENVIRONMENT,
   'file'=>self::BASENAME,'sha256'=>self::SHA];
  // JSON objects are unordered. Preserve exact keys, values and types.
  if(is_array($input))ksort($input,SORT_STRING);
  ksort($expected,SORT_STRING);
  if(!is_array($input) || $input!==$expected)
   return new WP_Error('mad4b_mu_exact_plan_required',
    'Exact registered MU deployment plan is required',['status'=>422]);
  foreach(['MAD4B_Company_MU_Deploy_Lifecycle_V1',
           'MAD4B_Central_VPS_Broker_V1',
           'MAD4B_Company_Environment_Registry_V2',
           'MAD4B_Original_Verified_WPCLI_Transport_V1'] as $class)
   if(!class_exists($class,false))
    return new WP_Error('mad4b_mu_original_lifecycle_missing',
      'An original lifecycle component is absent',['status'=>503]);
  $plan=MAD4B_Company_MU_Deploy_Lifecycle_V1::status(
   self::COMPANY,self::ENVIRONMENT,self::BASENAME,self::SHA);
  if(($plan['state']??null)!=='READY_FOR_EXACT_ORIGINAL_305_JOB'
     ||($plan['provider_ready']??null)!==true
     ||($plan['transport_ready']??null)!==true)
   return new WP_Error('mad4b_mu_original_312_provider_not_ready',
    'Exact signed MU Worker capability required before 305',['status'=>409,
    'stage'=>$plan['state']??null,'blockers'=>$plan['blockers']??[]]);
  $site=MAD4B_Company_Environment_Registry_V2::target(self::COMPANY,self::ENVIRONMENT);
  $slot=get_option('mad4b_company_native_wpcli_slot_v1_'.self::COMPANY.'_'.self::ENVIRONMENT,false);
  if(!is_array($site)||!is_array($slot)
    ||($site['site_uuid']??null)!==($slot['site_uuid']??null)
    ||($site['canonical_origin']??null)!=='https://wayfatours.com'
    ||($site['site_uuid']??null)!=='a3a7cc36-db7e-4a14-8456-b41857def05c')
   return new WP_Error('mad4b_mu_site_binding_changed','Site scope drifted',['status'=>409]);
  $owner='MAD4B_Central_SSH_Command_Intake_V1';
  $grant=get_option($owner::PREFIX.(string)($slot['grant_id']??''),false);
  $transport=get_option($owner::TRANSPORT_PREFIX.(string)($slot['transport_id']??''),false);
  if(!is_array($grant)||!is_array($transport)
    ||($grant['operation']??null)!=='company.remote.wpcli.full'
    ||($grant['company']??null)!==self::COMPANY
    ||($grant['central_principal_id']??null)!==get_current_user_id()
    ||($grant['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
    ||!MAD4B_Original_Verified_WPCLI_Transport_V1::active($transport,$grant))
   return new WP_Error('mad4b_mu_original_grant_ineligible',
     'Original enrolled owner-scope WP-CLI grant required',['status'=>403]);
  $argv=['mad4b','native-mu-file','deploy',self::BASENAME,'--sha256='.self::SHA];
  $digest=hash('sha256',wp_json_encode($argv,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  if(!hash_equals(self::COMMAND_SHA,$digest))
   return new WP_Error('mad4b_mu_command_digest_changed','Exact command drift',['status'=>409]);
  $nonce=(string)$req->get_header('X-WP-Nonce');
  $command=new WP_REST_Request('POST','/mad4b/v1/central-execution-lanes/'.self::COMPANY.'/commands');
  $command->set_url_params(['company'=>self::COMPANY]);
  $command->set_header('X-WP-Nonce',$nonce);
  $command->set_header('Content-Type','application/json');
  $command->set_body(wp_json_encode([
   'grant_id'=>$slot['grant_id'],
   'argv'=>$argv,'summary'=>'Exact signed MU Rate Naming deployment for Wayfatours',
   'correlation_id'=>'mu-rate-'.bin2hex(random_bytes(12)),
   'target_scope'=>'wordpress'],JSON_UNESCAPED_SLASHES));
  $accepted=$owner::accept_command($command);
  if(is_wp_error($accepted))return $accepted;
  if(!($accepted instanceof WP_REST_Response))
   return new WP_Error('mad4b_mu_305_acceptance_uncertain','305 status unknown',['status'=>503]);
  $data=$accepted->get_data();
  $job=is_array($data)?(string)($data['request_id']??''):'';
  if(!preg_match('/^[0-9a-f]{32}$/D',$job)
     ||($data['command_sha256']??null)!==self::COMMAND_SHA
     ||($data['company']??null)!==self::COMPANY
     ||($data['environment']??null)!==self::ENVIRONMENT)
   return new WP_Error('mad4b_mu_305_acceptance_readback_failed',
     '305 accepted but command receipt mismatch',['status'=>503,'job_id'=>$job]);
  $dispatch=new WP_REST_Request('POST','/mad4b/v1/central-execution-lanes/'.self::COMPANY.'/broker-dispatch');
  $dispatch->set_url_params(['company'=>self::COMPANY]);
  $dispatch->set_header('X-WP-Nonce',$nonce);
  $dispatch->set_header('Content-Type','application/json');
  $dispatch->set_body(wp_json_encode(['request_id'=>$job]));
  $result=MAD4B_Central_VPS_Broker_V1::dispatch($dispatch);
  if(is_wp_error($result))return new WP_Error('mad4b_mu_306_dispatch_pending_reconciliation',
    'Job accepted but original Broker could not confirm 306',['status'=>503,'job_id'=>$job]);
  if(!($result instanceof WP_REST_Response))
   return new WP_Error('mad4b_mu_306_dispatch_uncertain',
    'Original Broker returned uncertain status',['status'=>503,'job_id'=>$job]);
  $row=$result->get_data();
  if(!is_array($row)||($row['job_id']??null)!==$job
    ||($row['state']??null)!=='QUEUED_FOR_VPS_BROKER_CLAIM')
   return new WP_Error('mad4b_mu_306_job_unverified',
    'Original Broker 306 readback uncertain',['status'=>503,'job_id'=>$job]);
  return new WP_REST_Response([
   'state'=>'ORIGINAL_306_QUEUED','job_id'=>$job,
   'operation'=>'mu-plugin.deploy','source_sha256'=>self::SHA,
   'signed_312_executed'=>false,'original_313_certified'=>false,
   'production_mutation_observed'=>false],202);
 }
 public static function status(WP_REST_Request $req) {
  if(!self::allowed($req))return new WP_Error(
   'mad4b_mu_owner_session_required','Owner authorization required',['status'=>403]);
  $id=(string)$req['job_id'];
  if(!preg_match('/^[0-9a-f]{32}$/D',$id))
   return new WP_Error('mad4b_mu_job_invalid','Invalid Job ID',['status'=>422]);
  $row=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
  if(!is_array($row)||($row['job_id']??null)!==$id
    ||($row['central_principal_id']??null)!==get_current_user_id()
    ||($row['company']??null)!==self::COMPANY
    ||($row['environment']??null)!==self::ENVIRONMENT
    ||($row['command_sha256']??null)!==self::COMMAND_SHA)
   return new WP_Error('mad4b_mu_job_not_enrolled',
     'No original authorized job for this owner',['status'=>404]);
  $verified=($row['state']??null)==='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED'
    &&($row['executed']??null)===true
    &&($row['independent_readback_verified']??null)===true;
  return new WP_REST_Response([
   'job_id'=>$id,'state'=>(string)($row['state']??'UNKNOWN'),
   'original_313_certified'=>$verified,
   'readback_source'=>'original_305_306_312_313_journal',
   'production_mutation_observed'=>$verified],200);
 }
 public static function render():void {
  if(!current_user_can('manage_options'))return;
  $url=rest_url('mad4b/v1'.self::REST);
  $nonce=wp_create_nonce('wp_rest');
  echo '<div class="wrap"><h1>MAD4B · MU Plugin Lifecycle</h1>';
  echo '<p>Wayfatours Production · Rate Naming · Original 305 → 306 → 312 → independent 313.</p>';
  echo '<p>Exact file '.esc_html(self::BASENAME).' · SHA-256 '.esc_html(self::SHA).'</p>';
  echo '<p>Requires an existing owner-authorized original WordPress transport and a live signed 312 worker capability. No additional grant is issued here.</p>';
  echo '<button type="button" class="button button-primary" id="mad4b-mu-owner-launch">Start exact original Job</button>';
  echo '<p id="mad4b-mu-job-state" role="status" aria-live="polite"></p>';
  echo '<script>(function(){const b=document.getElementById("mad4b-mu-owner-launch"),s=document.getElementById("mad4b-mu-job-state");'
   .'const url='.wp_json_encode($url).',nonce='.wp_json_encode($nonce).';'
   .'const payload='.wp_json_encode(['company'=>self::COMPANY,'environment'=>self::ENVIRONMENT,
     'file'=>self::BASENAME,'sha256'=>self::SHA]).';'
   .'b.addEventListener("click",async()=>{b.disabled=true;s.textContent="Checking original 305 admission…";'
   .'try{const r=await fetch(url,{method:"POST",credentials:"same-origin",headers:'
   .'{"Content-Type":"application/json","X-WP-Nonce":nonce},body:JSON.stringify(payload)});'
   .'const d=await r.json();if(!r.ok)throw Error(d.message||d.code||"Original 305 rejected");'
   .'s.textContent="Original 306 queued · Job "+d.job_id;'
   .'for(let i=0;i<36;i++){await new Promise(resolve=>setTimeout(resolve,5000));'
   .'const q=await fetch(url+"/"+d.job_id,{credentials:"same-origin",headers:{"X-WP-Nonce":nonce}});'
   .'if(!q.ok)throw Error("Original Job status readback unavailable");'
   .'const x=await q.json();s.textContent="Job "+d.job_id+" · "+x.state;'
   .'if(x.original_313_certified===true){s.textContent="Original independent 313 VERIFIED · Job "+d.job_id;break;}'
   .'if(/EXPIRED|FAILED|ROLLBACK|RECONCILIATION/i.test(x.state))break;}'
   .'}catch(e){s.textContent="Not completed: "+e.message;}finally{b.disabled=false;}});})();</script></div>';
 }
 public static function boot():void {
  add_action('admin_menu',static function():void {
   if(wp_get_environment_type()==='staging'
     &&untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com')
    add_management_page('MAD4B MU Lifecycle','MAD4B MU Lifecycle','manage_options',
     'mad4b-mu-lifecycle',[__CLASS__,'render']);
  });
  add_action('rest_api_init',static function():void {
   register_rest_route('mad4b/v1',self::REST,[
    'methods'=>'POST','permission_callback'=>[__CLASS__,'allowed'],
    'callback'=>[__CLASS__,'enqueue']]);
   register_rest_route('mad4b/v1',self::REST.'/(?P<job_id>[0-9a-f]{32})',[
    'methods'=>'GET','permission_callback'=>[__CLASS__,'allowed'],
    'callback'=>[__CLASS__,'status']]);
  },101);
 }
}
MAD4B_Company_MU_Deploy_Owner_Launch_V1::boot();
