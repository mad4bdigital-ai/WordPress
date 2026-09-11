<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Defense-in-depth deny gate for OAuth bearer claims.
 *
 * Claims are intentionally unverified here and can only cause denial. Exact
 * authority selection, signature/JWKS verification and issuer-bound subject
 * authorization are repeated by MAD4B_SCP_OAuth_Resource_Bridge before any
 * WordPress identity is granted.
 */
final class MAD4B_SCP_OAuth_Subject_Gate {
	const CONTRACT = 'mad4b.oauth-subject-gate.v2';
	const MAX_TOKEN_BYTES = 16384;
	const MAX_SEGMENT_BYTES = 65536;
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'enforce' ), 0, 3 );
	}

	public static function status() {
		$bridge = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		$issuers = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::trusted_issuers() : array();
		$count = 0;
		foreach ( $issuers as $issuer ) $count += count( MAD4B_SCP_OAuth_Resource_Bridge::allowed_subjects_for_issuer( $issuer ) );
		return array(
			'contract' => self::CONTRACT,
			'configured' => ! empty( $issuers ) && 0 < $count,
			'effective' => ! empty( $bridge['effective'] ) && 0 < $count,
			'authority_mode' => isset( $bridge['authority_mode'] ) ? $bridge['authority_mode'] : '',
			'trusted_issuer_count' => count( $issuers ),
			'allowed_subject_count' => $count,
			'binding' => 'issuer+subject+aud+resource',
			'enforcement_order' => 'pre-cryptographic-deny-then-rs256-verify-and-reauthorize',
			'claims_used_before_signature' => 'deny-only',
			'post_signature_subject_reauthorization' => true,
			'fail_closed' => true,
			'stores_bearer_tokens' => false,
			'creates_credentials' => false,
			'write_surfaces_enabled' => false,
		);
	}

	public static function enforce( $result, $server, $request ) {
		if ( null !== $result ) return $result;
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return $result;
		$route = '/' . ltrim( rtrim( (string) $request->get_route(), '/' ), '/' );
		if ( '/mcp/mad4b-chatgpt' !== $route ) return $result;
		if ( method_exists( $request, 'get_method' ) && 'OPTIONS' === strtoupper( (string) $request->get_method() ) ) return $result;

		$authorization = method_exists( $request, 'get_header' ) ? trim( (string) $request->get_header( 'authorization' ) ) : '';
		if ( '' === $authorization ) return $result;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return self::denied( 'mad4b_oauth_subject_policy_unavailable', 'OAuth authority registry is unavailable.', 503 );

		$claims = self::claims_from_bearer( $authorization );
		if ( is_wp_error( $claims ) ) return self::denied( $claims->get_error_code(), $claims->get_error_message(), 401 );

		$issuer = isset( $claims['iss'] ) && is_string( $claims['iss'] ) ? $claims['iss'] : '';
		$subject = isset( $claims['sub'] ) && is_string( $claims['sub'] ) ? trim( $claims['sub'] ) : '';
		$resource = MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier();
		if ( '' === $issuer || ! MAD4B_SCP_OAuth_Resource_Bridge::is_trusted_issuer( $issuer ) ) return self::denied( 'mad4b_oauth_subject_issuer_mismatch', 'OAuth subject issuer is not trusted for this resource.', 403 );
		if ( '' === $subject || strlen( $subject ) > 512 ) return self::denied( 'mad4b_oauth_subject_invalid', 'OAuth subject is invalid.', 403 );
		if ( ! self::audience_contains( isset( $claims['aud'] ) ? $claims['aud'] : null, $resource ) ) return self::denied( 'mad4b_oauth_subject_audience_mismatch', 'OAuth subject audience binding does not match.', 403 );
		if ( ! isset( $claims['resource'] ) || ! is_string( $claims['resource'] ) || ! hash_equals( $resource, untrailingslashit( trim( $claims['resource'] ) ) ) ) return self::denied( 'mad4b_oauth_subject_resource_mismatch', 'OAuth subject resource binding does not match.', 403 );
		if ( ! MAD4B_SCP_OAuth_Resource_Bridge::subject_allowed( $issuer, $subject ) ) return self::denied( 'mad4b_oauth_subject_not_approved', 'OAuth subject is not approved for this issuer.', 403 );
		return $result;
	}

	private static function claims_from_bearer( $authorization ) {
		if ( ! is_string( $authorization ) || strlen( $authorization ) > self::MAX_TOKEN_BYTES + 32 ) return new WP_Error( 'mad4b_oauth_subject_bearer_invalid', 'OAuth bearer header is invalid.' );
		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', trim( $authorization ), $matches ) ) return new WP_Error( 'mad4b_oauth_subject_bearer_invalid', 'OAuth bearer header is invalid.' );
		$token = (string) $matches[1];
		if ( '' === $token || strlen( $token ) > self::MAX_TOKEN_BYTES ) return new WP_Error( 'mad4b_oauth_subject_bearer_invalid', 'OAuth bearer token length is invalid.' );
		$parts = explode( '.', $token );
		unset( $token );
		if ( 3 !== count( $parts ) ) return new WP_Error( 'mad4b_oauth_subject_jwt_invalid', 'OAuth bearer token must be a compact JWT.' );
		$payload = self::base64url_decode( $parts[1] );
		if ( false === $payload || strlen( $payload ) > self::MAX_SEGMENT_BYTES ) return new WP_Error( 'mad4b_oauth_subject_jwt_invalid', 'OAuth bearer payload is invalid.' );
		$claims = json_decode( $payload, true );
		unset( $payload );
		return is_array( $claims ) ? $claims : new WP_Error( 'mad4b_oauth_subject_jwt_invalid', 'OAuth bearer claims are invalid.' );
	}

	private static function base64url_decode( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) return false;
		$remainder = strlen( $value ) % 4;
		if ( $remainder ) $value .= str_repeat( '=', 4 - $remainder );
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	private static function audience_contains( $audience, $expected ) {
		if ( is_string( $audience ) ) return hash_equals( $expected, $audience );
		if ( ! is_array( $audience ) || count( $audience ) > 20 ) return false;
		foreach ( $audience as $candidate ) if ( is_string( $candidate ) && hash_equals( $expected, $candidate ) ) return true;
		return false;
	}

	private static function denied( $code, $message, $status ) {
		$response = new WP_REST_Response( array( 'error' => sanitize_key( (string) $code ), 'message' => sanitize_text_field( (string) $message ) ), (int) $status );
		if ( class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) $response->header( 'WWW-Authenticate', MAD4B_SCP_OAuth_Resource_Bridge::challenge_header() );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
