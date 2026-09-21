<?php

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "WordPress not loaded\n" ); exit( 1 ); }

$fail = static function ( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) : '' ) . PHP_EOL );
	exit( 1 );
};

if ( ! class_exists( 'MAD4B_SCP_ChatGPT_OAuth_Lifecycle' ) || ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ) {
	$fail( 'ChatGPT OAuth lifecycle classes unavailable.' );
}
if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) || true !== MAD4B_MCP_LOCAL_OAUTH_ENABLED ) {
	$fail( 'Zero-touch Local OAuth is not enabled in the Staging fixture.' );
}

$metadata = MAD4B_SCP_Local_OAuth_Server::metadata();
if ( ! in_array( 'refresh_token', $metadata['grant_types_supported'], true ) || ! in_array( 'offline_access', $metadata['scopes_supported'], true ) ) {
	$fail( 'Local authorization-server metadata does not support persistent lifecycle.', $metadata );
}

$resource = MAD4B_SCP_Local_OAuth_Server::resource_identifier();
$base = array(
	'client_id' => MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID,
	'response_type' => 'code',
	'resource' => $resource,
	'code_challenge' => str_repeat( 'A', 43 ),
	'code_challenge_method' => 'S256',
	'scope' => 'mad4b:read',
	'state' => 'ci-state',
);

$old_server = $_SERVER;
$old_get = $_GET;
$old_post = $_POST;

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/oauth/mcp/authorize?client_id=chatgpt';
$_GET = $base;
$_POST = array();

if ( ! MAD4B_SCP_ChatGPT_OAuth_Lifecycle::augment_authorization_scope() ) {
	$fail( 'Exact ChatGPT GET authorization request was not augmented.' );
}
if ( 'mad4b:read offline_access' !== $_GET['scope'] ) {
	$fail( 'ChatGPT lifecycle scope was not added exactly once.', $_GET );
}
if ( MAD4B_SCP_ChatGPT_OAuth_Lifecycle::augment_authorization_scope() ) {
	$fail( 'Lifecycle augmentation was not idempotent.', $_GET );
}
if ( 1 !== substr_count( $_GET['scope'], 'offline_access' ) ) {
	$fail( 'Lifecycle scope duplicated.', $_GET['scope'] );
}

$_GET = $base;
$_GET['client_id'] = 'https://example.test/client.json';
if ( MAD4B_SCP_ChatGPT_OAuth_Lifecycle::augment_authorization_scope() || 'mad4b:read' !== $_GET['scope'] ) {
	$fail( 'Foreign OAuth client received ChatGPT lifecycle augmentation.', $_GET );
}

$_GET = $base;
$_GET['resource'] = 'https://mad4b-local-oauth.test/wp-json/mcp/mad4b-read';
if ( MAD4B_SCP_ChatGPT_OAuth_Lifecycle::augment_authorization_scope() || 'mad4b:read' !== $_GET['scope'] ) {
	$fail( 'Non-ChatGPT protected resource received lifecycle augmentation.', $_GET );
}

$_GET = $base;
$_GET['code_challenge_method'] = 'plain';
if ( MAD4B_SCP_ChatGPT_OAuth_Lifecycle::augment_authorization_scope() || 'mad4b:read' !== $_GET['scope'] ) {
	$fail( 'Non-S256 authorization request received lifecycle augmentation.', $_GET );
}

$_GET = $base;
unset( $_GET['scope'] );
if ( ! MAD4B_SCP_ChatGPT_OAuth_Lifecycle::augment_authorization_scope() || 'mad4b:read offline_access' !== $_GET['scope'] ) {
	$fail( 'ChatGPT default read scope did not receive lifecycle continuity.', $_GET );
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = $base;
$_GET = array();
if ( ! MAD4B_SCP_ChatGPT_OAuth_Lifecycle::augment_authorization_scope() || 'mad4b:read offline_access' !== $_POST['scope'] ) {
	$fail( 'Consent POST did not preserve ChatGPT lifecycle scope.', $_POST );
}

$_SERVER = $old_server;
$_GET = $old_get;
$_POST = $old_post;

fwrite(
	STDOUT,
	'mad4b.site-control-plane.runtime-chatgpt-oauth-lifecycle.v1: PASS ' .
	wp_json_encode(
		array(
			'client_id' => MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID,
			'resource' => $resource,
			'authorization_scope' => 'mad4b:read offline_access',
			'access_authority_scope' => 'mad4b:read',
			'refresh_rotation_supported' => true,
		),
		JSON_UNESCAPED_SLASHES
	) . PHP_EOL
);
