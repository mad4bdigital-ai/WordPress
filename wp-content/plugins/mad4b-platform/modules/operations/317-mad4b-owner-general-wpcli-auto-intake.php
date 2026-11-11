<?php
/**
 * Plugin Name: MAD4B Owner-Authenticated General WP-CLI Auto-Intake
 * Description: Reuses original approved persistent central grants; never issues host privileges or executes shell.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_General_WPCLI_Auto_Intake_V1 {
 const CONTRACT='mad4b.general-wpcli-owner-auto-intake.v1';
 static function waiting($reason) {
   return rest_ensure_response(['contract'=>self::CONTRACT,
     'state'=>'AWAITING_ORIGINAL_CENTRAL_AUTHORITY','reason'=>$reason,
     'grant_issued'=>false,'shell_executed'=>false]);
 }
 static function route() {
   if (!class_exists('MAD4B_Central_SSH_Command_Intake_V1',false) ||
       !class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false) ||
       !MAD4B_Central_Dual_Lane_Registry_V1::central()) return;
   register_rest_route('mad4b/v1',
     '/central-execution-lanes/(?P<company>[a-z][a-z0-9-]{2,47})/auto-commands',
     ['methods'=>'POST',
      'permission_callback'=>['MAD4B_Central_SSH_Command_Intake_V1','permitted'],
      'callback'=>[__CLASS__,'intake']]);
 }
 static function intake(WP_REST_Request $request) {
   if (!MAD4B_Central_SSH_Command_Intake_V1::permitted($request))
     return new WP_Error('mad4b_auto_intake_owner_required',
       'Enrolled central owner authentication required',['status'=>403]);
   $company=(string)$request['company'];
   $input=$request->get_json_params();
   if (!is_array($input) || array_diff(array_keys($input),
       ['command','summary','correlation_id','host_id','host_principal','provision_transport_id']))
     return new WP_Error('mad4b_auto_intake_invalid_shape','Invalid request',['status'=>422]);
   $command=$input['command']??null;
   $summary=(string)($input['summary']??'');
   $correlation=(string)($input['correlation_id']??'');
   $host=(string)($input['host_id']??'');
   $principal=(string)($input['host_principal']??'');
   if (!is_string($command) || strlen($command)<5 || strlen($command)>4096 ||
       !preg_match('/^[A-Za-z0-9_.-]{8,100}$/D',$correlation) ||
       strlen($summary)<10 || strlen($summary)>240 ||
       !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{2,79}$/D',$host) ||
       !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D',$principal))
     return new WP_Error('mad4b_auto_intake_invalid_fields','Invalid fields',['status'=>422]);
   $decoded=json_decode($command,true);
   if (!is_array($decoded) || ($decoded['contract']??'')!=='mad4b.wpcli-exact-operation.v1' ||
       !isset($decoded['args'],$decoded['readback_args'],$decoded['expected_readback_sha256']) ||
       !is_array($decoded['args']) || !is_array($decoded['readback_args']) ||
       !preg_match('/^[a-f0-9]{64}$/D',(string)$decoded['expected_readback_sha256']))
     return new WP_Error('mad4b_auto_intake_command_contract_invalid',
       'Only exact canonical WP-CLI operations are accepted',['status'=>422]);
   $target=MAD4B_Manager_Auto_Write_V1::company_target($company);
   if (($target['state']??'')!=='REGISTERED')
     return self::waiting('ORIGINAL_COMPANY_TARGET_NOT_ENROLLED');
   global $wpdb;
   $prefix=MAD4B_Central_SSH_Command_Intake_V1::PREFIX;
   $keys=$wpdb->get_col($wpdb->prepare(
       'SELECT option_name FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 300',
       $wpdb->esc_like($prefix).'%'));
   foreach((array)$keys as $key) {
     $grant=get_option($key,false);
     if (!is_array($grant) || ($grant['contract']??'')!=='mad4b.central-ssh-command-intake.v1' ||
         ($grant['state']??'')!=='CENTRAL_OPERATION_SCOPE_GRANTED' ||
         ($grant['grant_mode']??'')!=='persistent' ||
         ($grant['company']??'')!==$company ||
         ($grant['operation']??'')!=='maintenance.shell' ||
         (int)($grant['central_principal_id']??0)!==get_current_user_id() ||
         ($grant['target_host_id']??'')!==$host ||
         ($grant['target_ssh_principal']??'')!==$principal ||
         ($grant['site_uuid']??'')!==($target['site_uuid']??'') ||
         ($grant['canonical_origin']??'')!==($target['canonical_origin']??'') ||
         ($grant['environment']??'')!==($target['environment']??''))
       continue;
     $tid=(string)($grant['transport_id']??'');
     $transport=get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$tid,false);
     if (!is_array($transport) || ($transport['state']??'')!=='PENDING_INDEPENDENT_SSH_VERIFICATION' ||
         ($transport['host_id']??'')!==$host ||
         ($transport['company']??'')!==$company) continue;
     $claimkey=MAD4B_Central_SSH_Command_Intake_V1::CLAIM_PREFIX.
       $grant['grant_id'].'_'.hash('sha256',$correlation);
     $existing=get_option($claimkey,false);
     if (is_string($existing) && preg_match('/^[a-f0-9]{32}$/D',$existing)) {
       $old=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$existing,false);
       if (is_array($old) && ($old['correlation_id']??'')===$correlation &&
           ($old['company']??'')===$company &&
           ($old['command_sha256']??'')===hash('sha256',$command) &&
           ($old['grant_id']??'')===$grant['grant_id']) {
         return rest_ensure_response(['contract'=>self::CONTRACT,'state'=>'REUSED_ORIGINAL_REQUEST',
           'request_id'=>$existing,'grant_id'=>$grant['grant_id'],
           'grant_issued'=>false,'shell_executed'=>false]);
       }
       return new WP_Error('mad4b_auto_intake_correlation_conflict',
         'Original correlation was already claimed for a different operation',['status'=>409]);
     }
     $internal=new WP_REST_Request('POST','/');
     $internal->set_url_params(['company'=>$company]);
     $internal->set_header('Content-Type','application/json');
     $internal->set_header('X-WP-Nonce',(string)$request->get_header('X-WP-Nonce'));
     $internal->set_body(wp_json_encode([
       'grant_id'=>$grant['grant_id'],'command'=>$command,
       'summary'=>$summary,'correlation_id'=>$correlation,
       'target_scope'=>'wordpress'],JSON_UNESCAPED_SLASHES));
     return MAD4B_Central_SSH_Command_Intake_V1::accept_command($internal);
   }
   $tid=(string)($input['provision_transport_id']??'');
   if ($tid!=='' && preg_match('/^[a-f0-9]{32}$/D',$tid)) {
     // Original owner-authorized issuance, never a synthetic WP-CLI user.
     $issue=new WP_REST_Request('POST','/');
     $issue->set_url_params(['company'=>$company]);
     $issue->set_header('Content-Type','application/json');
     $issue->set_header('X-WP-Nonce',(string)$request->get_header('X-WP-Nonce'));
     $issue->set_body(wp_json_encode([
       'operation'=>'maintenance.shell','host_id'=>$host,
       'host_principal'=>$principal,'grant_mode'=>'persistent',
       'transport_id'=>$tid,'reason'=>$summary],JSON_UNESCAPED_SLASHES));
     $issued=MAD4B_Central_SSH_Command_Intake_V1::issue($issue);
     if (is_wp_error($issued))return $issued;
     if (!($issued instanceof WP_REST_Response) || $issued->get_status()!==201)
       return self::waiting('ORIGINAL_GRANT_ISSUANCE_NOT_READY');
     $grant=$issued->get_data();
     $cmd=new WP_REST_Request('POST','/');
     $cmd->set_url_params(['company'=>$company]);
     $cmd->set_header('Content-Type','application/json');
     $cmd->set_header('X-WP-Nonce',(string)$request->get_header('X-WP-Nonce'));
     $cmd->set_body(wp_json_encode([
       'grant_id'=>$grant['grant_id'],'command'=>$command,
       'summary'=>$summary,'correlation_id'=>$correlation,
       'target_scope'=>'wordpress'],JSON_UNESCAPED_SLASHES));
     return MAD4B_Central_SSH_Command_Intake_V1::accept_command($cmd);
   }
   return self::waiting('NO_ACTIVE_MATCHING_OWNER_STANDING_GRANT');
 }
}
add_action('rest_api_init',['MAD4B_General_WPCLI_Auto_Intake_V1','route'],95);
