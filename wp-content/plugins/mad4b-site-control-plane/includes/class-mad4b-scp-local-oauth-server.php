<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Standalone WordPress OAuth 2.1 authorization server for the MAD4B MCP read resource.
 *
 * This authority is intentionally isolated from Growth OS. It owns a site-local
 * RS256 signing key, uses the existing WordPress login/session for user
 * authentication, requires explicit consent, binds authorization codes to PKCE
 * S256 + client + redirect URI + resource, rotates opaque refresh tokens, and
 * publishes only public metadata/JWKS.
 *
 * Client registration supports exact pre-registered clients plus fail-closed
 * Client ID Metadata Documents (CIMD). The initial CIMD policy is deliberately
 * narrow: only ChatGPT's stable HTTPS client metadata URL is accepted. DCR is
 * not exposed.
 */
final class MAD4B_SCP_Local_OAuth_Server {
	const CONTRACT = 'mad4b.local-oauth-server.v3';
	const ISSUER_PATH = '/oauth/mcp';
	const AUTHORIZE_PATH = '/oauth/mcp/authorize';
	const TOKEN_PATH = '/oauth/mcp/token';
	const JWKS_PATH = '/oauth/mcp/jwks';
	const REVOCATION_PATH = '/oauth/mcp/revoke';
	const CHATGPT_CIMD_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
	const CODE_TTL = 300;
	const ACCESS_TOKEN_TTL = 600;
	const REFRESH_TOKEN_TTL = 2592000;
	const CLOCK_SKEW = 60;
	const MAX_CLIENTS = 50;
	const MAX_REDIRECTS_PER_CLIENT = 20;
	const MAX_CLIENT_ID_BYTES = 191;
	const MAX_URI_BYTES = 2048;
	const MAX_TOKEN_INPUT_BYTES = 2048;
	const MAX_SCOPE_BYTES = 1024;
	const MAX_STATE_BYTES = 1024;
	const MAX_CIMD_BYTES = 65536;
	const CIMD_CACHE_TTL = 300;

	private static $booted = false;
	private static $runtime_error = null;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		self::bind_resource_bridge_defaults();
		add_action( 'init', array( __CLASS__, 'ensure_runtime' ), 1 );
		add_action( 'parse_request', array( __CLASS__, 'serve_protocol_paths' ), -10 );
		add_filter( 'pre_http_request', array( __CLASS__, 'intercept_local_discovery' ), 1, 3 );
	}

	public static function ensure_runtime() {
		if ( ! self::enabled() || ! self::environment_allowed() ) return;
		$issuer = self::configured_issuer_validation();
		if ( is_wp_error( $issuer ) ) {
			self::$runtime_error = $issuer;
			return;
		}
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Store' ) ) {
			self::$runtime_error = new WP_Error( 'mad4b_local_oauth_store_missing', 'Local OAuth store class is unavailable.' );
			return;
		}
		if ( self::MAX_CLIENT_ID_BYTES !== MAD4B_SCP_Local_OAuth_Store::MAX_CLIENT_ID_BYTES ) {
			self::$runtime_error = new WP_Error( 'mad4b_local_oauth_client_id_schema_mismatch', 'Local OAuth client-id bounds do not match the durable schema.' );
			return;
		}
		if ( ! MAD4B_SCP_Local_OAuth_Store::is_ready() || (int) get_option( MAD4B_SCP_Local_OAuth_Store::OPTION, 0 ) < MAD4B_SCP_Local_OAuth_Store::VERSION ) {
			$schema = MAD4B_SCP_Local_OAuth_Store::install_or_upgrade();
			if ( is_wp_error( $schema ) ) {
				self::$runtime_error = $schema;
				return;
			}
		}
		$key = self::ensure_signing_key();
		if ( is_wp_error( $key ) ) self::$runtime_error = $key;
	}

	public static function status() {
		$key = self::public_jwk();
		$key_ready = ! is_wp_error( $key );
		$store_ready = class_exists( 'MAD4B_SCP_Local_OAuth_Store' ) && MAD4B_SCP_Local_OAuth_Store::is_ready();
		$clients = self::clients();
		$issuer_validation = self::configured_issuer_validation();
		$issuer_valid = ! is_wp_error( $issuer_validation );
		$https = 'https' === strtolower( (string) wp_parse_url( self::issuer(), PHP_URL_SCHEME ) );
		$client_policy_ready = ! empty( $clients ) || self::cimd_supported();
		$effective = self::enabled() && self::environment_allowed() && $https && $issuer_valid && $store_ready && $key_ready && $client_policy_ready && ! is_wp_error( self::$runtime_error );
		return array(
			'contract' => self::CONTRACT,
			'configured' => self::enabled(),
			'effective' => (bool) $effective,
			'environment' => function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown',
			'production_approved' => self::production_approved(),
			'issuer' => self::issuer(),
			'issuer_same_origin_required' => true,
			'issuer_configuration_valid' => $issuer_valid,
			'authorization_endpoint' => self::authorize_url(),
			'token_endpoint' => self::token_url(),
			'jwks_uri' => self::jwks_url(),
			'revocation_endpoint' => self::revocation_url(),
			'authorization_server_metadata' => self::metadata_url(),
			'protected_resources' => array( self::resource_identifier() ),
			'client_registration_mode' => 'cimd_or_pre_registered',
			'client_id_metadata_document_supported' => true,
			'dynamic_client_registration_supported' => false,
			'client_count' => count( $clients ),
			'cimd_policy_client_count' => 1,
			'cimd_chatgpt_client_id' => self::CHATGPT_CIMD_CLIENT_ID,
			'cimd_cache_max_ttl_seconds' => self::CIMD_CACHE_TTL,
			'cimd_fetch_on_admin_status' => false,
			'max_client_id_bytes' => self::MAX_CLIENT_ID_BYTES,
			'max_uri_bytes' => self::MAX_URI_BYTES,
			'pkce_methods_supported' => array( 'S256' ),
			'authorization_response_iss_parameter_supported' => true,
			'access_token_signing_alg' => 'RS256',
			'consent_clickjacking_protected' => true,
			'private_key_present' => $key_ready,
			'private_key_exposed' => false,
			'private_key_stored_in_database' => false,
			'key_id' => $key_ready && isset( $key['kid'] ) ? $key['kid'] : '',
			'oauth_store_ready' => $store_ready,
			'runtime_error' => is_wp_error( self::$runtime_error ) ? self::$runtime_error->get_error_code() : ( is_wp_error( $issuer_validation ) ? $issuer_validation->get_error_code() : '' ),
		);
	}

	public static function metadata() {
		return array(
			'issuer' => self::issuer(),
			'authorization_endpoint' => self::authorize_url(),
			'token_endpoint' => self::token_url(),
			'jwks_uri' => self::jwks_url(),
			'revocation_endpoint' => self::revocation_url(),
			'response_types_supported' => array( 'code' ),
			'grant_types_supported' => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'code_challenge_methods_supported' => array( 'S256' ),
			'scopes_supported' => array( 'mad4b:read', 'offline_access' ),
			'authorization_response_iss_parameter_supported' => true,
			'protected_resources' => array( self::resource_identifier() ),
			'client_id_metadata_document_supported' => true,
		);
	}

	public static function jwks_document() {
		$key = self::public_jwk();
		return is_wp_error( $key ) ? $key : array( 'keys' => array( $key ) );
	}

	public static function issuer() {
		$validated = self::configured_issuer_validation();
		if ( is_string( $validated ) && '' !== $validated ) return $validated;
		return self::origin() . self::ISSUER_PATH;
	}

	public static function metadata_url() {
		$parts = wp_parse_url( self::issuer() );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) return self::origin() . '/.well-known/oauth-authorization-server';
		return self::origin() . '/.well-known/oauth-authorization-server/' . ltrim( rtrim( (string) $parts['path'], '/' ), '/' );
	}

	public static function authorize_url() { return self::origin() . self::AUTHORIZE_PATH; }
	public static function token_url() { return self::origin() . self::TOKEN_PATH; }
	public static function jwks_url() { return self::origin() . self::JWKS_PATH; }
	public static function revocation_url() { return self::origin() . self::REVOCATION_PATH; }

	public static function resource_identifier() {
		if ( class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier();
		return untrailingslashit( rest_url( 'mcp/mad4b-chatgpt' ) );
	}

	public static function intercept_local_discovery( $preempt, $args, $url ) {
		if ( ! self::enabled() || ! self::effective_for_protocol() ) return $preempt;
		$url = untrailingslashit( (string) $url );
		if ( hash_equals( untrailingslashit( self::metadata_url() ), $url ) ) return self::http_json_response( self::metadata() );
		if ( hash_equals( untrailingslashit( self::jwks_url() ), $url ) ) {
			$jwks = self::jwks_document();
			if ( is_wp_error( $jwks ) ) return $preempt;
			return self::http_json_response( $jwks );
		}
		return $preempt;
	}

	public static function serve_protocol_paths() {
		if ( ! self::enabled() ) return;
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) return;
		$path = rtrim( '/' . ltrim( $path, '/' ), '/' );
		$metadata_path = (string) wp_parse_url( self::metadata_url(), PHP_URL_PATH );
		$known = in_array( $path, array( rtrim( $metadata_path, '/' ), self::JWKS_PATH, self::AUTHORIZE_PATH, self::TOKEN_PATH, self::REVOCATION_PATH ), true );
		if ( $known && ! self::effective_for_protocol() ) self::send_oauth_error( 'temporarily_unavailable', 'Local OAuth authority is not effective.', 503 );
		if ( rtrim( $metadata_path, '/' ) === $path ) self::send_json( self::metadata(), 200 );
		if ( self::JWKS_PATH === $path ) {
			$jwks = self::jwks_document();
			if ( is_wp_error( $jwks ) ) self::send_oauth_error( 'server_error', 'Local signing key is unavailable.', 503 );
			self::send_json( $jwks, 200 );
		}
		if ( self::AUTHORIZE_PATH === $path ) self::handle_authorize();
		if ( self::TOKEN_PATH === $path ) self::handle_token();
		if ( self::REVOCATION_PATH === $path ) self::handle_revocation();
	}

	private static function handle_authorize() {
		if ( ! self::effective_for_protocol() ) self::send_oauth_error( 'temporarily_unavailable', 'Local OAuth authority is not effective.', 503 );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$params = 'POST' === $method ? wp_unslash( $_POST ) : wp_unslash( $_GET );
		$validated = self::validate_authorization_request( is_array( $params ) ? $params : array() );
		if ( is_wp_error( $validated ) ) self::authorization_request_error( $validated, is_array( $params ) ? $params : array() );

		if ( ! is_user_logged_in() ) {
			$current = self::authorize_url() . ( empty( $_SERVER['QUERY_STRING'] ) ? '' : '?' . (string) wp_unslash( $_SERVER['QUERY_STRING'] ) );
			wp_safe_redirect( wp_login_url( $current ) );
			exit;
		}

		$user_id = get_current_user_id();
		if ( ! self::user_authorized( $user_id ) ) self::redirect_authorization_error( $validated, 'access_denied' );
		if ( 'POST' !== $method ) self::render_consent( $validated, $user_id );

		$nonce = self::request_param( $params, '_mad4b_oauth_nonce', 256 );
		if ( is_wp_error( $nonce ) || ! wp_verify_nonce( $nonce, 'mad4b_local_oauth_consent' ) ) self::send_oauth_error( 'invalid_request', 'Consent request expired.', 400 );
		$decision = self::request_param( $params, 'decision', 32 );
		$decision = is_wp_error( $decision ) ? 'deny' : sanitize_key( $decision );
		if ( 'approve' !== $decision ) self::redirect_authorization_error( $validated, 'access_denied' );

		$code = self::random_token( 32 );
		if ( is_wp_error( $code ) ) self::send_oauth_error( 'server_error', 'Unable to create authorization code.', 500 );
		$stored = MAD4B_SCP_Local_OAuth_Store::insert_code(
			array(
				'code_hash' => hash( 'sha256', $code ),
				'client_id' => $validated['client_id'],
				'wp_user_id' => $user_id,
				'redirect_uri' => $validated['redirect_uri'],
				'resource' => $validated['resource'],
				'scope' => implode( ' ', $validated['scopes'] ),
				'code_challenge' => $validated['code_challenge'],
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( ! $stored ) self::send_oauth_error( 'server_error', 'Unable to persist authorization code.', 500 );
		$location = add_query_arg(
			array_filter(
				array(
					'code' => $code,
					'state' => $validated['state'],
					'iss' => self::issuer(),
				),
				static function ( $value ) { return '' !== $value; }
			),
			$validated['redirect_uri']
		);
		self::trusted_client_redirect( $location );
	}

	private static function validate_authorization_request( array $params ) {
		$client_id = self::request_param( $params, 'client_id', self::MAX_CLIENT_ID_BYTES );
		if ( is_wp_error( $client_id ) ) return $client_id;
		$client = self::client( $client_id );
		if ( is_wp_error( $client ) ) return $client;
		if ( ! is_array( $client ) ) return new WP_Error( 'invalid_client', 'OAuth client is neither pre-registered nor allowed by CIMD policy.' );
		$redirect_uri = self::request_param( $params, 'redirect_uri', self::MAX_URI_BYTES );
		if ( is_wp_error( $redirect_uri ) ) return $redirect_uri;
		if ( ! self::redirect_uri_allowed( $client, $redirect_uri ) ) return new WP_Error( 'invalid_redirect_uri', 'OAuth redirect URI is not registered in trusted client metadata.' );
		$response_type = self::request_param( $params, 'response_type', 32 );
		if ( is_wp_error( $response_type ) ) return $response_type;
		if ( 'code' !== $response_type ) return new WP_Error( 'unsupported_response_type', 'Only authorization code response type is supported.' );
		$resource = self::request_param( $params, 'resource', self::MAX_URI_BYTES );
		if ( is_wp_error( $resource ) ) return $resource;
		$resource = untrailingslashit( $resource );
		if ( ! hash_equals( self::resource_identifier(), $resource ) ) return new WP_Error( 'invalid_target', 'OAuth resource must exactly match the MCP protected resource.' );
		$challenge = self::request_param( $params, 'code_challenge', 128 );
		if ( is_wp_error( $challenge ) ) return $challenge;
		$method = self::request_param( $params, 'code_challenge_method', 16 );
		if ( is_wp_error( $method ) ) return $method;
		$method = strtoupper( $method );
		if ( 'S256' !== $method || ! preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $challenge ) ) return new WP_Error( 'invalid_request', 'PKCE S256 code challenge is required.' );
		$scope = self::request_param( $params, 'scope', self::MAX_SCOPE_BYTES );
		if ( is_wp_error( $scope ) ) return $scope;
		$scopes = self::normalize_scopes( '' !== $scope ? $scope : 'mad4b:read' );
		if ( is_wp_error( $scopes ) ) return $scopes;
		$state = self::request_param( $params, 'state', self::MAX_STATE_BYTES, false );
		if ( is_wp_error( $state ) ) return $state;
		return array(
			'client_id' => $client_id,
			'client' => $client,
			'redirect_uri' => $redirect_uri,
			'resource' => $resource,
			'code_challenge' => $challenge,
			'scopes' => $scopes,
			'state' => $state,
		);
	}

	private static function authorization_request_error( WP_Error $error, array $params ) {
		$client_id = self::request_param( $params, 'client_id', self::MAX_CLIENT_ID_BYTES );
		$redirect_uri = self::request_param( $params, 'redirect_uri', self::MAX_URI_BYTES );
		$client = is_wp_error( $client_id ) ? null : self::client( $client_id );
		if ( ! is_wp_error( $client ) && is_array( $client ) && ! is_wp_error( $redirect_uri ) && self::redirect_uri_allowed( $client, $redirect_uri ) ) {
			$state = self::request_param( $params, 'state', self::MAX_STATE_BYTES, false );
			$validated = array(
				'redirect_uri' => $redirect_uri,
				'state' => is_wp_error( $state ) ? '' : $state,
			);
			self::redirect_authorization_error( $validated, $error->get_error_code() );
		}
		self::send_oauth_error( $error->get_error_code(), $error->get_error_message(), 400 );
	}

	private static function redirect_authorization_error( array $validated, $error ) {
		$location = add_query_arg(
			array_filter(
				array(
					'error' => sanitize_key( (string) $error ),
					'state' => isset( $validated['state'] ) ? $validated['state'] : '',
					'iss' => self::issuer(),
				),
				static function ( $value ) { return '' !== $value; }
			),
			$validated['redirect_uri']
		);
		self::trusted_client_redirect( $location );
	}

	private static function render_consent( array $validated, $user_id ) {
		$client_name = isset( $validated['client']['client_name'] ) ? (string) $validated['client']['client_name'] : $validated['client_id'];
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		$hidden = array(
			'client_id' => $validated['client_id'],
			'redirect_uri' => $validated['redirect_uri'],
			'response_type' => 'code',
			'resource' => $validated['resource'],
			'code_challenge' => $validated['code_challenge'],
			'code_challenge_method' => 'S256',
			'scope' => implode( ' ', $validated['scopes'] ),
			'state' => $validated['state'],
		);
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__( 'Authorize MCP access', 'mad4b-site-control-plane' ) . '</title></head><body>';
		echo '<main style="max-width:720px;margin:40px auto;font-family:system-ui,sans-serif;padding:0 20px">';
		echo '<h1>' . esc_html__( 'Authorize MCP access', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p><strong>' . esc_html( $client_name ) . '</strong> ' . esc_html__( 'is requesting read access to this WordPress MCP resource.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p>' . esc_html__( 'Signed in WordPress user:', 'mad4b-site-control-plane' ) . ' <code>' . esc_html( (string) $user_id ) . '</code></p>';
		echo '<p>' . esc_html__( 'Scopes:', 'mad4b-site-control-plane' ) . ' <code>' . esc_html( implode( ' ', $validated['scopes'] ) ) . '</code></p>';
		echo '<form method="post" action="' . esc_url( self::authorize_url() ) . '">';
		foreach ( $hidden as $name => $value ) echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		wp_nonce_field( 'mad4b_local_oauth_consent', '_mad4b_oauth_nonce' );
		echo '<button type="submit" name="decision" value="approve">' . esc_html__( 'Approve', 'mad4b-site-control-plane' ) . '</button> ';
		echo '<button type="submit" name="decision" value="deny">' . esc_html__( 'Deny', 'mad4b-site-control-plane' ) . '</button>';
		echo '</form></main></body></html>';
		exit;
	}

	private static function handle_token() {
		if ( ! self::effective_for_protocol() ) self::send_oauth_error( 'temporarily_unavailable', 'Local OAuth authority is not effective.', 503 );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method ) self::send_oauth_error( 'invalid_request', 'Token endpoint requires POST.', 405 );
		$params = wp_unslash( $_POST );
		$params = is_array( $params ) ? $params : array();
		$grant_type = self::request_param( $params, 'grant_type', 64 );
		if ( is_wp_error( $grant_type ) ) self::send_oauth_error( 'invalid_request', $grant_type->get_error_message(), 400 );
		if ( 'authorization_code' === $grant_type ) self::exchange_authorization_code( $params );
		if ( 'refresh_token' === $grant_type ) self::exchange_refresh_token( $params );
		self::send_oauth_error( 'unsupported_grant_type', 'Unsupported OAuth grant type.', 400 );
	}

	private static function exchange_authorization_code( array $params ) {
		$code = self::request_param( $params, 'code', self::MAX_TOKEN_INPUT_BYTES );
		$client_id = self::request_param( $params, 'client_id', self::MAX_CLIENT_ID_BYTES );
		$redirect_uri = self::request_param( $params, 'redirect_uri', self::MAX_URI_BYTES );
		$resource = self::request_param( $params, 'resource', self::MAX_URI_BYTES );
		$verifier = self::request_param( $params, 'code_verifier', 128 );
		foreach ( array( $code, $client_id, $redirect_uri, $resource, $verifier ) as $value ) if ( is_wp_error( $value ) ) self::send_oauth_error( 'invalid_request', $value->get_error_message(), 400 );
		$client = self::client( $client_id );
		if ( is_wp_error( $client ) || ! is_array( $client ) ) self::send_oauth_error( 'invalid_client', 'OAuth client metadata is unavailable or invalid.', 400 );
		$resource = untrailingslashit( $resource );
		if ( '' === $code || '' === $client_id || '' === $redirect_uri || '' === $verifier ) self::send_oauth_error( 'invalid_request', 'Authorization-code exchange is incomplete.', 400 );
		if ( ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier ) ) self::send_oauth_error( 'invalid_grant', 'PKCE verifier is invalid.', 400 );
		$row = MAD4B_SCP_Local_OAuth_Store::get_code( hash( 'sha256', $code ) );
		if ( ! is_array( $row ) || ! empty( $row['used_at'] ) || strtotime( (string) $row['expires_at'] . ' UTC' ) < time() ) self::send_oauth_error( 'invalid_grant', 'Authorization code is invalid or expired.', 400 );
		if ( ! hash_equals( (string) $row['client_id'], $client_id ) || ! hash_equals( (string) $row['redirect_uri'], $redirect_uri ) ) self::send_oauth_error( 'invalid_grant', 'Authorization code binding does not match.', 400 );
		if ( ! self::redirect_uri_allowed( $client, $redirect_uri ) ) self::send_oauth_error( 'invalid_grant', 'Client metadata no longer authorizes this redirect URI.', 400 );
		if ( '' === $resource || ! hash_equals( (string) $row['resource'], $resource ) || ! hash_equals( self::resource_identifier(), $resource ) ) self::send_oauth_error( 'invalid_target', 'Token request resource does not match.', 400 );
		$challenge = self::base64url_encode( hash( 'sha256', $verifier, true ) );
		if ( ! hash_equals( (string) $row['code_challenge'], $challenge ) ) self::send_oauth_error( 'invalid_grant', 'PKCE verification failed.', 400 );
		if ( ! self::user_authorized( (int) $row['wp_user_id'] ) ) self::send_oauth_error( 'access_denied', 'WordPress subject is no longer authorized.', 403 );
		if ( ! MAD4B_SCP_Local_OAuth_Store::mark_code_used( (int) $row['id'], gmdate( 'Y-m-d H:i:s' ) ) ) self::send_oauth_error( 'invalid_grant', 'Authorization code was already consumed.', 400 );
		$scopes = self::normalize_scopes( (string) $row['scope'] );
		if ( is_wp_error( $scopes ) ) self::send_oauth_error( 'invalid_scope', 'Stored scope binding is invalid.', 500 );
		self::issue_token_response( $client_id, (int) $row['wp_user_id'], $resource, $scopes, true );
	}

	private static function exchange_refresh_token( array $params ) {
		$token = self::request_param( $params, 'refresh_token', self::MAX_TOKEN_INPUT_BYTES );
		$client_id = self::request_param( $params, 'client_id', self::MAX_CLIENT_ID_BYTES );
		$resource = self::request_param( $params, 'resource', self::MAX_URI_BYTES );
		foreach ( array( $token, $client_id, $resource ) as $value ) if ( is_wp_error( $value ) ) self::send_oauth_error( 'invalid_request', $value->get_error_message(), 400 );
		$client = self::client( $client_id );
		if ( is_wp_error( $client ) || ! is_array( $client ) ) self::send_oauth_error( 'invalid_client', 'OAuth client metadata is unavailable or invalid.', 400 );
		$resource = untrailingslashit( $resource );
		if ( '' === $token || '' === $client_id || '' === $resource ) self::send_oauth_error( 'invalid_request', 'Refresh-token exchange is incomplete.', 400 );
		$row = MAD4B_SCP_Local_OAuth_Store::get_refresh_token( hash( 'sha256', $token ) );
		if ( ! is_array( $row ) ) self::send_oauth_error( 'invalid_grant', 'Refresh token is invalid.', 400 );
		if ( ! empty( $row['used_at'] ) ) {
			MAD4B_SCP_Local_OAuth_Store::revoke_family( (string) $row['family_id'], gmdate( 'Y-m-d H:i:s' ) );
			self::send_oauth_error( 'invalid_grant', 'Refresh token replay detected; token family revoked.', 400 );
		}
		if ( ! empty( $row['revoked_at'] ) || strtotime( (string) $row['expires_at'] . ' UTC' ) < time() ) self::send_oauth_error( 'invalid_grant', 'Refresh token is expired or revoked.', 400 );
		if ( ! hash_equals( (string) $row['client_id'], $client_id ) || ! hash_equals( (string) $row['resource'], $resource ) || ! hash_equals( self::resource_identifier(), $resource ) ) self::send_oauth_error( 'invalid_grant', 'Refresh token binding does not match.', 400 );
		if ( ! self::user_authorized( (int) $row['wp_user_id'] ) ) self::send_oauth_error( 'access_denied', 'WordPress subject is no longer authorized.', 403 );
		$scopes = self::normalize_scopes( (string) $row['scope'] );
		if ( is_wp_error( $scopes ) ) self::send_oauth_error( 'invalid_scope', 'Stored scope binding is invalid.', 500 );
		$replacement = self::random_token( 48 );
		if ( is_wp_error( $replacement ) ) self::send_oauth_error( 'server_error', 'Unable to rotate refresh token.', 500 );
		$replacement_hash = hash( 'sha256', $replacement );
		if ( ! MAD4B_SCP_Local_OAuth_Store::rotate_refresh_token( (int) $row['id'], gmdate( 'Y-m-d H:i:s' ), $replacement_hash ) ) {
			MAD4B_SCP_Local_OAuth_Store::revoke_family( (string) $row['family_id'], gmdate( 'Y-m-d H:i:s' ) );
			self::send_oauth_error( 'invalid_grant', 'Refresh token rotation lost a replay race; token family revoked.', 400 );
		}
		$inserted = MAD4B_SCP_Local_OAuth_Store::insert_refresh_token(
			array(
				'token_hash' => $replacement_hash,
				'family_id' => (string) $row['family_id'],
				'client_id' => $client_id,
				'wp_user_id' => (int) $row['wp_user_id'],
				'resource' => $resource,
				'scope' => implode( ' ', $scopes ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TOKEN_TTL ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( ! $inserted ) {
			MAD4B_SCP_Local_OAuth_Store::revoke_family( (string) $row['family_id'], gmdate( 'Y-m-d H:i:s' ) );
			self::send_oauth_error( 'server_error', 'Unable to persist rotated refresh token.', 500 );
		}
		self::issue_token_response( $client_id, (int) $row['wp_user_id'], $resource, $scopes, false, $replacement );
	}

	private static function issue_token_response( $client_id, $wp_user_id, $resource, array $scopes, $allow_new_refresh, $rotated_refresh = '' ) {
		$access = self::mint_access_token( $client_id, $wp_user_id, $resource, $scopes );
		if ( is_wp_error( $access ) ) self::send_oauth_error( 'server_error', 'Unable to sign access token.', 500 );
		$response = array(
			'access_token' => $access,
			'token_type' => 'Bearer',
			'expires_in' => self::ACCESS_TOKEN_TTL,
			'scope' => implode( ' ', $scopes ),
			'resource' => $resource,
		);
		if ( '' !== $rotated_refresh ) {
			$response['refresh_token'] = $rotated_refresh;
		} elseif ( $allow_new_refresh && in_array( 'offline_access', $scopes, true ) ) {
			$refresh = self::create_refresh_token( $client_id, $wp_user_id, $resource, $scopes );
			if ( is_wp_error( $refresh ) ) self::send_oauth_error( 'server_error', 'Unable to create refresh token.', 500 );
			$response['refresh_token'] = $refresh;
		}
		self::send_json( $response, 200 );
	}

	private static function create_refresh_token( $client_id, $wp_user_id, $resource, array $scopes ) {
		$token = self::random_token( 48 );
		if ( is_wp_error( $token ) ) return $token;
		$stored = MAD4B_SCP_Local_OAuth_Store::insert_refresh_token(
			array(
				'token_hash' => hash( 'sha256', $token ),
				'family_id' => wp_generate_uuid4(),
				'client_id' => $client_id,
				'wp_user_id' => $wp_user_id,
				'resource' => $resource,
				'scope' => implode( ' ', $scopes ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TOKEN_TTL ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return $stored ? $token : new WP_Error( 'mad4b_local_oauth_refresh_store_failed', 'Unable to persist refresh token.' );
	}

	private static function handle_revocation() {
		if ( ! self::effective_for_protocol() ) self::send_oauth_error( 'temporarily_unavailable', 'Local OAuth authority is not effective.', 503 );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method ) self::send_oauth_error( 'invalid_request', 'Revocation endpoint requires POST.', 405 );
		$params = wp_unslash( $_POST );
		$params = is_array( $params ) ? $params : array();
		$token = self::request_param( $params, 'token', self::MAX_TOKEN_INPUT_BYTES );
		$client_id = self::request_param( $params, 'client_id', self::MAX_CLIENT_ID_BYTES );
		if ( is_wp_error( $token ) || is_wp_error( $client_id ) ) self::send_oauth_error( 'invalid_request', 'Revocation request contains an invalid parameter.', 400 );
		$client = self::client( $client_id );
		if ( '' !== $client_id && ( is_wp_error( $client ) || ! is_array( $client ) ) ) self::send_oauth_error( 'invalid_client', 'OAuth client metadata is unavailable or invalid.', 400 );
		if ( '' !== $token ) {
			$row = MAD4B_SCP_Local_OAuth_Store::get_refresh_token( hash( 'sha256', $token ) );
			if ( is_array( $row ) && '' !== $client_id && hash_equals( (string) $row['client_id'], $client_id ) ) MAD4B_SCP_Local_OAuth_Store::revoke_family( (string) $row['family_id'], gmdate( 'Y-m-d H:i:s' ) );
		}
		self::send_json( array(), 200 );
	}

	private static function mint_access_token( $client_id, $wp_user_id, $resource, array $scopes ) {
		if ( ! is_string( $client_id ) || '' === $client_id || strlen( $client_id ) > self::MAX_CLIENT_ID_BYTES ) return new WP_Error( 'mad4b_local_oauth_client_id_invalid', 'OAuth client identifier exceeds the durable schema bound.' );
		$key = self::public_jwk();
		if ( is_wp_error( $key ) ) return $key;
		$now = time();
		$header = array( 'typ' => 'at+jwt', 'alg' => 'RS256', 'kid' => $key['kid'] );
		$claims = array(
			'iss' => self::issuer(),
			'aud' => $resource,
			'resource' => $resource,
			'sub' => 'user:' . (int) $wp_user_id,
			'scope' => implode( ' ', $scopes ),
			'client_id' => $client_id,
			'azp' => $client_id,
			'jti' => wp_generate_uuid4(),
			'iat' => $now,
			'nbf' => $now - self::CLOCK_SKEW,
			'exp' => $now + self::ACCESS_TOKEN_TTL,
		);
		$encoded_header = self::base64url_encode( wp_json_encode( $header ) );
		$encoded_claims = self::base64url_encode( wp_json_encode( $claims ) );
		$signing_input = $encoded_header . '.' . $encoded_claims;
		$pem = self::private_key_pem();
		if ( is_wp_error( $pem ) ) return $pem;
		$private = openssl_pkey_get_private( $pem );
		unset( $pem );
		if ( false === $private ) return new WP_Error( 'mad4b_local_oauth_private_key_invalid', 'Local OAuth private key is invalid.' );
		$signature = '';
		if ( ! openssl_sign( $signing_input, $signature, $private, OPENSSL_ALGO_SHA256 ) ) return new WP_Error( 'mad4b_local_oauth_sign_failed', 'Unable to sign access token.' );
		return $signing_input . '.' . self::base64url_encode( $signature );
	}

	private static function normalize_scopes( $scope ) {
		$scope = trim( (string) $scope );
		if ( strlen( $scope ) > self::MAX_SCOPE_BYTES ) return new WP_Error( 'invalid_scope', 'OAuth scope is too large.' );
		$items = preg_split( '/\s+/', $scope );
		$allowed = array( 'mad4b:read', 'offline_access' );
		$scopes = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) continue;
			if ( ! in_array( $item, $allowed, true ) ) return new WP_Error( 'invalid_scope', 'OAuth scope is not supported.' );
			$scopes[] = $item;
		}
		$scopes = array_values( array_unique( $scopes ) );
		if ( ! in_array( 'mad4b:read', $scopes, true ) ) return new WP_Error( 'invalid_scope', 'mad4b:read is required.' );
		return $scopes;
	}

	private static function clients() {
		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_CLIENTS' ) ) return array();
		$value = constant( 'MAD4B_MCP_LOCAL_OAUTH_CLIENTS' );
		if ( ! is_array( $value ) ) return array();
		$clients = array();
		foreach ( array_slice( $value, 0, self::MAX_CLIENTS, true ) as $client_id => $config ) {
			$client_id = is_string( $client_id ) ? trim( $client_id ) : '';
			if ( '' === $client_id || strlen( $client_id ) > self::MAX_CLIENT_ID_BYTES || ! is_array( $config ) ) continue;
			$redirects = isset( $config['redirect_uris'] ) && is_array( $config['redirect_uris'] ) ? array_slice( $config['redirect_uris'], 0, self::MAX_REDIRECTS_PER_CLIENT ) : array();
			$bounded = array();
			foreach ( $redirects as $redirect ) if ( is_string( $redirect ) && strlen( trim( $redirect ) ) <= self::MAX_URI_BYTES && self::valid_redirect_uri( $redirect ) ) $bounded[] = trim( $redirect );
			if ( empty( $bounded ) ) continue;
			$client_name = isset( $config['client_name'] ) ? sanitize_text_field( (string) $config['client_name'] ) : $client_id;
			$clients[ $client_id ] = array(
				'client_name' => substr( $client_name, 0, 191 ),
				'redirect_uris' => array_values( array_unique( $bounded ) ),
				'application_type' => isset( $config['application_type'] ) && 'native' === sanitize_key( (string) $config['application_type'] ) ? 'native' : 'web',
				'registration_mode' => 'pre_registered',
			);
		}
		return $clients;
	}

	private static function client( $client_id ) {
		if ( ! is_string( $client_id ) || '' === $client_id || strlen( $client_id ) > self::MAX_CLIENT_ID_BYTES ) return null;
		$clients = self::clients();
		if ( isset( $clients[ $client_id ] ) ) return $clients[ $client_id ];
		if ( ! self::cimd_client_id_allowed( $client_id ) ) return null;
		return self::fetch_cimd_client( $client_id );
	}

	private static function cimd_supported() {
		return strlen( self::CHATGPT_CIMD_CLIENT_ID ) <= self::MAX_CLIENT_ID_BYTES && self::valid_cimd_client_id( self::CHATGPT_CIMD_CLIENT_ID );
	}

	private static function cimd_client_id_allowed( $client_id ) {
		return self::cimd_supported() && is_string( $client_id ) && hash_equals( self::CHATGPT_CIMD_CLIENT_ID, $client_id );
	}

	private static function valid_cimd_client_id( $client_id ) {
		if ( ! is_string( $client_id ) || '' === $client_id || strlen( $client_id ) > self::MAX_CLIENT_ID_BYTES ) return false;
		$parts = wp_parse_url( $client_id );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'], $parts['path'] )
			&& 'https' === strtolower( (string) $parts['scheme'] )
			&& '' !== (string) $parts['host']
			&& '/' !== (string) $parts['path']
			&& empty( $parts['user'] )
			&& empty( $parts['pass'] )
			&& empty( $parts['query'] )
			&& empty( $parts['fragment'] );
	}

	private static function fetch_cimd_client( $client_id ) {
		if ( ! self::cimd_client_id_allowed( $client_id ) ) return new WP_Error( 'invalid_client', 'Client ID Metadata Document URL is not allowed by policy.' );
		$cache_key = 'mad4b_oauth_cimd_' . substr( hash( 'sha256', $client_id ), 0, 32 );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['client_id'] ) && is_string( $cached['client_id'] ) && hash_equals( $client_id, $cached['client_id'] ) ) return $cached;

		$response = wp_safe_remote_get(
			$client_id,
			array(
				'timeout' => 5,
				'redirection' => 0,
				'sslverify' => true,
				'limit_response_size' => self::MAX_CIMD_BYTES + 1,
				'headers' => array( 'Accept' => 'application/json' ),
				'user-agent' => 'MAD4B Site Control Plane/' . ( defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : 'unknown' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) return new WP_Error( 'invalid_client', 'Client ID Metadata Document is unavailable.' );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > self::MAX_CIMD_BYTES ) return new WP_Error( 'invalid_client', 'Client ID Metadata Document is empty or exceeds its size bound.' );
		$metadata = json_decode( $body, true );
		$normalized = self::validate_cimd_metadata( $client_id, $metadata );
		if ( is_wp_error( $normalized ) ) return $normalized;

		$ttl = self::cimd_cache_ttl( (string) wp_remote_retrieve_header( $response, 'cache-control' ) );
		if ( $ttl > 0 ) set_transient( $cache_key, $normalized, $ttl );
		return $normalized;
	}

	private static function validate_cimd_metadata( $client_id, $metadata ) {
		if ( ! is_array( $metadata ) ) return new WP_Error( 'invalid_client', 'Client ID Metadata Document must contain a JSON object.' );
		if ( ! isset( $metadata['client_id'] ) || ! is_string( $metadata['client_id'] ) || ! hash_equals( $client_id, $metadata['client_id'] ) ) return new WP_Error( 'invalid_client', 'Client ID Metadata Document client_id must exactly match the requested URL.' );
		if ( empty( $metadata['client_name'] ) || ! is_string( $metadata['client_name'] ) || strlen( trim( $metadata['client_name'] ) ) > 191 ) return new WP_Error( 'invalid_client', 'Client ID Metadata Document client_name is missing or invalid.' );
		if ( empty( $metadata['redirect_uris'] ) || ! is_array( $metadata['redirect_uris'] ) || count( $metadata['redirect_uris'] ) > self::MAX_REDIRECTS_PER_CLIENT ) return new WP_Error( 'invalid_client', 'Client ID Metadata Document redirect_uris is missing or invalid.' );
		$redirects = array();
		foreach ( $metadata['redirect_uris'] as $redirect ) {
			if ( ! is_string( $redirect ) || strlen( trim( $redirect ) ) > self::MAX_URI_BYTES || ! self::valid_redirect_uri( $redirect ) ) return new WP_Error( 'invalid_client', 'Client ID Metadata Document contains an invalid redirect URI.' );
			$redirects[] = trim( $redirect );
		}
		$redirects = array_values( array_unique( $redirects ) );
		if ( empty( $redirects ) ) return new WP_Error( 'invalid_client', 'Client ID Metadata Document has no valid redirect URI.' );
		if ( isset( $metadata['grant_types'] ) && ( ! is_array( $metadata['grant_types'] ) || ! in_array( 'authorization_code', $metadata['grant_types'], true ) ) ) return new WP_Error( 'invalid_client', 'Client ID Metadata Document does not support authorization_code.' );
		if ( isset( $metadata['response_types'] ) && ( ! is_array( $metadata['response_types'] ) || ! in_array( 'code', $metadata['response_types'], true ) ) ) return new WP_Error( 'invalid_client', 'Client ID Metadata Document does not support code responses.' );

		$token_methods = array();
		if ( isset( $metadata['token_endpoint_auth_methods_supported'] ) && is_array( $metadata['token_endpoint_auth_methods_supported'] ) ) $token_methods = $metadata['token_endpoint_auth_methods_supported'];
		elseif ( isset( $metadata['token_endpoint_auth_method'] ) && is_string( $metadata['token_endpoint_auth_method'] ) ) $token_methods = array( $metadata['token_endpoint_auth_method'] );
		if ( empty( $token_methods ) || ! in_array( 'none', $token_methods, true ) ) return new WP_Error( 'invalid_client', 'Client ID Metadata Document is incompatible with this public PKCE token endpoint.' );

		return array(
			'client_id' => $client_id,
			'client_name' => substr( sanitize_text_field( trim( $metadata['client_name'] ) ), 0, 191 ),
			'redirect_uris' => $redirects,
			'application_type' => isset( $metadata['application_type'] ) && 'native' === sanitize_key( (string) $metadata['application_type'] ) ? 'native' : 'web',
			'registration_mode' => 'cimd',
		);
	}

	private static function cimd_cache_ttl( $cache_control ) {
		$cache_control = strtolower( trim( (string) $cache_control ) );
		if ( false !== strpos( $cache_control, 'no-store' ) ) return 0;
		if ( preg_match( '/(?:^|,)\s*max-age\s*=\s*(\d+)/', $cache_control, $matches ) ) return min( self::CIMD_CACHE_TTL, max( 0, (int) $matches[1] ) );
		return self::CIMD_CACHE_TTL;
	}

	private static function redirect_uri_allowed( array $client, $redirect_uri ) {
		if ( ! is_string( $redirect_uri ) || '' === $redirect_uri || strlen( $redirect_uri ) > self::MAX_URI_BYTES ) return false;
		foreach ( isset( $client['redirect_uris'] ) && is_array( $client['redirect_uris'] ) ? $client['redirect_uris'] : array() as $allowed ) if ( is_string( $allowed ) && hash_equals( $allowed, $redirect_uri ) ) return true;
		return false;
	}

	private static function valid_redirect_uri( $uri ) {
		$uri = trim( (string) $uri );
		if ( '' === $uri || strlen( $uri ) > self::MAX_URI_BYTES ) return false;
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['fragment'] ) ) return false;
		$scheme = strtolower( (string) $parts['scheme'] );
		$host = strtolower( (string) $parts['host'] );
		if ( 'https' === $scheme ) return true;
		return 'http' === $scheme && in_array( $host, array( '127.0.0.1', '::1', 'localhost' ), true );
	}

	private static function user_authorized( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) return false;
		$capability = defined( 'MAD4B_MCP_LOCAL_OAUTH_REQUIRED_CAPABILITY' ) ? sanitize_key( (string) constant( 'MAD4B_MCP_LOCAL_OAUTH_REQUIRED_CAPABILITY' ) ) : 'manage_options';
		if ( '' === $capability || ! user_can( $user, $capability ) ) return false;
		$subject = 'user:' . (int) $user_id;
		if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) return false;
		$value = constant( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' );
		$items = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		foreach ( is_array( $items ) ? $items : array() as $item ) if ( is_string( $item ) && hash_equals( $subject, trim( $item ) ) ) return true;
		return false;
	}

	private static function bind_resource_bridge_defaults() {
		if ( ! self::enabled() ) return;
		if ( ! defined( 'MAD4B_MCP_OAUTH_ENABLED' ) ) define( 'MAD4B_MCP_OAUTH_ENABLED', true );
		if ( ! defined( 'MAD4B_MCP_OAUTH_ISSUER' ) ) define( 'MAD4B_MCP_OAUTH_ISSUER', self::issuer() );
	}

	private static function enabled() {
		return defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) && true === constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' );
	}

	private static function production_approved() {
		return defined( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED' ) && true === constant( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED' );
	}

	private static function environment_allowed() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		return 'staging' === $environment || ( 'production' === $environment && self::production_approved() );
	}

	private static function effective_for_protocol() {
		$status = self::status();
		return ! empty( $status['effective'] );
	}

	private static function ensure_signing_key() {
		$path = self::private_key_path();
		if ( is_wp_error( $path ) ) return $path;
		if ( is_file( $path ) ) {
			@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort permission hardening.
			return true;
		}
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return new WP_Error( 'mad4b_local_oauth_key_directory_unavailable', 'Unable to create private OAuth key directory.' );
		@chmod( $dir, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort permission hardening.
		$key = openssl_pkey_new( array( 'private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		if ( false === $key ) return new WP_Error( 'mad4b_local_oauth_key_generation_failed', 'Unable to generate local RS256 signing key.' );
		$pem = '';
		if ( ! openssl_pkey_export( $key, $pem ) || '' === $pem ) return new WP_Error( 'mad4b_local_oauth_key_export_failed', 'Unable to export local RS256 signing key.' );
		$tmp = $path . '.tmp-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 12 );
		$written = file_put_contents( $tmp, $pem, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private key bootstrap requires atomic filesystem write.
		unset( $pem );
		if ( false === $written ) return new WP_Error( 'mad4b_local_oauth_key_write_failed', 'Unable to persist local RS256 signing key.' );
		@chmod( $tmp, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_file( $path ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return true;
			}
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_local_oauth_key_commit_failed', 'Unable to atomically commit local RS256 signing key.' );
		}
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return true;
	}

	private static function private_key_path() {
		$path = defined( 'MAD4B_MCP_LOCAL_OAUTH_PRIVATE_KEY_PATH' )
			? trim( (string) constant( 'MAD4B_MCP_LOCAL_OAUTH_PRIVATE_KEY_PATH' ) )
			: trailingslashit( dirname( rtrim( ABSPATH, '/\\' ) ) ) . '.mad4b/oauth/wordpress-local-rs256-private.pem';
		if ( '' === $path || ! self::absolute_path( $path ) ) return new WP_Error( 'mad4b_local_oauth_key_path_invalid', 'Local OAuth private key path must be absolute.' );
		$normalized = wp_normalize_path( $path );
		$web_root = trailingslashit( wp_normalize_path( ABSPATH ) );
		if ( 0 === strpos( trailingslashit( dirname( $normalized ) ), $web_root ) ) return new WP_Error( 'mad4b_local_oauth_key_path_web_exposed', 'Local OAuth private key path must be outside the WordPress web root.' );
		return $path;
	}

	private static function private_key_pem() {
		$path = self::private_key_path();
		if ( is_wp_error( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) return new WP_Error( 'mad4b_local_oauth_private_key_unavailable', 'Local OAuth private key is unavailable.' );
		$pem = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- private key is local filesystem state.
		if ( ! is_string( $pem ) || strlen( $pem ) > 16384 || false === strpos( $pem, 'PRIVATE KEY' ) ) return new WP_Error( 'mad4b_local_oauth_private_key_invalid', 'Local OAuth private key is invalid.' );
		return $pem;
	}

	private static function public_jwk() {
		$pem = self::private_key_pem();
		if ( is_wp_error( $pem ) ) return $pem;
		$key = openssl_pkey_get_private( $pem );
		unset( $pem );
		if ( false === $key ) return new WP_Error( 'mad4b_local_oauth_private_key_invalid', 'Local OAuth private key is invalid.' );
		$details = openssl_pkey_get_details( $key );
		if ( ! is_array( $details ) || empty( $details['key'] ) || empty( $details['rsa']['n'] ) || empty( $details['rsa']['e'] ) ) return new WP_Error( 'mad4b_local_oauth_public_key_unavailable', 'Unable to derive local OAuth public key.' );
		if ( empty( $details['bits'] ) || (int) $details['bits'] < 2048 ) return new WP_Error( 'mad4b_local_oauth_rsa_key_too_small', 'Local OAuth RSA signing key must be at least 2048 bits.' );
		return array(
			'kty' => 'RSA',
			'use' => 'sig',
			'key_ops' => array( 'verify' ),
			'alg' => 'RS256',
			'kid' => substr( hash( 'sha256', (string) $details['key'] ), 0, 32 ),
			'n' => self::base64url_encode( $details['rsa']['n'] ),
			'e' => self::base64url_encode( $details['rsa']['e'] ),
		);
	}

	private static function absolute_path( $path ) {
		return 1 === preg_match( '#^(?:[A-Za-z]:[\\\\/]|/)#', (string) $path );
	}

	private static function configured_issuer_validation() {
		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_ISSUER' ) ) return self::origin() . self::ISSUER_PATH;
		$configured = rtrim( trim( (string) constant( 'MAD4B_MCP_LOCAL_OAUTH_ISSUER' ) ), '/' );
		if ( strlen( $configured ) > self::MAX_URI_BYTES || ! self::valid_https_url( $configured ) ) return new WP_Error( 'mad4b_local_oauth_issuer_invalid', 'Configured local OAuth issuer must be a bounded HTTPS URL without credentials, query or fragment.' );
		if ( ! self::same_origin( $configured, self::origin() ) ) return new WP_Error( 'mad4b_local_oauth_issuer_cross_origin', 'Configured local OAuth issuer must use the same origin as this WordPress site.' );
		return $configured;
	}

	private static function same_origin( $url, $origin ) {
		$a = wp_parse_url( (string) $url );
		$b = wp_parse_url( (string) $origin );
		if ( ! is_array( $a ) || ! is_array( $b ) ) return false;
		$scheme_a = strtolower( (string) ( isset( $a['scheme'] ) ? $a['scheme'] : '' ) );
		$scheme_b = strtolower( (string) ( isset( $b['scheme'] ) ? $b['scheme'] : '' ) );
		$host_a = strtolower( (string) ( isset( $a['host'] ) ? $a['host'] : '' ) );
		$host_b = strtolower( (string) ( isset( $b['host'] ) ? $b['host'] : '' ) );
		$port_a = isset( $a['port'] ) ? (int) $a['port'] : ( 'https' === $scheme_a ? 443 : 80 );
		$port_b = isset( $b['port'] ) ? (int) $b['port'] : ( 'https' === $scheme_b ? 443 : 80 );
		return '' !== $host_a && $scheme_a === $scheme_b && $host_a === $host_b && $port_a === $port_b;
	}

	private static function valid_https_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && 'https' === strtolower( (string) $parts['scheme'] ) && '' !== (string) $parts['host'] && empty( $parts['user'] ) && empty( $parts['pass'] ) && empty( $parts['query'] ) && empty( $parts['fragment'] );
	}

	private static function origin() {
		$parts = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
		$origin = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) $origin .= ':' . (int) $parts['port'];
		return $origin;
	}

	private static function request_param( array $params, $name, $max_bytes, $trim = true ) {
		if ( ! array_key_exists( $name, $params ) ) return '';
		$value = $params[ $name ];
		if ( ! is_scalar( $value ) && null !== $value ) return new WP_Error( 'invalid_request', 'OAuth parameter must be scalar: ' . sanitize_key( (string) $name ) );
		$value = (string) $value;
		if ( $trim ) $value = trim( $value );
		if ( strlen( $value ) > (int) $max_bytes ) return new WP_Error( 'invalid_request', 'OAuth parameter exceeds its size limit: ' . sanitize_key( (string) $name ) );
		return $value;
	}

	private static function trusted_client_redirect( $location ) {
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		wp_redirect( esc_url_raw( $location ), 302, 'MAD4B Local OAuth' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- destination was exact-matched against trusted client metadata.
		exit;
	}

	private static function send_json( $payload, $status ) {
		nocache_headers();
		status_header( (int) $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $payload );
		exit;
	}

	private static function send_oauth_error( $code, $description, $status ) {
		self::send_json(
			array(
				'error' => sanitize_key( (string) $code ),
				'error_description' => sanitize_text_field( (string) $description ),
			),
			$status
		);
	}

	private static function http_json_response( array $payload ) {
		return array(
			'headers' => array(
				'content-type' => 'application/json; charset=utf-8',
				'cache-control' => 'no-store',
				'referrer-policy' => 'no-referrer',
				'x-content-type-options' => 'nosniff',
			),
			'body' => wp_json_encode( $payload ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies' => array(),
			'filename' => null,
		);
	}

	private static function random_token( $bytes ) {
		try {
			return self::base64url_encode( random_bytes( (int) $bytes ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'mad4b_local_oauth_random_failed', 'Cryptographically secure random generation failed.' );
		}
	}

	private static function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
	}
}
