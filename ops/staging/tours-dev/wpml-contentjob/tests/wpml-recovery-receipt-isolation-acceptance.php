<?php
/**
 * Offline, side-effect-free acceptance for the actual Recovery method.
 *
 * Loads ONLY the recover_claim() method from the source MU plugin into a
 * standalone PHP process with deterministic WordPress/WPML stubs. This never
 * boots WordPress or connects to its database, and cannot certify real HTTPS
 * authentication or a live eligible recovery row.
 *
 * Run from the branch:
 *   php tests/wpml-recovery-receipt-isolation-acceptance.php
 * Optional installed-source override:
 *   MAD4B_LOCALIZATION_SOURCE=/path/to/301-mad4b-elementor-localization.php php tests/wpml-recovery-receipt-isolation-acceptance.php
 */
declare(strict_types=1);
$sourceFile = getenv('MAD4B_LOCALIZATION_SOURCE') ?: dirname(__DIR__).'/301-mad4b-elementor-localization.php';
$source = @file_get_contents($sourceFile);
if ($source === false) { fwrite(STDERR, "localization_source_unavailable\n"); exit(2); }
$begin = strpos($source, '    public static function recover_claim($input){');
$end = $begin === false ? false : strpos($source, '    public static function ack($input){', $begin);
if ($begin === false || $end === false || $end <= $begin) {
    fwrite(STDERR, "recovery_method_boundary_not_found\n");
    exit(2);
}
$actualMethod = substr($source, $begin, $end - $begin);

// The synthetic objects here are completely disconnected from live WP state.
$GLOBALS['recovery_fixture'] = [
    'key' => str_repeat('a', 64),
    'digest' => str_repeat('b', 64),
    'receipt' => 'mad4b.wpml.stage.'.str_repeat('a', 32),
    'writes' => 0,
];
$f = &$GLOBALS['recovery_fixture'];
$now = time();
$f['row'] = [
    'status' => 'resolved_after_readback',
    'claimed_by_user' => 26,
    'receipt_id' => '',
    'source_sha256' => $f['digest'],
    'attempts' => 1,
    'lease_until' => gmdate('c', $now - 120),
    'claimed_at' => gmdate('c', $now - 3600),
    'source_post_id' => 111,
    'target_language' => 'fr',
];
$f['proof'] = [
    'receipt_id' => $f['receipt'],
    'wpml_readback_verified' => true,
    'source' => 111,
    'target' => 222,
    'locale' => 'fr',
    'job' => 'isolated-queued-job',
    'source_sha256' => hash('sha256', 'SOURCE-CONTENT'),
    'target_sha256' => hash('sha256', 'TARGET-CONTENT'),
];

class WP_Error {
    private $code;
    public function __construct($code) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class MAD4B_SCP_Policy {
    public static function can_mutate() { return true; }
}
class MAD4B_SCP_Operational_Integrity {
    public static function capture() {
        return ['scope' => ['site_uuid' => 'e5e64bca-ed62-48d2-b2b3-dff693cd702a']];
    }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function current_user_can($cap) {
    return in_array($cap, ['manage_options', 'edit_others_posts'], true);
}
function get_current_user_id() { return 26; }
function get_post_type($id) { return in_array($id, [111, 222], true) ? 'page' : null; }
function get_post_status($id) { return in_array($id, [111, 222], true) ? 'publish' : null; }
function apply_filters($name, $value, ...$args) {
    if ($name === 'wpml_object_id') return 222;
    if ($name === 'wpml_element_trid') return 17;
    if ($name === 'wpml_post_language_details') return ['language_code' => 'fr'];
    return $value;
}
function get_post_meta($id, $key, $single = true) {
    return $id === 111 ? 'SOURCE-CONTENT' : 'TARGET-CONTENT';
}
function get_option($name, $default = []) {
    if ($name === 'mad4b_wpml_staging_publication_receipt_222_v1') {
        return $GLOBALS['recovery_fixture']['proof'];
    }
    if ($name === 'mad4b_wpml_content_job_map_v1') return [];
    return $default;
}
function add_option(...$args) {
    $GLOBALS['recovery_fixture']['writes']++;
    throw new RuntimeException('isolated_fixture_write_forbidden');
}
function update_option(...$args) {
    $GLOBALS['recovery_fixture']['writes']++;
    throw new RuntimeException('isolated_fixture_write_forbidden');
}

// Evaluate only the exact method extracted from the repository-owned source.
// Do not load another plugin, external file, user-provided input, or WP runtime.
eval('class MAD4B_Isolated_Actual_Recovery {
    const OPTION = "isolated_outbox";
    public static function enabled() { return true; }
    public static function with_outbox_lock($cb) { return $cb(); }
    public static function outbox() {
        return [$GLOBALS["recovery_fixture"]["key"] => $GLOBALS["recovery_fixture"]["row"]];
    }
    public static function types() { return ["page"]; }
    public static function languages() { return ["en", "fr"]; }
    public static function default_lang() { return "en"; }
    public static function tree($id) { return []; }
    public static function source_digest($id, $tree) { return $GLOBALS["recovery_fixture"]["digest"]; }
    '.$actualMethod.'
}');

$before = serialize($f['row']);
$input = ['key' => $f['key'], 'expected_source_sha256' => $f['digest']];
$wrong = MAD4B_Isolated_Actual_Recovery::recover_claim($input + [
    'receipt_id' => 'mad4b.wpml.stage.'.str_repeat('0', 32),
]);
$right = MAD4B_Isolated_Actual_Recovery::recover_claim($input + [
    'receipt_id' => $f['receipt'],
]);

$assertions = [
    'eligible_fixture_rejects_wrong_receipt' =>
        is_wp_error($wrong) && $wrong->get_error_code() === 'mad4b_localization_recovery_receipt_mismatch',
    'same_fixture_valid_receipt_passes_receipt_gate' =>
        is_wp_error($right) && $right->get_error_code() === 'mad4b_localization_recovery_native_job_mismatch',
    'REST_status_contract_maps_receipt_mismatch_to_422' =>
        strpos($source, "'mad4b_localization_recovery_receipt_mismatch'=>422") !== false,
    'outbox_fixture_unchanged' => serialize($f['row']) === $before,
    'no_wordpress_or_database_writes' => $f['writes'] === 0,
];
echo json_encode([
    'contract' => 'mad4b.wpml.recovery-receipt-isolation.v1',
    'source_method_sha256' => hash('sha256', $actualMethod),
    'passed' => count(array_filter($assertions)),
    'total' => count($assertions),
    'assertions' => $assertions,
    'external_https_certified' => false,
    'real_recovery_row_used' => false,
], JSON_UNESCAPED_SLASHES)."\n";
if (in_array(false, $assertions, true)) exit(1);
