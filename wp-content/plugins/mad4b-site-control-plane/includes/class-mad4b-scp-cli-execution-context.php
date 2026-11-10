<?php
/**
 * Tenant-neutral WP-CLI Execution Context.
 * Identity is resolved from installed WordPress Site Profile + host assertion,
 * never a caller supplied --environment or hostname-derived capability grant.
 * Read-only introspection works without host approval; writes are fail-closed.
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
        $host_match = (''!==$uuid && $uuid===$host_uuid
            && ''!==$origin && $origin===$host_origin
            && ''!==$host_db && defined('DB_NAME') && DB_NAME===$host_db);
        $checks = array(
            'wp_cli_no_user_impersonation' => defined('WP_CLI') && WP_CLI && PHP_SAPI==='cli'
                && get_current_user_id()===0,
            'site_profile_configured' => !empty($site['configured']),
            'environment_exact' => ''!==$env && defined('WP_ENVIRONMENT_TYPE')
                && WP_ENVIRONMENT_TYPE===$env && $site_env===$env,
            'site_binding_ready' => !empty($site['authority_ready'])
                && !empty($site['same_origin_clone_protection'])
                && !empty($site['deployment_binding_match'])
                && !empty($site['origin_match'])
                && empty($site['mutation_pending_audit']),
            'host_registry_exact_site' => $host_match,
            'append_only_audit_ready' => !empty($audit['ready']) && !empty($audit['append_only']),
            'operation_allowed_in_host_policy' => !$is_mutation
                || (in_array($env,$allowed,true) && !empty($site['authority_ready'])),
            // Production always fails closed unless a future separate governed
            // operation approval contract is implemented. A config flag alone
            // must not unlock production writes.
            'production_mutation_denied_without_dedicated_approval' => !$is_mutation || $env!=='production',
        );
        $ready = !in_array(false,$checks,true);
        return array(
            'contract'=>self::CONTRACT,
            'operation'=>(string)$operation,
            'site_uuid'=>$uuid,
            'environment'=>$env,
            'configured_environment'=>$site_env,
            'canonical_origin'=>$origin,
            'host_site_binding_verified'=>$host_match,
            'governed_remote_write_enabled'=>!empty($site['write_enabled']),
            'local_operation_authorized'=>$ready && $is_mutation,
            'read_context_verified'=>$ready && !$is_mutation,
            'checks'=>$checks,
            'blockers'=>array_keys(array_filter($checks,static function($ok){return !$ok;})),
            'production_mutation_authorized'=>false,
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
