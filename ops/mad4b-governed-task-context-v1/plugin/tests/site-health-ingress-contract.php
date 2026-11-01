<?php
/* Standalone hermetic ingress test: no real WordPress, grants or Queue writes. */
define('ABSPATH', __DIR__ . '/');
define('DB_NAME', 'etg_commerce_staging');
class WP_Error {
    private $code;
    public function __construct($code, $msg = '', $data = array()) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function wp_get_environment_type() { return $GLOBALS['env'] ?? 'staging'; }
function home_url() { return $GLOBALS['url'] ?? 'https://tours.dev.mad4b.com'; }
function wp_has_ability($x) { return false; }
function add_action($hook, $callback, $priority) { $GLOBALS['hook'] = array($hook, $callback, $priority); }
function wp_register_ability($name, $args) { $GLOBALS['registered'] = array($name, $args); }
class MAD4B_Content_Hub {
    public static function permission() { return $GLOBALS['permitted'] ?? true; }
}
class MAD4B_SCP_Policy {
    public static function can_mutate() { return $GLOBALS['mutate'] ?? true; }
}
class MAD4B_Hub_Bulk_Jobs_Queue {
    public static function writable() { return $GLOBALS['queue_writable'] ?? true; }
    public static function enqueue($data) {
        ++$GLOBALS['enqueue_calls'];
        $GLOBALS['captured'] = $data;
        return array('synthetic_only' => true, 'batch_key' => $data['batch_key']);
    }
}
require dirname(__DIR__) . '/includes/class-mad4b-hub-queue-site-health-enqueue.php';

function must($value, $message) {
    if (!$value) { fwrite(STDERR, "FAILED: " . $message . "\n"); exit(1); }
}
function reset_test() {
    foreach (array('env', 'url', 'permitted', 'mutate', 'queue_writable') as $v) unset($GLOBALS[$v]);
    $GLOBALS['enqueue_calls'] = 0;
}
reset_test();
// No WordPress request actor during catalog initialization: discovery must
// still register the capability, but executing it must be denied.
$GLOBALS['permitted'] = false;
must(MAD4B_Hub_Queue_Site_Health_Enqueue::site_enrolled(), 'site enrollment is request-user neutral');
must(!MAD4B_Hub_Queue_Site_Health_Enqueue::site_ready(), 'execution requires an authorized actor');
MAD4B_Hub_Queue_Site_Health_Enqueue::boot();
must($GLOBALS['hook'][0] === 'wp_abilities_api_init' && $GLOBALS['hook'][2] === 48, 'canonical registration hook');
MAD4B_Hub_Queue_Site_Health_Enqueue::register();
must(isset($GLOBALS['registered']), 'ability registered with no request actor');
must(is_wp_error(MAD4B_Hub_Queue_Site_Health_Enqueue::execute(
    array('batch_key' => 'qa.site-health.vps.20261101-denial-check01')
)), 'unauthorized execution fails closed');
must($GLOBALS['enqueue_calls'] === 0, 'denied request did not enqueue');
reset_test();
list($ability, $args) = $GLOBALS['registered'];
must($ability === 'mad4b/hub-queue-site-health-enqueue', 'specific ability');
must($args['category'] === 'mad4b-write', 'write category');
must($args['meta']['mcp']['surface'] === 'write', 'write surface');
must($args['meta']['mcp']['mad4b_governed_write_authority'] === true, 'original execution boundary');
must($args['input_schema']['additionalProperties'] === false, 'exact schema');
must($args['meta']['public'] === false, 'no public exposure');

$key = 'qa.site-health.vps.20261101-local-check01';
$out = MAD4B_Hub_Queue_Site_Health_Enqueue::execute(array('batch_key' => $key));
must(!is_wp_error($out) && $out['batch_key'] === $key, 'one diagnostic request');
must($GLOBALS['enqueue_calls'] === 1, 'exactly one internal service request');
$b = $GLOBALS['captured'];
must(count($b['items']) === 1 && $b['max_concurrency'] === 1, 'bounded concurrency');
$t = $b['items'][0];
must($t['operation'] === 'site_health' && $t['stage'] === 'SOURCE_DISCOVERY', 'diagnostic only');
must($t['payload']['assigned_executor'] === 'vps_runner', 'not mcp_client');
must($t['job_id'] === '' && $t['depends_on'] === array(), 'no content job or dependencies');
must($t['target_ref'] === 'site:https://tours.dev.mad4b.com', 'fixed site target');
must($t['max_attempts'] === 1 && $t['payload']['no_automatic_publish'] === true, 'bounded attempt and publication denied');

$bad = array(
    array(),
    array('batch_key' => 'generic.enqueue'),
    array('batch_key' => $key, 'executor' => 'mcp_client'),
    array('batch_key' => $key, 'operation' => 'publish'),
    array('batch_key' => $key, 'items' => array()),
    array('batch_key' => $key, 'job_id' => 'fake'),
    array('batch_key' => $key, 'approval_ticket_id' => 'forged'),
    array('batch_key' => $key, 'target_ref' => 'site:https://bad.example'),
    array('batch_key' => array()),
);
foreach ($bad as $request) {
    reset_test();
    must(is_wp_error(MAD4B_Hub_Queue_Site_Health_Enqueue::execute($request)), 'invalid request rejected');
    must($GLOBALS['enqueue_calls'] === 0, 'invalid request did not enqueue');
}
foreach (array(
    array('env' => 'production'),
    array('url' => 'https://other.example'),
    array('permitted' => false),
    array('mutate' => false),
    array('queue_writable' => false),
) as $case) {
    reset_test();
    foreach ($case as $k => $v) $GLOBALS[$k] = $v;
    must(is_wp_error(MAD4B_Hub_Queue_Site_Health_Enqueue::execute(array('batch_key' => $key))), 'site or policy denied');
    must($GLOBALS['enqueue_calls'] === 0, 'site or policy denial had no write');
}
echo "SITE_HEALTH_INGRESS_HERMETIC_PASS\n";
