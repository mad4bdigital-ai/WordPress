<?php
/**
 * Hermetic contract check: native WordPress updates do not need provider
 * certificates; they still require the existing exact installer and checks.
 * This test never contacts WordPress, installs software, or grants authority.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
function add_action(...$unused): void {}
function sanitize_key($value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function wp_normalize_path($value): string { return str_replace('\\', '/', (string) $value); }
function sanitize_text_field($value): string { return trim((string) $value); }
function wp_json_encode($value, $options = 0) { return json_encode($value, $options); }
function wp_has_ability($name): bool { return $name === 'mad4b/plugin-remote-update-plan'; }
final class WP_Error {
    private $code;
    private $message;
    public function __construct($code, $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
final class MockNativeRemotePlan {
    public static $blockers = array();
    public static $lastInput = null;
    public function execute($input) {
        self::$lastInput = $input;
        return array(
            'contract' => 'mad4b.plugin-remote-update-plan.v1',
            'operation' => 'remote_update',
            'eligible' => ! self::$blockers,
            'blockers' => self::$blockers,
            'plan_sha256' => str_repeat('a', 64),
            'plugin_file' => $input['plugin_file'],
        );
    }
}
function wp_get_ability($name) {
    if ($name !== 'mad4b/plugin-remote-update-plan') throw new RuntimeException('Unexpected ability: ' . $name);
    return new MockNativeRemotePlan();
}
require __DIR__ . '/../includes/class-mad4b-scp-plugin-transaction.php';
function check($test, $message): void {
    if (!$test) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}
$input = array('action' => 'replace', 'plugin' => 'example/example.php', 'reason' => 'Native safe replacement');
$plan = MAD4B_SCP_Plugin_Transaction::plan($input);
check(!is_wp_error($plan), 'native replacement should produce a plan');
check($plan['planner'] === 'mad4b/plugin-remote-update-plan', 'native plan selected');
check($plan['executor'] === 'mad4b/plugin-remote-update-apply', 'authorized native executor selected');
check($plan['eligible'] === true, 'eligible when existing updater accepts exact offer');
check($plan['native_operation'] === 'remote_update', 'native operation preserved');
check($plan['package_certification_policy'] === 'optional_native_wordpress_offer', 'no provider certificate prerequisite');
check($plan['execution_contract'] === 'exact_executor_only', 'no generic mutation dispatcher');
check($plan['generic_mutation_dispatch'] === false && $plan['mutation_performed'] === false, 'planner stays read-only');
check(MockNativeRemotePlan::$lastInput['plugin_file'] === 'example/example.php', 'exact plugin identity passed');
check($plan['production_promotion_rule'] === 'independent_staging_pass_and_exact_native_production_batch', 'no automatic production deployment');

MockNativeRemotePlan::$blockers = array('wordpress_update_package_url_unsafe');
$denied = MAD4B_SCP_Plugin_Transaction::plan($input);
check($denied['eligible'] === false, 'native source block is preserved');
check(in_array('wordpress_update_package_url_unsafe', $denied['blockers'], true), 'native blocker is propagated');

$missing = MAD4B_SCP_Plugin_Transaction::plan(array('action' => 'replace', 'reason' => 'No plugin'));
check(is_wp_error($missing) && $missing->get_error_code() === 'mad4b_plugin_transaction_plugin_required', 'plugin identity mandatory');

$install = MAD4B_SCP_Plugin_Transaction::plan(array('action' => 'install', 'plugin' => 'example/example.php', 'reason' => 'No provider'));
check(is_wp_error($install) && $install->get_error_code() === 'mad4b_plugin_transaction_provider_required', 'untrusted new installs remain denied');

MockNativeRemotePlan::$blockers = array();
$update = MAD4B_SCP_Plugin_Transaction::plan(array('action' => 'update', 'plugin' => 'example/example.php', 'reason' => 'Native update'));
check($update['eligible'] && $update['executor'] === 'mad4b/plugin-remote-update-apply', 'native update unchanged');

echo "MAD4B_NATIVE_PLUGIN_REPLACE_CERT_OPTIONAL: PASS" . PHP_EOL;
