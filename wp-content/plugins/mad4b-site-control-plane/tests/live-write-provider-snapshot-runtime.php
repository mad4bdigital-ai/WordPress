<?php
/**
 * Hermetic exact-provider projection regression: no WP database or network.
 */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-server-write-catalog.php';

final class QA31_Write_Provider_Harness {
    use MAD4B_SCP_Server_Write_Catalog;
    public static $runtime = array();
    public static $core = array();
    public static $adapters = array();
    public static function write_tools() { return self::$runtime; }
    private static function core_write_candidates() { return self::$core; }
    private static function registered_adapter_write_candidates() { return self::$adapters; }
}
function qa31_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL ' . $message . PHP_EOL ); exit( 1 ); }
}
QA31_Write_Provider_Harness::$runtime = array( 'core/write', 'provider/write', 'unmapped/write' );
QA31_Write_Provider_Harness::$core = array( 'core/write', 'blocked/write' );
QA31_Write_Provider_Harness::$adapters = array(
    'provider/write' => 'jetengine',
    'core/write' => 'malicious_adapter_override',
    'unmounted/write' => 'woocommerce',
    'unmapped/write' => '',
);
$providers = QA31_Write_Provider_Harness::live_write_provider_snapshot();
qa31_assert( $providers === array(
    'core/write' => 'core',
    'provider/write' => 'jetengine',
), 'Only mounted core and provider-certified identities may be projected' );
qa31_assert( ! isset( $providers['blocked/write'] )
    && ! isset( $providers['unmounted/write'] )
    && ! isset( $providers['unmapped/write'] ), 'Unregistered, unmapped and blocked writes must stay unmounted' );
qa31_assert( count( $providers ) === 2, 'No extra grant targets are created' );
QA31_Write_Provider_Harness::$runtime = array();
qa31_assert( QA31_Write_Provider_Harness::live_write_provider_snapshot() === array(), 'Empty runtime must not create grants' );
echo "QA31_LIVE_WRITE_PROVIDER_SNAPSHOT_PASS\n";
