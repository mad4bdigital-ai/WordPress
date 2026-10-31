<?php
/**
 * Plugin Name: MAD4B Signed VPS Broker Journal Bridge
 * Description: Validates signed, bounded VPS Broker journal snapshots through the existing central WP-CLI control channel.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_VPS_Broker_Journal_V1 {
 const CONTRACT='mad4b.vps-broker.journal.v1';
 const OPTION='mad4b_vps_broker_host_journal_v1';
 const NONCE='mad4b_vps_broker_host_journal_nonce_v1_';
 const ALLOWED=['HEARTBEAT_VERIFIED','CLAIM_ATTEMPTED','CLAIM_VERIFIED_NOT_EXECUTED','CLAIM_RESULT_UNCERTAIN','PENDING_NOT_ELIGIBLE'];
 static function validate(array $args) {
  if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
   || !MAD4B_Central_Dual_Lane_Registry_V1::central()
   || !class_exists('MAD4B_Central_VPS_Broker_V1',false)
   || !function_exists('sodium_crypto_sign_verify_detached'))
   return new WP_Error('central_staging_broker_required','Central staging broker required');
  $host=(string)($args['host']??'');
  $nonce=(string)($args['nonce']??'');
  $issued=(int)($args['issued']??0);$expires=(int)($args['expires']??0);
  $bytes=base64_decode((string)($args['payload']??''),true);
  $signature=base64_decode((string)($args['signature']??''),true);
  $key=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  if($host!==(string)get_option(MAD4B_Central_VPS_Broker_V1::HOST_ID,'')
     || !preg_match('/^[a-f0-9]{32}$/D',$nonce)
     || $issued<time()-90 || $issued>time()+10
     || $expires<$issued || $expires>$issued+60 || $expires<time()
     || !is_string($bytes) || strlen($bytes)>16384
     || !is_string($key) || strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
     || !is_string($signature) || strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES)
     return new WP_Error('host_payload_invalid','Host journal proof invalid');
  $message=implode("\n",[self::CONTRACT,'journal',$host,$nonce,
   (string)$issued,(string)$expires,hash('sha256',$bytes)]);
  if(!sodium_crypto_sign_verify_detached($signature,$message,$key))
   return new WP_Error('signature_invalid','Host journal signature invalid');
  $data=json_decode($bytes,true);
  if (!is_array($data) || ($data['contract']??'')!==self::CONTRACT
   || ($data['host_id']??'')!==$host
   || !isset($data['events']) || !is_array($data['events'])
   || count($data['events'])>30)
   return new WP_Error('journal_shape_invalid','Host journal shape invalid');
  $events=[];
  foreach($data['events'] as $event) {
   if (!is_array($event)
    || array_diff(array_keys($event),['event','at','job_id','company'])
    || !in_array($event['event']??'',self::ALLOWED,true)
    || !is_string($event['at']??null) || strlen($event['at'])>48)
    return new WP_Error('event_rejected','Non-catalog host event');
   $job=(string)($event['job_id']??'');
   if($job!==''&&!preg_match('/^[a-f0-9]{32}$/D',$job))
    return new WP_Error('invalid_event_job','Invalid event job ID');
   $company=(string)($event['company']??'');
   if($company!==''&&!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company))
    return new WP_Error('invalid_event_company','Invalid event company');
   $events[]=['event'=>$event['event'],'at'=>$event['at'],
    'job_id'=>$job,'company'=>$company];
  }
  return ['contract'=>self::CONTRACT,'host_id'=>$host,'events'=>$events,
   'signed_at'=>$issued,'verified_at'=>time(),'signature_verified'=>true,
   'host_shell_execution_verified'=>false,'production_mutation'=>false];
 }
 static function cli($args,$assoc):void {
  if (!defined('WP_CLI')||!WP_CLI||!class_exists('WP_CLI'))
   return;
  if (get_current_user_id()<1||!current_user_can('manage_options'))
   WP_CLI::error('Privileged local host bridge required');
  if(($args[0]??'')!=='sync')WP_CLI::error('sync only');
  $result=self::validate($assoc);
  if(is_wp_error($result))WP_CLI::error($result->get_error_code());
  if(!add_option(self::NONCE.$assoc['nonce'],time(),'',false))
   WP_CLI::error('signed_journal_replay');
  if (!update_option(self::OPTION,$result,false))WP_CLI::error('journal_update_failed');
  WP_CLI::line(wp_json_encode(['contract'=>self::CONTRACT,
   'host_id'=>$result['host_id'],'signature_verified'=>true,
   'count'=>count($result['events']),'verified_at'=>$result['verified_at'],
   'host_shell_execution_verified'=>false],JSON_UNESCAPED_SLASHES));
 }
 static function rest():void {
  if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
   || !MAD4B_Central_Dual_Lane_Registry_V1::central())return;
  register_rest_route('mad4b/v1','/vps-broker-host-journal',[
   'methods'=>'GET',
   'permission_callback'=>['MAD4B_Central_Dual_Lane_Registry_V1','allowed'],
   'callback'=>static fn()=>rest_ensure_response(get_option(self::OPTION,[]))]);
 }
 static function boot():void {
  add_action('rest_api_init',[__CLASS__,'rest'],95);
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b broker-journal',[__CLASS__,'cli']);
 }
}
MAD4B_VPS_Broker_Journal_V1::boot();
