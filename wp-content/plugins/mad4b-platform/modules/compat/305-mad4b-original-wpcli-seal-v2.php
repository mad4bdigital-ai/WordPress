<?php
/**
 * Original 305 confidential WPCLI argv transfer to a formally enrolled host.
 *
 * Only original 305 authenticated intake may create the sealed record.
 * WordPress stores ciphertext and SHA, not raw command bytes. The host root
 * X25519 private key is never installed in PHP, WordPress or Docker.
 */
defined('ABSPATH') || exit;
final class MAD4B_Original_WPCLI_Sealed_Command_V2 {
 const CONTRACT='mad4b.original-305-wpcli-sealed-argv.v2';
 const HOST_KEY_CONTRACT='mad4b.original-305-wpcli-host-x25519.v1';
 const PREFIX='mad4b_original_305_sealed_wpcli_v2_';
 const KEY_PREFIX='mad4b_original_host_x25519_v1_';
 const KEY_FIELDS=['contract','host_id','public_key_b64','issued_epoch','nonce'];
 const MAX_BYTES=12000;
 private static function denied(string $why):WP_Error {
  return new WP_Error('mad4b_original_seal_'.$why,$why,['status'=>409]);
 }
 private static function host_key_verify(array $record,string $host):bool {
  if(array_keys($record)!==array_merge(self::KEY_FIELDS,['signature'])
    ||($record['contract']??null)!==self::HOST_KEY_CONTRACT
    ||($record['host_id']??null)!==$host
    ||!preg_match('/^[a-z0-9-]{4,64}$/D',$host)
    ||!is_int($record['issued_epoch'])
    ||$record['issued_epoch']>time()+10
    ||$record['issued_epoch']<time()-31536000
    ||!preg_match('/^[a-f0-9]{32}$/D',(string)$record['nonce']))
      return false;
  $pub=base64_decode((string)$record['public_key_b64'],true);
  if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_BOX_PUBLICKEYBYTES)return false;
  $signedKey=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $signature=base64_decode((string)($record['signature']??''),true);
  if(!is_string($signedKey)||strlen($signedKey)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
   ||!is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES)return false;
  $message=implode(chr(10),array_map(static fn($field)=>(string)$record[$field],self::KEY_FIELDS));
  return sodium_crypto_sign_verify_detached($signature,$message,$signedKey);
 }
 private static function validate_argv($argv):bool {
  if(!is_array($argv)||array_values($argv)!==$argv
     ||count($argv)<1||count($argv)>256)return false;
  foreach($argv as $arg)
   if(!is_string($arg)||strlen($arg)>8192
     ||preg_match('/[\x00\x0a\x0d]/',$arg)
     ||preg_match('/^--(?:path|url|ssh|http|user)(?:=|$)/i',$arg))return false;
  $s=wp_json_encode($argv,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  return is_string($s)&&strlen($s)<=4096;
 }
 public static function witness_valid($verification):bool {
  if($verification===null)return true;
  if(!is_array($verification)||array_keys($verification)!==[
     'readback_argv','rollback_argv','readback_expected_sha256',
     'rollback_expected_sha256'])return false;
  return self::validate_argv($verification['readback_argv'])
    &&self::validate_argv($verification['rollback_argv'])
    &&is_string($verification['readback_expected_sha256'])
    &&preg_match('/^[a-f0-9]{64}$/D',$verification['readback_expected_sha256'])
    &&is_string($verification['rollback_expected_sha256'])
    &&preg_match('/^[a-f0-9]{64}$/D',$verification['rollback_expected_sha256']);
 }
 public static function seal(array $receipt,array $argv,$verification) {
  if(!function_exists('sodium_crypto_box_seal')||
     !class_exists('MAD4B_Central_VPS_Broker_V1',false))
    return self::denied('original_sodium_or_broker_unavailable');
  $id=(string)($receipt['request_id']??'');
  $host=(string)($receipt['target_host_id']??'');
  if(!preg_match('/^[a-f0-9]{32}$/D',$id) ||
      ($receipt['target_scope']??'')!=='wordpress' ||
      ($receipt['command_format']??'')!=='wpcli.argv.json.v1' ||
      !self::validate_argv($argv) || !self::witness_valid($verification))
    return self::denied('invalid_owner_wpcli_or_witness');
  $record=get_option(self::KEY_PREFIX.$host,false);
  if(!is_array($record)||!self::host_key_verify($record,$host))
    return self::denied('original_signed_host_x25519_not_enrolled');
  $fields=[
   'contract'=>self::CONTRACT,'job_id'=>$id,
   'company'=>$receipt['company'],
   'environment'=>$receipt['environment'],
   'site_uuid'=>$receipt['site_uuid'],
   'canonical_origin'=>$receipt['canonical_origin'],
   'grant_id'=>$receipt['grant_id'],
   'transport_id'=>$receipt['transport_id'],
   'host_id'=>$host,
   'operation'=>$receipt['operation'],
   'command_format'=>$receipt['command_format'],
   'command_sha256'=>$receipt['command_sha256'],
   'argv'=>$argv,'verification'=>$verification,
  ];
  $plain=wp_json_encode($fields,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  if(!is_string($plain)||strlen($plain)>self::MAX_BYTES)
    return self::denied('encrypted_input_too_large');
  $public=base64_decode($record['public_key_b64'],true);
  $cipher=base64_encode(sodium_crypto_box_seal($plain,$public));
  if(strlen($cipher)>self::MAX_BYTES)
    return self::denied('encrypted_ciphertext_too_large');
  $sealHash=hash('sha256',$cipher);
  $saved=[
   'contract'=>self::CONTRACT,
   'request_id'=>$id,'host_id'=>$host,
   'ciphertext_b64'=>$cipher,
   'ciphertext_sha256'=>$sealHash,
   'command_sha256'=>$receipt['command_sha256'],
   'created_epoch'=>time(),
  ];
  if(!add_option(self::PREFIX.$id,$saved,'',false))
    return self::denied('exact_original_sealed_request_already_exists');
  return $sealHash;
 }
 public static function cli($args,$assoc):void {
  if(!defined('WP_CLI')||!WP_CLI || !class_exists('WP_CLI')
   ||!current_user_can('manage_options')
   ||!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
   ||!MAD4B_Central_Dual_Lane_Registry_V1::central())
   WP_CLI::error('ORIGINAL_CENTRAL_OPERATOR_REQUIRED');
  $action=$args[0]??'';
  if($action==='register-host-key'){
   if(array_keys($assoc)!==['payload'])WP_CLI::error('EXACT_ORIGINAL_SIGNED_KEY_REQUIRED');
   $decoded=base64_decode((string)$assoc['payload'],true);
   if(!is_string($decoded)||strlen($decoded)>2048)
     WP_CLI::error('ORIGINAL_SIGNED_KEY_PAYLOAD_OVERSIZE');
   $row=json_decode($decoded,true);
   $host=is_array($row)?(string)($row['host_id']??''):'';
   if(!is_array($row)||!self::host_key_verify($row,$host))
     WP_CLI::error('ORIGINAL_SIGNED_HOST_X25519_INVALID');
   $name=self::KEY_PREFIX.$host;
   $current=get_option($name,false);
   if($current!==false){
    if(!is_array($current)||($current['public_key_b64']??null)!==$row['public_key_b64']
      ||!self::host_key_verify($current,$host))
      WP_CLI::error('HOST_KEY_ROTATION_REQUIRES_SEPARATE_ORIGINAL_PROOF');
    WP_CLI::line(wp_json_encode(['state'=>'EXISTING_SIGNED_X25519_HOST_KEY_MATCHED',
      'host_id'=>$host,'new_grants'=>false]));return;
   }
   if(!add_option($name,$row,'',false))WP_CLI::error('HOST_KEY_SIGNED_REGISTRATION_FAILED');
   $back=get_option($name,false);
   if(!is_array($back)||($back['signature']??null)!==$row['signature'])
     WP_CLI::error('HOST_KEY_POSTWRITE_READBACK_MISMATCH');
   WP_CLI::line(wp_json_encode(['state'=>'ORIGINAL_SIGNED_X25519_HOST_KEY_REGISTERED',
      'host_id'=>$host,'new_grants'=>false]));return;
  }
  if($action==='inspect'){
   if(array_keys($assoc)!==['job'])WP_CLI::error('EXACT_SIGNED_JOB_REQUIRED');
   $job=(string)$assoc['job'];
   if(!preg_match('/^[a-f0-9]{32}$/D',$job))
     WP_CLI::error('EXACT_SIGNED_JOB_INVALID');
   $record=get_option(self::PREFIX.$job,false);
   if(!is_array($record)||($record['request_id']??'')!==$job)
     WP_CLI::error('ORIGINAL_305_SEALED_JOB_NOT_FOUND');
   $request=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$job,false);
   if(!is_array($request)||($request['command_sha256']??null)!==$record['command_sha256']
     ||($request['sealed_argv_sha256']??null)!==$record['ciphertext_sha256'])
     WP_CLI::error('ORIGINAL_SEALED_CIPHER_REQUEST_HASH_DRIFT');
   // Ciphertext only; no private key and no raw argv ever delivered to WP admin.
   WP_CLI::line(wp_json_encode(['contract'=>self::CONTRACT,
      'request_id'=>$job,'host_id'=>$record['host_id'],
      'command_sha256'=>$record['command_sha256'],
      'ciphertext_sha256'=>$record['ciphertext_sha256'],
      'ciphertext_b64'=>$record['ciphertext_b64']]));
   return;
  }
  WP_CLI::error('EXACT_ORIGINAL_WPCLI_SEAL_ACTION_REQUIRED');
 }
 public static function boot():void {
  if(class_exists('WP_CLI')&&defined('WP_CLI')&&WP_CLI)
   WP_CLI::add_command('mad4b original-wpcli-seal',[__CLASS__,'cli']);
 }
}
MAD4B_Original_WPCLI_Sealed_Command_V2::boot();
