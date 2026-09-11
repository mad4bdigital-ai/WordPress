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
	const CONTRACT = 'mad4b.external-handshake-evidence.v2';
	const OPTION = 'mad4b_scp_external_handshake_evidence';
	const OBSERVER_ATTESTATION_OPTION = 'mad4b_scp_external_inventory_attestation_v1';
	const PENDING_PREFIX = 'mad4b_ext_hs_';
	const PENDING_TTL = 900;
	const MAX_EVIDENCE_AGE = 2592000; // 30 days.
	const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
	const SERVER_ID = 'mad4b-chatgpt';
	const REQUIRED_SCOPE = 'mad4b:read';
	const FINALIZER_SESSION_SKEW = 5;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'observe_rest_response' ), PHP_INT_MAX, 3 );
		// The Live Acceptance observer stores its own exact-inventory attestation.
		// During an authenticated remote finalizer request, expose that attestation
		// only when it can be joined to this class's authoritative handshake from
		// the same OAuth subject/authority and the same tools/list observation.
		add_filter( 'option_' . self::OBSERVER_ATTESTATION_OPTION, array( __CLASS__, 'bind_observer_attestation_to_current_finalizer' ), PHP_INT_MAX, 1 );
	}

	public static function observe_rest_response( $response, $server, $request ) {
		if ( ! self::capture_runtime_allowed( $request ) ) return $response;
		$method = self::jsonrpc_method( $request );
		if ( 'initialize' === $method ) self::capture_initialize( $response, $request );
		elseif ( 'tools/list' === $method ) self::capture_tools_list( $response, $request );
		return $response;
	}

	public static function bind_observer_attestation_to_current_finalizer( $attestation ) {
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return $attestation;
		if ( ! is_array( $attestation ) || empty( $attestation ) ) return array();
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : null;
		if ( is_wp_error( $identity ) || ! is_array( $identity ) ) return array();
		$oauth = MAD4B_SCP_OAuth_Resource_Bridge::status();
		$handshake = get_option( self::OPTION, array() );
		$status = self::status();
		if ( ! is_array( $handshake ) ) $handshake = array();
		$handshake['verified'] = is_array( $status ) && ! empty( $status['verified'] );
		if ( ! self::observer_attestation_matches_finalizer_context( $attestation, $handshake, $identity, is_array( $oauth ) ? $oauth : array() ) ) return array();
		$attestation['finalizer_subject_binding_verified'] = true;
		$attestation['finalizer_context_digest'] = self::finalizer_context_digest( $attestation, $handshake );
		return $attestation;
	}

	/** @internal Pure finalizer-binding evaluator used by regression tests. */
	public static function observer_attestation_matches_finalizer_context( array $attestation, array $handshake, array $identity, array $oauth ) {
		if ( empty( $handshake['verified'] ) || empty( $attestation['real_external_session'] ) ) return false;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return false;
		$subject = isset( $identity['subject_fingerprint'] ) ? strtolower( trim( (string) $identity['subject_fingerprint'] ) ) : '';
		$handshake_subject = isset( $handshake['subject_fingerprint'] ) ? strtolower( trim( (string) $handshake['subject_fingerprint'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $subject ) || ! preg_match( '/^[a-f0-9]{64}$/', $handshake_subject ) || ! hash_equals( $handshake_subject, $subject ) ) return false;
		$identity_scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? array_values( array_unique( array_map( 'strval', $identity['token_scopes'] ) ) ) : array();
		$handshake_scopes = isset( $handshake['scope_set'] ) && is_array( $handshake['scope_set'] ) ? array_values( array_unique( array_map( 'strval', $handshake['scope_set'] ) ) ) : array();
		if ( ! in_array( self::REQUIRED_SCOPE, $identity_scopes, true ) || ! in_array( self::REQUIRED_SCOPE, $handshake_scopes, true ) ) return false;
		if ( 'oauth2_bearer' !== ( isset( $handshake['auth_method'] ) ? (string) $handshake['auth_method'] : '' ) ) return false;
		if ( empty( $identity['wp_user_id'] ) || empty( $handshake['wp_user_id'] ) || (int) $identity['wp_user_id'] !== (int) $handshake['wp_user_id'] ) return false;
		if ( self::SERVER_ID !== ( isset( $handshake['server_id'] ) ? (string) $handshake['server_id'] : '' ) || self::SERVER_ID !== ( isset( $attestation['server_id'] ) ? (string) $attestation['server_id'] : '' ) ) return false;
		if ( self::CHATGPT_CLIENT_ID !== ( isset( $handshake['client_id'] ) ? (string) $handshake['client_id'] : '' ) || self::CHATGPT_CLIENT_ID !== ( isset( $attestation['client_id'] ) ? (string) $attestation['client_id'] : '' ) ) return false;
		$issuer = isset( $oauth['issuer'] ) ? rtrim( (string) $oauth['issuer'], '/' ) : '';
		$resource = isset( $oauth['resource'] ) ? untrailingslashit( (string) $oauth['resource'] ) : '';
		$handshake_issuer = isset( $handshake['issuer'] ) ? rtrim( (string) $handshake['issuer'], '/' ) : '';
		$handshake_resource = isset( $handshake['resource'] ) ? untrailingslashit( (string) $handshake['resource'] ) : '';
		if ( '' === $issuer || '' === $resource || '' === $handshake_issuer || '' === $handshake_resource ) return false;
		if ( ! hash_equals( $issuer, $handshake_issuer ) || ! hash_equals( $resource, $handshake_resource ) ) return false;
		$observer_inventory = isset( $attestation['external_tool_inventory_fingerprint'] ) ? strtolower( trim( (string) $attestation['external_tool_inventory_fingerprint'] ) ) : '';
		$handshake_inventory = isset( $handshake['tool_inventory_fingerprint'] ) ? strtolower( trim( (string) $handshake['tool_inventory_fingerprint'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $observer_inventory ) || ! preg_match( '/^[a-f0-9]{64}$/', $handshake_inventory ) || ! hash_equals( $handshake_inventory, $observer_inventory ) ) return false;
		if ( ! isset( $attestation['external_tool_count'], $handshake['tool_count'] ) || (int) $attestation['external_tool_count'] !== (int) $handshake['tool_count'] ) return false;
		if ( ! isset( $attestation['external_write_tool_count'], $handshake['write_tool_count'] ) || (int) $attestation['external_write_tool_count'] !== (int) $handshake['write_tool_count'] ) return false;
		$observer_time = ! empty( $attestation['observed_at'] ) ? strtotime( (string) $attestation['observed_at'] . ' UTC' ) : false;
		$handshake_time = ! empty( $handshake['verified_at'] ) ? strtotime( (string) $handshake['verified_at'] . ' UTC' ) : false;
		if ( false === $observer_time || false === $handshake_time || abs( $observer_time - $handshake_time ) > self::FINALIZER_SESSION_SKEW ) return false;
		return true;
	}

	private static function finalizer_context_digest( array $attestation, array $handshake ) {
		$parts = array(
			isset( $handshake['subject_fingerprint'] ) ? strtolower( (string) $handshake['subject_fingerprint'] ) : '',
			isset( $handshake['issuer'] ) ? rtrim( (string) $handshake['issuer'], '/' ) : '',
			isset( $handshake['resource'] ) ? untrailingslashit( (string) $handshake['resource'] ) : '',
			isset( $handshake['client_id'] ) ? (string) $handshake['client_id'] : '',
			isset( $handshake['mcp_session_fingerprint'] ) ? strtolower( (string) $handshake['mcp_session_fingerprint'] ) : '',
			isset( $attestation['external_tool_inventory_fingerprint'] ) ? strtolower( (string) $attestation['external_tool_inventory_fingerprint'] ) : '',
			isset( $attestation['observed_at'] ) ? (string) $attestation['observed_at'] : '',
		);
		return hash( 'sha256', implode( "\n", $parts ) );
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
			'expected_tool_count' => 0,
			'write_tool_count' => 0,
			'expected_write_tool_count' => 0,
			'tool_inventory_fingerprint' => '',
			'expected_tool_inventory_fingerprint' => '',
			'tool_inventory_match' => false,
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
		$stored_inventory_fingerprint = isset( $evidence['tool_inventory_fingerprint'] ) ? strtolower( trim( (string) $evidence['tool_inventory_fingerprint'] ) ) : '';
		$expected_tool_names = self::expected_tool_names();
		$expected_write_names = self::expected_write_tool_names();
		$expected_inventory_fingerprint = self::inventory_fingerprint( $expected_tool_names );
		$inventory_match = '' !== $expected_inventory_fingerprint
			&& preg_match( '/^[a-f0-9]{64}$/', $stored_inventory_fingerprint )
			&& hash_equals( $expected_inventory_fingerprint, $stored_inventory_fingerprint );
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
		$base['expected_tool_count'] = count( $expected_tool_names );
		$base['write_tool_count'] = isset( $evidence['write_tool_count'] ) ? max( 0, (int) $evidence['write_tool_count'] ) : 0;
		$base['expected_write_tool_count'] = count( $expected_write_names );
		$base['tool_inventory_fingerprint'] = preg_match( '/^[a-f0-9]{64}$/', $stored_inventory_fingerprint ) ? $stored_inventory_fingerprint : '';
		$base['expected_tool_inventory_fingerprint'] = $expected_inventory_fingerprint;
		$base['tool_inventory_match'] = (bool) $inventory_match;
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
		if ( ! $inventory_match ) { $base['status'] = 'stale_tool_inventory_evidence'; return $base; }
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
		$names = self::normalize_tool_names( $names );
		if ( empty( $names ) ) return;

		// A successful external handshake must prove the exact currently governed
		// ChatGPT projection. Historical read-only deny-lists are invalid once the
		// exact-origin Staging write authority intentionally exposes certified core
		// mutations. Exact equality also fails closed on raw SQL, Breakglass, blocked
		// provider mutations, missing writes, and any foreign/unexpected tool.
		$expected_names = self::expected_tool_names();
		if ( empty( $expected_names ) ) return;
		$actual_fingerprint = self::inventory_fingerprint( $names );
		$expected_fingerprint = self::inventory_fingerprint( $expected_names );
		if ( '' === $actual_fingerprint || '' === $expected_fingerprint || ! hash_equals( $expected_fingerprint, $actual_fingerprint ) ) return;

		$write_names = self::expected_write_tool_names();
		$observed_write_names = array_values( array_intersect( $names, $write_names ) );
		if ( count( $observed_write_names ) !== count( $write_names ) ) return;

		$blocked_names = self::blocked_write_tool_names();
		if ( ! empty( array_intersect( $names, $blocked_names ) ) ) return;
		$breakglass_names = self::breakglass_tool_names();
		if ( ! empty( array_intersect( $names, $breakglass_names ) ) ) return;

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
			'write_tool_count' => count( $observed_write_names ),
			'tool_inventory_fingerprint' => $actual_fingerprint,
			'initialized_at' => isset( $pending['initialized_at'] ) ? sanitize_text_field( (string) $pending['initialized_at'] ) : '',
			'verified_at' => gmdate( 'Y-m-d H:i:s' ),
			'build_fingerprint' => self::build_fingerprint(),
		);
		update_option( self::OPTION, $evidence, false );
		delete_transient( self::pending_key( $session_fingerprint ) );
	}

	private static function normalize_tool_names( array $names ) {
		$names = array_values( array_unique( array_filter( array_map( static function ( $name ) {
			return is_string( $name ) ? trim( $name ) : '';
		}, $names ) ) ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	private static function ability_to_mcp_tool_name( $ability_name ) {
		if ( ! class_exists( '\\WP\\MCP\\Domain\\Utils\\McpNameSanitizer' ) ) return '';
		$name = \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( (string) $ability_name );
		if ( is_wp_error( $name ) || ! is_string( $name ) || '' === trim( $name ) ) return '';
		return trim( $name );
	}

	private static function ability_names_to_mcp_tool_names( array $ability_names ) {
		$names = array();
		foreach ( $ability_names as $ability_name ) {
			$name = self::ability_to_mcp_tool_name( $ability_name );
			if ( '' === $name ) return array();
			$names[] = $name;
		}
		return self::normalize_tool_names( $names );
	}

	private static function expected_tool_names() {
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) return array();
		$abilities = MAD4B_SCP_Servers::chatgpt_tools();
		return is_array( $abilities ) ? self::ability_names_to_mcp_tool_names( $abilities ) : array();
	}

	private static function expected_write_tool_names() {
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) return array();
		$abilities = MAD4B_SCP_Servers::write_tools();
		return is_array( $abilities ) ? self::ability_names_to_mcp_tool_names( $abilities ) : array();
	}

	private static function blocked_write_tool_names() {
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) return array();
		$blocked = MAD4B_SCP_Servers::blocked_write_tools();
		if ( ! is_array( $blocked ) ) return array();
		$abilities = array();
		foreach ( $blocked as $entry ) {
			if ( is_array( $entry ) && isset( $entry['ability'] ) ) $abilities[] = (string) $entry['ability'];
		}
		return self::ability_names_to_mcp_tool_names( $abilities );
	}

	private static function breakglass_tool_names() {
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) return array();
		$abilities = MAD4B_SCP_Servers::core_tools( 'mad4b-breakglass' );
		return is_array( $abilities ) ? self::ability_names_to_mcp_tool_names( $abilities ) : array();
	}

	private static function inventory_fingerprint( array $names ) {
		$names = self::normalize_tool_names( $names );
		if ( empty( $names ) ) return '';
		return hash( 'sha256', implode( "\n", $names ) );
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
