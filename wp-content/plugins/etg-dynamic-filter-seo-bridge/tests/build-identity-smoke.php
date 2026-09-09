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
$git = str_repeat( '1', 40 );
$git2 = str_repeat( '3', 40 );
$tree = str_repeat( '2', 40 );
$version = '0.4.0-alpha.13';

if ( ! defined( 'ETG_DFSB_DIR' ) ) {
	define( 'ETG_DFSB_DIR', $root . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'ETG_DFSB_VERSION' ) ) {
	define( 'ETG_DFSB_VERSION', $version );
}

$missing = BuildIdentity::inspectFile( $path, $version );
etg_build_identity_expect( empty( $missing['embedded'] ) && empty( $missing['valid'] ), 'missing identity remains non-authorizing and invalid' );
etg_build_identity_expect( 'identity_file_missing' === $missing['reason'], 'missing identity reports the exact reason' );
etg_build_identity_expect( 'fallback:alpha13-container-background-4' === BuildIdentity::bootBuild( 'alpha13-container-background-4' ), 'source/dev checkout uses the explicit deterministic fallback key' );

file_put_contents( $path, json_encode( array(
	'contract' => BuildIdentity::CONTRACT,
	'git_sha' => $git,
	'tree_sha' => $tree,
	'plugin_version' => $version,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
$valid = BuildIdentity::inspectFile( $path, $version );
etg_build_identity_expect( ! empty( $valid['embedded'] ) && ! empty( $valid['valid'] ), 'valid embedded identity is accepted' );
etg_build_identity_expect( $git === $valid['git_sha'] && $tree === $valid['tree_sha'], 'source and tree SHA are preserved' );
etg_build_identity_expect( empty( $valid['authorizing'] ) && ! empty( $valid['read_only'] ), 'identity evidence stays read-only and non-authorizing' );
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

@unlink( $path );
@rmdir( $root );
echo "BuildIdentity smoke passed.\n";
