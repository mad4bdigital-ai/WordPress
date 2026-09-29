<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact ChatGPT reconnect hardening layered over Upgrade Continuity.
 *
 * This component never grants write, Production or Breakglass authority. It
 * narrows reconnect readiness to the exact mad4b-chatgpt registration and
 * converts an otherwise ambiguous REST 404 into bounded 503 diagnostics.
 */
final class MAD4B_SCP_Reconnect_Hardening {
	const CONTRACT = 'mad4b.reconnect-hardening.v4';
	const SESSION_SHADOW_TTL = 120;
	const SESSION_DELETE_TOMBSTONE_TTL = 180;
	const SESSION_META_KEY = 'mcp_adapter_sessions';
	const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
	const CERTIFIED_STATEFUL_ADAPTER_VERSION = '0.6.1';
	private static $booted = false;
	private static $session_repair_state = 'not_attempted';
	private static $initialize_empty_requests = array();

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		// Upgrade Continuity owns the migration and governance checks; replace only
		// the reconnect projection/notice so unrelated MCP servers cannot block ChatGPT.
		remove_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_Upgrade_Continuity', 'register_abilities' ), 34 );
		remove_action( 'admin_notices', array( 'MAD4B_SCP_Upgrade_Continuity', 'connection_admin_notice' ), 1 );
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ), 34 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );
		add_action( 'admin_notices', array( __CLASS__, 'connection_admin_notice' ), 1 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'reset_session_policy_scope' ), -200, 3 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_mcp_rest_dispatch' ), -100, 3 );

		// MCP Adapter 0.6.x uses a user-meta backed session map shared by MCP servers.
		// Do not override its global capacity/timeout knobs from a ChatGPT-only fix;
		// bounded recovery below addresses only the proven first-session race.
		// Run after OAuth subject mapping (priority 2), before the MCP Adapter
		// callback validates the session. Only verified read requests may use the
		// bounded shadow to repair a just-lost transport session.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'repair_or_forget_session' ), 3, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'capture_initialized_session' ), 900, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'clear_session_policy_scope' ), PHP_INT_MAX, 3 );
	}


	private static function governed_nonproduction_transport() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return false;
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::managed_runtime_enabled() ) return false;
		$environment = MAD4B_SCP_Site_Profile::current_environment();
		return in_array( $environment, array( 'local', 'development', 'staging' ), true );
	}


	private static function request_scope_key( $request ) {
		return is_object( $request ) ? spl_object_hash( $request ) : '';
	}

	public static function reset_session_policy_scope( $result, $server, $request ) {
		$key = self::request_scope_key( $request );
		if ( '' !== $key ) unset( self::$initialize_empty_requests[ $key ] );
		return $result;
	}

	public static function clear_session_policy_scope( $response, $server, $request ) {
		$key = self::request_scope_key( $request );
		if ( '' !== $key ) unset( self::$initialize_empty_requests[ $key ] );
		return $response;
	}

	public static function session_continuity_policy() {
		return array(
			'contract' => self::CONTRACT,
			'governed_nonproduction' => self::governed_nonproduction_transport(),
			'request_scope_reset_before_reconnect_guard' => true,
			'request_scope_reset_before_oauth_dispatch' => true,
			'request_scope_cleared_after_dispatch' => true,
			'request_scope_bound_to_exact_request_object' => true,
			'nested_rest_request_cannot_clear_outer_initialize_state' => true,
			'certified_stateful_runtime' => self::session_repair_supported_runtime(),
			'adapter_session_max_modified' => false,
			'adapter_inactivity_timeout_modified' => false,
			'activity_update_interval_modified' => false,
			'stateful_http_session_compatibility' => true,
			'session_policy_applies_to_sibling_mcp_servers' => false,
			'preauth_guard_delegates_oauth_effectiveness_to_resource_bridge' => true,
			'preauth_guard_calls_full_reconnect_status' => false,
			'reinitialize_required_without_valid_repair_shadow' => true,
			'blind_read_replay_after_transport_reinitialize' => false,
			'original_read_request_continues_after_verified_repair' => true,
			'blind_mutation_replay_after_transport_reinitialize' => false,
			'authority_created' => false,
			'production_widened' => false,
		);
	}


	private static function session_repair_supported_runtime() {
		if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) || ! class_exists( '\\WP\\MCP\\Transport\\Infrastructure\\SessionManager' ) ) return false;
		if ( ! defined( 'WP\\MCP\\Core\\McpAdapter::VERSION' ) ) return false;
		return hash_equals( self::CERTIFIED_STATEFUL_ADAPTER_VERSION, (string) \WP\MCP\Core\McpAdapter::VERSION );
	}

	private static function session_meta_key() {
		if ( ! is_multisite() ) return self::SESSION_META_KEY;
		$blog_id = (int) get_current_blog_id();
		return $blog_id > 0 ? self::SESSION_META_KEY . '_' . $blog_id : self::SESSION_META_KEY;
	}

	private static function shadow_fingerprint( $session_id ) {
		return hash( 'sha256', (string) $session_id );
	}

	private static function shadow_key( $user_id, $session_id ) {
		$blog_id = is_multisite() ? max( 0, (int) get_current_blog_id() ) : 0;
		return 'mad4b_mcp_session_shadow_' . $blog_id . '_' . absint( $user_id ) . '_' . self::shadow_fingerprint( $session_id );
	}

	private static function shadow_tombstone_key( $user_id, $session_id ) {
		$blog_id = is_multisite() ? max( 0, (int) get_current_blog_id() ) : 0;
		return 'mad4b_mcp_session_deleted_' . $blog_id . '_' . absint( $user_id ) . '_' . self::shadow_fingerprint( $session_id );
	}

	private static function store_session_shadow( $user_id, $session_id, array $shadow ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 || ! self::valid_adapter_session_id( (string) $session_id ) ) return false;
		if ( get_transient( self::shadow_tombstone_key( $user_id, $session_id ) ) ) return false;
		$key = self::shadow_key( $user_id, $session_id );
		if ( ! set_transient( $key, $shadow, self::SESSION_SHADOW_TTL ) ) return false;
		if ( get_transient( self::shadow_tombstone_key( $user_id, $session_id ) ) ) {
			delete_transient( $key );
			return false;
		}
		return true;
	}

	private static function get_session_shadow( $user_id, $session_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 || ! self::valid_adapter_session_id( (string) $session_id ) || get_transient( self::shadow_tombstone_key( $user_id, $session_id ) ) ) return array();
		$shadow = get_transient( self::shadow_key( $user_id, $session_id ) );
		if ( ! is_array( $shadow ) ) return array();
		$captured_at = isset( $shadow['captured_at'] ) ? (int) $shadow['captured_at'] : 0;
		if ( $captured_at < time() - self::SESSION_SHADOW_TTL || $captured_at > time() + 30 ) {
			delete_transient( self::shadow_key( $user_id, $session_id ) );
			return array();
		}
		return $shadow;
	}

	private static function forget_session_shadow( $user_id, $session_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 || ! self::valid_adapter_session_id( (string) $session_id ) ) return;
		set_transient( self::shadow_tombstone_key( $user_id, $session_id ), 1, self::SESSION_DELETE_TOMBSTONE_TTL );
		delete_transient( self::shadow_key( $user_id, $session_id ) );
	}

	private static function request_route( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return '';
		return '/' . ltrim( rtrim( (string) $request->get_route(), '/' ), '/' );
	}

	private static function request_json( $request ) {
		if ( ! is_object( $request ) ) return array();
		if ( method_exists( $request, 'get_json_params' ) ) {
			$body = $request->get_json_params();
			if ( is_array( $body ) ) return $body;
		}
		if ( ! method_exists( $request, 'get_body' ) ) return array();
		$decoded = json_decode( (string) $request->get_body(), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function response_session_id( $response ) {
		$response = rest_ensure_response( $response );
		if ( ! ( $response instanceof WP_REST_Response ) || ! method_exists( $response, 'get_headers' ) ) return '';
		foreach ( (array) $response->get_headers() as $name => $value ) {
			if ( 'mcp-session-id' !== strtolower( (string) $name ) ) continue;
			$value = trim( is_array( $value ) ? (string) reset( $value ) : (string) $value );
			return strlen( $value ) <= 128 ? $value : '';
		}
		return '';
	}

	private static function minimal_initialize_params( $params ) {
		if ( ! is_array( $params ) ) return array();
		$protocol = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) ? trim( $params['protocolVersion'] ) : '';
		if ( '' === $protocol || strlen( $protocol ) > 64 || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $protocol ) ) return array();
		// Adapter 0.6.1 session validation does not consume clientInfo or
		// capabilities after initialize. Keep only the negotiated protocol revision.
		return array( 'protocolVersion' => $protocol );
	}

	private static function current_subject_binding() {
		$mapped = class_exists( 'MAD4B_SCP_OAuth_Subject_User_Bridge' ) ? MAD4B_SCP_OAuth_Subject_User_Bridge::mapped_context() : array();
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		if ( is_wp_error( $identity ) || ! is_array( $identity ) ) $identity = array();
		$user_id = isset( $mapped['wp_user_id'] ) ? absint( $mapped['wp_user_id'] ) : get_current_user_id();
		$subject = isset( $mapped['subject_fingerprint'] ) ? strtolower( (string) $mapped['subject_fingerprint'] ) : '';
		$profile = isset( $mapped['site_profile_digest'] ) ? strtolower( (string) $mapped['site_profile_digest'] ) : '';
		$client = isset( $identity['client_fingerprint'] ) ? strtolower( (string) $identity['client_fingerprint'] ) : '';
		return array(
			'wp_user_id' => $user_id,
			'subject_fingerprint' => $subject,
			'site_profile_digest' => $profile,
			'client_fingerprint' => $client,
		);
	}


	private static function valid_adapter_session_id( $session_id ) {
		return is_string( $session_id )
			&& 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $session_id );
	}

	private static function valid_session_record( $session ) {
		if ( ! is_array( $session ) || ! isset( $session['created_at'], $session['last_activity'], $session['client_params'] ) ) return false;
		if ( ! is_numeric( $session['created_at'] ) || ! is_numeric( $session['last_activity'] ) || ! is_array( $session['client_params'] ) ) return false;
		$created = (int) $session['created_at'];
		$activity = (int) $session['last_activity'];
		return $created > 0 && $activity >= $created && $activity <= time() + 300;
	}


	private static function adapter_visible_session_map( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) return array();
		wp_cache_delete( $user_id, 'user_meta' );
		$visible = get_user_meta( $user_id, self::session_meta_key(), true );
		return is_array( $visible ) ? $visible : array();
	}

	private static function session_store_is_empty_for_first_initialize( $user_id ) {
		// Match Adapter 0.6.1 exactly: get_all_user_sessions() reads single=true.
		// Hidden duplicate rows must not make MAD4B disagree with the Adapter about
		// whether this request started from an empty visible session map.
		return empty( self::adapter_visible_session_map( $user_id ) );
	}

	private static function valid_session_map( array $sessions ) {
		foreach ( $sessions as $session_id => $session ) {
			if ( ! self::valid_adapter_session_id( $session_id ) || ! self::valid_session_record( $session ) ) return false;
		}
		return true;
	}

	private static function mutate_visible_session_map( $user_id, $callback ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 || ! is_callable( $callback ) ) return false;
		$key = self::session_meta_key();
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$previous = self::adapter_visible_session_map( $user_id );
			// Recovery never invents the first canonical map. Upstream initialize owns
			// that transition; MAD4B only repairs a session lost by its documented race.
			if ( empty( $previous ) || ! self::valid_session_map( $previous ) ) return false;
			$updated = call_user_func( $callback, $previous );
			if ( ! is_array( $updated ) || ! self::valid_session_map( $updated ) ) return false;
			if ( $updated === $previous ) return true;
			$stored = update_user_meta( $user_id, $key, $updated, $previous );
			if ( false === $stored ) continue;
			wp_cache_delete( $user_id, 'user_meta' );
			$readback = get_user_meta( $user_id, $key, true );
			if ( is_array( $readback ) && $readback === $updated ) return true;
		}
		return false;
	}

	private static function remove_session_from_all_rows( $user_id, $session_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 || ! self::valid_adapter_session_id( (string) $session_id ) ) return false;
		$key = self::session_meta_key();
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			wp_cache_delete( $user_id, 'user_meta' );
			$rows = get_user_meta( $user_id, $key, false );
			if ( ! is_array( $rows ) ) return false;
			$found = false;
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row[ $session_id ] ) ) continue;
				$found = true;
				$updated = $row;
				unset( $updated[ $session_id ] );
				update_user_meta( $user_id, $key, $updated, $row );
			}
			if ( ! $found ) return true;
			wp_cache_delete( $user_id, 'user_meta' );
			$remaining = get_user_meta( $user_id, $key, false );
			$still_present = false;
			foreach ( is_array( $remaining ) ? $remaining : array() as $row ) {
				if ( is_array( $row ) && isset( $row[ $session_id ] ) ) { $still_present = true; break; }
			}
			if ( ! $still_present ) return true;
		}
		return false;
	}

	private static function repair_session_capacity() {
		$effective = (int) apply_filters( 'mcp_adapter_session_max_per_user', 32 );
		return max( 1, min( 32, $effective ) );
	}

	private static function ensure_session_record( $user_id, $session_id, array $client_params ) {
		if ( ! self::valid_adapter_session_id( (string) $session_id ) ) return false;
		if ( get_transient( self::shadow_tombstone_key( $user_id, $session_id ) ) ) return false;
		$now = time();
		$max_sessions = self::repair_session_capacity();
		$capacity_denied = false;
		$stored = self::mutate_visible_session_map( $user_id, static function ( array $sessions ) use ( $session_id, $client_params, $now, $max_sessions, &$capacity_denied ) {
			if ( isset( $sessions[ $session_id ] ) ) return $sessions;
			// Never evict or expire another Adapter session from the recovery layer.
			// The canonical SessionManager owns lifecycle cleanup. Repair is additive
			// only when bounded capacity is visibly available.
			if ( count( $sessions ) >= $max_sessions ) {
				$capacity_denied = true;
				return $sessions;
			}
			$sessions[ $session_id ] = array(
				'created_at' => $now,
				'last_activity' => $now,
				'client_params' => $client_params,
			);
			return $sessions;
		} );
		if ( ! $stored || $capacity_denied ) return false;
		if ( get_transient( self::shadow_tombstone_key( $user_id, $session_id ) ) ) {
			self::remove_session_record( $user_id, $session_id );
			return false;
		}
		return true;
	}

	private static function remove_session_record( $user_id, $session_id ) {
		return self::remove_session_from_all_rows( $user_id, $session_id );
	}

	private static function session_exists( $user_id, $session_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 || ! self::valid_adapter_session_id( (string) $session_id ) ) return false;
		$sessions = self::adapter_visible_session_map( $user_id );
		return isset( $sessions[ (string) $session_id ] ) && self::valid_session_record( $sessions[ (string) $session_id ] );
	}

	private static function readonly_transport_request( array $body ) {
		$method = isset( $body['method'] ) && is_string( $body['method'] ) ? $body['method'] : '';
		if ( in_array( $method, array( 'tools/list', 'resources/list', 'resources/templates/list', 'prompts/list', 'ping' ), true ) ) return true;
		if ( 'tools/call' !== $method ) return false;
		$tool_name = isset( $body['params']['name'] ) && is_string( $body['params']['name'] ) ? trim( $body['params']['name'] ) : '';
		if ( '' === $tool_name || ! class_exists( 'MAD4B_SCP_Servers' ) || ! class_exists( '\\WP\\MCP\\Domain\\Utils\\McpNameSanitizer' ) ) return false;
		$candidates = method_exists( 'MAD4B_SCP_Servers', 'chatgpt_direct_read_transport_tools' ) ? MAD4B_SCP_Servers::chatgpt_direct_read_transport_tools() : array();
		foreach ( is_array( $candidates ) ? $candidates : array() as $ability_name ) {
			$ability_name = (string) $ability_name;
			$sanitized = \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( $ability_name );
			if ( is_wp_error( $sanitized ) || ! is_string( $sanitized ) || ! hash_equals( $sanitized, $tool_name ) ) continue;
			if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) return false;
			$ability = wp_get_ability( $ability_name );
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) return false;
			$meta = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			return array_key_exists( 'readonly', $annotations ) && true === $annotations['readonly'];
		}
		return false;
	}

	public static function repair_or_forget_session( $result, $server, $request ) {
		if ( null !== $result || ! self::governed_nonproduction_transport() || ! self::session_repair_supported_runtime() ) return $result;
		if ( '/mcp/mad4b-chatgpt' !== self::request_route( $request ) ) return $result;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return $result;
		if ( ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( self::CHATGPT_CLIENT_ID ) ) return $result;
		$method = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'POST';
		$body = 'POST' === $method ? self::request_json( $request ) : array();
		if ( 'POST' === $method && 'initialize' === ( $body['method'] ?? '' ) ) {
			$key = self::request_scope_key( $request );
			if ( '' !== $key ) self::$initialize_empty_requests[ $key ] = self::session_store_is_empty_for_first_initialize( get_current_user_id() );
			return $result;
		}
		$session_id = method_exists( $request, 'get_header' ) ? trim( (string) $request->get_header( 'mcp-session-id' ) ) : '';
		if ( '' === $session_id || strlen( $session_id ) > 128 ) return $result;
		if ( 'DELETE' === $method ) {
			$user_id = get_current_user_id();
			if ( ! self::valid_adapter_session_id( $session_id ) ) return $result;
			self::forget_session_shadow( $user_id, $session_id );
			self::remove_session_from_all_rows( $user_id, $session_id );
			self::$session_repair_state = 'shadow_forgotten_on_delete';
			return $result;
		}
		if ( 'POST' !== $method ) return $result;
		$user_id = get_current_user_id();
		$shadow = self::get_session_shadow( $user_id, $session_id );
		if ( empty( $shadow['client_params'] ) || ! is_array( $shadow['client_params'] ) ) return $result;
		// Mutation requests never enter session repair. The canonical Adapter
		// validates their existing session and the normal governance path decides
		// execution; this layer cannot make a mutation request more executable.
		if ( ! self::readonly_transport_request( $body ) ) return $result;
		$binding = self::current_subject_binding();
		$bound_user_id = isset( $binding['wp_user_id'] ) ? absint( $binding['wp_user_id'] ) : 0;
		if ( $bound_user_id !== $user_id ) return $result;
		if ( $user_id < 1 || self::session_exists( $user_id, $session_id ) ) return $result;
		if ( (int) ( $shadow['wp_user_id'] ?? 0 ) !== $user_id ) return $result;
		foreach ( array( 'subject_fingerprint', 'site_profile_digest', 'client_fingerprint' ) as $key ) {
			$expected = isset( $shadow[ $key ] ) ? strtolower( (string) $shadow[ $key ] ) : '';
			$current = isset( $binding[ $key ] ) ? strtolower( (string) $binding[ $key ] ) : '';
			if ( '' === $expected || '' === $current || ! hash_equals( $expected, $current ) ) return $result;
		}
		if ( ! self::ensure_session_record( $user_id, $session_id, $shadow['client_params'] ) ) {
			self::$session_repair_state = 'rehydration_failed';
			return $result;
		}
		self::$session_repair_state = 'rehydrated_read_session';
		return $result;
	}

	public static function capture_initialized_session( $response, $server, $request ) {
		if ( ! self::governed_nonproduction_transport() || ! self::session_repair_supported_runtime() || '/mcp/mad4b-chatgpt' !== self::request_route( $request ) ) return $response;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return $response;
		if ( ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( self::CHATGPT_CLIENT_ID ) ) return $response;
		$body = self::request_json( $request );
		if ( 'initialize' !== ( $body['method'] ?? '' ) ) return $response;
		$key = self::request_scope_key( $request );
		$started_empty = '' !== $key && ! empty( self::$initialize_empty_requests[ $key ] );
		if ( ! $started_empty ) {
			self::$session_repair_state = 'initialize_not_first_empty_transition';
			return $response;
		}
		$response_obj = rest_ensure_response( $response );
		if ( ! ( $response_obj instanceof WP_REST_Response ) || 200 !== (int) $response_obj->get_status() ) return $response;
		$session_id = self::response_session_id( $response_obj );
		$params = isset( $body['params'] ) ? self::minimal_initialize_params( $body['params'] ) : array();
		if ( '' === $session_id || empty( $params ) ) return $response;
		$binding = self::current_subject_binding();
		$user_id = isset( $binding['wp_user_id'] ) ? absint( $binding['wp_user_id'] ) : 0;
		if ( $user_id < 1 ) return $response;
		$shadow = array(
			'wp_user_id' => $user_id,
			'subject_fingerprint' => isset( $binding['subject_fingerprint'] ) ? strtolower( (string) $binding['subject_fingerprint'] ) : '',
			'site_profile_digest' => isset( $binding['site_profile_digest'] ) ? strtolower( (string) $binding['site_profile_digest'] ) : '',
			'client_fingerprint' => isset( $binding['client_fingerprint'] ) ? strtolower( (string) $binding['client_fingerprint'] ) : '',
			'client_params' => $params,
			'captured_at' => time(),
			'raw_session_id_stored' => false,
			'oauth_client_bound' => true,
			'authority_created' => false,
		);
		if ( '' === $shadow['subject_fingerprint'] || '' === $shadow['site_profile_digest'] || 1 !== preg_match( '/^[a-f0-9]{64}$/', $shadow['client_fingerprint'] ) ) return $response;
		if ( ! self::store_session_shadow( $user_id, $session_id, $shadow ) ) return $response;
		if ( self::session_exists( $user_id, $session_id ) ) {
			self::$session_repair_state = 'initialized_session_present';
			return $response;
		}
		self::$session_repair_state = self::ensure_session_record( $user_id, $session_id, $params )
			? 'initialized_session_race_repaired'
			: 'initialized_session_race_repair_failed';
		return $response;
	}

	public static function session_repair_status() {
		return array(
			'contract' => self::CONTRACT,
			'state' => self::$session_repair_state,
			'shadow_ttl_seconds' => self::SESSION_SHADOW_TTL,
			'shadow_key_contains_session_fingerprint_only' => true,
			'shadow_storage_per_session' => true,
			'shadow_cross_session_lost_update_possible' => false,
			'shadow_hard_count_bound' => false,
			'shadow_admission_first_empty_transition_only' => true,
			'sequential_reconnect_shadow_growth_possible' => false,
			'shadow_ttl_bounded' => true,
			'delete_tombstone_blocks_shadow_resurrection' => true,
			'delete_tombstone_ttl_seconds' => self::SESSION_DELETE_TOMBSTONE_TTL,
			'delete_tombstone_outlives_shadow' => self::SESSION_DELETE_TOMBSTONE_TTL > self::SESSION_SHADOW_TTL,
			'post_shadow_write_tombstone_rechecked' => true,
			'delete_tombstone_bound_to_site_user_session' => true,
			'post_cas_delete_tombstone_rechecked' => true,
			'raw_session_id_persisted_in_shadow' => false,
			'canonical_adapter_session_store_used_for_repair' => true,
			'initialize_params_minimized' => true,
			'initialize_capabilities_persisted_in_shadow' => false,
			'initialize_client_info_persisted_in_shadow' => false,
			'initialize_shadow_fields' => array( 'protocolVersion' ),
			'first_session_race_repair_enabled' => self::governed_nonproduction_transport() && self::session_repair_supported_runtime(),
			'repair_scope_first_empty_transition_only' => true,
			'general_expiry_or_eviction_rehydration_enabled' => false,
			'certified_stateful_adapter_version' => self::CERTIFIED_STATEFUL_ADAPTER_VERSION,
			'exact_adapter_version_required' => true,
			'rehydration_read_only' => true,
			'rehydration_requires_runtime_readonly_annotation' => true,
			'notifications_do_not_trigger_rehydration' => true,
			'mutation_session_rehydration_enabled' => false,
			'rehydration_respects_session_capacity' => true,
			'repair_never_evicts_existing_adapter_session' => true,
			'repair_capacity_ceiling' => 32,
			'repair_uses_upstream_session_capacity' => true,
			'repair_uses_custom_lock' => false,
			'empty_only_session_meta_repair_allowed' => false,
			'repair_matches_adapter_single_meta_visibility' => true,
			'duplicate_nonempty_rows_union_enabled' => false,
			'delete_removes_target_from_all_duplicate_rows' => true,
			'repair_readback_verified' => true,
			'session_id_shape_pinned_to_adapter_uuid_v4' => true,
			'existing_session_records_shape_validated' => true,
			'session_exists_matches_adapter_visible_meta_row' => true,
			'steady_state_user_meta_read_after_shadow_expiry' => false,
			'delete_forgets_shadow' => true,
			'oauth_client_bound' => true,
			'authority_created' => false,
		);
	}

	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) return;
		wp_register_ability_category( 'mad4b-governance', array(
			'label' => 'MAD4B Governance',
			'description' => 'Read-only governance, enrollment and reconnect diagnostics.',
		) );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) ) return;
		if ( ! wp_has_ability( 'mad4b/reconnect-readiness' ) ) {
			wp_register_ability( 'mad4b/reconnect-readiness', array(
				'label' => 'MAD4B Reconnect Readiness',
				'description' => 'Inspect exact ChatGPT Site Profile, OAuth and MCP reconnect readiness without changing authority.',
				'category' => 'mad4b-governance',
				'execute_callback' => array( __CLASS__, 'reconnect_status' ),
				'permission_callback' => class_exists( 'MAD4B_SCP_Policy' ) ? array( 'MAD4B_SCP_Policy', 'can_read' ) : '__return_false',
				'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		}
		if ( ! wp_has_ability( 'mad4b/governance-bootstrap-status' ) ) {
			wp_register_ability( 'mad4b/governance-bootstrap-status', array(
				'label' => 'MAD4B Governance Bootstrap Status',
				'description' => 'Distinguish governance schema readiness from append-only audit readiness without mutation.',
				'category' => 'mad4b-governance',
				'execute_callback' => array( 'MAD4B_SCP_Upgrade_Continuity', 'governance_status' ),
				'permission_callback' => class_exists( 'MAD4B_SCP_Policy' ) ? array( 'MAD4B_SCP_Policy', 'can_read' ) : '__return_false',
				'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		}
	}

	public static function reconnect_status() {
		$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$local = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? MAD4B_SCP_Local_OAuth_Server::status() : array();
		$bridge = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		$registrations = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::registration_status() : array();
		$chatgpt = isset( $registrations['mad4b-chatgpt'] ) && is_array( $registrations['mad4b-chatgpt'] ) ? $registrations['mad4b-chatgpt'] : array();
		$bridge_registration = class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' ) ? MAD4B_SCP_MCP_Registration_Bridge::status() : array();
		$blockers = array();

		if ( empty( $profile['configured'] ) ) $blockers[] = 'site_profile_unconfigured';
		if ( empty( $profile['environment_match'] ) ) $blockers[] = 'site_profile_environment_drift';
		if ( empty( $profile['origin_match'] ) ) $blockers[] = 'site_profile_origin_drift';
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::oauth_enabled() ) $blockers[] = 'site_profile_oauth_disabled';
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::managed_runtime_enabled() ) $blockers[] = 'site_profile_managed_runtime_disabled';
		if ( empty( $bridge['effective'] ) ) $blockers[] = 'oauth_resource_bridge_not_effective';
		if ( empty( $chatgpt['registered'] ) ) $blockers[] = ! empty( $chatgpt['error'] ) ? sanitize_key( (string) $chatgpt['error'] ) : 'mcp_chatgpt_not_registered';
		$blockers = array_values( array_unique( array_filter( array_map( 'sanitize_key', $blockers ) ) ) );

		return array(
			'contract' => self::CONTRACT,
			'ready' => empty( $blockers ),
			'blockers' => $blockers,
			'resource' => self::resource_identifier(),
			'oauth_authorize_url' => ! empty( $local['effective'] ) ? untrailingslashit( home_url( '/oauth/mcp/authorize' ) ) : '',
			'site_profile' => self::bounded_profile( $profile ),
			'local_oauth_effective' => ! empty( $local['effective'] ),
			'oauth_authority_mode' => isset( $bridge['authority_mode'] ) ? sanitize_key( (string) $bridge['authority_mode'] ) : '',
			'oauth_resource_bridge_effective' => ! empty( $bridge['effective'] ),
			'local_oauth_required_for_reconnect' => false,
			'chatgpt_registered' => ! empty( $chatgpt['registered'] ),
			'chatgpt_error' => isset( $chatgpt['error'] ) ? sanitize_key( (string) $chatgpt['error'] ) : '',
			'missed_rest_recovery_state' => isset( $bridge_registration['missed_rest_recovery_state'] ) ? sanitize_key( (string) $bridge_registration['missed_rest_recovery_state'] ) : '',
			'missed_rest_recovery_blocker' => isset( $bridge_registration['missed_rest_recovery_blocker'] ) ? sanitize_key( (string) $bridge_registration['missed_rest_recovery_blocker'] ) : '',
			'session_continuity' => self::session_continuity_policy(),
			'session_repair' => self::session_repair_status(),
			'upgrade_recovery' => class_exists( 'MAD4B_SCP_Upgrade_Continuity' ) ? MAD4B_SCP_Upgrade_Continuity::recovery_status() : array(),
			'write_auto_enabled' => false,
			'production_authority_auto_enabled' => false,
			'breakglass_auto_enabled' => false,
		);
	}


	private static function preauth_reconnect_blockers() {
		$blockers = array();
		$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		if ( empty( $profile['configured'] ) ) $blockers[] = 'site_profile_unconfigured';
		if ( empty( $profile['environment_match'] ) ) $blockers[] = 'site_profile_environment_drift';
		if ( empty( $profile['origin_match'] ) ) $blockers[] = 'site_profile_origin_drift';
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::oauth_enabled() ) $blockers[] = 'site_profile_oauth_disabled';
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::managed_runtime_enabled() ) $blockers[] = 'site_profile_managed_runtime_disabled';
		$registrations = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::registration_status() : array();
		$chatgpt = isset( $registrations['mad4b-chatgpt'] ) && is_array( $registrations['mad4b-chatgpt'] ) ? $registrations['mad4b-chatgpt'] : array();
		if ( empty( $chatgpt['registered'] ) ) $blockers[] = ! empty( $chatgpt['error'] ) ? sanitize_key( (string) $chatgpt['error'] ) : 'mcp_chatgpt_not_registered';
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $blockers ) ) ) );
	}

	public static function guard_mcp_rest_dispatch( $result, $server, $request ) {
		if ( null !== $result || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return $result;
		if ( ! self::is_resource_request_path( (string) $request->get_route() ) ) return $result;
		$blockers = self::preauth_reconnect_blockers();
		if ( empty( $blockers ) ) return $result;
		return new WP_Error( 'mad4b_mcp_reconnect_not_ready', 'MAD4B MCP reconnect is not ready.', array(
			'status' => 503,
			'blockers' => $blockers,
			'contract' => self::CONTRACT,
			'resource' => self::resource_identifier(),
		) );
	}

	public static function connection_admin_notice() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
		if ( 'mad4b-control-plane-chatgpt' !== $page ) return;
		$status = self::reconnect_status();
		$recovery = class_exists( 'MAD4B_SCP_Upgrade_Continuity' ) ? MAD4B_SCP_Upgrade_Continuity::recovery_status() : array();
		if ( ! empty( $recovery['recovered'] ) ) echo '<div class="notice notice-success inline"><p>' . esc_html__( 'MAD4B recovered the previously verified read/OAuth connection for this exact non-Production Site Profile. Write authority remains disabled and requires explicit administrator re-enrollment.', 'mad4b-site-control-plane' ) . '</p></div>';
		if ( ! empty( $status['ready'] ) ) return;
		$blockers = ! empty( $status['blockers'] ) ? implode( ', ', array_map( 'sanitize_key', $status['blockers'] ) ) : 'reconnect_not_ready';
		echo '<div class="notice notice-warning inline"><p>' . esc_html( 'ChatGPT reconnect is not ready. Blockers: ' . $blockers . '.' ) . '</p></div>';
	}

	public static function is_resource_request_path( $path ) {
		$path = self::normalize_path( $path );
		$resource = wp_parse_url( self::resource_identifier(), PHP_URL_PATH );
		$resource = is_string( $resource ) ? self::normalize_path( $resource ) : '/wp-json/mcp/mad4b-chatgpt';
		if ( hash_equals( $resource, $path ) ) return true;
		$rest_root = wp_parse_url( rest_url(), PHP_URL_PATH );
		$rest_root = is_string( $rest_root ) ? self::normalize_path( $rest_root ) : '/wp-json';
		if ( '/' !== $rest_root && 0 === strpos( $resource, $rest_root . '/' ) ) return hash_equals( self::normalize_path( substr( $resource, strlen( $rest_root ) ) ), $path );
		return false;
	}

	private static function resource_identifier() {
		return class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier() : untrailingslashit( rest_url( 'mcp/mad4b-chatgpt' ) );
	}

	private static function bounded_profile( $status ) {
		if ( ! is_array( $status ) ) $status = array();
		return array(
			'configured' => ! empty( $status['configured'] ),
			'site_uuid' => isset( $status['site_uuid'] ) ? sanitize_text_field( (string) $status['site_uuid'] ) : '',
			'revision' => isset( $status['revision'] ) ? absint( $status['revision'] ) : 0,
			'environment' => isset( $status['environment'] ) ? sanitize_key( (string) $status['environment'] ) : '',
			'environment_match' => ! empty( $status['environment_match'] ),
			'origin_match' => ! empty( $status['origin_match'] ),
			'oauth_enabled' => ! empty( $status['oauth_enabled'] ),
			'write_enabled' => ! empty( $status['write_enabled'] ),
		);
	}

	private static function normalize_path( $path ) {
		$path = '/' . ltrim( (string) $path, '/' );
		return '/' === $path ? '/' : rtrim( $path, '/' );
	}
}
