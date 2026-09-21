<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Presentation-only enhancement for the local OAuth consent screen.
 *
 * The OAuth server remains the sole owner of request validation, nonce fields,
 * consent decisions, authorization-code issuance and redirects. This class only
 * injects bounded inline CSS into the already-rendered GET consent document.
 */
final class MAD4B_SCP_Local_OAuth_Consent_UI {
	const CONTRACT = 'mad4b.local-oauth-consent-ui.v1';
	const STYLE_ID = 'mad4b-oauth-consent-ui';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'parse_request', array( __CLASS__, 'start_buffer_for_consent' ), -20 );
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

	public static function enhance_document( $html ) {
		if ( ! is_string( $html ) || '' === $html ) return $html;
		if ( false !== strpos( $html, 'id="' . self::STYLE_ID . '"' ) ) return $html;
		if ( false === strpos( $html, '<title>Authorize MCP access</title>' ) ) return $html;
		if ( false === strpos( $html, '_mad4b_oauth_nonce' ) ) return $html;
		if ( false === strpos( $html, '</head>' ) ) return $html;

		$css = '<style id="' . self::STYLE_ID . '">'
			. ':root{color-scheme:light;--m4-text:#0f172a;--m4-muted:#64748b;--m4-line:#e2e8f0;--m4-bg:#f8fafc;--m4-card:#fff;--m4-primary:#111827;--m4-accent:#65a30d;}'
			. '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:radial-gradient(circle at 50% -10%,#ecfccb 0,transparent 34%),linear-gradient(180deg,#f8fafc 0%,#eef2f7 100%);color:var(--m4-text);}'
			. 'main{position:relative;width:min(calc(100% - 32px),620px)!important;max-width:620px!important;margin:32px auto!important;padding:36px!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;background:var(--m4-card);border:1px solid var(--m4-line);border-radius:20px;box-shadow:0 24px 60px rgba(15,23,42,.10),0 2px 8px rgba(15,23,42,.05);}'
			. 'main:before{content:"MAD4B · Secure OAuth connection";display:inline-flex;align-items:center;margin:0 0 18px;padding:7px 11px;border:1px solid #d9f99d;border-radius:999px;background:#f7fee7;color:#3f6212;font-size:12px;font-weight:700;letter-spacing:.02em;}'
			. 'h1{margin:0 0 12px;font-size:30px;line-height:1.16;letter-spacing:-.025em;color:var(--m4-text);}p{margin:11px 0;color:#475569;font-size:15px;line-height:1.65;}p strong{color:var(--m4-text);font-weight:700;}code{display:inline-block;max-width:100%;padding:3px 7px;border:1px solid var(--m4-line);border-radius:7px;background:#f8fafc;color:#334155;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;overflow-wrap:anywhere;vertical-align:middle;}'
			. 'form{display:flex;gap:10px;align-items:center;margin-top:26px;padding-top:24px;border-top:1px solid var(--m4-line);}button{appearance:none;min-height:44px;padding:0 18px;border-radius:10px;border:1px solid transparent;font:700 14px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;cursor:pointer;transition:transform .12s ease,box-shadow .12s ease,background .12s ease,border-color .12s ease;}button:hover{transform:translateY(-1px)}button:focus-visible{outline:3px solid rgba(101,163,13,.28);outline-offset:2px;}button[value="approve"]{background:var(--m4-primary);color:#fff;box-shadow:0 6px 18px rgba(15,23,42,.18);}button[value="approve"]:hover{background:#1f2937;}button[value="deny"]{background:#fff;color:#475569;border-color:#cbd5e1;}button[value="deny"]:hover{background:#f8fafc;border-color:#94a3b8;}'
			. 'main:after{content:"Read-only access · OAuth 2.1 · PKCE S256";display:block;margin-top:20px;padding-top:16px;border-top:1px solid #f1f5f9;color:var(--m4-muted);font-size:12px;line-height:1.5;}'
			. '@media(max-width:520px){main{width:min(calc(100% - 20px),620px)!important;margin:10px auto!important;padding:24px!important;border-radius:16px;}h1{font-size:25px}form{align-items:stretch;flex-direction:column}button{width:100%}}'
			. '@media(prefers-reduced-motion:reduce){button{transition:none}button:hover{transform:none}}'
			. '</style>';

		return str_replace( '</head>', $css . '</head>', $html );
	}
}
