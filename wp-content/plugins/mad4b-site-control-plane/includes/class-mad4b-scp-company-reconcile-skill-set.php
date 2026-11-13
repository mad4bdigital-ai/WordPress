<?php
/**
 * Native dynamic Skill Set for Company Manager -> Content Jobs -> Reconcile.
 *
 * Resolves source-active provider families and operation dimensions against
 * the existing file-backed Skill Registry. Plans are non-authorizing; the
 * original ContentJob/CWA and browser providers retain execution/readback.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Company_Reconcile_Skill_Set_V1 {
    const CONTRACT = 'mad4b.company-reconcile-dynamic-skill-set.v1';
    const POLICY_CONTRACT = 'mad4b.company-reconcile.dynamic-skill-policy.v1';

    public static function boot(): void {
        add_action('wp_abilities_api_init', [__CLASS__, 'register_abilities'], 42);
    }

    private static function blocked(string $reason, array $details=[]): array {
        return array_merge([
            'contract' => self::CONTRACT, 'state' => 'HOLD',
            'reason' => $reason, 'pre_execution_skill_selection_passed' => false,
            'native_execution_authorized' => false,
            'post_apply_acceptance' => 'UNTESTED',
            'reconcile_closure_accepted' => false,
            'mutation_performed' => false,
        ], $details);
    }

    private static function policy(): array {
        $path = WP_CONTENT_DIR . '/mad4b-skills/workflow/company-reconcile/' .
            'content-jobs-ui-reconcile/references/dynamic-skill-family-policy-v1.json';
        if (!is_file($path) || is_link($path) || filesize($path)>65536 ||
            filesize($path)<100) return [];
        $raw=file_get_contents($path);
        $p=json_decode((string)$raw,true);
        if (!is_array($p) || ($p['contract']??'')!==self::POLICY_CONTRACT ||
            empty($p['core_skills']) || empty($p['plugin_families']) ||
            empty($p['dimension_skills']) || empty($p['valid_dimensions'])) return [];
        return $p;
    }

    private static function source_plugins(int $max): array {
        $plugins=get_option('active_plugins',[]);
        if (!is_array($plugins)) return [];
        $network=is_multisite()?get_site_option('active_sitewide_plugins',[]):[];
        if (is_array($network)) $plugins=array_merge($plugins,array_keys($network));
        $plugins=array_values(array_unique(array_filter(array_map(
            static fn($v)=>is_string($v)?strtolower(trim($v)):'',
            $plugins), static fn($v)=>$v!=='' && strlen($v)<220)));
        sort($plugins,SORT_STRING);
        if (count($plugins)>$max) return [];
        return $plugins;
    }

    private static function source_theme(): string {
        $theme=get_template();
        return is_string($theme)?sanitize_key($theme):'';
    }

    /**
     * Dimensions and active plugin inventory are combined by UNION, never by
     * accepting a caller-provided replacement for the mandatory dimensions.
     * Original target runtime remains separately unverified until readback.
     */
    public static function plan(array $input=[]): array {
        if (!class_exists('MAD4B_Manager_Auto_Write_V1',false) ||
            !MAD4B_Manager_Auto_Write_V1::site() ||
            !class_exists('MAD4B_SCP_Skill_Registry',false))
            return self::blocked('source_registry_or_manager_unavailable');
        $company=strtolower(trim((string)($input['company_code']??'')));
        $environment=strtolower(trim((string)($input['environment']??'')));
        $site=(string)($input['site_uuid']??'');
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company) ||
            !in_array($environment,['production','staging'],true) ||
            !wp_is_uuid($site))
            return self::blocked('exact_company_environment_site_required');
        $target=MAD4B_Manager_Auto_Write_V1::company_target($company);
        if (($target['state']??'')!=='REGISTERED' ||
            ($target['environment']??'')!==$environment ||
            ($target['site_uuid']??'')!==$site ||
            !filter_var((string)($target['canonical_origin']??''),FILTER_VALIDATE_URL))
            return self::blocked('original_manager_target_binding_unverified',
                ['company_code'=>$company,'environment'=>$environment,'site_uuid'=>$site]);

        $policy=self::policy();
        if (!$policy) return self::blocked('source_native_dynamic_skill_policy_missing');
        $valid=(array)$policy['valid_dimensions'];
        $extra=$input['dimensions']??[];
        if (!is_array($extra) || count($extra)>count($valid) ||
            count(array_diff($extra,$valid))>0)
            return self::blocked('invalid_requested_dimensions');
        $dimensions=array_fill_keys((array)$policy['default_dimensions'],true);
        foreach($extra as $dim) $dimensions[$dim]=true;
        $plugins=self::source_plugins((int)($policy['max_active_plugins']??250));
        if (!$plugins && get_option('active_plugins',[])!==[])
            return self::blocked('active_source_plugin_inventory_unavailable');
        $plugin_sha=hash('sha256',wp_json_encode($plugins,JSON_UNESCAPED_SLASHES));
        $families=[];
        $rules=(array)$policy['plugin_families'];
        foreach($rules as $family=>$rule) {
            $triggered=false;
            foreach((array)($rule['prefixes']??[]) as $prefix) {
                foreach($plugins as $plugin) {
                    if (str_starts_with($plugin,(string)$prefix)) {
                        $triggered=true;break 2;
                    }
                }
            }
            if ($family==='astra' && self::source_theme()==='astra')
                $triggered=true;
            if (!$triggered)continue;
            $families[]=$family;
            foreach((array)($rule['dimensions']??[]) as $dim)
                if (in_array($dim,$valid,true))$dimensions[$dim]=true;
        }
        $asked=$input['impacted_families']??[];
        if(!is_array($asked)||count($asked)>24)
            return self::blocked('invalid_requested_provider_families');
        $unknown=[];
        foreach($asked as $family) {
            if(!is_string($family)||!isset($rules[$family])) {
                $unknown[]=(string)$family;continue;
            }
            if(!in_array($family,$families,true))$families[]=$family;
            foreach((array)($rules[$family]['dimensions']??[]) as $dim)
                if(in_array($dim,$valid,true))$dimensions[$dim]=true;
        }
        if($unknown) return self::blocked('impacted_provider_family_not_mapped',
            ['unmapped_impacted_families'=>array_slice($unknown,0,24)]);
        sort($families,SORT_STRING);
        $selected=[];
        $include=static function($id,$reason)use(&$selected) {
            if(!is_string($id)||!preg_match('/^(site|workflow|provider|adapter|connection):[a-z0-9._-]+:[a-z0-9-]+$/D',$id))
                return;
            if(!isset($selected[$id]))$selected[$id]=[];
            $selected[$id][]=$reason;
        };
        foreach((array)$policy['core_skills'] as $id)$include($id,'core');
        foreach(array_keys($dimensions) as $dimension)
            foreach((array)($policy['dimension_skills'][$dimension]??[]) as $id)
                $include($id,'dimension:'.$dimension);
        foreach($families as $family)
            foreach((array)($rules[$family]['skills']??[]) as $id)
                $include($id,'plugin_family:'.$family);
        if(count($selected)>($policy['max_selected_skills']??36))
            return self::blocked('selected_skill_set_exceeds_bounds');

        $registered=MAD4B_SCP_Skill_Registry::list_skills(['enabled'=>true]);
        $available=[];
        foreach($registered as $skill)
            $available[(string)$skill['logical_id']]=$skill;
        ksort($selected,SORT_STRING);
        $skills=[];$missing=[];
        foreach($selected as $id=>$reasons) {
            $present=$available[$id]??null;
            $ok=is_array($present) &&
                preg_match('/^[a-f0-9]{64}$/D',(string)($present['sha256']??''));
            if(!$ok)$missing[]=$id;
            $skills[]=['logical_id'=>$id,'required'=>true,
                'triggered_by'=>array_values(array_unique($reasons)),
                'state'=>$ok?'REGISTERED_ACTIVE':'MISSING_OR_DISABLED',
                'sha256'=>$ok?$present['sha256']:null,
                'pre_execution_evidence'=>'SKILL_REGISTRY_ONLY',
                'post_apply_provider_receipt'=>'UNTESTED'];
        }
        // External GitHub/search skills are suggestions only. They are never
        // mandatory until independently reviewed and installed in local registry.
        $suggestions=[];
        foreach ((array)($policy['external_recommendation_catalog']??[]) as $candidate) {
            $matched=array_values(array_intersect((array)($candidate['dimensions']??[]),
                array_keys($dimensions)));
            if (!$matched)continue;
            $repo=(string)($candidate['repository']??'');
            $path=(string)($candidate['path']??'');
            $sha=(string)($candidate['blob_sha']??'');
            if(!preg_match('/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+$/D',$repo) ||
                !preg_match('/^skills\/[a-z0-9-]+\/SKILL\.md$/D',$path) ||
                !preg_match('/^[a-f0-9]{40}$/D',$sha))continue;
            $suggestions[]=[
                'source'=>'github','repository'=>$repo,'path'=>$path,
                'upstream_blob_sha'=>$sha,
                'reference_url'=>(string)($candidate['source_url']??''),
                'recommended_for'=>$matched,
                'state'=>'EXTERNAL_REVIEW_REQUIRED_NOT_INSTALLED',
                'authorizes_execution'=>false,'part_of_mandatory_set'=>false,
            ];
        }
        // Optional live GitHub/tree + repository search cache; never executable.
        $cachePath=WP_CONTENT_DIR . '/mad4b-skills/workflow/company-reconcile/' .
            'content-jobs-ui-reconcile/references/external-skill-discovery-cache-v1.json';
        $discovery=['state'=>'UNAVAILABLE','candidate_count'=>0];
        if(is_file($cachePath) && !is_link($cachePath) &&
            filesize($cachePath)>0 && filesize($cachePath)<130000 &&
            time()-(int)filemtime($cachePath)<86400) {
            $cache=json_decode((string)file_get_contents($cachePath),true);
            if(is_array($cache) &&
                ($cache['contract']??'')==='mad4b.external-skill-discovery.readonly.v1' &&
                ($cache['external_skills_installed']??null)===0 &&
                ($cache['external_skill_instructions_executed']??null)===false) {
                $count=0;
                foreach((array)($cache['github_skill_candidates']??[]) as $candidate) {
                    $repo=(string)($candidate['repository']??'');
                    $path=(string)($candidate['path']??'');
                    $sha=(string)($candidate['blob_sha']??'');
                    if(!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',$repo) ||
                        !preg_match('/^skills\/[a-z0-9-]+\/SKILL\.md$/D',$path) ||
                        !preg_match('/^[a-f0-9]{40}$/D',$sha))continue;
                    $suggestions[]=[
                        'source'=>'github_discovery','repository'=>$repo,
                        'path'=>$path,'upstream_blob_sha'=>$sha,
                        'reference_url'=>(string)($candidate['url']??''),
                        'recommended_for'=>array_values(array_intersect(
                            (array)($cache['dimensions']??[]),array_keys($dimensions))),
                        'state'=>'EXTERNAL_REVIEW_REQUIRED_NOT_INSTALLED',
                        'authorizes_execution'=>false,'part_of_mandatory_set'=>false,
                    ];
                    $count++;
                    if($count>=25)break;
                }
                $discovery=[
                    'state'=>(string)($cache['state']??'UNAVAILABLE'),
                    'candidate_count'=>$count,
                    'search_repository_count'=>count((array)($cache['repository_search_candidates']??[])),
                    'source_errors'=>$cache['errors']??[],
                    'cache_last_modified_utc'=>gmdate('c',filemtime($cachePath)),
                    'non_authorizing'=>true,
                ];
            }
        }
        $policy_sha=hash('sha256',wp_json_encode($policy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $dims=array_keys($dimensions);sort($dims,SORT_STRING);
        $digest=hash('sha256',wp_json_encode([
            self::CONTRACT,$company,$environment,$site,$target['canonical_origin'],
            $policy_sha,$plugin_sha,$families,$dims,$skills,
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        return [
            'contract'=>self::CONTRACT,
            'state'=>$missing?'HOLD_REQUIRED_SKILLS_MISSING':'READY_FOR_GOVERNED_REPAIR_PLAN',
            'company_code'=>$company,'environment'=>$environment,
            'site_uuid'=>$site,'canonical_origin'=>$target['canonical_origin'],
            'policy_sha256'=>$policy_sha,'source_plugin_snapshot_sha256'=>$plugin_sha,
            'source_active_plugin_count'=>count($plugins),
            'target_plugin_inventory_verified'=>false,
            'source_inventory_is_not_target_proof'=>true,
            'required_target_probe_contract'=>'mad4b.company-reconcile.target-evidence.v1',
            'start_company_work_plan_contract'=>'mad4b.company-reconcile.work-plan.v1',
            'target_runtime_probe_verified'=>false,
            'target_probe_cannot_authorize_native_execution'=>true,
            'detected_plugin_families'=>$families,'dimensions'=>$dims,
            'required_skills'=>$skills,'required_skill_count'=>count($skills),
            'missing_required_skills'=>$missing,'skill_set_sha256'=>$digest,
            'external_skill_recommendations'=>$suggestions,
            'external_discovery_status'=>$discovery,
            'external_skill_autoinstall'=>false,
            'pre_execution_skill_selection_passed'=>!$missing,
            'repair_job_planning_allowed'=>true,
            'native_execution_authorized'=>false,
            'post_apply_acceptance'=>'UNTESTED',
            'native_provider_readback_required'=>true,
            'reconcile_closure_accepted'=>false,
            'mutation_performed'=>false,
        ];
    }

    public static function acceptance(array $input=[]): array {
        $plan=self::plan($input);
        if (($plan['pre_execution_skill_selection_passed']??false)!==true)
            return self::blocked('required_skill_selection_not_ready',
                ['skill_plan'=>$plan]);
        $expected=(string)($input['expected_skill_set_sha256']??'');
        if (!preg_match('/^[a-f0-9]{64}$/D',$expected) ||
            !hash_equals($expected,$plan['skill_set_sha256']))
            return self::blocked('skill_set_plan_sha256_missing_or_changed',
                ['skill_set_sha256'=>$plan['skill_set_sha256']]);
        // An active Skill is not its provider's signed readback receipt. Never
        // let client assertions, a Job status, HTTP 200, or source SHA alone
        // satisfy target-side interactive and locale acceptance.
        return [
            'contract'=>self::CONTRACT,
            'state'=>'UNTESTED_NATIVE_PROVIDER_RECEIPTS_REQUIRED',
            'company_code'=>$plan['company_code'],
            'environment'=>$plan['environment'],'site_uuid'=>$plan['site_uuid'],
            'skill_set_sha256'=>$plan['skill_set_sha256'],
            'required_skill_count'=>$plan['required_skill_count'],
            'unverified_skills'=>array_column($plan['required_skills'],'logical_id'),
            'target_plugin_inventory_verified'=>false,
            'native_provider_readback_required'=>true,
            'required_target_probe_contract'=>'mad4b.company-reconcile.target-evidence.v1',
            'target_runtime_probe_verified'=>false,
            'start_company_work_plan_contract'=>'mad4b.company-reconcile.work-plan.v1',
            'browser_interactions_verified'=>false,'locale_parity_verified'=>false,
            'reconcile_closure_accepted'=>false,'native_execution_authorized'=>false,
            'mutation_performed'=>false,
        ];
    }

    public static function register_abilities(): void {
        if(!function_exists('wp_register_ability'))return;
        foreach([
            'mad4b/content-job-reconcile-skill-plan'=>'plan',
            'mad4b/content-job-reconcile-skill-acceptance'=>'acceptance'
        ] as $name=>$method) {
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            $properties=[
                'company_code'=>['type'=>'string','pattern'=>'^[a-z][a-z0-9-]{2,47}$'],
                'environment'=>['type'=>'string','enum'=>['staging','production']],
                'site_uuid'=>['type'=>'string','minLength'=>36,'maxLength'=>36],
                'dimensions'=>['type'=>'array','maxItems'=>20,
                    'items'=>['type'=>'string','maxLength'=>32]],
                'impacted_families'=>['type'=>'array','maxItems'=>24,
                    'items'=>['type'=>'string','maxLength'=>80]],
            ];
            if($method==='acceptance')$properties['expected_skill_set_sha256']=[
                'type'=>'string','pattern'=>'^[a-f0-9]{64}$'];
            wp_register_ability($name,[
                'label'=>$method==='plan'
                    ?'Plan Dynamic Company Reconcile Skill Set'
                    :'Inspect Dynamic Company Reconcile Skill Acceptance',
                'description'=>'Source-native read-only ContentJob skill registry, dimensions and provider selection.',
                'category'=>'mad4b-read',
                'execute_callback'=>[__CLASS__,$method],
                'permission_callback'=>['MAD4B_SCP_Policy','can_read'],
                'input_schema'=>['type'=>'object','properties'=>$properties,
                    'required'=>$method==='plan'?
                        ['company_code','environment','site_uuid']:
                        ['company_code','environment','site_uuid','expected_skill_set_sha256'],
                    'additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['public'=>false,'show_in_rest'=>false,
                    'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                    'annotations'=>['readonly'=>true,'destructive'=>false,
                        'idempotent'=>true]],
            ]);
        }
    }
}
