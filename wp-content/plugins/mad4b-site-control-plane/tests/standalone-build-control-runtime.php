<?php
/** Offline-only WordPress Ability contract and refusal tests. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    public $code;
    public function __construct($code, $message = '') { $this->code = $code; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function add_action($hook, $call, $priority = 10) { $GLOBALS['build_hooks'][] = array($hook, $priority); }
function wp_register_ability($name, $config) { $GLOBALS['build_abilities'][$name] = $config; }
function wp_has_ability($name) { return isset($GLOBALS['build_abilities'][$name]); }
function wp_json_encode($data) { return json_encode($data); }
class MAD4B_SCP_Site_Profile {
    public static $identity = array('site_uuid' => 'site-1',
        'profile_digest' => 'digest-a', 'environment' => 'staging',
        'canonical_origin' => 'https://staging.example.test');
    public static function status() { return self::$identity; }
}
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-standalone-build-control.php';
function expect_build($condition, $reason) {
    if (!$condition) { fwrite(STDERR, "FAIL: " . $reason . "\n"); exit(1); }
}
$class = 'MAD4B_SCP_Standalone_Build_Control';
$class::boot();
$class::register_abilities();
$class::register_abilities();
expect_build(count($GLOBALS['build_abilities']) === 2, 'Idempotent ability registration');
foreach ($GLOBALS['build_abilities'] as $def) {
    expect_build($def['category'] === 'mad4b-read', 'Only read category');
    expect_build($def['meta']['annotations']['readonly'] === true, 'Read-only tool');
    expect_build($def['input_schema']['additionalProperties'] === false, 'No extra inputs');
}
$status = $class::discover(array());
expect_build($status['mcp_dispatch_implemented'] === false, 'No hidden shell runner');
expect_build($status['automatic_execution_enabled'] === false, 'No automatic execution');
expect_build($status['ci_required_for_build'] === false, 'CI-independent build');
expect_build(is_wp_error($class::discover(array('cmd' => 'php -r 1'))), 'Discovery refuses commands');
$sha = str_repeat('a', 40);
$plan = $class::plan(array('expected_head' => $sha, 'profile' => 'build-only'));
expect_build(!is_wp_error($plan) && $plan['source_commit_sha'] === $sha, 'Valid exact SHA');
expect_build($plan['builder_status'] === 'NOT_RUN' && !$plan['mcp_execution_available'],
    'Plan cannot fabricate an execution');
expect_build(!$plan['production_authorized'] && !$plan['staging_certified'],
    'Cannot certify release or Production');
expect_build(is_wp_error($class::plan(array('expected_head' => 'main'))), 'Branch not SHA');
expect_build(is_wp_error($class::plan(array('expected_head' => $sha, 'profile' => 'release'))),
    'No arbitrary release mode');
expect_build(is_wp_error($class::plan(array('expected_head' => $sha, 'command' => 'shell'))),
    'Reject command injection field');
$local = $class::plan(array('expected_head' => $sha, 'profile' => 'local-checks'));
expect_build($plan['plan_sha256'] !== $local['plan_sha256'], 'Profile bound in hash');
MAD4B_SCP_Site_Profile::$identity['profile_digest'] = 'digest-b';
$changed = $class::plan(array('expected_head' => $sha, 'profile' => 'build-only'));
expect_build($changed['plan_sha256'] !== $plan['plan_sha256'], 'Site profile drift invalidates plan');
echo "PASS: standalone build MCP discovery, plan binding, refusal and no execution assertions\n";
