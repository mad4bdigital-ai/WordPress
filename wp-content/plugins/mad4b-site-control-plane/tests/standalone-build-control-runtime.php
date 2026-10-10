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
expect_build(count($GLOBALS['build_abilities']) === 3, 'Idempotent read and owner-gated request registration');
foreach (array('mad4b/standalone-build-discover', 'mad4b/standalone-build-plan') as $ability_name) {
    $def = $GLOBALS['build_abilities'][$ability_name];
    expect_build($def['category'] === 'mad4b-read', 'Only read category');
    expect_build($def['meta']['annotations']['readonly'] === true, 'Read-only tool');
    expect_build($def['input_schema']['additionalProperties'] === false, 'No extra inputs');
}
$requestDef = $GLOBALS['build_abilities']['mad4b/standalone-build-request'];
expect_build($requestDef['category'] === 'mad4b-admin', 'Request uses admin governed surface');
expect_build($requestDef['meta']['annotations']['readonly'] === false, 'Queue write is not mislabeled readonly');
expect_build($requestDef['input_schema']['additionalProperties'] === false, 'No extra request fields');
expect_build(is_wp_error($class::request(array('command' => 'shell'))), 'Unsafe request rejected');
expect_build(is_wp_error($class::complete_signed_job(array(), array())), 'Unbound receipt rejected');
expect_build($class::valid_job_payload(array('shell' => 'php')) === false,
    'Arbitrary executable payload rejected');
$workOps = $class::work_definitions(array());
expect_build(isset($workOps['standalone_source_build']), 'Single semantic operation registered');
expect_build($workOps['standalone_source_build']['production_policy'] === 'deny',
    'Remote build operation never runs Production');
$status = $class::discover(array());
expect_build($status['mcp_dispatch_implemented'] === true &&
    $status['mcp_dispatch_mode'] === 'owner_approved_semantic_work_queue',
    'Only owner-gated semantic queue is implemented');
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

/* Real Ed25519 verification: synthetic Staging lease, no WordPress mutation. */
if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "BLOCKED: sodium extension required for signed build receipt tests\n");
    exit(2);
}
function wp_get_environment_type() { return $GLOBALS['wp_env'] ?? 'staging'; }
class MAD4B_SCP_Remote_Work_Queue {
    public static $seen = null;
    public static function complete($job, $executor, $lease, array $proof) {
        self::$seen = array('job' => $job, 'executor' => $executor, 'proof' => $proof);
        return array('state' => 'completed', 'job' => array('job_id' => $job));
    }
}
$keys = sodium_crypto_sign_keypair();
define('MAD4B_SCP_STANDALONE_BUILDER_PUBLIC_KEY_B64',
    base64_encode(sodium_crypto_sign_publickey($keys)));
define('MAD4B_SCP_STANDALONE_BUILDER_EXECUTOR_ID', 'runner_staging_01');
MAD4B_SCP_Site_Profile::$identity['configured_environment'] = 'staging';
MAD4B_SCP_Site_Profile::$identity['authority_ready'] = true;
MAD4B_SCP_Site_Profile::$identity['origin_match'] = true;
$now = time();
$job = array(
    'operation_id' => $class::OPERATION,
    'job_id' => '11111111-1111-4111-8111-111111111111',
    'status' => 'claimed', 'executor_id' => 'runner_staging_01',
    'claim_generation' => 1, 'lease_expires_at_epoch' => $now + 600,
    'provider_checkpoint' => 'provider_returned', 'cancel_requested_at' => '',
    'payload' => array(
        'expected_head' => $sha, 'profile' => 'build-only',
        'plan_sha256' => str_repeat('b', 64),
        'site_uuid' => 'site-1', 'profile_digest' => 'digest-b',
        'origin' => 'https://staging.example.test'
    )
);
$claims = array(
    'contract' => $class::CONTRACT . '.receipt.v1',
    'job_id' => $job['job_id'], 'executor_id' => 'runner_staging_01',
    'claim_generation' => 1, 'target_source_sha' => $sha,
    'plan_sha256' => str_repeat('b', 64), 'profile' => 'build-only',
    'site_uuid' => 'site-1', 'profile_digest' => 'digest-b',
    'origin' => 'https://staging.example.test',
    'archive_sha256' => str_repeat('c', 64),
    'build_fingerprint' => str_repeat('d', 64),
    'package_manifest_digest' => str_repeat('e', 64),
    'build_state' => 'BUILT_UNVERIFIED', 'issued_at_epoch' => $now,
    'expires_at_epoch' => $now + 300, 'production_authorized' => false
);
$raw = json_encode($claims, JSON_UNESCAPED_SLASHES);
$receipt = array(
    'claims_b64' => base64_encode($raw),
    'signature_b64' => base64_encode(sodium_crypto_sign_detached(
        $raw, sodium_crypto_sign_secretkey($keys)))
);
$input = array('job_id' => $job['job_id'], 'executor_id' => 'runner_staging_01',
    'lease_token' => str_repeat('f', 64), 'build_receipt' => $receipt);
$done = $class::complete_signed_job($input, $job);
expect_build(!is_wp_error($done) && $done['state'] === 'completed',
    'pinned signature fixture must complete bounded job');
expect_build(MAD4B_SCP_Remote_Work_Queue::$seen['proof']['build_state'] === 'BUILT_UNVERIFIED' &&
    MAD4B_SCP_Remote_Work_Queue::$seen['proof']['production_authorized'] === false,
    'receipt may not certify or promote');
$bad = $input;
$bad['build_receipt']['signature_b64'] = base64_encode(str_repeat('x', 64));
expect_build(is_wp_error($class::complete_signed_job($bad, $job)),
    'wrong Ed25519 signature denied');
$wrongGeneration = $job;
$wrongGeneration['claim_generation'] = 2;
expect_build(is_wp_error($class::complete_signed_job($input, $wrongGeneration)),
    'stale claim generation denied');
$cancelled = $job;
$cancelled['cancel_requested_at'] = '2026-10-11T00:00:00Z';
expect_build(is_wp_error($class::complete_signed_job($input, $cancelled)),
    'canceled lease denied');
$GLOBALS['wp_env'] = 'production';
expect_build(is_wp_error($class::complete_signed_job($input, $job)),
    'actual Production runtime denied');
$GLOBALS['wp_env'] = 'staging';
echo "PASS: standalone build MCP, signed runner receipt, lease and refusal assertions\n";
