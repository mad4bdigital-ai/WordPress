<?php
/**
 * MAD4B bounded Drive inventory cron worker.
 * Invoked through authenticated site-local WP-CLI by the host's systemd timer.
 * Read-only inventory; NEVER applies Context assets, approvals or Google writes.
 */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}

final class MAD4B_SCP_Drive_Scan_Cron_V1 {
    private const STATUS = 'mad4b_drive_scan_cron_status_v1';
    private const POLICY = 'mad4b_drive_scan_cron_policy_v1';
    private const CONTRACT = 'mad4b.drive-scan-cron.v1';

    private static function report(string $state, array $extra = []): void {
        require_once __DIR__ . '/class-mad4b-scp-brand-review-routing.php';
        $source_id=(string)getenv('MAD4B_SCAN_SOURCE_ID');
        $route=MAD4B_SCP_Brand_Review_Routing_V1::summarize($source_id);
        $previous = get_option(self::STATUS, []);
        if (!is_array($previous)) $previous = [];
        $item = array_merge($previous, [
            'contract' => self::CONTRACT,
            'state' => $state,
            'checked_at' => gmdate('c'),
            'site_uuid' => (string)getenv('MAD4B_SCAN_SITE_UUID'),
            'source_id' => (string)getenv('MAD4B_SCAN_SOURCE_ID'),
            'worker' => 'server_cron_bounded',
            'read_only' => true,
            'registry_mutation_performed' => false,
            'publication_authorized' => false,
            'review_inheritance' => $route,
            'effective_review_mode' => (string)($route['effective_mode']??'human_only'),
            'review_route' => (string)($route['review_route']??'blocked'),
            'ai_approval_authority_ready' => !empty($route['ai_review_eligible']),
        ], $extra);
        update_option(self::STATUS, $item, false);
        echo 'MAD4B_DRIVE_SCAN ' . wp_json_encode($item, JSON_UNESCAPED_SLASHES) . "\n";
    }

    public static function run(): void {
        if (!class_exists('MAD4B_SCP_Brand_Context_Builder', false)
            || !class_exists('MAD4B_SCP_Context_Authority', false)) {
            self::report('blocked', ['error_code' => 'scanner_classes_missing']);
            return;
        }

        $expected_site = (string)getenv('MAD4B_SCAN_SITE_UUID');
        $source_id = (string)getenv('MAD4B_SCAN_SOURCE_ID');
        $actor_id = (int)getenv('MAD4B_SCAN_ACTOR_ID');
        if (!preg_match('/^[a-f0-9-]{36}$/D', $expected_site)
            || !preg_match('/^[a-f0-9]{64}$/D', $source_id)
            || $actor_id <= 0 || !get_user_by('id', $actor_id)) {
            self::report('blocked', ['error_code' => 'scheduler_binding_invalid']);
            return;
        }

        wp_set_current_user($actor_id);
        if (!current_user_can('manage_options')) {
            self::report('blocked', ['error_code' => 'scheduler_operator_denied']);
            return;
        }
        $site = MAD4B_SCP_Site_Profile::status();
        $source = MAD4B_SCP_Context_Authority::source($source_id);
        if (!is_array($site) || empty($site['configured'])
            || !in_array($site['environment'] ?? '', ['staging', 'production'], true)
            || !hash_equals($expected_site, (string)($site['site_uuid'] ?? ''))
            || !hash_equals($expected_site, (string)($source['site_uuid'] ?? ''))
            || !hash_equals((string)($site['configured_environment'] ?? ''), (string)$site['environment'])
            || empty($site['origin_match']) || empty($site['authority_ready'])
            || empty($site['environment_match']) || empty($site['deployment_binding_match'])
            || ($source['provider'] ?? '') !== 'google_drive'
            || ($source['mode'] ?? '') !== 'governed') {
            self::report('blocked', ['error_code' => 'scheduler_site_or_source_drift']);
            return;
        }

        $policy = get_option(self::POLICY, []);
        if (!is_array($policy)
            || empty($policy['enabled'])
            || !hash_equals((string)($policy['site_uuid'] ?? ''), $expected_site)
            || !hash_equals((string)($policy['source_id'] ?? ''), $source_id)) {
            self::report('disabled', ['error_code' => 'scheduler_policy_not_enabled']);
            return;
        }

        require_once __DIR__ . '/class-mad4b-scp-drive-scan-continuation.php';
        $progress = get_option(MAD4B_SCP_Drive_Scan_Continuation::progress_option_name($source_id), []);
        if (is_array($progress) && ($progress['contract'] ?? '') === 'mad4b.scan-ui-progress.v1'
            && hash_equals($source_id, (string)($progress['source_id'] ?? ''))
            && !empty($progress['inventory_complete'])) {
            self::report('ready_for_review', [
                'next_action' => 'review_verified_inventory_then_inherited_review_route',
                'error_code' => '',
                'revision' => (int)($progress['checkpoint_revision'] ?? 0),
                'verified_files' => (int)($progress['verified_files'] ?? 0),
                'deferred_files' => (int)($progress['deferred_files'] ?? 0),
                'inventory_complete' => true,
                'last_success_at' => gmdate('c'),
            ]);
            return;
        }

        if (is_array($progress) && !empty($progress['checkpoint_revision'])
            && (int)($progress['expires_at'] ?? 0) <= time()) {
            // No silent restart. Expired checkpoint needs an explicit new scan
            // to preserve the existing reviewed candidate inventory.
            self::report('needs_attention', ['error_code' => 'checkpoint_expired']);
            return;
        }

        $before = get_option(self::STATUS, []);
        if (!is_array($before) || ($before['source_id'] ?? '') !== $source_id) $before = [];
        $next_retry = (int)($before['next_retry_at'] ?? 0);
        if ($next_retry > time()) {
            self::report('retry_scheduled', ['error_code' => (string)($before['error_code'] ?? ''),
                'next_retry_at' => $next_retry]);
            return;
        }
        $drive = MAD4B_SCP_Google_Drive_Context::connection_status();
        if (empty($drive['connected']) || empty($drive['read_available'])
            || !empty($drive['revocation_pending']) || !empty($drive['reconnect_required'])) {
            self::report('needs_attention', ['error_code' => 'google_read_grant_unavailable']);
            return;
        }

        $max_steps = max(1, min(7, (int)($policy['steps_per_run'] ?? 5)));
        $limit = microtime(true) + 50;
        $revisions_done = 0;
        $plan = null;
        $cursor = '';
        $last_rev = -1;
        for ($i=0; $i <= $max_steps && microtime(true) < $limit; $i++) {
            $plan = MAD4B_SCP_Brand_Context_Builder::source_scan_plan([
                'source_id' => $source_id,
                'resumable' => true,
                'scan_cursor' => $cursor,
            ]);
            if (is_wp_error($plan)) {
                $code = sanitize_key($plan->get_error_code());
                $transient = in_array($code, [
                    'http_request_failed', 'mad4b_google_drive_api_failed',
                    'mad4b_google_drive_binary_fetch_failed',
                    'mad4b_google_drive_timeout',
                    'mad4b_catalog_build_in_progress',
                    'mad4b_scan_checkpoint_cas_failed',
                    'mad4b_scan_cursor_stale_or_foreign',
                    'mad4b_scan_step_busy',
                ], true);
                $fails = $transient ? min(6, 1 + (int)($before['consecutive_errors'] ?? 0)) : 0;
                self::report($transient ? 'retry_scheduled' : 'needs_attention', [
                    'error_code' => $code,
                    'consecutive_errors' => $fails,
                    'next_retry_at' => $transient ? time() + min(600, 75 * (2 ** max(0, $fails-1))) : 0,
                    'steps_advanced' => $revisions_done,
                ]);
                return;
            }
            $revision = (int)($plan['continuation']['checkpoint_revision'] ?? 0);
            if ($i === 0) {
                $last_rev = $revision;
            } elseif ($revision > $last_rev) {
                $revisions_done += $revision - $last_rev;
                $last_rev = $revision;
            } else {
                // Other actor already advanced/replayed this revision. Stop.
                break;
            }
            if (!empty($plan['inventory_complete'])) break;
            $cursor = (string)($plan['continuation']['scan_cursor'] ?? '');
            if ($cursor === '') break;
        }

        $done = !empty($plan['inventory_complete']);
        $progress = get_option(MAD4B_SCP_Drive_Scan_Continuation::progress_option_name($source_id), []);
        self::report($done ? 'ready_for_review' : 'running', [
            'error_code' => '',
            'consecutive_errors' => 0,
            'next_retry_at' => 0,
            'last_success_at' => gmdate('c'),
            'steps_advanced' => $revisions_done,
            'revision' => (int)($plan['continuation']['checkpoint_revision'] ?? 0),
            'verified_files' => (int)($progress['verified_files'] ?? count((array)($plan['new_files'] ?? []))),
            'deferred_files' => (int)($progress['deferred_files'] ?? ($plan['deferred_count'] ?? 0)),
            'queued_folders' => (int)($progress['queued_folders'] ?? 0),
            'pending_files' => (int)($progress['pending_files'] ?? 0),
            'inventory_complete' => $done,
            'next_action' => $done ? 'review_verified_inventory_then_inherited_review_route' : 'scheduled_next_batch',
        ]);
    }
}
MAD4B_SCP_Drive_Scan_Cron_V1::run();
