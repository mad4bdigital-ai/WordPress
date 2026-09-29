<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.83' );
define( 'MAD4B_SCP_FILE', __DIR__ . '/mad4b-site-control-plane/mad4b-site-control-plane.php' );

final class WP_Error {
	private $code;
	private $data;
	public function __construct( $code, $message = '', $data = array() ) { unset( $message ); $this->code = (string) $code; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_url( $url ) { return parse_url( $url ); }

$GLOBALS['mad4b_transients'] = array();
$GLOBALS['mad4b_requests'] = array();
$GLOBALS['mad4b_pointer_mode'] = 'ok';
$GLOBALS['mad4b_manifest_tamper'] = false;

$source = str_repeat( 'a', 40 );
$manifest = array(
	'contract' => 'mad4b.control-plane-update-channel.v1',
	'repository' => 'mad4bdigital-ai/WordPress',
	'release_tag' => 'mad4b-site-control-plane-update-channel',
	'version' => '0.4.0-rc.84',
	'source_commit_sha' => $source,
	'archive_sha256' => str_repeat( 'b', 64 ),
	'build_fingerprint' => str_repeat( 'c', 64 ),
	'package_manifest_digest' => str_repeat( 'd', 64 ),
	'size_bytes' => 12345,
	'package_url' => 'https://github.com/mad4bdigital-ai/WordPress/releases/download/mad4b-site-control-plane-update-channel/mad4b-site-control-plane-' . $source . '.zip',
	'package_workflow_run_id' => 101,
	'release_verdict_run_id' => 202,
	'release_verdict_success' => true,
	'release_root_trust_verified' => true,
	'published_from_master' => true,
);
$GLOBALS['mad4b_manifest_body'] = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
$GLOBALS['mad4b_pointer'] = array(
	'contract' => 'mad4b.control-plane-update-pointer.v1',
	'repository' => 'mad4bdigital-ai/WordPress',
	'release_tag' => 'mad4b-site-control-plane-update-channel',
	'source_commit_sha' => $source,
	'manifest_asset' => 'mad4b-site-control-plane-update-' . $source . '.json',
	'manifest_sha256' => hash( 'sha256', $GLOBALS['mad4b_manifest_body'] ),
	'release_verdict_run_id' => 202,
	'release_verdict_success' => true,
	'release_root_trust_verified' => true,
	'published_from_master' => true,
);

function get_transient( $key ) { return $GLOBALS['mad4b_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { unset( $ttl ); $GLOBALS['mad4b_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['mad4b_transients'][ $key ] ); return true; }
function wp_safe_remote_get( $url, $args = array() ) {
	unset( $args );
	$GLOBALS['mad4b_requests'][] = $url;
	if ( false !== strpos( $url, 'mad4b-site-control-plane-update-pointer.json' ) ) {
		if ( 'network_error' === $GLOBALS['mad4b_pointer_mode'] ) return new WP_Error( 'simulated_network_error' );
		if ( 'invalid_contract' === $GLOBALS['mad4b_pointer_mode'] ) {
			$bad = $GLOBALS['mad4b_pointer'];
			$bad['contract'] = 'invalid.pointer.contract';
			return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $bad ) );
		}
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $GLOBALS['mad4b_pointer'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	if ( false !== strpos( $url, 'mad4b-site-control-plane-update-' . str_repeat( 'a', 40 ) . '.json' ) ) {
		$body = $GLOBALS['mad4b_manifest_body'];
		if ( $GLOBALS['mad4b_manifest_tamper'] ) $body .= " ";
		return array( 'response' => array( 'code' => 200 ), 'body' => $body );
	}
	if ( false !== strpos( $url, 'mad4b-site-control-plane-update.json' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => $GLOBALS['mad4b_manifest_body'] );
	}
	return array( 'response' => array( 'code' => 404 ), 'body' => '' );
}
function wp_remote_retrieve_response_code( $response ) { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $response ) { return (string) ( $response['body'] ?? '' ); }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-self-update.php';

$fetch = new ReflectionMethod( 'MAD4B_SCP_Self_Update', 'fetch_manifest' );
$fetch->setAccessible( true );

$result = $fetch->invoke( null, true );
if ( is_wp_error( $result ) || 'pointer_immutable' !== ( $result['_mad4b_resolution'] ?? '' ) ) {
	fwrite( STDERR, "pointer-first manifest resolution failed\n" );
	exit( 1 );
}
if ( count( $GLOBALS['mad4b_requests'] ) !== 2
	|| false === strpos( $GLOBALS['mad4b_requests'][0], 'mad4b-site-control-plane-update-pointer.json?mad4b_cb=' )
	|| false === strpos( $GLOBALS['mad4b_requests'][1], 'mad4b-site-control-plane-update-' . $source . '.json?mad4b_manifest_sha256=' ) ) {
	fwrite( STDERR, "pointer-first request order/derivation failed\n" );
	exit( 2 );
}

$GLOBALS['mad4b_requests'] = array();
$GLOBALS['mad4b_manifest_tamper'] = true;
$bad_digest = $fetch->invoke( null, true );
if ( ! is_wp_error( $bad_digest ) || 'mad4b_self_update_manifest_digest_mismatch' !== $bad_digest->get_error_code() ) {
	fwrite( STDERR, "immutable manifest digest mismatch did not fail closed\n" );
	exit( 3 );
}
foreach ( $GLOBALS['mad4b_requests'] as $request ) {
	if ( false !== strpos( $request, 'mad4b-site-control-plane-update.json' ) && false === strpos( $request, 'update-pointer.json' ) ) {
		fwrite( STDERR, "integrity failure incorrectly fell back to legacy manifest\n" );
		exit( 4 );
	}
}

$GLOBALS['mad4b_requests'] = array();
$GLOBALS['mad4b_manifest_tamper'] = false;
$GLOBALS['mad4b_pointer_mode'] = 'invalid_contract';
$bad_pointer = $fetch->invoke( null, true );
if ( ! is_wp_error( $bad_pointer ) || 'mad4b_self_update_pointer_contract_mismatch' !== $bad_pointer->get_error_code() || 1 !== count( $GLOBALS['mad4b_requests'] ) ) {
	fwrite( STDERR, "invalid pointer did not fail closed without legacy fallback\n" );
	exit( 5 );
}

$GLOBALS['mad4b_requests'] = array();
$GLOBALS['mad4b_pointer_mode'] = 'network_error';
$fallback = $fetch->invoke( null, true );
if ( is_wp_error( $fallback ) || 'legacy_stable_fallback' !== ( $fallback['_mad4b_resolution'] ?? '' ) ) {
	fwrite( STDERR, "network-unavailable pointer did not use bounded legacy fallback\n" );
	exit( 6 );
}
if ( count( $GLOBALS['mad4b_requests'] ) !== 2 || false === strpos( $GLOBALS['mad4b_requests'][1], 'mad4b-site-control-plane-update.json?mad4b_cb=' ) ) {
	fwrite( STDERR, "legacy fallback did not remain fixed-channel and cache-busted\n" );
	exit( 7 );
}

echo "mad4b.control-plane-update-pointer.v1 runtime: PASS\n";
