<?php
/** Executable hermetic regression: optional Operation Registry projections must have actual WP Ability registration before MCP mounting. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['qa31_registered'] = array( 'mad4b/qa31-registered-plan' => true );
function wp_has_ability( $name ) { return isset( $GLOBALS['qa31_registered'][ $name ] ); }
class MAD4B_SCP_Operation_Registry {
    public static function read_projection( $surface ) {
        if ( 'direct' === $surface ) return array();
        if ( 'catalog' !== $surface ) throw new RuntimeException( 'Wrong source surface' );
        return array( 'mad4b/qa31-registered-plan', 'mad4b/qa31-discovery-only-plan' );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-server-core-catalog.php';
class QA31_Read_Server_Catalog { use MAD4B_SCP_Server_Core_Catalog;
    public static function read() { return self::registered_operation_read_tools(); }
}
$fail = static function ( $message ) { fwrite( STDERR, "FAIL mcp_optional_operation: $message\n" ); exit( 1 ); };
$catalog = QA31_Read_Server_Catalog::read();
if ( ! in_array( 'mad4b/qa31-registered-plan', $catalog, true ) ) $fail( 'registered optional plan was lost' );
if ( in_array( 'mad4b/qa31-discovery-only-plan', $catalog, true ) ) $fail( 'unregistered optional plan mounted as MCP tool' );
$GLOBALS['qa31_registered']['mad4b/qa31-discovery-only-plan'] = true;
if ( ! in_array( 'mad4b/qa31-discovery-only-plan', QA31_Read_Server_Catalog::read(), true ) )
    $fail( 'late legitimately registered Ability was not dynamically projected' );
echo "MCP_OPTIONAL_OPERATION_READ_PROJECTION: PASS\n";
