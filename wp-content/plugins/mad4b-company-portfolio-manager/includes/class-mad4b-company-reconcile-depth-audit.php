<?php
/**
 * Company Reconcile depth audit: a read-only dependency, coverage and
 * evidence-quality evaluator. It never implies Original grant, receipt or apply.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Company_Reconcile_Depth_Audit_V1',false)) {
final class MAD4B_Company_Reconcile_Depth_Audit_V1 {
    const CONTRACT='mad4b.company-reconcile.depth-audit.v1';
    private static function invalid(string $reason):array {
        return [
            'contract'=>self::CONTRACT,
            'state'=>'SOURCE_POLICY_INTEGRITY_REVIEW_REQUIRED',
            'reason'=>$reason,
            'dependency_graph_valid'=>false,
            'planning_advisory_only'=>true,
            'can_start_mutation'=>false,
            'company_release_certified'=>false,
            'mutation_performed'=>false,
        ];
    }
    /**
     * Optional, separately invoked comparison with a real site-local probe.
     * Unsigned observations may drive triage, never Original 313 acceptance.
     * Expected identity is operator/backend bound, not copied from the probe.
     */
    public static function correlate_local_probe(array $depth,array $probe,
        string $expectedUuid,string $expectedOrigin,string $expectedEnv,
        int $now=0,int $maxAge=1800):array {
        $base=[
            'contract'=>self::CONTRACT.'.local-probe-triage',
            'state'=>'REJECTED_UNTRUSTED_TARGET_PROBE',
            'site_binding_verified'=>false,
            'independent_original_313_verified'=>false,
            'can_start_mutation'=>false,
            'can_close_any_stage'=>false,
            'originated_jobs'=>false,
        ];
        if(($depth['dependency_graph_valid']??false)!==true
            ||($probe['contract']??'')!=='mad4b.company-reconcile.target-evidence.v1'
            ||($probe['source_of_evidence']??'')!=='LOCAL_WORDPRESS_READ_ONLY'
            ||($probe['provider_signature_present']??true)!==false
            ||($probe['native_receipt_313_present']??true)!==false
            ||($probe['native_execution_authorized']??true)!==false
            ||($probe['mutation_performed']??true)!==false)
            return $base+['reason'=>'probe_contract_or_authority_claim_invalid'];
        if(!is_array($probe['site']??null)
            ||($probe['site']['site_uuid']??null)!==$expectedUuid
            ||($probe['site']['origin']??null)!==$expectedOrigin
            ||($probe['site']['environment']??null)!==$expectedEnv)
            return $base+['reason'=>'exact_site_identity_mismatch'];
        $now=$now>0?$now:time();
        $observed=is_string($probe['collected_utc']??null)
            ?strtotime($probe['collected_utc']):false;
        if($observed===false || $observed>$now+120 || $now-$observed>$maxAge
            || $maxAge<1 || $maxAge>86400)
            return $base+['reason'=>'missing_future_or_stale_observation'];
        $seen=[];
        if(!is_array($probe['checks']??null)||count($probe['checks'])>120)
            return $base+['reason'=>'probe_check_list_invalid'];
        foreach($probe['checks'] as $row) {
            if(!is_array($row)
                ||!is_string($row['check']??null)
                ||!preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$row['check'])
                ||isset($seen[$row['check']])
                ||!in_array(($row['state']??''),[
                    'PASS_LOCAL','DRIFT','ADVISORY','UNVERIFIED'],true))
                return $base+['reason'=>'probe_check_duplicate_or_invalid'];
            $seen[$row['check']]=$row['state'];
        }
        $states=[
            'LOCAL_PASS_NOT_CERTIFIED'=>0,'LOCAL_ADVISORY_REVIEW'=>0,
            'LOCAL_DRIFT_REVIEW'=>0,'TARGET_PROBE_UNVERIFIED'=>0,
            'TARGET_PROBE_DESIGN_REQUIRED'=>0,
            'ORIGINAL_PROVIDER_RECEIPT_REQUIRED'=>0,
            'COMPANY_PRE_POST_DIGEST_REQUIRED'=>0,
            'NAMED_TARGET_PROBE_NOT_REPORTED'=>0,
        ];
        $items=[];
        foreach((array)($depth['incident_probe_mapping']??[]) as $item) {
            $id=$item['target_probe_check']??null;
            if($id===null)$state=match($item['evidence_lane']??''){
                'original_provider'=>'ORIGINAL_PROVIDER_RECEIPT_REQUIRED',
                'company_pre_post_signed_digest'=>'COMPANY_PRE_POST_DIGEST_REQUIRED',
                default=>'TARGET_PROBE_DESIGN_REQUIRED',
            };
            elseif(!isset($seen[$id]))$state='NAMED_TARGET_PROBE_NOT_REPORTED';
            else $state=match($seen[$id]) {
                'PASS_LOCAL'=>'LOCAL_PASS_NOT_CERTIFIED',
                'DRIFT'=>'LOCAL_DRIFT_REVIEW',
                'ADVISORY'=>'LOCAL_ADVISORY_REVIEW',
                default=>'TARGET_PROBE_UNVERIFIED'
            };
            $states[$state]++;
            $items[]=[
                'incident_code'=>$item['code']??'unknown',
                'priority'=>$item['priority']??'P1',
                'target_probe_check'=>$id,
                'local_evidence_state'=>$state,
                'accepted_as_original_313'=>false,
                'may_auto_apply'=>false,
            ];
        }
        if(count($items)!==($depth['scenario_total']??-1))
            return $base+['reason'=>'incident_mapping_incomplete'];
        return array_replace($base,[
            'state'=>'FRESH_SITE_LOCAL_TRIAGE_UNSIGNED',
            'site_binding_verified'=>true,
            'observed_utc'=>$probe['collected_utc'],
            'evidence_age_seconds'=>$now-$observed,
            'incident_count'=>count($items),
            'counts'=>$states,
            'incidents'=>$items,
            'target_collector_observation_is_not_a_signed_receipt'=>true,
        ]);
    }
    public static function evaluate(array $policy,array $work,array $scenarios):array {
        $stages=(array)($policy['stages']??[]);
        $dependency=(array)($policy['check_dependencies']??[]);
        if(($policy['contract']??'')!=='mad4b.company-start-reconcile-completeness.v1'
            ||($policy['dependency_policy_kind']??'')!=='diagnostic_order_only_no_execution_grant'
            ||!isset($stages['bootstrap'],$stages['functional'],$stages['presentation'],$stages['commercial'])
            ||count($stages)!==4)
            return self::invalid('required_source_contract_or_stage_missing');
        $checks=[];$stagesByCheck=[];
        foreach($stages as $stage=>$names) {
            if(!is_array($names)||count($names)>64)return self::invalid('invalid_stage_list');
            foreach($names as $check) {
                if(!is_string($check)
                    ||!preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$check)
                    ||isset($checks[$check]))return self::invalid('duplicate_or_invalid_check_name');
                $checks[$check]=true;
                $stagesByCheck[$check]=$stage;
            }
        }
        if(count($checks)>160 || count($checks)===0
            ||count($dependency)!==count($checks)
            ||array_diff(array_keys($checks),array_keys($dependency))
            ||array_diff(array_keys($dependency),array_keys($checks)))
            return self::invalid('dependency_coverage_incomplete');
        $edgeCount=0;$indeg=[];$downstream=[];
        foreach($dependency as $check=>$parents) {
            if(!is_array($parents)||count($parents)>24
                ||count(array_unique($parents))!==count($parents))
                return self::invalid('duplicate_or_invalid_dependencies');
            $indeg[$check]=count($parents);
            foreach($parents as $parent) {
                if(!is_string($parent)||!isset($checks[$parent])||$parent===$check)
                    return self::invalid('unknown_or_self_dependency');
                $edgeCount++;
                $downstream[$parent][]=$check;
            }
        }
        // Kahn topological ordering; no WordPress queries, recursion or I/O.
        $waves=[];$order=array_keys($checks);$pending=$indeg;
        while($pending) {
            $wave=[];
            foreach($order as $check)
                if(isset($pending[$check])&&$pending[$check]===0)$wave[]=$check;
            if(!$wave)return self::invalid('dependency_cycle');
            $waves[]=$wave;
            foreach($wave as $check) {
                unset($pending[$check]);
                foreach($downstream[$check]??[] as $child) {
                    if(isset($pending[$child]))$pending[$child]--;
                }
            }
            if(count($waves)>160)return self::invalid('dependency_depth_exceeded');
        }
        $workByCheck=[];
        foreach($work as $row) {
            if(!is_array($row)||!is_string($row['check']??null)
                ||!isset($checks[$row['check']])||isset($workByCheck[$row['check']]))
                return self::invalid('work_item_scope_or_duplicate');
            if(($row['auto_apply']??true)!==false
                ||($row['original_305_312_313_required_for_mutation']??false)!==true
                ||($row['stage']??'')!==$stagesByCheck[$row['check']])
                return self::invalid('work_item_incorrect_authority_or_stage');
            $workByCheck[$row['check']]=$row;
        }
        if(count($workByCheck)!==count($checks))
            return self::invalid('work_item_missing');
        // Do not silently sanitize away unsafe or invalid source policy
        // declarations while constructing the advisory incident projection.
        $raw=(array)($policy['incident_scenarios']??[]);
        if(count($raw)!==count($scenarios) || count($raw)>30)
            return self::invalid('raw_incident_projection_count_mismatch');
        $declaredOwnership=['shared_definition','tenant_owned','site_generated',
            'reconnect','never_clone','inspect_only','company_policy','mixed'];
        $lanes=(array)($policy['incident_evidence_lanes']??[]);
        if(count($lanes)!==count($raw)
           ||array_diff(array_keys($lanes),array_column($raw,'code'))
           ||array_diff(array_column($raw,'code'),array_keys($lanes)))
            return self::invalid('incident_evidence_lanes_incomplete');
        foreach($raw as $index=>$source) {
            if(!is_array($source)
                ||($source['auto_apply']??null)!==false
                ||!is_string($source['code']??null)
                ||($source['code']??null)!==($scenarios[$index]['code']??null)
                ||($source['related_check']??null)!==($scenarios[$index]['related_check']??null)
                ||!in_array(($source['ownership_scope']??''),$declaredOwnership,true)
                ||!in_array(($source['priority']??null),['P0','P1','P2'],true))
                return self::invalid('raw_incident_policy_unsafe_or_projection_changed');
            $probe=$source['target_probe_check']??null;
            if($probe!==null
                &&(!is_string($probe)
                    ||!preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$probe)))
                return self::invalid('raw_incident_probe_invalid');
            $lane=$lanes[$source['code']]??null;
            if(!in_array($lane,[
                'target_local','target_probe_design_pending',
                'company_pre_post_signed_digest','original_provider'],true)
                ||($probe!==null && $lane!=='target_local')
                ||($probe===null && $lane==='target_local'))
                return self::invalid('incident_evidence_lane_incompatible');
        }
        $mapped=[];$hasProbe=0;$noProbe=[];$priorityCounts=['P0'=>0,'P1'=>0,'P2'=>0];
        $probeMapping=[];
        $p0=[];$scenarioKeys=[];$scenariosByCheck=[];
        foreach($scenarios as $scenario) {
            $code=$scenario['code']??null;$linked=$scenario['related_check']??null;
            if(!is_string($code)||!preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$code)
                ||isset($scenarioKeys[$code])||!is_string($linked)||!isset($checks[$linked])
                ||($scenario['auto_apply']??true)!==false
                ||($scenario['native_execution_authorized']??true)!==false)
                return self::invalid('incident_identity_or_authority_invalid');
            $scenarioKeys[$code]=true;
            $mapped[$linked]=true;$scenariosByCheck[$linked][]=$code;
            $priority=$scenario['priority']??null;
            if(!isset($priorityCounts[$priority]))
                return self::invalid('incident_priority_invalid');
            $priorityCounts[$priority]++;
            if($priority==='P0')$p0[]=$code;
            $probe=$scenario['target_probe_check']??null;
            if($probe===null)$noProbe[]=$code;
            elseif(is_string($probe)
                &&preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$probe))$hasProbe++;
            else return self::invalid('incident_probe_name_invalid');
            $probeMapping[]=[
                'code'=>$code,'related_check'=>$linked,'stage'=>$stagesByCheck[$linked],
                'priority'=>$priority,'target_probe_check'=>$probe,
                'evidence_lane'=>$lanes[$code],
                'evidence_state'=>'NOT_INGESTED',
                'ready_for_mutation'=>false,
            ];
        }
        $withoutScenario=array_values(array_diff(array_keys($checks),array_keys($mapped)));
        $probeRequiredWork=[];
        foreach($work as $row) {
            $check=$row['check'];
            $probeRequiredWork[]=[
                'check'=>$check,
                'stage'=>$row['stage'],
                'depends_on'=>$dependency[$check],
                'incident_ids'=>$scenariosByCheck[$check]??[],
                'incident_count'=>count($scenariosByCheck[$check]??[]),
                'independent_provider_readback'=>'REQUIRED',
                'local_observation_may_close'=>false,
                'operator_may_auto_apply'=>false,
            ];
        }
        $riskRegister=[
            ['code'=>'ORIGINAL_SIGNED_RECEIPT_MISSING',
             'severity'=>'P0','status'=>'REQUIRES_EXACT_JOB_AND_INDEPENDENT_313'],
            ['code'=>'FULL_INVENTORY_COVERAGE_UNVERIFIED',
             'severity'=>'P0','status'=>'NO_SITE_WIDE_DIFF_FROM_PAGED_MANIFESTS'],
            ['code'=>'OWNER_PRESERVATION_NOT_POSTCONDITION_VERIFIED',
             'severity'=>'P0','status'=>'CHECK_COMPANY_OWNED_VALUES_BEFORE_AFTER'],
            ['code'=>'BROWSER_LOCALE_ACCEPTANCE_PENDING',
             'severity'=>'P1','status'=>'TARGET_DEVICE_LANGUAGE_AJAX_READBACK_REQUIRED'],
            ['code'=>'UNSPECIFIED_SCENARIO_PROBES',
             'severity'=>'P1','status'=>$noProbe?'INCOMPLETE':'ALL_DECLARED',
             'incident_codes'=>$noProbe],
        ];
        return [
            'contract'=>self::CONTRACT,
            'state'=>'POLICY_DEPENDENCIES_VALID__PROVIDER_EVIDENCE_PENDING',
            'policy_checks'=>count($checks),
            'policy_stage_count'=>count($stages),
            'dependency_graph_valid'=>true,
            'dependency_edge_count'=>$edgeCount,
            'dependency_wave_count'=>count($waves),
            'dependency_waves'=>array_map(
                static fn($n,$items)=>['wave'=>$n+1,'checks'=>$items,
                    'count'=>count($items)],array_keys($waves),$waves),
            'dependency_not_execution_authority'=>true,
            'scenario_total'=>count($scenarios),
            'checks_with_incident_playbook'=>count($mapped),
            'checks_without_incident_playbook'=>count($withoutScenario),
            'checks_without_incident_playbook_names'=>$withoutScenario,
            'incident_scenarios_with_named_probe'=>$hasProbe,
            'incident_scenarios_without_named_probe'=>count($noProbe),
            'incident_codes_missing_probe'=>$noProbe,
            'incident_evidence_lane_counts'=>array_count_values(array_values($lanes)),
            'incident_probe_mapping'=>$probeMapping,
            'incident_priority_counts'=>$priorityCounts,
            'critical_incidents'=>$p0,
            'check_mapping'=>$probeRequiredWork,
            'risk_register'=>$riskRegister,
            'evidence_verified_as_independent_original_313'=>false,
            'local_target_probe_data_ingested'=>false,
            'local_probe_is_never_signed_original_receipt'=>true,
            'browser_acceptance_completed'=>false,
            'owner_preservation_postcondition_verified'=>false,
            'new_native_job_submitted'=>false,
            'can_start_mutation'=>false,
            'company_release_certified'=>false,
            'planning_advisory_only'=>true,
            'mutation_performed'=>false,
        ];
    }
}
}
