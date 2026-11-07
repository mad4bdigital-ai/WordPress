<?php
/**
 * Tenant-neutral WP-CLI Execution Context.
 * Identity is derived from the site's enrolled WordPress Site Profile and
 * deployment binding; optional host assertions, when supplied, MUST match.
 * Low-impact built-in local operations share one policy across environments.
 * Every other mutation remains on its existing dedicated authority path.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_CLI_Execution_Context {
    const CONTRACT = 'mad4b.cli-execution-context.v1';
    private static $booted = false;

    public static function boot() {
        if (self::$booted || !defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) return;
        self::$booted = true;
        WP_CLI::add_command('mad4b context inspect', array(__CLASS__, 'inspect_command'));
        WP_CLI::add_command('mad4b context check', array(__CLASS__, 'check_command'));
    }

    /**
     * A single, exact-operation policy for local scheduled maintenance.
     * This is NOT a global mutation grant, caller-defined capability, or
     * alternative OAuth/remote-client authorization path.
     *
     * Adding operations requires a code change and independent validation;
     * neither environment variables nor WP options can add new operation IDs.
     */
    private static function local_operation_permitted($operation, $environment) {
        if ($operation !== 'content_readiness.scheduled_scan') return false;
        if (!in_array($environment, array('staging','production','development','local'), true)) return false;
        if (!class_exists('MAD4B_SCP_Content_Readiness_Alerts')) return false;
        $rules = MAD4B_SCP_Content_Readiness_Alerts::rules();
        return is_array($rules) && !empty($rules['scheduled_scan_enabled']);
    }

    public static function resolve($operation='runtime.status.read', $is_mutation=false) {
        $site = class_exists('MAD4B_SCP_Site_Profile')
            ? MAD4B_SCP_Site_Profile::status() : array();
        $audit = class_exists('MAD4B_SCP_Audit')
            ? MAD4B_SCP_Audit::storage_status() : array();
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : '';
        $uuid = (string)($site['site_uuid']??'');
        $origin = function_exists('home_url') ? rtrim(home_url(),'/') : '';
        $host_uuid = trim((string)getenv('MAD4B_CLI_TARGET_SITE_UUID'));
        $host_origin = rtrim(trim((string)getenv('MAD4B_CLI_TARGET_ORIGIN')),'/');
        $host_db = trim((string)getenv('MAD4B_CLI_TARGET_DATABASE'));
        $site_env = (string)($site['configured_environment']??'');
        $allowed = array_filter(array_map('trim',explode(',',(string)getenv('MAD4B_CLI_ALLOWED_WRITE_ENVIRONMENTS'))));

        $site_bound = !empty($site['configured']) && !empty($site['authority_ready'])
            && !empty($site['same_origin_clone_protection'])
            && !empty($site['deployment_binding_match'])
            && !empty($site['origin_match'])
            && empty($site['mutation_pending_audit']);
        $environment_bound = ''!==$env && defined('WP_ENVIRONMENT_TYPE')
            && WP_ENVIRONMENT_TYPE===$env && $site_env===$env;
        $cli_identity = defined('WP_CLI') && WP_CLI && PHP_SAPI==='cli'
            && get_current_user_id()===0;

        $has_host_assertion = $host_uuid!=='' || $host_origin!=='' || $host_db!=='';
        $host_exact = $has_host_assertion && $uuid!=='' && hash_equals($uuid,$host_uuid)
            && $origin!=='' && hash_equals($origin,$host_origin)
            && $host_db!=='' && defined('DB_NAME') && hash_equals(DB_NAME,$host_db);

        // For a registered internal maintenance operation, the enrolled
        // site + deployment binding is the local source of identity.
        // If an independent host assertion exists, mismatches are never
        // ignored. Existing other mutation operations still require one.
        $internal = $is_mutation && self::local_operation_permitted((string)$operation,$env);
        $site_identity_verified = $site_bound && (
            $host_exact || (!$has_host_assertion && (!$is_mutation || $internal))
        );
        $legacy_host_write = $is_mutation && !$internal && $host_exact
            && $env!=='production' && in_array($env,$allowed,true)
            && !empty($site['authority_ready']);
        $operation_allowed = !$is_mutation || ($internal && $site_bound) || $legacy_host_write;
        $checks = array(
            'wp_cli_no_user_impersonation'=>$cli_identity,
            'site_profile_configured'=>!empty($site['configured']),
            'environment_exact'=>$environment_bound,
            'site_binding_ready'=>$site_bound,
            'unified_site_identity_verified'=>$site_identity_verified,
            'append_only_audit_ready'=>!empty($audit['ready']) && !empty($audit['append_only']),
            'unified_operation_policy_allowed'=>$operation_allowed,
        );
        $ready = !in_array(false,$checks,true);
        return array(
            'contract'=>self::CONTRACT,
            'operation'=>(string)$operation,
            'site_uuid'=>$uuid,
            'environment'=>$env,
            'configured_environment'=>$site_env,
            'canonical_origin'=>$origin,
            'host_site_binding_verified'=>$site_identity_verified,
            'governed_remote_write_enabled'=>!empty($site['write_enabled']),
            'local_operation_authorized'=>$ready && $is_mutation,
            'read_context_verified'=>$ready && !$is_mutation,
            'checks'=>$checks,
            'blockers'=>array_keys(array_filter($checks,static function($ok){return !$ok;})),
            'production_mutation_authorized'=>$ready && $is_mutation && $env==='production' && $internal,
            'mutation_performed'=>false,
        );
    }
    public static function inspect_command($args, $assoc_args) {
        if ($args || $assoc_args) WP_CLI::error('No user-supplied environment or site parameters accepted in WordPress context.');
        WP_CLI::line(wp_json_encode(self::resolve(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
    public static function check_command($args, $assoc_args) {
        if ($args || $assoc_args) WP_CLI::error('No caller-supplied environment or identity accepted.');
        $value=self::resolve();
        WP_CLI::line(wp_json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        if (!$value['read_context_verified']) WP_CLI::error('Host/site authority mismatch: '.implode(',',$value['blockers']));
    }
}
MAD4B_SCP_CLI_Execution_Context::boot();
