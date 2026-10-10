<?php
/* Configured local socket must never fall back to another signer/provider. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'sodium_crypto_sign_seed_keypair' ) ) { fwrite( STDERR, "FAIL Sodium required\n" ); exit( 1 ); }
$keypair = sodium_crypto_sign_seed_keypair( str_repeat( 'A', 32 ) );
define( 'MAD4B_SCP_HOST_ENVIRONMENT_RECEIPT_PUBLIC_KEY_B64', base64_encode( sodium_crypto_sign_publickey( $keypair ) ) );
define( 'MAD4B_SCP_HOST_IDENTITY_SOCKET', '/run/mad4b-host-runner/not-running.sock' );
$GLOBALS['unexpected_fallback_calls'] = 0;
function apply_filters( $name, $value, ...$args ) {
	$GLOBALS['unexpected_fallback_calls']++;
	return array( 'verified' => true );
}
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
final class MAD4B_SCP_Host_Bridge {
	public static $test_target = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	public static function target_fingerprint_readonly() { return self::$test_target; }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-host-identity-live.php';
$site = array(
	'configured' => true, 'authority_ready' => true,
	'origin_match' => true, 'environment_match' => true,
	'wordpress_environment_explicit' => true,
	'configured_environment' => 'staging', 'wordpress_environment' => 'staging',
	'site_uuid' => '49c562d1-8f2f-456f-b454-26816c6ba4cb',
	'canonical_origin' => 'https://staging.allroyalegypt.com',
	'revision' => 3, 'profile_digest' => str_repeat( 'a', 64 ),
);
$result = MAD4B_SCP_Host_Identity_Live::observe( $site );
if ( ! empty( $result['verified'] )
	|| 'fresh_host_signature_missing_or_invalid' !== ( $result['state'] ?? '' )
	|| 'enrolled_local_unix_socket' !== ( $result['transport'] ?? '' )
	|| ! empty( $GLOBALS['unexpected_fallback_calls'] )
	|| ! empty( $result['legacy_host_operations_automatically_unlocked'] ) ) {
	fwrite( STDERR, "FAIL: unavailable Host socket must fail closed with zero alternative provider calls\n" );
	exit( 1 );
}
echo "PASS: configured Host socket denial with zero alternate-source fallback\n";
