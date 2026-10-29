<?php
/* QA32B hermetic trusted Release -> Staging candidate continuation admission. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
function absint( $value ) { return abs( (int) $value ); }
function verify_selected_trust( $ok, $label ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-self-update.php';
$ref = new ReflectionMethod( 'MAD4B_SCP_Self_Update', 'trusted_master_release_candidate' );
$valid = array(
    'version' => '0.4.0-rc.104',
    'display_version' => '0.4.0-rc.104+build.0c6a80b',
    'source_commit_sha' => str_repeat( 'a', 40 ),
    'archive_sha256' => str_repeat( 'b', 64 ),
    'build_fingerprint' => str_repeat( 'c', 64 ),
    'package_manifest_digest' => str_repeat( 'd', 64 ),
    'size_bytes' => 15808576,
    'release_verdict_run_id' => 40817413273,
    'release_verdict_success' => true,
    'release_root_trust_verified' => true,
    'published_from_master' => true,
);
$identity = array_intersect_key( $valid, array_flip( array(
    'version', 'source_commit_sha', 'archive_sha256',
    'build_fingerprint', 'package_manifest_digest', 'size_bytes',
) ) );
$out = $ref->invoke( null, $identity, $valid );
verify_selected_trust( is_array( $out ) && count( $out ) >= 10, 'trusted manifest projects root evidence' );
foreach ( array( 'release_verdict_success', 'release_root_trust_verified', 'published_from_master' ) as $key )
    verify_selected_trust( $out[ $key ] === true, 'master release flag ' . $key );
verify_selected_trust( $out['release_verdict_run_id'] === 40817413273, 'exact trusted verdict run' );
$testcases = array(
    array( 'release_verdict_success', false ),
    array( 'release_root_trust_verified', false ),
    array( 'published_from_master', false ),
    array( 'release_verdict_run_id', 0 ),
    array( 'version', '0.4.0-rc.103' ),
    array( 'source_commit_sha', str_repeat( 'e', 40 ) ),
    array( 'archive_sha256', str_repeat( 'f', 64 ) ),
    array( 'build_fingerprint', str_repeat( '1', 64 ) ),
    array( 'package_manifest_digest', str_repeat( '2', 64 ) ),
    array( 'size_bytes', 15808577 ),
);
foreach ( $testcases as $entry ) {
    $changed = $valid;
    $changed[ $entry[0] ] = $entry[1];
    verify_selected_trust( array() === $ref->invoke( null, $identity, $changed ),
        'reject changed or untrusted ' . $entry[0] );
}
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-self-update.php' );
verify_selected_trust( strpos( $source, "'wordpress_native_candidate_upload' === \$plan['channel']" ) !== false,
    'trust binding isolated to WordPress-native candidate channel' );
verify_selected_trust( strpos( $source, 'self::fetch_manifest( true )' ) !== false,
    'trust derives from canonical fixed release fetch' );
verify_selected_trust( strpos( $source, "self::trusted_master_release_candidate( \$plan['target'], \$trusted_manifest )" ) !== false,
    'verified candidate target required' );
echo 'QA32B_SELECTED_HEAD_RELEASE_TRUST: PASS 18 assertions' . PHP_EOL;
