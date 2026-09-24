<?php
/**
 * Standalone runtime proof for exact package candidate binding.
 *
 * This deliberately avoids loading WordPress. It exercises the hot-path
 * candidate-binding predicate with a minimal option store and proves that
 * the mutation primitive cannot be invoked directly without audited context.
 * Governed mutation success is covered by staging-write-candidate-binding-runtime.php.
 */

define( 'ABSPATH', __DIR__ . '/' );

$tmp = sys_get_temp_dir() . '/mad4b-authority-candidate-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $tmp, 0700, true ) && ! is_dir( $tmp ) ) {
	fwrite( STDERR, "Unable to create candidate-binding fixture directory.\n" );
	exit( 1 );
}
define( 'MAD4B_SCP_DIR', rtrim( $tmp, '/\\' ) . DIRECTORY_SEPARATOR );

$GLOBALS['mad4b_test_options'] = array();

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['mad4b_test_options'] ) ? $GLOBALS['mad4b_test_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['mad4b_test_options'][ $key ] = $value; return true; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-authority.php';

$fail = static function ( $message ) use ( $tmp ) {
	@unlink( $tmp . '/MAD4B-BUILD-PROVENANCE.json' );
	@rmdir( $tmp );
	fwrite( STDERR, '[MAD4B authority candidate binding] ' . $message . PHP_EOL );
	exit( 1 );
};
$ok = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };

$source_a = str_repeat( 'a', 40 );
$build_a = str_repeat( 'b', 64 );
$source_b = str_repeat( 'c', 40 );
$build_b = str_repeat( 'd', 64 );
$manifest_digest = str_repeat( 'e', 64 );

$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Staging_Write_Authority::OPTION ] = array(
	'contract' => MAD4B_SCP_Staging_Write_Authority::CONTRACT,
	'ready' => true,
	'state' => 'ready',
	'blocker' => '',
	'agent_public_id' => '11111111-1111-1111-1111-111111111111',
	'write_tool_count' => 33,
	'write_inventory_fingerprint' => str_repeat( 'f', 64 ),
	'site_uuid' => '22222222-2222-2222-2222-222222222222',
	'site_profile_revision' => 2,
	'site_profile_digest' => str_repeat( '1', 64 ),
	'environment' => 'staging',
	'origin' => 'https://candidate.test',
);

// Source-tree CI compatibility: without a packaged provenance manifest, the
// existing reconciled predicate remains usable for isolated source tests.
$ok( MAD4B_SCP_Staging_Write_Authority::effective(), 'Source-tree authority unexpectedly requires a package manifest.' );

$write_manifest = static function ( $source, $build ) use ( $tmp, $manifest_digest, $fail ) {
	$data = array(
		'contract' => 'mad4b.build-provenance.v1',
		'control_plane_version' => '0.4.0-rc.35',
		'source_commit_sha' => $source,
		'build_fingerprint' => $build,
		'package_manifest_digest' => $manifest_digest,
		'artifact_identity' => 'mad4b-site-control-plane-general-distribution-kit-' . $source,
	);
	$encoded = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === file_put_contents( $tmp . '/MAD4B-BUILD-PROVENANCE.json', $encoded ) ) $fail( 'Unable to write package provenance fixture.' );
};

$reset_candidate_cache = static function () {
	$reflection = new ReflectionClass( 'MAD4B_SCP_Staging_Write_Authority' );
	$property = $reflection->getProperty( 'candidate_identity' );
	$property->setAccessible( true );
	$property->setValue( null, null );
};

$write_manifest( $source_a, $build_a );
$reset_candidate_cache();

$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
$ok( ! empty( $binding['required'] ), 'Packaged runtime did not require candidate binding.' );
$ok( empty( $binding['stored_bound'] ) && empty( $binding['match'] ), 'Unbound packaged authority unexpectedly matched current candidate.' );
$ok( ! MAD4B_SCP_Staging_Write_Authority::effective(), 'Packaged authority became effective before explicit candidate binding.' );

$wrong = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( $source_b, $build_b );
$ok( is_wp_error( $wrong ) && 'mad4b_write_authority_candidate_mismatch' === $wrong->get_error_code(), 'Wrong package candidate was not rejected.' );

$direct = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( $source_a, $build_a );
$ok( is_wp_error( $direct ) && 'mad4b_candidate_binding_audit_context_required' === $direct->get_error_code(), 'Direct exact candidate binding bypassed the mandatory audited operation context.' );
$persisted = get_option( MAD4B_SCP_Staging_Write_Authority::OPTION, array() );
$persisted['candidate_binding_contract'] = MAD4B_SCP_Staging_Write_Authority::CANDIDATE_BINDING_CONTRACT;
$persisted['source_commit_sha'] = $source_a;
$persisted['build_fingerprint'] = $build_a;
$persisted['package_manifest_digest'] = $manifest_digest;
$persisted['artifact_identity'] = 'mad4b-site-control-plane-general-distribution-kit-' . $source_a;
update_option( MAD4B_SCP_Staging_Write_Authority::OPTION, $persisted, false );
$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
$ok( ! empty( $binding['match'] ), 'Exact package candidate did not match after binding.' );
$ok( MAD4B_SCP_Staging_Write_Authority::effective(), 'Exact bound package authority is not effective.' );

// Same version and same write inventory, but a different package candidate.
// A fresh request must fail closed until the explicit reconciliation surface
// rebinds authority to the new package.
$write_manifest( $source_b, $build_b );
$reset_candidate_cache();
$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
$ok( ! empty( $binding['required'] ) && empty( $binding['match'] ), 'Same-version candidate drift was not detected.' );
$ok( ! MAD4B_SCP_Staging_Write_Authority::effective(), 'Stale candidate retained effective write authority after same-version redeploy.' );

$rebound = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( $source_b, $build_b );
$ok( is_wp_error( $rebound ) && 'mad4b_candidate_binding_audit_context_required' === $rebound->get_error_code(), 'Same-inventory direct rebind bypassed mandatory audit context.' );
$persisted = get_option( MAD4B_SCP_Staging_Write_Authority::OPTION, array() );
$persisted['candidate_binding_contract'] = MAD4B_SCP_Staging_Write_Authority::CANDIDATE_BINDING_CONTRACT;
$persisted['source_commit_sha'] = $source_b;
$persisted['build_fingerprint'] = $build_b;
$persisted['package_manifest_digest'] = $manifest_digest;
$persisted['artifact_identity'] = 'mad4b-site-control-plane-general-distribution-kit-' . $source_b;
update_option( MAD4B_SCP_Staging_Write_Authority::OPTION, $persisted, false );
$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
$ok( ! empty( $binding['match'] ), 'Rebound candidate does not match current package.' );
$ok( MAD4B_SCP_Staging_Write_Authority::effective(), 'Authority did not become effective after exact candidate rebind.' );

// Once a packaged authority is bound, removing provenance must fail closed.
@unlink( $tmp . '/MAD4B-BUILD-PROVENANCE.json' );
$reset_candidate_cache();
$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
$ok( ! empty( $binding['required'] ) && ! empty( $binding['stored_bound'] ) && empty( $binding['match'] ), 'Missing provenance did not invalidate an already package-bound authority.' );
$ok( ! MAD4B_SCP_Staging_Write_Authority::effective(), 'Authority remained effective after bound provenance disappeared.' );

@unlink( $tmp . '/MAD4B-BUILD-PROVENANCE.json' );
@rmdir( $tmp );

echo "mad4b.governed-write-authority-candidate-binding.v2: PASS\n";
