<?php
/** Version drift must not prevent provenance-bound read-only model discovery. */
define( 'ABSPATH', __DIR__ . '/' );
$case = $argv[1] ?? '';
if ( '' === $case ) {
 foreach ( array( 'drift', 'baseline', 'missing', 'escaped', 'foreign', 'oversized_model', 'oversized_plugin' ) as $scenario ) {
  // A fresh process isolates class state; retain required ini-loaded extensions
  // (JSON is a shared module on some supported PHP 7.4 installations).
  passthru( escapeshellarg( PHP_BINARY ) . ' -d auto_prepend_file= -d auto_append_file= ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario ), $exit );
  if ( $exit ) exit( $exit );
 }
 echo "mad4b.wp-import-export-readonly-bootstrap.runtime.v2: PASS\n"; exit;
}
function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
check( function_exists( 'json_encode' ) && function_exists( 'hash_file' ), 'Required WordPress JSON/hash extensions are unavailable' );
$root = sys_get_temp_dir() . '/mad4b-import-' . bin2hex( random_bytes( 8 ) );
define( 'WP_PLUGIN_DIR', $root . '/plugins' );
$plugin = WP_PLUGIN_DIR . '/wp-all-import-pro';
mkdir( $plugin . '/models/model', 0777, true ); mkdir( $plugin . '/models/import', 0777, true );
register_shutdown_function( static function () use ( $root ) {
 $remove = static function ( $path ) use ( &$remove ) { if ( is_link( $path ) || is_file( $path ) ) { unlink( $path ); return; } foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $name ) $remove( $path . '/' . $name ); rmdir( $path ); };
 $remove( $root );
} );
$paths = array( 'PMXI_Model' => 'models/model.php', 'PMXI_Model_Record' => 'models/model/record.php', 'PMXI_Model_List' => 'models/model/list.php', 'PMXI_Import_Record' => 'models/import/record.php', 'PMXI_Import_List' => 'models/import/list.php' );
foreach ( $paths as $class => $path ) file_put_contents( $plugin . '/' . $path, '<?php class ' . $class . ' {}' );
$provider = <<<'PROVIDER'
<?php
final class PMXI_Plugin {
 public static $autoloaded = array();
 public static function getInstance() { return new self(); }
 public function autoload( $class ) {
  self::$autoloaded[] = $class;
  $path = str_replace( '_', '/', strtolower( substr( $class, 5 ) ) ) . '.php';
  require_once __DIR__ . '/models/' . $path;
 }
}
PROVIDER;
file_put_contents( $plugin . '/wp-all-import-pro.php', $provider );
require $plugin . '/wp-all-import-pro.php';
abstract class MAD4B_SCP_Adapter_Base {}
class MAD4B_SCP_Provider_Contracts {
 static function get( $provider ) { return array( 'components' => array( 'import' => array( 'plugin_file' => 'wp-all-import-pro/wp-all-import-pro.php', 'version' => '5.0.8' ) ) ); }
 static function runtime_status( ...$args ) { throw new RuntimeException( 'Read bootstrap must not depend on a baseline version' ); }
}
if ( 'missing' === $case ) unlink( $plugin . '/models/import/list.php' );
if ( 'escaped' === $case ) { rename( $plugin . '/models/import/list.php', $root . '/escaped.php' ); symlink( $root . '/escaped.php', $plugin . '/models/import/list.php' ); }
if ( 'foreign' === $case ) { file_put_contents( $root . '/foreign.php', '<?php class PMXI_Import_Record {}' ); require $root . '/foreign.php'; }
if ( 'oversized_model' === $case ) file_put_contents( $plugin . '/models/import/list.php', str_repeat( ' ', 2097153 ) );
if ( 'oversized_plugin' === $case ) file_put_contents( $plugin . '/wp-all-import-pro.php', $provider . str_repeat( ' ', 2097153 ) );
require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-wp-import-export-adapter.php';
$adapter = new MAD4B_SCP_WP_Import_Export_Adapter(); $available = $adapter->is_available();
$blocker_property = new ReflectionProperty( 'MAD4B_SCP_WP_Import_Export_Adapter', 'import_readonly_autoload_blocker' );
$blocker_property->setAccessible( true );
$autoload_blocker = (string) $blocker_property->getValue();
if ( in_array( $case, array( 'drift', 'baseline' ), true ) ) {
 check( $available, 'Compatible local models were blocked by artifact drift: ' . $autoload_blocker );
 check( array_keys( $paths ) === PMXI_Plugin::$autoloaded, 'Class allowlist/order changed' );
 check( array() === $adapter->ability_names()['content'] && array() === $adapter->ability_names()['admin'], 'Structural read discovery mounted an execution ability' );
} else {
 check( ! $available, 'Untrusted/missing model provenance was accepted: ' . $case );
 if ( 'foreign' !== $case ) check( array() === PMXI_Plugin::$autoloaded, 'Autoloader invoked without safe bounded model paths' );
}
