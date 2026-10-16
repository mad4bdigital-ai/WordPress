<?php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['registered_abilities'] = array();
$GLOBALS['registered_actions'] = array();
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['registered_actions'][] = $hook; }
function wp_register_ability( $name, $args ) { $GLOBALS['registered_abilities'][ $name ] = $args; }
function wp_has_ability( $name ) { return isset( $GLOBALS['registered_abilities'][ $name ] ); }
class WP_Error {
    public $code;
    public function __construct( $code, $message ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class MAD4B_SCP_Policy {
    public static function can_read() { return true; }
}
function expect_true( $condition, $label ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: {$label}\n" ); exit(1); }
}
require dirname(__DIR__) . '/includes/class-mad4b-scp-root-cause-resolver.php';
MAD4B_SCP_Root_Cause_Resolver::boot();
MAD4B_SCP_Root_Cause_Resolver::register_ability();
expect_true( wp_has_ability( 'mad4b/root-cause-remediation-plan' ), 'registered read resolver' );
$ability = $GLOBALS['registered_abilities']['mad4b/root-cause-remediation-plan'];
expect_true( $ability['meta']['annotations']['readonly'] === true, 'no mutation surface' );
$evidence = array( 'error_code' => 'wp_not_defined', 'baseline_fails' => true,
    'optimization_bypass_passes' => true, 'optimization_restored_fails' => true,
    'active_optimizers' => array( 'litespeed' ), 'script_owner' => '' );
$case = MAD4B_SCP_Root_Cause_Resolver::assess( $evidence );
expect_true( $case['diagnosis'] === 'REPORTED_REPEATABLE_DIFFERENTIAL', 'differential classified' );
expect_true( $case['cause_confirmed'] === false && $case['mutation_performed'] === false,
    'client evidence cannot certify root cause' );
expect_true( $case['candidate_providers'][0]['provider'] === 'litespeed', 'litespeed suspect' );
expect_true( $case['capability_gap'] === 'SETTINGS_READ_NOT_VERIFIED', 'missing settings read surfaced' );
expect_true( $case['native_first_routes'][0]['route'] === 'provider_native_diagnostics', 'native first' );
$elementor = $evidence;
$elementor['script_owner'] = 'elementor';
$elementor['active_optimizers'] = array();
$elementor['optimization_bypass_passes'] = false;
$case = MAD4B_SCP_Root_Cause_Resolver::assess( $elementor );
expect_true( count( $case['candidate_providers'] ) === 1
    && $case['candidate_providers'][0]['provider'] === 'elementor', 'no false litespeed blame' );
$confounded = $evidence;
$confounded['active_optimizers'] = array( 'litespeed', 'other_optimizer' );
$case = MAD4B_SCP_Root_Cause_Resolver::assess( $confounded );
expect_true( $case['confounding_optimizers_present'] && $case['recommended_next_action']
    === 'isolate_each_optimizer_and_capture_new_browser_evidence', 'reject multiple-cause shortcut' );
foreach ( array(
    array( 'error_code' => 'wp_not_defined', 'baseline_fails' => 1,
        'optimization_bypass_passes' => true ),
    array( 'error_code' => 'wp_not_defined', 'baseline_fails' => true,
        'optimization_bypass_passes' => true, 'allow_write' => true ),
    array( 'error_code' => 'invalid', 'baseline_fails' => true,
        'optimization_bypass_passes' => true ),
) as $invalid ) {
    expect_true( MAD4B_SCP_Root_Cause_Resolver::assess( $invalid ) instanceof WP_Error,
        'reject invalid or privilege injection' );
}
abstract class MAD4B_SCP_Adapter_Base {
    abstract public function id();
    abstract public function label();
    abstract public function is_available();
    abstract public function ability_names();
    abstract public function register_abilities();
    public function runtime_version() { return defined( 'LSCWP_V' ) ? LSCWP_V : ''; }
    protected function add_ability( $name, $label, $method, $permission, $schema = null,
        $surface = 'read', $readonly = true ) {
        $GLOBALS['registered_abilities'][ $name ] = array(
            'surface' => $surface, 'readonly' => $readonly, 'schema' => $schema );
    }
    protected function schema( $p, $r = array() ) { return array( 'properties' => $p, 'required' => $r ); }
    protected function unavailable_error() { return new WP_Error( 'unavailable', 'not available' ); }
}
define( 'LSCWP_V', '7.9.1' );
require dirname(__DIR__) . '/includes/adapters/class-mad4b-scp-litespeed-adapter.php';
$adapter = new MAD4B_SCP_LiteSpeed_Adapter();
$adapter->register_abilities();
foreach ( array( 'litespeed/get-optimization-settings', 'litespeed/diagnose-script-order',
    'litespeed/settings-change-plan', 'litespeed/verify-optimization' ) as $name ) {
    expect_true( isset( $GLOBALS['registered_abilities'][ $name ] ), 'registered ' . $name );
    expect_true( $GLOBALS['registered_abilities'][ $name ]['readonly'] === true,
        'read-only diagnostics ' . $name );
}
$settings = $adapter->get_optimization_settings();
expect_true( $settings['state'] === 'CAPABILITY_GAP' && ! $settings['setting_values_read'],
    'no invented provider setting reads' );
$plan = $adapter->settings_change_plan( array( 'error_code' => 'wp_not_defined' ) );
expect_true( $plan['automatic_write_allowed'] === false && $plan['new_plugin_required'] === false,
    'no unsafe update or new plugin' );
expect_true( $adapter->settings_change_plan( array( 'error_code' => 'wp_not_defined',
    'update_option' => true ) ) instanceof WP_Error, 'reject unapproved writes' );
$runtime = $adapter->diagnose_script_order( array( 'handles' => array( 'wp-hooks', 'elementor-frontend' ) ) );
expect_true( $runtime['browser_order_verified'] === false, 'PHP snapshot not browser certification' );
expect_true( $adapter->diagnose_script_order( array( 'handles' => array( '../unsafe' ) ) )
    instanceof WP_Error, 'invalid handle rejected' );
require dirname(__DIR__) . '/includes/class-mad4b-scp-adaptive-operation-router.php';
$handoff = MAD4B_SCP_Adaptive_Operation_Router::native_first_diagnostic_handoff(
    array( 'task' => 'wp is not defined in the browser' ) );
expect_true( $handoff['diagnostic_ability'] === 'mad4b/root-cause-remediation-plan'
    && $handoff['automatic_mutation_allowed'] === false, 'router uses safe diagnostic handoff' );
expect_true( MAD4B_SCP_Adaptive_Operation_Router::native_first_diagnostic_handoff(
    array( 'task' => 'inspect page title' ) ) === null, 'unrelated task unchanged' );
echo "NATIVE_FIRST_ROOT_CAUSE_RUNTIME_PASS\n";
