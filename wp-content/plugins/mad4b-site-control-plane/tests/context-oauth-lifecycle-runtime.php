<?php

define( 'ABSPATH', '/tmp/mad4b-context-oauth-lifecycle/' );
define( 'MAD4B_GOOGLE_DRIVE_CLIENT_ID', 'client-id.apps.googleusercontent.com' );
define( 'MAD4B_GOOGLE_DRIVE_CLIENT_SECRET', 'client-secret-fixture' );
define( 'MAD4B_GOOGLE_MANAGED_OAUTH_BROKER_URL', 'https://auth.example.test' );
define( 'MAD4B_GOOGLE_MANAGED_OAUTH_SITE_KEY_ID', 'context-staging-v1' );
define( 'MAD4B_GOOGLE_MANAGED_OAUTH_SITE_SECRET', 'managed-google-site-signing-secret-fixture-0123456789' );
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['mad4b_context_options'] = array();
$GLOBALS['mad4b_context_transients'] = array();
$GLOBALS['mad4b_context_token_responses'] = array();
$GLOBALS['mad4b_context_revoke_status'] = 200;
$GLOBALS['mad4b_context_last_token_request'] = array();
$GLOBALS['mad4b_managed_requests'] = array();
$GLOBALS['mad4b_managed_redeem_responses'] = array();
$GLOBALS['mad4b_managed_refresh_responses'] = array();
$GLOBALS['mad4b_managed_site_nonces'] = array();

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code = (string) $code; $this->message = (string) $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can() { return true; }
function get_current_user_id() { return 7; }
function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : ''; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_email( $value ) { return (string) $value; }
function esc_url_raw( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }
function admin_url( $path = '' ) { return 'https://staging.example.test/wp-admin/' . ltrim( $path, '/' ); }
function home_url( $path = '' ) { return 'https://staging.example.test/' . ltrim( $path, '/' ); }
function wp_salt() { return 'mad4b-context-oauth-runtime-salt'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_generate_password() { return 'context-oauth-state'; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ); }
function set_transient( $name, $value ) { $GLOBALS['mad4b_context_transients'][ $name ] = $value; return true; }
function get_transient( $name ) { return isset( $GLOBALS['mad4b_context_transients'][ $name ] ) ? $GLOBALS['mad4b_context_transients'][ $name ] : false; }
function delete_transient( $name ) { unset( $GLOBALS['mad4b_context_transients'][ $name ] ); return true; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ? $GLOBALS['mad4b_context_options'][ $name ] : $default; }
function add_option( $name, $value ) {
	if ( array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ) return false;
	$GLOBALS['mad4b_context_options'][ $name ] = $value;
	return true;
}
function update_option( $name, $value ) {
	$changed = ! array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) || $GLOBALS['mad4b_context_options'][ $name ] !== $value;
	$GLOBALS['mad4b_context_options'][ $name ] = $value;
	return $changed;
}
function delete_option( $name ) {
	if ( ! array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ) return false;
	unset( $GLOBALS['mad4b_context_options'][ $name ] );
	return true;
}
function wp_remote_retrieve_response_code( $response ) { return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0; }
function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? (string) $response['body'] : ''; }
function mad4b_assert_managed_site_signature( $operation, $args ) {
	$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
	foreach ( array( 'X-MAD4B-Site-Key-ID', 'X-MAD4B-Site-Timestamp', 'X-MAD4B-Site-Nonce', 'X-MAD4B-Site-Signature' ) as $required ) {
		if ( empty( $headers[ $required ] ) ) throw new RuntimeException( 'Missing managed site auth header: ' . $required );
	}
	if ( MAD4B_GOOGLE_MANAGED_OAUTH_SITE_KEY_ID !== $headers['X-MAD4B-Site-Key-ID'] ) throw new RuntimeException( 'Managed site key ID mismatch.' );
	$timestamp = (string) $headers['X-MAD4B-Site-Timestamp'];
	$nonce = (string) $headers['X-MAD4B-Site-Nonce'];
	$signature = strtolower( (string) $headers['X-MAD4B-Site-Signature'] );
	if ( ! preg_match( '/^\d{10}$/', $timestamp ) ) throw new RuntimeException( 'Managed site timestamp invalid.' );
	if ( abs( time() - (int) $timestamp ) > 5 ) throw new RuntimeException( 'Managed site timestamp outside test clock window.' );
	if ( ! preg_match( '/^[A-Za-z0-9_-]{22,128}$/', $nonce ) ) throw new RuntimeException( 'Managed site nonce invalid.' );
	if ( isset( $GLOBALS['mad4b_managed_site_nonces'][ $nonce ] ) ) throw new RuntimeException( 'Managed site nonce replayed.' );
	$GLOBALS['mad4b_managed_site_nonces'][ $nonce ] = true;
	$body = isset( $args['body'] ) ? (string) $args['body'] : '';
	$path = '/v1/google/oauth/' . $operation;
	$signing_payload = implode( "\n", array(
		MAD4B_SCP_Google_Drive_Context::MANAGED_SITE_AUTH_SCHEME,
		'POST',
		$path,
		$timestamp,
		$nonce,
		hash( 'sha256', $body ),
	) );
	$expected = hash_hmac( 'sha256', $signing_payload, MAD4B_GOOGLE_MANAGED_OAUTH_SITE_SECRET );
	if ( ! hash_equals( $expected, $signature ) ) throw new RuntimeException( 'Managed site HMAC signature mismatch.' );
}
function wp_remote_post( $url, $args = array() ) {
	if ( false !== strpos( $url, 'auth.example.test/v1/google/oauth/session' ) ) {
		mad4b_assert_managed_site_signature( 'session', $args );
		$payload = json_decode( isset( $args['body'] ) ? (string) $args['body'] : '', true );
		$GLOBALS['mad4b_managed_requests']['session'] = is_array( $payload ) ? $payload : array();
		return array(
			'response' => array( 'code' => 200 ),
			'body' => json_encode( array(
				'contract' => MAD4B_SCP_Google_Drive_Context::MANAGED_SESSION_CONTRACT,
				'session_id' => 'managed-session-fixture',
				'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth?client_id=managed-fixture',
			) ),
		);
	}
	if ( false !== strpos( $url, 'auth.example.test/v1/google/oauth/redeem' ) ) {
		mad4b_assert_managed_site_signature( 'redeem', $args );
		$payload = json_decode( isset( $args['body'] ) ? (string) $args['body'] : '', true );
		$GLOBALS['mad4b_managed_requests']['redeem'] = is_array( $payload ) ? $payload : array();
		if ( empty( $GLOBALS['mad4b_managed_redeem_responses'] ) ) return new WP_Error( 'managed_redeem_fixture_exhausted', 'No managed redeem fixture remains.' );
		$data = array_shift( $GLOBALS['mad4b_managed_redeem_responses'] );
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $data ) );
	}
	if ( false !== strpos( $url, 'auth.example.test/v1/google/oauth/refresh' ) ) {
		mad4b_assert_managed_site_signature( 'refresh', $args );
		$payload = json_decode( isset( $args['body'] ) ? (string) $args['body'] : '', true );
		$GLOBALS['mad4b_managed_requests']['refresh'] = is_array( $payload ) ? $payload : array();
		if ( empty( $GLOBALS['mad4b_managed_refresh_responses'] ) ) return new WP_Error( 'managed_refresh_fixture_exhausted', 'No managed refresh fixture remains.' );
		$data = array_shift( $GLOBALS['mad4b_managed_refresh_responses'] );
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $data ) );
	}
	if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
		$GLOBALS['mad4b_context_last_token_request'] = $args;
		if ( empty( $GLOBALS['mad4b_context_token_responses'] ) ) return new WP_Error( 'token_fixture_exhausted', 'No OAuth token fixture remains.' );
		$data = array_shift( $GLOBALS['mad4b_context_token_responses'] );
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $data ) );
	}
	if ( false !== strpos( $url, 'oauth2.googleapis.com/revoke' ) ) {
		return array( 'response' => array( 'code' => (int) $GLOBALS['mad4b_context_revoke_status'] ), 'body' => '' );
	}
	return new WP_Error( 'unexpected_post', 'Unexpected HTTP POST target.' );
}
function wp_remote_get( $url, $args = array() ) {
	if ( false !== strpos( $url, '/about?' ) ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body' => json_encode(
				array(
					'user' => array(
						'displayName' => 'Context Operator',
						'emailAddress' => 'operator@example.test',
						'permissionId' => 'permission-fixture',
					),
				)
			),
		);
	}
	return new WP_Error( 'unexpected_get', 'Unexpected HTTP GET target.' );
}
function wp_check_invalid_utf8( $value ) { return (string) $value; }

class MAD4B_SCP_Site_Profile {
	public static function origin_enrolled() { return true; }
	public static function site_urls_match_enrollment() { return true; }
	public static function site_origin() { return 'https://staging.example.test'; }
	public static function site_uuid() { return '11111111-1111-4111-8111-111111111111'; }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-google-drive-context.php';

function mad4b_oauth_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$managed_vector_body = array(
	'requested_scope' => 'https://www.googleapis.com/auth/drive.readonly',
	'origin' => 'https://staging.egypttourgates.com',
	'contract' => 'mad4b.google-managed-oauth-session.v1',
	'site_uuid' => 'd745d81f-6fc4-5c6a-99dd-d953c92137bf',
	'callback_uri' => 'https://staging.egypttourgates.com/wp-admin/admin-post.php?action=mad4b_context_google_managed_callback',
	'verifier_method' => 'S256',
	'access_mode' => 'read_only',
	'state' => 'state-fixture-abcdefghijklmnopqrstuvwxyz0123456789',
	'verifier_challenge' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghi_jklmnopqrstu-1234567890',
);
$managed_canonical_reflection = new ReflectionMethod( 'MAD4B_SCP_Google_Drive_Context', 'managed_canonical_json' );
$managed_canonical_reflection->setAccessible( true );
$managed_vector_canonical = $managed_canonical_reflection->invoke( null, $managed_vector_body );
mad4b_oauth_assert( ! is_wp_error( $managed_vector_canonical ), 'Managed Google canonical JSON vector must serialize.', $managed_vector_canonical );
$managed_vector_body_hash = hash( 'sha256', $managed_vector_canonical );
mad4b_oauth_assert(
	'337c650bf992ce6108a3f8ee69c8ba04bec319f70de266027c4c372b9d6483f1' === $managed_vector_body_hash,
	'PHP Managed Google canonical body hash drifted from the Node cross-language contract.',
	array( 'canonical' => $managed_vector_canonical, 'sha256' => $managed_vector_body_hash )
);
$managed_vector_payload = implode( "\n", array(
	MAD4B_SCP_Google_Drive_Context::MANAGED_SITE_AUTH_SCHEME,
	'POST',
	'/v1/google/oauth/session',
	'1760000000',
	'abcdefghijklmnopqrstuvwxYZ012345',
	$managed_vector_body_hash,
) );
$managed_vector_signature = hash_hmac( 'sha256', $managed_vector_payload, 'site-broker-secret-fixture-0123456789abcdef' );
mad4b_oauth_assert(
	'0cb44b37a06baa3d48050474bee31a1a519eef22651d8be7295c840066966e48' === $managed_vector_signature,
	'PHP Managed Google site HMAC drifted from the Node cross-language contract.',
	$managed_vector_signature
);

$GLOBALS['mad4b_context_token_responses'][] = array(
	'access_token' => 'access-read-fixture',
	'refresh_token' => 'refresh-read-fixture',
	'expires_in' => 3600,
	'scope' => MAD4B_SCP_Google_Drive_Context::READ_SCOPE,
);
$url = MAD4B_SCP_Google_Drive_Context::authorization_url( 'read_only' );
mad4b_oauth_assert( ! is_wp_error( $url ), 'Read-only OAuth authorization URL must be created.', $url );
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $oauth_query );
mad4b_oauth_assert( isset( $oauth_query['code_challenge_method'] ) && 'S256' === $oauth_query['code_challenge_method'], 'Google OAuth authorization must use PKCE S256.', $oauth_query );
mad4b_oauth_assert( ! empty( $oauth_query['code_challenge'] ), 'Google OAuth authorization must include a PKCE challenge.', $oauth_query );
mad4b_oauth_assert( ! isset( $oauth_query['code_verifier'] ), 'PKCE verifier must never be sent in the browser authorization URL.', $oauth_query );
$read = MAD4B_SCP_Google_Drive_Context::complete_oauth( 'code-read', 'context-oauth-state' );
mad4b_oauth_assert( ! empty( $GLOBALS['mad4b_context_last_token_request']['body']['code_verifier'] ), 'OAuth token exchange must include the server-side PKCE verifier.' );
mad4b_oauth_assert( ! is_wp_error( $read ), 'Read-only OAuth exchange must succeed.', $read );
mad4b_oauth_assert( ! empty( $read['connected'] ) && ! empty( $read['read_available'] ), 'Read-only connection must expose read capability.', $read );
mad4b_oauth_assert( empty( $read['write_available'] ) && 'read_only' === $read['access_mode'], 'Read-only connection must not expose Drive writes.', $read );

$GLOBALS['mad4b_context_token_responses'][] = array(
	'access_token' => 'access-write-fixture',
	'refresh_token' => 'refresh-write-fixture',
	'expires_in' => 3600,
	'scope' => MAD4B_SCP_Google_Drive_Context::WRITE_SCOPE,
);
$url = MAD4B_SCP_Google_Drive_Context::authorization_url( 'read_write' );
mad4b_oauth_assert( ! is_wp_error( $url ), 'Read+Write OAuth authorization URL must be created.', $url );
$write = MAD4B_SCP_Google_Drive_Context::complete_oauth( 'code-write', 'context-oauth-state' );
mad4b_oauth_assert( ! is_wp_error( $write ), 'Explicit Read+Write OAuth exchange must succeed.', $write );
mad4b_oauth_assert( ! empty( $write['write_available'] ) && 'read_write' === $write['access_mode'], 'Read+Write connection must expose provider write capability.', $write );
mad4b_oauth_assert( MAD4B_SCP_Google_Drive_Context::WRITE_SCOPE === $write['scope'], 'Read+Write connection must retain exact governed full Drive scope only.', $write );

$public_write = MAD4B_SCP_Google_Drive_Context::public_connection_status();
mad4b_oauth_assert( 'mad4b.google-drive-public-connection.v1' === $public_write['contract'], 'Public Drive status contract mismatch.', $public_write );

$runtime_readiness = MAD4B_SCP_Google_Drive_Context::runtime_readiness();
mad4b_oauth_assert( 'mad4b.google-drive-context-runtime-readiness.v1' === $runtime_readiness['contract'], 'Context runtime readiness contract mismatch.', $runtime_readiness );
mad4b_oauth_assert( ! empty( $runtime_readiness['oauth']['pkce_s256'] ) && empty( $runtime_readiness['secrets_exposed'] ), 'Runtime readiness must prove PKCE and secret non-disclosure.', $runtime_readiness );
$readiness_json = wp_json_encode( $runtime_readiness );
foreach ( array( 'client-id.apps.googleusercontent.com', 'client-secret-fixture', 'access-write-fixture', 'refresh-write-fixture' ) as $secret_fixture ) {
	mad4b_oauth_assert( false === strpos( $readiness_json, $secret_fixture ), 'Runtime readiness leaked credential/token fixture material.', $runtime_readiness );
}
mad4b_oauth_assert( ! empty( $public_write['write_available'] ) && 'read_write' === $public_write['access_mode'], 'Public Drive status must retain capability truth.', $public_write );
foreach ( array( 'scope', 'account_email', 'account_name', 'permission_id', 'access_token', 'refresh_token' ) as $sensitive_key ) {
	mad4b_oauth_assert( ! array_key_exists( $sensitive_key, $public_write ), 'Public Drive status leaked account/token identity field: ' . $sensitive_key, $public_write );
}

$GLOBALS['mad4b_context_token_responses'][] = array(
	'access_token' => 'access-downgrade-fixture',
	'expires_in' => 3600,
	'scope' => MAD4B_SCP_Google_Drive_Context::READ_SCOPE,
);
$url = MAD4B_SCP_Google_Drive_Context::authorization_url( 'read_only' );
mad4b_oauth_assert( ! is_wp_error( $url ), 'Read-only reconnect attempt may start before downgrade proof is evaluated.', $url );
$downgrade = MAD4B_SCP_Google_Drive_Context::complete_oauth( 'code-downgrade', 'context-oauth-state' );
mad4b_oauth_assert( is_wp_error( $downgrade ), 'Silent downgrade must fail when the prior broad refresh grant would be reused.', $downgrade );
mad4b_oauth_assert( 'mad4b_google_drive_readonly_downgrade_requires_revoke' === $downgrade->get_error_code(), 'Silent broad-refresh downgrade must have a stable blocker.', $downgrade->get_error_code() );

$still_write = MAD4B_SCP_Google_Drive_Context::connection_status();
mad4b_oauth_assert( ! empty( $still_write['write_available'] ) && 'read_write' === $still_write['access_mode'], 'Failed downgrade must leave the prior governed connection unchanged until revoke.', $still_write );

$disconnected = MAD4B_SCP_Google_Drive_Context::disconnect();
mad4b_oauth_assert( ! is_wp_error( $disconnected ), 'Confirmed Google revoke must disconnect cleanly.', $disconnected );
mad4b_oauth_assert( empty( $disconnected['connected'] ), 'Confirmed revoke must remove the local encrypted token.', $disconnected );
mad4b_oauth_assert( ! empty( $disconnected['remote_revocation_attempted'] ) && ! empty( $disconnected['remote_revocation_confirmed'] ), 'Disconnect must expose confirmed remote revocation evidence.', $disconnected );
mad4b_oauth_assert( false === get_option( MAD4B_SCP_Google_Drive_Context::TOKEN_OPTION, false ), 'Confirmed revoke must leave no local token option.' );

$encoded = json_encode( $disconnected );
foreach ( array( 'access-read-fixture', 'refresh-read-fixture', 'access-write-fixture', 'refresh-write-fixture' ) as $secret ) {
	mad4b_oauth_assert( false === strpos( $encoded, $secret ), 'OAuth status must never expose token material.' );
}


$mode_status = MAD4B_SCP_Google_Drive_Context::auth_mode_status();
mad4b_oauth_assert( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_CUSTOM === $mode_status['mode'], 'Legacy/default authentication mode must remain Custom OAuth for backward compatibility.', $mode_status );
mad4b_oauth_assert( ! empty( $mode_status['managed_google']['configured'] ), 'Managed Google Sign-In broker must be reported configured when the server constant is present.', $mode_status );

$mode_change = MAD4B_SCP_Google_Drive_Context::set_auth_mode( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_MANAGED );
mad4b_oauth_assert( ! is_wp_error( $mode_change ), 'Authentication mode must switch to Managed Google after the previous Google grant is fully revoked.', $mode_change );
mad4b_oauth_assert( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_MANAGED === $mode_change['mode'], 'Managed Google mode must become active.', $mode_change );

$managed_credentials = MAD4B_SCP_Google_Drive_Context::credentials_status();
mad4b_oauth_assert( ! empty( $managed_credentials['configured'] ), 'Managed mode must use broker readiness instead of site Google credentials.', $managed_credentials );
mad4b_oauth_assert( ! empty( $managed_credentials['managed_broker']['site_request_auth']['configured'] ), 'Managed mode must require configured site-to-broker request signing.', $managed_credentials );
mad4b_oauth_assert( 'context-staging-v1' === $managed_credentials['managed_broker']['site_request_auth']['key_id'], 'Managed broker status must expose only the non-secret site key ID.', $managed_credentials );
mad4b_oauth_assert( empty( $managed_credentials['managed_broker']['site_request_auth']['secret_exposed'] ), 'Managed broker status must never expose the site request-signing secret.', $managed_credentials );
mad4b_oauth_assert( empty( $managed_credentials['client_id'] ) && empty( $managed_credentials['client_id_suffix'] ), 'Managed mode must not expose or require a Google Client ID on the site.', $managed_credentials );
mad4b_oauth_assert( empty( $managed_credentials['google_client_secret_on_site'] ) && 'mad4b_managed_oauth' === $managed_credentials['credential_custody'], 'Managed mode must declare off-site Google client-secret custody.', $managed_credentials );

$managed_url = MAD4B_SCP_Google_Drive_Context::managed_authorization_url( 'read_only' );
mad4b_oauth_assert( ! is_wp_error( $managed_url ), 'Managed Google authorization session must be created.', $managed_url );
mad4b_oauth_assert( 0 === strpos( $managed_url, 'https://accounts.google.com/' ), 'Managed broker must return an HTTPS Google authorization URL.', $managed_url );
$managed_session_request = $GLOBALS['mad4b_managed_requests']['session'];
mad4b_oauth_assert( MAD4B_SCP_Google_Drive_Context::MANAGED_SESSION_CONTRACT === $managed_session_request['contract'], 'Managed session contract mismatch.', $managed_session_request );
mad4b_oauth_assert( '11111111-1111-4111-8111-111111111111' === $managed_session_request['site_uuid'], 'Managed session must bind exact Site Profile UUID.', $managed_session_request );
mad4b_oauth_assert( 'https://staging.example.test' === $managed_session_request['origin'], 'Managed session must bind canonical origin.', $managed_session_request );
mad4b_oauth_assert( 'S256' === $managed_session_request['verifier_method'] && ! empty( $managed_session_request['verifier_challenge'] ), 'Managed session must use verifier-bound S256 handoff.', $managed_session_request );
$managed_session_json = json_encode( $managed_session_request );
foreach ( array( 'client-secret-fixture', 'MAD4B_GOOGLE_DRIVE_CLIENT_SECRET', 'refresh-read-fixture', 'refresh-write-fixture' ) as $forbidden ) {
	mad4b_oauth_assert( false === strpos( $managed_session_json, $forbidden ), 'Managed session request leaked site OAuth secret/token material.', $managed_session_request );
}

$GLOBALS['mad4b_managed_redeem_responses'][] = array(
	'contract' => MAD4B_SCP_Google_Drive_Context::MANAGED_REDEEM_CONTRACT,
	'access_token' => 'managed-access-short',
	'refresh_token' => 'managed-refresh-token',
	'expires_in' => 60,
	'scope' => MAD4B_SCP_Google_Drive_Context::READ_SCOPE,
);
$GLOBALS['mad4b_managed_refresh_responses'][] = array(
	'contract' => MAD4B_SCP_Google_Drive_Context::MANAGED_REFRESH_CONTRACT,
	'access_token' => 'managed-access-refreshed',
	'expires_in' => 3600,
	'scope' => MAD4B_SCP_Google_Drive_Context::READ_SCOPE,
);
$managed = MAD4B_SCP_Google_Drive_Context::complete_managed_oauth( 'managed-handoff-code', 'context-oauth-state' );
mad4b_oauth_assert( ! is_wp_error( $managed ), 'Managed one-time handoff redemption must succeed.', $managed );
mad4b_oauth_assert( ! empty( $managed['connected'] ) && ! empty( $managed['read_available'] ) && empty( $managed['write_available'] ), 'Managed read-only connection capability truth mismatch.', $managed );
mad4b_oauth_assert( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_MANAGED === $managed['auth_mode'], 'Managed connection must retain authentication mode in token state.', $managed );
mad4b_oauth_assert( 'mad4b_managed_oauth' === $managed['credential_custody'], 'Managed connection must expose managed credential custody.', $managed );

$redeem_request = $GLOBALS['mad4b_managed_requests']['redeem'];
mad4b_oauth_assert( 'managed-session-fixture' === $redeem_request['session_id'], 'Managed redemption must bind broker session ID.', $redeem_request );
mad4b_oauth_assert( ! empty( $redeem_request['verifier'] ), 'Managed redemption must include the one-time verifier server-to-server.', $redeem_request );
mad4b_oauth_assert( 'https://staging.example.test' === $redeem_request['origin'], 'Managed redemption must retain canonical origin binding.', $redeem_request );

$refresh_request = $GLOBALS['mad4b_managed_requests']['refresh'];
mad4b_oauth_assert( 'managed-refresh-token' === $refresh_request['refresh_token'], 'Managed access-token refresh must route through the broker using the Google refresh token.', $refresh_request );
mad4b_oauth_assert( MAD4B_SCP_Google_Drive_Context::READ_SCOPE === $refresh_request['requested_scope'], 'Managed refresh must preserve exact governed scope.', $refresh_request );

$blocked_mode_change = MAD4B_SCP_Google_Drive_Context::set_auth_mode( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_CUSTOM );
mad4b_oauth_assert( is_wp_error( $blocked_mode_change ), 'Authentication mode change must fail while a managed Google token is connected.', $blocked_mode_change );
mad4b_oauth_assert( 'mad4b_google_drive_auth_mode_change_requires_disconnect' === $blocked_mode_change->get_error_code(), 'Connected mode-change blocker must be stable.', $blocked_mode_change->get_error_code() );

$managed_public = MAD4B_SCP_Google_Drive_Context::public_connection_status();
$managed_public_json = json_encode( $managed_public );
foreach ( array( 'managed-refresh-token', 'managed-access-short', 'managed-access-refreshed', 'client-secret-fixture', 'managed-google-site-signing-secret-fixture-0123456789' ) as $secret ) {
	mad4b_oauth_assert( false === strpos( $managed_public_json, $secret ), 'Managed public status leaked OAuth/token material.', $managed_public );
}

$managed_disconnected = MAD4B_SCP_Google_Drive_Context::disconnect();
mad4b_oauth_assert( ! is_wp_error( $managed_disconnected ) && empty( $managed_disconnected['connected'] ), 'Managed Google grant must revoke and disconnect cleanly.', $managed_disconnected );

$dedicated_mode = MAD4B_SCP_Google_Drive_Context::set_auth_mode( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_DEDICATED );
mad4b_oauth_assert( ! is_wp_error( $dedicated_mode ), 'Authentication mode must switch to Dedicated Site OAuth after managed grant revocation.', $dedicated_mode );
mad4b_oauth_assert( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_DEDICATED === $dedicated_mode['mode'], 'Dedicated Site OAuth mode must become active.', $dedicated_mode );

$dedicated_saved = MAD4B_SCP_Google_Drive_Context::save_dedicated_credentials(
	'dedicated-client.apps.googleusercontent.com',
	'dedicated-client-secret-fixture'
);
mad4b_oauth_assert( ! is_wp_error( $dedicated_saved ), 'Dedicated Google OAuth credentials must persist encrypted in site settings.', $dedicated_saved );
mad4b_oauth_assert( 'https://staging.example.test/wp-admin/admin-post.php?action=mad4b_context_google_dedicated_callback' === $dedicated_saved['redirect_uri'], 'Dedicated OAuth redirect must be derived from the Site Profile primary domain.', $dedicated_saved );

$dedicated_credentials = MAD4B_SCP_Google_Drive_Context::credentials_status();
mad4b_oauth_assert( ! empty( $dedicated_credentials['configured'] ), 'Dedicated Google OAuth must become configured after saving site credentials.', $dedicated_credentials );
mad4b_oauth_assert( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_DEDICATED === $dedicated_credentials['auth_mode'], 'Dedicated credential status must preserve selected mode.', $dedicated_credentials );
mad4b_oauth_assert( 'dedicated_site_managed' === $dedicated_credentials['credential_custody'], 'Dedicated OAuth must declare site-managed credential custody.', $dedicated_credentials );
mad4b_oauth_assert( ! empty( $dedicated_credentials['google_client_secret_on_site'] ), 'Dedicated OAuth must explicitly report local Client Secret custody.', $dedicated_credentials );
mad4b_oauth_assert( empty( $dedicated_credentials['central_mad4b_dependency'] ), 'Dedicated OAuth must have no central MAD4B OAuth dependency.', $dedicated_credentials );
mad4b_oauth_assert( 'https://staging.example.test/wp-admin/admin-post.php?action=mad4b_context_google_dedicated_callback' === $dedicated_credentials['dedicated_redirect_uri'], 'Dedicated callback must stay on the enrolled primary site domain.', $dedicated_credentials );

$managed_requests_before_dedicated = json_encode( $GLOBALS['mad4b_managed_requests'] );
$GLOBALS['mad4b_context_token_responses'][] = array(
	'access_token' => 'dedicated-access-short',
	'refresh_token' => 'dedicated-refresh-token',
	'expires_in' => 3600,
	'scope' => MAD4B_SCP_Google_Drive_Context::READ_SCOPE,
);
$dedicated_url = MAD4B_SCP_Google_Drive_Context::authorization_url( 'read_only' );
mad4b_oauth_assert( ! is_wp_error( $dedicated_url ), 'Dedicated OAuth authorization URL must be created locally.', $dedicated_url );
parse_str( (string) parse_url( $dedicated_url, PHP_URL_QUERY ), $dedicated_query );
mad4b_oauth_assert( 'dedicated-client.apps.googleusercontent.com' === $dedicated_query['client_id'], 'Dedicated authorization must use the site dedicated Google Client ID.', $dedicated_query );
mad4b_oauth_assert( $dedicated_credentials['dedicated_redirect_uri'] === $dedicated_query['redirect_uri'], 'Dedicated authorization must use the dynamic site-domain redirect URI.', $dedicated_query );
mad4b_oauth_assert( 'S256' === $dedicated_query['code_challenge_method'], 'Dedicated OAuth must preserve PKCE S256.', $dedicated_query );

$dedicated = MAD4B_SCP_Google_Drive_Context::complete_oauth( 'code-dedicated', 'context-oauth-state' );
mad4b_oauth_assert( ! is_wp_error( $dedicated ), 'Dedicated Site OAuth token exchange must succeed locally.', $dedicated );
mad4b_oauth_assert( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_DEDICATED === $dedicated['auth_mode'], 'Dedicated token state must preserve dedicated auth mode.', $dedicated );
mad4b_oauth_assert( 'dedicated_site_managed' === $dedicated['credential_custody'], 'Dedicated connection must preserve local dedicated credential custody.', $dedicated );
mad4b_oauth_assert( empty( $dedicated['write_available'] ) && ! empty( $dedicated['read_available'] ), 'Dedicated read-only OAuth must expose only read capability.', $dedicated );
mad4b_oauth_assert( 'dedicated-client.apps.googleusercontent.com' === $GLOBALS['mad4b_context_last_token_request']['body']['client_id'], 'Dedicated token exchange must use dedicated site Client ID.' );
mad4b_oauth_assert( 'dedicated-client-secret-fixture' === $GLOBALS['mad4b_context_last_token_request']['body']['client_secret'], 'Dedicated token exchange must use dedicated encrypted site Client Secret.' );
mad4b_oauth_assert( $dedicated_credentials['dedicated_redirect_uri'] === $GLOBALS['mad4b_context_last_token_request']['body']['redirect_uri'], 'Dedicated token exchange must bind exact dynamic site-domain callback.' );
mad4b_oauth_assert( $managed_requests_before_dedicated === json_encode( $GLOBALS['mad4b_managed_requests'] ), 'Dedicated OAuth must not call the MAD4B managed OAuth broker.' );

$dedicated_public_json = json_encode( MAD4B_SCP_Google_Drive_Context::public_connection_status() );
foreach ( array( 'dedicated-client-secret-fixture', 'dedicated-access-short', 'dedicated-refresh-token' ) as $secret ) {
	mad4b_oauth_assert( false === strpos( $dedicated_public_json, $secret ), 'Dedicated public status leaked OAuth/token material.' );
}

$dedicated_disconnected = MAD4B_SCP_Google_Drive_Context::disconnect();
mad4b_oauth_assert( ! is_wp_error( $dedicated_disconnected ) && empty( $dedicated_disconnected['connected'] ), 'Dedicated Google grant must revoke and disconnect cleanly.', $dedicated_disconnected );

mad4b_oauth_assert( count( $GLOBALS['mad4b_managed_site_nonces'] ) >= 3, 'Managed session/redeem/refresh must each use a fresh request nonce.', $GLOBALS['mad4b_managed_site_nonces'] );
echo "mad4b.site-control-plane.context-oauth-lifecycle.runtime.v7: PASS\n";
