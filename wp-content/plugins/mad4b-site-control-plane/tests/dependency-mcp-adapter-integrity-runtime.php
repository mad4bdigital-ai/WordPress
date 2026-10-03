<?php

define( 'ABSPATH', __DIR__ . '/' );

function trailingslashit( $value ) { return rtrim( (string) $value, '/\\' ) . '/'; }
function wp_normalize_path( $value ) { return str_replace( '\\', '/', (string) $value ); }

$base = rtrim( sys_get_temp_dir(), '/\\' ) . '/mad4b-dependency-integrity-' . getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . ':' . uniqid( '', true ) ), 0, 12 );
$plugins = $base . '/plugins';
$adapter = $plugins . '/mcp-adapter';
if ( ! mkdir( $adapter . '/includes/Domain/Tools', 0777, true ) && ! is_dir( $adapter . '/includes/Domain/Tools' ) ) {
	throw new RuntimeException( 'Unable to create adapter fixture.' );
}
define( 'WP_PLUGIN_DIR', $plugins );

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-dependency-manager.php';

function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
function integrity( array $certified ) {
	$method = new ReflectionMethod( 'MAD4B_SCP_Dependency_Manager', 'installed_mcp_adapter_integrity' );
	$method->setAccessible( true );
	return $method->invoke( null, $certified );
}
function cleanup_tree( $path ) {
	if ( ! is_dir( $path ) ) return;
	foreach ( scandir( $path ) as $item ) {
		if ( '.' === $item || '..' === $item ) continue;
		$target = $path . '/' . $item;
		if ( is_dir( $target ) ) cleanup_tree( $target );
		else @unlink( $target );
	}
	@rmdir( $path );
}

$main = "<?php\n/* Plugin Name: MCP Adapter\nVersion: 0.6.1\n*/\n";
$validator = "<?php\nnamespace WP\\MCP\\Domain\\Tools;\nclass McpToolValidator {}\n";
file_put_contents( $adapter . '/mcp-adapter.php', $main );
file_put_contents( $adapter . '/includes/Domain/Tools/McpToolValidator.php', $validator );

$certified = array(
	'critical_files' => array(
		'mcp-adapter.php' => hash( 'sha256', $main ),
		'includes/Domain/Tools/McpToolValidator.php' => hash( 'sha256', $validator ),
	),
);

$ready = integrity( $certified );
check( ! empty( $ready['ready'] ), 'Exact critical files must pass disk integrity.' );
check( 'certified_disk_set' === $ready['state'], 'Exact disk state mismatch.' );
check( 2 === $ready['expected_count'] && 2 === $ready['verified_count'] && 0 === $ready['mismatch_count'], 'Exact disk counts mismatch.' );

file_put_contents( $adapter . '/includes/Domain/Tools/McpToolValidator.php', "\n// stale bytes\n", FILE_APPEND );
clearstatcache();
$drift = integrity( $certified );
check( empty( $drift['ready'] ), 'Same-version validator byte drift must fail closed.' );
check( 'integrity_mismatch' === $drift['state'], 'Byte drift state mismatch.' );
check( 1 === $drift['mismatch_count'], 'Exactly one drifted file must be reported.' );
check( 'includes/Domain/Tools/McpToolValidator.php' === $drift['mismatches'][0]['file'], 'Drift evidence must remain plugin-relative.' );
check( 'critical_file_sha256_mismatch' === $drift['mismatches'][0]['reason'], 'Drift reason mismatch.' );
check( false === strpos( json_encode( $drift ), $base ), 'Absolute filesystem path leaked from integrity evidence.' );

@unlink( $adapter . '/includes/Domain/Tools/McpToolValidator.php' );
$missing = integrity( $certified );
check( empty( $missing['ready'] ) && 'critical_file_missing' === $missing['mismatches'][0]['reason'], 'Missing critical file must fail closed.' );

$invalid = array(
	'critical_files' => array(
		'../escape.php' => str_repeat( 'a', 64 ),
		'mcp-adapter.php' => 'invalid-hash',
	),
);
$invalid_result = integrity( $invalid );
check( empty( $invalid_result['ready'] ), 'Invalid certified baseline must fail closed.' );
check( 2 === $invalid_result['mismatch_count'], 'Invalid baseline entries must both be rejected.' );
foreach ( $invalid_result['mismatches'] as $row ) {
	check( 'invalid_certified_critical_file' === $row['reason'], 'Invalid baseline reason mismatch.' );
}

cleanup_tree( $base );
echo "mad4b.mcp-adapter-installed-integrity.v1: PASS\n";
