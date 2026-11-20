<?php
/**
 * Plugin Name: MAD4B Start Company — Reconcile Completeness Projection
 * Version: 0.2.0
 * Description: Read-only, identity-bound completeness gate for the original company readiness workflow. No write or grant.
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-mad4b-company-reconcile-work-plan.php';
require_once __DIR__ . '/class-mad4b-company-reconcile-operation-readiness.php';
MAD4B_Company_Reconcile_Work_Plan_V1::boot();

final class MAD4B_Company_Start_Reconcile_Completeness_V1 {
    const CONTRACT = 'mad4b.company-start-reconcile-completeness-projection.v1';
    private static function origin(): bool {
        // The central site is discovered through Core signed Site Profile and
        // host binding, not a hardcoded domain or DB name.
        return class_exists('MAD4B_Company_Portfolio_Manager_V1', false)
            && MAD4B_Company_Portfolio_Manager_V1::staging();
    }
    private static function registry(string $company): array {
        if (!class_exists('MAD4B_Company_Environment_Registry_V2', false)) return array();
        $out = array();
        foreach (array('staging','production') as $env) {
            $target=MAD4B_Company_Environment_Registry_V2::target($company,$env);
            if(is_array($target))$out[$env]=$target;
        }
        return $out;
    }
    private static function css_source_evidence(): array {
        // Read-only evidence from the live Elementor source. Never pass stale
        // compiled CSS as a portable company-clone asset.
        $id=(int)get_option('elementor_active_kit');
        $settings=$id?get_post_meta($id,'_elementor_page_settings',true):null;
        $custom=is_array($settings)?(string)($settings['custom_css']??''):'';
        $meta=$id?get_post_meta($id,'_elementor_css',true):null;
        $generated=is_array($meta)?(string)($meta['css']??''):'';
        return array(
            'contract'=>'mad4b.elementor-css-source.v1',
            'source_kind'=>'live_elementor_site_kit',
            'original_kit_id'=>$id,
            'visual_custom_css_present'=>strlen($custom)>0,
            'visual_custom_css_bytes'=>strlen($custom),
            'visual_custom_css_sha256'=>$custom!==''?hash('sha256',$custom):null,
            'compiled_css_bytes'=>strlen($generated),
            'compiled_css_is_portable'=>false,
            'target_must_regenerate_using_elementor'=>true,
            'source_ready_for_transport'=>$id>0 && is_array($settings) && $custom!=='',
            'target_readback_verified'=>false,
            'style_parity_verified_for_target'=>false,
            'source_requires_identity_remap'=>true,
        );
    }
    public static function project(string $company, array $report = array()): array {
        if (!self::origin() || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D', $company)) {
            return array('contract'=>self::CONTRACT,'state'=>'INVALID_SOURCE_OR_COMPANY',
                'ready_to_publish'=>false,'authority_granted'=>false);
        }
        $byEnv=self::registry($company);
        $registered = array();
        foreach ($byEnv as $env=>$row) {
            if (($row['company_id']??'')!==$company || !wp_is_uuid((string)($row['site_uuid']??''))) continue;
            $registered[$env]=array(
                'company_id'=>$company,'environment'=>$env,
                'site_uuid'=>(string)$row['site_uuid'],
                'canonical_origin'=>(string)($row['canonical_origin']??''),
                'identity_binding_present'=>true);
        }
        $uuid=(string)($report['site_uuid']??'');
        // Legacy reports must bind to environment+HTTPS origin+UUID, not any
        // Site UUID in another environment of the same company. An unsigned
        // stage hint is not an independent receipt regardless of binding.
        $reportBound=false;
        $presentation=array();
        if (class_exists('MAD4B_Company_Presentation_Option_Ownership_V1',false)) {
            foreach(array('staging','production') as $scopedEnvironment) {
                if(!isset($registered[$scopedEnvironment]))continue;
                $project=MAD4B_Company_Presentation_Option_Ownership_V1::plan($company,$scopedEnvironment);
                $presentation[$scopedEnvironment]=is_wp_error($project)
                    ? array('state'=>$project->get_error_code(),'target_readback_verified'=>false)
                    : $project;
            }
        }
        // Keep topology evidence as an independent readiness dimension. A
        // signed 313 WordPress bootstrap or one MCP node cannot imply fleet
        // convergence; UNKNOWN is not a reason to revoke existing read lanes.
        $topology=array();
        if(class_exists('MAD4B_Company_Host_Topology_Registry_V1',false)){
            foreach(array_keys($registered) as $env)
                $topology[$env]=MAD4B_Company_Host_Topology_Registry_V1::discover($company,$env);
        }
        $seed = class_exists('MAD4B_Company_Seed_Admission_V1', false)
            ? MAD4B_Company_Seed_Admission_V1::status() : array();
        $seedReady = is_array($seed) && ($seed['seed_complete']??false) === true;
        $candidate = (array)($seed['inventory']??array());
        $seedSummary = array(
            'original_sealed_seed_verified'=>$seedReady,
            'source_candidate_available'=>preg_match('/^[a-f0-9]{64}$/D',
                (string)($candidate['candidate_archive_sha256']??''))===1,
            'source_candidate_sha256'=>$candidate['candidate_archive_sha256']??null,
            'live_source_component_count'=>(int)($candidate['classification_coverage']??0),
            'provider_review_candidates'=>(int)($candidate['review_required_count']??0),
            'original_seed_blocker'=>$seedReady?null:($seed['reason']??'ORIGINAL_SEED_PROVIDER_NOT_READY'),
            'source_candidate_is_authority'=>false,
        );
        $policyFile=WPMU_PLUGIN_DIR.'/company-start-reconcile/policy.json';
        $policy=is_readable($policyFile)?json_decode((string)file_get_contents($policyFile),true):null;
        $required=is_array($policy)?(array)($policy['stages']??array()):array();
        $workPlan = MAD4B_Company_Reconcile_Work_Plan_V1::build($company,$registered,is_array($policy)?$policy:[], $report);
        $operationLinks=array();
        foreach($registered as $environment=>$siteRecord){
            if(!in_array($environment,array('staging','production'),true)||
               !wp_is_uuid((string)($siteRecord['site_uuid']??'')))continue;
            $operationLinks[$environment]=add_query_arg(array(
                'environment'=>$environment,
                'expected_site_uuid'=>$siteRecord['site_uuid'],
            ),rest_url('mad4b/v1/company-brand-readiness/companies/'.$company.'/reconcile-operations'));
        }
        $reportBound=($workPlan['legacy_report_bound_to_target']??false)===true;
        // The existing report represents the legacy company registered site. It may
        // NOT be inherited by a new site UUID after a fresh 313 installation.
        $stageHints = array();
        foreach ((array)($report['stages']??array()) as $name=>$row)
            if (is_array($row)) $stageHints[$name]=$row['state']??'UNKNOWN';
        $blocked = array();
        if (!$seedReady) $blocked[]='SOURCE_MASTER_SEED_ORIGINAL_RELEASE_MISSING';
        if (!$reportBound) $blocked[]='READINESS_REPORT_ENV_ORIGIN_UUID_MISMATCH_OR_MISSING';
        if(!empty($workPlan['invalid_environment_records']))
            $blocked[]='ENVIRONMENT_REGISTRY_IDENTITY_INCONSISTENT';
        // A populated settings page is not proof that the active Elementor,
        // JetEngine, WPML or WooCommerce frontend reads those fields.
        if ($presentation) $blocked[]='PRESENTATION_CANONICAL_OWNER_TARGET_READBACK_REQUIRED';
        foreach (array('company_identity','wpml_template_translation_resolution',
                       'company_options_schema_registry','runtime_dynamic_provider_coverage',
                       'jet_filter_inventory','internal_link_target_readback') as $requiredName) {
            if (($stageHints[$requiredName]??'UNKNOWN')!=='PASS')
                $blocked[]='EXISTING_STAGE_NOT_PASS:'.$requiredName;
        }
        foreach (array('bootstrap','functional','presentation','commercial') as $stage) {
            foreach ((array)($required[$stage]??array()) as $check)
                $blocked[]='INDEPENDENT_READBACK_PENDING:'.$check;
        }
        // Dynamic schema candidates come from the existing Field Fusion owner;
        // target source-of-truth / company overrides still require 313 readback.
        $sourceSchemas=class_exists('MAD4B_Hub_Field_Fusion_V1',false)
            ? MAD4B_Hub_Field_Fusion_V1::reusable_jetengine_definition_manifest('tours-and-activities')
            : ['state'=>'FIELD_FUSION_OWNER_UNAVAILABLE','auto_apply'=>false];
        $schemaAlerts=array();
        // This is a reconciliation ALERT, not an assertion that the target
        // schema is missing. Exact target inventory and 313 are mandatory.
        foreach ((array)($sourceSchemas['definitions']??array()) as $definition) {
            if (!in_array(($definition['scope']??''),
                ['EXPLICIT_POST_TYPE','EXPLICIT_RELATED_POST_TYPE','EXPLICIT_TAXONOMY'],true))continue;
            $boxId=(string)($definition['id']??'');
            $sha=(string)($definition['definition_sha256']??'');
            if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{1,95}$/D',$boxId)
                || !preg_match('/^[a-f0-9]{64}$/D',$sha))continue;
            $schemaAlerts[]=array(
                'code'=>'JETENGINE_TARGET_SCHEMA_READBACK_REQUIRED',
                'severity'=>'warning','owner'=>'MAD4B_Hub_Field_Fusion_V1',
                'post_type'=>'tours-and-activities',
                'schema_scope'=>$definition['scope'],
                'object_type'=>$definition['object_type']??'post',
                'applicable_post_types'=>$definition['post_types']??[],
                'applicable_taxonomies'=>$definition['taxonomies']??[],
                'source_definition_id'=>$boxId,
                'source_definition_sha256'=>$sha,
                'source_semantic_sha256'=>$definition['semantic_sha256']??null,
                'target_state'=>'UNVERIFIED',
                'required_lane'=>'Company Manager → Native 305/312/313',
                'content_job_handoff_state'=>'REQUIRES_NATIVE_ADAPTER',
                'auto_apply'=>false,'company_values_preserved'=>true,
            );
            $blocked[]='JETENGINE_TARGET_SCHEMA_READBACK_REQUIRED:'.$boxId;
        }
        // Policy file and this endpoint are not signed provider receipts. Even if
        // all old stage hints are PASS, publication requires fresh original provider
        // receipts bound to exact site+manifest+profile revision and browser evidence.
        return array(
            'contract'=>self::CONTRACT,
            'company_id'=>$company,
            'registered_targets'=>$registered,
            'readiness_receipt_site_uuid'=>$uuid ?: null,
            'legacy_report_bound_to_registered_target'=>$reportBound,
            'legacy_report_result'=>$report['result']??null,
            'state'=>'RECONCILE_EVIDENCE_REQUIRED',
            'ready_to_publish'=>false, 'commercial_ready'=>false,
            'commercial_stage_requires_independent_readback'=>true,
            'commerce_only_gaps_have_independent_publication_state'=>true,
            'status_is_non_authorizing'=>true,
            'manifest_bound_readbacks_present'=>false,
            'seed_source_readiness'=>$seedSummary,
            'elementor_visual_css_source'=>self::css_source_evidence(),
            'source_field_fusion_definition_inventory'=>$sourceSchemas,
            'schema_reconciliation_alerts'=>$schemaAlerts,
            'host_topology_readback'=>$topology,
            'host_topology_full_deployment_verified'=>false,
            'all_required_node_receipts_verified'=>false,
            'host_topology_not_a_write_gate_for_existing_lanes'=>true,
            'presentation_option_ownership'=>$presentation,
            'presentation_option_contract_included'=>!empty($presentation),
            'presentation_aliases_do_not_claim_runtime_binding'=>true,
            'check_count'=>array_sum(array_map('count',$required)),
            'company_reconcile_work_plan'=>$workPlan,
            'operation_readiness_on_demand'=>true,
            'operation_readiness_contract'=>'mad4b.company-reconcile.operation-readiness.v1',
            'operation_readiness_path'=>'/wp-json/mad4b/v1/company-brand-readiness/companies/'.$company.'/reconcile-operations',
            'operation_readiness_links_by_environment'=>$operationLinks,
            'reconcile_runtime_observed'=>$workPlan['observed_runtime']['state']??'NOT_OBSERVED',
            'reconcile_next_action'=>$workPlan['next_action']??'COLLECT_EXACT_TARGET_READBACK',
            'reconcile_environment_scope'=>$workPlan['environment_inventory']??[],
            'unverified_check_count'=>count(array_unique($blocked)),
            'target_local_probe_contract'=>'mad4b.company-reconcile.target-evidence.v1',
            'target_probe_not_authoritative_without_original_readback'=>true,
            'unverified_checks'=>array_values(array_unique($blocked)),
            'next_action'=>$workPlan['next_action']??'COLLECT_EXACT_SITE_ORIGINAL_PROVIDER_RECEIPTS_AND_BROWSER_READBACK',
            'authority_granted'=>false,
            'mutation_performed'=>false,
            'source_host'=>(string)wp_parse_url(home_url(),PHP_URL_HOST),
        );
    }
    public static function routes(): void {
        if (!self::origin()) return;
        register_rest_route('mad4b/v1',
            '/company-brand-readiness/companies/(?P<company>[a-z][a-z0-9-]{2,47})/reconcile-completeness',
            array('methods'=>'GET','callback'=>array(__CLASS__,'rest'),
                'permission_callback'=>array('MAD4B_Company_Readiness_Governance','guard')));
    }
    public static function rest($request) {
        if (!class_exists('MAD4B_Company_Readiness_Governance',false))
            return new WP_Error('mad4b_start_reconcile_unavailable','Original readiness provider missing',array('status'=>503));
        $company=sanitize_key((string)$request->get_param('company'));
        $report=(array)get_option(MAD4B_Company_Readiness_Governance::STORE,array());
        $response=rest_ensure_response(self::project($company,(array)($report[$company]??array())));
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
    public static function decorate($response,$server,$request) {
        if (!self::origin() || !($request instanceof WP_REST_Request)
            || !($response instanceof WP_REST_Response)
            || is_wp_error($response)) return $response;
        $route=$request->get_route();
        if (!preg_match('~^/mad4b/v1/company-brand-readiness/companies/([a-z][a-z0-9-]{2,47})/workflow$~D', $route,$m))
            return $response;
        $data=$response->get_data();
        if (!is_array($data))return $response;
        $data['start_company_reconcile_completeness']=self::project($m[1],$data);
        // Do not change existing rollout approval semantics; provide an
        // additional publication readiness dimension that fails closed.
        $data['complete_site_publication_verified']=false;
        $response->set_data($data);
        return $response;
    }
}
add_action('rest_api_init',array('MAD4B_Company_Start_Reconcile_Completeness_V1','routes'),95);
add_filter('rest_request_after_callbacks',array('MAD4B_Company_Start_Reconcile_Completeness_V1','decorate'),50,3);
add_filter('rest_post_dispatch',array('MAD4B_Company_Start_Reconcile_Completeness_V1','decorate'),50,3);
