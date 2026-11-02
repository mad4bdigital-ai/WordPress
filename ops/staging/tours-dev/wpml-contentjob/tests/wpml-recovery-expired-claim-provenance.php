<?php
/**
 * Side-effect-free queue_scan claim-provenance acceptance.
 *
 * Run: php tests/wpml-recovery-expired-claim-provenance.php
 * Optional source override: MAD4B_LOCALIZATION_SOURCE=/path/to/301...php php tests/...
 * No WordPress bootstrap or DB connection.
 */
declare(strict_types=1);
$sourceFile = getenv('MAD4B_LOCALIZATION_SOURCE') ?: dirname(__DIR__).'/301-mad4b-elementor-localization.php';
$source = @file_get_contents($sourceFile);
if ($source === false) { fwrite(STDERR, "source_unavailable\n"); exit(2); }
// For testing a change proposed against an installed old runtime ONLY, patch
// the source in memory (never change any live files).
if (getenv('MAD4B_TEST_CANDIDATE_PATCH') === '1') {
    $original = "                    \$new[\$key]['lease_until']=null;\n"
        ."                    \$new[\$key]['claimed_at']=null;\n"
        ."                    \$new[\$key]['claimed_by_user']=null;";
    $replacement = "                    // Preserve the expired claim's original actor and lease\n"
        ."                    // timestamps. Recovery validates all three, the 24-hour\n"
        ."                    // bound, WPML publication proof and the native job state.\n"
        ."                    // Clearing them here made every previously leased\n"
        ."                    // resolved_after_readback row permanently ineligible.\n"
        ."                    // No new lease is granted by this scan.\n"
        ."                    \$new[\$key]['lease_until']=\$job['lease_until']??null;\n"
        ."                    \$new[\$key]['claimed_at']=\$job['claimed_at']??null;\n"
        ."                    \$new[\$key]['claimed_by_user']=\$job['claimed_by_user']??null;";
    if (substr_count($source, $original) !== 1) {
        fwrite(STDERR, "candidate_patch_not_applicable\n");
        exit(2);
    }
    $source = str_replace($original, $replacement, $source);
}
$start = strpos($source, '    public static function queue_scan(){');
$end = $start === false ? false : strpos($source, '    private static function is_style(', $start);
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "queue_scan_method_not_found\n");
    exit(2);
}
$method = substr($source, $start, $end - $start);
$now = time();
$GLOBALS['scan_fixture'] = [
 'outbox' => [
   'expired' => [
     'status' => 'leased', 'lease_until' => gmdate('c', $now - 120),
     'claimed_at' => gmdate('c', $now - 3600), 'claimed_by_user' => 26,
     'attempts' => 1, 'last_error' => null,
   ],
   'never_claimed' => ['status' => 'queued', 'attempts' => 0],
   'active' => [
     'status' => 'leased', 'lease_until' => gmdate('c', $now + 900),
     'claimed_at' => gmdate('c', $now - 900), 'claimed_by_user' => 26,
     'attempts' => 1,
   ],
 ],
 'reports' => 0, 'writes' => 0,
];
function get_option($name, $default = []) {
    return $name === 'scan_outbox' ? $GLOBALS['scan_fixture']['outbox'] : $default;
}
function update_option($name, $value, $autoload = false) {
    if ($name === 'scan_outbox') {
        $GLOBALS['scan_fixture']['outbox'] = $value;
        $GLOBALS['scan_fixture']['writes']++;
    } else if ($name === 'scan_report') {
        $GLOBALS['scan_fixture']['reports']++;
    } else {
        throw new RuntimeException('unexpected_option_write');
    }
    return true;
}
function do_action($name, ...$args) {
    if ($name !== 'mad4b/localization/jobs_reconciled') {
        throw new RuntimeException('unexpected_action');
    }
}
function is_wp_error($x) { return false; }
eval('class MAD4B_Isolated_Queue_Scan {
    const OPTION = "scan_outbox";
    const REPORT = "scan_report";
    public static function enabled() { return true; }
    public static function inspect($limit = 450) {
        return ["complete" => true, "jobs" => [], "issues" => [],
            "scanned_sources" => 0, "contract" => "isolated.queue-scan"];
    }
    public static function outbox() { return get_option(self::OPTION, []); }
    '.$method.'
}');
$before = $GLOBALS['scan_fixture']['outbox'];
$r = MAD4B_Isolated_Queue_Scan::queue_scan();
$after = $GLOBALS['scan_fixture']['outbox'];
$assertions = [
 'expired_claim_resolves' => $after['expired']['status'] === 'resolved_after_readback',
 'expired_claim_actor_preserved' => ($after['expired']['claimed_by_user'] ?? null) === 26,
 'expired_claim_time_preserved' => ($after['expired']['claimed_at'] ?? null) === $before['expired']['claimed_at'],
 'expired_claim_lease_preserved' => ($after['expired']['lease_until'] ?? null) === $before['expired']['lease_until'],
 'expired_claim_reason_preserved' => ($after['expired']['last_error'] ?? null) === 'lease_expired_before_ack_after_gap_resolved',
 'unclaimed_queue_resolves_without_forging_claim' =>
    $after['never_claimed']['status'] === 'resolved_after_readback'
    && empty($after['never_claimed']['claimed_at'])
    && empty($after['never_claimed']['claimed_by_user']),
 'active_lease_unchanged' => $after['active'] === $before['active'],
 'only_isolated_fixture_updated' => $GLOBALS['scan_fixture']['writes'] === 1,
 'report_written' => $GLOBALS['scan_fixture']['reports'] === 1,
];
echo json_encode([
  'contract' => 'mad4b.wpml.queue-scan-claim-provenance.v1',
  'method_sha256' => hash('sha256', $method),
  'source_blob_sha1' => sha1("blob ".strlen($source)."\0".$source),
  'candidate_patch_in_memory' => getenv('MAD4B_TEST_CANDIDATE_PATCH') === '1',
  'passed' => count(array_filter($assertions)),
  'total' => count($assertions),
  'assertions' => $assertions,
  'real_wordpress_mutations' => false,
], JSON_UNESCAPED_SLASHES)."\n";
if (in_array(false, $assertions, true)) exit(1);
