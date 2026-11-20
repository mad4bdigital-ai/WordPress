<?php
/**
 * Four independent original 305 operation providers. Registering a provider
 * never authorizes a job or marks a tenant capability as ready.
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Native_Scoped_Write_Providers_V1 {
    public const EVIDENCE='mad4b.company-native-scoped-write-312-provider.v1';
    public const OPERATIONS=[
        'content.write'=>['mad4b_company_content_write_native_312','content_readback'],
        'strategy.write'=>['mad4b_company_strategy_write_native_312','strategy_readback'],
        'operations.write'=>['mad4b_company_operations_write_native_312','operations_readback'],
        'commerce.write'=>['mad4b_company_commerce_write_native_312','commerce_readback'],
    ];
    private static function scoped(string $operation,string $company='',
                                   string $environment=''):array {
        $provider=self::OPERATIONS[$operation][0];
        $base=[
            'contract'=>self::EVIDENCE,'operation'=>$operation,
            'provider'=>$provider,'authority_family'=>'original_305',
            'provider_ready'=>false,'state'=>'EXACT_312_SCOPED_WRITE_HANDLER_NOT_READY',
            'reason'=>'ORIGINAL_312_SIGNED_OPERATION_HEARTBEAT_MISSING',
            'native_305_issued'=>false,'original_313_certified'=>false,
            'production_mutated'=>false
        ];
        if(wp_get_environment_type()!=='staging'
          ||untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com')
            return array_replace($base,['reason'=>'CENTRAL_STAGING_ONLY']);
        $class='MAD4B_Company_Native_Scoped_Write_Handler_V1';
        if(!class_exists($class,false) || !is_callable([$class,'capability_status']))
            return array_replace($base,['reason'=>'EXACT_SCOPED_WRITE_HANDLER_NOT_INSTALLED']);
        try {
            $method=new ReflectionMethod($class,'capability_status');
            $real=realpath((string)$method->getFileName());
            $root=realpath(WP_PLUGIN_DIR.'/mad4b-company-portfolio-manager');
            if(!$method->isPublic()||!$method->isStatic()||!is_string($root)
              ||!is_string($real)||!str_starts_with($real,$root.'/')
              ||is_link($method->getFileName())||fileowner($real)!==0
              ||(fileperms($real)&0022)!==0)
                return array_replace($base,['reason'=>'UNTRUSTED_NATIVE_312_HANDLER_SOURCE']);
            $cap=$class::capability_status($operation,$company,$environment);
        }catch(Throwable $e){
            return array_replace($base,['reason'=>'NATIVE_312_HANDLER_READBACK_ERROR']);
        }
        $now=time();
        if(!is_array($cap)
          ||($cap['contract']??null)!==$class::CONTRACT
          ||($cap['operation']??null)!==$operation
          ||($cap['provider']??null)!==$provider
          ||($cap['handler_ready']??false)!==true
          ||($cap['target_wpcli_verified']??false)!==true
          ||($cap['readback_supported']??false)!==true
          ||($cap['rollback_supported']??false)!==true
          ||!is_int($cap['heartbeat_epoch']??null)
          ||$cap['heartbeat_epoch']>$now+10
          ||$cap['heartbeat_epoch']<$now-$class::MAX_AGE_SECONDS)
            return array_replace($base,[
                'reason'=>$cap['reason']??'SIGNED_312_CAPABILITY_NOT_CURRENT'
            ]);
        return array_replace($base,[
            'provider_ready'=>true,'state'=>'SIGNED_ORIGINAL_312_OPERATION_PROVIDER_READY',
            'reason'=>null,'company_code'=>$cap['company_code'],
            'environment'=>$cap['environment'],
            'site_uuid'=>$cap['site_uuid'],
            'canonical_origin'=>$cap['canonical_origin'],
            'heartbeat_epoch'=>$cap['heartbeat_epoch'],
            'original_312_signature_verified'=>true
        ]);
    }
    /**
     * Content repair capability is discovered through the registered
     * Original provider, not a global incident-to-operation mapping.
     * Provider readiness and Original 312 remain separately enforced.
     */
    public static function content_readback():array {
        $result=self::scoped('content.write');
        $result['repair_support']=[
            'contract'=>'mad4b.company-repair.provider-capability.v1',
            'operation'=>'content.write',
            'provider'=>self::OPERATIONS['content.write'][0],
            'evidence_contract'=>self::EVIDENCE,
            'resource_scope'=>'exact_site_resource',
            'mutation_authorized'=>false,
            'independent_313_required'=>true,
            'rollback_via_new_original_job'=>true,
            'resource_resolver_contract'=>'mad4b.company-repair.resource-resolver.v2',
            'resource_resolution_read_only'=>true,
            'resource_resolver'=>[__CLASS__,'resolve_content_resource'],
            'resource_types'=>['wordpress.post'],
            'scoped_preflight_contract'=>'mad4b.company-repair.exact-provider-312.v1',
            'scoped_preflight_read_only'=>true,
            'scoped_preflight'=>[__CLASS__,'exact_company_312_readback'],
            // This is a first-party provider capability declaration, not
            // an engine-wide operation table. New providers register their
            // own checks via the Original source-owned registry.
            'supported_checks'=>['eligible_content_manifest'],
        ];
        return $result;
    }
    /**
     * Read only a short-lived, exact-site WordPress snapshot previously
     * captured by authorized target WP-CLI and imported by central operator.
     * No URL requests or native commands occur from web/REST.
     */
    /**
     * Exact Original 312 per company+environment, signature verified by
     * MAD4B_Company_Native_Scoped_Write_Handler_V1. This is NOT a 305 grant
     * and not an independent 313 execution receipt.
     */
    public static function exact_company_312_readback(
        string $company,string $environment,string $operation):array {
        $row=isset(self::OPERATIONS[$operation])
            ?self::scoped($operation,$company,$environment):[];
        $ready=($row['provider_ready']??false)===true
            &&($row['original_312_signature_verified']??false)===true
            &&($row['company_code']??null)===$company
            &&($row['environment']??null)===$environment
            &&($row['operation']??null)===$operation;
        return [
            'contract'=>'mad4b.company-repair.exact-provider-312.v1',
            'state'=>$ready?'EXACT_SOURCE_SIGNED_ORIGINAL_312_VERIFIED':
                'EXACT_COMPANY_PROVIDER_312_HOLD',
            'company_code'=>$company,
            'environment'=>$environment,
            'site_uuid'=>$ready?$row['site_uuid']:null,
            'canonical_origin'=>$ready?$row['canonical_origin']:null,
            'operation'=>$operation,
            'provider'=>self::OPERATIONS[$operation][0]??null,
            'heartbeat_epoch'=>$ready?$row['heartbeat_epoch']:0,
            'source_signature_verified'=>$ready,
            'original_312_signed'=>$ready,
            'native_job_submitted'=>false,
            'original_313_signed'=>false,
            'reason'=>$ready?null:($row['reason']??'SIGNED_312_EXACT_SCOPE_MISSING')
        ];
    }
    public static function resolve_content_resource(array $request):array {
        $hold=[
            'contract'=>'mad4b.company-repair.resource-observation.v1',
            'state'=>'RESOURCE_OBSERVATION_HOLD',
            'reason'=>'TARGET_RESOURCE_SNAPSHOT_NOT_AVAILABLE',
            'mutation_performed'=>false,'native_job_created'=>false,
            'original_312_signed'=>false,'original_313_signed'=>false
        ];
        if(($request['dry_run']??null)!==true
            ||($request['allow_write']??null)!==false
            ||($request['check']??null)!=='eligible_content_manifest'
            ||($request['resource_type']??null)!=='wordpress.post'
            ||!is_string($request['resource_id']??null)
            ||!preg_match('/^[1-9][0-9]{0,9}$/D',$request['resource_id'])
            ||!class_exists('MAD4B_Company_Resource_Snapshot_Store_V1',false))
            return $hold;
        $scope=[
            'company_code'=>$request['company_code']??null,
            'environment'=>$request['environment']??null,
            'site_uuid'=>$request['site_uuid']??null,
            'canonical_origin'=>$request['canonical_origin']??null,
            'incident_code'=>$request['incident_code']??null,
            'source_plan_sha256'=>$request['source_plan_sha256']??null,
        ];
        $snapshot=MAD4B_Company_Resource_Snapshot_Store_V1::get(
            $scope,$request['resource_type'],$request['resource_id']);
        if(is_wp_error($snapshot)){
            if($snapshot->get_error_code()===
                'mad4b_resource_snapshot_snapshot_stale_or_target_claim_invalid')
                $hold['reason']='TARGET_RESOURCE_SNAPSHOT_EXPIRED';
            return $hold;
        }
        $snapshot['company_code']=$scope['company_code'];
        $snapshot['source_plan_sha256']=$scope['source_plan_sha256'];
        $snapshot['operation']='content.write';
        $snapshot['provider']=self::OPERATIONS['content.write'][0];
        $snapshot['target_snapshot_import_level']=
            'CENTRAL_OPERATOR_UPLOADED_UNSIGNED_TARGET_WPCLI';
        // Provider is attesting only to the format of the readback: it is
        // NOT Original 313, and may not authorize even a first write.
        return $snapshot;
    }
    public static function strategy_readback():array {
        return self::scoped('strategy.write');
    }
    public static function operations_readback():array {
        return self::scoped('operations.write');
    }
    public static function commerce_readback():array {
        return self::scoped('commerce.write');
    }
    public static function register(string $registry):void {
        if($registry!=='MAD4B_Original_Native_Operation_Providers_V1'
          ||!class_exists($registry,false))return;
        foreach(self::OPERATIONS as $operation=>$profile){
            $registry::register([
                'operation'=>$operation,'provider'=>$profile[0],
                'authority_family'=>'original_305','target_scope'=>'wordpress',
                'command_format'=>'wpcli.argv.json.v1',
                'evidence_contract'=>self::EVIDENCE,
                'readback'=>[__CLASS__,$profile[1]]
            ]);
        }
    }
    public static function boot():void {
        add_action('mad4b_original_native_operation_providers_register',
            [__CLASS__,'register'],12,1);
        // The first-party registry can initialize before this Add-on.
        if(class_exists('MAD4B_Original_Native_Operation_Providers_V1',false))
            self::register('MAD4B_Original_Native_Operation_Providers_V1');
    }
}
MAD4B_Company_Native_Scoped_Write_Providers_V1::boot();
