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
    private static function scoped(string $operation):array {
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
            $cap=$class::capability_status($operation);
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
            'reason'=>null
        ]);
    }
    public static function content_readback():array {
        return self::scoped('content.write');
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
