<?php
/* Independent on-demand Host Ed25519 signature test. No WordPress side writes. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'sodium_crypto_sign_seed_keypair' ) ) {
	fwrite( STDERR, "FAIL: PHP Sodium extension required for signed Host proof\n" );
	exit( 1 );
}
$test_keypair = sodium_crypto_sign_seed_keypair( str_repeat( "A", 32 ) );
$test_secret = sodium_crypto_sign_secretkey( $test_keypair );
$test_public = sodium_crypto_sign_publickey( $test_keypair );
define( 'MAD4B_SCP_HOST_ENVIRONMENT_RECEIPT_PUBLIC_KEY_B64', base64_encode( $test_public ) );
$GLOBALS['host_identity_handler'] = null;
function apply_filters( $name, $value, ...$args ) {
	if ( 'mad4b_scp_enrolled_host_identity_attestation' !== $name || ! is_callable( $GLOBALS['host_identity_handler'] ) ) return $value;
	return call_user_func( $GLOBALS['host_identity_handler'], $args[0] );
}
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-host-identity-live.php';
function expect( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: " . $message . "\n" ); exit( 1 ); }
}
$site = array(
	'configured' => true, 'authority_ready' => true,
	'origin_match' => true, 'environment_match' => true,
	'wordpress_environment_explicit' => true,
	'configured_environment' => 'staging', 'wordpress_environment' => 'staging',
	'site_uuid' => '49c562d1-8f2f-456f-b454-26816c6ba4cb',
	'canonical_origin' => 'https://staging.allroyalegypt.com',
	'revision' => 3, 'profile_digest' => str_repeat( 'a', 64 ),
);
function make_host_proof( $challenge, $secret, $issued = null ) {
	$issued = null === $issued ? time() : $issued;
	$payload = array(
		'contract' => 'mad4b.host-identity-challenge-proof.v1',
		'nonce_sha256' => hash( 'sha256', $challenge['nonce'] ),
		'site_uuid' => $challenge['site_uuid'],
		'origin' => $challenge['origin'],
		'environment' => $challenge['environment'],
		'profile_revision' => $challenge['profile_revision'],
		'profile_digest' => $challenge['profile_digest'],
		'runner_profile_id' => 'staging-runner-01',
		'target_fingerprint' => str_repeat( 'b', 64 ),
		'issued_at' => $issued,
		'expires_at' => $issued + 30,
	);
	ksort( $payload, SORT_STRING );
	return array(
		'contract' => 'mad4b.host-identity-live.v1',
		'algorithm' => 'Ed25519',
		'payload' => $payload,
		'signature_b64' => base64_encode( sodium_crypto_sign_detached(
			json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $secret
		) ),
	);
}
$challenge = MAD4B_SCP_Host_Identity_Live::challenge( $site, str_repeat( '1', 64 ) );
expect( ! empty( $challenge ), 'valid explicit staging challenge must be created' );
$proof = make_host_proof( $challenge, $test_secret );
expect( MAD4B_SCP_Host_Identity_Live::verify( $challenge, $proof ), 'signed exact challenge verifies' );
expect( ! MAD4B_SCP_Host_Identity_Live::verify(
	MAD4B_SCP_Host_Identity_Live::challenge( $site, str_repeat( '2', 64 ) ), $proof
), 'replayed signature for a new nonce must fail' );
$other = $site; $other['revision'] = 4;
expect( ! MAD4B_SCP_Host_Identity_Live::verify(
	MAD4B_SCP_Host_Identity_Live::challenge( $other, $challenge['nonce'] ), $proof
), 'copied proof must fail on changed Site Profile revision' );
$other = $site; $other['canonical_origin'] = 'https://clone.example.com';
expect( ! MAD4B_SCP_Host_Identity_Live::verify(
	MAD4B_SCP_Host_Identity_Live::challenge( $other, $challenge['nonce'] ), $proof
), 'cloned origin must not inherit proof' );
$other = $site; $other['wordpress_environment'] = 'production';
expect( empty( MAD4B_SCP_Host_Identity_Live::challenge( $other, $challenge['nonce'] ) ), 'Production target is denied' );
$other = $site; $other['origin_match'] = false;
expect( empty( MAD4B_SCP_Host_Identity_Live::challenge( $other, $challenge['nonce'] ) ), 'foreign site profile is denied' );
$second = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( str_repeat( 'B', 32 ) ) );
expect( ! MAD4B_SCP_Host_Identity_Live::verify(
	$challenge, make_host_proof( $challenge, $second )
), 'wrong Host Runner private key denied' );
expect( ! MAD4B_SCP_Host_Identity_Live::verify(
	$challenge, make_host_proof( $challenge, $test_secret, time() - 300 )
), 'expired independent signature denied' );
$tampered = $proof;
$tampered['payload']['runner_profile_id'] = 'different-runner';
expect( ! MAD4B_SCP_Host_Identity_Live::verify( $challenge, $tampered ), 'post-signature payload change denied' );
$tampered = $proof;
$tampered['invented_public_key'] = base64_encode( $test_public );
expect( ! MAD4B_SCP_Host_Identity_Live::verify( $challenge, $tampered ), 'caller supplied key and fields denied' );
expect( ! MAD4B_SCP_Host_Identity_Live::verify( $challenge, array() ), 'unsigned identity denied' );
$missing = MAD4B_SCP_Host_Identity_Live::observe( $site );
expect( ! $missing['verified'] && 'fresh_host_signature_missing_or_invalid' === $missing['state'], 'absent host adapter must fail closed' );
$GLOBALS['host_identity_handler'] = function ( $c ) use ( $test_secret ) { return make_host_proof( $c, $test_secret ); };
$captured = MAD4B_SCP_Host_Identity_Live::observe( $site );
expect( $captured['verified'] && 'fresh_host_identity_verified' === $captured['state']
	&& ! $captured['authorizing'] && ! $captured['production_authorized']
	&& ! $captured['legacy_host_operations_automatically_unlocked']
	&& $captured['one_authoritative_source'], 'valid fresh signed proof remains non-authorizing' );
echo "PASS: 13 signed live Host identity, replay, cloning and denial checks\n";
