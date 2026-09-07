<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Staging-first OAuth 2.1 resource-server bridge for the MAD4B read MCP surface.
 *
 * The authorization server remains external. This class publishes RFC 9728
 * protected-resource metadata, verifies RS256 access tokens against the
 * configured issuer JWKS, maps a verified subject to a fixed WordPress user,
 * and feeds only normalized identity evidence into the existing MAD4B NHI
 * context. Raw bearer tokens are never persisted or logged.
 */
final class MAD4B_SCP_OAuth_Resource_Bridge {
	const CONTRACT = 'mad4b.oauth-resource-bridge.v2';
	const READ_SCOPE = 'mad4b:read';
	const METADATA_NAMESPACE = 'mad4b/v1';
	const METADATA_ROUTE = '/oauth-protected-resource';
	const CLOCK_SKEW = 60;
	const MAX_TOKEN_BYTES = 16384;
	const CACHE_TTL = 300;

	private static $booted = false;
	private static $verified_context = null;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_metadata_route' ), 1 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'authenticate_rest_request' ), 1, 3 );
		add_filter( 'mad4b_scp_authenticated_subject_context', array( __CLASS__, 'filter_identity_context' ), 20 );
	}

	public static function register_metadata_route() {
		register_rest_route(
			self::METADATA_NAMESPACE,
			self::METADATA_ROUTE,
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( __CLASS__, 'metadata_endpoint' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function metadata_endpoint() {
		$status = self::status();
		if ( empty( $status['effective'] ) ) {
			return new WP_REST_Response(
				array( 'error' => 'mad4b_oauth_resource_bridge_unavailable', 'contract' => self::CONTRACT ),
				503
			);
		}
		$response = new WP_REST_Response( self::protected_resource_metadata(), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function status() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$enabled = defined( 'MAD4B_MCP_OAUTH_ENABLED' ) && true === MAD4B_MCP_OAUTH_ENABLED;
		$production_approved = defined( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED' ) && true === MAD4B_MCP_OAUTH_PRODUCTION_APPROVED;
		$issuer = self::configured_issuer();
		$user_id = self::configured_user_id();
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		$user_capable = $user && user_can( $user, 'manage_options' );
		$https = self::resource_is_https();
		$environment_allowed = ( 'staging' === $environment ) || ( 'production' === $environment && $production_approved );
		$effective = $enabled && '' !== $issuer && $user_capable && $https && $environment_allowed;

		return array(
			'contract' => self::CONTRACT,
			'configured' => $enabled,
			'effective' => (bool) $effective,
			'environment' => $environment,
			'production_approved' => $production_approved,
			'issuer' => $issuer,
			'issuer_configured' => '' !== $issuer,
			'resource' => self::resource_identifier(),
			'metadata_url' => self::metadata_url(),
			'authorization_server_metadata_urls' => self::authorization_server_metadata_urls(),
			'scopes_supported' => array( self::READ_SCOPE ),
			'wp_user_id' => $user_id,
			'wp_user_capable' => (bool) $user_capable,
			'https' => $https,
			'accepted_bearer_algorithms' => array( 'RS256' ),
			'jwks_x5c_required' => false,
			'jwks_rsa_ne_supported' => true,
			'stores_bearer_tokens' => false,
			'creates_credentials' => false,
			'outbound_discovery_on_admin' => false,
			'write_surfaces_enabled' => false,
		);
	}

	public static function protected_resource_metadata() {
		return array(
			'resource' => self::resource_identifier(),
			'authorization_servers' => array( self::configured_issuer() ),
			'scopes_supported' => array( self::READ_SCOPE ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	public static function resource_identifier() {
		return untrailingslashit( rest_url( 'mcp/mad4b-read' ) );
	}

	public static function metadata_url() {
		return untrailingslashit( rest_url( self::METADATA_NAMESPACE . self::METADATA_ROUTE ) );
	}

	/**
	 * Return standards-first authorization-server discovery candidates.
	 *
	 * RFC 8414 path-scoped issuers place the well-known component immediately
	 * after the origin and append the issuer path. OIDC discovery keeps the
	 * well-known component after the issuer path. A bounded legacy RFC 8414
	 * candidate is retained last for pre-existing deployments only.
	 */
	public static function authorization_server_metadata_urls() {
		$issuer = self::configured_issuer();
		if ( '' === $issuer ) return array();
		$parts = wp_parse_url( $issuer );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return array();
		$origin = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) $origin .= ':' . (int) $parts['port'];
		$issuer_path = isset( $parts['path'] ) ? '/' . ltrim( rtrim( (string) $parts['path'], '/' ), '/' ) : '';
		if ( '/' === $issuer_path ) $issuer_path = '';
		$documents = array(
			$issuer . '/.well-known/openid-configuration',
			$origin . '/.well-known/oauth-authorization-server' . $issuer_path,
			$issuer . '/.well-known/oauth-authorization-server',
		);
		return array_values( array_unique( array_map( 'esc_url_raw', $documents ) ) );
	}

	public static function authenticate_rest_request( $result, $server, $request ) {
		if ( null !== $result ) return $result;
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return $result;
		$route = '/' . ltrim( rtrim( (string) $request->get_route(), '/' ), '/' );
		if ( '/mcp/mad4b-read' !== $route ) return $result;
		if ( method_exists( $request, 'get_method' ) && 'OPTIONS' === strtoupper( (string) $request->get_method() ) ) return $result;

		self::$verified_context = null;
		$status = self::status();
		if ( empty( $status['effective'] ) ) {
			return self::unauthorized_response( 'mad4b_oauth_resource_bridge_not_effective', 'OAuth resource bridge is not effective for this environment.', 503 );
		}

		$authorization = method_exists( $request, 'get_header' ) ? trim( (string) $request->get_header( 'authorization' ) ) : '';
		if ( '' === $authorization ) {
			if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) return $result;
			return self::unauthorized_response( 'mad4b_oauth_bearer_required', 'OAuth bearer token is required for remote MAD4B read transport.' );
		}

		$token = self::extract_bearer_token( $authorization );
		if ( is_wp_error( $token ) ) return self::unauthorized_response( $token->get_error_code(), $token->get_error_message() );
		$verified = self::verify_access_token( $token );
		unset( $token );
		if ( is_wp_error( $verified ) ) return self::unauthorized_response( $verified->get_error_code(), $verified->get_error_message() );

		$user_id = self::configured_user_id();
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			return self::unauthorized_response( 'mad4b_oauth_wp_subject_invalid', 'Configured OAuth WordPress subject is missing required capability.', 403 );
		}

		wp_set_current_user( $user_id );
		self::$verified_context = array(
			'authenticated' => true,
			'subject_type' => 'oauth',
			'subject_fingerprint' => hash( 'sha256', 'oauth' . "\0" . $verified['issuer'] . "\0" . $verified['subject'] ),
			'token_scopes' => $verified['scopes'],
			'approval_ticket_id' => '',
			'auth_method' => 'oauth2_bearer',
			'wp_user_id' => $user_id,
			'origin' => 'mcp',
		);
		return $result;
	}

	public static function filter_identity_context( $context ) {
		if ( ! is_array( $context ) || ! is_array( self::$verified_context ) ) return $context;
		return array_merge( $context, self::$verified_context );
	}

	public static function challenge_header() {
		$metadata = class_exists( 'MAD4B_SCP_MCP_Client_Compatibility' ) ? MAD4B_SCP_MCP_Client_Compatibility::authoritative_well_known_url() : self::metadata_url();
		return 'Bearer resource_metadata="' . esc_url_raw( $metadata ) . '", scope="' . self::READ_SCOPE . '"';
	}

	private static function unauthorized_response( $code, $message, $status = 401 ) {
		$response = new WP_REST_Response(
			array( 'error' => sanitize_key( (string) $code ), 'message' => sanitize_text_field( (string) $message ) ),
			(int) $status
		);
		$response->header( 'WWW-Authenticate', self::challenge_header() );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function extract_bearer_token( $authorization ) {
		if ( ! is_string( $authorization ) || strlen( $authorization ) > self::MAX_TOKEN_BYTES + 32 ) {
			return new WP_Error( 'mad4b_oauth_authorization_header_invalid', 'Authorization header is invalid.' );
		}
		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', trim( $authorization ), $matches ) ) {
			return new WP_Error( 'mad4b_oauth_bearer_malformed', 'Authorization header must contain a Bearer token.' );
		}
		$token = (string) $matches[1];
		if ( '' === $token || strlen( $token ) > self::MAX_TOKEN_BYTES ) {
			return new WP_Error( 'mad4b_oauth_bearer_size_invalid', 'Bearer token length is invalid.' );
		}
		return $token;
	}

	private static function verify_access_token( $token ) {
		$parts = explode( '.', (string) $token );
		if ( 3 !== count( $parts ) ) return new WP_Error( 'mad4b_oauth_jwt_malformed', 'Access token must be a compact JWT.' );
		$header = self::decode_json_segment( $parts[0] );
		$claims = self::decode_json_segment( $parts[1] );
		$signature = self::base64url_decode( $parts[2] );
		if ( is_wp_error( $header ) || is_wp_error( $claims ) || false === $signature ) return new WP_Error( 'mad4b_oauth_jwt_decode_failed', 'Unable to decode access token.' );
		if ( ! isset( $header['alg'] ) || 'RS256' !== $header['alg'] ) return new WP_Error( 'mad4b_oauth_jwt_alg_denied', 'Only RS256 access tokens are accepted.' );
		$kid = isset( $header['kid'] ) && is_string( $header['kid'] ) ? trim( $header['kid'] ) : '';
		if ( '' === $kid || strlen( $kid ) > 255 ) return new WP_Error( 'mad4b_oauth_jwt_kid_missing', 'JWT key identifier is required.' );

		$discovery = self::authorization_server_metadata();
		if ( is_wp_error( $discovery ) ) return $discovery;
		$jwks = self::jwks( $discovery['jwks_uri'] );
		if ( is_wp_error( $jwks ) ) return $jwks;
		$key = self::matching_jwk( $jwks, $kid );
		if ( is_wp_error( $key ) ) return $key;
		$public_key = self::public_key_from_jwk( $key );
		if ( is_wp_error( $public_key ) ) return $public_key;
		$verified = openssl_verify( $parts[0] . '.' . $parts[1], $signature, $public_key, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $verified ) return new WP_Error( 'mad4b_oauth_jwt_signature_invalid', 'JWT signature verification failed.' );

		return self::validate_claims( $claims );
	}

	private static function validate_claims( array $claims ) {
		$issuer = self::configured_issuer();
		if ( ! isset( $claims['iss'] ) || ! is_string( $claims['iss'] ) || $issuer !== $claims['iss'] ) return new WP_Error( 'mad4b_oauth_issuer_mismatch', 'Access token issuer does not match configured issuer.' );
		$subject = isset( $claims['sub'] ) && is_string( $claims['sub'] ) ? trim( $claims['sub'] ) : '';
		if ( '' === $subject || strlen( $subject ) > 512 ) return new WP_Error( 'mad4b_oauth_subject_missing', 'Access token subject is missing.' );
		if ( ! self::audience_contains( isset( $claims['aud'] ) ? $claims['aud'] : null, self::resource_identifier() ) ) return new WP_Error( 'mad4b_oauth_audience_mismatch', 'Access token audience does not match the MAD4B read resource.' );

		$now = time();
		if ( ! isset( $claims['exp'] ) || ! is_numeric( $claims['exp'] ) || (int) $claims['exp'] < $now - self::CLOCK_SKEW ) return new WP_Error( 'mad4b_oauth_token_expired', 'Access token is expired or missing exp.' );
		if ( isset( $claims['nbf'] ) && ( ! is_numeric( $claims['nbf'] ) || (int) $claims['nbf'] > $now + self::CLOCK_SKEW ) ) return new WP_Error( 'mad4b_oauth_token_not_yet_valid', 'Access token is not yet valid.' );
		if ( isset( $claims['iat'] ) && ( ! is_numeric( $claims['iat'] ) || (int) $claims['iat'] > $now + self::CLOCK_SKEW ) ) return new WP_Error( 'mad4b_oauth_token_iat_invalid', 'Access token issued-at time is invalid.' );

		$scopes = self::extract_scopes( $claims );
		if ( is_wp_error( $scopes ) ) return $scopes;
		if ( ! in_array( self::READ_SCOPE, $scopes, true ) ) return new WP_Error( 'mad4b_oauth_scope_missing', 'Access token does not grant mad4b:read.' );
		return array( 'issuer' => $issuer, 'subject' => $subject, 'scopes' => $scopes );
	}

	private static function extract_scopes( array $claims ) {
		$raw = array();
		if ( isset( $claims['scope'] ) && is_string( $claims['scope'] ) ) $raw = preg_split( '/\s+/', trim( $claims['scope'] ) );
		elseif ( isset( $claims['scp'] ) && is_array( $claims['scp'] ) ) $raw = $claims['scp'];
		elseif ( isset( $claims['scp'] ) && is_string( $claims['scp'] ) ) $raw = preg_split( '/\s+/', trim( $claims['scp'] ) );
		$scopes = array();
		foreach ( is_array( $raw ) ? $raw : array() as $scope ) {
			if ( ! is_string( $scope ) ) return new WP_Error( 'mad4b_oauth_scope_invalid', 'Access token scopes must be strings.' );
			$scope = trim( $scope );
			if ( '' === $scope ) continue;
			if ( strlen( $scope ) > 255 || false !== strpos( $scope, '*' ) ) return new WP_Error( 'mad4b_oauth_scope_invalid', 'Access token contains an invalid scope.' );
			$scopes[] = $scope;
			if ( count( $scopes ) > MAD4B_SCP_Identity_Context::MAX_SCOPES ) return new WP_Error( 'mad4b_oauth_scope_count_invalid', 'Access token contains too many scopes.' );
		}
		return array_values( array_unique( $scopes ) );
	}

	private static function audience_contains( $audience, $expected ) {
		if ( is_string( $audience ) ) return hash_equals( $expected, $audience );
		if ( ! is_array( $audience ) ) return false;
		foreach ( $audience as $candidate ) if ( is_string( $candidate ) && hash_equals( $expected, $candidate ) ) return true;
		return false;
	}

	private static function authorization_server_metadata() {
		$issuer = self::configured_issuer();
		if ( '' === $issuer ) return new WP_Error( 'mad4b_oauth_issuer_unconfigured', 'OAuth issuer is not configured.' );
		$key = 'mad4b_oauth_discovery_' . substr( hash( 'sha256', $issuer ), 0, 32 );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) return $cached;

		foreach ( self::authorization_server_metadata_urls() as $url ) {
			$response = wp_safe_remote_get( $url, array( 'timeout' => 5, 'redirection' => 2, 'headers' => array( 'Accept' => 'application/json' ) ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) continue;
			$metadata = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $metadata ) || ! isset( $metadata['issuer'] ) || $issuer !== $metadata['issuer'] ) continue;
			if ( empty( $metadata['jwks_uri'] ) || ! self::same_origin_https_url( $metadata['jwks_uri'], $issuer ) ) continue;
			if ( empty( $metadata['authorization_endpoint'] ) || ! self::same_origin_https_url( $metadata['authorization_endpoint'], $issuer ) ) continue;
			if ( empty( $metadata['token_endpoint'] ) || ! self::same_origin_https_url( $metadata['token_endpoint'], $issuer ) ) continue;
			if ( empty( $metadata['code_challenge_methods_supported'] ) || ! is_array( $metadata['code_challenge_methods_supported'] ) || ! in_array( 'S256', $metadata['code_challenge_methods_supported'], true ) ) continue;
			$bounded = array(
				'issuer' => $issuer,
				'jwks_uri' => esc_url_raw( $metadata['jwks_uri'] ),
				'authorization_endpoint' => esc_url_raw( $metadata['authorization_endpoint'] ),
				'token_endpoint' => esc_url_raw( $metadata['token_endpoint'] ),
				'code_challenge_methods_supported' => array( 'S256' ),
			);
			set_transient( $key, $bounded, self::CACHE_TTL );
			return $bounded;
		}
		return new WP_Error( 'mad4b_oauth_discovery_unavailable', 'OAuth authorization-server metadata is unavailable or not MCP-compatible.' );
	}

	private static function jwks( $jwks_uri ) {
		$key = 'mad4b_oauth_jwks_' . substr( hash( 'sha256', (string) $jwks_uri ), 0, 32 );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) return $cached;
		$response = wp_safe_remote_get( $jwks_uri, array( 'timeout' => 5, 'redirection' => 2, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) return new WP_Error( 'mad4b_oauth_jwks_unavailable', 'OAuth JWKS endpoint is unavailable.' );
		$jwks = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $jwks ) || empty( $jwks['keys'] ) || ! is_array( $jwks['keys'] ) || count( $jwks['keys'] ) > 100 ) return new WP_Error( 'mad4b_oauth_jwks_invalid', 'OAuth JWKS payload is invalid.' );
		$bounded = array( 'keys' => array_slice( $jwks['keys'], 0, 100 ) );
		set_transient( $key, $bounded, self::CACHE_TTL );
		return $bounded;
	}

	private static function matching_jwk( array $jwks, $kid ) {
		foreach ( isset( $jwks['keys'] ) && is_array( $jwks['keys'] ) ? $jwks['keys'] : array() as $key ) {
			if ( ! is_array( $key ) || ! isset( $key['kid'] ) || ! is_string( $key['kid'] ) || ! hash_equals( $kid, $key['kid'] ) ) continue;
			if ( ! isset( $key['kty'] ) || 'RSA' !== $key['kty'] ) return new WP_Error( 'mad4b_oauth_jwk_type_invalid', 'JWT signing key must be RSA.' );
			if ( isset( $key['alg'] ) && 'RS256' !== $key['alg'] ) return new WP_Error( 'mad4b_oauth_jwk_alg_invalid', 'JWT signing key must use RS256.' );
			return $key;
		}
		return new WP_Error( 'mad4b_oauth_jwk_not_found', 'JWT signing key was not found for kid.' );
	}

	private static function public_key_from_jwk( array $key ) {
		if ( ! empty( $key['x5c'][0] ) && is_string( $key['x5c'][0] ) ) {
			return "-----BEGIN CERTIFICATE-----\n" . chunk_split( preg_replace( '/\s+/', '', $key['x5c'][0] ), 64, "\n" ) . "-----END CERTIFICATE-----\n";
		}
		if ( empty( $key['n'] ) || empty( $key['e'] ) || ! is_string( $key['n'] ) || ! is_string( $key['e'] ) ) {
			return new WP_Error( 'mad4b_oauth_jwk_material_missing', 'RSA JWKS signing key must provide x5c or n/e public-key material.' );
		}
		$modulus = self::base64url_decode( $key['n'] );
		$exponent = self::base64url_decode( $key['e'] );
		if ( false === $modulus || false === $exponent || '' === $modulus || '' === $exponent ) return new WP_Error( 'mad4b_oauth_jwk_material_invalid', 'RSA JWK n/e material is invalid.' );
		$rsa = self::asn1_sequence( self::asn1_integer( $modulus ) . self::asn1_integer( $exponent ) );
		$rsa_algorithm = hex2bin( '300d06092a864886f70d0101010500' );
		if ( false === $rsa_algorithm ) return new WP_Error( 'mad4b_oauth_jwk_material_invalid', 'RSA algorithm identifier is unavailable.' );
		$subject_public_key = self::asn1_sequence( $rsa_algorithm . "\x03" . self::asn1_length( strlen( $rsa ) + 1 ) . "\x00" . $rsa );
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $subject_public_key ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	private static function asn1_integer( $bytes ) {
		$bytes = ltrim( (string) $bytes, "\x00" );
		if ( '' === $bytes ) $bytes = "\x00";
		if ( ord( $bytes[0] ) & 0x80 ) $bytes = "\x00" . $bytes;
		return "\x02" . self::asn1_length( strlen( $bytes ) ) . $bytes;
	}

	private static function asn1_sequence( $bytes ) {
		return "\x30" . self::asn1_length( strlen( $bytes ) ) . $bytes;
	}

	private static function asn1_length( $length ) {
		$length = (int) $length;
		if ( $length < 128 ) return chr( $length );
		$encoded = '';
		while ( $length > 0 ) { $encoded = chr( $length & 0xff ) . $encoded; $length >>= 8; }
		return chr( 0x80 | strlen( $encoded ) ) . $encoded;
	}

	private static function configured_issuer() {
		if ( ! defined( 'MAD4B_MCP_OAUTH_ISSUER' ) ) return '';
		$issuer = rtrim( trim( (string) constant( 'MAD4B_MCP_OAUTH_ISSUER' ) ), '/' );
		if ( '' === $issuer || ! self::valid_https_url( $issuer ) ) return '';
		return $issuer;
	}

	private static function configured_user_id() {
		return defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ? absint( constant( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) : 0;
	}

	private static function resource_is_https() {
		return 'https' === strtolower( (string) wp_parse_url( self::resource_identifier(), PHP_URL_SCHEME ) );
	}

	private static function valid_https_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && 'https' === strtolower( (string) $parts['scheme'] ) && '' !== (string) $parts['host'] && empty( $parts['user'] ) && empty( $parts['pass'] ) && empty( $parts['query'] ) && empty( $parts['fragment'] );
	}

	private static function same_origin_https_url( $url, $issuer ) {
		if ( ! self::valid_https_url( $url ) ) return false;
		$url_parts = wp_parse_url( (string) $url );
		$issuer_parts = wp_parse_url( (string) $issuer );
		$url_port = isset( $url_parts['port'] ) ? (int) $url_parts['port'] : 443;
		$issuer_port = isset( $issuer_parts['port'] ) ? (int) $issuer_parts['port'] : 443;
		return isset( $url_parts['host'], $issuer_parts['host'] ) && strtolower( (string) $url_parts['host'] ) === strtolower( (string) $issuer_parts['host'] ) && $url_port === $issuer_port;
	}

	private static function decode_json_segment( $segment ) {
		$decoded = self::base64url_decode( $segment );
		if ( false === $decoded || strlen( $decoded ) > 65536 ) return new WP_Error( 'mad4b_oauth_jwt_segment_invalid', 'JWT segment is invalid.' );
		$value = json_decode( $decoded, true );
		return is_array( $value ) ? $value : new WP_Error( 'mad4b_oauth_jwt_json_invalid', 'JWT segment JSON is invalid.' );
	}

	private static function base64url_decode( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) return false;
		$padding = strlen( $value ) % 4;
		if ( $padding ) $value .= str_repeat( '=', 4 - $padding );
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}
}
