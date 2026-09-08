<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Staging-only OAuth resource-server bridge for the exact MAD4B read MCP
 * resource. The Authorization Server keeps the private signing key; this
 * bridge validates RS256 bearer tokens against a fixed JWKS endpoint and
 * maps a verified OAuth subject to one explicitly bound WordPress user.
 */
final class MAD4B_SCP_Staging_OAuth_Bridge {
	const CONTRACT = 'mad4b.wordpress-staging-mcp-resource-server.v1';
	const ENABLE_FLAG = 'MAD4B_SCP_WORDPRESS_STAGING_OAUTH_ENABLED';
	const RESOURCE = 'https://staging.egypttourgates.com/wp-json/mcp/mad4b-read';
	const RESOURCE_PATH = '/wp-json/mcp/mad4b-read';
	const MCP_ROUTE = '/mcp/mad4b-read';
	const ISSUER = 'https://dev.mad4b.com/auth/mcp/wordpress-staging';
	const JWKS_URI = 'https://dev.mad4b.com/auth/mcp/wordpress-staging/oauth/jwks';
	const METADATA_PATH = '/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-read';
	const METADATA_URL = 'https://staging.egypttourgates.com/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-read';
	const REQUIRED_SCOPE = 'mad4b:read';
	const OFFLINE_SCOPE = 'offline_access';
	const TOKEN_PURPOSE = 'wordpress_staging_mcp_access';
	const CLIENT_ID_PREFIX = 'mcp_stg_wp_';
	const CLIENT_PROFILE_PREFIX = 'wordpress_staging_mcp:';
	const SUBJECT_TYPE = 'oauth_subject';
	const USER_META_KEY = 'mad4b_scp_oauth_subject_fingerprint';
	const JWKS_CACHE_KEY = 'mad4b_scp_wordpress_staging_oauth_jwks_v1';
	const JWKS_CACHE_TTL = 300;
	const CLOCK_SKEW = 30;
	const MAX_TOKEN_LIFETIME = 3600;
	const MAX_TOKEN_BYTES = 32768;

	private static $bearer_attempted = false;
	private static $verified = false;
	private static $auth_error = null;
	private static $claims = array();
	private static $scopes = array();
	private static $subject_fingerprint = '';
	private static $wp_user_id = 0;

	public static function boot() {
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve_protected_resource_metadata' ), 0 );
		add_filter( 'determine_current_user', array( __CLASS__, 'authenticate_bearer_user' ), 5 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_authentication_errors' ), 5 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'enforce_resource_authentication' ), 5, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'attach_bearer_challenge' ), 10, 3 );
		add_filter( 'mad4b_scp_authenticated_subject_context', array( __CLASS__, 'inject_subject_context' ), 5 );
	}

	public static function enabled() {
		if ( ! defined( self::ENABLE_FLAG ) || true !== constant( self::ENABLE_FLAG ) ) return false;
		if ( ! extension_loaded( 'openssl' ) ) return false;
		if ( function_exists( 'wp_get_environment_type' ) && 'staging' !== wp_get_environment_type() ) return false;
		if ( ! function_exists( 'home_url' ) ) return false;
		return self::normalize_origin( home_url( '/' ) ) === 'https://staging.egypttourgates.com';
	}

	private static function normalize_origin( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) return '';
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) return '';
		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( '' === $host ) return '';
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 443;
		if ( 443 !== $port ) return '';
		$path = isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';
		if ( '' !== $path ) return '';
		return 'https://' . $host;
	}

	private static function request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}

	private static function is_exact_resource_request() {
		return self::enabled() && self::RESOURCE_PATH === self::request_path();
	}

	public static function protected_resource_metadata() {
		return array(
			'resource' => self::RESOURCE,
			'authorization_servers' => array( self::ISSUER ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported' => array( self::REQUIRED_SCOPE ),
			'resource_name' => 'MAD4B WordPress Staging Read MCP',
		);
	}

	public static function maybe_serve_protected_resource_metadata() {
		if ( ! self::enabled() || self::METADATA_PATH !== self::request_path() ) return;
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			status_header( 405 );
			header( 'Allow: GET, HEAD' );
			header( 'Cache-Control: no-store' );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=300' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'HEAD' !== $method ) echo wp_json_encode( self::protected_resource_metadata(), JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response.
		exit;
	}

	public static function challenge_header( $invalid_token = false ) {
		$challenge = 'Bearer resource_metadata="' . self::METADATA_URL . '"';
		if ( $invalid_token ) $challenge .= ', error="invalid_token"';
		return $challenge;
	}

	private static function authorization_header() {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && '' !== trim( $_SERVER[ $key ] ) ) return trim( (string) wp_unslash( $_SERVER[ $key ] ) );
		}
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $name => $value ) {
					if ( 'authorization' === strtolower( (string) $name ) && is_string( $value ) ) return trim( $value );
				}
			}
		}
		return '';
	}

	private static function bearer_token_from_header( $header ) {
		$header = trim( (string) $header );
		if ( '' === $header ) return '';
		if ( ! preg_match( '/^Bearer[\x20\x09]+([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/i', $header, $matches ) ) return null;
		return $matches[1];
	}

	private static function auth_error( $code, $message ) {
		return new WP_Error( $code, $message, array( 'status' => 401 ) );
	}

	private static function reset_request_state() {
		self::$bearer_attempted = false;
		self::$verified = false;
		self::$auth_error = null;
		self::$claims = array();
		self::$scopes = array();
		self::$subject_fingerprint = '';
		self::$wp_user_id = 0;
	}

	public static function authenticate_bearer_user( $user_id ) {
		if ( ! self::is_exact_resource_request() ) return $user_id;
		self::reset_request_state();
		$header = self::authorization_header();
		if ( '' === $header ) return $user_id;
		self::$bearer_attempted = true;
		$token = self::bearer_token_from_header( $header );
		if ( ! is_string( $token ) || '' === $token ) {
			self::$auth_error = self::auth_error( 'mad4b_oauth_invalid_authorization_header', 'Bearer authentication failed.' );
			return 0;
		}
		$claims = self::verify_access_token( $token );
		if ( is_wp_error( $claims ) ) {
			self::$auth_error = $claims;
			return 0;
		}
		$bound_user = self::resolve_bound_user_id( $claims );
		if ( is_wp_error( $bound_user ) ) {
			self::$auth_error = $bound_user;
			return 0;
		}
		self::$verified = true;
		self::$claims = $claims;
		self::$scopes = self::scope_list( $claims['scope'] );
		self::$subject_fingerprint = self::subject_fingerprint( $claims['sub'] );
		self::$wp_user_id = (int) $bound_user;
		return self::$wp_user_id;
	}

	public static function rest_authentication_errors( $result ) {
		if ( ! self::is_exact_resource_request() ) return $result;
		if ( is_wp_error( self::$auth_error ) ) return self::$auth_error;
		if ( self::$verified ) return true;
		return $result;
	}

	public static function enforce_resource_authentication( $result, $server, $request ) {
		if ( ! self::enabled() || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || self::MCP_ROUTE !== (string) $request->get_route() ) return $result;
		if ( is_wp_error( self::$auth_error ) ) return self::$auth_error;
		if ( self::$verified ) return $result;
		if ( is_user_logged_in() ) return $result;
		return self::auth_error( 'mad4b_oauth_bearer_required', 'Bearer authentication is required for this protected resource.' );
	}

	public static function attach_bearer_challenge( $response, $server, $request ) {
		if ( ! self::enabled() || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || self::MCP_ROUTE !== (string) $request->get_route() ) return $response;
		$response = rest_ensure_response( $response );
		if ( 401 === (int) $response->get_status() ) {
			$response->header( 'WWW-Authenticate', self::challenge_header( self::$bearer_attempted ) );
			$response->header( 'Cache-Control', 'no-store' );
		}
		return $response;
	}

	public static function inject_subject_context( $context ) {
		if ( ! self::$verified || self::$wp_user_id <= 0 || ! preg_match( '/^[a-f0-9]{64}$/', self::$subject_fingerprint ) ) return $context;
		return array(
			'authenticated' => true,
			'subject_type' => self::SUBJECT_TYPE,
			'subject_fingerprint' => self::$subject_fingerprint,
			'token_scopes' => self::$scopes,
			'approval_ticket_id' => '',
			'auth_method' => 'oauth_rs256_jwks',
			'wp_user_id' => self::$wp_user_id,
			'request_id' => class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::request_id() : wp_generate_uuid4(),
			'origin' => 'mcp',
		);
	}

	public static function subject_fingerprint( $subject ) {
		return hash( 'sha256', self::SUBJECT_TYPE . "\0" . (string) $subject );
	}

	public static function resolve_bound_user_id( array $claims ) {
		if ( empty( $claims['sub'] ) || ! is_string( $claims['sub'] ) ) return self::auth_error( 'mad4b_oauth_subject_missing', 'Bearer subject binding failed.' );
		$fingerprint = self::subject_fingerprint( $claims['sub'] );
		$ids = get_users( array(
			'meta_key' => self::USER_META_KEY,
			'meta_value' => $fingerprint,
			'number' => 2,
			'fields' => 'ID',
			'orderby' => 'ID',
			'order' => 'ASC',
		) );
		if ( ! is_array( $ids ) || 1 !== count( $ids ) ) return self::auth_error( 'mad4b_oauth_subject_unbound', 'Bearer subject is not bound to exactly one WordPress user.' );
		$user_id = absint( $ids[0] );
		if ( $user_id <= 0 || ! user_can( $user_id, 'manage_options' ) ) return self::auth_error( 'mad4b_oauth_subject_capability_denied', 'Bearer subject is not bound to an authorized WordPress user.' );
		return $user_id;
	}

	private static function scope_list( $scope ) {
		$parts = preg_split( '/\s+/', trim( (string) $scope ) );
		return array_values( array_unique( array_filter( array_map( 'trim', is_array( $parts ) ? $parts : array() ) ) ) );
	}

	private static function scalar_subject_component( $value ) {
		if ( is_int( $value ) || is_string( $value ) ) {
			$value = trim( (string) $value );
			if ( '' !== $value && strlen( $value ) <= 128 && ! preg_match( '/[\s,]/', $value ) ) return $value;
		}
		return '';
	}

	private static function canonical_subject_from_claims( array $claims ) {
		$user = isset( $claims['user_id'] ) ? self::scalar_subject_component( $claims['user_id'] ) : '';
		if ( '' === $user ) return '';
		$tenant = isset( $claims['tenant_id'] ) && null !== $claims['tenant_id'] ? self::scalar_subject_component( $claims['tenant_id'] ) : '';
		if ( isset( $claims['tenant_id'] ) && null !== $claims['tenant_id'] && '' === $tenant ) return '';
		return '' !== $tenant ? 'tenant:' . $tenant . ':user:' . $user : 'user:' . $user;
	}

	private static function numeric_date( $value ) {
		if ( is_int( $value ) ) return $value;
		if ( is_string( $value ) && preg_match( '/^[0-9]{1,12}$/', $value ) ) return (int) $value;
		return null;
	}

	public static function verify_access_token( $token, $jwks = null, $now = null ) {
		$token = (string) $token;
		if ( '' === $token || strlen( $token ) > self::MAX_TOKEN_BYTES ) return self::auth_error( 'mad4b_oauth_token_malformed', 'Bearer token validation failed.' );
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) return self::auth_error( 'mad4b_oauth_token_malformed', 'Bearer token validation failed.' );
		$header_json = self::base64url_decode( $parts[0] );
		$payload_json = self::base64url_decode( $parts[1] );
		$signature = self::base64url_decode( $parts[2] );
		if ( null === $header_json || null === $payload_json || null === $signature ) return self::auth_error( 'mad4b_oauth_token_malformed', 'Bearer token validation failed.' );
		$header = json_decode( $header_json, true, 16, JSON_BIGINT_AS_STRING );
		$claims = json_decode( $payload_json, true, 32, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $header ) || ! is_array( $claims ) ) return self::auth_error( 'mad4b_oauth_token_malformed', 'Bearer token validation failed.' );
		if ( isset( $header['jku'] ) || isset( $header['x5u'] ) || isset( $header['x5c'] ) || isset( $header['crit'] ) ) return self::auth_error( 'mad4b_oauth_token_header_denied', 'Bearer token validation failed.' );
		if ( ! isset( $header['alg'], $header['kid'] ) || 'RS256' !== $header['alg'] || ! is_string( $header['kid'] ) || ! preg_match( '/^[A-Za-z0-9_-]{8,128}$/', $header['kid'] ) ) return self::auth_error( 'mad4b_oauth_token_header_invalid', 'Bearer token validation failed.' );

		if ( null === $jwks ) $jwks = self::load_jwks( $header['kid'] );
		if ( is_wp_error( $jwks ) ) return $jwks;
		$key = self::select_jwk( $jwks, $header['kid'] );
		if ( is_wp_error( $key ) ) return $key;
		$pem = self::rsa_jwk_to_pem( $key );
		if ( is_wp_error( $pem ) ) return $pem;
		$verified = openssl_verify( $parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $verified ) return self::auth_error( 'mad4b_oauth_signature_invalid', 'Bearer token validation failed.' );

		$now = null === $now ? time() : (int) $now;
		$exp = isset( $claims['exp'] ) ? self::numeric_date( $claims['exp'] ) : null;
		$iat = isset( $claims['iat'] ) ? self::numeric_date( $claims['iat'] ) : null;
		$nbf = isset( $claims['nbf'] ) ? self::numeric_date( $claims['nbf'] ) : null;
		if ( null === $exp || null === $iat ) return self::auth_error( 'mad4b_oauth_token_time_invalid', 'Bearer token validation failed.' );
		if ( $exp <= $now - self::CLOCK_SKEW || $iat > $now + self::CLOCK_SKEW || ( null !== $nbf && $nbf > $now + self::CLOCK_SKEW ) ) return self::auth_error( 'mad4b_oauth_token_expired_or_early', 'Bearer token validation failed.' );
		if ( $exp <= $iat || ( $exp - $iat ) > self::MAX_TOKEN_LIFETIME ) return self::auth_error( 'mad4b_oauth_token_lifetime_invalid', 'Bearer token validation failed.' );

		if ( ! isset( $claims['iss'] ) || self::ISSUER !== $claims['iss'] ) return self::auth_error( 'mad4b_oauth_issuer_invalid', 'Bearer token validation failed.' );
		if ( ! isset( $claims['aud'] ) || self::RESOURCE !== $claims['aud'] ) return self::auth_error( 'mad4b_oauth_audience_invalid', 'Bearer token validation failed.' );
		if ( ! isset( $claims['resource'] ) || self::RESOURCE !== $claims['resource'] ) return self::auth_error( 'mad4b_oauth_resource_invalid', 'Bearer token validation failed.' );
		if ( ! isset( $claims['purpose'] ) || self::TOKEN_PURPOSE !== $claims['purpose'] ) return self::auth_error( 'mad4b_oauth_purpose_invalid', 'Bearer token validation failed.' );
		if ( ! isset( $claims['jti'] ) || ! is_string( $claims['jti'] ) || '' === trim( $claims['jti'] ) || strlen( $claims['jti'] ) > 255 ) return self::auth_error( 'mad4b_oauth_jti_invalid', 'Bearer token validation failed.' );

		$scopes = isset( $claims['scope'] ) && is_string( $claims['scope'] ) ? self::scope_list( $claims['scope'] ) : array();
		$allowed_scopes = array( self::REQUIRED_SCOPE, self::OFFLINE_SCOPE );
		if ( ! in_array( self::REQUIRED_SCOPE, $scopes, true ) || array_diff( $scopes, $allowed_scopes ) ) return self::auth_error( 'mad4b_oauth_scope_invalid', 'Bearer token validation failed.' );
		$claims['scope'] = implode( ' ', $scopes );

		if ( ! isset( $claims['client_id'], $claims['azp'], $claims['client_profile_key'] ) || ! is_string( $claims['client_id'] ) || ! is_string( $claims['azp'] ) || ! is_string( $claims['client_profile_key'] ) ) return self::auth_error( 'mad4b_oauth_client_binding_invalid', 'Bearer token validation failed.' );
		if ( $claims['client_id'] !== $claims['azp'] || 0 !== strpos( $claims['client_id'], self::CLIENT_ID_PREFIX ) || 0 !== strpos( $claims['client_profile_key'], self::CLIENT_PROFILE_PREFIX ) ) return self::auth_error( 'mad4b_oauth_client_binding_invalid', 'Bearer token validation failed.' );
		if ( strlen( $claims['client_id'] ) > 160 || strlen( $claims['client_profile_key'] ) > 255 ) return self::auth_error( 'mad4b_oauth_client_binding_invalid', 'Bearer token validation failed.' );

		$canonical_sub = self::canonical_subject_from_claims( $claims );
		if ( '' === $canonical_sub || ! isset( $claims['sub'] ) || ! is_string( $claims['sub'] ) || $claims['sub'] !== $canonical_sub ) return self::auth_error( 'mad4b_oauth_subject_invalid', 'Bearer token validation failed.' );
		return $claims;
	}

	private static function load_jwks( $kid ) {
		$injected = apply_filters( 'mad4b_scp_wordpress_staging_oauth_jwks_document', null, self::JWKS_URI );
		if ( null !== $injected ) {
			$validated = self::validate_jwks( $injected );
			return is_wp_error( $validated ) ? $validated : $validated;
		}
		$cached = get_site_transient( self::JWKS_CACHE_KEY );
		$validated_cached = self::validate_jwks( $cached );
		if ( ! is_wp_error( $validated_cached ) && ! is_wp_error( self::select_jwk( $validated_cached, $kid ) ) ) return $validated_cached;

		$response = wp_safe_remote_get( self::JWKS_URI, array(
			'timeout' => 3,
			'redirection' => 0,
			'sslverify' => true,
			'headers' => array( 'Accept' => 'application/json' ),
			'user-agent' => 'MAD4B-Site-Control-Plane/' . ( defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : 'unknown' ),
		) );
		if ( is_wp_error( $response ) ) return self::auth_error( 'mad4b_oauth_jwks_unavailable', 'Bearer key verification is unavailable.' );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) return self::auth_error( 'mad4b_oauth_jwks_unavailable', 'Bearer key verification is unavailable.' );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > 65536 ) return self::auth_error( 'mad4b_oauth_jwks_invalid', 'Bearer key verification is unavailable.' );
		$decoded = json_decode( $body, true, 16 );
		$validated = self::validate_jwks( $decoded );
		if ( is_wp_error( $validated ) ) return $validated;
		set_site_transient( self::JWKS_CACHE_KEY, $validated, self::JWKS_CACHE_TTL );
		return $validated;
	}

	private static function validate_jwks( $jwks ) {
		if ( ! is_array( $jwks ) || ! isset( $jwks['keys'] ) || ! is_array( $jwks['keys'] ) || count( $jwks['keys'] ) < 1 || count( $jwks['keys'] ) > 10 ) return self::auth_error( 'mad4b_oauth_jwks_invalid', 'Bearer key verification is unavailable.' );
		$seen = array();
		$keys = array();
		foreach ( $jwks['keys'] as $key ) {
			if ( ! is_array( $key ) || ! isset( $key['kty'], $key['kid'], $key['n'], $key['e'] ) ) return self::auth_error( 'mad4b_oauth_jwks_invalid', 'Bearer key verification is unavailable.' );
			$kid = is_string( $key['kid'] ) ? $key['kid'] : '';
			if ( 'RSA' !== $key['kty'] || ! preg_match( '/^[A-Za-z0-9_-]{8,128}$/', $kid ) || isset( $seen[ $kid ] ) ) return self::auth_error( 'mad4b_oauth_jwks_invalid', 'Bearer key verification is unavailable.' );
			if ( isset( $key['alg'] ) && 'RS256' !== $key['alg'] ) return self::auth_error( 'mad4b_oauth_jwks_invalid', 'Bearer key verification is unavailable.' );
			if ( isset( $key['use'] ) && 'sig' !== $key['use'] ) return self::auth_error( 'mad4b_oauth_jwks_invalid', 'Bearer key verification is unavailable.' );
			if ( ! is_string( $key['n'] ) || ! is_string( $key['e'] ) || null === self::base64url_decode( $key['n'] ) || null === self::base64url_decode( $key['e'] ) ) return self::auth_error( 'mad4b_oauth_jwks_invalid', 'Bearer key verification is unavailable.' );
			$seen[ $kid ] = true;
			$keys[] = array( 'kty' => 'RSA', 'kid' => $kid, 'n' => $key['n'], 'e' => $key['e'], 'alg' => 'RS256', 'use' => 'sig' );
		}
		return array( 'keys' => $keys );
	}

	private static function select_jwk( array $jwks, $kid ) {
		$matches = array();
		foreach ( $jwks['keys'] as $key ) if ( isset( $key['kid'] ) && hash_equals( (string) $key['kid'], (string) $kid ) ) $matches[] = $key;
		if ( 1 !== count( $matches ) ) return self::auth_error( 'mad4b_oauth_kid_unknown', 'Bearer key verification failed.' );
		return $matches[0];
	}

	private static function base64url_decode( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) return null;
		$padding = strlen( $value ) % 4;
		if ( $padding ) $value .= str_repeat( '=', 4 - $padding );
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );
		return false === $decoded ? null : $decoded;
	}

	private static function der_length( $length ) {
		$length = (int) $length;
		if ( $length < 128 ) return chr( $length );
		$bytes = '';
		while ( $length > 0 ) {
			$bytes = chr( $length & 0xff ) . $bytes;
			$length >>= 8;
		}
		return chr( 0x80 | strlen( $bytes ) ) . $bytes;
	}

	private static function der_integer( $bytes ) {
		$bytes = ltrim( $bytes, "\x00" );
		if ( '' === $bytes ) $bytes = "\x00";
		if ( ord( $bytes[0] ) & 0x80 ) $bytes = "\x00" . $bytes;
		return "\x02" . self::der_length( strlen( $bytes ) ) . $bytes;
	}

	private static function der_sequence( $bytes ) {
		return "\x30" . self::der_length( strlen( $bytes ) ) . $bytes;
	}

	private static function rsa_jwk_to_pem( array $jwk ) {
		$n = self::base64url_decode( $jwk['n'] );
		$e = self::base64url_decode( $jwk['e'] );
		if ( null === $n || null === $e || strlen( $n ) < 256 || strlen( $n ) > 1024 || strlen( $e ) < 1 || strlen( $e ) > 8 ) return self::auth_error( 'mad4b_oauth_jwk_key_invalid', 'Bearer key verification failed.' );
		$rsa_public_key = self::der_sequence( self::der_integer( $n ) . self::der_integer( $e ) );
		$rsa_algorithm = hex2bin( '300d06092a864886f70d0101010500' );
		$subject_public_key = "\x03" . self::der_length( strlen( $rsa_public_key ) + 1 ) . "\x00" . $rsa_public_key;
		$spki = self::der_sequence( $rsa_algorithm . $subject_public_key );
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		$key = openssl_pkey_get_public( $pem );
		if ( false === $key ) return self::auth_error( 'mad4b_oauth_jwk_key_invalid', 'Bearer key verification failed.' );
		$details = openssl_pkey_get_details( $key );
		if ( ! is_array( $details ) || ! isset( $details['bits'] ) || (int) $details['bits'] < 2048 || ! isset( $details['type'] ) || OPENSSL_KEYTYPE_RSA !== $details['type'] ) return self::auth_error( 'mad4b_oauth_jwk_key_invalid', 'Bearer key verification failed.' );
		return $pem;
	}
}
