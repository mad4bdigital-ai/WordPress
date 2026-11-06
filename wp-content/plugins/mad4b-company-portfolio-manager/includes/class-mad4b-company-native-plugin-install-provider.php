<?php
/**
 * Trusted registration contract for plugin.install.
 *
 * Provider readiness is only an exact-source 312 handler-installation
 * capability check, not a previous Job's 313 execution result.
 * Each new Job must still be authorized and independently verified after run.
 *
 * Never sets grants, never mutates a tenant, never signs its own receipts.
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Native_Plugin_Install_Provider_V1 {
    public const CONTRACT = 'mad4b.company-native-plugin-install-provider.v1';
    public const EVIDENCE = 'mad4b.company-plugin-install-exact-handler.v1';
    public const PROFILE_PROVIDER = 'mad4b_company_plugin_install_exact_handler';
    public const OPTION = 'mad4b_company_native_plugin_install_handler_activation_v1';
    private static function result(string $reason):array {
        return [
            'contract'=>self::EVIDENCE,
            'operation'=>'plugin.install',
            'provider'=>self::PROFILE_PROVIDER,
            'authority_family'=>'original_305',
            'provider_ready'=>false,
            'state'=>'EXACT_312_PLUGIN_INSTALL_HANDLER_NOT_READY',
            'reason'=>$reason,
            'native_305_issued'=>false,
            'signed_312_verified'=>false,
            'original_313_certified'=>false,
            'target_mutated'=>false
        ];
    }

    /**
     * Native 312 capability discovery, independent from any prior Job 313.
     * Only a root-owned first-party handler can attest its live worker health.
     * Missing handlers stay NOT READY; transport grants are checked per company.
     */
    public static function readback():array {
        if(wp_get_environment_type()!=='staging' ||
           untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com')
            return self::result('CENTRAL_STAGING_ONLY');
        if(!class_exists('MAD4B_Central_VPS_Broker_V1',false))
            return self::result('ORIGINAL_305_CORE_NOT_LOADED');
        $class='MAD4B_Company_Native_Plugin_Install_Handler_V1';
        if(!class_exists($class,false) || !is_callable([$class,'capability_status']))
            return self::result('EXACT_312_PLUGIN_INSTALL_HANDLER_NOT_INSTALLED');
        try {
            $method=new ReflectionMethod($class,'capability_status');
            if(!$method->isPublic() || !$method->isStatic())
                return self::result('EXECUTOR_READBACK_CALLBACK_INVALID');
            $file=$method->getFileName();
            $root=realpath(WP_PLUGIN_DIR.'/mad4b-company-portfolio-manager');
            $real=is_string($file)?realpath($file):false;
            if(!is_string($root) || !is_string($real)
                || !str_starts_with($real,$root.'/')
                || is_link($file)
                || fileowner($real)!==0 || (fileperms($real)&0022)!==0)
                return self::result('EXECUTOR_SOURCE_NOT_TRUSTED');
            $cap=$class::capability_status();
        } catch(Throwable $e) {
            return self::result('EXECUTOR_HEALTH_READBACK_FAILED');
        }
        $now=time();
        if(!is_array($cap)
           || ($cap['contract']??null)!=='mad4b.native-312-plugin-install-capability.v1'
           || ($cap['operation']??null)!=='plugin.install'
           || ($cap['transport_operation']??null)!=='company.remote.wpcli.full'
           || ($cap['provider']??null)!==self::PROFILE_PROVIDER
           || ($cap['handler_ready']??null)!==true
           || ($cap['target_wpcli_verified']??null)!==true
           || ($cap['readback_supported']??null)!==true
           || ($cap['rollback_supported']??null)!==true
           || !is_int($cap['heartbeat_epoch']??null)
           || $cap['heartbeat_epoch']>$now+10
           || $cap['heartbeat_epoch']<$now-180)
            return self::result('EXECUTOR_NOT_READY_OR_HEARTBEAT_STALE');
        return [
            'contract'=>self::EVIDENCE,
            'operation'=>'plugin.install',
            'provider'=>self::PROFILE_PROVIDER,
            'authority_family'=>'original_305',
            'provider_ready'=>true,
            'state'=>'EXACT_312_PLUGIN_INSTALL_CAPABLE',
            'native_305_issued'=>false,
            'signed_312_verified'=>false,
            'original_313_certified'=>false,
            'target_mutated'=>false
        ];
    }

    public static function register(string $registry):void {
        if($registry!=='MAD4B_Original_Native_Operation_Providers_V1' ||
           !class_exists($registry,false))return;
        $registry::register([
            'operation'=>'plugin.install',
            'provider'=>self::PROFILE_PROVIDER,
            'authority_family'=>'original_305',
            'target_scope'=>'wordpress',
            'command_format'=>'wpcli.argv.json.v1',
            'evidence_contract'=>self::EVIDENCE,
            'readback'=>[__CLASS__,'readback']
        ]);
    }

    public static function boot():void {
        add_action('mad4b_original_native_operation_providers_register',[__CLASS__,'register'],12,1);
    }
}
MAD4B_Company_Native_Plugin_Install_Provider_V1::boot();
