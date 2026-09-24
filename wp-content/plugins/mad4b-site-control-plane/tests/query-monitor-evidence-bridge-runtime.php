<?php

define( 'ABSPATH', '/srv/wordpress/' );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.27' );
define( 'QM_VERSION', '4.0.7' );
define( 'PHP_INT_MAX_TEST', PHP_INT_MAX );

$GLOBALS['actions'] = array();
$GLOBALS['option'] = array();
$GLOBALS['current_build_fingerprint'] = str_repeat('a',64);
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['actions'][$hook][$priority][] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['mad4b_qm_filters'][$hook][$priority][] = $callback; return true; }
function remove_action( $hook, $callback, $priority = 10 ) {
    if ( empty( $GLOBALS['actions'][$hook][$priority] ) ) return false;
    foreach ( $GLOBALS['actions'][$hook][$priority] as $i => $registered ) {
        if ( $registered === $callback ) { unset( $GLOBALS['actions'][$hook][$priority][$i] ); return true; }
    }
    return false;
}
function sanitize_key( $v ) { return strtolower( preg_replace('/[^a-z0-9_\-]/i','',(string)$v) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function get_option( $k, $d = false ) { return $GLOBALS['option'] ?: $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['option'] = $v; return true; }
function is_admin() { return false; }
function get_num_queries() { return 37; }
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 0.125;

final class MAD4B_SCP_Live_Acceptance_Observer {
    const TELEMETRY_OPTION = 'mad4b_scp_live_acceptance_observation_v1';
    const QUERY_MONITOR_CONTRACT = 'mad4b.query-monitor-regression.v1';
    const MAX_EVENTS = 32;
    const TELEMETRY_TTL = 21600;
    public static function staging_capture_allowed() { return true; }
    public static function build_provenance_status() { return array('build_fingerprint' => $GLOBALS['current_build_fingerprint']); }
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
class FakeComponent { public $type; private $name; public function __construct($type,$name){$this->type=$type;$this->name=$name;} public function get_name(){return $this->name;} }
class FakeTrace {
    private $frames; private $component; public function __construct($frames,$component=null){$this->frames=$frames;$this->component=$component;}
    public function get_filtered_trace(){return $this->frames;}
    public function get_caller(){return $this->frames ? $this->frames[0] : false;}
    public function get_component(){return $this->component ?: new FakeComponent('unknown','Unknown');}
}
class QM_Doing_It_Wrong_Run {
    private $m,$t; public function __construct($m,$t){$this->m=$m;$this->t=$t;}
    public function get_message(){return $this->m;} public function get_trace(){return $this->t;}
}
class QM_Deprecated_Function_Run extends QM_Doing_It_Wrong_Run {}
class FakeData { public $actions = array(); }
class FakeCollector { private $data; public function __construct($d){$this->data=$d;} public function get_data(){return $this->data;} }
class QM_Collectors { public static $collector; public static function get($id){ return 'doing_it_wrong'===$id ? self::$collector : null; } }

$GLOBALS['wpdb'] = (object) array(
    'queries' => array(
        array( 'SELECT 1', 0.010, 'Core_A::run', 'trace' => new FakeTrace(array(new FakeFrame('Alpha\\Reader::load()', '/srv/wordpress/wp-content/plugins/alpha/read.php')), new FakeComponent('plugin','Alpha')) ),
        array( 'SELECT 1', 0.012, 'Core_A::run', 'trace' => new FakeTrace(array(new FakeFrame('Alpha\\Reader::load()', '/srv/wordpress/wp-content/plugins/alpha/read.php')), new FakeComponent('plugin','Alpha')) ),
        array( 'SELECT 2', 0.060, 'Core_B::run', 'trace' => new FakeTrace(array(new FakeFrame('Beta\\Query::load()', '/srv/wordpress/wp-content/plugins/beta/query.php')), new FakeComponent('plugin','Beta')) ),
        array( 'SELECT 3', 0.020, 'Core_C::run' ),
    ),
);

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

$check = function($c,$m){ if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);} };
$check(str_repeat('a',64) === MAD4B_SCP_Query_Monitor_Evidence_Bridge::request_build_fingerprint_for_test(), 'request build fingerprint must pin at request bootstrap');
$GLOBALS['current_build_fingerprint'] = str_repeat('b',64);
$check(str_repeat('a',64) === MAD4B_SCP_Query_Monitor_Evidence_Bridge::request_build_fingerprint_for_test(), 'mid-request provenance replacement must not change pinned build identity');

$d = new FakeData();
$d->actions[] = new QM_Doing_It_Wrong_Run(
    'Function wp_get_ability was called incorrectly. Ability "mad4b/test" not found before Abilities init.',
    new FakeTrace(array(new FakeFrame('MAD4B_Real_Source::run()', '/srv/wordpress/wp-content/plugins/mad4b-site-control-plane/includes/real-source.php')))
);
for ( $i = 0; $i < 40; $i++ ) {
    $d->actions[] = new QM_Doing_It_Wrong_Run(
        'Function as_next_scheduled_action was called incorrectly. Action Scheduler data store was not initialized.',
        new FakeTrace(array(new FakeFrame('FluentForm\\Scheduler::run()', '/srv/wordpress/wp-content/plugins/fluentform/app/Scheduler.php')))
    );
}
QM_Collectors::$collector = new FakeCollector($d);
MAD4B_SCP_Query_Monitor_Evidence_Bridge::capture_and_flush();
$t = $GLOBALS['option'];
$check(str_repeat('a',64) === $t['build_fingerprint'], 'shutdown telemetry must stay bound to request-start build identity');
$check(1 === $t['observed_request_count'], 'request count');
$check(1 === $t['counters']['mad4b']['doing_it_wrong'], 'MAD4B warning imported exactly once');
$check(1 === $t['counters']['mad4b']['ability_not_found'], 'ability-not-found preserved');
$check(1 === $t['counters']['mad4b']['wp_get_ability_missing'], 'wp_get_ability classification preserved');
$check(1 === $t['counters']['mad4b']['pre_init_abilities_violation'], 'pre-init semantics preserved at shutdown');
$check(40 === $t['counters']['third_party']['doing_it_wrong'], 'third-party warning remains third-party');
$check(40 === $t['counters']['third_party']['fluentform_action_scheduler'], 'FluentForms classification preserved');
$check('mad4b.query-monitor-collector-bridge.v1' === $t['events'][0]['evidence_source'], 'collector evidence source recorded');
$check(32 === count($t['events']), 'global event ring remains bounded to 32');
$global_mad4b = array_values(array_filter($t['events'], function($event){ return isset($event['classification']) && 'mad4b' === $event['classification']; }));
$check(0 === count($global_mad4b), 'fixture must prove global ring can evict older MAD4B detail');
$check(isset($t['events_by_bucket']['mad4b']) && 1 === count($t['events_by_bucket']['mad4b']), 'MAD4B bucket ring must retain its event independently');
$check('doing_it_wrong' === $t['events_by_bucket']['mad4b'][0]['type'], 'retained MAD4B event type drifted');
$check(isset($t['events_by_bucket']['third_party']) && 32 === count($t['events_by_bucket']['third_party']), 'third-party bucket ring must remain independently bounded');
$check(!empty($t['performance']['frontend_observed']), 'frontend performance evidence observed');
$check(isset($t['performance']['last_by_class']['frontend']), 'frontend performance last sample present');
$perf = $t['performance']['last_by_class']['frontend'];
$check(37 === $perf['db_queries'], 'frontend DB query count captured');
$check($perf['peak_memory_bytes'] > 0, 'frontend peak memory captured');
$check($perf['server_elapsed_ms'] >= 100, 'frontend server elapsed captured');
$check(isset($perf['db_profile']) && !empty($perf['db_profile']['available']), 'DB performance profile available');
$check(4 === $perf['db_profile']['query_rows_observed'], 'DB performance profile query rows');
$check(1 === $perf['db_profile']['duplicate_query_count'], 'duplicate query count derived');
$check(1 === $perf['db_profile']['duplicate_group_count'], 'duplicate query group derived');
$check(1 === $perf['db_profile']['slow_query_count'], 'slow query count derived');
$check($perf['db_profile']['total_db_time_ms'] >= 102, 'DB time aggregate derived');
$check(3 === $perf['db_profile']['extended_trace_count'], 'extended trace count derived');
$check('Alpha\\Reader::load' === $perf['db_profile']['top_callers'][0]['caller'], 'top caller attribution derived');
$check('plugin:Alpha' === $perf['db_profile']['top_components'][0]['component'], 'top component attribution derived');
$check(false === strpos(json_encode($perf['db_profile']), 'SELECT 1'), 'raw SQL must not be returned in profile');
$check(empty($perf['db_profile']['raw_sql_returned']), 'raw SQL safety flag must remain false');
echo "mad4b.query-monitor-collector-bridge.runtime.v4: PASS\n";
