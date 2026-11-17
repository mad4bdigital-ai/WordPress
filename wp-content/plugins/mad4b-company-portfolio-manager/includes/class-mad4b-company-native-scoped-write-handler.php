<?php
/**
 * MAD4B original 312 capability: four distinct company-manager write scopes.
 *
 * This is NOT an executor. It verifies independently signed capability
 * readbacks produced by a real original 312 worker. Without such a signed
 * readback the operation stays blocked; no legacy Site UUID may substitute.
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Native_Scoped_Write_Handler_V1 {
    public const CONTRACT='mad4b.native-312-company-scoped-write-capability.v1';
    public const PREFIX='mad4b_company_scoped_write_native_312_v1_';
    public const MAX_AGE_SECONDS=300;
    public const OPERATIONS=[
        'content.write'=>'mad4b_company_content_write_native_312',
        'strategy.write'=>'mad4b_company_strategy_write_native_312',
        'operations.write'=>'mad4b_company_operations_write_native_312',
        'commerce.write'=>'mad4b_company_commerce_write_native_312',
    ];
    public const FIELDS=[
        'contract','operation','transport_operation','provider','company_code',
        'environment','site_uuid','canonical_origin','worker_sha256',
        'readback_sha256','rollback_sha256','handler_ready','target_wpcli_verified',
        'readback_supported','rollback_supported','heartbeat_epoch','nonce'
    ];

    private static function exact_central():bool {
        return function_exists('wp_get_environment_type')
          && wp_get_environment_type()==='staging'
          && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
    }
    private static function blocked(string $operation,string $why):array {
        return [
            'contract'=>self::CONTRACT,'operation'=>$operation,
            'transport_operation'=>'company.remote.wpcli.full',
            'provider'=>self::OPERATIONS[$operation]??null,
            'handler_ready'=>false,'target_wpcli_verified'=>false,
            'readback_supported'=>false,'rollback_supported'=>false,
            'heartbeat_epoch'=>0,'reason'=>$why,
            'native_job_submitted'=>false,'original_313_certified'=>false,
            'production_mutated'=>false
        ];
    }
    public static function scoped_key(string $operation,string $company,string $environment,string $uuid):string {
        // Unique company/env/current site UUID key, never one global ready bit
        // that might silently become authority for another company.
        return self::PREFIX.hash('sha256',
            $company.'|'.$environment.'|'.$uuid.'|'.$operation);
    }
    private static function verify(string $operation,string $company,string $environment,$proof):array {
        if(!self::exact_central())return self::blocked($operation,'EXACT_CENTRAL_STAGING_REQUIRED');
        if(!isset(self::OPERATIONS[$operation]))
            return self::blocked($operation,'OPERATION_NOT_REGISTERED');
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
            || !in_array($environment,['staging','production'],true))
            return self::blocked($operation,'EXACT_COMPANY_ENVIRONMENT_REQUIRED');
        if(!class_exists('MAD4B_Company_Environment_Registry_V2',false))
            return self::blocked($operation,'ENROLLED_COMPANY_TARGET_REGISTRY_MISSING');
        $site=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
        if(!is_array($site)||!wp_is_uuid((string)($site['site_uuid']??'')))
            return self::blocked($operation,'EXACT_COMPANY_SITE_NOT_ENROLLED');
        if(!is_array($proof)||array_keys($proof)!==array_merge(self::FIELDS,['signature']))
            return self::blocked($operation,'SIGNED_312_OPERATION_PROOF_MISSING');
        foreach(self::FIELDS as $field) {
            if(!is_string($proof[$field])&&!is_int($proof[$field]))
                return self::blocked($operation,'SIGNED_312_FIELD_TYPE_INVALID');
        }
        $now=time();
        if(($proof['contract']??null)!==self::CONTRACT
          || ($proof['operation']??null)!==$operation
          || ($proof['transport_operation']??null)!=='company.remote.wpcli.full'
          || ($proof['provider']??null)!==self::OPERATIONS[$operation]
          || ($proof['company_code']??null)!==$company
          || ($proof['environment']??null)!==$environment
          || ($proof['site_uuid']??null)!==($site['site_uuid']??null)
          || ($proof['canonical_origin']??null)!==($site['canonical_origin']??null)
          || !is_int($proof['heartbeat_epoch'])
          || $proof['heartbeat_epoch']>$now+10
          || $proof['heartbeat_epoch']<$now-self::MAX_AGE_SECONDS
          || !preg_match('/^[a-f0-9]{32}$/D',(string)$proof['nonce']))
            return self::blocked($operation,'SIGNED_312_OPERATION_SCOPE_OR_AGE_INVALID');
        foreach(['worker_sha256','readback_sha256','rollback_sha256'] as $field)
            if(!preg_match('/^[a-f0-9]{64}$/D',(string)$proof[$field]))
                return self::blocked($operation,'SIGNED_312_EXECUTOR_DIGEST_INVALID');
        foreach(['handler_ready','target_wpcli_verified','readback_supported','rollback_supported'] as $field)
            if($proof[$field]!=='1')
                return self::blocked($operation,'NATIVE_312_CAPABILITY_NOT_VERIFIED');
        if(!class_exists('MAD4B_Central_VPS_Broker_V1',false))
            return self::blocked($operation,'ORIGINAL_BROKER_UNAVAILABLE');
        $key=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
        $sig=base64_decode((string)$proof['signature'],true);
        if(!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
           ||!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)
            return self::blocked($operation,'ORIGINAL_312_SIGNATURE_MISSING');
        $message=implode("\n",array_map(static fn($field)=>(string)$proof[$field],self::FIELDS));
        if(!sodium_crypto_sign_verify_detached($sig,$message,$key))
            return self::blocked($operation,'ORIGINAL_312_SIGNATURE_INVALID');
        // The exact Company Manager target is validated by original 333
        // against the currently active original 305 Grant + transport.
        // Never permit a verified 312 proof from one Site UUID on another.
        $manager='MAD4B_Manager_Auto_Write_V1';
        if(!class_exists($manager,false))
            return self::blocked($operation,'CENTRAL_COMPANY_MANAGER_NOT_LOADED');
        $current=$manager::company_target($company);
        if(($current['state']??null)!=='REGISTERED'
          || ($current['environment']??null)!==$environment
          || ($current['site_uuid']??null)!==$site['site_uuid']
          || ($current['canonical_origin']??null)!==$site['canonical_origin'])
            return self::blocked($operation,'CURRENT_COMPANY_EXECUTION_TARGET_CHANGED');
        return [
            'contract'=>self::CONTRACT,'operation'=>$operation,
            'transport_operation'=>'company.remote.wpcli.full',
            'provider'=>self::OPERATIONS[$operation],
            'handler_ready'=>true,'target_wpcli_verified'=>true,
            'readback_supported'=>true,'rollback_supported'=>true,
            'heartbeat_epoch'=>$proof['heartbeat_epoch'],
            'company_code'=>$proof['company_code'],
            'environment'=>$proof['environment'],
            'site_uuid'=>$proof['site_uuid'],
            'canonical_origin'=>$proof['canonical_origin'],
            'state'=>'SIGNED_ORIGINAL_312_CAPABLE_JOB_STILL_REQUIRED',
            'native_job_submitted'=>false,'original_313_certified'=>false,
            'production_mutated'=>false
        ];
    }
    public static function capability_status(
        string $operation,string $company='',string $environment=''
    ):array {
        if(!isset(self::OPERATIONS[$operation]))
            return self::blocked($operation,'OPERATION_NOT_REGISTERED');
        // No company-less global readiness can grant native site execution.
        if($company===''||$environment==='')return self::blocked(
            $operation,'EXACT_COMPANY_AND_ENVIRONMENT_REQUIRED');
        $site=class_exists('MAD4B_Company_Environment_Registry_V2',false)
            ? MAD4B_Company_Environment_Registry_V2::target($company,$environment)
            : null;
        if(!is_array($site)||!wp_is_uuid((string)($site['site_uuid']??'')))
            return self::blocked($operation,'EXACT_COMPANY_SITE_NOT_ENROLLED');
        $proof=get_option(self::scoped_key(
            $operation,$company,$environment,$site['site_uuid']),false);
        return self::verify($operation,$company,$environment,$proof);
    }
    public static function ingest_signed(array $proof):array {
        // A fresh Original root-signed 312 liveness proof is a capability,
        // not a Job approval and not a historic 313 certification.
        $op=(string)($proof['operation']??'');
        $company=(string)($proof['company_code']??'');
        $environment=(string)($proof['environment']??'');
        if(!isset(self::OPERATIONS[$op]))
            return self::blocked($op,'OPERATION_NOT_REGISTERED');
        $validated=self::verify($op,$company,$environment,$proof);
        if(($validated['handler_ready']??false)!==true)
            return $validated;
        $key=self::scoped_key($op,$company,$environment,$proof['site_uuid']);
        $old=get_option($key,false);
        if(!is_array($old)||($old['signature']??null)!==($proof['signature']??null)) {
            // WordPress Core update_option; zero target writes, no grants.
            update_option($key,$proof,false);
        }
        $confirmed=self::capability_status($op,$company,$environment);
        if(($confirmed['handler_ready']??false)!==true
          ||($confirmed['heartbeat_epoch']??null)!==$proof['heartbeat_epoch'])
            return self::blocked($op,'SIGNED_312_PERSISTENCE_READBACK_FAILED');
        return ['contract'=>self::CONTRACT,
            'state'=>'SIGNED_312_EXACT_COMPANY_CAPABILITY_STORED',
            'operation'=>$op,'company_code'=>$company,'environment'=>$environment,
            'site_uuid'=>$proof['site_uuid'],'heartbeat_epoch'=>$proof['heartbeat_epoch'],
            'verified'=>true,'jobs_created'=>0,'grants_issued'=>0,
            'production_mutated'=>false,'original_313_certified'=>false];
    }
    public static function cli($args,$assoc):void {
        if(!defined('WP_CLI')||!WP_CLI||!class_exists('WP_CLI')
          ||count($args)!==1||$args[0]!=='ingest'
          ||array_keys($assoc)!==['proof'])
            WP_CLI::error('ORIGINAL_EXACT_SIGNED_312_PROOF_REQUIRED');
        $encoded=(string)$assoc['proof'];
        if(strlen($encoded)>6000)
            WP_CLI::error('SIGNED_312_PAYLOAD_TOO_LARGE');
        $raw=base64_decode($encoded,true);
        $decoded=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($decoded))
            WP_CLI::error('SIGNED_312_JSON_INVALID');
        $result=self::ingest_signed($decoded);
        WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
        if(($result['verified']??false)!==true)
            WP_CLI::halt(2);
    }
    public static function boot():void {
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b native-312-scoped',[__CLASS__,'cli']);
    }
}
MAD4B_Company_Native_Scoped_Write_Handler_V1::boot();
