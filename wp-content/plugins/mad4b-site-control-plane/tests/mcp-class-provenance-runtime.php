<?php

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }

$base = rtrim( sys_get_temp_dir(), '/\\' ) . '/mad4b-mcp-provenance-' . getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . ':' . uniqid( '', true ) ), 0, 12 );
$plugins = $base . '/plugins';
$runtime = $plugins . '/renamed-mcp-runtime';
if ( ! mkdir( $runtime, 0777, true ) && ! is_dir( $runtime ) ) throw new RuntimeException( 'Unable to create fixture root' );
define( 'WP_PLUGIN_DIR', $plugins );

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-mcp-class-provenance.php';

function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
function fixture_php( $class ) {
	$parts = explode( '\\', $class );
	$name = array_pop( $parts );
	$namespace = implode( '\\', $parts );
	return "<?php\nnamespace {$namespace};\nclass {$name} {}\n";
}
function write_fixture_class( $runtime, $relative, $class, $load = true ) {
	$file = $runtime . '/' . $relative;
	$dir = dirname( $file );
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) throw new RuntimeException( 'Unable to create fixture directory' );
	$content = fixture_php( $class );
	if ( false === file_put_contents( $file, $content ) ) throw new RuntimeException( 'Unable to write fixture class' );
	if ( $load ) require $file;
	return hash( 'sha256', $content );
}
function cleanup_tree( $path ) {
	if ( ! is_dir( $path ) ) return;
	$items = scandir( $path );
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) continue;
		$target = $path . '/' . $item;
		if ( is_dir( $target ) ) cleanup_tree( $target );
		else @unlink( $target );
	}
	@rmdir( $path );
}

$contract = array(
	'version' => 'fixture-0.6.1',
	'critical_files' => array(),
);
$specs = MAD4B_SCP_MCP_Class_Provenance::critical_classes();
$loaded_aliases = array( 'adapter', 'tool_validator' );
foreach ( $specs as $alias => $spec ) {
	$contract['critical_files'][ $spec['file'] ] = write_fixture_class( $runtime, $spec['file'], $spec['class'], in_array( $alias, $loaded_aliases, true ) );
}

$partial = MAD4B_SCP_MCP_Class_Provenance::inspect_contract( $contract, $runtime, array(), false );
check( empty( $partial['ready'] ), 'loaded-only inspection must not claim full certification' );
check( 'partial_certified_class_set' === $partial['state'], 'unloaded classes must classify as partial, not drift' );
check( 0 === $partial['failure_count'], 'unloaded classes must not count as provenance failures' );
check( count( $specs ) - count( $loaded_aliases ) === $partial['unobserved_count'], 'loaded-only unobserved count mismatch' );
check( '' === $partial['blocker'], 'partial loaded-only inspection must not block or trigger repair' );

foreach ( $specs as $alias => $spec ) {
	if ( in_array( $alias, $loaded_aliases, true ) ) continue;
	require $runtime . '/' . $spec['file'];
}

$ready = MAD4B_SCP_MCP_Class_Provenance::inspect_contract( $contract, $runtime );
check( ! empty( $ready['enforced'] ), 'fixture provenance must be enforced' );
check( ! empty( $ready['ready'] ), 'exact class set must be ready' );
check( 'certified_class_set' === $ready['state'], 'exact class set state mismatch' );
check( 0 === $ready['failure_count'], 'exact class set must have zero failures' );
check( $ready['class_count'] === $ready['verified_count'], 'all critical classes must verify' );

$validator = $runtime . '/includes/Domain/Tools/McpToolValidator.php';
file_put_contents( $validator, "\n// drift\n", FILE_APPEND );
clearstatcache( true, $validator );

$mixed = MAD4B_SCP_MCP_Class_Provenance::inspect_contract( $contract, $runtime );
check( empty( $mixed['ready'] ), 'drifted class set must fail closed' );
check( 'mixed_runtime' === $mixed['state'], 'single drift among verified classes must classify as mixed runtime' );
check( MAD4B_SCP_MCP_Class_Provenance::BLOCKER === $mixed['blocker'], 'mixed runtime blocker mismatch' );
check( 1 === $mixed['failure_count'], 'one drifted file must produce one class failure' );
check( 'tool_validator' === $mixed['failures'][0]['alias'], 'validator drift alias mismatch' );
check( 'runtime_class_sha256_mismatch' === $mixed['failures'][0]['reason'], 'validator drift reason mismatch' );
check( 'renamed-mcp-runtime/includes/Domain/Tools/McpToolValidator.php' === $mixed['failures'][0]['observed_source'], 'observed source must be plugin-relative and bounded' );
check( false === strpos( $mixed['failures'][0]['observed_source'], $base ), 'absolute fixture path leaked' );

$missing_contract = $contract;
unset( $missing_contract['critical_files']['includes/Domain/Utils/SchemaTransformer.php'] );
$missing = MAD4B_SCP_MCP_Class_Provenance::inspect_contract( $missing_contract, $runtime );
$reasons = array_column( $missing['failures'], 'reason', 'alias' );
check( isset( $reasons['schema_transformer'] ) && 'certified_class_hash_missing' === $reasons['schema_transformer'], 'missing certified hash must fail closed' );


$dynamic_root = $plugins . '/dynamic-mcp-runtime';
if ( ! mkdir( $dynamic_root, 0777, true ) && ! is_dir( $dynamic_root ) ) throw new RuntimeException( 'Unable to create dynamic fixture root' );
$dynamic_specs = array(
	array( 'symbol' => 'Fixture\\Dynamic\\RuntimeClass', 'kind' => 'class', 'file' => 'includes/Core/RuntimeClass.php', 'declaration' => 'class RuntimeClass {}' ),
	array( 'symbol' => 'Fixture\\Dynamic\\RuntimeInterface', 'kind' => 'interface', 'file' => 'includes/Core/RuntimeInterface.php', 'declaration' => 'interface RuntimeInterface {}' ),
	array( 'symbol' => 'Fixture\\Dynamic\\RuntimeTrait', 'kind' => 'trait', 'file' => 'includes/Core/RuntimeTrait.php', 'declaration' => 'trait RuntimeTrait {}' ),
);
$dynamic_contract = array( 'version' => 'fixture-dynamic', 'critical_files' => array(), 'runtime_symbols' => array() );
foreach ( $dynamic_specs as $spec ) {
	$file = $dynamic_root . '/' . $spec['file'];
	if ( ! is_dir( dirname( $file ) ) ) mkdir( dirname( $file ), 0777, true );
	$raw = "<?php\nnamespace Fixture\\Dynamic;\n" . $spec['declaration'] . "\n";
	file_put_contents( $file, $raw );
	require $file;
	$dynamic_contract['runtime_symbols'][] = array(
		'symbol' => $spec['symbol'],
		'kind' => $spec['kind'],
		'file' => $spec['file'],
		'git_blob_sha1' => sha1( 'blob ' . strlen( $raw ) . "\0" . $raw ),
	);
}
$dynamic_ready = MAD4B_SCP_MCP_Class_Provenance::inspect_contract( $dynamic_contract, $dynamic_root );
check( ! empty( $dynamic_ready['ready'] ) && 3 === $dynamic_ready['verified_count'], 'generated class/interface/trait surface did not verify' );
$trait_file = $dynamic_root . '/includes/Core/RuntimeTrait.php';
file_put_contents( $trait_file, "\n// dynamic drift\n", FILE_APPEND );
clearstatcache( true, $trait_file );
$dynamic_drift = MAD4B_SCP_MCP_Class_Provenance::inspect_contract( $dynamic_contract, $dynamic_root );
$dynamic_reasons = array_column( $dynamic_drift['failures'], 'reason', 'class' );
check( isset( $dynamic_reasons['Fixture\\Dynamic\\RuntimeTrait'] ) && 'runtime_symbol_blob_mismatch' === $dynamic_reasons['Fixture\\Dynamic\\RuntimeTrait'], 'generated symbol blob drift did not fail closed' );

cleanup_tree( $base );
echo "mad4b.mcp-class-provenance.v1: PASS\n";
