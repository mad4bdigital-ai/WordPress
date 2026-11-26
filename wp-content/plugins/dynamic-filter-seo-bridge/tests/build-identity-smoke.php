<?php
require_once dirname( __DIR__ ) . '/includes/Diagnostics/BuildIdentity.php';

use DynamicFilterSEOBridge\Diagnostics\BuildIdentity;

$GLOBALS['etg_dfsb_boot_guard_test_options'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $acceptedArgs = 1 ) { return true; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['etg_dfsb_boot_guard_test_options'] )
			? $GLOBALS['etg_dfsb_boot_guard_test_options'][ $name ]
			: $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['etg_dfsb_boot_guard_test_options'][ $name ] = $value;
		return true;
	}
}

function etg_build_identity_expect( $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function etg_build_identity_remove_tree( string $root ): void {
	if ( ! is_dir( $root ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $entry ) {
		$entry->isDir() && ! $entry->isLink() ? @rmdir( $entry->getPathname() ) : @unlink( $entry->getPathname() );
	}
	@rmdir( $root );
}

$root = sys_get_temp_dir() . '/etg-dfsb-build-identity-' . str_replace( '.', '', uniqid( '', true ) );
$contentRoot = $root . '-wp-content';
mkdir( $root, 0700, true );
mkdir( $contentRoot, 0700, true );
$path = $root . '/build-identity.json';
$manifestPath = $root . '/' . BuildIdentity::INSTALLED_CONTENT_MANIFEST;
$provenancePath = $root . '/etg-dfsb-provenance.txt';
$payloadPath = $root . '/payload.php';
$git = str_repeat( '1', 40 );
$git2 = str_repeat( '3', 40 );
$tree = str_repeat( '2', 40 );
$version = '0.4.16';
$packageSha = str_repeat( 'a', 64 );
$payload = "<?php echo 'stable';\n";

if ( ! defined( 'DFSB_DIR' ) ) {
	define( 'DFSB_DIR', $root . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'DFSB_VERSION' ) ) {
	define( 'DFSB_VERSION', $version );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', $contentRoot );
}

$missing = BuildIdentity::inspectFile( $path, $version );
etg_build_identity_expect( empty( $missing['embedded'] ) && empty( $missing['valid'] ), 'missing identity remains non-authorizing and invalid' );
etg_build_identity_expect( 'identity_file_missing' === $missing['reason'], 'missing identity reports the exact reason' );
etg_build_identity_expect( 'fallback:alpha13-container-background-4' === BuildIdentity::bootBuild( 'alpha13-container-background-4' ), 'source/dev checkout uses the explicit deterministic fallback key' );

file_put_contents( $payloadPath, $payload );
file_put_contents( $manifestPath, json_encode( array(
	'contract' => BuildIdentity::INSTALLED_CONTENT_CONTRACT,
	'algorithm' => 'sha256',
	'files' => array( 'payload.php' => hash( 'sha256', $payload ) ),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
file_put_contents( $path, json_encode( array(
	'contract' => BuildIdentity::CONTRACT,
	'git_sha' => $git,
	'tree_sha' => $tree,
	'plugin_version' => $version,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

$valid = BuildIdentity::inspectFile( $path, $version );
$identitySha = hash_file( 'sha256', $path );
etg_build_identity_expect( ! empty( $valid['embedded'] ) && ! empty( $valid['valid'] ), 'valid embedded identity is accepted' );
etg_build_identity_expect( $git === $valid['git_sha'] && $tree === $valid['tree_sha'], 'source and tree SHA are preserved' );
etg_build_identity_expect( $identitySha === $valid['embedded_identity_sha256'], 'embedded identity SHA-256 is calculated from exact live bytes' );

$installed = BuildIdentity::inspectInstalledContentManifest( $root, $manifestPath );
etg_build_identity_expect( ! empty( $installed['installed_content_valid'] ), 'embedded installed-content manifest validates the exact installed file set' );
etg_build_identity_expect( BuildIdentity::INSTALLED_CONTENT_CONTRACT === $installed['installed_content_contract'], 'installed-content contract remains explicit' );
etg_build_identity_expect( 1 === $installed['installed_content_file_count'], 'installed-content verifier counts exact manifest members' );

$selfContained = BuildIdentity::collect();
etg_build_identity_expect( ! empty( $selfContained['provenance_complete'] ), 'missing optional detached receipt no longer blocks exact installed-build provenance' );
etg_build_identity_expect( 'self_contained_installed_manifest' === $selfContained['provenance_mode'], 'self-contained installed manifest is the canonical runtime provenance mode' );
etg_build_identity_expect( 'optional_detached_receipt_missing' === $selfContained['package_provenance_reason'], 'missing detached receipt is reported as optional evidence' );
etg_build_identity_expect( 'none' === $selfContained['package_provenance_source'], 'no detached receipt produces no synthetic package source' );
etg_build_identity_expect( empty( $selfContained['package_provenance_required'] ), 'detached package receipt is optional unless explicitly configured' );
etg_build_identity_expect( 'ok' === $selfContained['exact_build_reason'], 'self-contained exact installed build closes without a sidecar' );

file_put_contents( $payloadPath, "<?php echo 'tampered';\n" );
$tampered = BuildIdentity::collect();
etg_build_identity_expect( empty( $tampered['provenance_complete'] ), 'tampered installed file fails exact-build provenance' );
etg_build_identity_expect( 0 === strpos( $tampered['installed_content_reason'], 'installed_content_file_hash_mismatch:' ), 'tampered file reports exact hash mismatch' );
file_put_contents( $payloadPath, $payload );

file_put_contents( $root . '/unexpected.txt', 'extra' );
$extraFile = BuildIdentity::collect();
etg_build_identity_expect( empty( $extraFile['provenance_complete'] ) && 'installed_content_file_set_mismatch' === $extraFile['installed_content_reason'], 'unexpected installed file fails closed' );
@unlink( $root . '/unexpected.txt' );

$validProvenance = implode( "\n", array(
	'contract=' . BuildIdentity::PROVENANCE_CONTRACT,
	'git_sha=' . $git,
	'tree_sha=' . $tree,
	'plugin_version=' . $version,
	'package_sha256=' . $packageSha,
	'embedded_identity_contract=' . BuildIdentity::CONTRACT,
	'embedded_identity_sha256=' . $identitySha,
	'push_run_id=123',
	'pr_run_id=456',
	'current_event=pull_request',
) ) . "\n";
file_put_contents( $provenancePath, $validProvenance );
$withReceipt = BuildIdentity::collect();
etg_build_identity_expect( ! empty( $withReceipt['provenance_complete'] ), 'valid optional detached receipt augments self-contained provenance' );
etg_build_identity_expect( ! empty( $withReceipt['package_provenance_valid'] ) && $packageSha === $withReceipt['package_sha256'], 'valid detached receipt still exposes exact package SHA' );
etg_build_identity_expect( 'plugin_root_fallback' === $withReceipt['package_provenance_source'], 'plugin-root receipt remains compatibility evidence' );

file_put_contents( $provenancePath, "contract=" . BuildIdentity::PROVENANCE_CONTRACT . "\ncontract=" . BuildIdentity::PROVENANCE_CONTRACT . "\n" );
$conflict = BuildIdentity::collect();
etg_build_identity_expect( empty( $conflict['provenance_complete'] ), 'present but invalid detached receipt remains fail-closed' );
etg_build_identity_expect( 'package_provenance_duplicate_key' === $conflict['package_provenance_reason'], 'invalid detached receipt reports its exact conflict' );

$persistentDir = $contentRoot . '/mad4b/provenance/etg-dfsb/' . $git;
mkdir( $persistentDir, 0700, true );
$persistentPath = $persistentDir . '/etg-dfsb-provenance.txt';
file_put_contents( $persistentPath, $validProvenance );
$persistent = BuildIdentity::collect();
etg_build_identity_expect( ! empty( $persistent['provenance_complete'] ), 'valid persistent receipt augments self-contained provenance' );
etg_build_identity_expect( 'persistent_sha_store' === $persistent['package_provenance_source'], 'persistent exact-SHA receipt remains preferred when present' );
@unlink( $persistentPath );

@unlink( $provenancePath );
$bootA = BuildIdentity::bootBuild( 'alpha13-container-background-4' );
etg_build_identity_expect( 'identity:' . $git . ':' . $tree === $bootA, 'Safe Boot key binds to exact embedded Git/tree identity' );
file_put_contents( $path, json_encode( array(
	'contract' => BuildIdentity::CONTRACT,
	'git_sha' => $git2,
	'tree_sha' => $tree,
	'plugin_version' => $version,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
$bootB = BuildIdentity::bootBuild( 'alpha13-container-background-4' );
etg_build_identity_expect( $bootA !== $bootB, 'changing exact source SHA changes the Safe Boot key without semantic version changes' );

$wrongVersion = BuildIdentity::inspectFile( $path, '0.4.0-alpha.14' );
etg_build_identity_expect( empty( $wrongVersion['valid'] ) && 'identity_version_mismatch' === $wrongVersion['reason'], 'version mismatch fails closed' );
file_put_contents( $path, '{"contract":"' . BuildIdentity::CONTRACT . '","git_sha":"bad","tree_sha":"' . $tree . '","plugin_version":"' . $version . '"}' );
$badSha = BuildIdentity::inspectFile( $path, $version );
etg_build_identity_expect( empty( $badSha['valid'] ) && 'identity_sha_invalid' === $badSha['reason'], 'malformed SHA fails closed' );

etg_build_identity_remove_tree( $contentRoot );
etg_build_identity_remove_tree( $root );
echo "Neutral BuildIdentity isolated fixture: PASS; package and boot gates tested separately\n";
