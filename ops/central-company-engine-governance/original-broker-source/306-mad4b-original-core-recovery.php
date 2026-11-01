<?php
/**
 * MAD4B original Broker 306 pre-dispatch recovery journal.
 * No new dispatcher, command storage, grant, host privilege or automatic replay.
 * A signed failure is only the first half of a recovery. The original Core must
 * independently rebind the command payload and admit a NEW job before dispatch.
 */
defined('ABSPATH') || exit;

final class MAD4B_Original_Core_Recovery_V1 {
    const CONTRACT = 'mad4b.original-core-pre-dispatch-recovery.v1';
    const EVIDENCE = 'mad4b_central_broker_pre_dispatch_failure_v1_';
    const REASONS = array('TRANSPORT_UNREACHABLE','PROVIDER_UNAVAILABLE','CONNECTION_NOT_ESTABLISHED');
    const PROVIDERS = array('container_exec','coolify_service','vps_ssh');

    private static function reject(string $reason, int $status = 409) {
        return new WP_Error('mad4b_original_recovery_'.$reason, $reason,
            array('status'=>$status,'contract'=>self::CONTRACT));
    }

    private static function original_job(string $id) {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) return self::reject('invalid_job_id',422);
        $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
        if (!is_array($job) || ($job['job_id']??'')!==$id
            || ($job['contract']??'')!==MAD4B_Central_VPS_Broker_V1::CONTRACT
            || ($job['environment']??'')!=='staging'
            || ($job['state']??'')!=='QUEUED_FOR_VPS_BROKER_CLAIM'
            || ($job['executed']??true)!==false
            || !in_array(($job['operation']??''),array('maintenance.shell','company.remote.wpcli.full'),true)
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($job['command_sha256']??'')))
            return self::reject('original_job_not_pre_dispatch');
        if (get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id,false)!==false)
            return self::reject('already_claimed_or_uncertain');
        $request=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id,false);
        if (!is_array($request)||($request['request_id']??'')!==$id
            || ($request['status']??'')!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
            || ($request['execution_performed']??true)!==false
            || (int)($request['expires_epoch']??0)<=time())
            return self::reject('original_intake_not_active');
        foreach(array('company','site_uuid','canonical_origin','environment',
            'grant_id','operation','target_host_id','target_ssh_principal',
            'command_sha256','central_principal_id') as $k) {
            if (!isset($job[$k],$request[$k]) || $job[$k]!==$request[$k])
                return self::reject('original_intake_scope_drift');
        }
        $grant=get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$job['grant_id'],false);
        if (!is_array($grant)||($grant['state']??'')!=='CENTRAL_OPERATION_SCOPE_GRANTED'
            || ($grant['company']??'')!==$job['company']
            || ($grant['operation']??'')!==$job['operation']
            || ($grant['central_principal_id']??0)!==$job['central_principal_id']
            || (isset($grant['expires_epoch']) && $grant['expires_epoch']!==null
                && (int)$grant['expires_epoch']<=time()))
            return self::reject('original_grant_not_active');
        if (($job['grant_mode']??'')!=='persistent'
            || !preg_match('/^[a-f0-9]{32}$/D',(string)($job['transport_id']??'')))
            return self::reject('reusable_original_transport_required');
        $transport=get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.
            $job['transport_id'],false);
        if (!is_array($transport)
            || ($transport['state']??'')!=='PENDING_INDEPENDENT_SSH_VERIFICATION'
            || ($transport['company']??'')!==$job['company']
            || ($transport['site_uuid']??'')!==$job['site_uuid']
            || ($transport['canonical_origin']??'')!==$job['canonical_origin']
            || ($transport['host_id']??'')!==$job['target_host_id'])
            return self::reject('original_transport_not_active');
        if (!class_exists('MAD4B_Manager_Auto_Write_V1',false))
            return self::reject('site_authority_unavailable');
        $site=MAD4B_Manager_Auto_Write_V1::company_target($job['company']);
        if (!is_array($site)||($site['state']??'')!=='REGISTERED'
            || ($site['site_uuid']??'')!==$job['site_uuid']
            || ($site['canonical_origin']??'')!==$job['canonical_origin']
            || ($site['environment']??'')!==$job['environment'])
            return self::reject('site_authority_drift');
        return $job;
    }

    private static function message(array $job,array $proof):string {
        return implode("\n",array(
            self::CONTRACT,'before-dispatch-failure',
            $job['job_id'],$job['company'],$job['site_uuid'],
            $job['canonical_origin'],$job['environment'],
            $job['target_host_id'],$job['grant_id'],$job['transport_id'],
            $job['command_sha256'],(string)$job['central_principal_id'],
            (string)($proof['provider']??''),(string)($proof['reason']??''),
            (string)($proof['nonce']??''),(string)($proof['issued']??''),
            (string)($proof['expires']??''),'dispatch_started=false',
            'claim_committed=false','effects_possible=false'
        ));
    }

    private static function verify(array $job,array $proof,bool $fresh=true):bool {
        if (!function_exists('sodium_crypto_sign_verify_detached'))return false;
        if (($proof['contract']??'')!==self::CONTRACT
            || ($proof['job_id']??'')!==$job['job_id']
            || !in_array(($proof['provider']??''),self::PROVIDERS,true)
            || !in_array(($proof['reason']??''),self::REASONS,true)
            || ($proof['dispatch_started']??null)!==false
            || ($proof['claim_committed']??null)!==false
            || ($proof['effects_possible']??null)!==false
            || !preg_match('/^[a-f0-9]{32}$/D',(string)($proof['nonce']??'')))return false;
        $issued=$proof['issued']??null; $expires=$proof['expires']??null;
        if (!is_int($issued)||!is_int($expires)
            ||$expires<$issued||$expires>$issued+60)return false;
        if ($fresh) {
            if ($issued<time()-60||$issued>time()+10||$expires<time())return false;
        } elseif (!isset($proof['verified_epoch']) ||
            !is_int($proof['verified_epoch']) ||
            $proof['verified_epoch']<$issued-10 ||
            $proof['verified_epoch']>$expires) return false;
        $key=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
        $sig=base64_decode((string)($proof['signature']??''),true);
        if (!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            ||!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)return false;
        return sodium_crypto_sign_verify_detached($sig,self::message($job,$proof),$key);
    }

    // Only a genuinely enrolled Original-Core principal may submit signed
    // host evidence. No caller-provided "before_dispatch" boolean suffices.
    public static function recordOriginalPreDispatchFailure(string $id,array $proof) {
        $job=self::original_job($id);
        if (is_wp_error($job))return $job;
        if (!self::verify($job,$proof))return self::reject('signed_host_evidence_invalid');
        // Shared with the original signed claim path. Its unique option key
        // decides the winner; failed storage leaves the job PARKED, not replayable.
        if (!add_option(MAD4B_Central_VPS_Broker_V1::ROUTE_GATE.$id,
            array('decision'=>'RECOVERY','at'=>time()),'',false))
            return self::reject('job_route_already_reserved');
        $proof['verified_source']='original_core_execution_journal';
        $proof['verified_epoch']=time();
        if (!add_option(self::EVIDENCE.$id,$proof,'',false))
            return self::reject('evidence_storage_uncertain',503);
        $job['state']='TRANSPORT_FAILED_BEFORE_DISPATCH';
        if (!update_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,$job,false))
            return self::reject('job_state_update_uncertain',503);
        return array('state'=>'RECORDED_PRE_DISPATCH_FAILURE',
            'job_id'=>$id,'execution_performed'=>false);
    }

    public static function readOriginalRecoveryEvidence(string $id) {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id))return self::reject('invalid_job_id',422);
        $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
        $proof=get_option(self::EVIDENCE.$id,false);
        $gate=get_option(MAD4B_Central_VPS_Broker_V1::ROUTE_GATE.$id,false);
        if (!is_array($job)||!is_array($proof)||!is_array($gate)
            ||($gate['decision']??'')!=='RECOVERY'
            ||($job['state']??'')!=='TRANSPORT_FAILED_BEFORE_DISPATCH'
            ||get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id,false)!==false)
            return self::reject('original_journal_missing_or_uncertain');
        // The original signature is reverified, but is not treated as current
        // provider availability or as a new admission.
        if (!self::verify($job,$proof,false))
            return self::reject('signed_journal_invalid');
        return array('contract'=>self::CONTRACT,'job_id'=>$id,
            'original_job'=>$job,'signed_failure'=>$proof,
            'dispatch_started'=>false,'claim_committed'=>false,
            'effects_possible'=>false,'execution_performed'=>false,
            'new_core_job_required'=>true);
    }

    public static function createOriginalRecoveryJobOnce(array $input) {
        $id=(string)($input['original_job_id']??'');
        $proof=self::readOriginalRecoveryEvidence($id);
        if (is_wp_error($proof))return $proof;
        $job=$proof['original_job'];
        $grant=get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.
            (string)($job['grant_id']??''),false);
        $transport=get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.
            (string)($job['transport_id']??''),false);
        if (!is_array($grant)||($grant['state']??'')!=='CENTRAL_OPERATION_SCOPE_GRANTED'
            ||($grant['company']??'')!==($job['company']??'')
            ||($grant['operation']??'')!==($job['operation']??'')
            ||!is_array($transport)
            ||($transport['state']??'')!=='PENDING_INDEPENDENT_SSH_VERIFICATION'
            ||($transport['site_uuid']??'')!==($job['site_uuid']??'')
            ||($transport['company']??'')!==($job['company']??'')
            ||get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id,false)!==false)
            return self::reject('original_authority_revoked_or_drifted');
        if (!class_exists('MAD4B_Manager_Auto_Write_V1',false))
            return self::reject('original_site_authority_unavailable');
        $site=MAD4B_Manager_Auto_Write_V1::company_target((string)$job['company']);
        if (!is_array($site)||($site['state']??'')!=='REGISTERED'
            ||($site['site_uuid']??'')!==$job['site_uuid']
            ||($site['canonical_origin']??'')!==$job['canonical_origin']
            ||($site['environment']??'')!=='staging')
            return self::reject('original_site_binding_revoked');
        // The original 305 intake deliberately never persisted raw command bytes.
        // Do NOT fabricate the signed payload from its SHA or silently clone
        // the old grant. Only the original Core's installed sealed-payload
        // rebind provider may create a replacement through 305 -> 306.
        if (!method_exists('MAD4B_Central_SSH_Command_Intake_V1',
            'rebindOriginalSealedCommandOnce'))
            return self::reject('original_sealed_payload_rebind_provider_required',503);
        return MAD4B_Central_SSH_Command_Intake_V1::rebindOriginalSealedCommandOnce(
            $id,$input,$proof);
    }

    public static function record(WP_REST_Request $request) {
        if (!MAD4B_Central_SSH_Command_Intake_V1::permitted($request))
            return self::reject('original_operator_required',403);
        $id=(string)$request['job_id'];
        $body=$request->get_json_params();
        if (!is_array($body)||array_keys($body)!==array('proof')||!is_array($body['proof']))
            return self::reject('signed_proof_shape_invalid',422);
        $job=self::original_job($id);
        if (is_wp_error($job))return $job;
        if ((int)$job['central_principal_id']!==get_current_user_id()
            ||($job['company']??'')!==(string)$request['company'])
            return self::reject('principal_or_company_mismatch',403);
        $out=self::recordOriginalPreDispatchFailure($id,$body['proof']);
        return is_wp_error($out)?$out:rest_ensure_response($out);
    }

    public static function inspect(WP_REST_Request $request) {
        if (!MAD4B_Central_SSH_Command_Intake_V1::permitted($request))
            return self::reject('original_operator_required',403);
        $id=(string)$request['job_id'];
        $row=self::readOriginalRecoveryEvidence($id);
        if (is_wp_error($row))return $row;
        if (($row['original_job']['company']??'')!==(string)$request['company']
            ||(int)($row['original_job']['central_principal_id']??0)!==get_current_user_id())
            return self::reject('principal_or_company_mismatch',403);
        // Never return an executable command or a signer secret.
        return rest_ensure_response(array('contract'=>self::CONTRACT,
            'job_id'=>$id,'state'=>'SIGNED_BEFORE_DISPATCH_FAILURE_VERIFIED',
            'recovery_requires_sealed_payload_rebind'=>true,
            'executed'=>false));
    }

    // The original operator sends a fresh host-signed, digest-matching
    // sealed command only once. The bytes are handled in memory by 305;
    // no endpoint accepts caller-supplied grants, target changes or raw shell.
    public static function create(WP_REST_Request $request) {
        if (!MAD4B_Central_SSH_Command_Intake_V1::permitted($request))
            return self::reject('original_operator_required',403);
        $id=(string)$request['job_id'];
        $body=$request->get_json_params();
        if (!is_array($body)||array_keys($body)!==array('sealed_payload')
            ||!is_array($body['sealed_payload']))
            return self::reject('signed_rebind_shape_invalid',422);
        $record=self::readOriginalRecoveryEvidence($id);
        if (is_wp_error($record))return $record;
        if (($record['original_job']['company']??'')!==(string)$request['company']
            ||(int)($record['original_job']['central_principal_id']??0)!==get_current_user_id())
            return self::reject('original_operator_or_site_mismatch',403);
        $out=self::createOriginalRecoveryJobOnce(array(
            'original_job_id'=>$id,
            'authenticated_request'=>$request,
            'sealed_payload'=>$body['sealed_payload']
        ));
        return is_wp_error($out)?$out:rest_ensure_response($out);
    }

    public static function routes():void {
        if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
            || !MAD4B_Central_Dual_Lane_Registry_V1::central())return;
        $base='/central-execution-lanes/(?P<company>[a-z][a-z0-9-]{2,47})';
        $endpoint=$base.'/broker-jobs/(?P<job_id>[a-f0-9]{32})/recovery-evidence';
        register_rest_route('mad4b/v1',$endpoint,array(
            'methods'=>'POST',
            'permission_callback'=>array('MAD4B_Central_SSH_Command_Intake_V1','permitted'),
            'callback'=>array(__CLASS__,'record')));
        register_rest_route('mad4b/v1',$base.'/broker-jobs/(?P<job_id>[a-f0-9]{32})/recovery-job',array(
            'methods'=>'POST',
            'permission_callback'=>array('MAD4B_Central_SSH_Command_Intake_V1','permitted'),
            'callback'=>array(__CLASS__,'create')));
        register_rest_route('mad4b/v1',$endpoint,array(
            'methods'=>'GET',
            'permission_callback'=>array('MAD4B_Central_SSH_Command_Intake_V1','permitted'),
            'callback'=>array(__CLASS__,'inspect')));
    }

    public static function boot():void {
        add_action('rest_api_init',array(__CLASS__,'routes'),96);
    }
}
MAD4B_Original_Core_Recovery_V1::boot();
