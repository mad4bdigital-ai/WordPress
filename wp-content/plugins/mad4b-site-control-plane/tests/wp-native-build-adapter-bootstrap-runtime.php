<?php
/**
 * Regression: WordPress admin maintenance read must never fatal when a
 * source-owned adapter is discovered before its optional worker is loaded.
 * Runs without WordPress, a DB, network, or any mutation authority.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
abstract class MAD4B_SCP_Adapter_Base {
    abstract public function id();
    abstract public function label();
    abstract public function is_available();
    abstract public function ability_names();
    abstract public function register_abilities();
}
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function fail_native_boot( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-wp-native-build-adapter.php';
$adapter = new MAD4B_SCP_WP_Native_Build_Adapter();
$none = array( 'read' => array(), 'content' => array(),
    'admin' => array(), 'write' => array() );
fail_native_boot( ! $adapter->is_available(), 'optional worker must initially be absent' );
fail_native_boot( $adapter->ability_names() === $none, 'unloaded adapter must declare zero abilities' );
fail_native_boot( $adapter->mutation_ability_runtime_eligibility( 'mad4b/wp-native-build-start' )
    instanceof WP_Error, 'unloaded adapter mutation must deny' );
class NativeWorkerFixture {
    const PREFIX = 'mad4b/wp-native-build-';
    public static function capabilities() {
        return array( 'eligible' => false );
    }
}
class NativeApprovalFixture {
    const APPLY = 'mad4b/wp-native-self-approve-install';
}
class_alias( 'NativeWorkerFixture', 'MAD4B_SCP_WP_Native_Build' );
fail_native_boot( $adapter->ability_names() === $none,
    'missing self-approval dependency must deny without fatal' );
class_alias( 'NativeApprovalFixture', 'MAD4B_SCP_WP_Native_Self_Approval' );
$names = $adapter->ability_names();
fail_native_boot( $names['admin'] === array(
    'mad4b/wp-native-build-start', 'mad4b/wp-native-self-approve-install' ),
    'fully loaded adapter must project only its two existing governed abilities' );
fail_native_boot( $names['read'] === array() && $names['write'] === array(),
    'native builder must not bypass dedicated governed Write plane' );
$deny = $adapter->mutation_ability_runtime_eligibility( 'mad4b/wp-native-build-start' );
fail_native_boot( $deny instanceof WP_Error
    && 'mad4b_native_adapter_staging_not_eligible' === $deny->get_error_code(),
    'Staging opt-in/runtime gate must remain fail-closed' );
$main = file_get_contents( dirname( __DIR__ ) . '/mad4b-site-control-plane.php' );
$include = strpos( $main, "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-wp-native-build.php';" );
$boot = strpos( $main, 'MAD4B_SCP_WP_Native_Build::boot();' );
$registry = strpos( $main, 'MAD4B_SCP_Adapter_Registry::boot_ability_registration();' );
$servers = strpos( $main, "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-servers.php';" );
fail_native_boot( false !== $include && false !== $boot
    && false !== $registry && false !== $servers
    && $include < $boot && $boot < $registry && $registry < $servers,
    'source worker and approval registration must precede adapter/Server projection' );
echo "WP_NATIVE_BUILD_ADAPTER_BOOTSTRAP: PASS\n";
