<?php
/** Live-source preservation: exact ChatGPT CIMD client identities and redirects. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    public $code, $message;
    public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-oauth-server.php';
function expect_true( $value, $label ) { if ( ! $value ) { fwrite( STDERR, 'FAIL: '.$label."\n" ); exit( 1 ); } }
$server = 'MAD4B_SCP_Local_OAuth_Server';
$legacy = $server::CHATGPT_CIMD_CLIENT_ID;
$scoped = 'https://chatgpt.com/oauth/connector_example_123456/client.json';
expect_true( $server::trusted_chatgpt_client_id( $legacy ), 'Legacy ChatGPT metadata URL remains valid' );
expect_true( $server::trusted_chatgpt_client_id( $scoped ), 'Exact scoped ChatGPT metadata URL is supported' );
expect_true( $server::trusted_chatgpt_client_ids_match( $scoped, $scoped ), 'Exact same scoped identity may agree' );
expect_true( ! $server::trusted_chatgpt_client_ids_match( $scoped, $legacy ), 'Distinct ChatGPT identities must not be conflated' );
expect_true( ! $server::trusted_chatgpt_client_ids_match( $scoped, 'https://evil.example/oauth/connector_example_123456/client.json' ), 'Untrusted right observer is forbidden' );
$invalid = array(
    'http://chatgpt.com/oauth/connector_example_123456/client.json',
    'https://chatgpt.com.evil.example/oauth/connector_example_123456/client.json',
    'https://evil.chatgpt.com/oauth/connector_example_123456/client.json',
    'https://chatgpt.com:443/oauth/connector_example_123456/client.json',
    'https://chatgpt.com@evil.example/oauth/connector_example_123456/client.json',
    'https://user@chatgpt.com/oauth/connector_example_123456/client.json',
    'https://chatgpt.com/oauth/connector_example_123456/client.json?q=1',
    'https://chatgpt.com/oauth/connector_example_123456/client.json#frag',
    'https://chatgpt.com/oauth/connector_example_123456/client.json/',
    'https://chatgpt.com/oauth/short/client.json',
    'https://chatgpt.com/oauth/connector_example_123456/client%2Ejson',
    'https://chatgpt.com/oauth/connector_example_123456/other.json',
    'https://CHATGPT.COM/oauth/connector_example_123456/client.json',
    'https://chatgpt.com/oauth/connector_example_123456/client.json?redirect_uri=https://evil.example',
);
foreach ( $invalid as $id ) {
    expect_true( ! $server::trusted_chatgpt_client_id( $id ), 'Reject untrusted metadata URL: ' . $id );
}
$redirect = new ReflectionMethod( $server, 'chatgpt_expected_redirect_uri' );
expect_true( 'https://chatgpt.com/connector_platform_oauth_redirect' === $redirect->invoke( null, $legacy ), 'Legacy redirect is fixed' );
$expected = 'https://chatgpt.com/connector/oauth/connector_example_123456';
expect_true( $expected === $redirect->invoke( null, $scoped ), 'Scoped redirect exactly matches its metadata path' );
expect_true( '' === $redirect->invoke( null, 'https://evil.example/oauth/connector_example_123456/client.json' ), 'Invalid client cannot derive redirect' );
$validate = new ReflectionMethod( $server, 'validate_cimd_metadata' );
$valid = array( 'client_id'=>$scoped, 'client_name'=>'ChatGPT Fixture', 'redirect_uris'=>array($expected), 'token_endpoint_auth_methods_supported'=>array('none'), 'grant_types'=>array('authorization_code'), 'response_types'=>array('code') );
$ok = $validate->invoke(null,$scoped,$valid);
expect_true( is_array($ok) && 'cimd' === $ok['registration_mode'] && $scoped === $ok['client_id'], 'Exact scoped metadata approved for public PKCE' );
$extra=$valid;$extra['redirect_uris'][]='https://evil.example/callback';
expect_true( is_wp_error($validate->invoke(null,$scoped,$extra)), 'Additional redirect rejected' );
$wrong=$valid;$wrong['redirect_uris']=array('https://chatgpt.com/connector/oauth/unrelated');
expect_true( is_wp_error($validate->invoke(null,$scoped,$wrong)), 'Cross-connector redirect rejected' );
$wrong=$valid;$wrong['client_id']=$legacy;
expect_true( is_wp_error($validate->invoke(null,$scoped,$wrong)), 'Metadata client_id substitution rejected' );
$wrong=$valid;$wrong['token_endpoint_auth_methods_supported']=array('client_secret_basic');
expect_true( is_wp_error($validate->invoke(null,$scoped,$wrong)), 'Confidential-client method rejected for public PKCE' );
$legacy_valid=$valid;$legacy_valid['client_id']=$legacy;$legacy_valid['redirect_uris']=array('https://chatgpt.com/connector_platform_oauth_redirect');
expect_true( is_array($validate->invoke(null,$legacy,$legacy_valid)), 'Legacy metadata preserved' );
echo "PASS: exact ChatGPT per-connector CIMD identity and redirect validation (26+ guards)\n";
