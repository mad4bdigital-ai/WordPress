<?php
/**
 * MAD4B runtime capability-owner observation v1.
 * Read-only WP-CLI audit. Never detaches hooks, activates plugins, grants
 * authority, mutates options, or decides semantic parity from class presence.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    return;
}
if (!function_exists('mad4b_company_owner_observe_v1')) {
    function mad4b_company_owner_observe_v1(): array {
        $contract='mad4b.company-runtime-owner-observation.v1';
        $relations=[];
        $profiles=glob(WP_PLUGIN_DIR.'/mad4b-*/mad4b-portability.json') ?: [];
        if (count($profiles)>250) throw new RuntimeException('PROFILE_COUNT_EXCEEDED');
        $classesByFile=[];
        foreach(get_declared_classes() as $class){
            try{$r=new ReflectionClass($class);$f=$r->getFileName();}
            catch(Throwable $e){continue;}
            if(is_string($f))$classesByFile[$f][]=$class;
        }
        $hooksByFile=[];
        global $wp_filter;
        foreach((array)$wp_filter as $hook){
            if(!is_object($hook)||!is_array($hook->callbacks??null))continue;
            foreach($hook->callbacks as $entries)foreach((array)$entries as $entry){
                $callback=$entry['function']??null;
                try {
                    if($callback instanceof Closure ||
                        (is_string($callback)&&function_exists($callback))){
                        $ref=new ReflectionFunction($callback);
                    }elseif(is_array($callback)&&count($callback)===2){
                        $base=$callback[0];$class=is_object($base)?get_class($base):$base;
                        if(!is_string($class)||!class_exists($class,false)||
                           !method_exists($class,(string)$callback[1]))continue;
                        $ref=new ReflectionMethod($class,(string)$callback[1]);
                    }elseif(is_object($callback)&&method_exists($callback,'__invoke')){
                        $ref=new ReflectionMethod($callback,'__invoke');
                    }else continue;
                    $file=$ref->getFileName();
                    if(is_string($file))$hooksByFile[$file]=($hooksByFile[$file]??0)+1;
                }catch(Throwable $e){continue;}
            }
        }
        $included=array_fill_keys(array_filter(array_map('realpath',get_included_files())),true);
        foreach ($profiles as $profile) {
            if (is_link($profile)||is_link(dirname($profile))||
                filesize($profile)>200000) throw new RuntimeException('UNSAFE_PROFILE_FILE');
            $raw=file_get_contents($profile);
            $manifest=json_decode((string)$raw,true);
            $plugin=basename(dirname($profile));
            if (!is_array($manifest) || ($manifest['contract']??'')!=='mad4b.plugin-portability-profile.v1' ||
                ($manifest['plugin']??'')!==$plugin) throw new RuntimeException('PORTABILITY_IDENTITY_INVALID');
            $moves=$manifest['company_specific_legacy_mu_replacements']??[];
            if (!is_array($moves) || count($moves)>100) throw new RuntimeException('MIGRATION_CONTRACT_INVALID');
            foreach ($moves as $move) {
                if (!is_array($move)) throw new RuntimeException('MIGRATION_ITEM_INVALID');
                $old=(string)($move['legacy_file']??'');$new=(string)($move['replacement']??'');
                if (!preg_match('/^[A-Za-z0-9_-]{1,150}\.php$/D',$old) ||
                    !preg_match('~^src/[A-Za-z0-9_./-]{1,220}\.php$~D',$new) ||
                    in_array('..',explode('/',$new),true)) throw new RuntimeException('MIGRATION_PATH_INVALID');
                $oldFile=WPMU_PLUGIN_DIR.'/'.$old;
                $newFile=WP_PLUGIN_DIR.'/'.$plugin.'/'.$new;
                $oldReal=is_file($oldFile)?realpath($oldFile):false;
                $newReal=is_file($newFile)?realpath($newFile):false;
                if (is_link($oldFile)||is_link($newFile)||is_link(dirname($newFile)))
                    throw new RuntimeException('SYMLINK_OWNER_REJECTED');
                $muRoot=realpath(WPMU_PLUGIN_DIR);$pluginRoot=realpath(WP_PLUGIN_DIR.'/'.$plugin);
                if (($oldReal!==false && !str_starts_with($oldReal,$muRoot.'/'))||
                    ($newReal!==false && !str_starts_with($newReal,$pluginRoot.'/')))
                    throw new RuntimeException('OWNER_PATH_ESCAPED_SITE');
                $paths=['legacy'=>$oldReal,'plugin'=>$newReal];
                $observed=[];
                foreach($paths as $role=>$real) {
                    $declared=$real!==false?($classesByFile[$real]??[]):[];
                    $hooks=$real!==false?($hooksByFile[$real]??0):0;
                    $observed[$role]=[
                        'file_present'=>$real!==false,
                        'included_in_php_runtime'=>$real!==false && isset($included[$real]),
                        'sha256'=>$real!==false?hash_file('sha256',$real):null,
                        'declared_classes'=>$declared,
                        'registered_hook_callbacks'=>$hooks,
                    ];
                }
                $legacyHooks=$observed['legacy']['registered_hook_callbacks'];
                $nativeHooks=$observed['plugin']['registered_hook_callbacks'];
                $state=$legacyHooks && $nativeHooks?'DUAL_HOOK_OWNERS_CONFLICT':
                    ($legacyHooks?'LEGACY_HOOK_OWNER_OBSERVED':
                     ($nativeHooks?'PLUGIN_HOOK_OWNER_OBSERVED':'OWNER_UNPROVEN'));
                $relations[]=[
                    'plugin'=>$plugin,'reconcile_contract'=>(string)($move['reconcile_contract']??''),
                    'legacy_file'=>$old,'replacement'=>$new,
                    'state'=>$state,'legacy'=>$observed['legacy'],'canonical'=>$observed['plugin'],
                    'functional_parity_certified'=>false,
                    'signed_transition_authority'=>false,
                ];
            }
        }
        usort($relations,static fn(array $a,array $b):int=>strcmp($a['plugin'].$a['legacy_file'],$b['plugin'].$b['legacy_file']));
        return [
            'contract'=>$contract,'site_url'=>home_url('/'),'observation_lane'=>'read_only_wp_cli',
            'relations'=>$relations,'mutation_performed'=>false,
            'authorization_issued'=>false,'independent_313_receipt_issued'=>false,
        ];
    }
}
echo wp_json_encode(mad4b_company_owner_observe_v1(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
