<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Explicit deny-only isolation for provider-native MCP/AI surfaces.
 *
 * This is deliberately OFF by default. When enabled it suppresses only bounded,
 * reviewed provider MCP registrations and REST/control routes. It never grants
 * MAD4B authority, changes provider settings, creates credentials, disables the
 * provider plugin, or auto-enables mutation. Unknown routes and unknown server
 * callbacks remain untouched and therefore remain visible to fail-closed peer
 * governance.
 */
final class MAD4B_SCP_MCP_Provider_Isolation {
	const CONTRACT = 'mad4b.mcp-provider-isolation.v2';
	// Historical identifier retained only so older repository consistency gates
	// can recognize the migration boundary. Runtime/status authority is v2 only.
	const PREVIOUS_CONTRACT = 'mad4b.mcp-provider-isolation.v1';
	const ENABLE_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_ENABLED';
	const PRODUCTION_APPROVAL_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_PRODUCTION_APPROVED';

	private static $booted = false;
	private static $removed_routes = array();
	private static $suppression_attempted = false;
	private static $suppressed_server_callbacks = array();

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		// Suppress the generic default WordPress MCP server while explicit MAD4B
		// isolation is effective. Custom MAD4B servers are registered separately.
		add_filter( 'mcp_adapter_create_default_server', array( __CLASS__, 'filter_default_server' ), 1 );

		// Remove reviewed provider registrations immediately before the official
		// adapter initializes. REST uses priority 15; WP-CLI uses init priority 20.
		add_action( 'rest_api_init', array( __CLASS__, 'suppress_provider_server_registrations' ), 14 );
		add_action( 'init', array( __CLASS__, 'suppress_provider_server_registrations' ), 19 );

		// Defense in depth: if a provider adds its registration callback unusually
		// late, remove exact reviewed callbacks before later mcp_adapter_init
		// priorities execute. Unknown callbacks are never removed.
		add_action( 'mcp_adapter_init', array( __CLASS__, 'suppress_provider_server_registrations' ), -1000000 );

		// Remove only reviewed provider MCP/control endpoints. A future or unknown
		// MCP route will not match these descriptors and remains visible/blocking.
		add_filter( 'rest_endpoints', array( __CLASS__, 'filter_rest_endpoints' ), PHP_INT_MAX );
	}

	public static function configured() {
		return defined( self::ENABLE_FLAG ) && true === constant( self::ENABLE_FLAG );
	}

	public static function production_approved() {
		return defined( self::PRODUCTION_APPROVAL_FLAG ) && true === constant( self::PRODUCTION_APPROVAL_FLAG );
	}

	public static function effective() {
		if ( ! self::configured() ) return false;
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		if ( 'production' === $environment && ! self::production_approved() ) return false;
		return in_array( $environment, array( 'staging', 'development', 'local', 'production' ), true );
	}

	public static function filter_default_server( $create ) {
		return self::effective() ? false : (bool) $create;
	}

	/**
	 * Remove only exact reviewed provider callbacks from mcp_adapter_init.
	 *
	 * This intentionally does not mutate the MCP Adapter private server registry.
	 * If a provider server was already created before this guard ran, peer
	 * governance continues to see it and the connection remains fail-closed.
	 */
	public static function suppress_provider_server_registrations() {
		if ( ! self::effective() ) return;
		self::$suppression_attempted = true;

		global $wp_filter;
		if ( ! isset( $wp_filter['mcp_adapter_init'] ) || ! ( $wp_filter['mcp_adapter_init'] instanceof WP_Hook ) ) return;

		$callbacks = $wp_filter['mcp_adapter_init']->callbacks;
		if ( ! is_array( $callbacks ) ) return;

		foreach ( $callbacks as $priority => $entries ) {
			if ( ! is_array( $entries ) ) continue;
			foreach ( $entries as $entry ) {
				$callback = isset( $entry['function'] ) ? $entry['function'] : null;
				$descriptor = self::descriptor_for_server_callback( $callback );
				if ( ! $descriptor ) continue;
				if ( ! remove_action( 'mcp_adapter_init', $callback, (int) $priority ) ) continue;

				$key = sanitize_key( $descriptor['provider'] . '-' . $descriptor['server_id'] );
				self::$suppressed_server_callbacks[ $key ] = array(
					'provider' => sanitize_key( $descriptor['provider'] ),
					'server_id' => sanitize_text_field( $descriptor['server_id'] ),
					'callback' => sanitize_text_field( $descriptor['callback_class'] . '::' . $descriptor['callback_method'] ),
					'priority' => (int) $priority,
				);
			}
		}
	}

	public static function filter_rest_endpoints( $endpoints ) {
		if ( ! self::effective() || ! is_array( $endpoints ) ) return $endpoints;
		foreach ( array_keys( $endpoints ) as $route ) {
			$descriptor = self::descriptor_for_route( (string) $route );
			if ( ! $descriptor ) continue;
			self::$removed_routes[] = substr( (string) $route, 0, 255 );
			unset( $endpoints[ $route ] );
		}
		self::$removed_routes = array_values( array_unique( self::$removed_routes ) );
		return $endpoints;
	}

	/**
	 * Exact/bounded provider route descriptors. Regexes are anchored and scoped to
	 * reviewed provider namespaces. They are a deny/isolation list, never an
	 * allowlist. Hostinger JWT token/revoke endpoints are included because they
	 * create/revoke credentials for its independent MCP transport.
	 */
	public static function descriptors() {
		return array(
			array(
				'provider' => 'hostinger_ai_assistant',
				'pattern' => '#^/hostinger-ai-assistant/v1/mcp/?$#',
				'class' => 'mcp_transport',
			),
			array(
				'provider' => 'hostinger_ai_assistant',
				'pattern' => '#^/hostinger-ai-assistant/v1/jwt/(?:token|revoke)/?$#',
				'class' => 'mcp_credential_control',
			),
			array(
				'provider' => 'fluent_forms',
				'pattern' => '#^/fluentform/v1/mcp/(?:status|toggle|install-adapter|config-snippets)/?$#',
				'class' => 'mcp_control_surface',
			),
			array(
				'provider' => 'fluent_forms',
				'pattern' => '#^/fluentform/mcp/?$#',
				'class' => 'mcp_transport',
			),
			array(
				'provider' => 'jetengine',
				'pattern' => '#^/jet-engine/v1/mcp/?$#',
				'class' => 'mcp_transport',
			),
			array(
				'provider' => 'jetengine',
				'pattern' => '#^/jet-engine/v1/mcp-tools/?$#',
				'class' => 'mcp_execution_surface',
			),
			array(
				'provider' => 'jetengine',
				'pattern' => '#^/jet-engine/v1/mcp-tools/run(?:/.*)?$#',
				'class' => 'mcp_execution_surface',
			),
			array(
				'provider' => 'uae_hfe',
				'pattern' => '#^/hfe/v1/mcp-(?:abilities|settings)/?$#',
				'class' => 'mcp_control_surface',
			),
			array(
				'provider' => 'uae_hfe',
				'pattern' => '#^/uae/mcp/?$#',
				'class' => 'mcp_transport',
			),
			array(
				'provider' => 'elementskit',
				'pattern' => '#^/elementskit/mcp/?$#',
				'class' => 'mcp_transport',
			),
			array(
				'provider' => 'elementskit',
				'pattern' => '#^/elementskit/v1/mcp-proxy/?$#',
				'class' => 'mcp_execution_surface',
			),
		);
	}

	/**
	 * Exact callback identities certified from provider implementations observed
	 * on the live Staging target. Unknown callback identities are not suppressed.
	 */
	public static function server_callback_descriptors() {
		return array(
			array(
				'provider' => 'hostinger_ai_assistant',
				'server_id' => 'hostinger-ai-assistant-mcp-server',
				'callback_class' => 'Hostinger\\AiAssistant\\Mcp\\McpServer',
				'callback_method' => 'create_server',
			),
			array(
				'provider' => 'elementskit',
				'server_id' => 'elementskit-mcp-server',
				'callback_class' => 'ElementsKit_Lite\\Mcp\\Server',
				'callback_method' => 'register_server',
			),
		);
	}

	public static function descriptor_for_route( $route ) {
		$route = '/' . ltrim( (string) $route, '/' );
		foreach ( self::descriptors() as $descriptor ) {
			if ( preg_match( $descriptor['pattern'], $route ) ) return $descriptor;
		}
		return null;
	}

	public static function descriptor_for_server_callback( $callback ) {
		if ( ! is_array( $callback ) || 2 !== count( $callback ) ) return null;
		$left = $callback[0];
		$class = is_object( $left ) ? get_class( $left ) : ( is_string( $left ) ? ltrim( $left, '\\' ) : '' );
		$method = is_string( $callback[1] ) ? $callback[1] : '';
		if ( '' === $class || '' === $method ) return null;

		foreach ( self::server_callback_descriptors() as $descriptor ) {
			if ( ltrim( $descriptor['callback_class'], '\\' ) === ltrim( $class, '\\' ) && $descriptor['callback_method'] === $method ) return $descriptor;
		}
		return null;
	}

	public static function status() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$route_descriptors = array();
		foreach ( self::descriptors() as $descriptor ) {
			$route_descriptors[] = array(
				'provider' => sanitize_key( $descriptor['provider'] ),
				'class' => sanitize_key( $descriptor['class'] ),
				'pattern' => (string) $descriptor['pattern'],
			);
		}
		$server_descriptors = array();
		foreach ( self::server_callback_descriptors() as $descriptor ) {
			$server_descriptors[] = array(
				'provider' => sanitize_key( $descriptor['provider'] ),
				'server_id' => sanitize_text_field( $descriptor['server_id'] ),
				'callback' => sanitize_text_field( $descriptor['callback_class'] . '::' . $descriptor['callback_method'] ),
			);
		}
		$suppressed = array_values( self::$suppressed_server_callbacks );
		$server_ids = array();
		foreach ( $suppressed as $item ) {
			if ( isset( $item['server_id'] ) ) $server_ids[] = sanitize_text_field( $item['server_id'] );
		}

		return array(
			'contract' => self::CONTRACT,
			'configured' => self::configured(),
			'effective' => self::effective(),
			'environment' => $environment,
			'production_approved' => self::production_approved(),
			'default_server_suppressed' => self::effective(),
			'server_registration_suppression_attempted' => (bool) self::$suppression_attempted,
			'suppressed_server_count' => count( $suppressed ),
			'suppressed_server_ids' => array_values( array_unique( $server_ids ) ),
			'suppressed_server_callbacks' => array_slice( $suppressed, 0, 100 ),
			'server_callback_descriptors' => $server_descriptors,
			'removed_route_count' => count( self::$removed_routes ),
			'removed_routes' => array_slice( self::$removed_routes, 0, 100 ),
			'descriptors' => $route_descriptors,
			'unknown_routes_fail_closed' => true,
			'unknown_server_callbacks_fail_closed' => true,
			'changes_provider_settings' => false,
			'disables_provider_plugins' => false,
			'creates_authority' => false,
		);
	}
}
