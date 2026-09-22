<?php
define( 'ABSPATH', '/tmp/' );
$root = sys_get_temp_dir() . '/mad4b-functional-gap-' . getmypid();
$plugins = $root . '/plugins';
@mkdir( $plugins . '/alpha', 0777, true );
@mkdir( $plugins . '/beta/sub', 0777, true );
file_put_contents( $plugins . '/alpha/alpha.php', "<?php\n/* alpha */\n" );
file_put_contents( $plugins . '/alpha/readme.txt', "alpha-readme\n" );
file_put_contents( $plugins . '/beta/beta.php', "<?php\n/* beta */\n" );
file_put_contents( $plugins . '/beta/sub/data.json', "{\"beta\":true}\n" );
define( 'WP_PLUGIN_DIR', $plugins );

$GLOBALS['mad4b_gap_items'] = array(
	array(
		'plugin_file' => 'alpha/alpha.php',
		'plugin_name' => 'Alpha Provider',
		'family' => 'alpha',
		'functional_family_key' => 'alpha',
		'functional_coverage' => array( 'state' => 'contract_discovery_required' ),
	),
	array(
		'plugin_file' => 'beta/beta.php',
		'plugin_name' => 'Beta Provider',
		'family' => 'beta',
		'functional_family_key' => 'beta',
		'functional_coverage' => array( 'state' => 'safety_blocked' ),
	),
	array(
		'plugin_file' => 'ready/ready.php',
		'plugin_name' => 'Ready Provider',
		'family' => 'ready',
		'functional_family_key' => 'ready',
		'functional_coverage' => array( 'state' => 'functional_ready' ),
	),
);

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action() {}
function wp_register_ability() {}
function wp_has_ability() { return false; }
function sanitize_key( $v ) { return strtolower( trim( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function trailingslashit( $v ) { return rtrim( (string) $v, "/\\" ) . '/'; }
function wp_normalize_path( $v ) { return str_replace( '\\', '/', (string) $v ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }

final class MAD4B_SCP_Policy { public static function can_read() { return true; } }
final class MAD4B_SCP_Plugin_Discovery {
	public static function functional_coverage_report() {
		return array(
			'contract' => 'mad4b.provider-functional-coverage.v1',
			'items' => $GLOBALS['mad4b_gap_items'],
		);
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-functional-gap-runtime-diagnostic.php';

function mad4b_gap_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}
function mad4b_gap_assert( $condition, $message, $data = null ) { if ( ! $condition ) mad4b_gap_fail( $message, $data ); }

function mad4b_expected_tree( $plugin_root, $plugins_root ) {
	$rows = array();
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY );
	foreach ( $it as $file ) {
		if ( ! $file->isFile() || $file->isLink() ) continue;
		$path = $file->getRealPath();
		$relative = ltrim( substr( wp_normalize_path( $path ), strlen( trailingslashit( wp_normalize_path( $plugins_root ) ) ) ), '/' );
		$rows[] = array( 'path' => $relative, 'size' => (int) $file->getSize(), 'sha256' => hash_file( 'sha256', $path ) );
	}
	usort( $rows, static function ( $a, $b ) { return strcmp( $a['path'], $b['path'] ); } );
	$material = '';
	foreach ( $rows as $row ) $material .= $row['path'] . "\0" . $row['size'] . "\0" . $row['sha256'] . "\n";
	return hash( 'sha256', $material );
}

$result = MAD4B_SCP_Functional_Gap_Runtime_Diagnostic::execute();
mad4b_gap_assert( ! is_wp_error( $result ), 'diagnostic unexpectedly failed', $result );
mad4b_gap_assert( 'mad4b.runtime-functional-gap-diagnostic.v2' === $result['contract'], 'contract drifted', $result );
mad4b_gap_assert( 2 === (int) $result['family_count'], 'only contract-discovery and safety-blocked families should be targeted', $result );
mad4b_gap_assert( ! empty( $result['read_only'] ) && empty( $result['mutation_performed'] ) && empty( $result['remote_request_performed'] ) && empty( $result['raw_sql_performed'] ), 'diagnostic safety truth drifted', $result );
mad4b_gap_assert( ! empty( $result['complete'] ), 'valid fixture should be complete', $result );
mad4b_gap_assert( 64 === strlen( $result['report_sha256'] ) && ctype_xdigit( $result['report_sha256'] ), 'report digest invalid', $result );

$by_family = array();
foreach ( $result['families'] as $family ) $by_family[ $family['family'] ] = $family;
mad4b_gap_assert( isset( $by_family['alpha'], $by_family['beta'] ), 'target family keys missing', $result );
mad4b_gap_assert( ! isset( $by_family['alpha']['files'] ), 'default response must not expose full file manifest', $by_family['alpha'] );
mad4b_gap_assert( mad4b_expected_tree( $plugins . '/alpha', $plugins ) === $by_family['alpha']['tree_sha256'], 'alpha tree hash is not path+size+sha256 deterministic identity', $by_family['alpha'] );
mad4b_gap_assert( mad4b_expected_tree( $plugins . '/beta', $plugins ) === $by_family['beta']['tree_sha256'], 'beta tree hash is not path+size+sha256 deterministic identity', $by_family['beta'] );

$detail = MAD4B_SCP_Functional_Gap_Runtime_Diagnostic::execute( array( 'family' => 'alpha', 'include_files' => true ) );
mad4b_gap_assert( ! is_wp_error( $detail ) && 1 === (int) $detail['family_count'], 'single-family detail failed', $detail );
mad4b_gap_assert( isset( $detail['families'][0]['files'] ) && 2 === count( $detail['families'][0]['files'] ), 'single-family detail must expose exact bounded file manifest', $detail );

$unbounded = MAD4B_SCP_Functional_Gap_Runtime_Diagnostic::execute( array( 'include_files' => true ) );
mad4b_gap_assert( is_wp_error( $unbounded ) && 'mad4b_functional_gap_family_required' === $unbounded->get_error_code(), 'full-manifest request without family must fail closed', $unbounded );

$GLOBALS['mad4b_gap_items'] = array(
	array(
		'plugin_file' => '../outside.php',
		'plugin_name' => 'Escape Attempt',
		'family' => 'escape',
		'functional_family_key' => 'escape',
		'functional_coverage' => array( 'state' => 'safety_blocked' ),
	),
);
$escape = MAD4B_SCP_Functional_Gap_Runtime_Diagnostic::execute( array( 'family' => 'escape' ) );
mad4b_gap_assert( ! is_wp_error( $escape ) && empty( $escape['complete'] ), 'escaped plugin path must produce incomplete fail-closed evidence', $escape );
mad4b_gap_assert( 'mad4b_functional_gap_plugin_file_invalid' === $escape['families'][0]['errors'][0]['error'], 'escaped plugin path blocker code missing', $escape );

$GLOBALS['mad4b_gap_items'] = array(
	array(
		'plugin_file' => 'top-level.php',
		'plugin_name' => 'Top Level Provider',
		'family' => 'top-level',
		'functional_family_key' => 'top-level',
		'functional_coverage' => array( 'state' => 'contract_discovery_required' ),
	),
);
file_put_contents( $plugins . '/top-level.php', "<?php\n/* top level */\n" );
$top = MAD4B_SCP_Functional_Gap_Runtime_Diagnostic::execute( array( 'family' => 'top-level' ) );
mad4b_gap_assert( ! is_wp_error( $top ) && empty( $top['complete'] ), 'top-level plugin must never cause whole plugins directory scan', $top );
mad4b_gap_assert( 'mad4b_functional_gap_top_level_plugin_unbounded' === $top['families'][0]['errors'][0]['error'], 'top-level plugin bound blocker missing', $top );
@unlink( $plugins . '/top-level.php' );

if ( function_exists( 'symlink' ) ) {
	$GLOBALS['mad4b_gap_items'] = array(
		array(
			'plugin_file' => 'alpha/alpha.php',
			'plugin_name' => 'Alpha Provider',
			'family' => 'alpha',
			'functional_family_key' => 'alpha',
			'functional_coverage' => array( 'state' => 'safety_blocked' ),
		),
	);
	@symlink( $plugins . '/alpha/readme.txt', $plugins . '/alpha/readme-link.txt' );
	$linked = MAD4B_SCP_Functional_Gap_Runtime_Diagnostic::execute( array( 'family' => 'alpha' ) );
	if ( is_link( $plugins . '/alpha/readme-link.txt' ) ) {
		mad4b_gap_assert( empty( $linked['complete'] ) && (int) $linked['families'][0]['symlink_count'] > 0, 'symlinked plugin tree must remain incomplete', $linked );
		@unlink( $plugins . '/alpha/readme-link.txt' );
	}
}

foreach ( array( $plugins . '/alpha/alpha.php', $plugins . '/alpha/readme.txt', $plugins . '/beta/beta.php', $plugins . '/beta/sub/data.json' ) as $file ) @unlink( $file );
@rmdir( $plugins . '/beta/sub' ); @rmdir( $plugins . '/beta' ); @rmdir( $plugins . '/alpha' ); @rmdir( $plugins ); @rmdir( $root );

echo "mad4b.runtime-functional-gap-diagnostic.runtime.v1: PASS\n";
