<?php
/**
 * Plugin Name: MAD4B Original 313 Owner Launcher
 * Description: Single authenticated REST action: exact read-only 305 request -> 306 dispatch. No grant issuance or WordPress actor impersonation.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Original_313_Owner_Launcher_V1 {
 const REST='/native-313/readonly-canary';
 const TARGET_COMPANY='wayfatours';
 const TARGET_ENV='production';
 const COMMAND=['option','get','home'];
 private static function active_central():bool {
  return class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
   && MAD4B_Central_Dual_Lane_Registry_V1::central()
   && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
 }
 public static function permitted(WP_REST_Request $r):bool {
  return self::active_central()
   && current_user_can('manage_options')
   && class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
   && MAD4B_Central_SSH_Command_Intake_V1::permitted($r);
 }
 public static function launch(WP_REST_Request $request) {
  if (!self::permitted($request))
   return new WP_Error('original_305_owner_rest_auth_required','Original WordPress administrator REST session required',['status'=>403]);
  if (!is_array($request->get_json_params()) || $request->get_json_params()!==[])
   return new WP_Error('exact_canary_shape_required','No caller-selected command or target accepted',['status'=>422]);
  foreach(['MAD4B_Central_SSH_Command_Intake_V1','MAD4B_Central_VPS_Broker_V1',
           'MAD4B_Original_Verified_WPCLI_Transport_V1','MAD4B_Company_Environment_Registry_V2'] as $c)
   if (!class_exists($c,false))return new WP_Error('original_native_component_missing','Original native component unavailable',['status'=>503]);
  $company=self::TARGET_COMPANY;$environment=self::TARGET_ENV;
  $target=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
  $slot=get_option('mad4b_company_native_wpcli_slot_v1_'.$company.'_'.$environment,false);
  if (!is_array($target)||!is_array($slot)
   || ($target['site_uuid']??null)!==($slot['site_uuid']??null))
   return new WP_Error('original_target_binding_changed','Registered company site is no longer enrolled',['status'=>409]);
  $c='MAD4B_Central_SSH_Command_Intake_V1';
  $g=get_option($c::PREFIX.(string)($slot['grant_id']??''),false);
  $t=get_option($c::TRANSPORT_PREFIX.(string)($slot['transport_id']??''),false);
  if (!is_array($g)||!is_array($t)
   || ($g['central_principal_id']??null)!==get_current_user_id()
   || ($g['operation']??null)!=='company.remote.wpcli.full'
   || ($g['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
   || ($g['site_uuid']??null)!==$target['site_uuid']
   || ($g['environment']??null)!==$environment
   || ($t['environment']??null)!==$environment
   || !MAD4B_Original_Verified_WPCLI_Transport_V1::active($t,$g))
   return new WP_Error('original_305_grant_not_eligible','Original exact owner grant or signed host binding inactive',['status'=>403]);
  $nonce=(string)$request->get_header('X-WP-Nonce');
  $correlation='native313-readonly-'.bin2hex(random_bytes(12));
  $inner=new WP_REST_Request('POST','/mad4b/v1/central-execution-lanes/'.$company.'/commands');
  $inner->set_url_params(['company'=>$company]);
  $inner->set_header('X-WP-Nonce',$nonce);
  $inner->set_header('Content-Type','application/json');
  $inner->set_body(wp_json_encode([
    'grant_id'=>$slot['grant_id'],'argv'=>self::COMMAND,
    'summary'=>'Original 313 nonmutating WPCLI site identity canary',
    'correlation_id'=>$correlation,'target_scope'=>'wordpress'
   ],JSON_UNESCAPED_SLASHES));
  $receipt=$c::accept_command($inner);
  if (is_wp_error($receipt))return $receipt;
  if (!($receipt instanceof WP_REST_Response))
   return new WP_Error('original_305_result_unknown','Original 305 acceptance uncertain',['status'=>503]);
  $accepted=$receipt->get_data();
  $id=is_array($accepted)?(string)($accepted['request_id']??''):'';
  if (!preg_match('/^[a-f0-9]{32}$/D',$id)
   || ($accepted['company']??null)!==$company
   || ($accepted['environment']??null)!==$environment
   || ($accepted['command_format']??null)!=='wpcli.argv.json.v1')
   return new WP_Error('original_305_receipt_mismatch','Original 305 request was not accepted as exact WPCLI canary',['status'=>503]);
  $dispatch=new WP_REST_Request('POST','/mad4b/v1/central-execution-lanes/'.$company.'/broker-dispatch');
  $dispatch->set_url_params(['company'=>$company]);
  $dispatch->set_header('X-WP-Nonce',$nonce);
  $dispatch->set_header('Content-Type','application/json');
  $dispatch->set_body(wp_json_encode(['request_id'=>$id]));
  $issued=MAD4B_Central_VPS_Broker_V1::dispatch($dispatch);
  if (is_wp_error($issued))return new WP_Error('original_306_dispatch_failed',
    '305 accepted but original 306 rejected the Job: '.$issued->get_error_code(),
    ['status'=>503,'request_id'=>$id]);
  if (!($issued instanceof WP_REST_Response))return new WP_Error('original_306_dispatch_uncertain',
    '305 accepted but 306 dispatch is uncertain',['status'=>503,'request_id'=>$id]);
  $j=$issued->get_data();
  if (!is_array($j) || ($j['job_id']??null)!==$id
   || ($j['state']??null)!=='QUEUED_FOR_VPS_BROKER_CLAIM'
   || ($j['company']??null)!==$company
   || ($j['environment']??null)!==$environment
   || ($j['job_type']??null)!=='signed_vps_wpcli')
   return new WP_Error('original_306_job_readback_mismatch','Broker Job readback uncertain',
    ['status'=>503,'request_id'=>$id]);
  return new WP_REST_Response(['state'=>'ORIGINAL_306_QUEUED',
   'job_id'=>$id,'company'=>$company,'environment'=>$environment,
   'read_only_canary'=>true,'grant_created'=>false,'original_313_certified'=>false],202);
 }
 public static function job_status(WP_REST_Request $request) {
  if (!self::permitted($request))
   return new WP_Error('original_owner_session_required','Original REST owner session required',['status'=>403]);
  $id=(string)$request['job_id'];
  if (!preg_match('/^[a-f0-9]{32}$/D',$id))
   return new WP_Error('invalid_job_id','Invalid original Job ID',['status'=>422]);
  $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
  if (!is_array($job) || ($job['job_id']??null)!==$id
   || ($job['company']??null)!==self::TARGET_COMPANY
   || ($job['environment']??null)!==self::TARGET_ENV
   || ($job['operation']??null)!=='company.remote.wpcli.full'
   || ($job['central_principal_id']??null)!==get_current_user_id())
   return new WP_Error('original_job_not_found','Job not found for the authenticated principal',['status'=>404]);
  $pending=get_option(MAD4B_Broker_Remote_SSH_Receipt_V1::WPCLI_PREFIX.$id,false);
  $finish=get_option(MAD4B_Broker_Remote_SSH_Receipt_V1::WPCLI_FINISH_PREFIX.$id,false);
  $verified=is_array($pending) && is_array($finish)
   && ($job['state']??null)==='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED'
   && ($job['executed']??null)===true
   && ($job['independent_readback_verified']??null)===true
   && ($pending['state']??null)==='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED'
   && ($pending['native_executor_certified']??null)===true
   && ($pending['independent_host_readback_verified']??null)===true
   && ($pending['native_receipt_sha256']??null)!==null
   && hash_equals((string)$pending['native_receipt_sha256'],(string)($finish['native_receipt_sha256']??''));
  $state=$verified?'WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED':(string)($job['state']??'UNKNOWN');
  if (!$verified && (int)($job['expires_epoch']??0)<time())$state='EXPIRED_WITHOUT_ORIGINAL_313_CERTIFICATE';
  return new WP_REST_Response(['job_id'=>$id,'state'=>$state,
   'original_313_certified'=>$verified,'production_mutation'=>false],200);
 }
 public static function routes():void {
  if (!self::active_central())return;
  register_rest_route('mad4b/v1',self::REST,[
    'methods'=>'POST','permission_callback'=>[__CLASS__,'permitted'],
    'callback'=>[__CLASS__,'launch']]);
  register_rest_route('mad4b/v1',self::REST.'/(?P<job_id>[a-f0-9]{32})',[
    'methods'=>'GET','permission_callback'=>[__CLASS__,'permitted'],
    'callback'=>[__CLASS__,'job_status']]);
 }
 public static function menu():void {
  if (!self::active_central()||!current_user_can('manage_options'))return;
  add_management_page('MAD4B Native 313','MAD4B Native 313','manage_options',
   'mad4b-native-313',[__CLASS__,'render']);
 }
 public static function render():void {
  if (!current_user_can('manage_options')||!self::active_central())return;
  $url=rest_url('mad4b/v1'.self::REST);
  $nonce=wp_create_nonce('wp_rest');
  echo '<div class="wrap"><h1>MAD4B · Native 313</h1>';
  echo '<p>Exact signed read-only canary: Wayfa Production. The original 305 owner grant is used; no new grant is created.</p>';
  echo '<p><strong>Run:</strong> WP-CLI <code>option get home</code>. The 306/312/313 worker independently verifies the result.</p>';
  echo '<button id="mad4b-313-run" class="button button-primary">Queue verified read-only canary</button>';
  echo '<p id="mad4b-313-status" role="status" aria-live="polite"></p>';
  echo '<script>(function(){const b=document.getElementById("mad4b-313-run"),s=document.getElementById("mad4b-313-status");'
   .'b.addEventListener("click",async function(){b.disabled=true;s.textContent="Requesting original signed Job…";'
   .'try{const r=await fetch('.wp_json_encode($url).',{method:"POST",credentials:"same-origin",'
   .'headers:{"Content-Type":"application/json","X-WP-Nonce":'.wp_json_encode($nonce).'},body:"{}"});'
   .'const j=await r.json();if(!r.ok)throw Error(j.message||j.code||"Original grant or REST session rejected");'
   .'s.textContent="Original 306 Job queued: "+j.job_id+". Checking independent 313 readback…";'
   .'for(let n=0;n<30;n++){await new Promise(resolve=>setTimeout(resolve,8000));'
   .'const q=await fetch('.wp_json_encode($url).'+"/"+j.job_id,{credentials:"same-origin",headers:{"X-WP-Nonce":'.wp_json_encode($nonce).'}});'
   .'if(!q.ok)throw Error("Original Job status unavailable");const x=await q.json();'
   .'s.textContent="Job "+j.job_id+": "+x.state;'
   .'if(x.original_313_certified===true){s.textContent="Original 313 VERIFIED · Job "+j.job_id;break;}'
   .'if(x.state==="EXPIRED_WITHOUT_ORIGINAL_313_CERTIFICATE")break;}'

   .'}catch(e){s.textContent="Not queued: "+e.message;}finally{b.disabled=false;}});})();</script>';
  echo '</div>';
 }
 public static function boot():void {
  add_action('rest_api_init',[__CLASS__,'routes'],100);
  add_action('admin_menu',[__CLASS__,'menu']);
 }
}
MAD4B_Original_313_Owner_Launcher_V1::boot();
