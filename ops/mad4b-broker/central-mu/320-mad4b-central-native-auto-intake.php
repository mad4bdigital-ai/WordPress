<?php
/**
 * Plugin Name: MAD4B Central Native Host Authenticated Auto Intake
 * Description: Reuse the owner's original 305 persistent local grant and atomically request 306 broker dispatch. No Shell execution or grant issuance.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Central_Native_Auto_Intake_V1 {
 const CONTRACT='mad4b.central-native-auto-intake.v1';
 const COMPANY='tours-central';
 const HOST='srv2030908';
 const PRINCIPAL='root';
 const CREDENTIAL='vault:registry/local-srv2030908';
 private static function deny($code,$http=403) {
  return new WP_Error('mad4b_central_native_auto_'.$code,$code,
    ['status'=>$http,'contract'=>self::CONTRACT]);
 }
 private static function original_auth(WP_REST_Request $request):bool {
  return class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
   && MAD4B_Central_SSH_Command_Intake_V1::permitted($request)
   && class_exists('MAD4B_Central_Host_Scope_V1',false)
   && MAD4B_Central_Host_Scope_V1::current_owner();
 }
 private static function matching_grants(array $identity,$requested_id):array {
  global $wpdb;
  if ($requested_id!=='' && !preg_match('/^[a-f0-9]{32}$/D',$requested_id))return [];
  $prefix=MAD4B_Central_SSH_Command_Intake_V1::PREFIX;
  $keys=$requested_id!==''?[$prefix.$requested_id]:
    $wpdb->get_col($wpdb->prepare('SELECT option_name FROM '.$wpdb->options.
      ' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 300',$wpdb->esc_like($prefix).'%'));
  $found=[];
  foreach((array)$keys as $key) {
   $g=get_option($key,false);
   if (!is_array($g) || ($g['contract']??'')!==MAD4B_Central_SSH_Command_Intake_V1::CONTRACT
    || ($g['state']??'')!=='CENTRAL_OPERATION_SCOPE_GRANTED'
    || ($g['grant_mode']??'')!=='persistent'
    || ($g['expires_epoch']??false)!==null
    || ($g['company']??'')!==self::COMPANY
    || ($g['operation']??'')!=='maintenance.shell'
    || ($g['target_host_id']??'')!==self::HOST
    || ($g['target_ssh_principal']??'')!==self::PRINCIPAL
    || (int)($g['central_principal_id']??0)!==get_current_user_id()
    || ($g['site_uuid']??'')!==($identity['site_uuid']??'')
    || ($g['canonical_origin']??'')!==($identity['canonical_origin']??'')
    || ($g['environment']??'')!=='staging'
    || ($g['assignment_revision']??null)!==0)continue;
   $tid=(string)($g['transport_id']??'');
   if (!preg_match('/^[a-f0-9]{32}$/D',$tid))continue;
   $t=get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$tid,false);
   if (!is_array($t) || ($t['state']??'')!=='PENDING_INDEPENDENT_SSH_VERIFICATION'
    || ($t['company']??'')!==self::COMPANY
    || ($t['host_id']??'')!==self::HOST
    || ($t['host_principal']??'')!==self::PRINCIPAL
    || ($t['credential_ref']??'')!==self::CREDENTIAL
    || ($t['site_uuid']??'')!==$identity['site_uuid']
    || ($t['canonical_origin']??'')!==$identity['canonical_origin']
    || ($t['environment']??'')!=='staging'
    || ($t['assignment_revision']??null)!==0
    || (int)($t['central_principal_id']??0)!==get_current_user_id())continue;
   $found[]=$g;
   if (count($found)>1)break;
  }
  return $found;
 }
 private static function original_request(WP_REST_Request $source,array $payload):WP_REST_Request {
  $r=new WP_REST_Request('POST','/');
  $r->set_url_params(['company'=>self::COMPANY]);
  $r->set_header('Content-Type','application/json');
  $r->set_header('X-WP-Nonce',(string)$source->get_header('X-WP-Nonce'));
  $r->set_body(wp_json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return $r;
 }
 private static function dispatch(WP_REST_Request $source,$request_id) {
  $response=MAD4B_Central_VPS_Broker_V1::dispatch(
   self::original_request($source,['request_id'=>$request_id]));
  if (is_wp_error($response))return $response;
  if (!($response instanceof WP_REST_Response))return self::deny('broker_dispatch_invalid',503);
  $job=$response->get_data();
  if (!is_array($job) || ($job['job_id']??null)!==$request_id
   || ($job['company']??'')!==self::COMPANY
   || ($job['target_host_id']??'')!==self::HOST
   || ($job['state']??'')!=='QUEUED_FOR_VPS_BROKER_CLAIM')
    return self::deny('broker_job_readback_mismatch',409);
  return new WP_REST_Response([
   'contract'=>self::CONTRACT,'state'=>$job['state'],
   'job_id'=>$job['job_id'],'correlation_id'=>$job['correlation_id'],
   'command_sha256'=>$job['command_sha256'],
   'company'=>self::COMPANY,'target_host_id'=>self::HOST,
   'independent_host_auth_required'=>true,
   'execution_performed'=>false,'grant_issued'=>false],$response->get_status());
 }
 public static function intake(WP_REST_Request $request) {
  if (!self::original_auth($request))return self::deny('enrolled_owner_required');
  if (!class_exists('MAD4B_Central_VPS_Broker_V1',false))
   return self::deny('original_broker_missing',503);
  $input=$request->get_json_params();
  if (!is_array($input) || array_diff(array_keys($input),
   ['grant_id','command','summary','correlation_id']) ||
   !isset($input['command'],$input['summary'],$input['correlation_id']))
    return self::deny('input_shape_invalid',422);
  $command=$input['command'];$summary=$input['summary'];
  $correlation=$input['correlation_id'];$id=(string)($input['grant_id']??'');
  if (!is_string($command)||trim($command)===''||strlen($command)>4096
   || strpos($command,"\0")!==false || !is_string($summary)
   || strlen($summary)<10||strlen($summary)>240
   || !is_string($correlation) || !preg_match('/^[a-zA-Z0-9_.-]{8,100}$/D',$correlation))
    return self::deny('command_shape_invalid',422);
  $host=MAD4B_Central_Host_Scope_V1::inspect();
  if (($host['state']??'')!=='CENTRAL_HOST_IDENTITY_VERIFIED')
    return self::deny('central_identity_not_ready',409);
  $matches=self::matching_grants($host,$id);
  if (count($matches)===0)
   return new WP_REST_Response([
    'contract'=>self::CONTRACT,'state'=>'AWAITING_ORIGINAL_OWNER_STANDING_GRANT',
    'grant_issued'=>false,'execution_performed'=>false,
    'required'=>'owner-enrolled 305 local transport and persistent grant'],409);
  if (count($matches)!==1)return self::deny('multiple_matching_grants_require_exact_id',409);
  $grant=$matches[0];
  $claim=MAD4B_Central_SSH_Command_Intake_V1::CLAIM_PREFIX.$grant['grant_id'].
   '_'.hash('sha256',$correlation);
  $previous=get_option($claim,false);
  if (is_string($previous) && preg_match('/^[a-f0-9]{32}$/D',$previous)) {
   $old=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$previous,false);
   if (!is_array($old) || ($old['correlation_id']??'')!==$correlation
    || ($old['company']??'')!==self::COMPANY
    || ($old['grant_id']??'')!==$grant['grant_id']
    || ($old['command_sha256']??'')!==hash('sha256',$command))
     return self::deny('correlation_conflict',409);
   return self::dispatch($request,$previous);
  }
  $receipt=MAD4B_Central_SSH_Command_Intake_V1::accept_command(
   self::original_request($request,[
    'grant_id'=>$grant['grant_id'],'command'=>$command,
    'summary'=>$summary,'correlation_id'=>$correlation,'target_scope'=>'host']));
  if (is_wp_error($receipt))return $receipt;
  if (!($receipt instanceof WP_REST_Response))return self::deny('intake_invalid',503);
  $data=$receipt->get_data();
  if (!is_array($data)||!preg_match('/^[a-f0-9]{32}$/D',(string)($data['request_id']??'')))
   return self::deny('intake_readback_mismatch',409);
  return self::dispatch($request,$data['request_id']);
 }
 public static function routes():void {
  if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
   || !MAD4B_Central_Dual_Lane_Registry_V1::central())return;
  register_rest_route('mad4b/v1','/central-native-host/auto-commands',[
   'methods'=>'POST',
   'permission_callback'=>[__CLASS__,'original_auth'],
   'callback'=>[__CLASS__,'intake']]);
 }
 public static function boot():void {
  add_action('rest_api_init',[__CLASS__,'routes'],95);
 }
}
MAD4B_Central_Native_Auto_Intake_V1::boot();
