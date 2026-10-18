<?php
/** Hermetic policy isolation: only bundled, reviewed Adapter_Base subclasses. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class MAD4B_SCP_Provider_Compatibility_Certification {}
function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) { return true; }
function add_filter( $hook, $callback = null, $priority = 10, $args = 1 ) { return true; }
function do_action( $hook, $value = null ) { return null; }
function registration_assert( $ok, $why ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $why . "\n" ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-adapter-base.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-adapter-registry.php';
class Unreviewed_Third_Party_Adapter extends MAD4B_SCP_Adapter_Base {
    public function id() { return 'unreviewed'; }
    public function label() { return 'Unreviewed'; }
    public function is_available() { return true; }
    public function ability_names() { return array( 'read'=>array(), 'content'=>array('unreviewed/delete-all') ); }
    public function register_abilities() {}
}
$registry = MAD4B_SCP_Adapter_Registry::instance();
$discovered = MAD4B_SCP_Adapter_Registry::bundled_adapter_classes();
$approved = MAD4B_SCP_Adapter_Registry::approved_bundled_adapter_classes();
registration_assert( in_array( 'MAD4B_SCP_Core_Content_Modeling_Adapter', $discovered, true ),
    'Known bundled adapter dynamically discovered' );
registration_assert( ! in_array( 'Unreviewed_Third_Party_Adapter', $discovered, true ),
    'External subclass cannot pose as bundled adapter' );
registration_assert( ! in_array( 'Unreviewed_Third_Party_Adapter', $approved, true ),
    'Unreviewed external adapter is never silently approved' );
registration_assert( class_exists( 'MAD4B_SCP_Core_Content_Modeling_Adapter', false )
    && is_subclass_of( 'MAD4B_SCP_Core_Content_Modeling_Adapter', 'MAD4B_SCP_Adapter_Base', true ),
    'Exact loaded reviewed adapter subclass identity is accepted without autoload' );
registration_assert( in_array( 'MAD4B_SCP_Core_Content_Modeling_Adapter', $approved, true ),
    'Source-reviewed registration policy is read successfully' );
registration_assert( count( $approved ) >= 18,
    'Reviewed policy retains built-in default adapters even when not loaded in this fixture' );
$registry->register_defaults();
registration_assert( in_array( 'mad4b/plugin-capability-graph', $registry->ability_names( 'read' ), true ),
    'Registered plugin capability graph is discoverable via canonical read inventory' );
registration_assert( count( $registry->all() ) > 0, 'Reviewed default adapters actually register after trust check' );
registration_assert( $registry->get( 'core-content-modeling' ) !== null
    || $registry->get( 'content-modeling' ) !== null,
    'Known bundled functional adapter registered' );
$all = $registry->all();
$first = reset( $all );
registration_assert( ! $registry->register( $first ), 'Duplicate adapter ID cannot silently override' );
registration_assert( ! $registry->register( new Unreviewed_Third_Party_Adapter() ),
    'Unreviewed external Adapter_Base subclass denied even via legacy hook' );
registration_assert( $registry->get( 'unreviewed' ) === null,
    'Unreviewed class cannot auto-mount or register directly' );
echo "DYNAMIC_ADAPTER_REGISTRATION_POLICY: PASS (no implicit mutation grant)\n";
