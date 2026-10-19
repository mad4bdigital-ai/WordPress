<?php
/**
 * Passive object-cache diagnosis with explicit, bounded Staging CLI acceptance.
 * Page cache, extensions and drop-ins are only hints; no runtime proof inferred.
 */
if (!defined('ABSPATH')) { exit; }
final class MAD4B_SCP_Object_Cache_Diagnostics {
    const CONTRACT='mad4b.object-cache-diagnostics.v1';
    const GROUP='mad4b-object-cache-acceptance';
    const TTL=12;

    public static function status(): array {
        $persistent=function_exists('wp_using_ext_object_cache') && (bool)wp_using_ext_object_cache();
        $api=array(
            'add'=>function_exists('wp_cache_add'),
            'increment'=>function_exists('wp_cache_incr'),
            'get'=>function_exists('wp_cache_get'),
            'delete'=>function_exists('wp_cache_delete'),
        );
        $dropin=defined('WP_CONTENT_DIR') && is_file(WP_CONTENT_DIR.'/object-cache.php');
        $ls=defined('LSCWP_V') || defined('LSCWP_DIR')
            || (function_exists('is_plugin_active') && is_plugin_active('litespeed-cache/litespeed-cache.php'));
        $cls=isset($GLOBALS['wp_object_cache']) && is_object($GLOBALS['wp_object_cache'])
            ? strtolower(get_class($GLOBALS['wp_object_cache'])):'';
        $hint='unknown';
        if(false!==strpos($cls,'memcache')){$hint='memcached_class_hint';}
        elseif(false!==strpos($cls,'redis')){$hint='redis_class_hint';}
        elseif(false!==strpos($cls,'litespeed')||false!==strpos($cls,'lscache')){$hint='litespeed_class_hint';}
        $waf=defined('ETG_DFSB_EXTERNAL_WAF_RATE_LIMIT_VERIFIED')&&true===ETG_DFSB_EXTERNAL_WAF_RATE_LIMIT_VERIFIED;
        return array(
            'contract'=>self::CONTRACT,'read_only'=>true,'authorizing'=>false,'mutation_performed'=>false,
            'litespeed_cache_detected'=>(bool)$ls,'object_cache_dropin_present'=>(bool)$dropin,
            'persistent_object_cache_reported'=>$persistent,
            'memcached_php_extension_available'=>extension_loaded('memcached')||class_exists('Memcached',false),
            'redis_php_extension_available'=>extension_loaded('redis')||class_exists('Redis',false),
            'implementation_hint'=>$hint,'wp_cache_api'=>$api,
            'ajax_rate_limit_mode'=>$persistent&&$api['add']&&$api['increment']?'persistent_object_cache':
                ($waf?'external_waf_attested':'rate_limit_backend_unavailable'),
            'cross_request_proven'=>false,'atomic_increment_proven'=>false,
            'ttl_expiration_proven'=>false,'multi_node_shared_storage_proven'=>false,
            'connection_proven'=>false,'passive_evidence_only'=>true,
            'operator_action'=>'perform_authorized_staging_cross_request_and_concurrency_probe'
        );
    }

    /** Ephemeral namespaced key only; never exposed through a governed read ability. */
    public static function cli_probe($phase,$token='') {
        if(!defined('WP_CLI')||true!==WP_CLI||!function_exists('wp_get_environment_type')
            ||!in_array(wp_get_environment_type(),array('staging','development','local'),true)
            ||!function_exists('current_user_can')||!current_user_can('manage_options')) {
            return self::error('mad4b_cache_probe_forbidden');
        }
        $status=self::status();
        if(!$status['persistent_object_cache_reported']||in_array(false,$status['wp_cache_api'],true)){
            return self::error('mad4b_cache_probe_backend_unavailable');
        }
        if('seed'===$phase) {
            try{$token=bin2hex(random_bytes(16));}
            catch(\Throwable$e){return self::error('mad4b_cache_probe_random_failed');}
        }
        if(!is_string($token)||1!==preg_match('/^[a-f0-9]{32}$/D',$token)){
            return self::error('mad4b_cache_probe_invalid_token');
        }
        $key='mad4b_ocp_'.hash('sha256',$token);
        if('seed'===$phase) {
            if(true!==wp_cache_add($key,1,self::GROUP,self::TTL)){
                return self::error('mad4b_cache_probe_add_failed');
            }
            $count=wp_cache_incr($key,1,self::GROUP);
            if(false===$count||2!==(int)$count){return self::error('mad4b_cache_probe_increment_failed');}
            return array('contract'=>self::CONTRACT,'phase'=>'seed','token'=>$token,
                'counter'=>2,'ttl_seconds'=>self::TTL,'cross_request_proven'=>false,
                'atomic_increment_proven'=>false,'multi_node_shared_storage_proven'=>false);
        }
        $found=false;
        $value=wp_cache_get($key,self::GROUP,false,$found);
        if('expiry'===$phase) {
            return array('contract'=>self::CONTRACT,'phase'=>'expiry',
                'expired_observed'=>!$found,'ttl_expiration_proven'=>!$found,
                'multi_node_shared_storage_proven'=>false);
        }
        if(!$found||!is_numeric($value)||(int)$value<2){
            return self::error('mad4b_cache_probe_cross_request_failed');
        }
        if('read'===$phase) {
            return array('contract'=>self::CONTRACT,'phase'=>'read',
                'counter'=>(int)$value,'cross_request_proven'=>true,
                'atomic_increment_proven'=>false,'multi_node_shared_storage_proven'=>false);
        }
        if('increment'===$phase) {
            $count=wp_cache_incr($key,1,self::GROUP);
            if(false===$count||(int)$count<3){return self::error('mad4b_cache_probe_increment_failed');}
            return array('contract'=>self::CONTRACT,'phase'=>'increment','counter'=>(int)$count,
                'atomic_increment_proven'=>false,'parallel_worker_reconciliation_required'=>true);
        }
        return self::error('mad4b_cache_probe_action_unsupported');
    }

    private static function error($code) {
        return class_exists('WP_Error')?new WP_Error($code,'Object Cache acceptance probe denied or failed.'):
            array('error'=>$code);
    }
}
