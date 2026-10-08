<?php
/** Hermetic assistant operator workspace permission/escaping checks. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ );
$GLOBALS['allowed'] = false; $GLOBALS['hooks'] = array(); $GLOBALS['menu'] = array();
function current_user_can( $name ) { return $GLOBALS['allowed'] && 'manage_options' === $name; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function add_action( $hook, $cb, $priority = 10 ) { $GLOBALS['hooks'][ $hook ] = $cb; }
function add_submenu_page( ...$args ) { $GLOBALS['menu'] = $args; }
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class MAD4B_SCP_Assistant_Read_Work_Operations {
    const OPERATIONS = array( 'assistant_provider_catalog_snapshot', 'assistant_dependency_readback', 'assistant_configuration_diff' );
}
class MAD4B_SCP_Assistant_Bootstrap_Diagnostic {
    public static $value;
    public static function status() { return self::$value; }
}
class MAD4B_SCP_Remote_Work_Queue {
    public static function list_jobs( $op ) { return array( 'count' => 8, 'items' => array() ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-assistant-operator-workspace.php';
$GLOBALS['asserts'] = 0;
function check_case( $name, $ok ) {
    ++$GLOBALS['asserts'];
    if ( ! $ok ) { fwrite( STDERR, 'FAIL ' . $name . PHP_EOL ); exit( 1 ); }
    echo 'PASS ' . $name . PHP_EOL;
}
check_case( 'administrator permission enforced', is_wp_error( MAD4B_SCP_Assistant_Operator_Workspace::snapshot() ) );
MAD4B_SCP_Assistant_Operator_Workspace::boot();
check_case( 'admin lifecycle attached', isset( $GLOBALS['hooks']['admin_menu'] ) );
$GLOBALS['allowed'] = true;
MAD4B_SCP_Assistant_Operator_Workspace::register_menu();
check_case( 'menu requires manage_options', 'manage_options' === $GLOBALS['menu'][3] );
MAD4B_SCP_Assistant_Bootstrap_Diagnostic::$value = array(
    'environment' => 'staging', 'preview_eligible' => true,
    'blockers' => array( 'restore_epoch_unverified', '<script>invalid</script>' ) );
$view = MAD4B_SCP_Assistant_Operator_Workspace::snapshot();
check_case( 'workspace no mutation', $view['read_only'] && ! $view['mutation_performed'] && ! $view['provider_install_allowed'] );
check_case( 'preview not execution', $view['preview_eligible'] && ! $view['task_execution_ready'] );
check_case( 'invalid blocker suppressed', array( 'restore_epoch_unverified' ) === $view['blockers'] );
check_case( 'bounded read queues', 3 === count( $view['read_work_counts'] ) && 8 === $view['read_work_counts']['assistant_provider_catalog_snapshot'] );
ob_start(); MAD4B_SCP_Assistant_Operator_Workspace::render(); $html = ob_get_clean();
check_case( 'unsafe HTML not rendered', false === strpos( $html, '<script>' ) );
check_case( 'no mutation controls', false === strpos( $html, '<form' ) && false === strpos( $html, '<button' ) );
check_case( 'independent authority distinguished', false !== strpos( $html, 'Not granted' ) );
MAD4B_SCP_Assistant_Bootstrap_Diagnostic::$value = new WP_Error( 'unavailable' );
check_case( 'missing bootstrap evidence fails closed',
    in_array( 'bootstrap_read_unavailable', MAD4B_SCP_Assistant_Operator_Workspace::snapshot()['blockers'], true ) );
echo 'ASSISTANT_OPERATOR_WORKSPACE: PASS ' . $GLOBALS['asserts'] . ' assertions' . PHP_EOL;
