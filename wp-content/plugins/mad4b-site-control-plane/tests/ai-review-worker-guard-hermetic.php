<?php
/** Source-only, network-free guard contract; not original Agent or live WP acceptance. */
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $code;
    public function __construct($code, $message = '', $data = null) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
$GLOBALS['wp_hooks'] = array();
$GLOBALS['wp_options'] = array();
$GLOBALS['local_env'] = 'production';
$GLOBALS['bound'] = false;
$GLOBALS['admin'] = false;
$GLOBALS['enrolled'] = false;
$GLOBALS['model_enabled'] = true;
$GLOBALS['credentials'] = true;
$GLOBALS['identity'] = true;
$GLOBALS['ai_policy'] = 'ai_only';
function add_action($name, $callable) { $GLOBALS['wp_hooks'][$name] = $callable; }
function current_user_can($cap) { return $GLOBALS['admin']; }
function get_current_user_id() { return 11; }
function wp_get_environment_type() { return $GLOBALS['local_env']; }
function get_option($name, $default = null) { return $GLOBALS['wp_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = false) { $GLOBALS['wp_options'][$name] = $value; return true; }
function register_rest_route($namespace, $route, $args) { $GLOBALS['rest_route'] = array($namespace, $route, $args); }
class MAD4B_SCP_AI_Model_Router {
    const OPTION = 'test_router';
    public static function settings() { return array('enabled' => $GLOBALS['model_enabled'], 'max_jobs_per_run' => 1, 'mode' => 'test'); }
    public static function provider_status() { return array('credential_ready' => $GLOBALS['credentials']); }
}
class MAD4B_SCP_Local_Client { public static function bound() { return $GLOBALS['bound']; } }
class MAD4B_SCP_Context_Authority {
    public static function review_policy() { return array('mode' => $GLOBALS['ai_policy']); }
}
class MAD4B_SCP_AI_Only_Local_Activation {
    public static function status() { return array('identity_ready' => $GLOBALS['identity']); }
    public static function site_bound() {
        return $GLOBALS['local_env']==='local' ? $GLOBALS['bound'] : !empty($GLOBALS['crossenv_bound']);
    }
}
class MAD4B_SCP_Policy {
    public static function can_connect_user($id) { return $GLOBALS['enrolled'] && $id === 11; }
}
require dirname(__DIR__) . '/includes/class-mad4b-scp-ai-review-worker.php';
$passed = 0;
function check($name, $ok) {
    global $passed;
    if (!$ok) { fwrite(STDERR, 'FAIL ' . $name . PHP_EOL); exit(1); }
    $passed++;
}
$pre = MAD4B_SCP_AI_Review_Worker::preflight();
check('production denied', !$pre['ready'] && !$pre['publication_authorized']);
$res = MAD4B_SCP_AI_Review_Worker::run_once();
check('blocked run no mutation', $res['status'] === 'blocked' && !$res['mutation_performed']);
$GLOBALS['local_env'] = 'local';
check('unbound client denied', !MAD4B_SCP_AI_Review_Worker::preflight()['ready']);
$GLOBALS['bound'] = true;
$GLOBALS['ai_policy'] = 'human_first';
check('human-first policy denied', !MAD4B_SCP_AI_Review_Worker::preflight()['ready']);
$GLOBALS['ai_policy'] = 'ai_only';
$GLOBALS['identity'] = false;
check('unverified agent denied', !MAD4B_SCP_AI_Review_Worker::preflight()['ready']);
$GLOBALS['identity'] = true;
$GLOBALS['model_enabled'] = false;
check('disabled model denied', !MAD4B_SCP_AI_Review_Worker::preflight()['ready']);
$GLOBALS['model_enabled'] = true;
$GLOBALS['credentials'] = false;
check('missing credential denied', !MAD4B_SCP_AI_Review_Worker::preflight()['ready']);
$GLOBALS['credentials'] = true;
check('fully enrolled local preflight', MAD4B_SCP_AI_Review_Worker::preflight()['ready']);
$GLOBALS['local_env']='staging';
check('staging without scope rejected', !MAD4B_SCP_AI_Review_Worker::preflight()['ready']);
$GLOBALS['crossenv_bound']=true;
check('staging explicitly scoped allowed', MAD4B_SCP_AI_Review_Worker::preflight()['ready']
 && !MAD4B_SCP_AI_Review_Worker::preflight()['local_scope']
 && MAD4B_SCP_AI_Review_Worker::preflight()['site_scope']);
$GLOBALS['local_env']='production';
check('production scoped review allowed', MAD4B_SCP_AI_Review_Worker::preflight()['ready']);
$GLOBALS['identity']=false;
check('production missing agent identity denied', !MAD4B_SCP_AI_Review_Worker::preflight()['ready']);
$GLOBALS['identity']=true;
$GLOBALS['local_env']='local';
$evidence = array('normalized_text' => "A\nB\nC");
$valid = array(
 'decision' => 'approve', 'confidence' => 0.92,
 'review_note' => str_repeat('Evidence is clear and source is bounded. ', 2),
 'evidence_lines' => array(1, 2)
);
$ok = MAD4B_SCP_AI_Review_Worker::parse_verdict(json_encode($valid), $evidence);
check('bounded verdict parsed', is_array($ok) && $ok['decision'] === 'approve');
$bad = $valid; $bad['deployment_authorized'] = true;
check('unknown authority field denied', is_wp_error(MAD4B_SCP_AI_Review_Worker::parse_verdict(json_encode($bad), $evidence)));
$bad = $valid; $bad['confidence'] = 0.2;
check('low confidence approval denied', is_wp_error(MAD4B_SCP_AI_Review_Worker::parse_verdict(json_encode($bad), $evidence)));
$bad = $valid; $bad['evidence_lines'] = array(1, 1);
check('duplicate citations denied', is_wp_error(MAD4B_SCP_AI_Review_Worker::parse_verdict(json_encode($bad), $evidence)));
$bad = $valid; $bad['evidence_lines'] = array(1, 99);
check('nonexistent evidence line denied', is_wp_error(MAD4B_SCP_AI_Review_Worker::parse_verdict(json_encode($bad), $evidence)));
$bad = $valid; $bad['evidence_lines'] = array('1', 2);
check('string citation denied', is_wp_error(MAD4B_SCP_AI_Review_Worker::parse_verdict(json_encode($bad), $evidence)));
check('malformed JSON denied', is_wp_error(MAD4B_SCP_AI_Review_Worker::parse_verdict('not-json', $evidence)));
check('oversized JSON denied', is_wp_error(MAD4B_SCP_AI_Review_Worker::parse_verdict(str_repeat('x', 24001), $evidence)));
$bad = $valid; $bad['revised_text'] = str_repeat('x', 180001);
check('oversized revision denied', is_wp_error(MAD4B_SCP_AI_Review_Worker::parse_verdict(json_encode($bad), $evidence)));
check('anonymous REST denied', !MAD4B_SCP_AI_Review_Worker::rest_permission());
$GLOBALS['admin'] = true;
check('unenrolled admin REST denied', !MAD4B_SCP_AI_Review_Worker::rest_permission());
$GLOBALS['enrolled'] = true;
check('enrolled admin REST accepted', MAD4B_SCP_AI_Review_Worker::rest_permission());
MAD4B_SCP_AI_Review_Worker::register_rest();
check('REST original permission attached', $GLOBALS['rest_route'][0] === 'mad4b'
 && $GLOBALS['rest_route'][1] === '/ai/review-cycle'
 && $GLOBALS['rest_route'][2]['permission_callback'][1] === 'rest_permission');
$GLOBALS['admin'] = false;
MAD4B_SCP_AI_Review_Worker::guarded_action();
check('unauthorized action does not mutate', $GLOBALS['wp_options'] === array());
echo 'PASS: ' . $passed . ' source-only checks' . PHP_EOL;
