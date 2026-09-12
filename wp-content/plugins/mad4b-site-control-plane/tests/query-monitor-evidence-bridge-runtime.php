<?php

define( 'ABSPATH', '/srv/wordpress/' );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.27' );
define( 'QM_VERSION', '4.0.7' );
define( 'PHP_INT_MAX_TEST', PHP_INT_MAX );

$GLOBALS['actions'] = array();
$GLOBALS['option'] = array();
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['actions'][$hook][$priority][] = $callback; }
function remove_action( $hook, $callback, $priority = 10 ) {
    if ( empty( $GLOBALS['actions'][$hook][$priority] ) ) return false;
    foreach ( $GLOBALS['actions'][$hook][$priority] as $i => $registered ) {
        if ( $registered === $callback ) { unset( $GLOBALS['actions'][$hook][$priority][$i] ); return true; }
    }
    return false;
}
function sanitize_key( $v ) { return strtolower( preg_replace('/[^a-z0-9_\-]/i','',(string)$v) ); }
function get_option( $k, $d = false ) { return $GLOBALS['option'] ?: $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['option'] = $v; return true; }
function is_admin() { return true; }

final class MAD4B_SCP_Live_Acceptance_Observer {
    const TELEMETRY_OPTION = 'mad4b_scp_live_acceptance_observation_v1';
    const QUERY_MONITOR_CONTRACT = 'mad4b.query-monitor-regression.v1';
    const MAX_EVENTS = 32;
    const TELEMETRY_TTL = 21600;
    public static function staging_capture_allowed() { return true; }
    public static function build_provenance_status() { return array('build_fingerprint' => str_repeat('a',64)); }
    public static function sanitize_warning_message( $m ) { return (string)$m; }
    public static function classify_warning_for_test( $type, $function, $message, array $trace = array() ) {
        $mad4b = false;
        $callers = array();
        foreach ( $trace as $frame ) {
            if ( ! empty($frame['file']) && 0 === strpos($frame['file'], '/srv/wordpress/wp-content/plugins/mad4b-site-control-plane/') ) $mad4b = true;
            if ( ! empty($frame['function']) ) $callers[] = (!empty($frame['class']) ? $frame['class'].'::' : '').$frame['function'];
        }
        $fluent = false !== stripos($message, 'Action Scheduler');
        if ( $fluent ) return array('bucket'=>'third_party','severity'=>'third_party_non_blocking','component'=>'fluentform','plugin_slug'=>'fluentform','ability_not_found'=>false,'wp_get_ability_missing'=>false,'pre_init_abilities_violation'=>false,'fluentform_action_scheduler'=>true,'callers'=>$callers);
        return array(
            'bucket'=>$mad4b?'mad4b':'unknown','severity'=>$mad4b?'blocking_regression':'observed','component'=>$mad4b?'mad4b-site-control-plane':'','plugin_slug'=>$mad4b?'mad4b-site-control-plane':'',
            'ability_not_found'=>$mad4b && false!==stripos($message,'Ability') && false!==stripos($message,'not found'),
            'wp_get_ability_missing'=>$mad4b && ('wp_get_ability'===$function || false!==stripos($message,'wp_get_ability')),
            'pre_init_abilities_violation'=>false,'fluentform_action_scheduler'=>false,'callers'=>$callers,
        );
    }
    public static function observe_doing_it_wrong() {}
    public static function observe_deprecated_function() {}
    public static function observe_deprecated_argument() {}
    public static function observe_deprecated_hook() {}
    public static function observe_deprecated_class() {}
    public static function flush_observation() {}
}

class FakeFrame { public $id; public $file; public function __construct($id,$file){$this->id=$id;$this->file=$file;} }
class FakeTrace {
    private $frames; public function __construct($frames){$this->frames=$frames;}
    public function get_filtered_trace(){return $this->frames;}
    public function get_caller(){return $this->frames ? $this->frames[0] : false;}
}
class QM_Doing_It_Wrong_Run {
    private $m,$t; public function __construct($m,$t){$this->m=$m;$this->t=$t;}
    public function get_message(){return $this->m;} public function get_trace(){return $this->t;}
}
class QM_Deprecated_Function_Run extends QM_Doing_It_Wrong_Run {}
class FakeData { public $actions = array(); }
class FakeCollector { private $data; public function __construct($d){$this->data=$d;} public function get_data(){return $this->data;} }
class QM_Collectors { public static $collector; public static function get($id){ return 'doing_it_wrong'===$id ? self::$collector : null; } }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-query-monitor-evidence-bridge.php';

$hooks = MAD4B_SCP_Query_Monitor_Evidence_Bridge::legacy_observer_hooks();
foreach ( $hooks as $hook => $method ) add_action($hook, array('MAD4B_SCP_Live_Acceptance_Observer',$method), PHP_INT_MAX, 3);
add_action('shutdown', array('MAD4B_SCP_Live_Acceptance_Observer','flush_observation'), PHP_INT_MAX);
MAD4B_SCP_Query_Monitor_Evidence_Bridge::boot_early();
foreach ( $hooks as $hook => $method ) {
    if ( ! empty($GLOBALS['actions'][$hook][PHP_INT_MAX]) ) { fwrite(STDERR,"FAIL: legacy concerned hook still registered: $hook\n"); exit(1); }
}
if ( ! empty($GLOBALS['actions']['shutdown'][PHP_INT_MAX]) ) { fwrite(STDERR,"FAIL: legacy flush still registered\n"); exit(1); }
if ( empty($GLOBALS['actions']['shutdown'][8]) ) { fwrite(STDERR,"FAIL: bridge shutdown capture missing\n"); exit(1); }

$d = new FakeData();
$d->actions[] = new QM_Doing_It_Wrong_Run(
    'Function wp_get_ability was called incorrectly. Ability "mad4b/test" not found before Abilities init.',
    new FakeTrace(array(new FakeFrame('MAD4B_Real_Source::run()', '/srv/wordpress/wp-content/plugins/mad4b-site-control-plane/includes/real-source.php')))
);
$d->actions[] = new QM_Doing_It_Wrong_Run(
    'Function as_next_scheduled_action was called incorrectly. Action Scheduler data store was not initialized.',
    new FakeTrace(array(new FakeFrame('FluentForm\\Scheduler::run()', '/srv/wordpress/wp-content/plugins/fluentform/app/Scheduler.php')))
);
QM_Collectors::$collector = new FakeCollector($d);
MAD4B_SCP_Query_Monitor_Evidence_Bridge::capture_and_flush();
$t = $GLOBALS['option'];
$assert = function($c,$m){ if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);} };
$assert(1 === $t['observed_request_count'], 'request count');
$assert(1 === $t['counters']['mad4b']['doing_it_wrong'], 'MAD4B warning imported exactly once');
$assert(1 === $t['counters']['mad4b']['ability_not_found'], 'ability-not-found preserved');
$assert(1 === $t['counters']['mad4b']['wp_get_ability_missing'], 'wp_get_ability classification preserved');
$assert(1 === $t['counters']['mad4b']['pre_init_abilities_violation'], 'pre-init semantics preserved at shutdown');
$assert(1 === $t['counters']['third_party']['doing_it_wrong'], 'third-party warning remains third-party');
$assert(1 === $t['counters']['third_party']['fluentform_action_scheduler'], 'FluentForms classification preserved');
$assert('mad4b.query-monitor-collector-bridge.v1' === $t['events'][0]['evidence_source'], 'collector evidence source recorded');
echo "mad4b.query-monitor-collector-bridge.runtime.v1: PASS\n";
