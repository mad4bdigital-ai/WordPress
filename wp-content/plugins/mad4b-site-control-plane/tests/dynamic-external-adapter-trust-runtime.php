<?php
/** Hermetic test: trusted third-party code admission is owner pinned and never grants authority. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class MAD4B_SCP_Provider_Compatibility_Certification {}
function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) { return true; }
function do_action( $hook, $value = null ) { return null; }
$GLOBALS['external_active_plugins'] = array();
function get_option( $name, $fallback = array() ) {
    return 'active_plugins' === $name ? $GLOBALS['external_active_plugins'] : $fallback;
}
function external_assert( $condition, $why ) {
    if ( ! $condition ) { fwrite( STDERR, 'EXTERNAL_ADAPTER_FAIL: ' . $why . "\n" ); exit( 1 ); }
}
$root = sys_get_temp_dir() . '/mad4b-external-trust-ci-' . getmypid();
$plugins = $root . '/plugins';
external_assert( ! is_dir( $root ) && mkdir( $plugins . '/demo', 0700, true ), 'Unique writable fixture root' );
define( 'WP_PLUGIN_DIR', $plugins );
$source = $plugins . '/demo/main.php';
$php = <<<'EXT_ADAPTER'
<?php
final class MAD4B_CI_Reviewed_External_Adapter extends MAD4B_SCP_Adapter_Base {
    private $changed;
    public function __construct( $changed = false ) { $this->changed = $changed; }
    public function id() { return 'external-ci'; }
    public function label() { return 'External CI'; }
    public function is_available() { return true; }
    public function ability_names() { return array(
        'read' => array( 'external-ci/inspect' ),
        'content' => $this->changed ? array( 'external-ci/unreviewed-write' ) : array(),
        'admin' => array(),
    ); }
    public function register_abilities() {}
}
EXT_ADAPTER;
file_put_contents( $source, $php );
$manifest_path = $root . '/owner-reviewed.json';
$manifest = array(
    'contract' => 'mad4b.external-adapter-trust.v1',
    'adapters' => array( array(
        'class' => 'MAD4B_CI_Reviewed_External_Adapter',
        'adapter_id' => 'external-ci',
        'plugin_basename' => 'demo/main.php',
        'source_relpath' => 'demo/main.php',
        'source_sha256' => hash( 'sha256', $php ),
        'abilities' => array(
            'read' => array( 'external-ci/inspect' ),
            'content' => array(),
            'admin' => array(),
        ),
        'reversible_contracts' => array(),
    ) ),
);
file_put_contents( $manifest_path, json_encode( $manifest ) );
define( 'MAD4B_SCP_EXTERNAL_ADAPTER_MANIFEST_PATH', $manifest_path );
define( 'MAD4B_SCP_EXTERNAL_ADAPTER_MANIFEST_SHA256', hash_file( 'sha256', $manifest_path ) );
require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-adapter-base.php';
require_once $source;
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-adapter-registry.php';

$valid = new MAD4B_CI_Reviewed_External_Adapter();
$changed = new MAD4B_CI_Reviewed_External_Adapter( true );
$registry = MAD4B_SCP_Adapter_Registry::instance();

external_assert( ! MAD4B_SCP_External_Adapter_Trust::allows( $valid ), 'Inactive plugin denied' );
$GLOBALS['external_active_plugins'] = array( 'demo/main.php' );
external_assert( MAD4B_SCP_External_Adapter_Trust::allows( $valid ), 'Pinned source + manifest + active plugin accepted' );
external_assert( ! MAD4B_SCP_External_Adapter_Trust::allows( $changed ), 'Unreviewed additional write tool denied' );
file_put_contents( $source, $php . "\n// source changed" );
external_assert( ! MAD4B_SCP_External_Adapter_Trust::allows( $valid ), 'Source drift denied on each admission' );
file_put_contents( $source, $php );
external_assert( MAD4B_SCP_External_Adapter_Trust::allows( $valid ), 'Exact source digest restored' );
external_assert( $registry->register( $valid ), 'Reviewed external source can register without MAD4B core edit' );
external_assert( ! $registry->register( $valid ), 'Duplicate external registration rejected' );
external_assert( $registry->get( 'external-ci' ) === $valid, 'Registered object identity preserved' );
external_assert( ! MAD4B_SCP_External_Adapter_Trust::status()['write_authority_created'], 'External source pin creates no write grant' );
unlink( $source );
unlink( $manifest_path );
rmdir( $plugins . '/demo' );
rmdir( $plugins );
rmdir( $root );
echo "DYNAMIC_EXTERNAL_ADAPTER_TRUST: PASS (owner-pinned, read-only registration)\n";
