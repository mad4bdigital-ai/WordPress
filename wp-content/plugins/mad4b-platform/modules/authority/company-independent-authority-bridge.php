<?php
/**
 * MAD4B Independent Operational Authority — read-only tenant projection.
 *
 * The VPS Authority DB, not GitHub or WordPress, is the policy source.
 * This component CANNOT issue/verify an operational permit or certificate,
 * cannot select an alternative mode, and never grants a deployment.
 */
defined('ABSPATH') || exit;

final class MAD4B_Independent_Authority_Bridge {
    const CONTRACT='mad4b.independent-authority.control-plane-bridge.v1';
    const SOURCE_CONTRACT='mad4b.independent-authority.status-projection.v1';
    const SNAPSHOT='/run/mad4b-independent-authority/authority-status.json';
    const MAX_AGE=600;
    const NS='mad4b/v1';

    public static function boot() {
        add_action('rest_api_init',[__CLASS__,'routes'],35);
        add_action('wp_abilities_api_init',[__CLASS__,'register_ability'],40);
    }

    public static function permitted($request=null) {
        if (wp_get_environment_type()!=='staging')
            return new WP_Error('mad4b_authority_environment_denied',
                'Authority readback is available on Staging only.', ['status'=>403]);
        if (!is_user_logged_in())
            return new WP_Error('mad4b_authority_auth_required',
                'Authentication required.', ['status'=>401]);
        if (!current_user_can('manage_options') ||
            !class_exists('MAD4B_SCP_Policy') ||
            !MAD4B_SCP_Policy::can_read())
            return new WP_Error('mad4b_authority_read_denied',
                'Company administrator and Control Plane read authorization required.',
                ['status'=>403]);
        return true;
    }

    public static function routes() {
        foreach (['/operational-authority/status'=>'status',
                  '/operational-authority/release-readiness'=>'readiness'] as $path=>$method) {
            register_rest_route(self::NS,$path,[
                'methods'=>WP_REST_Server::READABLE,
                'permission_callback'=>[__CLASS__,'permitted'],
                'callback'=>[__CLASS__,$method],
                'args'=>[],
            ]);
        }
    }

    public static function register_ability() {
        if (!function_exists('wp_register_ability')) return;
        if (function_exists('wp_has_ability') &&
            wp_has_ability('mad4b/independent-authority-status')) return;
        wp_register_ability('mad4b/independent-authority-status',[
            'label'=>'Independent Authority operational-readiness status',
            'description'=>'Read-only current Staging Authority status; no permit or completion certificate can be issued.',
            'category'=>'mad4b-read',
            'permission_callback'=>[__CLASS__,'permitted'],
            'execute_callback'=>[__CLASS__,'observe'],
            'input_schema'=>['type'=>'object','additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>[
                'public'=>false,'show_in_rest'=>false,
                'mcp'=>['public'=>false,'surface'=>'read','type'=>'tool'],
                'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true],
            ],
        ]);
    }

    private static function blocked($reason,$fields=[]) {
        return array_merge([
            'contract'=>self::CONTRACT,
            'site_host'=>(string)wp_parse_url(home_url('/'),PHP_URL_HOST),
            'environment'=>wp_get_environment_type(),
            'preferred_authority_mode'=>'independent_authority',
            'authority_mode_verified'=>false,
            'authority_status'=>'BLOCKED',
            'reason'=>$reason,
            'authoritative_operational_approval'=>false,
            'release_permitted'=>false,
            'completion_certificate_authorized'=>false,
            'github_fallback_allowed'=>false,
            'approval_or_permit_issued'=>false,
            'mutation_performed'=>false,
            'source'=>'central_authority_staging_database',
            'projection_trusted_for_authorization'=>false,
            'global_wordpress_mutation_gate_modified'=>false,
            'sensitive_release_mutators_require_separate_permit_gate'=>true,
        ],$fields);
    }

    public static function evaluate($data,$now=null) {
        if (!is_array($data)) return self::blocked('projection_missing_or_invalid');
        $expectedHost=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
        if (($data['contract']??null)!==self::SOURCE_CONTRACT ||
            ($data['source']??null)!=='central_authority_staging_database' ||
            ($data['target_environment']??null)!=='staging' ||
            ($data['target_site_host']??null)!==$expectedHost ||
            ($data['non_authorizing_readback_only']??false)!==true ||
            ($data['operational_authorization']??true)!==false ||
            ($data['completion_certificate_authorized']??true)!==false ||
            ($data['approval_or_permit_issued']??true)!==false) {
            return self::blocked('projection_scope_or_contract_invalid');
        }
        $stamp=(string)($data['observed_at_utc']??'');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$stamp))
            return self::blocked('projection_timestamp_invalid');
        $timestamp=strtotime($stamp);
        $reference=$now??time();
        if (!$timestamp || $timestamp>$reference+30 || $reference-$timestamp>self::MAX_AGE)
            return self::blocked('projection_stale');
        $counts=[];
        foreach (['actual_permit_count','actual_event_count','mfa_principal_count',
                  'operational_ca_count','diagnostic_qa_runner_count'] as $field) {
            $value=$data[$field]??null;
            if ($value!==null && (!is_int($value)||$value<0))
                return self::blocked('projection_counter_invalid');
            $counts[$field]=$value;
        }
        $safeMode=($data['selected_mode']??null)==='independent_authority' &&
                  ($data['automatic_fallback']??true)===false;
        $dbHealthy=($data['database_health']??null)==='healthy';
        $report=self::blocked('independent_operational_issuance_not_certified',[
            'observed_at_utc'=>$stamp,
            'selected_mode'=>is_string($data['selected_mode']??null)?$data['selected_mode']:'unknown',
            'authority_mode_verified'=>$safeMode && $dbHealthy,
            'database_health'=>$data['database_health']??'unknown',
            'issuer_state'=>($data['authority_status']??null)==='BLOCKED'?'BLOCKED':'UNKNOWN',
            'blocking_reason'=>is_string($data['reason']??null)?$data['reason']:'unknown',
            'counts'=>$counts,
        ]);
        if (!$safeMode) $report['reason']='authority_mode_or_fallback_invalid';
        elseif (!$dbHealthy) $report['reason']='central_authority_database_unhealthy';
        elseif (($data['authority_status']??null)!=='BLOCKED')
            $report['reason']='authority_projection_unexpected_status';
        elseif (($data['operational_issuance_enabled']??true)!==false)
            $report['reason']='issuance_enabled_requires_live_certification';
        return $report;
    }

    public static function observe($input=[]) {
        if (wp_get_environment_type()!=='staging')
            return self::blocked('environment_not_staging');
        $path=self::SNAPSHOT;
        if (!is_file($path) || is_link($path) || !is_readable($path) ||
            filesize($path)>8192)
            return self::blocked('projection_unavailable');
        $json=json_decode((string)@file_get_contents($path),true);
        return self::evaluate($json);
    }

    private static function deliver($value) {
        $response=rest_ensure_response($value);
        $response->header('Cache-Control','private, no-store, no-cache, must-revalidate');
        $response->header('Pragma','no-cache');
        $response->header('Vary','Authorization, Cookie');
        $response->header('X-Content-Type-Options','nosniff');
        return $response;
    }

    public static function status($request) {
        return self::deliver(self::observe([]));
    }

    public static function readiness($request) {
        $s=self::observe([]);
        return self::deliver([
            'contract'=>self::CONTRACT,
            'site_host'=>$s['site_host'],
            'environment'=>$s['environment'],
            'selected_mode'=>$s['selected_mode']??'independent_authority',
            'decision'=>'BLOCKED',
            'reason'=>$s['reason'],
            'blocking_reason'=>$s['blocking_reason']??$s['reason'],
            'required_independent_evidence'=>[
                'real_owner_and_platform_owner_mfa',
                'operational_ca_key_custody',
                'independently_signed_qa_and_runtime_evidence',
                'exact_artifact_and_target_site_uuid_binding',
                'atomic_one_time_permit_and_revocation',
                'independent_completion_certificate_after_runtime_readback',
            ],
            'github_governed_automatic_fallback'=>false,
            'authorization_executed'=>false,
            'permit_issued'=>false,
            'certificate_issued'=>false,
            'mutation_performed'=>false,
            'global_wordpress_mutation_gate_modified'=>false,
            'release_controller_permit_enforcement_installed'=>false,
        ]);
    }
}
MAD4B_Independent_Authority_Bridge::boot();
