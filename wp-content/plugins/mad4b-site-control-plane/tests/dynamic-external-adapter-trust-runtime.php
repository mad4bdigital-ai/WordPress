<?php
/** Hermetic test: trusted third-party code admission is owner pinned and never grants authority. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class MAD4B_SCP_Provider_Compatibility_Certification {}
function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) { return true; }
function add_filter( $hook, $callback = null, $priority = 10, $args = 1 ) { return true; }
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
$reviewed_callback = $plugins . '/demo/ability-reader.php';
$reader_php = '<?php function ci_reviewed_external_reader( $args ) { return array( "ok" => true ); }';
file_put_contents( $reviewed_callback, $reader_php );
$manifest_path = $root . '/owner-reviewed.json';
$manifest = array(
    'contract' => 'mad4b.external-adapter-trust.v1',
    'adapters' => array( array(
        'class' => 'MAD4B_CI_Reviewed_External_Adapter',
        'adapter_id' => 'external-ci',
        'plugin_basename' => 'demo/main.php',
        'source_relpath' => 'demo/main.php',
        'source_sha256' => hash( 'sha256', $php ),
        'php_source_tree_sha256' => hash( 'sha256', json_encode(
            array( 'ability-reader.php' => hash( 'sha256', $reader_php ),
                'main.php' => hash( 'sha256', $php ) ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ),
        'abilities' => array(
            'read' => array( 'external-ci/inspect' ),
            'content' => array(),
            'admin' => array(),
        ),
        'reversible_contracts' => array(),
        'callback_sources' => array(
            'external-ci/inspect' => array(
                'relative_path' => 'demo/ability-reader.php',
                'sha256' => hash( 'sha256', $reader_php )
            )
        ),
    ) ),
);
file_put_contents( $manifest_path, json_encode( $manifest ) );
define( 'MAD4B_SCP_EXTERNAL_ADAPTER_MANIFEST_PATH', $manifest_path );
define( 'MAD4B_SCP_EXTERNAL_ADAPTER_MANIFEST_SHA256', hash_file( 'sha256', $manifest_path ) );
require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-adapter-base.php';
require_once $source;
require_once $reviewed_callback;
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-adapter-registry.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-mcp-hybrid-authority.php';
class MAD4B_Test_External_Read_Ability {
    protected $execute_callback;
    protected $annotations;
    public function __construct( $callback, $readonly = true ) {
        $this->execute_callback = $callback;
        $this->annotations = array( 'readonly' => $readonly, 'destructive' => false );
    }
    public function get_name() { return 'external-ci/inspect'; }
    public function get_meta() { return array( 'annotations' => $this->annotations ); }
    public function execute( $args = array() ) { return call_user_func( $this->execute_callback, $args ); }
}


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
$helper = $plugins . '/demo/helper.php';
file_put_contents( $helper, '<?php function ci_added_helper() {}' );
external_assert( ! MAD4B_SCP_External_Adapter_Trust::allows( $valid ),
    'Adding a PHP dependency changes the source tree and revokes admission' );
unlink( $helper );
external_assert( MAD4B_SCP_External_Adapter_Trust::allows( $valid ),
    'Restoring the complete PHP tree restores admission' );
external_assert( $registry->register( $valid ), 'Reviewed external source can register without MAD4B core edit' );
external_assert( ! $registry->register( $valid ), 'Duplicate external registration rejected' );
external_assert( $registry->get( 'external-ci' ) === $valid, 'Registered object identity preserved' );
$verified_callback = new MAD4B_Test_External_Read_Ability( 'ci_reviewed_external_reader' );
external_assert( MAD4B_SCP_MCP_Hybrid_Authority::verified_local_read(
    $verified_callback, $valid, 'external-ci/inspect' ),
    'Reviewed external Ability may implement its callback in a distinct exact pinned file' );
$unknown_callback = new MAD4B_Test_External_Read_Ability( 'strlen' );
external_assert( ! MAD4B_SCP_MCP_Hybrid_Authority::verified_local_read(
    $unknown_callback, $valid, 'external-ci/inspect' ),
    'Unreviewed callback source cannot borrow an approved Ability name' );
$unsafe_metadata = new MAD4B_Test_External_Read_Ability( 'ci_reviewed_external_reader', false );
external_assert( ! MAD4B_SCP_MCP_Hybrid_Authority::verified_local_read(
    $unsafe_metadata, $valid, 'external-ci/inspect' ),
    'Callback source pin does not substitute for read-only Ability annotations' );
external_assert( ! MAD4B_SCP_External_Adapter_Trust::status()['write_authority_created'], 'External source pin creates no write grant' );
// Read-only source tree cost measurement: do not replace exact PHP hashes
// with an unchecked persistent cache. Record a deterministic bounded sample,
// including within-request drift detection.
$benchmark_dir = $root . '/digest-benchmark';
external_assert( mkdir( $benchmark_dir, 0700 ), 'Performance fixture directory' );
$fixture_files = array();
for ( $n = 0; $n < 128; ++$n ) {
    $file = $benchmark_dir . '/source-' . str_pad( (string) $n, 3, '0', STR_PAD_LEFT ) . '.php';
    external_assert( false !== file_put_contents( $file, "<?php // deterministic digest input $n\n" ),
        'Performance fixture file' );
    $fixture_files[] = $file;
}
$benchmark_started = microtime( true );
$hash_before = MAD4B_SCP_External_Adapter_Trust::php_source_tree_sha256( $benchmark_dir );
$hash_repeat = MAD4B_SCP_External_Adapter_Trust::php_source_tree_sha256( $benchmark_dir );
$benchmark_ms = round( 1000 * ( microtime( true ) - $benchmark_started ), 2 );
external_assert( is_string( $hash_before ) && preg_match( '/^[a-f0-9]{64}$/D', $hash_before )
    && hash_equals( $hash_before, $hash_repeat ), 'Bounded PHP tree digest is stable' );
file_put_contents( $fixture_files[51], "<?php // changed within same request\n" );
$hash_changed = MAD4B_SCP_External_Adapter_Trust::php_source_tree_sha256( $benchmark_dir );
external_assert( is_string( $hash_changed ) && '' !== $hash_changed
    && ! hash_equals( $hash_before, $hash_changed ),
    'Same-request PHP source drift must never be hidden by digest caching' );
foreach ( $fixture_files as $file ) unlink( $file );
rmdir( $benchmark_dir );
fwrite( STDOUT, 'EXTERNAL_PHP_TREE_DIGEST_SAMPLE: two scans of 128 files in '
    . $benchmark_ms . " ms; exact drift detection PASS\n" );
unlink( $reviewed_callback );
unlink( $source );
unlink( $manifest_path );
rmdir( $plugins . '/demo' );
rmdir( $plugins );
rmdir( $root );
echo "DYNAMIC_EXTERNAL_ADAPTER_TRUST: PASS (owner-pinned, read-only registration)\n";
