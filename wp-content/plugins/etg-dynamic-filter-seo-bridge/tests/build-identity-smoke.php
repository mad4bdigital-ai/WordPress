<?php
require_once dirname( __DIR__ ) . '/includes/Diagnostics/BuildIdentity.php';
require_once dirname( __DIR__ ) . '/includes/Runtime/BootGuard.php';

use ETG\DynamicFilterSEOBridge\Diagnostics\BuildIdentity;
use ETG\DynamicFilterSEOBridge\Runtime\BootGuard;

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

$root = sys_get_temp_dir() . '/etg-dfsb-build-identity-' . str_replace( '.', '', uniqid( '', true ) );
mkdir( $root, 0700, true );
$path = $root . '/build-identity.json';
$provenancePath = $root . '/etg-dfsb-provenance.txt';
$contentRoot = $root . '/wp-content';
mkdir( $contentRoot, 0700, true );
$git = str_repeat( '1', 40 );
$git2 = str_repeat( '3', 40 );
$tree = str_repeat( '2', 40 );
$version = '0.4.0-alpha.13';
$packageSha = str_repeat( 'a', 64 );

if ( ! defined( 'ETG_DFSB_DIR' ) ) {
	define( 'ETG_DFSB_DIR', $root . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'ETG_DFSB_VERSION' ) ) {
	define( 'ETG_DFSB_VERSION', $version );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', $contentRoot );
}

$missing = BuildIdentity::inspectFile( $path, $version );
etg_build_identity_expect( empty( $missing['embedded'] ) && empty( $missing['valid'] ), 'missing identity remains non-authorizing and invalid' );
etg_build_identity_expect( 'identity_file_missing' === $missing['reason'], 'missing identity reports the exact reason' );
etg_build_identity_expect( '' === $missing['embedded_identity_sha256'], 'missing identity has no synthetic embedded identity digest' );
etg_build_identity_expect( 'fallback:alpha13-container-background-4' === BuildIdentity::bootBuild( 'alpha13-container-background-4' ), 'source/dev checkout uses the explicit deterministic fallback key' );

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
etg_build_identity_expect( $identitySha === $valid['embedded_identity_sha256'], 'embedded identity SHA-256 is calculated from the exact live identity bytes' );
etg_build_identity_expect( empty( $valid['authorizing'] ) && ! empty( $valid['read_only'] ), 'identity evidence stays read-only and non-authorizing' );

$missingProvenance = BuildIdentity::inspectProvenanceFile( $provenancePath, $valid );
etg_build_identity_expect( empty( $missingProvenance['package_provenance_present'] ) && empty( $missingProvenance['package_provenance_valid'] ), 'missing detached provenance remains incomplete evidence' );
etg_build_identity_expect( 'package_provenance_file_missing' === $missingProvenance['package_provenance_reason'], 'missing detached provenance has an explicit reason' );

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
$provenance = BuildIdentity::inspectProvenanceFile( $provenancePath, $valid );
etg_build_identity_expect( ! empty( $provenance['package_provenance_present'] ) && ! empty( $provenance['package_provenance_valid'] ), 'detached release provenance is accepted only when bound to the live identity' );
etg_build_identity_expect( 'ok' === $provenance['package_provenance_reason'], 'valid detached provenance reports ok' );
etg_build_identity_expect( BuildIdentity::PROVENANCE_CONTRACT === $provenance['package_provenance_contract'], 'release provenance contract remains explicit' );
etg_build_identity_expect( $packageSha === $provenance['package_sha256'], 'validated package SHA-256 is exposed only from detached provenance' );

$collected = BuildIdentity::collect();
etg_build_identity_expect( ! empty( $collected['provenance_complete'] ), 'collect marks exact package provenance complete only after detached receipt validation' );
etg_build_identity_expect( $identitySha === $collected['embedded_identity_sha256'], 'collect exposes the independently calculated embedded identity SHA-256' );
etg_build_identity_expect( $packageSha === $collected['package_sha256'], 'collect exposes the detached validated package SHA-256' );
etg_build_identity_expect( 'plugin_root_fallback' === $collected['package_provenance_source'], 'legacy plugin-root receipt remains an explicit compatibility fallback' );

$persistentDir = $contentRoot . '/mad4b/provenance/etg-dfsb/' . $git;
mkdir( $persistentDir, 0700, true );
$persistentPath = $persistentDir . '/etg-dfsb-provenance.txt';
file_put_contents( $persistentPath, $validProvenance );
file_put_contents( $provenancePath, "contract=" . BuildIdentity::PROVENANCE_CONTRACT . "\ncontract=" . BuildIdentity::PROVENANCE_CONTRACT . "\n" );
$persistentCollected = BuildIdentity::collect();
etg_build_identity_expect( ! empty( $persistentCollected['provenance_complete'] ), 'SHA-addressed persistent provenance is accepted when bound to the installed identity' );
etg_build_identity_expect( 'persistent_sha_store' === $persistentCollected['package_provenance_source'], 'persistent SHA store is preferred over plugin-root fallback' );
etg_build_identity_expect( $packageSha === $persistentCollected['package_sha256'], 'persistent SHA store exposes the exact validated package digest' );

@unlink( $persistentPath );
@rmdir( $persistentDir );
@rmdir( dirname( $persistentDir ) );
@rmdir( dirname( dirname( $persistentDir ) ) );
@rmdir( dirname( dirname( dirname( $persistentDir ) ) ) );
file_put_contents( $provenancePath, $validProvenance );
$fallbackCollected = BuildIdentity::collect();
etg_build_identity_expect( 'plugin_root_fallback' === $fallbackCollected['package_provenance_source'], 'plugin-root fallback is restored only when no persistent exact-SHA receipt exists' );

file_put_contents( $provenancePath, "contract=" . BuildIdentity::PROVENANCE_CONTRACT . "\ncontract=" . BuildIdentity::PROVENANCE_CONTRACT . "\n" );
$duplicate = BuildIdentity::inspectProvenanceFile( $provenancePath, $valid );
etg_build_identity_expect( empty( $duplicate['package_provenance_valid'] ) && 'package_provenance_duplicate_key' === $duplicate['package_provenance_reason'], 'duplicate detached provenance keys fail closed' );

file_put_contents( $provenancePath, str_replace( 'embedded_identity_sha256=' . $identitySha, 'embedded_identity_sha256=' . str_repeat( 'b', 64 ), $validProvenance ) );
$identityMismatch = BuildIdentity::inspectProvenanceFile( $provenancePath, $valid );
etg_build_identity_expect( empty( $identityMismatch['package_provenance_valid'] ) && 'package_provenance_identity_sha_mismatch' === $identityMismatch['package_provenance_reason'], 'receipt bound to different embedded identity bytes fails closed' );

file_put_contents( $provenancePath, str_replace( 'package_sha256=' . $packageSha, 'package_sha256=not-a-sha', $validProvenance ) );
$badPackageSha = BuildIdentity::inspectProvenanceFile( $provenancePath, $valid );
etg_build_identity_expect( empty( $badPackageSha['package_provenance_valid'] ) && 'package_provenance_package_sha_invalid' === $badPackageSha['package_provenance_reason'], 'malformed package SHA-256 fails closed' );

file_put_contents( $provenancePath, $validProvenance );
$bootA = BuildIdentity::bootBuild( 'alpha13-container-background-4' );
etg_build_identity_expect( 'identity:' . $git . ':' . $tree === $bootA, 'Safe Boot key binds to exact embedded Git/tree identity' );

$GLOBALS['etg_dfsb_boot_guard_test_options'] = array();
BootGuard::register( $bootA );
BootGuard::holdOnFirstLoad( 'activation' );
etg_build_identity_expect( BootGuard::shouldHold(), 'new installed package starts in Safe Boot hold' );
etg_build_identity_expect( BootGuard::run( static function () {} ), 'guarded package A boot succeeds' );
etg_build_identity_expect( ! BootGuard::shouldHold(), 'successful exact package A boot clears its hold' );

file_put_contents( $path, json_encode( array(
	'contract' => BuildIdentity::CONTRACT,
	'git_sha' => $git2,
	'tree_sha' => $tree,
	'plugin_version' => $version,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
$bootB = BuildIdentity::bootBuild( 'alpha13-container-background-4' );
etg_build_identity_expect( $bootA !== $bootB, 'changing exact source SHA changes the Safe Boot key without semantic version changes' );
BootGuard::register( $bootB );
etg_build_identity_expect( BootGuard::shouldHold(), 'replaced active package re-enters Safe Boot when exact embedded identity changes' );

$wrongVersion = BuildIdentity::inspectFile( $path, '0.4.0-alpha.14' );
etg_build_identity_expect( empty( $wrongVersion['valid'] ) && 'identity_version_mismatch' === $wrongVersion['reason'], 'version mismatch fails closed' );

file_put_contents( $path, '{"contract":"' . BuildIdentity::CONTRACT . '","git_sha":"bad","tree_sha":"' . $tree . '","plugin_version":"' . $version . '"}' );
$badSha = BuildIdentity::inspectFile( $path, $version );
etg_build_identity_expect( empty( $badSha['valid'] ) && 'identity_sha_invalid' === $badSha['reason'], 'malformed SHA fails closed' );
$invalidBootA = BuildIdentity::bootBuild( 'alpha13-container-background-4' );
etg_build_identity_expect( 0 === strpos( $invalidBootA, 'identity-invalid:identity_sha_invalid:' ), 'malformed embedded identity gets a fail-closed Safe Boot key' );

file_put_contents( $path, '{"contract":"' . BuildIdentity::CONTRACT . '","git_sha":"still-bad","tree_sha":"' . $tree . '","plugin_version":"' . $version . '"}' );
$invalidBootB = BuildIdentity::bootBuild( 'alpha13-container-background-4' );
etg_build_identity_expect( $invalidBootA !== $invalidBootB, 'different malformed embedded package bytes cannot reuse a prior invalid Safe Boot key' );

file_put_contents( $path, json_encode( array(
	'contract' => BuildIdentity::CONTRACT,
	'git_sha' => $git,
	'tree_sha' => $tree,
	'plugin_version' => $version,
	'current_run_id' => 123,
) ) );
$extra = BuildIdentity::inspectFile( $path, $version );
etg_build_identity_expect( empty( $extra['valid'] ) && 'identity_fields_invalid' === $extra['reason'], 'event/run-specific fields are refused' );

@unlink( $provenancePath );
@unlink( $path );
@rmdir( $contentRoot );
@rmdir( $root );

$evidenceCommand = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/alpha13-evidence-provider-smoke.php' );
passthru( $evidenceCommand, $evidenceExitCode );
etg_build_identity_expect( 0 === $evidenceExitCode, 'bounded evidence provider smoke test passes under the canonical PHP contract job' );

$abilitiesCommand = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/alpha13-evidence-abilities-smoke.php' );
passthru( $abilitiesCommand, $abilitiesExitCode );
etg_build_identity_expect( 0 === $abilitiesExitCode, 'WordPress 6.9 ability registration lifecycle smoke passes under the canonical PHP contract job' );

$resultCountCommand = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/alpha13-result-count-observability-smoke.php' );
passthru( $resultCountCommand, $resultCountExitCode );
etg_build_identity_expect( 0 === $resultCountExitCode, 'authoritative browser result-count observability smoke passes under the canonical PHP contract job' );

$acceptanceCommand = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/alpha13-live-acceptance-provider-smoke.php' );
passthru( $acceptanceCommand, $acceptanceExitCode );
etg_build_identity_expect( 0 === $acceptanceExitCode, 'semantic live acceptance provider smoke test passes under the canonical PHP contract job' );

echo "BuildIdentity smoke passed.\n";
