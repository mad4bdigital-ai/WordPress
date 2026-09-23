<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Presentation-only semantic hardening for local OAuth consent and the
 * Connection workspace.
 *
 * The OAuth server remains the sole owner of request validation, nonce fields,
 * consent decisions, authorization-code issuance and redirects. This class only
 * transforms bounded presentation strings and injects bounded inline CSS into
 * already-rendered documents. It never grants authority, changes scopes,
 * enables mutation, creates credentials or performs outbound discovery.
 */
final class MAD4B_SCP_Local_OAuth_Consent_UI {
	const CONTRACT = 'mad4b.local-oauth-consent-ui.v3';
	const STYLE_ID = 'mad4b-oauth-consent-ui';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'parse_request', array( __CLASS__, 'start_buffer_for_consent' ), -20 );
		add_action( 'admin_init', array( __CLASS__, 'start_buffer_for_connection_admin' ), -20 );
	}

	public static function start_buffer_for_consent() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method || headers_sent() ) return;

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) return;
		$path = rtrim( '/' . ltrim( $path, '/' ), '/' );
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) || MAD4B_SCP_Local_OAuth_Server::AUTHORIZE_PATH !== $path ) return;

		ob_start( array( __CLASS__, 'enhance_document' ) );
	}

	public static function start_buffer_for_connection_admin() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) || headers_sent() ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing only.
		$expected = class_exists( 'MAD4B_SCP_Connection_Admin_UI' ) ? MAD4B_SCP_Connection_Admin_UI::PAGE_SLUG : 'mad4b-control-plane-connection';
		if ( $expected !== $page ) return;

		ob_start( array( __CLASS__, 'enhance_connection_document' ) );
	}

	public static function enhance_document( $html ) {
		if ( ! is_string( $html ) || '' === $html ) return $html;
		if ( false !== strpos( $html, 'id="' . self::STYLE_ID . '"' ) ) return $html;
		if ( false === strpos( $html, '<title>Authorize MCP access</title>' ) ) return $html;
		if ( false === strpos( $html, '_mad4b_oauth_nonce' ) ) return $html;
		if ( false === strpos( $html, '</head>' ) ) return $html;

		$legacy_request = 'is requesting read access to this WordPress MCP resource.';
		$governed_request = 'is requesting OAuth read access to this WordPress MCP resource. This consent authenticates the client and grants only the read resource scope shown below. Write, Developer and Developer Breakglass are separate governed authorities and are not created by this OAuth approval.';
		$html = str_replace( $legacy_request, $governed_request, $html );
		$html = str_replace( '<title>Authorize MCP access</title>', '<title>Authorize read access</title>', $html );
		$html = str_replace( '<h1>Authorize MCP access</h1>', '<h1>Authorize read access</h1>', $html );

		$context = '<section class="mad4b-consent-context" aria-label="OAuth consent boundary">'
			. '<h2>What you are approving now</h2>'
			. '<div class="mad4b-consent-grid">'
			. '<div><span>Resource</span><strong><code>mad4b-chatgpt</code></strong></div>'
			. '<div><span>Access</span><strong>Read identity</strong></div>'
			. '<div><span>Write authority</span><strong>Separate governance</strong></div>'
			. '<div><span>Developer authority</span><strong>Separate governance</strong></div>'
			. '<div><span>Developer Breakglass</span><strong>Separate governance</strong></div>'
			. '<div><span>Generic raw-SQL Breakglass</span><strong>Not included</strong></div>'
			. '</div>'
			. '<p><code>offline_access</code> keeps the approved connection active without repeatedly asking you to sign in; it does not add mutation authority.</p>'
			. '</section>';
		$authority_summary = '<section class="mad4b-authority-summary" aria-label="Current Staging authority">'
			. '<h2>Current Staging authority</h2>'
			. '<div class="mad4b-authority-grid">'
			. '<div><span>Write</span><strong id="mad4b-write-state" class="mad4b-authority-pending">Checking…</strong></div>'
			. '<div><span>Developer</span><strong id="mad4b-developer-state" class="mad4b-authority-pending">Checking…</strong></div>'
			. '<div><span>Developer Breakglass</span><strong id="mad4b-developer-breakglass-state" class="mad4b-authority-pending">Checking…</strong></div>'
			. '</div>'
			. '<p id="mad4b-full-authority-state" class="mad4b-full-blocked"><strong>Full Staging Authority:</strong> Checking…</p>'
			. '<p>Generic raw-SQL Breakglass is not included in Full Staging Authority.</p>'
			. '</section>';
		if ( false !== strpos( $html, '<section class="mad4b-live-grants"' ) ) {
			$html = str_replace( '<section class="mad4b-live-grants"', $context . $authority_summary . '<section class="mad4b-live-grants"', $html );
		}

		$html = str_replace( '>Approve</button>', '>Approve read access</button>', $html );
		$html = str_replace( '>Deny</button>', '>Deny access</button>', $html );

		$css = '<style id="' . self::STYLE_ID . '">'
			. ':root{color-scheme:light;--m4-text:#0f172a;--m4-muted:#64748b;--m4-line:#e2e8f0;--m4-bg:#f8fafc;--m4-card:#fff;--m4-primary:#111827;--m4-accent:#65a30d;}'
			. '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:radial-gradient(circle at 50% -10%,#ecfccb 0,transparent 34%),linear-gradient(180deg,#f8fafc 0%,#eef2f7 100%);color:var(--m4-text);}'
			. 'main{position:relative;display:flex;flex-direction:column;width:min(calc(100% - 32px),620px)!important;max-width:620px!important;margin:32px auto!important;padding:36px!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;background:var(--m4-card);border:1px solid var(--m4-line);border-radius:20px;box-shadow:0 24px 60px rgba(15,23,42,.10),0 2px 8px rgba(15,23,42,.05);}'
			. 'main:before{content:"MAD4B · Secure OAuth connection";display:inline-flex;align-items:center;margin:0 0 18px;padding:7px 11px;border:1px solid #d9f99d;border-radius:999px;background:#f7fee7;color:#3f6212;font-size:12px;font-weight:700;letter-spacing:.02em;}'
			. 'h1{margin:0 0 12px;font-size:30px;line-height:1.16;letter-spacing:-.025em;color:var(--m4-text);}h2{margin:0 0 10px;font-size:17px;color:var(--m4-text);}p{margin:11px 0;color:#475569;font-size:15px;line-height:1.65;}p strong{color:var(--m4-text);font-weight:700;}code{display:inline-block;max-width:100%;padding:3px 7px;border:1px solid var(--m4-line);border-radius:7px;background:#f8fafc;color:#334155;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;overflow-wrap:anywhere;vertical-align:middle;}.mad4b-consent-context{order:5;margin:18px 0 0;padding:16px;border:1px solid #dbeafe;border-radius:14px;background:#eff6ff}.mad4b-consent-context h2{margin:0 0 10px}.mad4b-consent-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.mad4b-consent-grid div{padding:9px 10px;border:1px solid #dbeafe;border-radius:9px;background:#fff}.mad4b-consent-grid span{display:block;color:var(--m4-muted);font-size:11px}.mad4b-consent-grid strong{display:block;margin-top:3px;color:var(--m4-text);font-size:13px}.mad4b-authority-summary{order:15;margin:14px 0 0;padding:16px;border:1px solid var(--m4-line);border-radius:14px;background:#fff}.mad4b-authority-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.mad4b-authority-grid div{padding:10px;border:1px solid var(--m4-line);border-radius:10px;background:#f8fafc}.mad4b-authority-grid span{display:block;color:var(--m4-muted);font-size:11px}.mad4b-authority-grid strong{display:block;margin-top:4px;font-size:13px}.mad4b-authority-ready{color:#166534}.mad4b-authority-blocked{color:#9a3412}.mad4b-authority-pending{color:#64748b}.mad4b-full-ready{color:#166534}.mad4b-full-blocked{color:#9a3412}.mad4b-live-grants{order:20;margin:22px 0 0;padding:18px;border:1px solid var(--m4-line);border-radius:14px;background:#fbfdff}.mad4b-grant-head{display:flex;gap:12px;align-items:center;justify-content:space-between}.mad4b-grant-head h2{margin:0}.mad4b-state{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:800;background:#fff7ed;color:#9a3412}.mad4b-ok-state{background:#f0fdf4;color:#166534}.mad4b-block-state{background:#fff7ed;color:#9a3412}.mad4b-grant-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:14px 0}.mad4b-grant-metrics div{padding:10px;border:1px solid var(--m4-line);border-radius:10px;background:#fff}.mad4b-grant-metrics strong{display:block;color:var(--m4-text);font-size:17px}.mad4b-grant-metrics span{display:block;margin-top:3px;color:var(--m4-muted);font-size:11px;line-height:1.35}.mad4b-live-grants details{margin-top:12px}.mad4b-live-grants summary{cursor:pointer;font-weight:700;color:#334155}.mad4b-live-grants ul{max-height:210px;overflow:auto;margin:12px 0 0;padding-left:20px}.mad4b-live-grants li{margin:7px 0;color:#64748b}.mad4b-grant-note,.mad4b-live-stamp{font-size:12px}.mad4b-grant-blockers{margin-top:12px;padding:10px 12px;border-radius:9px;font-size:12px;line-height:1.5}.mad4b-grant-blockers ul{max-height:none;margin:6px 0 0;padding-left:18px}.mad4b-grant-blockers.mad4b-ok{background:#f0fdf4;color:#166534}.mad4b-grant-blockers.mad4b-warn{background:#fff7ed;color:#9a3412}'
			. 'form{order:10;display:flex;gap:10px;align-items:center;margin-top:22px;padding-top:24px;border-top:1px solid var(--m4-line);}button{appearance:none;min-height:44px;padding:0 18px;border-radius:10px;border:1px solid transparent;font:700 14px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;cursor:pointer;transition:transform .12s ease,box-shadow .12s ease,background .12s ease,border-color .12s ease;}button:hover{transform:translateY(-1px)}button:focus-visible{outline:3px solid rgba(101,163,13,.28);outline-offset:2px;}button[value="approve"]{background:var(--m4-primary);color:#fff;box-shadow:0 6px 18px rgba(15,23,42,.18);}button[value="approve"]:hover{background:#1f2937;}button[value="deny"]{background:#fff;color:#475569;border-color:#cbd5e1;}button[value="deny"]:hover{background:#f8fafc;border-color:#94a3b8;}'
			. 'main:after{order:30;content:"OAuth read identity · Write/Developer authorities separate · PKCE S256";display:block;margin-top:20px;padding-top:16px;border-top:1px solid #f1f5f9;color:var(--m4-muted);font-size:12px;line-height:1.5;}'
			. '@media(max-width:520px){main{width:min(calc(100% - 20px),620px)!important;margin:10px auto!important;padding:24px!important;border-radius:16px;}h1{font-size:25px}.mad4b-consent-grid,.mad4b-authority-grid,.mad4b-grant-metrics{grid-template-columns:1fr}.mad4b-grant-head{align-items:flex-start;flex-direction:column}form{align-items:stretch;flex-direction:column}button{width:100%}}'
			. '@media(prefers-reduced-motion:reduce){button{transition:none}button:hover{transform:none}}'
			. '</style>';

		return str_replace( '</head>', $css . '</head>', $html );
	}

	public static function enhance_connection_document( $html ) {
		if ( ! is_string( $html ) || '' === $html ) return $html;
		if ( false === strpos( $html, 'MAD4B Connection' ) ) return $html;

		$legacy_workspace = 'Read-only connection workspace. It organizes environment, MCP transport, OAuth authority, isolation and external certification without creating credentials, connecting a client, enabling mutation, granting authority or making outbound discovery requests.';
		$governed_workspace = 'Connection and certification workspace. This page is inspection-only: it does not create credentials, connect a client, enable mutation, grant write authority or make outbound discovery requests. Governed write capability, when available, is established separately by Write Authority, Write Runtime Certification and one-time approvals.';
		$html = str_replace( $legacy_workspace, $governed_workspace, $html );

		$legacy_complete = 'The connection reports externally verified certification evidence. Continue to governance and adapter coverage before enabling any mutation path.';
		$governed_complete = 'The connection reports externally verified OAuth/MCP client evidence. This certification does not grant write authority; governed mutation availability is evaluated separately by Write Authority and Write Runtime Certification.';
		return str_replace( $legacy_complete, $governed_complete, $html );
	}
}
