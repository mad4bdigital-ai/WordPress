<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * OAuth 2.1 resource-server bridge for the MAD4B read MCP surface.
 *
 * Supports local, external and explicitly enabled hybrid trust without turning
 * client identity into authority. Every bearer is selected by exact `iss`, then
 * verified against that authority's own discovery/JWKS contract. Subject policy
 * is enforced again after signature verification; the pre-cryptographic subject
 * gate remains deny-only defense in depth.
 */
final class MAD4B_SCP_OAuth_Resource_Bridge {
	const CONTRACT = 'mad4b.oauth-resource-bridge.v3';
	const READ_SCOPE = 'mad4b:read';
	const METADATA_NAMESPACE = 'mad4b/v1';
	const METADATA_ROUTE = '/oauth-protected-resource';
	const CLOCK_SKEW = 60;
	const MAX_TOKEN_BYTES = 16384;
	const MAX_URI_BYTES = 2048;
	const MAX_SUBJECT_BYTES = 512;
	const MAX_DISCOVERY_BYTES = 65536;
	const MAX_JWKS_BYTES = 262144;
	const MAX_JWKS_KEYS = 100;
	const MIN_RSA_BITS = 2048;
	const CACHE_TTL = 300;
	const JWKS_REFRESH_COOLDOWN = 30;

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
		$enabled = self::enabled();
		$production_approved = defined( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED' ) && true === MAD4B_MCP_OAUTH_PRODUCTION_APPROVED;
		$mode = self::authority_mode();
		$authorities = self::authority_registry();
		$issuers = array_keys( $authorities );
		$https = self::resource_is_https();
		$environment_allowed = ( 'staging' === $environment ) || ( 'production' === $environment && $production_approved );
		$registry_valid = self::authority_registry_valid( $mode, $authorities );
		$subject_policy_ready = $registry_valid && self::subject_policy_ready( $issuers );
		$wp_users_ready = $registry_valid && self::authority_users_ready( $issuers );
		$local_ready = true;
		foreach ( $authorities as $authority ) {
			if ( 'local' === $authority['type'] && empty( $authority['runtime_ready'] ) ) $local_ready = false;
		}
		$effective = $enabled && $registry_valid && $subject_policy_ready && $wp_users_ready && $local_ready && $https && $environment_allowed;

		$authority_status = array();
		foreach ( $authorities as $issuer => $authority ) {
			$authority_status[] = array(
				'type' => $authority['type'],
				'issuer' => $issuer,
				'wp_user_id' => self::configured_user_id( $issuer ),
				'allowed_subject_count' => count( self::allowed_subjects_for_issuer( $issuer ) ),
				'runtime_ready' => ! empty( $authority['runtime_ready'] ),
			);
		}

		return array(
			'contract' => self::CONTRACT,
			'configured' => $enabled,
			'effective' => (bool) $effective,
			'environment' => $environment,
			'production_approved' => $production_approved,
			'authority_mode' => $mode,
			'authority_count' => count( $authorities ),
			'authorities' => $authority_status,
			'authority_registry_valid' => $registry_valid,
			'subject_policy_ready' => $subject_policy_ready,
			'issuer' => self::primary_issuer(),
			'issuers' => $issuers,
			'issuer_configured' => ! empty( $issuers ),
			'resource' => self::resource_identifier(),
			'metadata_url' => self::metadata_url(),
			'authorization_server_metadata_urls' => self::authorization_server_metadata_urls(),
			'scopes_supported' => array( self::READ_SCOPE ),
			'wp_user_id' => self::configured_user_id( self::primary_issuer() ),
			'wp_user_capable' => $wp_users_ready,
			'https' => $https,
			'accepted_bearer_algorithms' => array( 'RS256' ),
			'jwks_x5c_required' => false,
			'jwks_rsa_ne_supported' => true,
			'jwks_minimum_rsa_bits' => self::MIN_RSA_BITS,
			'jwks_use_sig_enforced' => true,
			'jwks_key_ops_verify_enforced' => true,
			'jwks_refresh_cooldown_seconds' => self::JWKS_REFRESH_COOLDOWN,
			'jwks_cache_bound_to_issuer' => true,
			'jwt_resource_claim_required' => true,
			'bearer_request_resets_identity_before_verification' => true,
			'stores_bearer_tokens' => false,
			'creates_credentials' => false,
			'outbound_discovery_on_admin' => false,
			'write_surfaces_enabled' => false,
		);
	}

	public static function protected_resource_metadata() {
		return array(
			'resource' => self::resource_identifier(),
			'authorization_servers' => self::trusted_issuers(),
			'scopes_supported' => array( self::READ_SCOPE ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	public static function resource_identifier() { return untrailingslashit( rest_url( 'mcp/mad4b-read' ) ); }
	public static function metadata_url() { return untrailingslashit( rest_url( self::METADATA_NAMESPACE . self::METADATA_ROUTE ) ); }

	public static function authority_mode() {
		if ( defined( 'MAD4B_MCP_OAUTH_MODE' ) ) {
			$mode = sanitize_key( (string) constant( 'MAD4B_MCP_OAUTH_MODE' ) );
			if ( in_array( $mode, array( 'local', 'external', 'hybrid' ), true ) ) return $mode;
			return 'invalid';
		}
		$local = self::local_issuer();
		$raw = self::raw_configured_issuer();
		if ( '' !== $local && ( '' === $raw || hash_equals( $local, $raw ) ) ) return 'local';
		return 'external';
	}

	public static function trusted_issuers() { return array_keys( self::authority_registry() ); }

	public static function is_trusted_issuer( $issuer ) {
		if ( ! is_string( $issuer ) || '' === $issuer || strlen( $issuer ) > self::MAX_URI_BYTES ) return false;
		foreach ( self::trusted_issuers() as $trusted ) if ( hash_equals( $trusted, $issuer ) ) return true;
		return false;
	}

	public static function primary_issuer() {
		$mode = self::authority_mode();
		$registry = self::authority_registry();
		if ( 'external' === $mode || 'hybrid' === $mode ) foreach ( $registry as $issuer => $authority ) if ( 'external' === $authority['type'] ) return $issuer;
		foreach ( $registry as $issuer => $authority ) return $issuer;
		return '';
	}

	public static function allowed_subjects_for_issuer( $issuer ) {
		if ( ! self::is_trusted_issuer( $issuer ) ) return array();
		if ( defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' ) ) {
			$bindings = constant( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' );
			if ( is_array( $bindings ) ) {
				foreach ( $bindings as $bound_issuer => $subjects ) {
					if ( ! is_string( $bound_issuer ) ) continue;
					$bound_issuer = rtrim( trim( $bound_issuer ), '/' );
					if ( ! hash_equals( $issuer, $bound_issuer ) ) continue;
					return self::normalize_subjects( $subjects );
				}
			}
		}
		if ( 'hybrid' === self::authority_mode() || 1 !== count( self::authority_registry() ) ) return array();
		if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) return array();
		return self::normalize_subjects( constant( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) );
	}

	public static function subject_allowed( $issuer, $subject ) {
		if ( ! is_string( $subject ) || '' === $subject || strlen( $subject ) > self::MAX_SUBJECT_BYTES || preg_match( '/[\s,]/', $subject ) ) return false;
		foreach ( self::allowed_subjects_for_issuer( $issuer ) as $allowed ) if ( hash_equals( $allowed, $subject ) ) return true;
		return false;
	}

	public static function verified_bearer_active() {
		return is_array( self::$verified_context ) && ! empty( self::$verified_context['authenticated'] ) && 'oauth2_bearer' === (string) self::$verified_context['auth_method'];
	}

	/** Reset request-local OAuth authority before a bearer is evaluated. */
	public static function reset_verified_bearer_context( $bearer_request = false ) {
		self::$verified_context = null;
		if ( $bearer_request ) wp_set_current_user( 0 );
	}

	public static function authorization_server_metadata_urls( $issuer = '' ) {
		$issuer = '' === $issuer ? self::primary_issuer() : (string) $issuer;
		if ( '' === $issuer || ! self::is_trusted_issuer( $issuer ) ) return array();
		$parts = wp_parse_url( $issuer );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return array();
		$origin = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) $origin .= ':' . (int) $parts['port'];
		$issuer_path = isset( $parts['path'] ) ? '/' . ltrim( rtrim( (string) $parts['path'], '/' ), '/' ) : '';
		if ( '/' === $issuer_path ) $issuer_path = '';
		return array_values( array_unique( array_map( 'esc_url_raw', array(
			$issuer . '/.well-known/openid-configuration',
			$origin . '/.well-known/oauth-authorization-server' . $issuer_path,
			$issuer . '/.well-known/oauth-authorization-server',
		) ) ) );
	}

	public static function authenticate_rest_request( $result, $server, $request ) {
		if ( null !== $result ) return $result;
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return $result;
		$route = '/' . ltrim( rtrim( (string) $request->get_route(), '/' ), '/' );
		if ( '/mcp/mad4b-read' !== $route ) return $result;
		if ( method_exists( $request, 'get_method' ) && 'OPTIONS' === strtoupper( (string) $request->get_method() ) ) return $result;

		$status = self::status();
		if ( empty( $status['effective'] ) ) return self::unauthorized_response( 'mad4b_oauth_resource_bridge_not_effective', 'OAuth resource bridge is not effective for this environment.', 503 );
		$authorization = method_exists( $request, 'get_header' ) ? trim( (string) $request->get_header( 'authorization' ) ) : '';
		if ( '' === $authorization ) {
			self::reset_verified_bearer_context( false );
			if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) return $result;
			return self::unauthorized_response( 'mad4b_oauth_bearer_required', 'OAuth bearer token is required for remote MAD4B read transport.' );
		}
		self::reset_verified_bearer_context( true );

		$token = self::extract_bearer_token( $authorization );
		if ( is_wp_error( $token ) ) return self::unauthorized_response( $token->get_error_code(), $token->get_error_message() );
		$verified = self::verify_access_token( $token );
		unset( $token );
		if ( is_wp_error( $verified ) ) {
			$status_code = 'mad4b_oauth_subject_not_approved' === $verified->get_error_code() ? 403 : 401;
			return self::unauthorized_response( $verified->get_error_code(), $verified->get_error_message(), $status_code );
		}

		$user_id = self::configured_user_id( $verified['issuer'] );
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) return self::unauthorized_response( 'mad4b_oauth_wp_subject_invalid', 'Configured OAuth WordPress subject is missing required capability.', 403 );
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
		$response = new WP_REST_Response( array( 'error' => sanitize_key( (string) $code ), 'message' => sanitize_text_field( (string) $message ) ), (int) $status );
		$response->header( 'WWW-Authenticate', self::challenge_header() );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function extract_bearer_token( $authorization ) {
		if ( ! is_string( $authorization ) || strlen( $authorization ) > self::MAX_TOKEN_BYTES + 32 ) return new WP_Error( 'mad4b_oauth_authorization_header_invalid', 'Authorization header is invalid.' );
		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', trim( $authorization ), $matches ) ) return new WP_Error( 'mad4b_oauth_bearer_malformed', 'Authorization header must contain a Bearer token.' );
		$token = (string) $matches[1];
		if ( '' === $token || strlen( $token ) > self::MAX_TOKEN_BYTES ) return new WP_Error( 'mad4b_oauth_bearer_size_invalid', 'Bearer token length is invalid.' );
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

		$issuer = isset( $claims['iss'] ) && is_string( $claims['iss'] ) ? $claims['iss'] : '';
		if ( '' === $issuer || strlen( $issuer ) > self::MAX_URI_BYTES || ! self::is_trusted_issuer( $issuer ) ) return new WP_Error( 'mad4b_oauth_issuer_untrusted', 'Access token issuer is not a configured trusted authority.' );
		$discovery = self::authorization_server_metadata( $issuer );
		if ( is_wp_error( $discovery ) ) return $discovery;
		$jwks = self::jwks( $discovery['jwks_uri'], $issuer, false );
		if ( is_wp_error( $jwks ) ) return $jwks;
		$key = self::matching_jwk( $jwks, $kid );
		if ( is_wp_error( $key ) && 'mad4b_oauth_jwk_not_found' === $key->get_error_code() && self::claim_jwks_refresh_slot( $issuer, $discovery['jwks_uri'] ) ) {
			$jwks = self::jwks( $discovery['jwks_uri'], $issuer, true );
			if ( is_wp_error( $jwks ) ) return $jwks;
			$key = self::matching_jwk( $jwks, $kid );
		}
		if ( is_wp_error( $key ) ) return $key;
		$public_key = self::public_key_from_jwk( $key );
		if ( is_wp_error( $public_key ) ) return $public_key;
		$verified = openssl_verify( $parts[0] . '.' . $parts[1], $signature, $public_key, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $verified ) return new WP_Error( 'mad4b_oauth_jwt_signature_invalid', 'JWT signature verification failed.' );
		$validated = self::validate_claims( $claims, $issuer );
		if ( is_wp_error( $validated ) ) return $validated;
		if ( ! self::subject_allowed( $issuer, $validated['subject'] ) ) return new WP_Error( 'mad4b_oauth_subject_not_approved', 'OAuth subject is not approved for this issuer and protected resource.' );
		return $validated;
	}

	private static function validate_claims( array $claims, $issuer ) {
		if ( ! isset( $claims['iss'] ) || ! is_string( $claims['iss'] ) || ! hash_equals( $issuer, $claims['iss'] ) ) return new WP_Error( 'mad4b_oauth_issuer_mismatch', 'Access token issuer does not match selected authority.' );
		$subject = isset( $claims['sub'] ) && is_string( $claims['sub'] ) ? trim( $claims['sub'] ) : '';
		if ( '' === $subject || strlen( $subject ) > self::MAX_SUBJECT_BYTES ) return new WP_Error( 'mad4b_oauth_subject_missing', 'Access token subject is missing.' );
		$resource = self::resource_identifier();
		if ( ! self::audience_contains( isset( $claims['aud'] ) ? $claims['aud'] : null, $resource ) ) return new WP_Error( 'mad4b_oauth_audience_mismatch', 'Access token audience does not match the MAD4B read resource.' );
		if ( ! isset( $claims['resource'] ) || ! is_string( $claims['resource'] ) || ! hash_equals( $resource, untrailingslashit( trim( $claims['resource'] ) ) ) ) return new WP_Error( 'mad4b_oauth_resource_mismatch', 'Access token resource claim must exactly match the MAD4B read resource.' );
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
			if ( class_exists( 'MAD4B_SCP_Identity_Context' ) && count( $scopes ) > MAD4B_SCP_Identity_Context::MAX_SCOPES ) return new WP_Error( 'mad4b_oauth_scope_count_invalid', 'Access token contains too many scopes.' );
			if ( count( $scopes ) > 200 ) return new WP_Error( 'mad4b_oauth_scope_count_invalid', 'Access token contains too many scopes.' );
		}
		return array_values( array_unique( $scopes ) );
	}

	private static function audience_contains( $audience, $expected ) {
		if ( is_string( $audience ) ) return strlen( $audience ) <= self::MAX_URI_BYTES && hash_equals( $expected, $audience );
		if ( ! is_array( $audience ) || count( $audience ) > 20 ) return false;
		foreach ( $audience as $candidate ) if ( is_string( $candidate ) && strlen( $candidate ) <= self::MAX_URI_BYTES && hash_equals( $expected, $candidate ) ) return true;
		return false;
	}

	private static function authorization_server_metadata( $issuer ) {
		if ( ! self::is_trusted_issuer( $issuer ) ) return new WP_Error( 'mad4b_oauth_issuer_unconfigured', 'OAuth issuer is not configured as a trusted authority.' );
		$key = 'mad4b_oauth_discovery_' . substr( hash( 'sha256', $issuer ), 0, 32 );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) return $cached;
		foreach ( self::authorization_server_metadata_urls( $issuer ) as $url ) {
			$response = wp_safe_remote_get( $url, array( 'timeout' => 5, 'redirection' => 0, 'headers' => array( 'Accept' => 'application/json' ) ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) continue;
			$body = (string) wp_remote_retrieve_body( $response );
			if ( '' === $body || strlen( $body ) > self::MAX_DISCOVERY_BYTES ) continue;
			$metadata = json_decode( $body, true );
			if ( ! is_array( $metadata ) || ! isset( $metadata['issuer'] ) || ! is_string( $metadata['issuer'] ) || ! hash_equals( $issuer, $metadata['issuer'] ) ) continue;
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

	private static function jwks( $jwks_uri, $issuer, $force_refresh = false ) {
		if ( ! self::same_origin_https_url( $jwks_uri, $issuer ) ) return new WP_Error( 'mad4b_oauth_jwks_origin_invalid', 'OAuth JWKS URI must remain on the selected issuer origin.' );
		$key = 'mad4b_oauth_jwks_' . substr( hash( 'sha256', $issuer . "\0" . (string) $jwks_uri ), 0, 32 );
		if ( $force_refresh ) delete_transient( $key );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) return $cached;
		$response = wp_safe_remote_get( $jwks_uri, array( 'timeout' => 5, 'redirection' => 0, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) return new WP_Error( 'mad4b_oauth_jwks_unavailable', 'OAuth JWKS endpoint is unavailable.' );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > self::MAX_JWKS_BYTES ) return new WP_Error( 'mad4b_oauth_jwks_invalid', 'OAuth JWKS payload is empty or exceeds its size bound.' );
		$jwks = json_decode( $body, true );
		if ( ! is_array( $jwks ) || empty( $jwks['keys'] ) || ! is_array( $jwks['keys'] ) || count( $jwks['keys'] ) > self::MAX_JWKS_KEYS ) return new WP_Error( 'mad4b_oauth_jwks_invalid', 'OAuth JWKS payload is invalid.' );
		$bounded = array( 'keys' => array_slice( $jwks['keys'], 0, self::MAX_JWKS_KEYS ) );
		set_transient( $key, $bounded, self::CACHE_TTL );
		return $bounded;
	}

	private static function claim_jwks_refresh_slot( $issuer, $jwks_uri ) {
		$key = 'mad4b_oauth_jwks_refresh_' . substr( hash( 'sha256', $issuer . "\0" . (string) $jwks_uri ), 0, 32 );
		if ( false !== get_transient( $key ) ) return false;
		set_transient( $key, 1, self::JWKS_REFRESH_COOLDOWN );
		return true;
	}

	private static function matching_jwk( array $jwks, $kid ) {
		$matches = array();
		foreach ( isset( $jwks['keys'] ) && is_array( $jwks['keys'] ) ? $jwks['keys'] : array() as $key ) {
			if ( ! is_array( $key ) || ! isset( $key['kid'] ) || ! is_string( $key['kid'] ) || ! hash_equals( $kid, $key['kid'] ) ) continue;
			$matches[] = $key;
		}
		if ( 0 === count( $matches ) ) return new WP_Error( 'mad4b_oauth_jwk_not_found', 'JWT signing key was not found for kid.' );
		if ( 1 !== count( $matches ) ) return new WP_Error( 'mad4b_oauth_jwk_kid_ambiguous', 'JWKS contains more than one signing key for the requested kid.' );
		$key = $matches[0];
		if ( ! isset( $key['kty'] ) || 'RSA' !== $key['kty'] ) return new WP_Error( 'mad4b_oauth_jwk_type_invalid', 'JWT signing key must be RSA.' );
		if ( isset( $key['alg'] ) && ( ! is_string( $key['alg'] ) || 'RS256' !== $key['alg'] ) ) return new WP_Error( 'mad4b_oauth_jwk_alg_invalid', 'JWT signing key must use RS256.' );
		if ( isset( $key['use'] ) && ( ! is_string( $key['use'] ) || 'sig' !== $key['use'] ) ) return new WP_Error( 'mad4b_oauth_jwk_use_invalid', 'JWT key use must be sig when declared.' );
		if ( isset( $key['key_ops'] ) ) {
			if ( ! is_array( $key['key_ops'] ) ) return new WP_Error( 'mad4b_oauth_jwk_key_ops_invalid', 'JWT key_ops must be an array when declared.' );
			$ops = array();
			foreach ( $key['key_ops'] as $op ) {
				if ( ! is_string( $op ) || strlen( $op ) > 32 ) return new WP_Error( 'mad4b_oauth_jwk_key_ops_invalid', 'JWT key_ops contains an invalid operation.' );
				$ops[] = $op;
			}
			if ( ! in_array( 'verify', $ops, true ) ) return new WP_Error( 'mad4b_oauth_jwk_key_ops_invalid', 'JWT signing key must allow verify when key_ops is declared.' );
		}
		return $key;
	}

	private static function public_key_from_jwk( array $key ) {
		if ( isset( $key['x5c'] ) && is_array( $key['x5c'] ) && ! empty( $key['x5c'][0] ) && is_string( $key['x5c'][0] ) ) {
			$x5c = preg_replace( '/\s+/', '', $key['x5c'][0] );
			if ( ! is_string( $x5c ) || '' === $x5c || strlen( $x5c ) > 32768 || ! preg_match( '/^[A-Za-z0-9+\/=]+$/', $x5c ) ) return new WP_Error( 'mad4b_oauth_jwk_material_invalid', 'RSA x5c certificate material is invalid.' );
			$decoded = base64_decode( $x5c, true );
			if ( false === $decoded || strlen( $decoded ) > 24576 ) return new WP_Error( 'mad4b_oauth_jwk_material_invalid', 'RSA x5c certificate material is invalid.' );
			$pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split( $x5c, 64, "\n" ) . "-----END CERTIFICATE-----\n";
			return self::validated_rsa_public_key( $pem );
		}
		if ( empty( $key['n'] ) || empty( $key['e'] ) || ! is_string( $key['n'] ) || ! is_string( $key['e'] ) ) return new WP_Error( 'mad4b_oauth_jwk_material_missing', 'RSA JWKS signing key must provide x5c or n/e public-key material.' );
		$modulus = self::base64url_decode( $key['n'] );
		$exponent = self::base64url_decode( $key['e'] );
		if ( false === $modulus || false === $exponent || '' === $modulus || '' === $exponent ) return new WP_Error( 'mad4b_oauth_jwk_material_invalid', 'RSA JWK n/e material is invalid.' );
		if ( self::rsa_modulus_bits( $modulus ) < self::MIN_RSA_BITS ) return new WP_Error( 'mad4b_oauth_jwk_rsa_too_small', 'RSA JWKS signing key must be at least 2048 bits.' );
		$exponent_value = self::rsa_exponent_value( $exponent );
		if ( is_wp_error( $exponent_value ) ) return $exponent_value;
		$rsa = self::asn1_sequence( self::asn1_integer( $modulus ) . self::asn1_integer( $exponent ) );
		$rsa_algorithm = hex2bin( '300d06092a864886f70d0101010500' );
		if ( false === $rsa_algorithm ) return new WP_Error( 'mad4b_oauth_jwk_material_invalid', 'RSA algorithm identifier is unavailable.' );
		$subject_public_key = self::asn1_sequence( $rsa_algorithm . "\x03" . self::asn1_length( strlen( $rsa ) + 1 ) . "\x00" . $rsa );
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $subject_public_key ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		return self::validated_rsa_public_key( $pem );
	}

	private static function validated_rsa_public_key( $pem ) {
		$public = openssl_pkey_get_public( $pem );
		if ( false === $public ) return new WP_Error( 'mad4b_oauth_jwk_material_invalid', 'Unable to parse RSA public-key material.' );
		$details = openssl_pkey_get_details( $public );
		if ( ! is_array( $details ) || ! isset( $details['type'], $details['bits'], $details['key'] ) || OPENSSL_KEYTYPE_RSA !== $details['type'] ) return new WP_Error( 'mad4b_oauth_jwk_type_invalid', 'JWT signing key must resolve to RSA public-key material.' );
		if ( (int) $details['bits'] < self::MIN_RSA_BITS ) return new WP_Error( 'mad4b_oauth_jwk_rsa_too_small', 'RSA JWKS signing key must be at least 2048 bits.' );
		return (string) $details['key'];
	}

	private static function rsa_modulus_bits( $modulus ) {
		$modulus = ltrim( (string) $modulus, "\x00" );
		if ( '' === $modulus ) return 0;
		$first = ord( $modulus[0] );
		$leading = 0;
		for ( $mask = 0x80; $mask > 0 && 0 === ( $first & $mask ); $mask >>= 1 ) ++$leading;
		return strlen( $modulus ) * 8 - $leading;
	}

	private static function rsa_exponent_value( $exponent ) {
		$exponent = ltrim( (string) $exponent, "\x00" );
		if ( '' === $exponent || strlen( $exponent ) > 4 ) return new WP_Error( 'mad4b_oauth_jwk_exponent_invalid', 'RSA public exponent is invalid.' );
		$value = 0;
		for ( $i = 0, $len = strlen( $exponent ); $i < $len; ++$i ) $value = ( $value << 8 ) | ord( $exponent[ $i ] );
		if ( $value < 3 || 0 === ( $value & 1 ) ) return new WP_Error( 'mad4b_oauth_jwk_exponent_invalid', 'RSA public exponent must be an odd integer of at least 3.' );
		return $value;
	}

	private static function asn1_integer( $bytes ) {
		$bytes = ltrim( (string) $bytes, "\x00" );
		if ( '' === $bytes ) $bytes = "\x00";
		if ( ord( $bytes[0] ) & 0x80 ) $bytes = "\x00" . $bytes;
		return "\x02" . self::asn1_length( strlen( $bytes ) ) . $bytes;
	}
	private static function asn1_sequence( $bytes ) { return "\x30" . self::asn1_length( strlen( $bytes ) ) . $bytes; }
	private static function asn1_length( $length ) {
		$length = (int) $length;
		if ( $length < 128 ) return chr( $length );
		$encoded = '';
		while ( $length > 0 ) { $encoded = chr( $length & 0xff ) . $encoded; $length >>= 8; }
		return chr( 0x80 | strlen( $encoded ) ) . $encoded;
	}

	private static function enabled() { return defined( 'MAD4B_MCP_OAUTH_ENABLED' ) && true === MAD4B_MCP_OAUTH_ENABLED; }

	private static function authority_registry() {
		$mode = self::authority_mode();
		$registry = array();
		$local = self::local_issuer();
		$raw = self::raw_configured_issuer();
		if ( in_array( $mode, array( 'local', 'hybrid' ), true ) && '' !== $local ) {
			$local_ready = true;
			if ( class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) && method_exists( 'MAD4B_SCP_Local_OAuth_Server', 'status' ) ) {
				$local_status = MAD4B_SCP_Local_OAuth_Server::status();
				$local_ready = is_array( $local_status ) && ! empty( $local_status['effective'] );
			}
			$registry[ $local ] = array( 'type' => 'local', 'runtime_ready' => $local_ready );
		}
		if ( in_array( $mode, array( 'external', 'hybrid' ), true ) && '' !== $raw && ( '' === $local || ! hash_equals( $local, $raw ) ) ) $registry[ $raw ] = array( 'type' => 'external', 'runtime_ready' => true );
		return $registry;
	}

	private static function authority_registry_valid( $mode, array $registry ) {
		if ( ! in_array( $mode, array( 'local', 'external', 'hybrid' ), true ) ) return false;
		$types = array();
		foreach ( $registry as $authority ) if ( isset( $authority['type'] ) ) $types[] = $authority['type'];
		if ( 'local' === $mode ) return 1 === count( $registry ) && in_array( 'local', $types, true );
		if ( 'external' === $mode ) return 1 === count( $registry ) && in_array( 'external', $types, true );
		return 2 === count( $registry ) && in_array( 'local', $types, true ) && in_array( 'external', $types, true );
	}

	private static function subject_policy_ready( array $issuers ) {
		if ( empty( $issuers ) ) return false;
		foreach ( $issuers as $issuer ) if ( empty( self::allowed_subjects_for_issuer( $issuer ) ) ) return false;
		return true;
	}

	private static function authority_users_ready( array $issuers ) {
		if ( empty( $issuers ) ) return false;
		foreach ( $issuers as $issuer ) {
			$user_id = self::configured_user_id( $issuer );
			$user = $user_id > 0 ? get_userdata( $user_id ) : false;
			if ( ! $user || ! user_can( $user, 'manage_options' ) ) return false;
		}
		return true;
	}

	private static function configured_user_id( $issuer = '' ) {
		if ( '' !== $issuer && defined( 'MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER' ) ) {
			$mapping = constant( 'MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER' );
			if ( is_array( $mapping ) ) foreach ( $mapping as $bound_issuer => $user_id ) if ( is_string( $bound_issuer ) && hash_equals( $issuer, rtrim( trim( $bound_issuer ), '/' ) ) ) return absint( $user_id );
		}
		return defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ? absint( constant( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) : 0;
	}

	private static function raw_configured_issuer() {
		if ( ! defined( 'MAD4B_MCP_OAUTH_ISSUER' ) ) return '';
		$issuer = rtrim( trim( (string) constant( 'MAD4B_MCP_OAUTH_ISSUER' ) ), '/' );
		if ( '' === $issuer || strlen( $issuer ) > self::MAX_URI_BYTES || ! self::valid_https_url( $issuer ) ) return '';
		return $issuer;
	}

	private static function local_issuer() {
		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) || true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) return '';
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) || ! method_exists( 'MAD4B_SCP_Local_OAuth_Server', 'issuer' ) ) return '';
		$issuer = rtrim( (string) MAD4B_SCP_Local_OAuth_Server::issuer(), '/' );
		return self::valid_https_url( $issuer ) ? $issuer : '';
	}

	private static function normalize_subjects( $value ) {
		$items = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		$subjects = array();
		foreach ( is_array( $items ) ? array_slice( $items, 0, 500 ) : array() as $item ) {
			if ( ! is_string( $item ) ) continue;
			$item = trim( $item );
			if ( '' === $item || strlen( $item ) > self::MAX_SUBJECT_BYTES || preg_match( '/[\s,]/', $item ) ) continue;
			if ( 0 !== strpos( $item, 'user:' ) && 0 !== strpos( $item, 'tenant:' ) ) continue;
			$subjects[] = $item;
		}
		return array_values( array_unique( $subjects ) );
	}

	private static function resource_is_https() { return 'https' === strtolower( (string) wp_parse_url( self::resource_identifier(), PHP_URL_SCHEME ) ); }
	private static function valid_https_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > self::MAX_URI_BYTES ) return false;
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && 'https' === strtolower( (string) $parts['scheme'] ) && '' !== (string) $parts['host'] && empty( $parts['user'] ) && empty( $parts['pass'] ) && empty( $parts['query'] ) && empty( $parts['fragment'] );
	}
	private static function same_origin_https_url( $url, $issuer ) {
		if ( ! self::valid_https_url( $url ) || ! self::valid_https_url( $issuer ) ) return false;
		$url_parts = wp_parse_url( (string) $url );
		$issuer_parts = wp_parse_url( (string) $issuer );
		$url_port = isset( $url_parts['port'] ) ? (int) $url_parts['port'] : 443;
		$issuer_port = isset( $issuer_parts['port'] ) ? (int) $issuer_parts['port'] : 443;
		return strtolower( (string) $url_parts['host'] ) === strtolower( (string) $issuer_parts['host'] ) && $url_port === $issuer_port;
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
