<?php
/**
 * Portable, provider-optional MCP catalog contract.
 * Run with `php tests/runtime-optional-entity-catalog.php` in plugin-only PHP CI.
 * This must not register, grant, or execute any WordPress Ability.
 */
define( 'ABSPATH', '/fixture' );
class WP_Error {}
$GLOBALS['mad4b_probe_present'] = false;
$GLOBALS['mad4b_probe_bound'] = false;
function wp_has_ability( $name ) {
    return $name === 'mad4b/entity-reference-resolve' && $GLOBALS['mad4b_probe_present'];
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class MAD4B_SCP_Admin_Remote_Parity {
    const ABILITY = 'mad4b/admin-remote-parity';
}
class MAD4B_SCP_Capability_Descriptor_Registry {
    public static function binding( $name, $consumer ) {
        if ( $name === 'mad4b/entity-reference-resolve'
            && $consumer === 'servers' && $GLOBALS['mad4b_probe_bound'] ) {
            return array( 'contract' => 'mad4b.capability-descriptor-consumer-binding.v1',
                          'authorizing' => false );
        }
        return new WP_Error();
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-server-core-catalog.php';
class MAD4B_Optional_Entity_Catalog_Probe {
    use MAD4B_SCP_Server_Core_Catalog;
    public static function inspect( $server ) {
        return self::declared_core_tools( $server );
    }
    public static function write_tools() { return array(); }
}
$target = 'mad4b/entity-reference-resolve';
$scenarios = array(
    array( false, false, false ),
    array( false, true, false ),
    array( true, false, false ),
    array( true, true, true ),
);
foreach ( $scenarios as $scenario ) {
    list( $present, $bound, $expected ) = $scenario;
    $GLOBALS['mad4b_probe_present'] = $present;
    $GLOBALS['mad4b_probe_bound'] = $bound;
    $read = MAD4B_Optional_Entity_Catalog_Probe::inspect( 'mad4b-read' );
    $chatgpt = MAD4B_Optional_Entity_Catalog_Probe::inspect( 'mad4b-chatgpt' );
    $observed = in_array( $target, $read, true );
    if ( $observed !== $expected || in_array( $target, $chatgpt, true ) ) {
        throw new RuntimeException( 'Optional entity MCP capability was advertised without verified registration, or exposed on an unintended server' );
    }
    if ( $read !== MAD4B_Optional_Entity_Catalog_Probe::inspect( 'mad4b-read' ) ) {
        throw new RuntimeException( 'Catalog recursion guard leaked state between repeated calls' );
    }
}
echo "MAD4B optional entity catalog portability: PASS\n";
