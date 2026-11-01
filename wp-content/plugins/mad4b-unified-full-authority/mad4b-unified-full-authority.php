<?php
/**
 * Plugin Name: MAD4B Unified Full Authority
 * Description: Environment-neutral Full Authority discovery, planning, and native owner handoff for Staging and Production.
 * Version: 0.1.3
 * Author: MAD4B
 * Text Domain: mad4b-unified-full-authority
 */
defined('ABSPATH') || exit;

/**
 * One contract and one logical principal across environments. This plugin
 * does not replace native environment owners, mint grants, issue tickets, or
 * elevate WordPress identities. Production is supported as a governed mode,
 * not a staging bypass or an unattended grant escalation.
 */
final class MAD4B_Unified_Full_Authority {
    const CONTRACT='mad4b.unified-full-authority.v1';
    const PRODUCTION_OWNER='MAD4B_SCP_Production_Full_Authority';
    const CENTRAL_STAGING_ORIGIN='https://tours.dev.mad4b.com';

    public static function boot() {
        if(!function_exists('add_action'))return;
        add_action('admin_init',array(__CLASS__,'register'),20);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('mad4b full-authority-unified',array(__CLASS__,'cli_status'));
            WP_CLI::add_command('mad4b full-authority-lifecycle',array(__CLASS__,'cli_lifecycle'));
        }
    }

    public static function plan() {
        // The canonical environment policy is shipped with Control Plane
        // but is not always loaded by its optional bootstrap. Load only
        // that exact, trusted local class file; never synthesize a policy.
        if (!class_exists('MAD4B_SCP_Environment_Convergence_Policy')
            && defined('WP_PLUGIN_DIR')) {
            $policy_file = WP_PLUGIN_DIR . '/mad4b-site-control-plane/includes/class-mad4b-scp-environment-convergence-policy.php';
            if (is_file($policy_file)) require_once $policy_file;
        }
        if(!class_exists('MAD4B_SCP_Execution_Authority')
            ||!class_exists('MAD4B_SCP_Environment_Convergence_Policy'))
            return new WP_Error('mad4b_full_authority_dependency_unavailable','Canonical identity and environment policy required.');
        $actor=MAD4B_SCP_Execution_Authority::require_access('plan',array('staging','production'));
        if(is_wp_error($actor))return $actor;
        $policy=MAD4B_SCP_Environment_Convergence_Policy::current();
        if(!is_array($policy)||empty($policy['environment_authority_verified'])
            || ($policy['environment']??'')!==$actor['environment'])
            return new WP_Error('mad4b_full_authority_environment_unverified','Exact WordPress, Site Profile, and deployment binding must agree.');
        $env=$actor['environment'];
        $owner=$env==='staging'?'MAD4B_SCP_Full_Staging_Authority':self::PRODUCTION_OWNER;
        $owner_ready=class_exists($owner) && method_exists($owner,'plan')
            && method_exists($owner,'apply') && method_exists($owner,'can_apply');
        $native=null;$native_error='';
        if($owner_ready) {
            $native=$owner::plan();
            if(is_wp_error($native)){$native_error=$native->get_error_code();$native=null;}
        }
        $runtime=class_exists('MAD4B_SCP_Live_Acceptance_Observer')
            ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : null;
        $build_ready=is_array($runtime)&&!empty($runtime['manifest_valid'])
            &&!empty($runtime['runtime_manifest_match'])
            &&empty($runtime['stale'])&&empty($runtime['provenance_mismatch']);
        $strict=$env==='production';
        $blockers=array();
        if(!$build_ready)$blockers[]='exact_build_provenance_unverified';
        if(!$owner_ready)$blockers[]='native_environment_full_authority_executor_missing';
        if($native_error!=='')$blockers[]='native_owner_plan_'.$native_error;
        if($native!==null && empty($native['ready_to_apply']))
            $blockers[]='native_owner_plan_not_ready';
        if($strict) {
            if(empty($policy['production_strict']))$blockers[]='production_risk_policy_not_strict';
            // The environment native owner is responsible for independent
            // owner/organization approval, exact grants, backup/recovery,
            // rollout window, audit, and readback. None may be assumed.
            if($native===null || empty($native['production_authorization_verified']))
                $blockers[]='production_native_owner_authorization_unverified';
        }
        $blockers=array_values(array_unique($blockers));
        $body=array(
            'contract'=>self::CONTRACT,
            'site_uuid'=>(string)$actor['site_uuid'],
            'environment'=>$env,'mode'=>$strict?'production_governed':'staging_auto_reconcile',
            'production_supported'=>true,'production_strict'=>$strict,
            'canonical_principal'=>$actor['canonical_principal'],
            'transport'=>$actor['transport'],
            'source_commit_sha'=>(string)($runtime['source_commit_sha']??''),
            'build_fingerprint'=>(string)($runtime['build_fingerprint']??''),
            'environment_policy_contract'=>(string)($policy['contract']??''),
            'native_owner'=>$owner,
            'native_plan_sha256'=>(string)($native['plan_sha256']??''),
            'native_owner_ready'=>$owner_ready,
            'ready_to_apply'=>!$blockers,
            'blockers'=>$blockers,
            'requires_native_exact_grants'=>true,
            'requires_native_owner_approval'=>true,
            'requires_independent_production_readback'=>$strict,
            'production_mutation_performed'=>false,
            'mutation_performed'=>false,
        );
        $body['plan_sha256']=hash('sha256',wp_json_encode($body,JSON_UNESCAPED_SLASHES));
        return $body;
    }

    /**
     * Never construct a production approval, mutate via Staging owner,
     * substitute CLI --user for a principal, or accept a stale plan.
     * The original native owner must independently validate its own exact
     * approval, ticket and execution context.
     */
    public static function apply($input) {
        if(!is_array($input) || empty($input['expected_plan_sha256'])
            || empty($input['native_approved_input'])||!is_array($input['native_approved_input']))
            return new WP_Error('mad4b_full_authority_exact_input_required','Exact plan and native owner approved input are required.');
        $plan=self::plan();
        if(is_wp_error($plan))return $plan;
        if(!hash_equals((string)$plan['plan_sha256'],(string)$input['expected_plan_sha256']))
            return new WP_Error('mad4b_full_authority_plan_changed','Full Authority plan changed.');
        if(empty($plan['ready_to_apply']))
            return new WP_Error('mad4b_full_authority_not_ready','Native environment authority is not ready.',
                array('blockers'=>$plan['blockers']));
        $actor=MAD4B_SCP_Execution_Authority::require_access('apply',array('staging','production'));
        if(is_wp_error($actor))return $actor;
        if(!hash_equals((string)$plan['canonical_principal'],(string)$actor['canonical_principal'])
            || $plan['environment']!==$actor['environment'])
            return new WP_Error('mad4b_full_authority_actor_changed','Exact operator or environment changed.');
        $owner=$plan['native_owner'];
        if(!class_exists($owner) || !method_exists($owner,'can_apply')
            || !method_exists($owner,'apply'))
            return new WP_Error('mad4b_full_authority_native_owner_missing','Exact native owner unavailable.');
        $access=$owner::can_apply($input['native_approved_input']);
        if(is_wp_error($access)||$access!==true)
            return is_wp_error($access)?$access:new WP_Error('mad4b_full_authority_native_access_denied','Native owner refused execution.');
        // The native owner, not this generic router, is responsible for all
        // execution state transitions, audit, rollback and postconditions.
        return $owner::apply($input['native_approved_input']);
    }
    /** A read-only projection of the existing authority lifecycle. */
    public static function lifecycle_status() {
        $plan=self::plan();
        if(is_wp_error($plan))return $plan;
        $env=$plan['environment'];
        $source=class_exists('MAD4B_SCP_Live_Acceptance_Observer')
            ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
        $source_ok=is_array($source)&&!empty($source['manifest_valid'])
            &&!empty($source['runtime_manifest_match'])&&empty($source['stale']);
        $write=array();
        if('staging'===$env && class_exists('MAD4B_SCP_Staging_Write_Authority')) {
            $write=MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
            if(is_wp_error($write))$write=array();
        }
        $missing=(int)($write['exact_grants_missing_count']??0);
        $stale=(int)($write['stale_allow_grants_count']??0)
            +(int)($write['unreviewed_stale_allow_grants_count']??0);
        $subjects=(array)($write['subject_preflight_blockers']??array());
        $candidate=!empty($write['candidate_binding']['match']);
        $reconciled='staging'===$env && $source_ok && !$missing && !$stale
            && !$subjects && $candidate && !empty($write['effective_ready']);
        $phases=array(
            'resolve'=>'ready',
            'source'=>$source_ok?'ready':'blocked',
            'reconcile'=>!$source_ok?'waiting_source':($reconciled?'ready':'blocked'),
            'authorize'=>!$reconciled?'waiting_reconciliation':(
                !empty($plan['ready_to_apply'])?'ready':'blocked'),
            'execute'=>'not_executed',
            'verify'=>'not_executed',
            'resume'=>'not_executed',
        );
        if('production'===$env) {
            $phases['reconcile']=$source_ok?'awaiting_production_policy':'waiting_source';
            $phases['authorize']='awaiting_native_production_authorization';
        }
        return array(
            'contract'=>'mad4b.unified-authority-lifecycle-status.v1',
            'environment'=>$env,'site_uuid'=>$plan['site_uuid'],
            'source_sha'=>$plan['source_commit_sha'],
            'rollout_topology'=>array(
                'contract'=>'mad4b.central-staging-company-rollout.v1',
                'central_staging_origin'=>self::CENTRAL_STAGING_ORIGIN,
                'per_company_staging_required'=>false,
                'company_target_environment'=>'production',
                'central_release_verified'=>false,
                'company_compatibility_verified'=>false,
                'company_production_authorized'=>false,
                'note'=>'Central Staging qualifies the shared artifact; the company target still requires exact release readback, compatibility, scoped authorization, rollback and verification.'
            ),
            'plan_sha256'=>$plan['plan_sha256'],
            'phases'=>$phases,
            'source_mismatch'=>array_slice((array)($source['provenance_mismatch']??array()),0,12),
            'missing_exact_grants'=>$env==='staging'?$missing:null,
            'stale_write_grants'=>$env==='staging'?$stale:null,
            'subject_conflicts'=>$env==='staging'?$subjects:array(),
            'candidate_binding_match'=>$env==='staging'?$candidate:null,
            'native_backend_available'=>!empty($plan['native_owner_ready']),
            'ready_to_apply'=>!empty($plan['ready_to_apply']),
            'next_phase'=>!$source_ok?'source':(!$reconciled?'reconcile':'authorize'),
            'mutation_performed'=>false,
            'production_mutation_performed'=>false,
        );
    }

    public static function cli_lifecycle($args,$flags) {
        if($args||$flags)WP_CLI::error('No caller-selected site or actor.');
        $p=self::lifecycle_status();
        if(is_wp_error($p))WP_CLI::error($p->get_error_code());
        WP_CLI::line(wp_json_encode($p,JSON_UNESCAPED_SLASHES));
    }

    public static function cli_status($args,$flags) {
        if($args||$flags)WP_CLI::error('No site or principal overrides are accepted.');
        $p=self::plan();
        if(is_wp_error($p))WP_CLI::error($p->get_error_code());
        WP_CLI::line(wp_json_encode($p,JSON_UNESCAPED_SLASHES));
    }
    public static function register() {
        // Runtime engine owns auth/ability registration. This plugin exposes
        // CLI/read-only routing without remote mutable endpoints.
    }
}
add_action('plugins_loaded',array('MAD4B_Unified_Full_Authority','boot'),25);
