<?php
/**
 * Plugin Name: MAD4B Site-Local Cron Runtime
 * Description: Host-agnostic read-only site-owned Cron and Action Scheduler state.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) return;

final class MAD4B_Site_Local_Cron_Runtime_V1 {
    public const CONTRACT = 'mad4b.site-local-cron-runtime.v1';
    public const VERSION = '1.0.0';

    /** Pure read-only classification. Only site-local host runners may own dispatch. */
    public static function status(bool $include_queue = true): array {
        $disabled = defined('DISABLE_WP_CRON') && (bool) DISABLE_WP_CRON;
        $alternate = defined('ALTERNATE_WP_CRON') && (bool) ALTERNATE_WP_CRON;
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $mode = $disabled ? 'site_local_host_runner_required_unverified'
            : ($alternate ? 'wordpress_core_alternate' : 'wordpress_core_request_driven');
        $scheduled = wp_next_scheduled('action_scheduler_run_queue');
        $result = [
            'contract' => self::CONTRACT,
            'version' => self::VERSION,
            'site_host' => $host,
            'site_environment' => wp_get_environment_type(),
            'execution_owner' => 'site_host_runtime',
            'central_remote_cron_dispatch_allowed' => false,
            'mode' => $mode,
            'request_driven_wp_cron_enabled' => !$disabled,
            'native_host_runner_attested' => false,
            'host_native_reconciliation_pending' => true,
            'host_native_schedule_source' => 'not_yet_independently_proven',
            'site_local_fallback' => $disabled ? 'not_available_until_site_host_scheduler_is_verified' : 'wordpress_core_wp_cron',
            'host_cron_reconciliation_required' => $disabled,
            'action_scheduler_hook_registered' => false !== has_action('action_scheduler_run_queue'),
            'action_scheduler_next_run_utc' => $scheduled ? gmdate('Y-m-d\TH:i:s\Z', (int) $scheduled) : null,
            'observed_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'evidence_scope' => 'wordpress_runtime_state_only',
        ];
        if (!$include_queue) return $result;
        $now = time();
        $due = 0; $pending_events = 0;
        foreach ((array) _get_cron_array() as $epoch => $hooks) {
            if (!is_array($hooks)) continue;
            foreach ($hooks as $jobs) {
                if (!is_array($jobs)) continue;
                $pending_events += count($jobs);
                if ((int) $epoch <= $now) $due += count($jobs);
            }
        }
        $result['wpcron_events_registered'] = $pending_events;
        $result['wpcron_due_events'] = $due;
        $result['action_scheduler_queue'] = [
            'state' => 'unavailable',
            'pending' => null,
            'overdue_more_than_5m' => null,
        ];
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && isset($wpdb->prefix)
            && false !== has_action('action_scheduler_run_queue')) {
            $table = $wpdb->prefix . 'actionscheduler_actions';
            // No queue writes, replays, audit exports, or action args.
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ((string) $exists === $table) {
                $pending = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE status=%s", 'pending'
                ));
                $overdue = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE status=%s AND scheduled_date_gmt<%s",
                    'pending', gmdate('Y-m-d H:i:s', $now - 300)
                ));
                $result['action_scheduler_queue'] = [
                    'state' => 'observed',
                    'pending' => (int) $pending,
                    'overdue_more_than_5m' => (int) $overdue,
                ];
            }
        }
        return $result;
    }

    /** CLI-only management readback; never exposes private infrastructure publicly. */
    public static function register_cli(): void {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) return;
        \WP_CLI::add_command('mad4b cron-runtime status', static function(): void {
            \WP_CLI::line(wp_json_encode(self::status(true), JSON_UNESCAPED_SLASHES));
        }, ['shortdesc' => 'Read site-local WordPress Cron/Action Scheduler status (no dispatch).']);
    }
}
MAD4B_Site_Local_Cron_Runtime_V1::register_cli();
