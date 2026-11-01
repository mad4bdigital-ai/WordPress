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
                ||preg_match('/^--(?:path|url|ssh|http|user)(?:=|$)/i',$a))
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
            ($new['environment']??'')!=='staging'||
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
