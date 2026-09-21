<?php

define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-oauth-consent-ui.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
};

$sample = '<!doctype html><html><head><meta charset="utf-8"><title>Authorize MCP access</title></head><body>'
	. '<main style="max-width:720px;margin:40px auto;font-family:system-ui,sans-serif;padding:0 20px">'
	. '<h1>Authorize MCP access</h1><p><strong>ChatGPT</strong> is requesting read access.</p>'
	. '<form method="post" action="https://example.test/oauth/mcp/authorize">'
	. '<input type="hidden" name="client_id" value="https://chatgpt.com/oauth/client.json">'
	. '<input type="hidden" name="_mad4b_oauth_nonce" value="nonce-value">'
	. '<button type="submit" name="decision" value="approve">Approve</button>'
	. '<button type="submit" name="decision" value="deny">Deny</button>'
	. '</form></main></body></html>';

$enhanced = MAD4B_SCP_Local_OAuth_Consent_UI::enhance_document( $sample );
if ( false === strpos( $enhanced, 'id="mad4b-oauth-consent-ui"' ) ) $fail( 'Consent stylesheet marker was not injected.' );
if ( false === strpos( $enhanced, 'Read-only access · OAuth 2.1 · PKCE S256' ) ) $fail( 'Security context footer is missing.' );
if ( false === strpos( $enhanced, 'name="_mad4b_oauth_nonce" value="nonce-value"' ) ) $fail( 'Consent nonce field was changed.' );
if ( false === strpos( $enhanced, 'name="client_id" value="https://chatgpt.com/oauth/client.json"' ) ) $fail( 'Client identity field was changed.' );
if ( false === strpos( $enhanced, 'method="post" action="https://example.test/oauth/mcp/authorize"' ) ) $fail( 'Consent form transport was changed.' );
if ( false !== stripos( $enhanced, '<script' ) ) $fail( 'Consent UI must not inject JavaScript.' );
if ( false !== stripos( $enhanced, '<link' ) ) $fail( 'Consent UI must not load external stylesheets.' );
if ( $enhanced !== MAD4B_SCP_Local_OAuth_Consent_UI::enhance_document( $enhanced ) ) $fail( 'Consent enhancement is not idempotent.' );

$unrelated = '<!doctype html><html><head><title>Other page</title></head><body>Other</body></html>';
if ( $unrelated !== MAD4B_SCP_Local_OAuth_Consent_UI::enhance_document( $unrelated ) ) $fail( 'Unrelated HTML was modified.' );

echo "mad4b.site-control-plane.local-oauth-consent-ui.v1: PASS\n";
