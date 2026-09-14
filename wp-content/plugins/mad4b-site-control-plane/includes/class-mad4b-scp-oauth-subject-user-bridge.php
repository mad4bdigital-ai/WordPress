<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Maps an already-verified OAuth `user:<id>` subject to that exact WordPress user.
 *
 * The OAuth resource bridge remains the cryptographic authority: this class runs
 * only after its rest_pre_dispatch verifier has accepted the bearer. It never
 * validates an untrusted JWT by itself and never creates grants. Its only role is
 * to stop a multi-user connection from collapsing every verified subject onto a
 * single compatibility/default WordPress administrator.
 */
final class MAD4B_SCP_OAuth_Subject_User_Bridge {
	const CONTRACT = 'mad4b.oauth-subject-user-bridge.v1';
	const MAX_TOKEN_BYTES = 16384;
	const MAX_SEGMENT_BYTES = 65536;

	private static $booted = false;
	private static $mapped_context = null;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'map_verified_subject' ), 2, 3 );
		add_filter( 'mad4b_scp_authenticated_subject_context', array( __CLASS__, 'filter_identity_context' ), 30 );
	}

	public static function map_verified_subject( $result, $server, $request ) {
		self::$mapped_context = null;
		if ( null !== $result ) return $result;
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return $result;
		$route = '/' . ltrim( rtrim( (string) $request->get_route(), '/' ), '/' );
		if ( '/mcp/mad4b-chatgpt' !== $route ) return $result;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return $result;
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::oauth_enabled() ) {
			return self::deny( 'mad4b_oauth_site_profile_not_enrolled', 'Verified OAuth identity is not bound to an exact enrolled Site Profile.' );
		}

		$authorization = method_exists( $request, 'get_header' ) ? trim( (string) $request->get_header( 'authorization' ) ) : '';
		$claims = self::verified_bearer_claims( $authorization );
		if ( is_wp_error( $claims ) ) return self::deny( $claims->get_error_code(), $claims->get_error_message() );

		$issuer = isset( $claims['iss'] ) && is_string( $claims['iss'] ) ? rtrim( trim( $claims['iss'] ), '/' ) : '';
		$subject = isset( $claims['sub'] ) && is_string( $claims['sub'] ) ? trim( $claims['sub'] ) : '';
		if ( '' === $issuer || '' === $subject || ! preg_match( '/^user:([1-9][0-9]*)$/', $subject, $matches ) ) {
			return self::deny( 'mad4b_oauth_subject_user_mapping_required', 'Verified OAuth subject must be an enrolled user:<id> subject for this Site Profile.' );
		}
		if ( ! MAD4B_SCP_OAuth_Resource_Bridge::is_trusted_issuer( $issuer ) || ! MAD4B_SCP_OAuth_Resource_Bridge::subject_allowed( $issuer, $subject ) ) {
			return self::deny( 'mad4b_oauth_subject_not_approved', 'Verified OAuth subject is outside the configured issuer/subject policy.' );
		}

		$user_id = absint( $matches[1] );
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) {
			return self::deny( 'mad4b_oauth_subject_user_not_enrolled', 'Verified OAuth user is not enrolled in the current Site Profile.' );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) return self::deny( 'mad4b_oauth_subject_user_missing', 'Verified OAuth WordPress user no longer exists.' );
		if ( class_exists( 'MAD4B_SCP_Policy' ) && method_exists( 'MAD4B_SCP_Policy', 'can_connect_user' ) && ! MAD4B_SCP_Policy::can_connect_user( $user_id ) ) {
			return self::deny( 'mad4b_oauth_subject_user_not_connection_capable', 'Verified OAuth WordPress user is no longer connection-capable.' );
		}

		wp_set_current_user( $user_id );
		self::$mapped_context = array(
			'authenticated' => true,
			'subject_type' => 'oauth',
			'subject_fingerprint' => hash( 'sha256', 'oauth' . "\0" . $issuer . "\0" . $subject ),
			'wp_user_id' => $user_id,
			'origin' => 'mcp',
			'issuer' => $issuer,
			'subject' => $subject,
			'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
			'site_profile_revision' => MAD4B_SCP_Site_Profile::revision(),
			'site_profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
		);
		return $result;
	}

	public static function filter_identity_context( $context ) {
		if ( ! is_array( $context ) || ! is_array( self::$mapped_context ) ) return $context;
		foreach ( array( 'authenticated', 'subject_type', 'subject_fingerprint', 'wp_user_id', 'origin' ) as $key ) {
			$context[ $key ] = self::$mapped_context[ $key ];
		}
		$context['site_uuid'] = self::$mapped_context['site_uuid'];
		$context['site_profile_revision'] = self::$mapped_context['site_profile_revision'];
		$context['site_profile_digest'] = self::$mapped_context['site_profile_digest'];
		return $context;
	}

	public static function mapped_context() {
		return is_array( self::$mapped_context ) ? self::$mapped_context : array();
	}

	private static function verified_bearer_claims( $authorization ) {
		if ( ! is_string( $authorization ) || strlen( $authorization ) > self::MAX_TOKEN_BYTES + 16 || ! preg_match( '/^Bearer[ \t]+([^ \t\r\n]+)$/i', $authorization, $matches ) ) {
			return new WP_Error( 'mad4b_oauth_bearer_invalid', 'Verified bearer header cannot be remapped safely.' );
		}
		$token = (string) $matches[1];
		if ( strlen( $token ) > self::MAX_TOKEN_BYTES ) return new WP_Error( 'mad4b_oauth_bearer_too_large', 'Bearer token exceeds the remapping size bound.' );
		$parts = explode( '.', $token );
		unset( $token );
		if ( 3 !== count( $parts ) ) return new WP_Error( 'mad4b_oauth_jwt_shape_invalid', 'Verified bearer is not a JWT.' );
		$payload = self::base64url_decode( $parts[1] );
		$parts = array();
		if ( false === $payload || strlen( $payload ) > self::MAX_SEGMENT_BYTES ) return new WP_Error( 'mad4b_oauth_jwt_payload_invalid', 'Verified JWT payload is invalid.' );
		$claims = json_decode( $payload, true );
		unset( $payload );
		return is_array( $claims ) ? $claims : new WP_Error( 'mad4b_oauth_jwt_payload_invalid', 'Verified JWT payload is not a JSON object.' );
	}

	private static function base64url_decode( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) return false;
		$padding = strlen( $value ) % 4;
		if ( $padding ) $value .= str_repeat( '=', 4 - $padding );
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	private static function deny( $code, $message ) {
		return new WP_Error( sanitize_key( (string) $code ), (string) $message, array( 'status' => 403 ) );
	}
}
