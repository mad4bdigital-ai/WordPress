<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable, non-secret evidence of a real external ChatGPT MCP session.
 *
 * Certification requires two successful requests on the same MCP session:
 * initialize -> tools/list. Raw bearer tokens and raw MCP session ids are never
 * persisted. WP-CLI, cron and internal rest_do_request probes cannot certify.
 */
final class MAD4B_SCP_External_Handshake_Evidence {
	const CONTRACT = 'mad4b.external-handshake-evidence.v1';
	const OPTION = 'mad4b_scp_external_handshake_evidence';
	const PENDING_PREFIX = 'mad4b_ext_hs_';
	const PENDING_TTL = 900;
	const MAX_EVIDENCE_AGE = 2592000; // 30 days.
	const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
	const SERVER_ID = 'mad4b-chatgpt';
	const REQUIRED_SCOPE = 'mad4b:read';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'observe_rest_response' ), PHP_INT_MAX, 3 );
	}

	public static function observe_rest_response( $response, $server, $request ) {
		if ( ! self::capture_runtime_allowed( $request ) ) return $response;
		$method = self::jsonrpc_method( $request );
		if ( 'initialize' === $method ) self::capture_initialize( $response, $request );
		elseif ( 'tools/list' === $method ) self::capture_tools_list( $response, $request );
		return $response;
	}

	public static function status() {
		$evidence = get_option( self::OPTION, array() );
		$base = array(
			'contract' => self::CONTRACT,
			'verified' => false,
			'status' => 'unverified',
			'environment' => function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown',
			'server_id' => self::SERVER_ID,
			'client_id' => self::CHATGPT_CLIENT_ID,
			'resource' => class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? esc_url_raw( MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier() ) : '',
			'issuer' => '',
			'auth_method' => '',
			'wp_user_id' => 0,
			'subject_fingerprint_present' => false,
			'scope_set' => array(),
			'mcp_session_fingerprint_present' => false,
			'tool_count' => 0,
			'verified_at' => '',
			'age_seconds' => 0,
			'build_fingerprint_match' => false,
			'credential_material_stored' => false,
		);
		if ( ! is_array( $evidence ) || empty( $evidence ) ) return $base;

		$environment = isset( $evidence['environment'] ) ? sanitize_key( (string) $evidence['environment'] ) : '';
		$resource = isset( $evidence['resource'] ) ? esc_url_raw( (string) $evidence['resource'] ) : '';
		$issuer = isset( $evidence['issuer'] ) ? esc_url_raw( (string) $evidence['issuer'] ) : '';
		$client_id = isset( $evidence['client_id'] ) ? esc_url_raw( (string) $evidence['client_id'] ) : '';
		$server_id = isset( $evidence['server_id'] ) ? sanitize_key( (string) $evidence['server_id'] ) : '';
		$auth_method = isset( $evidence['auth_method'] ) ? sanitize_key( (string) $evidence['auth_method'] ) : '';
		$wp_user_id = isset( $evidence['wp_user_id'] ) ? absint( $evidence['wp_user_id'] ) : 0;
		$subject_fingerprint = isset( $evidence['subject_fingerprint'] ) ? strtolower( trim( (string) $evidence['subject_fingerprint'] ) ) : '';
		$session_fingerprint = isset( $evidence['mcp_session_fingerprint'] ) ? strtolower( trim( (string) $evidence['mcp_session_fingerprint'] ) ) : '';
		$scope_set = isset( $evidence['scope_set'] ) && is_array( $evidence['scope_set'] ) ? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $evidence['scope_set'] ) ) ) ) : array();
		$tool_count = isset( $evidence['tool_count'] ) ? max( 0, (int) $evidence['tool_count'] ) : 0;
		$verified_at = isset( $evidence['verified_at'] ) ? sanitize_text_field( (string) $evidence['verified_at'] ) : '';
		$verified_ts = '' !== $verified_at ? strtotime( $verified_at . ' UTC' ) : false;
		$age = false === $verified_ts ? PHP_INT_MAX : max( 0, time() - $verified_ts );
		$current_build = self::build_fingerprint();
		$stored_build = isset( $evidence['build_fingerprint'] ) ? strtolower( trim( (string) $evidence['build_fingerprint'] ) ) : '';
		$build_match = '' !== $current_build && preg_match( '/^[a-f0-9]{64}$/', $stored_build ) && hash_equals( $current_build, $stored_build );

		$base['environment'] = $environment;
		$base['resource'] = $resource;
		$base['issuer'] = $issuer;
		$base['client_id'] = $client_id;
		$base['server_id'] = $server_id;
		$base['auth_method'] = $auth_method;
		$base['wp_user_id'] = $wp_user_id;
		$base['subject_fingerprint_present'] = (bool) preg_match( '/^[a-f0-9]{64}$/', $subject_fingerprint );
		$base['scope_set'] = $scope_set;
		$base['mcp_session_fingerprint_present'] = (bool) preg_match( '/^[a-f0-9]{64}$/', $session_fingerprint );
		$base['tool_count'] = $tool_count;
		$base['verified_at'] = $verified_at;
		$base['age_seconds'] = PHP_INT_MAX === $age ? 0 : $age;
		$base['build_fingerprint_match'] = (bool) $build_match;

		$current_environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$current_resource = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier() : '';
		if ( 'staging' !== $current_environment || 'staging' !== $environment ) { $base['status'] = 'environment_mismatch'; return $base; }
		if ( self::SERVER_ID !== $server_id || ! hash_equals( self::CHATGPT_CLIENT_ID, $client_id ) ) { $base['status'] = 'client_or_server_mismatch'; return $base; }
		if ( '' === $current_resource || ! hash_equals( $current_resource, $resource ) ) { $base['status'] = 'resource_mismatch'; return $base; }
		if ( 'oauth2_bearer' !== $auth_method || $wp_user_id < 1 || ! $base['subject_fingerprint_present'] || ! $base['mcp_session_fingerprint_present'] ) { $base['status'] = 'subject_or_session_invalid'; return $base; }
		if ( ! in_array( self::REQUIRED_SCOPE, $scope_set, true ) || $tool_count < 1 || '' === $issuer ) { $base['status'] = 'handshake_incomplete'; return $base; }
		if ( ! $build_match ) { $base['status'] = 'stale_build_evidence'; return $base; }
		if ( $age > self::MAX_EVIDENCE_AGE ) { $base['status'] = 'stale_time_evidence'; return $base; }

		$base['verified'] = true;
		$base['status'] = 'verified_external_chatgpt_session';
		return $base;
	}

	public static function build_fingerprint() {
		$files = array(
			defined( 'MAD4B_SCP_FILE' ) ? MAD4B_SCP_FILE : '',
			defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-resource-bridge.php' : '',
			defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'includes/class-mad4b-scp-external-handshake-evidence.php' : '',
			defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-status.php' : '',
			defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-peer-governance.php' : '',
			defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-provider-isolation.php' : '',
			defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'includes/class-mad4b-scp-servers.php' : '',
		);
		$ctx = hash_init( 'sha256' );
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || '' === $file || ! is_readable( $file ) ) return '';
			$digest = hash_file( 'sha256', $file );
			if ( ! is_string( $digest ) || '' === $digest ) return '';
			hash_update( $ctx, basename( $file ) . "\0" . $digest . "\0" );
		}
		return hash_final( $ctx );
	}

	private static function capture_runtime_allowed( $request ) {
		if ( ! defined( 'REST_REQUEST' ) || true !== REST_REQUEST ) return false;
		if ( defined( 'WP_CLI' ) && WP_CLI ) return false;
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) return false;
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return false;
		$route = '/' . ltrim( rtrim( (string) $request->get_route(), '/' ), '/' );
		if ( '/mcp/' . self::SERVER_ID !== $route ) return false;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return false;
		if ( ! function_exists( 'wp_is_using_https' ) || ! wp_is_using_https() ) return false;
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		return 'staging' === $environment;
	}

	private static function capture_initialize( $response, $request ) {
		$response = rest_ensure_response( $response );
		if ( ! $response instanceof WP_REST_Response || 200 !== (int) $response->get_status() ) return;
		$data = self::normalize_value( $response->get_data() );
		if ( ! is_array( $data ) || isset( $data['error'] ) || empty( $data['result']['capabilities']['tools'] ) ) return;
		if ( empty( $data['result']['serverInfo']['name'] ) || 'MAD4B ChatGPT MCP' !== (string) $data['result']['serverInfo']['name'] ) return;
		$session_id = self::response_session_id( $response );
		if ( '' === $session_id ) return;
		$context = self::verified_request_context( $request );
		if ( ! is_array( $context ) ) return;
		$context['mcp_session_fingerprint'] = hash( 'sha256', $session_id );
		$context['initialized_at'] = gmdate( 'Y-m-d H:i:s' );
		$context['build_fingerprint'] = self::build_fingerprint();
		if ( '' === $context['build_fingerprint'] ) return;
		set_transient( self::pending_key( $context['mcp_session_fingerprint'] ), $context, self::PENDING_TTL );
	}

	private static function capture_tools_list( $response, $request ) {
		$response = rest_ensure_response( $response );
		if ( ! $response instanceof WP_REST_Response || 200 !== (int) $response->get_status() ) return;
		$session_id = method_exists( $request, 'get_header' ) ? trim( (string) $request->get_header( 'mcp-session-id' ) ) : '';
		if ( '' === $session_id || strlen( $session_id ) > 512 ) return;
		$session_fingerprint = hash( 'sha256', $session_id );
		$pending = get_transient( self::pending_key( $session_fingerprint ) );
		if ( ! is_array( $pending ) || empty( $pending['mcp_session_fingerprint'] ) || ! hash_equals( $pending['mcp_session_fingerprint'], $session_fingerprint ) ) return;
		$current = self::verified_request_context( $request );
		if ( ! is_array( $current ) || ! self::same_context( $pending, $current ) ) return;
		if ( empty( $pending['build_fingerprint'] ) || ! hash_equals( self::build_fingerprint(), (string) $pending['build_fingerprint'] ) ) return;

		$data = self::normalize_value( $response->get_data() );
		$tools = is_array( $data ) && isset( $data['result']['tools'] ) && is_array( $data['result']['tools'] ) ? $data['result']['tools'] : array();
		if ( empty( $tools ) ) return;
		$names = array();
		foreach ( $tools as $tool ) if ( is_array( $tool ) && isset( $tool['name'] ) && is_string( $tool['name'] ) ) $names[] = $tool['name'];
		$names = array_values( array_unique( $names ) );
		if ( empty( $names ) ) return;
		foreach ( array( 'mad4b-filesystem-read', 'mad4b-database-select', 'mad4b-database-update', 'mad4b-content-update-post', 'mad4b-plugin-activate', 'mad4b-mutation-undo' ) as $forbidden ) {
			if ( in_array( $forbidden, $names, true ) ) return;
		}

		$evidence = array(
			'contract' => self::CONTRACT,
			'environment' => $current['environment'],
			'server_id' => self::SERVER_ID,
			'resource' => $current['resource'],
			'issuer' => $current['issuer'],
			'client_id' => $current['client_id'],
			'auth_method' => $current['auth_method'],
			'wp_user_id' => $current['wp_user_id'],
			'subject_fingerprint' => $current['subject_fingerprint'],
			'scope_set' => $current['scope_set'],
			'mcp_session_fingerprint' => $session_fingerprint,
			'tool_count' => count( $names ),
			'initialized_at' => isset( $pending['initialized_at'] ) ? sanitize_text_field( (string) $pending['initialized_at'] ) : '',
			'verified_at' => gmdate( 'Y-m-d H:i:s' ),
			'build_fingerprint' => self::build_fingerprint(),
		);
		update_option( self::OPTION, $evidence, false );
		delete_transient( self::pending_key( $session_fingerprint ) );
	}

	private static function verified_request_context( $request ) {
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : null;
		$oauth = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		if ( ! is_array( $identity ) || empty( $identity['authenticated'] ) || 'oauth2_bearer' !== (string) $identity['auth_method'] ) return null;
		if ( empty( $identity['subject_fingerprint'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $identity['subject_fingerprint'] ) ) return null;
		$scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? array_values( array_unique( array_map( 'sanitize_text_field', $identity['token_scopes'] ) ) ) : array();
		if ( ! in_array( self::REQUIRED_SCOPE, $scopes, true ) ) return null;
		$claims = self::verified_jwt_claims( $request );
		if ( ! is_array( $claims ) ) return null;
		$issuer = isset( $oauth['issuer'] ) ? rtrim( (string) $oauth['issuer'], '/' ) : '';
		$resource = isset( $oauth['resource'] ) ? untrailingslashit( (string) $oauth['resource'] ) : '';
		if ( '' === $issuer || '' === $resource ) return null;
		if ( empty( $claims['iss'] ) || ! is_string( $claims['iss'] ) || ! hash_equals( $issuer, rtrim( $claims['iss'], '/' ) ) ) return null;
		if ( empty( $claims['resource'] ) || ! is_string( $claims['resource'] ) || ! hash_equals( $resource, untrailingslashit( $claims['resource'] ) ) ) return null;
		$client_id = isset( $claims['client_id'] ) && is_string( $claims['client_id'] ) ? trim( $claims['client_id'] ) : '';
		if ( ! hash_equals( self::CHATGPT_CLIENT_ID, $client_id ) ) return null;

		return array(
			'environment' => 'staging',
			'resource' => $resource,
			'issuer' => $issuer,
			'client_id' => $client_id,
			'auth_method' => 'oauth2_bearer',
			'wp_user_id' => isset( $identity['wp_user_id'] ) ? absint( $identity['wp_user_id'] ) : 0,
			'subject_fingerprint' => strtolower( (string) $identity['subject_fingerprint'] ),
			'scope_set' => $scopes,
		);
	}

	private static function verified_jwt_claims( $request ) {
		$authorization = method_exists( $request, 'get_header' ) ? trim( (string) $request->get_header( 'authorization' ) ) : '';
		if ( ! preg_match( '/^Bearer\s+([^\s]+)$/i', $authorization, $matches ) ) return null;
		$parts = explode( '.', $matches[1] );
		unset( $matches, $authorization );
		if ( 3 !== count( $parts ) || strlen( $parts[1] ) > 32768 ) return null;
		$payload = self::base64url_decode( $parts[1] );
		unset( $parts );
		if ( ! is_string( $payload ) || '' === $payload || strlen( $payload ) > 24576 ) return null;
		$claims = json_decode( $payload, true );
		unset( $payload );
		return is_array( $claims ) ? $claims : null;
	}

	private static function jsonrpc_method( $request ) {
		$params = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;
		if ( ! is_array( $params ) || empty( $params['method'] ) || ! is_string( $params['method'] ) ) return '';
		return trim( $params['method'] );
	}

	private static function response_session_id( $response ) {
		$headers = $response->get_headers();
		foreach ( is_array( $headers ) ? $headers : array() as $name => $value ) {
			if ( 'mcp-session-id' !== strtolower( (string) $name ) ) continue;
			$value = is_array( $value ) ? reset( $value ) : $value;
			$value = is_string( $value ) ? trim( $value ) : '';
			return strlen( $value ) <= 512 ? $value : '';
		}
		return '';
	}

	private static function pending_key( $session_fingerprint ) {
		return self::PENDING_PREFIX . substr( (string) $session_fingerprint, 0, 40 );
	}

	private static function same_context( array $left, array $right ) {
		foreach ( array( 'environment', 'resource', 'issuer', 'client_id', 'auth_method', 'subject_fingerprint' ) as $key ) {
			if ( ! isset( $left[ $key ], $right[ $key ] ) || ! is_string( $left[ $key ] ) || ! is_string( $right[ $key ] ) || ! hash_equals( $left[ $key ], $right[ $key ] ) ) return false;
		}
		return isset( $left['wp_user_id'], $right['wp_user_id'] ) && (int) $left['wp_user_id'] === (int) $right['wp_user_id'];
	}

	private static function normalize_value( $value ) {
		$encoded = wp_json_encode( $value );
		if ( false === $encoded ) return null;
		return json_decode( $encoded, true );
	}

	private static function base64url_decode( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) return null;
		$pad = strlen( $value ) % 4;
		if ( $pad ) $value .= str_repeat( '=', 4 - $pad );
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );
		return false === $decoded ? null : $decoded;
	}
}
