<?php
/**
 * Plugin Name: MAD4B Broker Registry SSH Transport Readback
 * Description: Verifies host-signed Registry SSH observation, without issuing shell authority.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Broker_Registry_SSH_Status_V1 {
 const CONTRACT='mad4b.broker-registry-ssh-status.v1';
 const OPTION='mad4b_broker_registry_ssh_status_v1';
 const NONCE='mad4b_broker_registry_ssh_status_nonce_v1_';
 private static function central(): bool {
  return class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
   && MAD4B_Central_Dual_Lane_Registry_V1::central();
 }
 private static function fail(string $why):void { WP_CLI::error('registry_ssh_status_'.$why); }
 public static function cli($args,$assoc):void {
  if (!self::central() || get_current_user_id()<1 || !current_user_can('manage_options')) self::fail('central_admin_required');
  $action=(string)($args[0]??'');
  if ($action==='status') {
   $row=get_option(self::OPTION,['state'=>'NO_SIGNED_REGISTRY_OBSERVATION']);
   if (isset($row['received_epoch']) && (int)$row['received_epoch']<time()-600) {
    $row['state']='REGISTRY_TRANSPORT_STATUS_STALE';
    $row['registry_transport_authenticated']=false;
    $row['fixed_diagnostic_shell_authorized']=false;
   }
   WP_CLI::line(wp_json_encode($row));
   return;
  }
  if ($action!=='sync')self::fail('unknown_action');
  $host=(string)($assoc['host']??'');$nonce=(string)($assoc['nonce']??'');
  $issued=(int)($assoc['issued']??0);$expires=(int)($assoc['expires']??0);
  $payload=base64_decode((string)($assoc['payload']??''),true);
  $signature=base64_decode((string)($assoc['signature']??''),true);
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $knownhost=(string)get_option(MAD4B_Central_VPS_Broker_V1::HOST_ID,'');
  if ($host!==$knownhost||$host!=='srv2030908'||!preg_match('/^[a-f0-9]{32}$/D',$nonce)
    || $issued<time()-60||$issued>time()+10||$expires<$issued||$expires>$issued+60||$expires<time()
    || !is_string($payload)||strlen($payload)>8192
    || !is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES
    || !is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)self::fail('scope_or_signature_shape');
  $fields=[self::CONTRACT,'status',$host,$nonce,(string)$issued,(string)$expires,hash('sha256',$payload)];
  if (!sodium_crypto_sign_verify_detached($signature,implode("\n",$fields),$pub))self::fail('host_signature_invalid');
  $value=json_decode($payload,true);
  if (!is_array($value)||($value['contract']??'')!==self::CONTRACT
    || ($value['host_id']??'')!==$host
    || ($value['central_queue_execution_eligible']??null)!==false
    || ($value['production_mutation']??null)!==false
    || !is_array($value['targets']??null) || count($value['targets'])>2)self::fail('status_contract_invalid');
  $allowed=['hosting-1','hosting-3'];$seen=[];
  foreach($value['targets'] as $item) {
    if (!is_array($item)||!in_array($item['target_id']??null,$allowed,true)
      || in_array($item['target_id'],$seen,true)
      || !is_bool($item['ssh_authenticated']??null)
      || !is_bool($item['verified_shell_permission']??null)
      || !in_array($item['permission_scope']??'', ['none','fixed_nonmutating_diagnostic'],true)
      || ($item['verified_shell_permission']===true
          && ($item['ssh_authenticated']!==true || $item['permission_scope']!=='fixed_nonmutating_diagnostic'))
      || ($item['verified_shell_permission']===false && $item['permission_scope']!=='none'))
      self::fail('invalid_target');
    $seen[]=$item['target_id'];
  }
  if (!add_option(self::NONCE.$nonce,time(),'',false))self::fail('nonce_replayed');
  $row=['contract'=>self::CONTRACT,'host_id'=>$host,'received_epoch'=>time(),
   'signed_host_observation_verified'=>true,'targets'=>$value['targets'],
   'registry_transport_authenticated'=>($value['registry_transport_authenticated']??false)===true,
   'fixed_diagnostic_shell_authorized'=>($value['fixed_diagnostic_shell_authorized']??false)===true
        && count(array_filter($value['targets'],static fn($x)=>($x['verified_shell_permission']??false)===true))>0,
   'central_queue_execution_eligible'=>false,
   'central_grant_verifier_bound'=>false,
   'production_mutation'=>false,
   'blockers'=>array_values((array)($value['blockers']??[])),
   'state'=>'REGISTRY_TRANSPORT_VERIFIED_CENTRAL_AUTHORITY_PENDING'];
  update_option(self::OPTION,$row,false);
  WP_CLI::line(wp_json_encode(['contract'=>self::CONTRACT,'signature_verified'=>true,
   'host_id'=>$host,'targets_received'=>count($seen),'received_epoch'=>$row['received_epoch'],
   'central_queue_execution_eligible'=>false]));
 }
 public static function rest():void {
  if (!self::central())return;
  register_rest_route('mad4b/v1','/broker-registry-ssh-status',[
   'methods'=>'GET',
   'permission_callback'=>['MAD4B_Central_Dual_Lane_Registry_V1','allowed'],
   'callback'=>static function(){
    $row=get_option(self::OPTION,['state'=>'NO_SIGNED_REGISTRY_OBSERVATION']);
    if (isset($row['received_epoch']) && (int)$row['received_epoch']<time()-600) {
     $row['state']='REGISTRY_TRANSPORT_STATUS_STALE';
     $row['registry_transport_authenticated']=false;
     $row['fixed_diagnostic_shell_authorized']=false;
    }
    return rest_ensure_response($row);
   }]);
 }
 public static function boot():void {
  add_action('rest_api_init',[__CLASS__,'rest'],85);
  if (defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))WP_CLI::add_command('mad4b broker-registry-ssh',[__CLASS__,'cli']);
 }
}
MAD4B_Broker_Registry_SSH_Status_V1::boot();
