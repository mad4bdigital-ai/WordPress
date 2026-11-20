<?php
/**
 * Sealed pre-dispatch recovery through the EXISTING 305 command intake.
 * The original signed operator payload is reintroduced ONLY in memory.
 * Never stores raw commands in wp_options and never executes them.
 */
defined('ABSPATH') || exit;
final class MAD4B_Original_Sealed_Command_Rebind_V1 {
    const CONTRACT='mad4b.original-core-sealed-command-rebind.v1';
    const FENCE='mad4b_central_broker_recovery_job_once_v1_';
    private static function reject(string $reason,int $status=409) {
        return new WP_Error('mad4b_original_rebind_'.$reason,$reason,
            array('status'=>$status,'contract'=>self::CONTRACT));
    }
    private static function digest(array $old,array $input) {
        if (($old['command_format']??'')==='wpcli.argv.json.v1') {
            $argv=$input['argv']??null;
            if (!is_array($argv)||array_values($argv)!==$argv||
                count($argv)<1||count($argv)>256||isset($input['command']))return false;
            foreach($argv as $a)if(!is_string($a)||strlen($a)>8192
                ||preg_match('/[\x00\x0a\x0d]/',$a)
                ||preg_match('/^--(?:path|url|ssh|http)(?:=|$)/i',$a))
                return false;
            $body=wp_json_encode($argv,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            return is_string($body)&&strlen($body)<=4096?hash('sha256',$body):false;
        }
        if (($old['command_format']??'')==='maintenance.shell.text.v1') {
            $body=$input['command']??null;
            if (!is_string($body)||trim($body)===''||strlen($body)>4096
                ||strpos($body,"\0")!==false||isset($input['argv']))return false;
            return hash('sha256',$body);
        }
        return false;
    }
    private static function verify(array $old,array $input,string $digest):bool {
        if (!function_exists('sodium_crypto_sign_verify_detached'))return false;
        if (($input['contract']??'')!==self::CONTRACT||
            ($input['original_job_id']??'')!==$old['job_id']||
            ($input['command_sha256']??'')!==$digest||
            ($input['command_sha256']??'')!==$old['command_sha256']||
            !in_array(($input['provider']??''),array('container_exec','coolify_service','vps_ssh'),true)||
            !preg_match('/^[a-f0-9]{32}$/D',(string)($input['nonce']??''))||
            !is_int($input['issued']??null)||!is_int($input['expires']??null)||
            $input['issued']<time()-60||$input['issued']>time()+10||
            $input['expires']<$input['issued']||
            $input['expires']>$input['issued']+60||
            $input['expires']<time())return false;
        $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
        $sig=base64_decode((string)($input['signature']??''),true);
        if (!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||
            !is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)return false;
        $message=implode("\n",array(
            self::CONTRACT,'original-core-rebind',
            $old['job_id'],$old['company'],$old['site_uuid'],
            $old['canonical_origin'],$old['environment'],
            $old['target_host_id'],$old['grant_id'],$old['transport_id'],
            (string)$old['central_principal_id'],$old['operation'],
            $input['provider'],$digest,$input['nonce'],
            (string)$input['issued'],(string)$input['expires']
        ));
        return sodium_crypto_sign_verify_detached($sig,$message,$pub);
    }
    public static function execute(string $originalId,array $input,array $originalProof) {
        $liveEvidence=class_exists('MAD4B_Original_Core_Recovery_V1',false)
            ? MAD4B_Original_Core_Recovery_V1::readOriginalRecoveryEvidence($originalId)
            : null;
        if (!is_array($liveEvidence)
            ||!is_array($liveEvidence['original_job']??null)
            ||($liveEvidence['original_job']['job_id']??'')!==$originalId
            ||($liveEvidence['original_job']['command_sha256']??'')!==
                ($originalProof['original_job']['command_sha256']??'')
            ||($liveEvidence['original_job']['site_uuid']??'')!==
                ($originalProof['original_job']['site_uuid']??''))
            return self::reject('original_signed_pre_dispatch_journal_required');
        $original=$liveEvidence['original_job'];
        $rest=$input['authenticated_request']??null;
        $sealed=$input['sealed_payload']??null;
        if (!($rest instanceof WP_REST_Request)||
            !MAD4B_Central_SSH_Command_Intake_V1::permitted($rest)||
            get_current_user_id()<1)return self::reject('authenticated_original_operator_required',403);
        if (!is_array($original)||!is_array($sealed)||
            ($original['job_id']??'')!==$originalId||
            ($original['environment']??'')!=='staging'||
            (int)($original['central_principal_id']??0)!==get_current_user_id()||
            (string)$rest['company']!==($original['company']??''))return self::reject('scope_or_operator_mismatch',403);
        $digest=self::digest($original,$sealed);
        if (!is_string($digest)||$digest!==($original['command_sha256']??'')||
            !self::verify($original,$sealed,$digest))return self::reject('signed_payload_or_digest_invalid');
        $provider=(string)$sealed['provider'];
        if (($liveEvidence['signed_failure']['provider']??'')===$provider)
            return self::reject('same_failed_provider_cannot_be_replayed');
        // The only installed original provider registration at present is
        // an explicitly scoped, owner-enrolled VPS SSH transport. Other
        // providers require their own original-Core registry adapter first.
        if ($provider!=='vps_ssh')
            return self::reject('alternate_provider_not_enrolled_in_original_core');
        if (($original['operation']??'')!=='maintenance.shell')
            return self::reject('shell_provider_cannot_claim_wpcli_operation');
        $transport=get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.
            (string)$original['transport_id'],false);
        if (!is_array($transport)||
            ($transport['state']??'')!=='PENDING_INDEPENDENT_SSH_VERIFICATION'||
            ($transport['company']??'')!==$original['company']||
            ($transport['site_uuid']??'')!==$original['site_uuid']||
            ($transport['host_id']??'')!==$original['target_host_id']||
            ($transport['host_principal']??'')!==$original['target_ssh_principal'])
            return self::reject('independent_registered_ssh_transport_required');
        $grant=get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.
            (string)$original['grant_id'],false);
        if (!is_array($grant)||($grant['state']??'')!=='CENTRAL_OPERATION_SCOPE_GRANTED'
            ||($grant['operation']??'')!==$original['operation']
            ||($grant['company']??'')!==$original['company']
            ||($grant['central_principal_id']??0)!==get_current_user_id())
            return self::reject('original_grant_revoked');
        // The exclusive old-job gate has already been consumed by the signed
        // pre-dispatch failure; this second durable fence allows one new job.
        // If any later step fails, keep the fence: uncertain outcomes do NOT
        // silently retry or re-enter with another command.
        if (!add_option(self::FENCE.$originalId,
            array('original_job_id'=>$originalId,'state'=>'PREPARING',
                'command_sha256'=>$digest,'issued_epoch'=>time()),'',false))
            return self::reject('recovery_already_reserved');
        $payload=array(
            'grant_id'=>$original['grant_id'],
            'summary'=>'Original Core signed pre-dispatch recovery '.$originalId,
            'correlation_id'=>'recovery_'.$originalId.'_'.$sealed['nonce'],
            'target_scope'=>$original['target_scope']
        );
        if (($original['command_format']??'')==='maintenance.shell.text.v1')
            $payload['command']=$sealed['command'];
        else $payload['argv']=$sealed['argv'];
        // Run the existing 305 intake, preserving all original company/site,
        // transport, grant, user and command validation.
        $intake=new WP_REST_Request('POST','/');
        $intake->set_param('company',$original['company']);
        $nonce=(string)$rest->get_header('X-WP-Nonce');
        if ($nonce!=='')$intake->set_header('X-WP-Nonce',$nonce);
        $intake->set_header('Content-Type','application/json');
        $intake->set_body(wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $received=MAD4B_Central_SSH_Command_Intake_V1::accept_command($intake);
        if (is_wp_error($received)||!($received instanceof WP_REST_Response))
            return self::reject('original_intake_failed_parked',503);
        $requestId=$received->data['request_id']??'';
        if (!preg_match('/^[a-f0-9]{32}$/D',$requestId)||
            ($received->data['command_sha256']??'')!==$digest||
            ($received->data['company']??'')!==$original['company'])
            return self::reject('original_intake_receipt_uncertain',503);
        $dispatch=new WP_REST_Request('POST','/');
        $dispatch->set_param('company',$original['company']);
        if ($nonce!=='')$dispatch->set_header('X-WP-Nonce',$nonce);
        $dispatch->set_header('Content-Type','application/json');
        $dispatch->set_body(wp_json_encode(array('request_id'=>$requestId)));
        $created=MAD4B_Central_VPS_Broker_V1::dispatch($dispatch);
        if (is_wp_error($created)||!($created instanceof WP_REST_Response))
            return self::reject('original_broker_dispatch_uncertain',503);
        $new=$created->data;
        if (($new['job_id']??'')!==$requestId||
            ($new['command_sha256']??'')!==$digest||
            ($new['company']??'')!==$original['company']||
            ($new['site_uuid']??'')!==$original['site_uuid']||
            ($new['canonical_origin']??'')!==$original['canonical_origin']||
            ($new['environment']??'')!=='staging'||
            ($new['operation']??'')!==$original['operation']||
            ($new['grant_id']??'')!==$original['grant_id']||
            ($new['transport_id']??'')!==$original['transport_id']||
            ($new['target_host_id']??'')!==$original['target_host_id']||
            ($new['target_ssh_principal']??'')!==$original['target_ssh_principal']||
            (int)($new['central_principal_id']??0)!==$original['central_principal_id']||
            ($new['state']??'')!=='QUEUED_FOR_VPS_BROKER_CLAIM'||
            ($new['executed']??true)!==false)
            return self::reject('original_broker_new_job_readback_uncertain',503);
        if (!update_option(self::FENCE.$originalId,array(
            'original_job_id'=>$originalId,'state'=>'ORIGINAL_CORE_RECOVERY_JOB_COMMITTED',
            'new_job_id'=>$requestId,'command_sha256'=>$digest,'issued_epoch'=>time()),false))
            return self::reject('original_recovery_commit_audit_uncertain',503);
        return array(
            'state'=>'ORIGINAL_CORE_RECOVERY_JOB_COMMITTED',
            'original_job_id'=>$originalId,'new_job_id'=>$requestId,
            'operation'=>$original['operation'],'provider'=>'vps_ssh',
            'company'=>$original['company'],'site_uuid'=>$original['site_uuid'],
            'canonical_origin'=>$original['canonical_origin'],
            'environment'=>'staging','target_host_id'=>$original['target_host_id'],
            'command_sha256'=>$digest,'executed'=>false);
    }
}


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
    ||$record['issued_epoch']<=0
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
     ||preg_match('/^--(?:path|url|ssh|http)(?:=|$)/i',$arg))return false;
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
  $target=(string)($receipt['target_host_id']??'');
  $host=$target;
  // Original 313 decrypts sealed argv on the VPS Broker, even for a
  // separately verified remote Hostinger WordPress execution target.
  // Never assign the broker's private X25519 key to the SSH host.
  if(in_array($target,['hosting-2','hosting-3'],true)) {
   $co=(string)($receipt['company']??'');$env=(string)($receipt['environment']??'');
   $i='MAD4B_Central_SSH_Command_Intake_V1';
   $t=get_option($i::TRANSPORT_PREFIX.(string)($receipt['transport_id']??''),false);
   $g=get_option($i::PREFIX.(string)($receipt['grant_id']??''),false);
   if(!is_array($t)||!is_array($g)
      ||($receipt['operation']??null)!=='company.remote.wpcli.full'
      ||($t['principal_type']??null)!=='signed_hostinger_ssh_wpcli'
      ||($g['principal_type']??null)!=='signed_hostinger_ssh_wpcli'
      ||($t['host_id']??null)!==$target
      ||($g['target_host_id']??null)!==$target
      ||($t['transport_id']??null)!==($receipt['transport_id']??null)
      ||($g['transport_id']??null)!==($receipt['transport_id']??null)
      ||($t['company']??null)!==$co||($g['company']??null)!==$co
      ||($t['environment']??null)!==$env||($g['environment']??null)!==$env
      ||($t['site_uuid']??null)!==($receipt['site_uuid']??null)
      ||($g['site_uuid']??null)!==($receipt['site_uuid']??null)
      ||!class_exists('MAD4B_Original_Remote_Hostinger_328_V1',false)
      ||!MAD4B_Original_Remote_Hostinger_328_V1::active($t,$g))
       return self::denied('remote_broker_seal_requires_active_original_328');
   $host='srv2030908';
  }
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
