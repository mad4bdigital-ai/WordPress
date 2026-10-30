<?php
/**
 * Hermetic adapter catalog regression:
 * an absent optional provider must never advertise unregistered MCP abilities.
 * The available provider must keep its exact reviewed 11 reads and 3 governed
 * writes. No WP bootstrap, network, SQL, privileges, or mutation.
 */
define( 'ABSPATH', '/isolated-wordpress/' );
define( 'WP_PLUGIN_DIR', '/isolated-wordpress/wp-content/plugins' );
$GLOBALS['cmb_plugin_active'] = false;
$GLOBALS['cmb_registered_names'] = array();

function get_option( $key, $default = false ) {
    return 'active_plugins' === $key && $GLOBALS['cmb_plugin_active']
        ? array( 'customer-messaging-bridge/customer-messaging-bridge.php' )
        : $default;
}
abstract class MAD4B_SCP_Adapter_Base {
    abstract public function id();
    abstract public function label();
    abstract public function is_available();
    abstract public function ability_names();
    abstract public function register_abilities();
    protected function schema( $properties, $required = array() ) {
        return array( 'type' => 'object', 'properties' => $properties, 'required' => $required );
    }
    protected function add_ability( $name, ...$details ) {
        $GLOBALS['cmb_registered_names'][] = $name;
    }
}
class Customer_Messaging_Bridge { const API_ORIGIN = 'https://provider.example.invalid'; }
class CMB_Channels {}
class CMB_Commerce {}
class CMB_Webhooks {}
class CMB_Business_Context {
    public static function draftable_source_types() { return array( 'provider-tour', 'provider-hotel' ); }
}
require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php';

function require_true( $condition, $why ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL chatbotx-optional-provider: ' . $why . "\n" ); exit( 1 ); }
}
$adapter = new MAD4B_SCP_ChatbotX_Provider_Adapter();
require_true( ! $adapter->is_available(), 'provider activation fixture unexpectedly available' );
$absent = $adapter->ability_names();
foreach ( array( 'read', 'content', 'write', 'admin' ) as $surface ) {
    require_true( isset( $absent[$surface] ) && array() === $absent[$surface],
        'absent provider must not project ' . $surface . ' abilities' );
}
$adapter->register_abilities();
require_true( array() === $GLOBALS['cmb_registered_names'],
    'absent provider registered executable abilities' );
echo "PASS absent provider: neither MCP read catalog nor write names exposed\n";

$GLOBALS['cmb_plugin_active'] = true;
require_true( $adapter->is_available(), 'active reviewed plugin not discovered' );
$present = $adapter->ability_names();
require_true( 11 === count( $present['read'] ) && 3 === count( $present['write'] )
    && array() === $present['content'] && array() === $present['admin'],
    'reviewed provider contract changed' );
$adapter->register_abilities();
require_true( 14 === count( $GLOBALS['cmb_registered_names'] ),
    'reviewed provider ability register lost expected declarations' );
require_true( array() === array_diff(
    array_merge( $present['read'], $present['write'] ),
    $GLOBALS['cmb_registered_names']
), 'advertised ability missing from actual provider registration' );
echo "PASS present provider: exact 11 read and 3 governed-write declarations retained\n";

$GLOBALS['cmb_plugin_active'] = false;
$absent_again = $adapter->ability_names();
require_true( array() === $absent_again['read'] && array() === $absent_again['write'],
    'runtime deactivation did not withdraw optional catalog candidates' );
echo "PASS withdrawn provider: no stale catalog exposure\n";
echo "CHATBOTX_OPTIONAL_MCP_REGISTRATION: PASS\n";
