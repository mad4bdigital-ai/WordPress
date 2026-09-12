<?php

define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-oauth-consent-ui.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
};

$sample = '<!doctype html><html><head><meta charset="utf-8"><title>Authorize MCP access</title></head><body>'
	. '<main style="max-width:720px;margin:40px auto;font-family:system-ui,sans-serif;padding:0 20px">'
	. '<h1>Authorize MCP access</h1><p><strong>ChatGPT</strong> is requesting read access to this WordPress MCP resource.</p>'
	. '<form method="post" action="https://example.test/oauth/mcp/authorize">'
	. '<input type="hidden" name="client_id" value="https://chatgpt.com/oauth/client.json">'
	. '<input type="hidden" name="_mad4b_oauth_nonce" value="nonce-value">'
	. '<button type="submit" name="decision" value="approve">Approve</button>'
	. '<button type="submit" name="decision" value="deny">Deny</button>'
	. '</form></main></body></html>';

$enhanced = MAD4B_SCP_Local_OAuth_Consent_UI::enhance_document( $sample );
if ( false === strpos( $enhanced, 'id="mad4b-oauth-consent-ui"' ) ) $fail( 'Consent stylesheet marker was not injected.' );
if ( false === strpos( $enhanced, 'This consent authenticates the client and grants the narrow read resource scope shown below; it does not grant write authority.' ) ) $fail( 'OAuth/read-scope separation statement is missing.' );
if ( false === strpos( $enhanced, 'Governed write actions, when available, require separate Staging write authority and a one-time approval.' ) ) $fail( 'Governed write separation statement is missing.' );
if ( false === strpos( $enhanced, 'OAuth identity/read scope · write authority separate · PKCE S256' ) ) $fail( 'Security context footer is missing.' );
if ( false !== strpos( $enhanced, 'Read-only access · OAuth 2.1 · PKCE S256' ) ) $fail( 'Legacy whole-plugin read-only claim remains visible.' );
if ( false !== strpos( $enhanced, 'mad4b:write' ) ) $fail( 'Consent presentation must not advertise or create a write OAuth scope.' );
if ( false === strpos( $enhanced, 'name="_mad4b_oauth_nonce" value="nonce-value"' ) ) $fail( 'Consent nonce field was changed.' );
if ( false === strpos( $enhanced, 'name="client_id" value="https://chatgpt.com/oauth/client.json"' ) ) $fail( 'Client identity field was changed.' );
if ( false === strpos( $enhanced, 'method="post" action="https://example.test/oauth/mcp/authorize"' ) ) $fail( 'Consent form transport was changed.' );
if ( false === strpos( $enhanced, 'name="decision" value="approve"' ) || false === strpos( $enhanced, 'name="decision" value="deny"' ) ) $fail( 'Consent approve/deny ownership was changed.' );
if ( false !== stripos( $enhanced, '<script' ) ) $fail( 'Consent UI must not inject JavaScript.' );
if ( false !== stripos( $enhanced, '<link' ) ) $fail( 'Consent UI must not load external stylesheets.' );
if ( $enhanced !== MAD4B_SCP_Local_OAuth_Consent_UI::enhance_document( $enhanced ) ) $fail( 'Consent enhancement is not idempotent.' );

$connection_sample = '<!doctype html><html><head><title>MAD4B Connection</title></head><body>'
	. '<p>Read-only connection workspace. It organizes environment, MCP transport, OAuth authority, isolation and external certification without creating credentials, connecting a client, enabling mutation, granting authority or making outbound discovery requests.</p>'
	. '<p>The connection reports externally verified certification evidence. Continue to governance and adapter coverage before enabling any mutation path.</p>'
	. '</body></html>';
$connection_enhanced = MAD4B_SCP_Local_OAuth_Consent_UI::enhance_connection_document( $connection_sample );
if ( false === strpos( $connection_enhanced, 'Connection and certification workspace. This page is inspection-only:' ) ) $fail( 'Connection workspace semantics were not hardened.' );
if ( false === strpos( $connection_enhanced, 'Governed write capability, when available, is established separately by Write Authority, Write Runtime Certification and one-time approvals.' ) ) $fail( 'Connection workspace write-authority separation is missing.' );
if ( false === strpos( $connection_enhanced, 'This certification does not grant write authority; governed mutation availability is evaluated separately by Write Authority and Write Runtime Certification.' ) ) $fail( 'External certification/write-authority separation is missing.' );
if ( false !== strpos( $connection_enhanced, 'Read-only connection workspace.' ) ) $fail( 'Legacy whole-workspace read-only wording remains visible.' );

$unrelated = '<!doctype html><html><head><title>Other page</title></head><body>Other</body></html>';
if ( $unrelated !== MAD4B_SCP_Local_OAuth_Consent_UI::enhance_document( $unrelated ) ) $fail( 'Unrelated HTML was modified.' );
if ( $unrelated !== MAD4B_SCP_Local_OAuth_Consent_UI::enhance_connection_document( $unrelated ) ) $fail( 'Unrelated connection HTML was modified.' );

echo "mad4b.site-control-plane.local-oauth-consent-ui.v2: PASS\n";
