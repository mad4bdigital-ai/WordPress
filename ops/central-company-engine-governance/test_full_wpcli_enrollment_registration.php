<?php
/**
 * Hermetic registration regression test. No WordPress, SSH or grant writes.
 * Validate actual first-party catalog loading and fail-closed discovery.
 */
declare(strict_types=1);
define('ABSPATH', '/');
final class MAD4B_Test_Catalog {
    const SKILLS_ABILITY = 'mad4b/reconcile-managed-skills';
    const FRONTEND_SAMPLE_ABILITY = 'mad4b/frontend-performance-sample-run';
    const BROWSER_ACCEPTANCE_ABILITY = 'mad4b/browser-acceptance-run';
    const WORK_QUEUE_ABILITY = 'mad4b/remote-operation-work-queue';
    const WORK_CLAIM_ABILITY = 'mad4b/remote-operation-work-claim';
    const WORK_COMPLETE_ABILITY = 'mad4b/remote-operation-work-complete';
    const QUERY_MONITOR_ATTRIBUTION_ABILITY = 'mad4b/query-monitor-db-attribution-bootstrap';
    const PERFORMANCE_INDEX_ABILITY = 'mad4b/admin-query-performance-apply';
    const PERFORMANCE_RECONCILE_ABILITY = 'mad4b/admin-query-performance-reconcile';
    public static function read(): array {
        return require dirname(__DIR__, 2) . '/wp-content/plugins/mad4b-site-control-plane/config/remote-operation-catalog.php';
    }
}
function check(bool $ok, string $reason): void {
    if (!$ok) { fwrite(STDERR, "FAIL: " . $reason . PHP_EOL); exit(1); }
}
$all = MAD4B_Test_Catalog::read();
$id = 'company_remote_wpcli_full_enrollment';
check(isset($all[$id]) && is_array($all[$id]), 'enrollment_operation_missing');
$row = $all[$id];
check($row['feature_id'] === 'company-ssh-wpcli', 'feature_not_exact');
check($row['authority_surface'] === 'mad4b-enrollment', 'not_original_enrollment');
check($row['remote_ability'] === 'mad4b/company-remote-wpcli-enroll', 'wrong_owner_ability');
check($row['executor'] === 'original_core_broker_305_312_313', 'parallel_executor');
check($row['remote_caller_role'] === 'owner', 'owner_scope_missing');
check($row['human_decision_required'] === true, 'owner_consent_missing');
check($row['chatgpt_direct_step_up'] === false, 'unreviewed_stepup');
check($row['production_policy'] === 'deny', 'production_not_protected');
check(in_array('wp-cli', $row['capability_tags'], true), 'wpcli_not_tagged');
check(!isset($row['auto_authorize']) && !isset($row['grants_created']), 'phantom_grant');
$dispatch_file = dirname(__DIR__, 2) . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-enrollment-dispatch.php';
$dispatch = file_get_contents($dispatch_file);
check(is_string($dispatch) && str_contains($dispatch, "$" . "pending_id = '" . $id . "'"), 'enrollment_discovery_missing');
check(str_contains($dispatch, "$" . "row['dispatchable'] = false"), 'pending_candidate_dispatchable');
check(str_contains($dispatch, "$" . "row['capability_activated'] = false"), 'phantom_activation');
check(str_contains($dispatch, 'generic_admin_denied'), 'legacy_privilege_fence_not_retained');
echo "PASS full WP-CLI enrollment registered as pending; original owner and Core fences preserved." . PHP_EOL;
