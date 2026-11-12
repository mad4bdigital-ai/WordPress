<?php
/**
 * Dynamic source-backed original-provider discovery. No per-incident static
 * operation mapping and no operation guessed from ownership or names.
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-mad4b-company-repair-resource-resolver.php';
if(!class_exists('MAD4B_Company_Repair_Capability_Discovery_V1',false)) {
final class MAD4B_Company_Repair_Capability_Discovery_V1 {
    const CONTRACT='mad4b.company-repair.provider-capability.v1';
    const RESULT='mad4b.company-repair.dynamic-discovery.v1';

    public static function inspect_profiles(string $check,array $profiles):array {
        $candidates=[];$rejected=0;$seen=[];
        foreach($profiles as $p){
            if(!is_array($p)
                ||($p['authority_family']??null)!=='original_305'
                ||($p['target_scope']??null)!=='wordpress'
                ||($p['command_format']??null)!=='wpcli.argv.json.v1'
                ||($p['evidence_contract']??null)==='original_305_claim'
                ||($p['provider_readback_contract']??null)!==($p['evidence_contract']??null)
                ||!is_string($p['operation']??null)
                ||!preg_match('/^[a-z][a-z0-9._-]{2,119}$/D',$p['operation'])
                ||!is_string($p['provider']??null)
                ||!preg_match('/^[a-z][a-z0-9._-]{2,119}$/D',$p['provider'])
                ||($p['registry_source_trusted']??null)!==true
                ||!is_array($p['repair_support']??null)){
                $rejected++;continue;
            }
            $support=$p['repair_support'];
            if(($support['contract']??null)!==self::CONTRACT
                ||($support['operation']??null)!==$p['operation']
                ||($support['provider']??null)!==$p['provider']
                ||($support['evidence_contract']??null)!==$p['evidence_contract']
                ||($support['resource_scope']??null)!=='exact_site_resource'
                ||($support['mutation_authorized']??true)!==false
                ||($support['independent_313_required']??false)!==true
                ||($support['rollback_via_new_original_job']??false)!==true
                ||($support['resource_resolver_contract']??null)!==
                    MAD4B_Company_Repair_Resource_Resolver_V2::CONTRACT
                ||($support['resource_resolution_read_only']??null)!==true
                ||!is_array($support['resource_resolver']??null)
                ||array_keys($support['resource_resolver'])!==[0,1]
                ||!is_string($support['resource_resolver'][0]??null)
                ||!is_string($support['resource_resolver'][1]??null)
                ||!is_array($support['resource_types']??null)
                ||count($support['resource_types'])===0
                ||count($support['resource_types'])>32
                ||count(array_unique($support['resource_types']))!==
                   count($support['resource_types'])
                ||count(array_filter($support['resource_types'],static fn($type)=>
                    !is_string($type)||!preg_match('/^[a-z][a-z0-9._-]{2,63}$/D',$type)))>0
                ||!is_array($support['supported_checks']??null)
                ||count($support['supported_checks'])>100
                ||count(array_unique($support['supported_checks']))!==
                   count($support['supported_checks'])
                ||count(array_filter($support['supported_checks'],static fn($v)=>
                    !is_string($v)||!preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$v)))>0
                ||!in_array($check,$support['supported_checks'],true)){
                $rejected++;continue;
            }
            $key=$p['operation'];
            if(array_key_exists($key,$seen)){
                // A third duplicate must NEVER resurrect an ambiguous provider.
                $rejected++;$seen[$key]=false;unset($candidates[$key]);continue;
            }
            $seen[$key]=true;
            $candidates[$key]=[
                'operation'=>$p['operation'],'provider'=>$p['provider'],
                'evidence_contract'=>$p['evidence_contract'],
                'target_scope'=>$p['target_scope'],'command_format'=>$p['command_format'],
                'supported_checks'=>$support['supported_checks'],
                'resource_scope'=>$support['resource_scope'],
                'resource_resolver_contract'=>$support['resource_resolver_contract'],
                'resource_types'=>$support['resource_types'],
                'resource_resolver_id'=>implode('::',$support['resource_resolver']),
                'resource_resolution_read_only'=>true,
                'scoped_preflight_contract'=>$support['scoped_preflight_contract']??null,
                'scoped_preflight_id'=>is_array($support['scoped_preflight']??null)
                    ?implode('::',$support['scoped_preflight']):null,
                'requires_original_305'=>true,'requires_fresh_signed_312'=>true,
                'requires_exact_313'=>true,'writes_allowed_by_discovery'=>false,
            ];
        }
        foreach($seen as $key=>$valid)if(!$valid)unset($candidates[$key]);
        ksort($candidates,SORT_STRING);
        $options=array_values($candidates);
        $state=count($options)===1?'EXACT_CAPABILITY_DISCOVERED':
            (count($options)>1?'MULTIPLE_PROVIDERS_NEED_EXPLICIT_GOVERNED_RESOLUTION':
            'NO_TRUSTED_PROVIDER_DECLARES_REPAIR_SUPPORT');
        $selected=count($options)===1?$options[0]:null;
        $fingerprint=hash('sha256',json_encode(
            ['contract'=>self::RESULT,'check'=>$check,'candidates'=>$options],
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        return [
            'contract'=>self::RESULT,'state'=>$state,'check'=>$check,
            'candidate_count'=>count($options),'rejected_count'=>$rejected,
            'candidates'=>$options,
            'selected'=>$selected,
            'capability_fingerprint'=>$fingerprint,
            'native_305_issued'=>false,'grant_issued'=>false,
            'ready_for_first_mutation'=>false,
        ];
    }
    public static function discover(string $check):array {
        $registry='MAD4B_Original_Native_Operation_Providers_V1';
        if(!class_exists($registry,false)||!is_callable([$registry,'discover']))
            return self::inspect_profiles($check,[]);
        $found=$registry::discover();
        if(($found['contract']??null)!==$registry::CONTRACT
            ||($found['state']??'')!=='DISCOVERY_ONLY'
            ||!is_array($found['operations']??null)
            ||count($found['operations'])>$registry::MAX_PROVIDERS)
            return self::inspect_profiles($check,[]);
        $profiles=[];
        foreach($found['operations'] as $r){
            $op=$r['operation']??null;
            if(!is_string($op))continue;
            $profile=$registry::profile($op);
            if(!is_array($profile)||!is_array($profile['readback']??null))continue;
            try{
                // Registry::register validates callback root ownership and
                // forbids world-writable/non-source callbacks.
                $readback=call_user_func($profile['readback']);
                if(!is_array($readback))continue;
            }catch(Throwable $e){continue;}
            // The root-owned readback file must own the resolver as well.
            // A config option or a different plugin may not nominate one.
            $support=$readback['repair_support']??null;
            if(is_array($support)
                &&is_array($support['resource_resolver']??null)
                &&!MAD4B_Company_Repair_Resource_Resolver_V2::trusted_provider_resolver(
                    $profile,$support['resource_resolver']))
                $support=null;
            $profiles[]=[
                'operation'=>$op,
                'provider'=>$profile['provider']??null,
                'authority_family'=>$profile['authority_family']??null,
                'target_scope'=>$profile['target_scope']??null,
                'command_format'=>$profile['command_format']??null,
                'evidence_contract'=>$profile['evidence_contract']??null,
                'provider_readback_contract'=>$readback['contract']??null,
                'registry_source_trusted'=>true,
                'repair_support'=>$support,
            ];
        }
        return self::inspect_profiles($check,$profiles);
    }
}
}
