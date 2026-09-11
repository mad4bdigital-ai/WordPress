<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deny-only JOSE header policy for Remote MCP access tokens.
 *
 * `typ` is part of the JWS protected header and the Resource Bridge later
 * verifies the signature over these exact bytes. Rejecting a wrong/missing typ
 * before signature verification therefore grants nothing, while preventing an
 * otherwise valid RS256 token issued for a different JWT purpose from being
 * consumed as an MCP access token.
 */
final class MAD4B_SCP_OAuth_JWT_Header_Guard {
	const CONTRACT = 'mad4b.oauth-jwt-header-guard.v1';
	const MAX_TOKEN_BYTES = 16384;
	const MAX_HEADER_BYTES = 8192;
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'enforce' ), 0, 3 );
	}

	public static function enforce( $result, $server, $request ) {
		if ( null !== $result ) return $result;
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return $result;
		$route = '/' . ltrim( rtrim( (string) $request->get_route(), '/' ), '/' );
		if ( '/mcp/mad4b-chatgpt' !== $route ) return $result;
		if ( method_exists( $request, 'get_method' ) && 'OPTIONS' === strtoupper( (string) $request->get_method() ) ) return $result;
		$authorization = method_exists( $request, 'get_header' ) ? trim( (string) $request->get_header( 'authorization' ) ) : '';
		if ( '' === $authorization ) return $result;

		$header = self::header_from_authorization( $authorization );
		if ( is_wp_error( $header ) ) return self::denied( $header->get_error_code(), $header->get_error_message() );
		if ( ! isset( $header['typ'] ) || ! is_string( $header['typ'] ) || ! hash_equals( 'at+jwt', strtolower( trim( $header['typ'] ) ) ) ) {
			return self::denied( 'mad4b_oauth_jwt_typ_denied', 'OAuth bearer must use the at+jwt access-token type.' );
		}
		if ( ! isset( $header['alg'] ) || ! is_string( $header['alg'] ) || ! hash_equals( 'RS256', $header['alg'] ) ) {
			return self::denied( 'mad4b_oauth_jwt_alg_denied', 'Only RS256 access tokens are accepted.' );
		}
		return $result;
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'expected_typ' => 'at+jwt',
			'expected_alg' => 'RS256',
			'protected_header_verified_later' => true,
			'claims_used_for_authority' => false,
			'creates_authority' => false,
		);
	}

	private static function header_from_authorization( $authorization ) {
		if ( ! is_string( $authorization ) || strlen( $authorization ) > self::MAX_TOKEN_BYTES + 32 ) return new WP_Error( 'mad4b_oauth_jwt_header_invalid', 'OAuth Authorization header is invalid.' );
		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', trim( $authorization ), $matches ) ) return new WP_Error( 'mad4b_oauth_jwt_header_invalid', 'OAuth bearer header is malformed.' );
		$token = (string) $matches[1];
		if ( '' === $token || strlen( $token ) > self::MAX_TOKEN_BYTES ) return new WP_Error( 'mad4b_oauth_jwt_header_invalid', 'OAuth bearer token length is invalid.' );
		$parts = explode( '.', $token );
		unset( $token );
		if ( 3 !== count( $parts ) ) return new WP_Error( 'mad4b_oauth_jwt_header_invalid', 'OAuth bearer must be a compact JWT.' );
		$decoded = self::base64url_decode( $parts[0] );
		if ( false === $decoded || strlen( $decoded ) > self::MAX_HEADER_BYTES ) return new WP_Error( 'mad4b_oauth_jwt_header_invalid', 'OAuth JWT protected header is invalid.' );
		$header = json_decode( $decoded, true );
		unset( $decoded );
		return is_array( $header ) ? $header : new WP_Error( 'mad4b_oauth_jwt_header_invalid', 'OAuth JWT protected header JSON is invalid.' );
	}

	private static function base64url_decode( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) return false;
		$padding = strlen( $value ) % 4;
		if ( $padding ) $value .= str_repeat( '=', 4 - $padding );
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	private static function denied( $code, $message ) {
		$response = new WP_REST_Response( array( 'error' => sanitize_key( (string) $code ), 'message' => sanitize_text_field( (string) $message ) ), 401 );
		if ( class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) $response->header( 'WWW-Authenticate', MAD4B_SCP_OAuth_Resource_Bridge::challenge_header() );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
