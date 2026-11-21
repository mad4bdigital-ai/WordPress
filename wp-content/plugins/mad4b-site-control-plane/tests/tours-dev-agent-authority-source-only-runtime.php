<?php
/** No-network, no-lease source preservation and explicit denial fixture. */
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $code;
    public function __construct($code, $message = '', $data = null) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($result) { return $result instanceof WP_Error; }
function sanitize_key($value) { return strtolower((string) $value); }
function require_assertion($state, $message) {
    if (!$state) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}
class MAD4B_SCP_Policy {
    public static function can_mutate() { return false; }
}
class MAD4B_SCP_Durable_Execution {
    public static $calls = 0;
    public static function acquire_lease() { self::$calls++; return false; }
    public static function heartbeat() { self::$calls++; return false; }
    public static function assert_fencing_token() { self::$calls++; return false; }
}
$plugin = dirname(__DIR__);
$bootstrap = file_get_contents($plugin . '/mad4b-site-control-plane.php');
$source = array(
    'class-mad4b-scp-background-agent-admission.php' => '4db34bdebc6158e460027cd57713223798cc696778cc2ac145954e0092300da0',
    'class-mad4b-scp-background-agent-dispatch.php' => 'd50e226773418c2bcc7e0c9de2088582be37bc5896e8ef5bee63bdec869bae18',
    'class-mad4b-scp-execution-authority.php' => 'a5f006f42e5eb1be37c60193480fdc0182b138e84204980ed6352cce691f3dc6',
);
require_assertion(is_string($bootstrap), 'Candidate bootstrap missing');
foreach ($source as $name => $expected) {
    $file = $plugin . '/includes/' . $name;
    require_assertion(is_file($file) && hash_equals($expected, hash_file('sha256', $file)), 'Original sealed source changed: ' . $name);
    require_assertion(false === strpos($bootstrap, $name), 'New authority module loaded by main bootstrap: ' . $name);
}
foreach (array_keys($source) as $name) require_once $plugin . '/includes/' . $name;
$valid = array(
    'worker_id' => 'worker-a1',
    'job_id' => '11111111-1111-1111-1111-111111111111',
    'plan_sha256' => str_repeat('a', 64), 'lease_epoch' => 1,
);
$admission = MAD4B_SCP_Background_Agent_Admission::check(array());
require_assertion(is_wp_error($admission) && $admission->get_error_code() === 'agent_identity_invalid', 'Reject missing agent identity');
$admission = MAD4B_SCP_Background_Agent_Admission::check($valid);
require_assertion(is_wp_error($admission) && $admission->get_error_code() === 'agent_mutation_not_authorized', 'Reject missing mutation grant');
foreach (array('claim', 'heartbeat', 'verify_commit') as $name) {
    $output = MAD4B_SCP_Background_Agent_Dispatch::$name($valid);
    require_assertion(is_wp_error($output) && $output->get_error_code() === 'agent_mutation_not_authorized', 'Reject dispatch operation: ' . $name);
}
require_assertion(MAD4B_SCP_Durable_Execution::$calls === 0, 'No lease, heartbeat or fencing allowed');
$denied = MAD4B_SCP_Execution_Authority::resolve('unexpected');
require_assertion(is_wp_error($denied) && $denied->get_error_code() === 'mad4b_actor_operation_invalid', 'Reject invalid authority operation');
$denied = MAD4B_SCP_Execution_Authority::resolve('apply');
require_assertion(is_wp_error($denied) && $denied->get_error_code() === 'mad4b_actor_site_not_enrolled', 'Reject absent enrolled site');
require_assertion(MAD4B_SCP_Durable_Execution::$calls === 0, 'No authority mutation performed');
echo "PASS: three SHA256-sealed source files, no bootstrap, grants or lease calls" . PHP_EOL;
