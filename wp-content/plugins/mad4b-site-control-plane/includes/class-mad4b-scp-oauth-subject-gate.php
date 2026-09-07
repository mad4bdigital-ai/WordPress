<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Defense-in-depth subject policy for external OAuth bearer tokens.
 *
 * The OAuth resource bridge remains authoritative for RS256/JWKS verification.
 * This gate runs before that bridge and uses unverified JWT claims only to deny
 * requests that cannot possibly satisfy the configured iss + sub + aud/resource
 * policy. Passing this gate grants nothing: the bridge must still verify the
 * signature and all token claims before any fixed WordPress service identity is
 * selected. This ordering prevents an unapproved subject from ever reaching the
 * privileged identity mapping. The gate creates no credential, grant, approval
 * or mutation authority.
 */
final class MAD4B_SCP_OAuth_Subject_Gate {
	const CONTRACT = 'mad4b.oauth-subject-gate.v1';
	const MAX_TOKEN_BYTES = 16384;
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		// Fail closed on subject/resource policy at priority 0. The resource bridge
		// follows at priority 1 and remains responsible for cryptographic trust.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'enforce' ), 0, 3 );
	}

	public static function status() {
		$subjects = self::allowed_subjects();
		$bridge = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		return array(
			'contract' => self::CONTRACT,
			'configured' => ! empty( $subjects ),
			'effective' => ! empty( $subjects ) && ! empty( $bridge['effective'] ),
			'allowed_subject_count' => count( $subjects ),
			'binding' => 'iss+sub+aud+resource',
			'enforcement_order' => 'pre-cryptographic-deny-then-rs256-verify',
			'claims_used_before_signature' => 'deny-only',
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
		if ( '/mcp/mad4b-read' !== $route ) return $result;
		if ( method_exists( $request, 'get_method' ) && 'OPTIONS' === strtoupper( (string) $request->get_method() ) ) return $result;

		$authorization = method_exists( $request, 'get_header' ) ? trim( (string) $request->get_header( 'authorization' ) ) : '';
		// Local authenticated WordPress administration is governed by the existing
		// permission callback and is not an external OAuth subject.
		if ( '' === $authorization ) return $result;

		$claims = self::claims_from_bearer( $authorization );
		if ( is_wp_error( $claims ) ) return self::denied( $claims->get_error_code(), $claims->get_error_message(), 401 );

		$issuer = self::configured_issuer();
		$resource = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier() : '';
		$subject = isset( $claims['sub'] ) && is_string( $claims['sub'] ) ? trim( $claims['sub'] ) : '';
		if ( '' === $issuer || '' === $resource ) return self::denied( 'mad4b_oauth_subject_policy_unavailable', 'OAuth subject policy binding is unavailable.', 503 );
		if ( ! isset( $claims['iss'] ) || ! is_string( $claims['iss'] ) || ! hash_equals( $issuer, $claims['iss'] ) ) return self::denied( 'mad4b_oauth_subject_issuer_mismatch', 'OAuth subject issuer binding does not match.', 403 );
		if ( '' === $subject || strlen( $subject ) > 512 ) return self::denied( 'mad4b_oauth_subject_invalid', 'OAuth subject is invalid.', 403 );
		if ( ! self::audience_contains( isset( $claims['aud'] ) ? $claims['aud'] : null, $resource ) ) return self::denied( 'mad4b_oauth_subject_audience_mismatch', 'OAuth subject audience binding does not match.', 403 );
		if ( ! isset( $claims['resource'] ) || ! is_string( $claims['resource'] ) || ! hash_equals( $resource, untrailingslashit( trim( $claims['resource'] ) ) ) ) return self::denied( 'mad4b_oauth_subject_resource_mismatch', 'OAuth subject resource binding does not match.', 403 );

		$subjects = self::allowed_subjects();
		if ( empty( $subjects ) ) return self::denied( 'mad4b_oauth_subject_policy_unconfigured', 'OAuth external subject allowlist is not configured.', 403 );
		if ( ! in_array( $subject, $subjects, true ) ) return self::denied( 'mad4b_oauth_subject_not_approved', 'OAuth subject is not approved for this protected resource.', 403 );
		return $result;
	}

	private static function allowed_subjects() {
		if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) return array();
		$value = constant( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' );
		$items = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		$subjects = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( ! is_string( $item ) ) continue;
			$item = trim( $item );
			if ( '' === $item || strlen( $item ) > 512 || preg_match( '/[\s,]/', $item ) ) continue;
			if ( 0 !== strpos( $item, 'user:' ) && 0 !== strpos( $item, 'tenant:' ) ) continue;
			$subjects[] = $item;
		}
		return array_values( array_unique( $subjects ) );
	}

	private static function configured_issuer() {
		if ( ! defined( 'MAD4B_MCP_OAUTH_ISSUER' ) ) return '';
		$issuer = rtrim( trim( (string) constant( 'MAD4B_MCP_OAUTH_ISSUER' ) ), '/' );
		$parts = wp_parse_url( $issuer );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) ) return '';
		return $issuer;
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
		if ( false === $payload ) return new WP_Error( 'mad4b_oauth_subject_jwt_invalid', 'OAuth bearer payload is invalid.' );
		$claims = json_decode( $payload, true );
		unset( $payload );
		return is_array( $claims ) ? $claims : new WP_Error( 'mad4b_oauth_subject_jwt_invalid', 'OAuth bearer claims are invalid.' );
	}

	private static function base64url_decode( $value ) {
		$value = strtr( (string) $value, '-_', '+/' );
		$remainder = strlen( $value ) % 4;
		if ( $remainder ) $value .= str_repeat( '=', 4 - $remainder );
		return base64_decode( $value, true );
	}

	private static function audience_contains( $audience, $expected ) {
		if ( is_string( $audience ) ) return hash_equals( $expected, $audience );
		if ( ! is_array( $audience ) ) return false;
		foreach ( $audience as $candidate ) if ( is_string( $candidate ) && hash_equals( $expected, $candidate ) ) return true;
		return false;
	}

	private static function denied( $code, $message, $status ) {
		$response = new WP_REST_Response(
			array( 'error' => sanitize_key( (string) $code ), 'message' => sanitize_text_field( (string) $message ) ),
			(int) $status
		);
		if ( class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) $response->header( 'WWW-Authenticate', MAD4B_SCP_OAuth_Resource_Bridge::challenge_header() );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}