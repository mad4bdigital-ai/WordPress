<?php
/** Hermetic integration of the real main bootstrap contract and both GA/GB hooks.
 * This is not a substitute for disposable WordPress/MCP certification.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['assistant_hooks'] = array();
$GLOBALS['assistant_abilities'] = array();
$GLOBALS['assistant_register_calls'] = 0;
function add_action( $hook, $callback, $priority = 10 ) {
    $GLOBALS['assistant_hooks'][ $hook ][] = array( 'callback' => $callback, 'priority' => $priority );
    return true;
}
function wp_register_ability( $name, $args ) {
    ++$GLOBALS['assistant_register_calls'];
    $GLOBALS['assistant_abilities'][ $name ] = $args;
    return true;
}
function wp_has_ability( $name ) { return isset( $GLOBALS['assistant_abilities'][ $name ] ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class MAD4B_SCP_Adapter_Base {}
class AssistantEntryRegistry {
    public $registered = array();
    public function register( $adapter ) {
        if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base ) return false;
        $this->registered[ $adapter->id() ] = $adapter;
        return true;
    }
}
function assistant_check( $value, $message ) {
    if ( ! $value ) { fwrite( STDERR, 'FAIL ' . $message . PHP_EOL ); exit( 1 ); }
}
function assistant_action( $name, $argument = null ) {
    $hooks = isset( $GLOBALS['assistant_hooks'][ $name ] ) ? $GLOBALS['assistant_hooks'][ $name ] : array();
    usort( $hooks, static function ( $left, $right ) { return $left['priority'] <=> $right['priority']; } );
    foreach ( $hooks as $row ) {
        if ( 'mad4b_scp_register_adapters' === $name ) call_user_func( $row['callback'], $argument );
        else call_user_func( $row['callback'] );
    }
}
$plugin = dirname( __DIR__ );
$main = file_get_contents( $plugin . '/mad4b-site-control-plane.php' );
$agent = file_get_contents( $plugin . '/includes/class-mad4b-scp-agent-registry.php' );
assistant_check( is_string( $main ) && is_string( $agent ), 'bootstrap sources readable' );
foreach ( array( 'class-mad4b-scp-assistant-planning.php', 'class-mad4b-scp-assistant-bootstrap-diagnostic.php',
                 'MAD4B_SCP_Assistant_Planning::boot();', 'MAD4B_SCP_Assistant_Bootstrap_Diagnostic::boot();' ) as $needle ) {
    assistant_check( false !== strpos( $main, $needle ), 'entrypoint loads and boots: ' . $needle );
}
assistant_check( false === strpos( $agent, 'MAD4B_SCP_Assistant_Planning::boot();' ) &&
    false === strpos( $agent, 'MAD4B_SCP_Assistant_Bootstrap_Diagnostic::boot();' ),
    'agent registry cannot register read tools by implicit side effect' );
require_once $plugin . '/includes/class-mad4b-scp-assistant-planning.php';
require_once $plugin . '/includes/class-mad4b-scp-assistant-bootstrap-diagnostic.php';
MAD4B_SCP_Assistant_Planning::boot();
MAD4B_SCP_Assistant_Bootstrap_Diagnostic::boot();
assistant_check( 2 === count( $GLOBALS['assistant_hooks']['wp_abilities_api_init'] ), 'both native WordPress Abilities callbacks bound' );
assistant_check( 2 === count( $GLOBALS['assistant_hooks']['mad4b_scp_register_adapters'] ), 'both governed adapters callbacks bound' );
$registry = new AssistantEntryRegistry();
assistant_action( 'mad4b_scp_register_adapters', $registry );
assistant_check( isset( $registry->registered['assistant-planning'], $registry->registered['assistant-bootstrap'] ), 'both adapters registered' );
assistant_action( 'wp_abilities_api_init' );
assistant_check( 2 === $GLOBALS['assistant_register_calls'], 'two read abilities registered exactly once' );
foreach ( array( 'mad4b/assistant-plan', 'mad4b/assistant-bootstrap-diagnostic' ) as $name ) {
    assistant_check( isset( $GLOBALS['assistant_abilities'][ $name ] ), 'native ability registered: ' . $name );
    $ability = $GLOBALS['assistant_abilities'][ $name ];
    assistant_check( true === $ability['meta']['annotations']['readonly'] &&
        false === $ability['meta']['annotations']['destructive'] &&
        false === $ability['meta']['public'] &&
        false === $ability['meta']['mcp']['public'] &&
        'read' === $ability['meta']['mcp']['surface'] &&
        $ability['permission_callback'] === array( 'MAD4B_SCP_Policy', 'can_read' ),
        'read-only, private, governed identity: ' . $name );
    assistant_check( wp_has_ability( $name ), 'catalog lookup: ' . $name );
}
foreach ( $registry->registered as $adapter ) {
    $names = $adapter->ability_names();
    assistant_check( array() === $names['content'] && array() === $names['admin'] &&
        1 === count( $names['read'] ), 'no executable abilities in assistant adapter' );
    $adapter->register_abilities();
}
assistant_check( 2 === $GLOBALS['assistant_register_calls'], 'adapter lifecycle does not duplicate WordPress Ability definitions' );
echo 'ASSISTANT_ENTRYPOINT_REGISTRATION: PASS' . PHP_EOL;
