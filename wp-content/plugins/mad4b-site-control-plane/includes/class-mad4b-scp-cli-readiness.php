<?php
/**
 * Local operator lane for already-configured Content Readiness scheduled scans.
 * This is NOT OAuth, MCP governed Write, arbitrary WP CLI eval or an authority
 * expansion. Reuses original source-owned scheduled runner and append-only Audit.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_CLI_Readiness {
    const CONTRACT = 'mad4b.cli-readiness.v1';
    const CONFIRM = 'RUN SCHEDULED READINESS SCAN';
    private static $booted = false;

    public static function boot() {
        if (self::$booted || !defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) return;
        self::$booted = true;
        WP_CLI::add_command('mad4b readiness plan', array(__CLASS__, 'plan_command'));
        WP_CLI::add_command('mad4b readiness run', array(__CLASS__, 'run_command'));
    }
    private static function context() {
        $scanner = class_exists('MAD4B_SCP_Content_Readiness_Alerts');
        $rules = $scanner ? MAD4B_SCP_Content_Readiness_Alerts::rules() : array();
        $history = $scanner ? (array)get_option(MAD4B_SCP_Content_Readiness_Alerts::AUDIT, array()) : array();
        $last = 0;
        foreach ($history as $row) {
            if (is_array($row) && ($row['event']??'')==='scan') {
                $last = max($last, strtotime((string)($row['at']??'')) ?: 0);
            }
        }
        $interval = max(15, min(1440, absint($rules['scheduled_scan_interval_minutes']??60))) * 60;
        $now = time();
        // A single source-backed execution context resolves tenant identity,
        // environment, binding, and host authorization for every operation.
        $execution = MAD4B_SCP_CLI_Execution_Context::resolve(
            'content_readiness.scheduled_scan', true
        );
        $checks = array_merge(
            $execution['checks'],
            array(
                'scanner_available' => $scanner
                    && method_exists('MAD4B_SCP_Content_Readiness_Alerts','run_scheduled'),
                'scheduled_scan_authorized_by_site_policy' => !empty($rules['scheduled_scan_enabled']),
            )
        );
        $identity = array(
            'site_uuid'=>(string)$execution['site_uuid'],
            'profile_digest'=>(string)($status['profile_digest']??''),
            'policy_sha256'=>hash('sha256', wp_json_encode($rules,JSON_UNESCAPED_SLASHES)),
            'latest_scan_timestamp'=>$last,
            'next_due_timestamp'=>$last ? $last+$interval : 0,
            'site_origin'=>home_url(),
            'operation'=>'content_readiness.scheduled_scan',
            'resolved_environment'=>(string)$execution['environment'],
            'host_binding_verified'=>!empty($execution['host_site_binding_verified']),
            'context_contract'=>$execution['contract'],
        );
        $sha = hash('sha256', wp_json_encode(array(
            'contract'=>self::CONTRACT,'identity'=>$identity,'checks'=>$checks
        ),JSON_UNESCAPED_SLASHES));
        return array(
            'contract'=>self::CONTRACT.'.plan',
            'operation'=>'content_readiness.scheduled_scan',
            'site_environment'=>wp_get_environment_type(),
            'eligible'=>!in_array(false,$checks,true),
            'due'=>$last===0 || $now >= $last+$interval,
            'due_at_utc'=>$last ? gmdate('c',$last+$interval):'immediately',
            'checks'=>$checks,
            'blockers'=>array_keys(array_filter($checks,static function($yes){return !$yes;})),
            'plan_sha256'=>$sha,
            'site_uuid'=>$identity['site_uuid'],
            'context'=>$execution,
            'confirmation'=>self::CONFIRM,
            'scope'=>'existing_content_readiness_issues_only',
            'may_create_external_tasks'=>false,
            'may_enable_mcp_write'=>false,
            'commercial_bookings_authorized'=>false,
            'production_mutation_authorized'=>false,
            'mutation_performed'=>false,
        );
    }
    public static function plan_command($args, $assoc_args) {
        if ($args || $assoc_args) WP_CLI::error('Plan accepts no arguments.');
        WP_CLI::line(wp_json_encode(self::context(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
    public static function run_command($args, $assoc_args) {
        if ($args || array_diff(array_keys($assoc_args),array('expected-plan','confirm'))) {
            WP_CLI::error('Only --expected-plan and --confirm are allowed.');
        }
        $plan = self::context();
        if (!$plan['eligible']) WP_CLI::error('Site operation blocked: '.implode(',',$plan['blockers']));
        if (empty($plan['due'])) WP_CLI::error('Readiness scan not due; next due '.$plan['due_at_utc']);
        $expected = (string)($assoc_args['expected-plan']??'');
        $confirmed = (string)($assoc_args['confirm']??'');
        if (!preg_match('/^[a-f0-9]{64}$/D',$expected)
            || !hash_equals($plan['plan_sha256'],$expected)
            || !hash_equals(self::CONFIRM,$confirmed)) {
            WP_CLI::error('Stale plan or exact owner confirmation missing.');
        }
        // A CLI caller cannot assert a WordPress user by --user to acquire new
        // powers. The original source-owned scheduled runner is the only writer.
        if (!defined('MAD4B_READINESS_SCHEDULED_RUN')) define('MAD4B_READINESS_SCHEDULED_RUN',true);
        if (MAD4B_READINESS_SCHEDULED_RUN!==true) WP_CLI::error('Scheduler context not trusted.');
        $intent=MAD4B_SCP_Audit::record('mad4b/cli-readiness-scan-intent',array(
            'site_uuid'=>$plan['site_uuid'],'plan_sha256'=>$plan['plan_sha256'],
            'operation'=>$plan['operation'],'wp_cli'=>true,'actor'=>'host_scheduled_operator',
            'external_task_dispatch'=>false,'governed_write_enablement'=>false
        ),'intent');
        if (is_wp_error($intent)) WP_CLI::error('Append-only intent audit failed: '.$intent->get_error_code());
        $result=MAD4B_SCP_Content_Readiness_Alerts::run_scheduled();
        if (is_wp_error($result)) {
            MAD4B_SCP_Audit::record('mad4b/cli-readiness-scan-result',array(
                'plan_sha256'=>$plan['plan_sha256'],'result_code'=>$result->get_error_code()
            ),'failed');
            WP_CLI::error('Scanner blocked: '.$result->get_error_code());
        }
        if (!is_array($result)) WP_CLI::error('Scanner result missing; needs reconciliation.');
        $issue_registry=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
        $summary=array(
            'contract'=>self::CONTRACT.'.run',
            'status'=>(string)($result['status']??'completed'),
            'issue_registry_count'=>count($issue_registry),
            'scanner_result'=>$result,
            'source_of_write'=>'MAD4B_SCP_Content_Readiness_Alerts::run_scheduled',
            'oauth_used'=>false,'mcp_write_enabled'=>false,'external_tasks_created'=>false,
        );
        $audit=MAD4B_SCP_Audit::record('mad4b/cli-readiness-scan-result',array(
            'site_uuid'=>$plan['site_uuid'],'plan_sha256'=>$plan['plan_sha256'],
            'issue_registry_count'=>count($issue_registry),
            'scanner_state'=>$summary['status'],'external_tasks_created'=>false
        ),'ok');
        if(is_wp_error($audit)) WP_CLI::error('Scan executed but result audit failed; manual reconciliation required.');
        WP_CLI::line(wp_json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
}
MAD4B_SCP_CLI_Readiness::boot();
