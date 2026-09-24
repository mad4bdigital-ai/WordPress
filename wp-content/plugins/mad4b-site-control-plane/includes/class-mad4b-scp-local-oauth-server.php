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
	private static $public_jwk_request_cache = null;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		self::bind_resource_bridge_defaults();
		add_action( 'init', array( __CLASS__, 'ensure_runtime' ), 1 );
		add_action( 'parse_request', array( __CLASS__, 'serve_protocol_paths' ), -10 );
		add_filter( 'pre_http_request', array( __CLASS__, 'intercept_local_discovery' ), 1, 3 );
		add_action( 'wp_ajax_mad4b_oauth_grant_projection', array( __CLASS__, 'ajax_grant_projection' ) );
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
		$transport_allowed = self::issuer_transport_allowed( self::issuer() );
		$client_policy_ready = ! empty( $clients ) || self::cimd_supported();
		$effective = self::enabled() && self::environment_allowed() && $transport_allowed && $issuer_valid && $store_ready && $key_ready && $client_policy_ready && ! is_wp_error( self::$runtime_error );
		return array(
			'contract' => self::CONTRACT,
			'configured' => self::enabled(),
			'effective' => (bool) $effective,
			'environment' => function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown',
			'production_approved' => self::production_approved(),
			'issuer' => self::issuer(),
			'issuer_same_origin_required' => true,
			'issuer_configuration_valid' => $issuer_valid,
			'issuer_transport_allowed' => $transport_allowed,
			'http_loopback_local_only' => true,
			'authorization_endpoint' => self::authorize_url(),
			'token_endpoint' => self::token_url(),
			'jwks_uri' => self::jwks_url(),
			'revocation_endpoint' => self::revocation_url(),
			'authorization_server_metadata' => self::metadata_url(),
			'protected_resources' => self::resource_identifiers(),
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
			'scopes_supported' => array( 'mad4b:read', 'mad4b:authority:step-up', 'server:mad4b-developer', 'server:mad4b-developer-breakglass', 'offline_access' ),
			'authorization_response_iss_parameter_supported' => true,
			'protected_resources' => self::resource_identifiers(),
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
		return self::site_base_url() . self::ISSUER_PATH;
	}

	public static function metadata_url() {
		return self::metadata_url_for_issuer( self::issuer() );
	}

	public static function authorize_url() { return self::site_base_url() . self::AUTHORIZE_PATH; }
	public static function token_url() { return self::site_base_url() . self::TOKEN_PATH; }
	public static function jwks_url() { return self::site_base_url() . self::JWKS_PATH; }
	public static function revocation_url() { return self::site_base_url() . self::REVOCATION_PATH; }

	private static function metadata_url_for_issuer( $issuer ) {
		$parts = wp_parse_url( (string) $issuer );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) return self::origin() . '/.well-known/oauth-authorization-server';
		return self::origin() . '/.well-known/oauth-authorization-server/' . ltrim( rtrim( (string) $parts['path'], '/' ), '/' );
	}

	public static function resource_identifier() {
		if ( class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier();
		return untrailingslashit( rest_url( 'mcp/mad4b-chatgpt' ) );
	}

	public static function resource_identifiers() {
		if ( class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) && method_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', 'resource_identifiers' ) ) return MAD4B_SCP_OAuth_Resource_Bridge::resource_identifiers();
		return array( self::resource_identifier() );
	}

	private static function resource_allowed( $resource ) {
		$resource = untrailingslashit( trim( (string) $resource ) );
		foreach ( self::resource_identifiers() as $expected ) if ( hash_equals( $expected, $resource ) ) return true;
		return false;
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
		$path = self::protocol_path( $path );
		$paths = array(
			'metadata' => self::protocol_path( self::metadata_url() ),
			'jwks' => self::protocol_path( self::jwks_url() ),
			'authorize' => self::protocol_path( self::authorize_url() ),
			'token' => self::protocol_path( self::token_url() ),
			'revocation' => self::protocol_path( self::revocation_url() ),
		);
		$known = in_array( $path, array_values( $paths ), true );
		if ( $known && ! self::effective_for_protocol() ) self::send_oauth_error( 'temporarily_unavailable', 'Local OAuth authority is not effective.', 503 );
		if ( hash_equals( $paths['metadata'], $path ) ) self::send_json( self::metadata(), 200 );
		if ( hash_equals( $paths['jwks'], $path ) ) {
			$jwks = self::jwks_document();
			if ( is_wp_error( $jwks ) ) self::send_oauth_error( 'server_error', 'Local signing key is unavailable.', 503 );
			self::send_json( $jwks, 200 );
		}
		if ( hash_equals( $paths['authorize'], $path ) ) self::handle_authorize();
		if ( hash_equals( $paths['token'], $path ) ) self::handle_token();
		if ( hash_equals( $paths['revocation'], $path ) ) self::handle_revocation();
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
		if ( ! self::resource_allowed( $resource ) ) return new WP_Error( 'invalid_target', 'OAuth resource must exactly match a protected MAD4B MCP resource.' );
		$challenge = self::request_param( $params, 'code_challenge', 128 );
		if ( is_wp_error( $challenge ) ) return $challenge;
		$method = self::request_param( $params, 'code_challenge_method', 16 );
		if ( is_wp_error( $method ) ) return $method;
		$method = strtoupper( $method );
		if ( 'S256' !== $method || ! preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $challenge ) ) return new WP_Error( 'invalid_request', 'PKCE S256 code challenge is required.' );
		$scope = self::request_param( $params, 'scope', self::MAX_SCOPE_BYTES );
		if ( is_wp_error( $scope ) ) return $scope;
		$scopes = self::normalize_scopes( '' !== $scope ? $scope : 'mad4b:read', $resource, $client_id );
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


	public static function consent_grant_projection() {
		$plan = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'reconciliation_plan' )
			? MAD4B_SCP_Staging_Write_Authority::reconciliation_plan()
			: array();
		$rows = isset( $plan['rows'] ) && is_array( $plan['rows'] ) ? $plan['rows'] : array();
		$grants = array();
		$plan_runtime = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) continue;
			$ability = isset( $row['ability'] ) ? trim( (string) $row['ability'] ) : '';
			$provider = isset( $row['provider'] ) ? trim( (string) $row['provider'] ) : '';
			if ( '' !== $ability && ! empty( $row['mounted'] ) ) $plan_runtime[] = $ability;
			if ( '' === $ability || '' === $provider || empty( $row['mounted'] ) || empty( $row['exact_grant_present'] ) ) continue;
			$grants[] = array( 'ability' => $ability, 'provider' => $provider );
		}
		usort( $grants, static function ( $a, $b ) {
			return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] );
		} );

		$catalog = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::external_write_tools() : array();
		$runtime = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::write_tools() : array();
		$blocked = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::blocked_write_tools() : array();
		$authority_gated = class_exists( 'MAD4B_SCP_Servers' ) && method_exists( 'MAD4B_SCP_Servers', 'authority_gated_write_tools' )
			? MAD4B_SCP_Servers::authority_gated_write_tools()
			: array();
		$catalog = array_values( array_unique( array_map( 'strval', is_array( $catalog ) ? $catalog : array() ) ) );
		$runtime = array_values( array_unique( array_map( 'strval', is_array( $runtime ) ? $runtime : array() ) ) );
		$plan_runtime = array_values( array_unique( array_map( 'strval', $plan_runtime ) ) );
		sort( $catalog, SORT_STRING );
		sort( $runtime, SORT_STRING );
		sort( $plan_runtime, SORT_STRING );

		$blocked_rows = array_values( is_array( $blocked ) ? $blocked : array() );
		$blocked_abilities = array();
		foreach ( $blocked_rows as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['ability'] ) ) $blocked_abilities[] = (string) $entry['ability'];
		}
		$blocked_abilities = array_values( array_unique( $blocked_abilities ) );
		sort( $blocked_abilities, SORT_STRING );

		$authority_gated_rows = array_values( is_array( $authority_gated ) ? $authority_gated : array() );
		$authority_gated_abilities = array();
		foreach ( $authority_gated_rows as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['ability'] ) ) $authority_gated_abilities[] = (string) $entry['ability'];
		}
		$authority_gated_abilities = array_values( array_unique( $authority_gated_abilities ) );
		sort( $authority_gated_abilities, SORT_STRING );

		$reconstructed_catalog = array_values( array_unique( array_merge( $runtime, $blocked_abilities, $authority_gated_abilities ) ) );
		sort( $reconstructed_catalog, SORT_STRING );

		$binding = isset( $plan['candidate_binding'] ) && is_array( $plan['candidate_binding'] ) ? $plan['candidate_binding'] : array();
		$missing = isset( $plan['exact_grants_missing_count'] ) ? (int) $plan['exact_grants_missing_count'] : 0;
		$missing_items = isset( $plan['exact_grants_missing'] ) && is_array( $plan['exact_grants_missing'] ) ? array_values( $plan['exact_grants_missing'] ) : array();
		$stale = isset( $plan['stale_allow_grants_count'] ) ? (int) $plan['stale_allow_grants_count'] : 0;
		$stale_items = isset( $plan['stale_allow_grants'] ) && is_array( $plan['stale_allow_grants'] ) ? array_values( $plan['stale_allow_grants'] ) : array();
		$global_wildcards = isset( $plan['global_registry_wildcard_grants'] ) ? (int) $plan['global_registry_wildcard_grants'] : ( isset( $plan['wildcard_grants'] ) ? (int) $plan['wildcard_grants'] : 0 );
		$current_agent_wildcards = isset( $plan['current_agent_wildcard_grants'] ) ? (int) $plan['current_agent_wildcard_grants'] : 0;
		$duplicates = isset( $plan['duplicate_exact_allow_grants_count'] ) ? (int) $plan['duplicate_exact_allow_grants_count'] : 0;
		$duplicate_items = isset( $plan['duplicate_exact_allow_grants'] ) && is_array( $plan['duplicate_exact_allow_grants'] ) ? array_values( $plan['duplicate_exact_allow_grants'] ) : array();
		$broad_environment = isset( $plan['broad_environment_grants_count'] ) ? (int) $plan['broad_environment_grants_count'] : 0;
		$broad_environment_items = isset( $plan['broad_environment_grants'] ) && is_array( $plan['broad_environment_grants'] ) ? array_values( $plan['broad_environment_grants'] ) : array();
		$write_tool_count = isset( $plan['write_tool_count'] ) ? (int) $plan['write_tool_count'] : count( $runtime );
		$binding_required = ! empty( $binding['required'] );
		$binding_match = ! $binding_required || ! empty( $binding['match'] );

		$consistency_violations = array();
		if ( $write_tool_count !== count( $runtime ) ) $consistency_violations[] = 'plan_runtime_count_mismatch';
		if ( $plan_runtime !== $runtime ) $consistency_violations[] = 'plan_runtime_inventory_mismatch';
		if ( count( $grants ) > count( $runtime ) ) $consistency_violations[] = 'exact_grants_exceed_runtime_inventory';
		$grant_abilities = array_values( array_unique( array_map( static function ( $grant ) { return isset( $grant['ability'] ) ? (string) $grant['ability'] : ''; }, $grants ) ) );
		$grant_abilities = array_values( array_filter( $grant_abilities, static function ( $value ) { return '' !== $value; } ) );
		sort( $grant_abilities, SORT_STRING );
		if ( ! empty( array_diff( $grant_abilities, $runtime ) ) ) $consistency_violations[] = 'exact_grant_outside_runtime_inventory';
		if ( $catalog !== $reconstructed_catalog ) $consistency_violations[] = 'catalog_runtime_gate_partition_mismatch';
		if ( ! empty( array_intersect( $runtime, $blocked_abilities ) ) ) $consistency_violations[] = 'provider_gated_runtime_overlap';
		if ( ! empty( array_intersect( $runtime, $authority_gated_abilities ) ) ) $consistency_violations[] = 'authority_gated_runtime_overlap';
		if ( ! empty( array_intersect( $blocked_abilities, $authority_gated_abilities ) ) ) $consistency_violations[] = 'provider_authority_gate_overlap';
		$projection_consistent = empty( $consistency_violations );

		$blocking_conditions = array();
		if ( ! $projection_consistent ) $blocking_conditions[] = array( 'code' => 'authority_projection_inconsistent', 'count' => count( $consistency_violations ), 'items' => $consistency_violations );
		if ( $missing > 0 ) $blocking_conditions[] = array( 'code' => 'exact_grants_missing', 'count' => $missing, 'items' => array_slice( $missing_items, 0, 20 ) );
		if ( $stale > 0 ) $blocking_conditions[] = array( 'code' => 'stale_allow_grants', 'count' => $stale, 'items' => array_slice( $stale_items, 0, 20 ) );
		if ( $broad_environment > 0 ) $blocking_conditions[] = array( 'code' => 'broad_environment_grants', 'count' => $broad_environment, 'items' => array_slice( $broad_environment_items, 0, 20 ) );
		if ( $duplicates > 0 ) $blocking_conditions[] = array( 'code' => 'duplicate_exact_allow_grants', 'count' => $duplicates, 'items' => array_slice( $duplicate_items, 0, 20 ) );
		if ( $global_wildcards > 0 ) $blocking_conditions[] = array( 'code' => 'global_registry_wildcard_grants', 'count' => $global_wildcards, 'current_agent_count' => $current_agent_wildcards );
		if ( ! $binding_match ) $blocking_conditions[] = array(
			'code' => 'candidate_binding_mismatch',
			'count' => 1,
			'binding' => array(
				'stored_source_commit_sha' => isset( $binding['stored_source_commit_sha'] ) ? (string) $binding['stored_source_commit_sha'] : '',
				'current_source_commit_sha' => isset( $binding['current_source_commit_sha'] ) ? (string) $binding['current_source_commit_sha'] : '',
				'stored_build_fingerprint' => isset( $binding['stored_build_fingerprint'] ) ? (string) $binding['stored_build_fingerprint'] : '',
				'current_build_fingerprint' => isset( $binding['current_build_fingerprint'] ) ? (string) $binding['current_build_fingerprint'] : '',
			),
		);
		if ( empty( $plan['current_ready'] ) && empty( $blocking_conditions ) ) $blocking_conditions[] = array( 'code' => 'write_authority_not_ready', 'count' => 1 );

		$catalog_fingerprint = hash( 'sha256', wp_json_encode( $catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$runtime_inventory_fingerprint = hash( 'sha256', wp_json_encode( $plan_runtime, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$grant_set_fingerprint = hash( 'sha256', wp_json_encode( $grants, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$candidate_identity = array(
			'stored_source_commit_sha' => isset( $binding['stored_source_commit_sha'] ) ? (string) $binding['stored_source_commit_sha'] : '',
			'current_source_commit_sha' => isset( $binding['current_source_commit_sha'] ) ? (string) $binding['current_source_commit_sha'] : '',
			'stored_build_fingerprint' => isset( $binding['stored_build_fingerprint'] ) ? (string) $binding['stored_build_fingerprint'] : '',
			'current_build_fingerprint' => isset( $binding['current_build_fingerprint'] ) ? (string) $binding['current_build_fingerprint'] : '',
		);
		$candidate_fingerprint = hash( 'sha256', wp_json_encode( $candidate_identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$authority_generation = hash( 'sha256', implode( "\0", array( $catalog_fingerprint, $runtime_inventory_fingerprint, $grant_set_fingerprint, $candidate_fingerprint ) ) );
		$write_ready = ! empty( $plan['current_ready'] )
			&& $projection_consistent
			&& $write_tool_count > 0
			&& count( $grants ) === count( $runtime )
			&& empty( $blocking_conditions );

		$developer_status = class_exists( 'MAD4B_SCP_Developer_Authority' ) ? MAD4B_SCP_Developer_Authority::status() : array();
		$developer_ready = ! empty( $developer_status['developer_enabled'] )
			&& ! empty( $developer_status['direct_execution_enabled'] )
			&& empty( $developer_status['kill_switch_enabled'] )
			&& ! empty( $developer_status['normal_authority']['ready'] );
		$developer_breakglass_ready = $developer_ready
			&& ! empty( $developer_status['breakglass_enabled'] )
			&& ! empty( $developer_status['breakglass_authority']['ready'] );
		$full_staging_authority_ready = $write_ready && $developer_ready && $developer_breakglass_ready;
		$developer_fingerprint = hash( 'sha256', wp_json_encode( array(
			'agent_public_id' => isset( $developer_status['agent_public_id'] ) ? (string) $developer_status['agent_public_id'] : '',
			'developer_enabled' => ! empty( $developer_status['developer_enabled'] ),
			'direct_execution_enabled' => ! empty( $developer_status['direct_execution_enabled'] ),
			'kill_switch_enabled' => ! empty( $developer_status['kill_switch_enabled'] ),
			'normal_ready' => $developer_ready,
			'breakglass_enabled' => ! empty( $developer_status['breakglass_enabled'] ),
			'breakglass_ready' => $developer_breakglass_ready,
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		$projection_fingerprint = hash( 'sha256', wp_json_encode( array(
			'generation' => $authority_generation,
			'blocking_conditions' => $blocking_conditions,
			'provider_gated' => $blocked_rows,
			'authority_gated' => $authority_gated_rows,
			'current_ready' => ! empty( $plan['current_ready'] ),
			'developer_fingerprint' => $developer_fingerprint,
			'full_staging_authority_ready' => $full_staging_authority_ready,
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		$ready = $write_ready;

		return array(
			'contract' => 'mad4b.oauth-consent-grant-projection.v3',
			'read_only' => true,
			'mutation_performed' => false,
			'oauth_scope_changed' => false,
			'write_authority_granted_by_consent' => false,
			'eligible' => ! empty( $plan['eligible'] ),
			'current_ready' => ! empty( $plan['current_ready'] ),
			'ready' => $ready,
			'state' => $ready ? 'ready' : ( ! $projection_consistent ? 'projection_inconsistent' : ( ! empty( $blocking_conditions ) ? 'authority_blocked' : 'read_only_only' ) ),
			'environment' => isset( $plan['environment'] ) ? (string) $plan['environment'] : '',
			'catalog_write_tool_count' => count( $catalog ),
			'runtime_eligible_write_tool_count' => count( $runtime ),
			'provider_gated_write_tool_count' => count( $blocked_rows ),
			'authority_gated_write_tool_count' => count( $authority_gated_rows ),
			'write_tool_count' => $write_tool_count,
			'exact_grants_existing' => count( $grants ),
			'exact_grants_missing_count' => $missing,
			'stale_allow_grants_count' => $stale,
			'broad_environment_grants_count' => $broad_environment,
			'duplicate_exact_allow_grants_count' => $duplicates,
			'current_agent_wildcard_grants' => $current_agent_wildcards,
			'global_registry_wildcard_grants' => $global_wildcards,
			'candidate_binding_required' => $binding_required,
			'candidate_binding_match' => $binding_match,
			'candidate_binding' => array_merge( array( 'required' => $binding_required, 'match' => $binding_match ), $candidate_identity ),
			'projection_consistent' => $projection_consistent,
			'consistency_violations' => $consistency_violations,
			'catalog_fingerprint' => $catalog_fingerprint,
			'runtime_inventory_fingerprint' => $runtime_inventory_fingerprint,
			'grant_set_fingerprint' => $grant_set_fingerprint,
			'candidate_fingerprint' => $candidate_fingerprint,
			'authority_generation' => $authority_generation,
			'projection_fingerprint' => $projection_fingerprint,
			'observed_at' => gmdate( 'c' ),
			'grant_lookup_strategy' => isset( $plan['grant_lookup_strategy'] ) ? (string) $plan['grant_lookup_strategy'] : '',
			'normal_remote_writes_require_exact_approval' => true,
			'developer_authority_ready' => $developer_ready,
			'developer_breakglass_authority_ready' => $developer_breakglass_ready,
			'developer_enabled' => ! empty( $developer_status['developer_enabled'] ),
			'developer_direct_execution_enabled' => ! empty( $developer_status['direct_execution_enabled'] ),
			'developer_kill_switch_enabled' => ! empty( $developer_status['kill_switch_enabled'] ),
			'developer_breakglass_enabled' => ! empty( $developer_status['breakglass_enabled'] ),
			'developer_agent_public_id' => isset( $developer_status['agent_public_id'] ) ? (string) $developer_status['agent_public_id'] : '',
			'full_staging_authority_ready' => $full_staging_authority_ready,
			'generic_raw_sql_breakglass_included' => false,
			'blocking_conditions' => $blocking_conditions,
			'grants' => $grants,
			'provider_gated_catalog_abilities' => $blocked_rows,
			'authority_gated_catalog_abilities' => $authority_gated_rows,
			'blocked_catalog_abilities' => array_values( array_merge( $blocked_rows, $authority_gated_rows ) ),
		);
	}

	public static function consent_user_identity( $user_id ) {
		$user_id = (int) $user_id;
		$user = $user_id > 0 && function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
		$display = $user && isset( $user->display_name ) && '' !== trim( (string) $user->display_name ) ? (string) $user->display_name : ( $user && isset( $user->user_login ) ? (string) $user->user_login : '' );
		return array(
			'contract' => 'mad4b.oauth-consent-user-identity.v1',
			'user_id' => $user_id,
			'display_name' => $display,
			'display_label' => '' !== $display ? $display : __( 'WordPress account', 'mad4b-site-control-plane' ),
			'id_exposed_in_primary_ui' => false,
		);
	}

	public static function ajax_grant_projection() {
		if ( ! is_user_logged_in() ) wp_send_json_error( array( 'code' => 'authentication_required' ), 401 );
		if ( ! self::user_authorized( get_current_user_id() ) ) wp_send_json_error( array( 'code' => 'access_denied' ), 403 );
		check_ajax_referer( 'mad4b_oauth_grant_projection', 'nonce' );
		wp_send_json_success( array(
			'projection' => self::consent_grant_projection(),
			'user' => self::consent_user_identity( get_current_user_id() ),
			'observed_at' => gmdate( 'c' ),
		) );
	}

	private static function render_consent( array $validated, $user_id ) {
		$client_name = isset( $validated['client']['client_name'] ) ? (string) $validated['client']['client_name'] : $validated['client_id'];
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		$script_nonce = rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' );
		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-" . $script_nonce . "'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" );
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
		$step_up_requested = in_array( 'mad4b:authority:step-up', $validated['scopes'], true );
		echo '<p><strong>' . esc_html( $client_name ) . '</strong> ' . esc_html( $step_up_requested
			? __( 'is requesting read access plus a governed Staging authority step-up scope for this WordPress MCP resource.', 'mad4b-site-control-plane' )
			: __( 'is requesting read access to this WordPress MCP resource.', 'mad4b-site-control-plane' )
		) . '</p>';
		$user_identity = self::consent_user_identity( $user_id );
		echo '<p>' . esc_html__( 'Signed in as:', 'mad4b-site-control-plane' ) . ' <strong id="mad4b-oauth-user-label">' . esc_html( (string) $user_identity['display_label'] ) . '</strong></p>';
		echo '<p>' . esc_html__( 'OAuth scopes:', 'mad4b-site-control-plane' ) . ' <code>' . esc_html( implode( ' ', $validated['scopes'] ) ) . '</code></p>';
		if ( $step_up_requested ) {
			echo '<p><strong>' . esc_html__( 'Authority step-up:', 'mad4b-site-control-plane' ) . '</strong> ' .
				esc_html__( 'This OAuth scope only permits ChatGPT to request the composite Full Staging Authority operation. It does not create write grants, Developer authority, Developer Breakglass authority, or Production authority. Execution still requires an exact current plan, matching build/site digests, enrolled administrator identity, explicit confirmation, audit readiness, and all fail-closed governance gates.', 'mad4b-site-control-plane' ) .
				'</p>';
		}
		$grant_projection = self::consent_grant_projection();
		$grant_count = isset( $grant_projection['exact_grants_existing'] ) ? (int) $grant_projection['exact_grants_existing'] : 0;
		$grant_total = isset( $grant_projection['write_tool_count'] ) ? (int) $grant_projection['write_tool_count'] : 0;
		$catalog_count = isset( $grant_projection['catalog_write_tool_count'] ) ? (int) $grant_projection['catalog_write_tool_count'] : 0;
		$runtime_count = isset( $grant_projection['runtime_eligible_write_tool_count'] ) ? (int) $grant_projection['runtime_eligible_write_tool_count'] : $grant_total;
		$blocked_count = isset( $grant_projection['provider_gated_write_tool_count'] ) ? (int) $grant_projection['provider_gated_write_tool_count'] : 0;
		echo '<section class="mad4b-live-grants" id="mad4b-live-authority" aria-label="' . esc_attr__( 'Live governed write authority', 'mad4b-site-control-plane' ) . '">';
		echo '<div class="mad4b-grant-head"><h2>' . esc_html__( 'Live governed write authority', 'mad4b-site-control-plane' ) . '</h2><span id="mad4b-grant-state" class="mad4b-state">' . esc_html( ! empty( $grant_projection['ready'] ) ? __( 'Converged', 'mad4b-site-control-plane' ) : __( 'Fail-closed', 'mad4b-site-control-plane' ) ) . '</span></div>';
		echo '<div class="mad4b-grant-metrics">';
		echo '<div><strong id="mad4b-exact-count">' . esc_html( sprintf( '%d/%d', $grant_count, $runtime_count ) ) . '</strong><span>' . esc_html__( 'exact / runtime eligible', 'mad4b-site-control-plane' ) . '</span></div>';
		echo '<div><strong id="mad4b-catalog-count">' . esc_html( (string) $catalog_count ) . '</strong><span>' . esc_html__( 'governed catalog', 'mad4b-site-control-plane' ) . '</span></div>';
		echo '<div><strong id="mad4b-blocked-count">' . esc_html( (string) $blocked_count ) . '</strong><span>' . esc_html__( 'provider gated', 'mad4b-site-control-plane' ) . '</span></div>';
		echo '</div>';
		echo '<p class="mad4b-grant-note">' . esc_html__( 'This panel is live governance evidence, not an OAuth permission request. OAuth approval cannot create or widen write grants. Every normal remote write still requires a runtime-eligible ability, its exact grant, and a one-time approval.', 'mad4b-site-control-plane' ) . '</p>';
		$blocking_conditions = isset( $grant_projection['blocking_conditions'] ) && is_array( $grant_projection['blocking_conditions'] ) ? $grant_projection['blocking_conditions'] : array();
		$blocker_class = empty( $blocking_conditions ) && ! empty( $grant_projection['ready'] ) ? 'mad4b-grant-blockers mad4b-ok' : 'mad4b-grant-blockers mad4b-warn';
		echo '<div id="mad4b-grant-blockers" class="' . esc_attr( $blocker_class ) . '" aria-live="polite">';
		if ( empty( $blocking_conditions ) ) {
			echo esc_html( ! empty( $grant_projection['ready'] ) ? __( 'Write authority is fully converged for the runtime-eligible surface.', 'mad4b-site-control-plane' ) : __( 'No grant drift detected; write authority remains unavailable for another governed condition.', 'mad4b-site-control-plane' ) );
		} else {
			echo esc_html__( 'Execution remains fail-closed:', 'mad4b-site-control-plane' );
			echo '<ul>';
			foreach ( $blocking_conditions as $condition ) {
				$code = isset( $condition['code'] ) ? sanitize_key( (string) $condition['code'] ) : 'governance_blocker';
				$count = isset( $condition['count'] ) ? (int) $condition['count'] : 0;
				echo '<li><code>' . esc_html( $code ) . '</code>' . ( $count > 0 ? ' (' . esc_html( (string) $count ) . ')' : '' ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
		echo '<details><summary>' . esc_html__( 'Exact granted abilities', 'mad4b-site-control-plane' ) . '</summary><ul id="mad4b-grant-list">';
		foreach ( (array) $grant_projection['grants'] as $grant ) echo '<li><code>' . esc_html( (string) $grant['ability'] ) . '</code> <span>· ' . esc_html( (string) $grant['provider'] ) . '</span></li>';
		echo '</ul></details>';
		echo '<details><summary>' . esc_html__( 'Provider-gated catalog abilities', 'mad4b-site-control-plane' ) . '</summary><ul id="mad4b-blocked-list">';
		foreach ( (array) $grant_projection['blocked_catalog_abilities'] as $entry ) {
			$ability = isset( $entry['ability'] ) ? (string) $entry['ability'] : '';
			$provider = isset( $entry['provider'] ) ? (string) $entry['provider'] : '';
			$reason = isset( $entry['reason'] ) ? (string) $entry['reason'] : 'provider_gated';
			echo '<li><code>' . esc_html( $ability ) . '</code> <span>· ' . esc_html( $provider . ' · ' . $reason ) . '</span></li>';
		}
		echo '</ul></details>';
		echo '<p class="mad4b-live-stamp">' . esc_html__( 'Live read-only authority refresh: immediate on focus/return, then every 15 seconds while visible; paused while hidden.', 'mad4b-site-control-plane' ) . ' <span id="mad4b-observed-at">' . esc_html( isset( $grant_projection['observed_at'] ) ? (string) $grant_projection['observed_at'] : '' ) . '</span></p>';
		echo '</section>';
		$projection_url = admin_url( 'admin-ajax.php' );
		$projection_nonce = wp_create_nonce( 'mad4b_oauth_grant_projection' );
		echo '<script nonce="' . esc_attr( $script_nonce ) . '">(function(){const u=' . wp_json_encode( $projection_url ) . ',nonce=' . wp_json_encode( $projection_nonce ) . ';const BASE=15000,MAX=60000;let timer=null,failures=0,inflight=false,lastFingerprint="";const q=(s)=>document.querySelector(s);const clear=(el)=>{while(el&&el.firstChild)el.removeChild(el.firstChild)};const li=(a,p,r)=>{const n=document.createElement("li"),c=document.createElement("code"),s=document.createElement("span");c.textContent=a||"";s.textContent=" · "+(p||"")+(r?" · "+r:"");n.append(c,s);return n};const blockers=(p)=>{const el=q("#mad4b-grant-blockers");clear(el);const b=Array.isArray(p.blocking_conditions)?p.blocking_conditions:[];if(!b.length){el.className="mad4b-grant-blockers mad4b-ok";el.textContent=p.ready?"Write authority is fully converged for the runtime-eligible surface.":"No grant drift detected; write authority remains unavailable for another governed condition.";return}el.className="mad4b-grant-blockers mad4b-warn";const ul=document.createElement("ul");b.forEach(x=>{const n=document.createElement("li");let t=(x.code||"governance_blocker")+(x.count?" ("+x.count+")":"");if(Array.isArray(x.items)&&x.items.length){const names=x.items.slice(0,4).map(i=>i&&i.ability?i.ability:(typeof i==="string"?i:"")).filter(Boolean);if(names.length)t+=" · "+names.join(", ")+(x.items.length>4?" …":"")}if(x.binding){const s=(x.binding.stored_source_commit_sha||"").slice(0,8),c=(x.binding.current_source_commit_sha||"").slice(0,8);if(s||c)t+=" · "+(s||"unbound")+" → "+(c||"unknown")}n.textContent=t;ul.appendChild(n)});el.append("Execution remains fail-closed: ",ul)};const paint=(d)=>{if(!d||!d.projection)return;const p=d.projection;q("#mad4b-exact-count").textContent=(p.exact_grants_existing||0)+"/"+(p.runtime_eligible_write_tool_count||0);q("#mad4b-catalog-count").textContent=p.catalog_write_tool_count||0;q("#mad4b-blocked-count").textContent=p.provider_gated_write_tool_count||0;const st=q("#mad4b-grant-state");st.textContent=p.ready?"Converged":"Fail-closed";st.className="mad4b-state "+(p.ready?"mad4b-ok-state":"mad4b-block-state");const authority=(id,ready)=>{const e=q(id);if(!e)return;e.textContent=ready?"Allowed":"Blocked";e.className=ready?"mad4b-authority-ready":"mad4b-authority-blocked"};authority("#mad4b-write-state",!!p.ready);authority("#mad4b-developer-state",!!p.developer_authority_ready);authority("#mad4b-developer-breakglass-state",!!p.developer_breakglass_authority_ready);const full=q("#mad4b-full-authority-state");if(full){full.className=p.full_staging_authority_ready?"mad4b-full-ready":"mad4b-full-blocked";full.textContent="Full Staging Authority: "+(p.full_staging_authority_ready?"Ready":"Not fully converged")}blockers(p);const gl=q("#mad4b-grant-list");clear(gl);(p.grants||[]).forEach(x=>gl.appendChild(li(x.ability,x.provider,"")));const bl=q("#mad4b-blocked-list");clear(bl);(p.blocked_catalog_abilities||[]).forEach(x=>bl.appendChild(li(x.ability,x.provider,x.reason||"provider_gated")));if(d.user&&d.user.display_label)q("#mad4b-oauth-user-label").textContent=d.user.display_label;if(p.observed_at)q("#mad4b-observed-at").textContent=p.observed_at};const schedule=(delay)=>{if(timer)clearTimeout(timer);timer=null;if(document.hidden)return;timer=setTimeout(run,delay)};const run=()=>{if(document.hidden||inflight)return;inflight=true;const body=new URLSearchParams();body.set("action","mad4b_oauth_grant_projection");body.set("nonce",nonce);fetch(u,{method:"POST",credentials:"same-origin",cache:"no-store",headers:{"Accept":"application/json","Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:body.toString()}).then(r=>r.ok?r.json():Promise.reject(new Error("http_"+r.status))).then(x=>{if(!x||!x.success||!x.data||!x.data.projection)throw new Error("invalid_projection");failures=0;const fp=x.data.projection.projection_fingerprint||"";if(!fp||fp!==lastFingerprint){paint(x.data);lastFingerprint=fp}schedule(BASE)}).catch(()=>{failures=Math.min(failures+1,3);schedule(Math.min(MAX,BASE*Math.pow(2,failures)))}).finally(()=>{inflight=false})};document.addEventListener("visibilitychange",()=>{if(document.hidden){if(timer)clearTimeout(timer);timer=null}else{run()}});window.addEventListener("focus",()=>{if(!document.hidden)run()});run()})();</script>';
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
		if ( '' === $resource || ! hash_equals( (string) $row['resource'], $resource ) || ! self::resource_allowed( $resource ) ) self::send_oauth_error( 'invalid_target', 'Token request resource does not match.', 400 );
		$challenge = self::base64url_encode( hash( 'sha256', $verifier, true ) );
		if ( ! hash_equals( (string) $row['code_challenge'], $challenge ) ) self::send_oauth_error( 'invalid_grant', 'PKCE verification failed.', 400 );
		if ( ! self::user_authorized( (int) $row['wp_user_id'] ) ) self::send_oauth_error( 'access_denied', 'WordPress subject is no longer authorized.', 403 );
		if ( ! MAD4B_SCP_Local_OAuth_Store::mark_code_used( (int) $row['id'], gmdate( 'Y-m-d H:i:s' ) ) ) self::send_oauth_error( 'invalid_grant', 'Authorization code was already consumed.', 400 );
		$scopes = self::normalize_scopes( (string) $row['scope'], $resource, $client_id );
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
		if ( ! hash_equals( (string) $row['client_id'], $client_id ) || ! hash_equals( (string) $row['resource'], $resource ) || ! self::resource_allowed( $resource ) ) self::send_oauth_error( 'invalid_grant', 'Refresh token binding does not match.', 400 );
		if ( ! self::user_authorized( (int) $row['wp_user_id'] ) ) self::send_oauth_error( 'access_denied', 'WordPress subject is no longer authorized.', 403 );
		$scopes = self::normalize_scopes( (string) $row['scope'], $resource, $client_id );
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

	private static function normalize_scopes( $scope, $resource = '', $client_id = '' ) {
		$scope = trim( (string) $scope );
		if ( strlen( $scope ) > self::MAX_SCOPE_BYTES ) return new WP_Error( 'invalid_scope', 'OAuth scope is too large.' );
		$items = preg_split( '/\s+/', $scope );
		$allowed = array( 'mad4b:read', 'mad4b:authority:step-up', 'server:mad4b-developer', 'server:mad4b-developer-breakglass', 'offline_access' );
		$scopes = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) continue;
			if ( ! in_array( $item, $allowed, true ) ) return new WP_Error( 'invalid_scope', 'OAuth scope is not supported.' );
			$scopes[] = $item;
		}
		$scopes = array_values( array_unique( $scopes ) );
		if ( ! in_array( 'mad4b:read', $scopes, true ) ) return new WP_Error( 'invalid_scope', 'mad4b:read is required.' );

		$resource = untrailingslashit( trim( (string) $resource ) );
		$client_id = trim( (string) $client_id );
		if ( '' !== $resource && class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) {
			$chatgpt = MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier();
			$developer = MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier( 'mad4b-developer' );
			$breakglass = MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier( 'mad4b-developer-breakglass' );
			$has_step_up = in_array( 'mad4b:authority:step-up', $scopes, true );
			$has_developer = in_array( 'server:mad4b-developer', $scopes, true );
			$has_breakglass = in_array( 'server:mad4b-developer-breakglass', $scopes, true );

			if ( $has_step_up ) {
				if ( ! hash_equals( self::CHATGPT_CIMD_CLIENT_ID, $client_id ) ) return new WP_Error( 'invalid_scope', 'Authority step-up scope is reserved for the exact ChatGPT CIMD client.' );
				if ( ! hash_equals( $chatgpt, $resource ) ) return new WP_Error( 'invalid_scope', 'Authority step-up scope is valid only for the canonical ChatGPT resource.' );
				if ( ! MAD4B_SCP_OAuth_Resource_Bridge::authority_step_up_scope_available() ) return new WP_Error( 'invalid_scope', 'Authority step-up scope is unavailable outside exact eligible Staging.' );
			}

			if ( hash_equals( $developer, $resource ) ) {
				if ( ! $has_developer || $has_breakglass || $has_step_up ) return new WP_Error( 'invalid_scope', 'Developer resource requires exactly the normal Developer server scope.' );
			} elseif ( hash_equals( $breakglass, $resource ) ) {
				if ( ! $has_breakglass || $has_developer || $has_step_up ) return new WP_Error( 'invalid_scope', 'Developer Breakglass resource requires exactly the Breakglass server scope.' );
			} elseif ( $has_developer || $has_breakglass ) {
				return new WP_Error( 'invalid_scope', 'Developer scopes cannot be issued for a non-Developer protected resource.' );
			}
		}
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
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_environment() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );
		$profile_ready = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::oauth_enabled();
		if ( ! $profile_ready ) return false;
		if ( in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) return true;
		return 'production' === $environment && self::production_approved();
	}

	private static function effective_for_protocol() {
		$status = self::status();
		return ! empty( $status['effective'] );
	}

	private static function ensure_signing_key() {
		$path = self::private_key_path();
		if ( is_wp_error( $path ) ) return $path;
		if ( is_file( $path ) ) {
			$mode = @fileperms( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bounded local key metadata check.
			if ( false === $mode || 0600 !== ( $mode & 0777 ) ) {
				@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort repair only when permissions drift.
			}
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
		if ( is_array( self::$public_jwk_request_cache ) ) return self::$public_jwk_request_cache;
		$pem = self::private_key_pem();
		if ( is_wp_error( $pem ) ) return $pem;
		$key = openssl_pkey_get_private( $pem );
		unset( $pem );
		if ( false === $key ) return new WP_Error( 'mad4b_local_oauth_private_key_invalid', 'Local OAuth private key is invalid.' );
		$details = openssl_pkey_get_details( $key );
		if ( ! is_array( $details ) || empty( $details['key'] ) || empty( $details['rsa']['n'] ) || empty( $details['rsa']['e'] ) ) return new WP_Error( 'mad4b_local_oauth_public_key_unavailable', 'Unable to derive local OAuth public key.' );
		if ( empty( $details['bits'] ) || (int) $details['bits'] < 2048 ) return new WP_Error( 'mad4b_local_oauth_rsa_key_too_small', 'Local OAuth RSA signing key must be at least 2048 bits.' );
		self::$public_jwk_request_cache = array(
			'kty' => 'RSA',
			'use' => 'sig',
			'key_ops' => array( 'verify' ),
			'alg' => 'RS256',
			'kid' => substr( hash( 'sha256', (string) $details['key'] ), 0, 32 ),
			'n' => self::base64url_encode( $details['rsa']['n'] ),
			'e' => self::base64url_encode( $details['rsa']['e'] ),
		);
		return self::$public_jwk_request_cache;
	}

	private static function absolute_path( $path ) {
		return 1 === preg_match( '#^(?:[A-Za-z]:[\\\\/]|/)#', (string) $path );
	}

	private static function configured_issuer_validation() {
		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_ISSUER' ) ) return self::site_base_url() . self::ISSUER_PATH;
		$configured = rtrim( trim( (string) constant( 'MAD4B_MCP_LOCAL_OAUTH_ISSUER' ) ), '/' );
		if ( strlen( $configured ) > self::MAX_URI_BYTES || ! self::issuer_transport_allowed( $configured ) ) return new WP_Error( 'mad4b_local_oauth_issuer_invalid', 'Configured local OAuth issuer must be HTTPS, except bounded HTTP loopback in the local environment.' );
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


	private static function issuer_transport_allowed( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) return false;
		$scheme = strtolower( (string) $parts['scheme'] );
		$host = strtolower( (string) $parts['host'] );
		if ( 'https' === $scheme ) return true;
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_environment() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );
		return 'local' === $environment && 'http' === $scheme && in_array( $host, array( '127.0.0.1', '::1', 'localhost' ), true );
	}

	private static function valid_https_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && 'https' === strtolower( (string) $parts['scheme'] ) && '' !== (string) $parts['host'] && empty( $parts['user'] ) && empty( $parts['pass'] ) && empty( $parts['query'] ) && empty( $parts['fragment'] );
	}

	private static function origin() {
		$parts = wp_parse_url( self::site_base_url() );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
		$origin = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) $origin .= ':' . (int) $parts['port'];
		return $origin;
	}

	private static function site_base_url() {
		$parts = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
		$base = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) $base .= ':' . (int) $parts['port'];
		$path = isset( $parts['path'] ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '';
		$path = '/' === $path ? '' : rtrim( $path, '/' );
		return $base . $path;
	}

	private static function protocol_path( $url_or_path ) {
		$value = (string) $url_or_path;
		$path = wp_parse_url( $value, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) $path = $value;
		$path = '/' . ltrim( (string) $path, '/' );
		return '/' === $path ? '/' : rtrim( $path, '/' );
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
