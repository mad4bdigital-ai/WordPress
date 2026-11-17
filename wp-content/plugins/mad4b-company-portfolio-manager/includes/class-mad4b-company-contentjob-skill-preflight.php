<?php
/**
 * Company Manager consumer of the ORIGINAL ContentJob dynamic Skill Set.
 *
 * No parallel planner, job, permission, grant or acceptance engine.
 * Original ContentJobs owns Skill Set plan + post-apply readback; Company
 * Manager owns the company identity and operation ordering.
 */
defined('ABSPATH')||exit;
final class MAD4B_Company_ContentJob_Skill_Preflight_V1 {
    const CONTRACT='mad4b.company-manager.contentjob-skill-preflight.v1';
    const SKILL='workflow:company-reconcile:content-jobs-ui-reconcile';
    const NAME='content-jobs-ui-reconcile';
    const OPS=['start_company','company_reconcile','reconcile_repair'];
    private static function fail(string $id,int $status=409):WP_Error {
        return new WP_Error('mad4b_company_contentjob_'.$id,
            'Company ContentJob Skill admission: '.$id,
            ['status'=>$status,'mutation_performed'=>false]);
    }
    private static function resource(string $name,string $contract) {
        $base=MAD4B_SCP_Skill_Registry::storage_root().
            '/workflow/company-reconcile/'.self::NAME.'/references/';
        $file=$base.$name;
        if(!is_file($file)||is_link($file)||!is_readable($file))return null;
        $raw=file_get_contents($file);
        if(!is_string($raw)||strlen($raw)<40||strlen($raw)>65536)return null;
        $doc=json_decode($raw,true);
        if(!is_array($doc)||($doc['contract']??null)!==$contract)return null;
        return ['doc'=>$doc,'sha256'=>hash('sha256',$raw)];
    }
    public static function inspect(string $code,
            string $operation='company_reconcile',
            string $phase='pre_execution') {
        if(!MAD4B_Company_Portfolio_Manager_V1::staging())
            return self::fail('central_staging_required',403);
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code)
            ||!in_array($operation,self::OPS,true)
            ||!in_array($phase,['pre_execution','post_apply_acceptance'],true))
            return self::fail('scope_invalid',422);
        $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($code);
        if(is_wp_error($binding))return $binding;
        if(!class_exists('MAD4B_SCP_Skill_Registry',false)
           ||!class_exists('MAD4B_SCP_Company_Reconcile_Skill_Set_V1',false)
           ||!class_exists('MAD4B_SCP_Content_Jobs',false)
           ||!class_exists('MAD4B_Company_Environment_Registry_V2',false))
            return self::fail('original_contentjob_skill_owner_missing',503);
        $skill=MAD4B_SCP_Skill_Registry::get_skill('workflow','company-reconcile',
            self::NAME);
        if(is_wp_error($skill)||($skill['enabled']??false)!==true
            ||!hash_equals((string)($skill['sha256']??''),
                hash('sha256',(string)($skill['content']??''))))
            return self::fail('mandatory_runtime_skill_not_verified',503);
        $original=self::resource('content-jobs-reconcile-contract-v1.json',
            'mad4b.contentjob.company-reconcile-skill.v1');
        $policy=self::resource('dynamic-skill-family-policy-v1.json',
            'mad4b.company-reconcile.dynamic-skill-policy.v1');
        if(!$original||!$policy
            ||!in_array($operation,(array)($original['doc']['apply_scope']??[]),true)
            ||!in_array($operation,(array)($policy['doc']['execution_stage']??[]),true)
            ||($original['doc']['synthetic_evidence']??null)!=='DENIED'
            ||($policy['doc']['never_confers_execution_grant']??false)!==true)
            return self::fail('skill_contract_or_policy_invalid',503);
        // Manager identifies the exact enrolled environment; never infer
        // destination from DNS or accept caller-supplied site UUID.
        $targets=[];
        foreach(['staging','production'] as $env) {
            $site=MAD4B_Company_Environment_Registry_V2::target($code,$env);
            if(is_array($site)
                &&($site['site_uuid']??'')===$binding['company_site_uuid'])
                $targets[]=['environment'=>$env,
                    'site_uuid'=>$site['site_uuid'],
                    'canonical_origin'=>$site['canonical_origin']??''];
        }
        if(count($targets)!==1)return self::fail('exact_environment_binding_ambiguous',409);
        $nativeInput=[
            'company_code'=>$code,
            'environment'=>$targets[0]['environment'],
            'site_uuid'=>$targets[0]['site_uuid']
        ];
        // Native owner selects Skills by active providers, dimensions and
        // registered abilities; no second selection algorithm here.
        $plan=MAD4B_SCP_Company_Reconcile_Skill_Set_V1::plan($nativeInput);
        if(!is_array($plan)||($plan['contract']??'')!==
            MAD4B_SCP_Company_Reconcile_Skill_Set_V1::CONTRACT)
            return self::fail('native_skill_plan_invalid',503);
        $canPlan=($plan['pre_execution_skill_selection_passed']??false)===true
            &&($plan['state']??'')==='READY_FOR_GOVERNED_REPAIR_PLAN'
            &&($plan['native_execution_authorized']??true)===false;
        $acceptance=null;
        if($phase==='post_apply_acceptance' && $canPlan) {
            // Use the exact digest returned by the owner; never recompute
            // or infer an approval from file checks or ContentJob status.
            $acceptance=MAD4B_SCP_Company_Reconcile_Skill_Set_V1::acceptance(
                $nativeInput+[
                    'expected_skill_set_sha256'=>$plan['skill_set_sha256']
                ]);
        }
        $tasks=[];
        foreach((array)($original['doc']['tasks']??[]) as $name=>$checks)
            $tasks[$name]=['state'=>'UNTESTED',
                'required_checks'=>array_values((array)$checks),
                'signed_target_readback_verified'=>false];
        $seed=defined('MAD4B_SCP_DIR')
            ?MAD4B_SCP_DIR.'skill-seeds/'.self::NAME.'/SKILL.md':'';
        $seedHash=$seed&&is_file($seed)&&!is_link($seed)
            ?hash_file('sha256',$seed):null;
        $out=[
            'contract'=>self::CONTRACT,
            'company_code'=>$code,
            'company_site_uuid'=>$binding['company_site_uuid'],
            'company_binding_sha256'=>$binding['binding_sha256'],
            'operation'=>$operation,'phase'=>$phase,
            'target_environment'=>$targets[0]['environment'],
            'target_origin'=>$targets[0]['canonical_origin'],
            'mandatory_skill_logical_id'=>self::SKILL,
            'mandatory_skill_sha256'=>$skill['sha256'],
            'mandatory_skill_registered_enabled'=>true,
            'seed_baseline_sha256'=>$seedHash,
            'runtime_skill_has_owner_modifications'=>$seedHash!==$skill['sha256'],
            'runtime_skill_is_primary_source'=>true,
            'source_skill_contract_sha256'=>$original['sha256'],
            'source_dynamic_policy_sha256'=>$policy['sha256'],
            'native_skill_set_contract'=>$plan['contract'],
            'native_skill_set_sha256'=>$plan['skill_set_sha256']??null,
            'native_skill_plan_state'=>$plan['state']??'HOLD',
            'native_skill_plan_blocker'=>$plan['reason']??null,
            'native_required_skill_count'=>$plan['required_skill_count']??0,
            'native_detected_provider_families'=>$plan['detected_plugin_families']??[],
            'native_selected_dimensions'=>$plan['dimensions']??[],
            'native_missing_required_skills'=>$plan['missing_required_skills']??[],
            'native_external_recommendation_count'=>count((array)($plan['external_skill_recommendations']??[])),
            'external_skill_autoinstall'=>false,
            'target_provider_inventory_verified'=>false,
            'post_apply_original_result'=>is_array($acceptance)
                ?($acceptance['state']??'UNTESTED'):null,
            'post_apply_acceptance_checks'=>$tasks,
            'state'=>$canPlan?($phase==='pre_execution'
                ?'PREFLIGHT_PLAN_READY_APPLY_HOLD':'POST_APPLY_ACCEPTANCE_HOLD')
                :'HOLD_ORIGINAL_SKILL_SET_UNAVAILABLE',
            'can_prepare_company_plan'=>$canPlan && $phase==='pre_execution',
            'can_create_content_job'=>false,
            'can_execute_company_reconcile'=>false,
            'can_finalize_company_reconcile'=>false,
            'original_content_jobs_retains_authority'=>true,
            'content_jobs_created'=>0,
            'company_options_mutated'=>false
        ];
        // The existing ContentJob/Company Reconcile skill remains the owner.
        // This is source preflight evidence only: target WPML/JetEngine parity
        // must be read back through that company's authorized Native Executor.
        // A source PASS cannot certify a different company or environment.
        $parity=class_exists('MAD4B_WPML_Term_Parity_V1',false)
            ?MAD4B_WPML_Term_Parity_V1::audit(200)
            :['contract'=>'mad4b.wpml-taxonomy-parity.v1','state'=>'SOURCE_PROVIDER_NOT_INSTALLED'];
        $parityState=(string)($parity['state']??'HOLD');
        $out['source_wpml_taxonomy_parity']=[
            'contract'=>$parity['contract']??'mad4b.wpml-taxonomy-parity.v1',
            'state'=>$parityState,
            'affected_post_count'=>(int)($parity['count']??0),
            'source_host'=>home_url('/'),
            'snapshot_sha256'=>hash('sha256',wp_json_encode($parity,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))
        ];
        $out['target_wpml_taxonomy_parity']=[
            'state'=>'UNTESTED_TARGET_NATIVE_READBACK_REQUIRED',
            'target_environment'=>$targets[0]['environment'],
            'target_origin'=>$targets[0]['canonical_origin'],
            'required_checks'=>['native_term_relationships','same_wpml_trid',
                'post_and_term_language_equal','no_duplicate_translated_term',
                'post_apply_parity_recheck','rollback_receipt_if_modified'],
            'target_write_authorized'=>false
        ];
        $out['post_apply_acceptance_checks']['wpml_taxonomy_parity']=[
            'state'=>'UNTESTED_TARGET_NATIVE_READBACK_REQUIRED',
            'source_state'=>$parityState,
            'signed_target_readback_verified'=>false,
            'required_checks'=>$out['target_wpml_taxonomy_parity']['required_checks']
        ];
        // A source with mismatched WPML categories is not a valid clone seed;
        // automatic repair is owned by the source runtime and does not grant
        // a target write. No optional certificate or owner signature added.
        $wpmlActive=in_array('wpml',(array)($plan['detected_plugin_families']??[]),true);
        if($wpmlActive && $parityState!=='PASS'){
            $out['state']='HOLD_SOURCE_WPML_TAXONOMY_PARITY';
            $out['can_prepare_company_plan']=false;
            $out['can_execute_company_reconcile']=false;
        }
        $out['snapshot_sha256']=hash('sha256',
            wp_json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        return $out;
    }
    public static function ready_for_strategy_reconcile(string $company) {
        $r=self::inspect($company,'company_reconcile');
        if(is_wp_error($r))return $r;
        if(($r['can_prepare_company_plan']??false)!==true)
            return self::fail('original_skill_set_holds_company_plan',503);
        return $r;
    }
    public static function permission() {
        return MAD4B_Company_Portfolio_Manager_V1::admin_permission();
    }
    public static function routes():void {
        register_rest_route('mad4b/v1',
            '/company-managers/companies/(?P<company>[a-z][a-z0-9-]{2,47})/contentjob-preflight',[
            'methods'=>'GET',
            'permission_callback'=>[__CLASS__,'permission'],
            'callback'=>static function($request){
                $result=self::inspect((string)$request['company'],
                    (string)($request->get_param('operation')?:'company_reconcile'),
                    (string)($request->get_param('phase')?:'pre_execution'));
                $response=rest_ensure_response($result);
                if($response instanceof WP_REST_Response)
                    $response->header('Cache-Control','no-store, private');
                return $response;
            }
        ]);
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'routes'],91);
    }
}
MAD4B_Company_ContentJob_Skill_Preflight_V1::boot();
