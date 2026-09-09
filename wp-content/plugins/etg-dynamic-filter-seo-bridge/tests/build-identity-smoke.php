<?php
require_once dirname( __DIR__ ) . '/includes/Diagnostics/BuildIdentity.php';

use ETG\DynamicFilterSEOBridge\Diagnostics\BuildIdentity;

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
$tree = str_repeat( '2', 40 );
$version = '0.4.0-alpha.13';

$missing = BuildIdentity::inspectFile( $path, $version );
etg_build_identity_expect( empty( $missing['embedded'] ) && empty( $missing['valid'] ), 'missing identity remains non-authorizing and invalid' );
etg_build_identity_expect( 'identity_file_missing' === $missing['reason'], 'missing identity reports the exact reason' );

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

$wrongVersion = BuildIdentity::inspectFile( $path, '0.4.0-alpha.14' );
etg_build_identity_expect( empty( $wrongVersion['valid'] ) && 'identity_version_mismatch' === $wrongVersion['reason'], 'version mismatch fails closed' );

file_put_contents( $path, '{"contract":"' . BuildIdentity::CONTRACT . '","git_sha":"bad","tree_sha":"' . $tree . '","plugin_version":"' . $version . '"}' );
$badSha = BuildIdentity::inspectFile( $path, $version );
etg_build_identity_expect( empty( $badSha['valid'] ) && 'identity_sha_invalid' === $badSha['reason'], 'malformed SHA fails closed' );

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
