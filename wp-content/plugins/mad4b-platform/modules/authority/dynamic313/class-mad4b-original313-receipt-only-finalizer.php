<?php
/**
 * Original 313 independently signed, receipt-only historical reconciliation.
 *
 * Different from a timely company.code.release Host Apply finish. This class
 * never replays a Job, renews an expired Grant or mutates company content.
 * No public REST endpoint. A source-only candidate pending governed enrollment.
 */
defined('ABSPATH') || exit;

final class MAD4B_Original_313_Receipt_Recovery_Finalizer_V1 {
    const CONTRACT='mad4b.original313.receipt-only-central-finalizer.v1';
    const HOST_CONTRACT='mad4b.original313.receipt-only-independent-host-proof.v1';
    const OPERATION='company.code.release.receipt.reconcile';
    const CERT_PREFIX='mad4b_original313_receipt_recovery_certificate_v1_';
    const PROOF_PREFIX='mad4b_original313_receipt_recovery_signed_proof_v1_';
    const TRUST_OPTION='mad4b_original313_receipt_recovery_first_party_trust_v1';
    const HOST='srv2030908';
    const FIELDS=[
      'contract','operation_id','job_id','original_job_id','company',
      'environment','site_uuid','origin','target_id','grant_id',
      'transport_id','command_sha256','plan_sha256','old_plan_sha256',
      'readback_sha256','old_grant_sha256','old_host_receipt_sha256',
      'original_312_verified','independent_readback_verified',
      'production_mutation','schema_ddl_performed','job_replayed',
      'issued_epoch','expires_epoch','nonce','signature'
    ];
    private static function deny(string $reason):array {
        return ['contract'=>self::CONTRACT,'verified'=>false,
            'reason'=>$reason,'original_313_certified'=>false,
            'recovery_certificate_issued'=>false,'execution_replayed'=>false,
            'production_mutation'=>false,'schema_ddl_executed'=>false];
    }
    private static function is_hex($value,int $length):bool {
        return is_string($value)&&strlen($value)===$length&&
            ctype_xdigit($value)&&strtolower($value)===$value;
    }
    private static function canonical($value) {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map([__CLASS__,'canonical'],$value);
            }
            ksort($value,SORT_STRING);
            foreach($value as $key=>$row)$value[$key]=self::canonical($row);
        }
        return $value;
    }
    private static function encode(array $value):string {
        $text=wp_json_encode(self::canonical($value),
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return is_string($text)?$text:'';
    }
    /** Enrollment must come from a separate trusted First-party install. */
    private static function trust():array {
        if(!class_exists('MAD4B_Native_WPCLI_Receipt_Verifier_V1',false)
          ||!class_exists('MAD4B_Central_VPS_Broker_V1',false)
          ||!class_exists('MAD4B_Broker_Exact_SSH_Authority_V1',false)
          ||!class_exists('MAD4B_Broker_Remote_SSH_Receipt_V1',false)
          ||!function_exists('sodium_crypto_sign_verify_detached'))return [];
        $proof=get_option(self::TRUST_OPTION,false);
        if(!is_array($proof)
          ||($proof['contract']??null)!=='mad4b.original313.receipt-only-first-party-trust.v1'
          ||($proof['operation_id']??null)!==self::OPERATION
          ||($proof['first_party_provider_enrolled']??null)!==true
          ||($proof['independent_signer_verified']??null)!==true
          ||($proof['receipt_only_host_adapter_verified']??null)!==true
          ||($proof['signed_original_305_312_bridge_verified']??null)!==true
          ||($proof['independent_readback_enrolled']??null)!==true
          ||($proof['central_certificate_append_only']??null)!==true)
            return [];
        // An arbitrary public key or caller flag must never be trusted:
        // require the existing original Native 313 public key exactly.
        $public=(string)($proof['existing_original_313_public_key_b64']??'');
        if(!hash_equals((string)MAD4B_Native_WPCLI_Receipt_Verifier_V1::PUBLIC_KEY_B64,$public)
          ||strlen((string)base64_decode($public,true))!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)
            return [];
        // A valid Broker signature for another enrolled company or host
        // cannot grant this Central WordPress installation authority.
        if(!class_exists('MAD4B_SCP_Site_Profile',false))return [];
        $site=MAD4B_SCP_Site_Profile::status();
        if(!is_array($site)||empty($site['authority_ready'])
          ||empty($site['deployment_binding_match'])
          ||empty($site['origin_match'])||empty($site['environment_match'])
          ||!empty($site['mutation_pending_audit'])
          ||($proof['central_site_uuid']??'')!==($site['site_uuid']??null)
          ||($proof['central_environment']??'')!==($site['configured_environment']??null)
          ||($proof['central_origin']??'')!==untrailingslashit(home_url('/')))
            return [];
        if(($proof['issuer']??'')!=='mad4b-company-broker'
          ||($proof['finalizer_file_sha256']??'')!==hash_file('sha256',__FILE__)
          ||!self::is_hex($proof['provider_manifest_sha256']??null,64)
          ||!self::is_hex($proof['worker_adapter_sha256']??null,64)
          ||!is_string($proof['signature']??null))return [];
        $key=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
        $signature=base64_decode($proof['signature'],true);
        if(!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
          ||!is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES)
            return [];
        $payload=$proof;
        unset($payload['signature']);
        $message='mad4b.original313.receipt-only-first-party-trust.v1'."\n".self::encode($payload);
        if(!sodium_crypto_sign_verify_detached($signature,$message,$key))return [];
        return $proof;
    }
    public static function status():array {
        // Source presence is NOT enrollment. This only turns ready after a
        // separately approved First-party signed config and all core checks.
        $ready=self::trust()!==[];
        return ['contract'=>self::CONTRACT,
            'operational_ready'=>$ready,
            'independent_readback_ready'=>$ready,
            'append_only_certificate_ready'=>$ready,
            'operation_id'=>self::OPERATION,
            'original_313_certified'=>false,
            'original_313_receipt_issued'=>false,
            'production_mutation'=>false];
    }
    public static function check_exact(array $proof):array {
        if(self::trust()===[])return self::deny('RECOVERY_FIRST_PARTY_ENROLLMENT_REQUIRED');
        $keys=array_keys($proof);$expected=self::FIELDS;
        sort($keys);sort($expected);
        if($keys!==$expected)return self::deny('RECOVERY_INDEPENDENT_PROOF_FIELDS_CHANGED');
        foreach(['job_id','original_job_id','nonce'] as $key)
            if(!self::is_hex($proof[$key],32))
                return self::deny('RECOVERY_PROOF_IDENTITY_INVALID');
        foreach(['command_sha256','plan_sha256','old_plan_sha256',
                 'readback_sha256','old_grant_sha256','old_host_receipt_sha256'] as $key)
            if(!self::is_hex($proof[$key],64))
                return self::deny('RECOVERY_PROOF_HASH_INVALID');
        if($proof['contract']!==self::HOST_CONTRACT
          ||$proof['operation_id']!==self::OPERATION
          ||$proof['job_id']===$proof['original_job_id']
          ||$proof['target_id']!==self::HOST
          ||$proof['original_312_verified']!==true
          ||$proof['independent_readback_verified']!==true
          ||$proof['production_mutation']!==false
          ||$proof['schema_ddl_performed']!==false
          ||$proof['job_replayed']!==false)
            return self::deny('RECOVERY_HOST_ONLY_READBACK_PROOF_REQUIRED');
        $now=time();
        if(!is_int($proof['issued_epoch'])||!is_int($proof['expires_epoch'])
          ||$proof['issued_epoch']<$now-120||$proof['issued_epoch']>$now+10
          ||$proof['expires_epoch']<$now+10
          ||$proof['expires_epoch']>$proof['issued_epoch']+120)
            return self::deny('RECOVERY_INDEPENDENT_PROOF_EXPIRED');
        if(!class_exists('MAD4B_Central_VPS_Broker_V1',false)
          ||!class_exists('MAD4B_Broker_Exact_SSH_Authority_V1',false)
          ||!class_exists('MAD4B_Broker_Remote_SSH_Receipt_V1',false))
            return self::deny('ORIGINAL_306_312_NOT_LOADED');
        $id=$proof['job_id'];
        $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
        $pending=get_option(MAD4B_Broker_Remote_SSH_Receipt_V1::WPCLI_PREFIX.$id,false);
        $old=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$proof['original_job_id'],false);
        if(!is_array($job)||!is_array($pending)||!is_array($old)
          ||($job['job_id']??null)!==$id
          ||($job['executed']??null)!==false
          ||($job['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
          ||($job['job_type']??null)!=='signed_vps_wpcli'
          ||($job['operation']??null)!=='company.remote.wpcli.full'
          ||($job['target_scope']??null)!=='wordpress'
          ||($job['command_format']??null)!=='wpcli.argv.json.v1'
          ||($pending['state']??null)!=='PENDING_INDEPENDENT_NATIVE_READBACK'
          ||($pending['command_sha256']??null)!==$proof['command_sha256']
          ||($pending['readback_sha256']??null)!==$proof['readback_sha256']
          ||($pending['output_sha256']??null)!==$proof['readback_sha256']
          ||($old['job_id']??null)!==$proof['original_job_id']
          ||($old['company']??null)!==$job['company']
          ||($old['environment']??null)!==$job['environment']
          ||($old['site_uuid']??null)!==$job['site_uuid'])
            return self::deny('RECOVERY_ORIGINAL_JOB_OR_PENDING_CHANGED');
        $authority=MAD4B_Broker_Exact_SSH_Authority_V1::evaluate(
            $id,self::HOST,$proof['command_sha256']);
        if(!is_array($authority)||($authority['contract']??null)!=='mad4b.broker-exact-ssh-authority.v1'
          ||($authority['verified']??null)!==true
          ||($authority['eligible']??null)!==true
          ||($authority['owner_enrolled_wpcli']??null)!==true
          ||($authority['central_scope_only']??null)!==true
          ||($authority['shell_executed']??null)!==false
          ||($authority['operation']??null)!=='company.remote.wpcli.full'
          ||($authority['command_sha256']??null)!==$proof['command_sha256']
          ||($authority['expires_epoch']??0)<=$now+10)
            return self::deny('RECOVERY_NEW_ORIGINAL_312_NOT_VERIFIED');
        foreach(['company','environment','site_uuid','origin','command_sha256',
                 'grant_id','transport_id'] as $field){
            $jobval=$field==='origin'?($job['canonical_origin']??null):($job[$field]??null);
            if($jobval!==$proof[$field]||($authority[$field]??null)!==$proof[$field])
                return self::deny('RECOVERY_CURRENT_305_312_SCOPE_CHANGED');
        }
        if(($pending['grant_id']??null)!==$proof['grant_id']
          ||($pending['transport_id']??null)!==$proof['transport_id']
          ||($pending['site_uuid']??null)!==$proof['site_uuid']
          ||($pending['origin']??null)!==$proof['origin'])
            return self::deny('RECOVERY_PENDING_NATIVE_SCOPE_CHANGED');
        $pub=base64_decode(MAD4B_Native_WPCLI_Receipt_Verifier_V1::PUBLIC_KEY_B64,true);
        $sig=base64_decode($proof['signature'],true);
        $payload=$proof;unset($payload['signature']);
        if(!is_string($pub)||!is_string($sig)
          ||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
          ||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES
          ||!sodium_crypto_sign_verify_detached($sig,self::encode($payload),$pub))
            return self::deny('RECOVERY_ORIGINAL_INDEPENDENT_SIGNATURE_INVALID');
        return ['contract'=>self::CONTRACT,'verified'=>true,
            'job_id'=>$id,'operation_id'=>self::OPERATION,
            'original_job_id'=>$proof['original_job_id'],
            'plan_sha256'=>$proof['plan_sha256'],
            'readback_sha256'=>$proof['readback_sha256'],
            'original_313_certified'=>false,
            'recovery_certificate_issued'=>false,
            'production_mutation'=>false,'schema_ddl_executed'=>false];
    }
    /** Append-only proof; does not yet certify. The certificate is distinct. */
    public static function ingest_exact(array $proof):array {
        $checked=self::check_exact($proof);
        if(($checked['verified']??false)!==true)return $checked;
        $key=self::PROOF_PREFIX.$proof['job_id'];
        $prior=get_option($key,false);
        if($prior!==false)
            return $prior===$proof
                ?['verified'=>true,'state'=>'RECOVERY_SIGNED_PROOF_ALREADY_STORED',
                  'job_id'=>$proof['job_id'],'original_313_certified'=>false]
                :self::deny('RECOVERY_INDEPENDENT_PROOF_CONFLICT');
        if(!add_option($key,$proof,'',false))
            return self::deny('RECOVERY_SIGNED_PROOF_APPEND_FAILED');
        return get_option($key,false)===$proof
            ?['verified'=>true,'state'=>'RECOVERY_SIGNED_PROOF_STORED',
              'job_id'=>$proof['job_id'],'original_313_certified'=>false]
            :self::deny('RECOVERY_SIGNED_PROOF_READBACK_FAILED');
    }
    /** Never infer certification from the original expired Host Apply Job. */
    public static function finalize_exact(string $jobid):array {
        if(!self::is_hex($jobid,32))return self::deny('RECOVERY_JOB_ID_INVALID');
        $proof=get_option(self::PROOF_PREFIX.$jobid,false);
        if(!is_array($proof))return self::deny('RECOVERY_SIGNED_PROOF_NOT_STORED');
        $verified=self::check_exact($proof);
        if(($verified['verified']??false)!==true)return $verified;
        $certificate=[
            'contract'=>'mad4b.original313.independent-receipt-recovery-certificate.v1',
            'state'=>'CENTRAL_RECOVERY_RECEIPT_INDEPENDENTLY_CERTIFIED',
            'new_job_id'=>$jobid,'original_job_id'=>$verified['original_job_id'],
            'operation_id'=>self::OPERATION,
            'plan_sha256'=>$verified['plan_sha256'],
            'readback_sha256'=>$verified['readback_sha256'],
            'independent_proof_sha256'=>hash('sha256',self::encode($proof)),
            'recorded_at'=>gmdate('c'),
            'original_313_timely_apply_certified'=>false,
            'historical_reconciliation_only'=>true,
            'code_reapplied'=>false,'production_mutation'=>false,
            'schema_ddl_performed'=>false
        ];
        $key=self::CERT_PREFIX.$jobid;
        $existing=get_option($key,false);
        if($existing!==false){
            unset($certificate['recorded_at']);
            $saved=is_array($existing)?$existing:[];
            unset($saved['recorded_at']);
            return $saved===$certificate
                ?['verified'=>true,'state'=>'RECOVERY_CERTIFICATE_ALREADY_RECORDED',
                  'new_job_id'=>$jobid,'historical_only'=>true]
                :self::deny('RECOVERY_CERTIFICATE_APPEND_ONLY_CONFLICT');
        }
        if(!add_option($key,$certificate,'',false))
            return self::deny('RECOVERY_CERTIFICATE_CREATE_CONFLICT');
        $saved=get_option($key,false);
        return $saved===$certificate
            ?['verified'=>true,'state'=>'CENTRAL_RECOVERY_RECEIPT_INDEPENDENTLY_CERTIFIED',
              'new_job_id'=>$jobid,'original_job_id'=>$verified['original_job_id'],
              'historical_only'=>true,'original_313_timely_apply_certified'=>false]
            :self::deny('RECOVERY_CERTIFICATE_READBACK_UNCERTAIN');
    }
    public static function cli($args,$assoc):void {
        if(!defined('WP_CLI')||!WP_CLI||!class_exists('WP_CLI')
          ||get_current_user_id()<1||!current_user_can('manage_options'))
            WP_CLI::error('CENTRAL_ORIGINAL_OWNER_REQUIRED');
        $mode=(string)($args[0]??'');
        if($mode==='status'&&$assoc===[]){
            WP_CLI::line(wp_json_encode(self::status()));return;
        }
        if($mode==='check' && array_keys($assoc)===['payload']){
            $raw=base64_decode((string)$assoc['payload'],true);
            $proof=is_string($raw)&&strlen($raw)<=12000?json_decode($raw,true):null;
            $row=is_array($proof)?self::check_exact($proof):self::deny('RECOVERY_PAYLOAD_INVALID');
        }elseif($mode==='ingest'&&array_keys($assoc)===['payload']){
            $raw=base64_decode((string)$assoc['payload'],true);
            $proof=is_string($raw)&&strlen($raw)<=12000?json_decode($raw,true):null;
            $row=is_array($proof)?self::ingest_exact($proof):self::deny('RECOVERY_PAYLOAD_INVALID');
        }elseif($mode==='finalize'&&array_keys($assoc)===['job']){
            $row=self::finalize_exact((string)$assoc['job']);
        }else WP_CLI::error('ORIGINAL_RECOVERY_READONLY_CONTRACT_REQUIRED');
        if(($row['verified']??false)!==true)
            WP_CLI::error((string)($row['reason']??'ORIGINAL_RECOVERY_VERIFICATION_FAILED'));
        WP_CLI::line(wp_json_encode($row));
    }
    public static function boot():void {
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b receipt-recovery',[__CLASS__,'cli']);
    }
}
MAD4B_Original_313_Receipt_Recovery_Finalizer_V1::boot();
