<?php
/**
 * Plugin Name: MAD4B Start Company — Reconcile Completeness Projection
 * Version: 0.2.0
 * Description: Read-only, identity-bound completeness gate for the original company readiness workflow. No write or grant.
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Start_Reconcile_Completeness_V1 {
    const CONTRACT = 'mad4b.company-start-reconcile-completeness-projection.v1';
    private static function origin(): bool {
        // The central site is discovered through Core signed Site Profile and
        // host binding, not a hardcoded domain or DB name.
        return class_exists('MAD4B_Company_Portfolio_Manager_V1', false)
            && MAD4B_Company_Portfolio_Manager_V1::staging();
    }
    private static function registry(): array {
        if (!class_exists('MAD4B_Company_Environment_Registry_V2', false)) return array();
        $out = array();
        foreach (array('staging','production') as $env) {
            $out[$env] = (array) MAD4B_Company_Environment_Registry_V2::target(self::$company, $env);
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
    private static string $company = '';
    public static function project(string $company, array $report = array()): array {
        if (!self::origin() || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D', $company)) {
            return array('contract'=>self::CONTRACT,'state'=>'INVALID_SOURCE_OR_COMPANY',
                'ready_to_publish'=>false,'authority_granted'=>false);
        }
        self::$company = $company;
        $byEnv=self::registry();
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
        $reportBound=false;
        foreach ($registered as $row)
            if ($row['site_uuid']===$uuid) $reportBound=true;
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
        // The existing report represents the legacy company registered site. It may
        // NOT be inherited by a new site UUID after a fresh 313 installation.
        $stageHints = array();
        foreach ((array)($report['stages']??array()) as $name=>$row)
            if (is_array($row)) $stageHints[$name]=$row['state']??'UNKNOWN';
        $blocked = array();
        if (!$seedReady) $blocked[]='SOURCE_MASTER_SEED_ORIGINAL_RELEASE_MISSING';
        if (!$reportBound) $blocked[]='READINESS_REPORT_SITE_UUID_MISMATCH_OR_MISSING';
        // A populated settings page is not proof that the active Elementor,
        // JetEngine, WPML or WooCommerce frontend reads those fields.
        if ($presentation) $blocked[]='PRESENTATION_CANONICAL_OWNER_TARGET_READBACK_REQUIRED';
        foreach (array('company_identity','wpml_template_translation_resolution',
                       'company_options_schema_registry','runtime_dynamic_provider_coverage',
                       'jet_filter_inventory','internal_link_target_readback') as $requiredName) {
            if (($stageHints[$requiredName]??'UNKNOWN')!=='PASS')
                $blocked[]='EXISTING_STAGE_NOT_PASS:'.$requiredName;
        }
        foreach (array('bootstrap','functional','presentation') as $stage) {
            foreach ((array)($required[$stage]??array()) as $check)
                $blocked[]='INDEPENDENT_READBACK_PENDING:'.$check;
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
            'status_is_non_authorizing'=>true,
            'manifest_bound_readbacks_present'=>false,
            'seed_source_readiness'=>$seedSummary,
            'elementor_visual_css_source'=>self::css_source_evidence(),
            'presentation_option_ownership'=>$presentation,
            'presentation_option_contract_included'=>!empty($presentation),
            'presentation_aliases_do_not_claim_runtime_binding'=>true,
            'check_count'=>array_sum(array_map('count',$required)),
            'unverified_checks'=>array_values(array_unique($blocked)),
            'next_action'=>'COLLECT_EXACT_SITE_ORIGINAL_PROVIDER_RECEIPTS_AND_BROWSER_READBACK',
            'authority_granted'=>false,
            'mutation_performed'=>false,
            'source_host'=>'tours.dev.mad4b.com',
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
