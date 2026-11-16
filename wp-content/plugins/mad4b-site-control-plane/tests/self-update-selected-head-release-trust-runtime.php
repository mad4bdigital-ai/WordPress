<?php
/**
 * QA32B: execute the current canonical immutable Release pointer / manifest
 * checks, not an obsolete method removed with the pointer-first release lane.
 * This offline fixture supplies no trusted release, Owner receipt or write grant.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) { return abs( (int) $value ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code; private $message;
        public function __construct( $code, $message = '' ) { $this->code=$code; $this->message=$message; }
        public function get_error_code() { return $this->code; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
}
function verify_selected_trust( $ok, $label ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-self-update.php';
$owner = 'MAD4B_SCP_Self_Update';
$pointer_validator = new ReflectionMethod( $owner, 'validate_pointer' );
$binding_validator = new ReflectionMethod( $owner, 'validate_pointer_manifest_binding' );
if ( PHP_VERSION_ID < 80100 ) {
    $pointer_validator->setAccessible( true );
    $binding_validator->setAccessible( true );
}
$sha = str_repeat( 'a', 40 );
$valid = array(
    'contract' => $owner::POINTER_CONTRACT,
    'repository' => 'mad4bdigital-ai/WordPress',
    'release_tag' => $owner::RELEASE_TAG,
    'source_commit_sha' => $sha,
    'manifest_asset' => 'mad4b-site-control-plane-update-' . $sha . '.json',
    'manifest_sha256' => str_repeat( 'b', 64 ),
    'release_verdict_run_id' => 40817413273,
    'release_verdict_success' => true,
    'release_root_trust_verified' => true,
    'published_from_master' => true,
);
$run_pointer = static function ( array $row ) use ( $pointer_validator ) {
    $result = $pointer_validator->invokeArgs( null, array( &$row ) );
    return array( $result, $row );
};
list( $ok, $normalized ) = $run_pointer( $valid );
verify_selected_trust( true === $ok && $normalized === $valid, 'fixed owned release pointer accepts complete exact identity only' );
$negative = array(
    array( 'contract', 'unreviewed-pointer' ),
    array( 'repository', 'foreign/WordPress' ),
    array( 'release_tag', 'unreviewed-tag' ),
    array( 'source_commit_sha', str_repeat( 'e', 40 ) ),
    array( 'manifest_asset', 'unreviewed.json' ),
    array( 'manifest_sha256', 'unsafe' ),
    array( 'release_verdict_run_id', 0 ),
    array( 'release_verdict_success', false ),
    array( 'release_root_trust_verified', false ),
    array( 'published_from_master', false ),
);
foreach ( $negative as $entry ) {
    $changed = $valid;
    $changed[ $entry[0] ] = $entry[1];
    list( $out, $ignored ) = $run_pointer( $changed );
    verify_selected_trust( is_wp_error( $out ), 'reject missing or changed release pointer: ' . $entry[0] );
}
$manifest = array(
    'source_commit_sha' => $sha,
    'release_verdict_run_id' => $valid['release_verdict_run_id'],
);
verify_selected_trust( true === $binding_validator->invoke( null, $valid, $manifest ), 'exact pointer to manifest source and run binding' );
$wrong_source = $manifest;
$wrong_source['source_commit_sha'] = str_repeat( 'e', 40 );
$wrong_verdict = $manifest;
$wrong_verdict['release_verdict_run_id'] = 99;
verify_selected_trust( is_wp_error( $binding_validator->invoke( null, $valid, $wrong_source ) ), 'reject changed manifest SHA' );
verify_selected_trust( is_wp_error( $binding_validator->invoke( null, $valid, $wrong_verdict ) ), 'reject changed verdict run' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-self-update.php' );
$fetch = explode( 'private static function fetch_manifest( $force = false )', $source, 2 );
verify_selected_trust( 2 === count( $fetch ), 'canonical immutable release fetch exists' );
$fetch = explode( 'private static function clear_manifest_cache()', $fetch[1], 2 )[0];
$steps = array(
    'self::fetch_pointer( $force )',
    "hash_equals( \$pointer['manifest_sha256'], \$actual_digest )",
    'self::validate_manifest( $manifest )',
    'self::validate_pointer_manifest_binding( $pointer, $manifest )',
    'set_transient(',
);
$position = -1;
foreach ( $steps as $step ) {
    $i = strpos( $fetch, $step );
    verify_selected_trust( false !== $i && $i > $position, 'canonical pointer/manifest verification order: ' . $step );
    $position = $i;
}
verify_selected_trust( false !== strpos( $source, 'MAD4B_SCP_WordPress_Native_Opt_In::enabled()' ), 'native candidate retains explicit site opt-in' );
verify_selected_trust( false !== strpos( $source, 'self::verify_archive( $tmp, $plan[\'target\'] )' ), 'native candidate retains exact archive hash verification' );
echo "QA32B_SELECTED_HEAD_RELEASE_TRUST: PASS canonical pointer, 10 denials, manifest binding, immutable fetch, native opt-in\n";
echo "QA32B_SELECTED_HEAD_RELEASE_TRUST: PASS\n";
