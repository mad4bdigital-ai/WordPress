<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Preserves MAD4B transport admission failures before MCP Adapter collapses a
 * custom permission-callback WP_Error into a generic false/rest_forbidden.
 *
 * WordPress invokes rest_request_before_callbacks after the request has passed
 * rest_pre_dispatch (where OAuth bearer verification and subject mapping run)
 * and after the route has been matched, but before the registered route
 * permission_callback. Re-running the MAD4B admission predicate at this seam is
 * therefore fail-closed and lets WordPress serialize the original bounded
 * WP_Error instead of losing its code/stage/blocker inside HttpTransport.
 *
 * This class never authenticates a bearer, grants authority, executes a tool or
 * persists request data. It only preserves already-defined MAD4B admission
 * semantics and adds bounded diagnostic response headers.
 */
final class MAD4B_SCP_MCP_Transport_Admission {
	const CONTRACT = 'mad4b.mcp-transport-admission.v1';

	private static $booted = false;
	private static $last_denial = array();
	private static $observed_rejection = false;
	const REJECTION_OPTION = 'mad4b_scp_mcp_rejections_v1';
	const REJECTION_CONTRACT = 'mad4b.mcp-rejections.v1';
	const REJECTION_MAX_EVENTS = 12;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'preserve_permission_error' ), 1, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'annotate_response' ), 90, 3 );
	}

	public static function preserve_permission_error( $response, $handler, $request ) {
		unset( $handler );
		if ( is_wp_error( $response ) ) return $response;
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return $response;
		if ( method_exists( $request, 'get_method' ) && 'OPTIONS' === strtoupper( (string) $request->get_method() ) ) return $response;

		$server_id = self::server_id_for_route( $request->get_route() );
		if ( '' === $server_id ) return $response;

		if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) {
			return self::denial(
				new WP_Error(
					'mad4b_transport_admission_unavailable',
					'MAD4B transport admission is unavailable.',
					array( 'status' => 503, 'stage' => 'transport_admission', 'blocker' => 'server_registry_unavailable' )
				),
				$server_id,
				$request
			);
		}

		$callbacks = array(
			'mad4b-read' => array( 'MAD4B_SCP_Servers', 'can_read_transport' ),
			'mad4b-chatgpt' => array( 'MAD4B_SCP_Servers', 'can_chatgpt_transport' ),
			'mad4b-enrollment' => array( 'MAD4B_SCP_Servers', 'can_enrollment_transport' ),
			'mad4b-content' => array( 'MAD4B_SCP_Servers', 'can_content_transport' ),
			'mad4b-write' => array( 'MAD4B_SCP_Servers', 'can_write_transport' ),
			'mad4b-admin' => array( 'MAD4B_SCP_Servers', 'can_admin_transport' ),
			'mad4b-developer' => array( 'MAD4B_SCP_Servers', 'can_developer_transport' ),
			'mad4b-developer-breakglass' => array( 'MAD4B_SCP_Servers', 'can_developer_breakglass_transport' ),
			'mad4b-breakglass' => array( 'MAD4B_SCP_Servers', 'can_breakglass_transport' ),
		);
		$callback = isset( $callbacks[ $server_id ] ) ? $callbacks[ $server_id ] : null;
		if ( ! is_callable( $callback ) ) {
			return self::denial(
				new WP_Error(
					'mad4b_transport_admission_callback_unavailable',
					'MAD4B transport admission callback is unavailable.',
					array( 'status' => 503, 'stage' => 'transport_admission', 'blocker' => 'permission_callback_unavailable' )
				),
				$server_id,
				$request
			);
		}

		try {
			$permission = call_user_func( $callback, $request );
		} catch ( Throwable $error ) {
			return self::denial(
				new WP_Error(
					'mad4b_transport_admission_exception',
					'MAD4B transport admission failed unexpectedly.',
					array( 'status' => 503, 'stage' => 'transport_admission', 'blocker' => 'permission_callback_exception' )
				),
				$server_id,
				$request
			);
		}

		if ( is_wp_error( $permission ) ) return self::denial( $permission, $server_id, $request );
		if ( (bool) $permission ) return $response;

		$status = function_exists( 'rest_authorization_required_code' ) ? (int) rest_authorization_required_code() : 403;
		return self::denial(
			new WP_Error(
				'mad4b_transport_permission_denied',
				'MAD4B transport permission was denied.',
				array( 'status' => $status, 'stage' => 'transport_permission', 'blocker' => 'permission_callback_denied' )
			),
			$server_id,
			$request
		);
	}

	public static function annotate_response( $response, $server, $request ) {
		unset( $server );
		if ( ! ( $response instanceof WP_REST_Response ) || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return $response;
		$server_id = self::server_id_for_route( $request->get_route() );
		if ( '' === $server_id ) return $response;
		if ( 200 === (int) $response->get_status() ) {
			// Inspect only the JSON-RPC envelope; never persist body or tool inputs.
			$data = $response->get_data();
			if ( is_array( $data ) && isset( $data['error'], $data['jsonrpc'] ) && '2.0' === $data['jsonrpc'] ) {
				self::observe_rejection( 'jsonrpc', 200, 'mcp_json_rpc_error' );
			}
			return $response;
		}
		if ( (int) $response->get_status() < 400 ) return $response;

		$request_key = self::request_key( $request );
		if ( empty( self::$last_denial ) || ! hash_equals( (string) self::$last_denial['request_key'], $request_key ) ) return $response;

		$response->header( 'X-MAD4B-MCP-Admission', 'denied' );
		$response->header( 'X-MAD4B-MCP-Admission-Stage', self::$last_denial['stage'] );
		$response->header( 'X-MAD4B-MCP-Admission-Code', self::$last_denial['code'] );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'booted' => self::$booted,
			'preserves_permission_wp_error' => true,
			'seam' => 'rest_request_before_callbacks',
			'runs_after_oauth_pre_dispatch' => true,
			'runs_before_adapter_permission_callback' => true,
			'creates_authority' => false,
			'executes_tools' => false,
			'stores_credentials' => false,
			'persists_request_data' => false,
			'request_local_diagnostic_state' => true,
		);
	}


	/** Capture bounded, non-authorizing denial metadata on exact Staging only. */
	public static function observe_rejection( $stage, $http_status, $code ) {
		try {
			if ( self::$observed_rejection
				|| ! function_exists( 'wp_get_environment_type' ) || 'staging' !== wp_get_environment_type()
				|| ! class_exists( 'MAD4B_Native_Incident_MU', false )
				|| ! MAD4B_Native_Incident_MU::active()
				|| ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) return false;
			if ( ! is_string( $stage ) || ! in_array( $stage, array( 'oauth', 'oauth_exception', 'transport', 'jsonrpc' ), true ) ) return false;
			$status = (int) $http_status;
			if ( ! in_array( $status, array( 200, 401, 403, 503 ), true )
				|| ( 'jsonrpc' === $stage ) !== ( 200 === $status ) ) return false;
			// Only developer-defined opaque identifiers are stored; no raw errors.
			$code = is_string( $code ) && preg_match( '/^(?:mad4b_[a-z0-9_]{3,80}|mcp_json_rpc_error)$/D', $code )
				? $code : 'mad4b_rejection_unspecified';
			$now = time();
			$stored = get_option( self::REJECTION_OPTION, array() );
			$last = is_array( $stored ) ? (int) ( $stored['last_write_at'] ?? 0 ) : 0;
			// Global 15-second limit stops alternating bad tokens from write-spamming DB.
			if ( $last > 0 && $now >= $last && $now - $last < 15 ) return false;
			$rows = is_array( $stored ) && is_array( $stored['events'] ?? null ) ? $stored['events'] : array();
			$rows = array_values( array_filter( $rows, static function ( $row ) use ( $now ) {
				return is_array( $row ) && isset( $row['at'] ) && (int) $row['at'] <= $now
					&& (int) $row['at'] >= $now - 7 * 86400;
			} ) );
			$correlation = MAD4B_Native_Incident_MU::correlation_id();
			if ( ! is_string( $correlation ) || ! preg_match( '/^[a-f0-9]{32}$/D', $correlation ) ) return false;
			$rows[] = array( 'stage' => $stage, 'status' => $status, 'code' => $code,
				'correlation_id' => $correlation, 'at' => $now );
			$next = array( 'contract' => self::REJECTION_CONTRACT, 'last_write_at' => $now,
				'events' => array_slice( $rows, -self::REJECTION_MAX_EVENTS ) );
			self::$observed_rejection = true;
			return (bool) update_option( self::REJECTION_OPTION, $next, false );
		} catch ( Throwable $ignored ) {
			return false; // Instrumentation must never alter OAuth or permissions.
		}
	}

	/** Sanitized, read-only projection; caller must enforce governed read. */
	public static function rejection_evidence_status() {
		$empty = array( 'contract' => self::REJECTION_CONTRACT, 'available' => false,
			'events' => array(), 'count' => 0 );
		try {
			if ( ! function_exists( 'wp_get_environment_type' ) || 'staging' !== wp_get_environment_type()
				|| ! class_exists( 'MAD4B_Native_Incident_MU', false )
				|| ! MAD4B_Native_Incident_MU::active()
				|| ! function_exists( 'get_option' ) ) return $empty;
			$stored = get_option( self::REJECTION_OPTION, array() );
			$rows = is_array( $stored ) && is_array( $stored['events'] ?? null ) ? $stored['events'] : array();
			$safe = array();
			$now = time();
			foreach ( array_slice( $rows, -self::REJECTION_MAX_EVENTS ) as $row ) {
				if ( ! is_array( $row ) ) continue;
				$stage = $row['stage'] ?? '';
				$code = $row['code'] ?? '';
				$status = (int) ( $row['status'] ?? 0 );
				$at = (int) ( $row['at'] ?? 0 );
				$correlation = $row['correlation_id'] ?? '';
				if ( ! in_array( $stage, array( 'oauth', 'oauth_exception', 'transport', 'jsonrpc' ), true )
					|| ! in_array( $status, array( 200, 401, 403, 503 ), true )
					|| ( 'jsonrpc' === $stage ) !== ( 200 === $status )
					|| ! is_string( $code ) || ! preg_match( '/^(?:mad4b_[a-z0-9_]{3,80}|mcp_json_rpc_error)$/D', $code )
					|| ! is_string( $correlation ) || ! preg_match( '/^[a-f0-9]{32}$/D', $correlation )
					|| $at > $now || $at < $now - 7 * 86400 ) continue;
				$safe[] = array( 'stage' => $stage, 'http_status' => $status, 'code' => $code,
					'correlation_id' => $correlation, 'at' => $at );
			}
			return array( 'contract' => self::REJECTION_CONTRACT, 'available' => true,
				'state' => $safe ? 'REJECTIONS_OBSERVED' : 'NO_REJECTIONS_CAPTURED',
				'events' => $safe, 'count' => count( $safe ), 'read_only' => true );
		} catch ( Throwable $ignored ) {
			return $empty;
		}
	}

	private static function denial( $error, $server_id, $request ) {
		$code = sanitize_key( (string) $error->get_error_code() );
		if ( '' === $code ) $code = 'mad4b_transport_admission_denied';
		// Never reflect upstream error text. The bounded code/stage/blocker tuple is
		// sufficient for diagnosis and prevents future provider messages from
		// exposing paths, identities or request-derived material.
		$message = 'MAD4B transport admission was denied.';

		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		$stage = isset( $data['stage'] ) ? sanitize_key( (string) $data['stage'] ) : '';
		if ( '' === $stage ) $stage = self::stage_for_code( $code );
		$default_status = in_array( $stage, array( 'catalog_admission', 'runtime_transaction', 'transport_binding', 'transport_admission' ), true ) ? 503 : 403;
		$status = isset( $data['status'] ) && is_numeric( $data['status'] ) ? (int) $data['status'] : $default_status;
		if ( $status < 400 || $status > 599 ) $status = $default_status;
		$blocker = isset( $data['blocker'] ) ? sanitize_key( (string) $data['blocker'] ) : '';
		if ( '' === $blocker ) $blocker = $code;

		$bounded = array(
			'status' => $status,
			'contract' => self::CONTRACT,
			'stage' => $stage,
			'blocker' => $blocker,
			'server_id' => sanitize_key( (string) $server_id ),
			'diagnostic_safe' => true,
		);
		self::$last_denial = array(
			'request_key' => self::request_key( $request ),
			'code' => $code,
			'stage' => $stage,
			'blocker' => $blocker,
			'server_id' => sanitize_key( (string) $server_id ),
			'status' => $status,
		);
		self::observe_rejection( 'transport', $status, $code );
		return new WP_Error( $code, $message, $bounded );
	}

	private static function stage_for_code( $code ) {
		$code = sanitize_key( (string) $code );
		if ( false !== strpos( $code, 'catalog' ) ) return 'catalog_admission';
		if ( false !== strpos( $code, 'transaction' ) || false !== strpos( $code, 'bootstrap' ) ) return 'runtime_transaction';
		if ( false !== strpos( $code, 'route' ) || false !== strpos( $code, 'transport_server' ) ) return 'transport_binding';
		if ( false !== strpos( $code, 'permission' ) || false !== strpos( $code, 'capability' ) ) return 'transport_permission';
		return 'transport_admission';
	}

	private static function server_id_for_route( $route ) {
		$route = self::normalize_route( $route );
		if ( 0 !== strpos( $route, '/mcp/mad4b-' ) ) return '';
		$server_id = sanitize_key( substr( $route, strlen( '/mcp/' ) ) );
		$expected = class_exists( 'MAD4B_SCP_Servers' )
			? MAD4B_SCP_Servers::expected_server_ids()
			: array(
				'mad4b-read',
				'mad4b-chatgpt',
				'mad4b-enrollment',
				'mad4b-content',
				'mad4b-write',
				'mad4b-admin',
				'mad4b-developer',
				'mad4b-developer-breakglass',
				'mad4b-breakglass',
			);
		return in_array( $server_id, $expected, true ) ? $server_id : '';
	}

	private static function normalize_route( $route ) {
		$route = '/' . ltrim( rtrim( (string) $route, '/' ), '/' );
		return '/' === $route ? '/' : $route;
	}

	private static function request_key( $request ) {
		return is_object( $request ) ? spl_object_hash( $request ) : '';
	}
}
