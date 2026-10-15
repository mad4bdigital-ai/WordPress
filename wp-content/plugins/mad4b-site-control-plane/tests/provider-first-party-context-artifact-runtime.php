<?php
/** Fail-closed regression for in-tree Google Drive Context provider binding. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $key ) ); }
function add_action( $name, $callback, $priority = 10 ) {}
function add_filter( $name, $callback, $priority = 10, $args = 1 ) {}
$root = sys_get_temp_dir() . '/mad4b-firstparty-' . bin2hex( random_bytes( 8 ) ) . '/';
if ( ! mkdir( $root . 'includes/adapters', 0700, true ) ) throw new RuntimeException( 'Cannot create sandbox' );
define( 'MAD4B_SCP_DIR', $root );
$files = array(
    'includes/adapters/class-mad4b-scp-context-adapter.php',
    'includes/class-mad4b-scp-brand-context-builder.php',
    'includes/class-mad4b-scp-context-authority.php',
    'includes/class-mad4b-scp-context-preflight.php',
    'includes/class-mad4b-scp-context-provider-gateway.php',
    'includes/class-mad4b-scp-google-drive-context.php',
);
$hashes = array();
foreach ( $files as $file ) {
    $path = $root . $file;
    $dir = dirname( $path );
    if ( ! is_dir( $dir ) ) mkdir( $dir, 0700, true );
    file_put_contents( $path, 'test-only-' . $file );
    $hashes[ $file ] = hash_file( 'sha256', $path );
}
class MAD4B_SCP_External_Handshake_Evidence {
    public static $build;
    public static function build_fingerprint() { return self::$build; }
}
class MAD4B_SCP_FirstPartyTestAdapter {
    public $status = array();
    public function context_provider_contract_status() { return $this->status; }
}
MAD4B_SCP_External_Handshake_Evidence::$build = str_repeat( 'a', 64 );
$adapter = new MAD4B_SCP_FirstPartyTestAdapter();
$adapter->status = array(
    'contract' => 'mad4b.google-drive-context-provider.v1',
    'provider' => 'google_drive_context',
    'ready' => true,
    'first_party' => true,
    'control_plane_build_fingerprint' => MAD4B_SCP_External_Handshake_Evidence::$build,
    'artifact_fingerprint' => str_repeat( 'b', 64 ),
    'critical_files' => $hashes,
    'rollback_contracts' => array(
        'context/update-drive-asset' => 'mad4b.rollback.google-drive-context-update.v1',
        'context/recreate-drive-asset' => 'mad4b.rollback.google-drive-context-recreate.v1',
    ),
);
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-compatibility-certification.php';
$m = new ReflectionMethod( 'MAD4B_SCP_Provider_Compatibility_Certification', 'first_party_context_evidence' );
$m->setAccessible( true );
$read = static function () use ( $m, $adapter ) { return $m->invoke( null, 'google_drive_context', $adapter ); };
$assert = static function ( $pass, $reason ) {
    if ( ! $pass ) { fwrite( STDERR, "FAIL: $reason\n" ); exit( 1 ); }
};
try {
    $ok = $read();
    $assert( ! empty( $ok['bound'] ) && $ok['verified_critical_file_count'] === 6
        && ! $ok['mutation_authorized'] && ! $ok['behavioral_certification_granted']
        && ! $ok['rollback_certification_granted'], 'identity only, no write promotion' );
    $adapter->status['control_plane_build_fingerprint'] = str_repeat( 'c', 64 );
    $assert( empty( $read()['bound'] ), 'build drift fails closed' );
    $adapter->status['control_plane_build_fingerprint'] = MAD4B_SCP_External_Handshake_Evidence::$build;
    $adapter->status['critical_files']['includes/class-mad4b-scp-google-drive-context.php'] = str_repeat( '0', 64 );
    $assert( empty( $read()['bound'] ), 'critical file drift fails closed' );
    $adapter->status['critical_files'] = $hashes;
    $adapter->status['rollback_contracts']['context/recreate-drive-asset'] = 'unknown';
    $assert( empty( $read()['bound'] ), 'rollback contract drift fails closed' );
    $adapter->status['rollback_contracts']['context/recreate-drive-asset'] = 'mad4b.rollback.google-drive-context-recreate.v1';
    unset( $adapter->status['critical_files']['includes/class-mad4b-scp-context-preflight.php'] );
    $assert( empty( $read()['bound'] ), 'incomplete critical file manifest fails closed' );
    $adapter->status['critical_files'] = $hashes;
    $adapter->status['ready'] = false;
    $assert( empty( $read()['bound'] ), 'provider runtime health fails closed' );
    $adapter->status['ready'] = true;
    $assert( empty( $m->invoke( null, 'unrelated_provider', $adapter )['bound'] ), 'external providers never inherit first-party exemption' );
    echo "PASS: first-party Google provider identity, file drift, rollback contract and no write promotion\n";
} finally {
    foreach ( $files as $file ) @unlink( $root . $file );
    @rmdir( $root . 'includes/adapters' );
    @rmdir( $root . 'includes' );
    @rmdir( $root );
}
