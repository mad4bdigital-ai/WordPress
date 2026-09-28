<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Explicit deny-only isolation for provider-native MCP/AI surfaces.
 *
 * Runtime suppression is fail-closed everywhere. On the exact governed site
 * origin only, the existing isolation intent and runtime-suppression gates are
 * auto-configured unless either was explicitly set false by an operator. This
 * preserves profile-driven runtime isolation while keeping every other origin fail-closed.
 *
 * When both gates are enabled it suppresses only bounded, reviewed provider MCP
 * registrations and REST/control routes. It never grants MAD4B authority,
 * changes provider settings, creates credentials, disables the provider plugin,
 * or auto-enables mutation. Unknown routes and unknown server callbacks remain
 * untouched and therefore remain visible to fail-closed peer governance.
 */
if ( class_exists( 'WP_REST_Server' ) && ! class_exists( 'MAD4B_SCP_Internal_Provider_REST_Dispatcher', false ) ) {
	final class MAD4B_SCP_Internal_Provider_REST_Dispatcher extends WP_REST_Server {
		public function dispatch_retained( WP_REST_Request $request, $route, array $handler, array $url_params = array(), $bypass_provider_permission = false ) {
			$request->set_url_params( $url_params );

			// Retained JetEngine routes are no longer external transports. The outer
			// MAD4B Ability permission/authorization layer owns read access, NHI,
			// exact one-time approval and replay denial before execution can reach
			// this handoff. Replaying the provider's external REST authentication
			// here makes the isolated bridge require credentials that were
			// intentionally removed with the raw provider route.
			$effective_handler = $handler;
			$provider_permission_present = isset( $handler['permission_callback'] ) && is_callable( $handler['permission_callback'] );
			$permission_result = 'provider_permission_evaluated';
			if ( $bypass_provider_permission ) {
				$effective_handler['permission_callback'] = '__return_true';
				$permission_result = 'bypassed_external_provider_permission';
			}

			$callback_reached = false;
			if ( ! empty( $effective_handler['callback'] ) && is_callable( $effective_handler['callback'] ) ) {
				$provider_callback = $effective_handler['callback'];
				$effective_handler['callback'] = static function ( $callback_request ) use ( $provider_callback, &$callback_reached ) {
					$callback_reached = true;
					return call_user_func( $provider_callback, $callback_request );
				};
			}
			$request->set_attributes( $effective_handler );

			$defaults = array();
			foreach ( isset( $effective_handler['args'] ) && is_array( $effective_handler['args'] ) ? $effective_handler['args'] : array() as $arg => $options ) {
				if ( is_array( $options ) && isset( $options['default'] ) ) $defaults[ $arg ] = $options['default'];
			}
			$request->set_default_params( $defaults );

			$error = null;
			if ( empty( $effective_handler['callback'] ) || ! is_callable( $effective_handler['callback'] ) ) {
				$error = new WP_Error( 'mad4b_internal_provider_handler_invalid', 'Retained provider route handler is not callable.', array( 'status' => 500 ) );
			}
			if ( ! is_wp_error( $error ) ) {
				$valid = $request->has_valid_params();
				if ( is_wp_error( $valid ) ) {
					$error = $valid;
				} else {
					$sanitized = $request->sanitize_params();
					if ( is_wp_error( $sanitized ) ) $error = $sanitized;
				}
			}
			$response = $this->respond_to_request( $request, (string) $route, $effective_handler, $error );
			if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
				$response->header( 'X-MAD4B-Internal-Provider-Permission', $permission_result );
				$response->header( 'X-MAD4B-Internal-Provider-Permission-Present', $provider_permission_present ? '1' : '0' );
				$response->header( 'X-MAD4B-Internal-Provider-Callback-Reached', $callback_reached ? '1' : '0' );
			}
			return $response;
		}
	}
}

final class MAD4B_SCP_MCP_Provider_Isolation {
	const CONTRACT = 'mad4b.mcp-provider-isolation.v3';
	const PREVIOUS_CONTRACT = 'mad4b.mcp-provider-isolation.v2';
	const LEGACY_CONTRACT = 'mad4b.mcp-provider-isolation.v1';
	const ENABLE_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_ENABLED';
	const RUNTIME_SUPPRESSION_APPROVAL_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_RUNTIME_SUPPRESSION_APPROVED';
	const PRODUCTION_APPROVAL_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_PRODUCTION_APPROVED';

	private static $early_booted = false;
	private static $booted = false;
	private static $removed_routes = array();
	private static $internal_provider_routes = array();
	private static $suppression_attempted = false;
	private static $suppressed_server_callbacks = array();
	private static $staging_autoconfig_evaluated = false;
	private static $staging_autoconfig_applied = false;
	private static $staging_autoconfig_blocker = '';
	private static $staging_autoconfig_source = 'none';

	/** Register provider-owned kill switches before plugins_loaded callbacks run. */
	public static function boot_early() {
		if ( self::$early_booted ) return;
		self::$early_booted = true;

		self::bootstrap_governed_staging();

		// wp-media/mcp-oauth owns an independent Adapter server named
		// mcp-oauth-server. When MAD4B isolation is effective, disable that
		// provider server through its documented filter instead of allowlisting it
		// or mutating the Adapter registry after registration.
		add_filter( 'wpmedia_mcp_oauth_server_enabled', array( __CLASS__, 'filter_wpmedia_oauth_server_enabled' ), PHP_INT_MIN );
	}

	public static function boot() {
		self::boot_early();
		if ( self::$booted ) return;
		self::$booted = true;

		add_filter( 'mcp_adapter_create_default_server', array( __CLASS__, 'filter_default_server' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'suppress_provider_server_registrations' ), 14 );
		add_action( 'init', array( __CLASS__, 'suppress_provider_server_registrations' ), 19 );
		add_action( 'mcp_adapter_init', array( __CLASS__, 'suppress_provider_server_registrations' ), -1000000 );
		add_filter( 'rest_endpoints', array( __CLASS__, 'filter_rest_endpoints' ), PHP_INT_MAX );
	}

	private static function bootstrap_governed_staging() {
		if ( self::$staging_autoconfig_evaluated ) return;
		self::$staging_autoconfig_evaluated = true;

		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$enable_flag_preexisting = defined( self::ENABLE_FLAG );
		$runtime_flag_preexisting = defined( self::RUNTIME_SUPPRESSION_APPROVAL_FLAG );
		$profile_enrolled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled();
		$profile_allows = $profile_enrolled && MAD4B_SCP_Site_Profile::provider_isolation_enabled();
		$portable_readonly = class_exists( 'MAD4B_SCP_Portable_Readonly_Connection' )
			&& MAD4B_SCP_Portable_Readonly_Connection::effective();

		if ( $profile_enrolled && ! $profile_allows ) {
			self::$staging_autoconfig_blocker = 'site_profile_provider_isolation_disabled';
			return;
		}

		if ( ! $profile_allows ) {
			// A fresh HTTPS installation may expose the tenant-neutral portable
			// read-only OAuth resource before a Site Profile exists. Provider
			// isolation is deny-only and creates no authority, so on non-Production
			// environments it may safely protect that bootstrap transport from
			// reviewed provider-native MCP side channels. Unknown routes still
			// remain visible and fail closed.
			if ( ! $portable_readonly ) {
				self::$staging_autoconfig_blocker = 'site_profile_not_enrolled_and_portable_readonly_unavailable';
				return;
			}
			if ( ! in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) {
				self::$staging_autoconfig_blocker = 'portable_readonly_isolation_nonproduction_only';
				return;
			}
			// Do not reinterpret a pre-existing legacy isolation intent flag as
			// runtime suppression approval. Portable zero-touch isolation is only
			// synthesized when neither isolation gate was operator-defined before
			// bootstrap. This preserves the two-gate legacy safety contract.
			if ( $enable_flag_preexisting && ! $runtime_flag_preexisting ) {
				self::$staging_autoconfig_blocker = 'explicit_isolation_intent_requires_runtime_suppression_approval';
				return;
			}
			self::$staging_autoconfig_source = 'portable_readonly_bootstrap';
		} else {
			self::$staging_autoconfig_source = 'site_profile';
			if ( class_exists( 'MAD4B_SCP_Site_Profile' ) ) $environment = MAD4B_SCP_Site_Profile::current_environment();
		}

		if ( 'production' === $environment && ! self::production_approved() ) {
			self::$staging_autoconfig_blocker = 'production_isolation_approval_required';
			return;
		}

		if ( defined( self::ENABLE_FLAG ) && true !== constant( self::ENABLE_FLAG ) ) {
			self::$staging_autoconfig_blocker = 'explicit_isolation_disabled';
			return;
		}
		if ( ! defined( self::ENABLE_FLAG ) ) define( self::ENABLE_FLAG, true );

		if ( defined( self::RUNTIME_SUPPRESSION_APPROVAL_FLAG ) && true !== constant( self::RUNTIME_SUPPRESSION_APPROVAL_FLAG ) ) {
			self::$staging_autoconfig_blocker = 'explicit_runtime_suppression_disabled';
			return;
		}
		if ( ! defined( self::RUNTIME_SUPPRESSION_APPROVAL_FLAG ) ) define( self::RUNTIME_SUPPRESSION_APPROVAL_FLAG, true );

		self::$staging_autoconfig_applied = true;
		self::$staging_autoconfig_blocker = '';
	}

	public static function configured() {
		return defined( self::ENABLE_FLAG ) && true === constant( self::ENABLE_FLAG );
	}

	public static function runtime_suppression_approved() {
		return defined( self::RUNTIME_SUPPRESSION_APPROVAL_FLAG ) && true === constant( self::RUNTIME_SUPPRESSION_APPROVAL_FLAG );
	}

	public static function production_approved() {
		return defined( self::PRODUCTION_APPROVAL_FLAG ) && true === constant( self::PRODUCTION_APPROVAL_FLAG );
	}

	public static function effective() {
		if ( ! self::configured() || ! self::runtime_suppression_approved() ) return false;
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		if ( 'production' === $environment && ! self::production_approved() ) return false;
		return in_array( $environment, array( 'staging', 'development', 'local', 'production' ), true );
	}

	public static function filter_default_server( $create ) {
		return self::effective() ? false : (bool) $create;
	}

	public static function filter_wpmedia_oauth_server_enabled( $enabled ) {
		return self::effective() ? false : (bool) $enabled;
	}

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
			if ( ! empty( $descriptor['internal_retention'] ) && 'mcp_execution_surface' === (string) $descriptor['class'] ) {
				self::retain_internal_provider_route( (string) $route, isset( $endpoints[ $route ] ) ? $endpoints[ $route ] : array() );
			}
			self::$removed_routes[] = substr( (string) $route, 0, 255 );
			unset( $endpoints[ $route ] );
		}
		self::$removed_routes = array_values( array_unique( self::$removed_routes ) );
		return $endpoints;
	}

	private static function retain_internal_provider_route( $route, $definition ) {
		$route = (string) $route;
		if ( ! is_array( $definition ) || count( self::$internal_provider_routes ) >= 32 ) return;
		$descriptor = self::descriptor_for_route( $route );
		if ( ! $descriptor || empty( $descriptor['internal_retention'] ) || 'mcp_execution_surface' !== (string) $descriptor['class'] ) return;
		self::$internal_provider_routes[ $route ] = array( 'definition' => $definition, 'descriptor' => $descriptor );
	}

	public static function internal_provider_transport_status( $provider ) {
		$provider = sanitize_key( (string) $provider );
		$registry = false;
		$run = false;
		$count = 0;
		foreach ( self::$internal_provider_routes as $route => $retained ) {
			$descriptor = isset( $retained['descriptor'] ) && is_array( $retained['descriptor'] ) ? $retained['descriptor'] : array();
			if ( $provider !== sanitize_key( isset( $descriptor['provider'] ) ? (string) $descriptor['provider'] : '' ) ) continue;
			++$count;
			$purpose = isset( $descriptor['purpose'] ) ? sanitize_key( (string) $descriptor['purpose'] ) : '';
			if ( 'registry' === $purpose ) $registry = true;
			if ( 'execute' === $purpose ) $run = true;
		}
		return array(
			'provider' => $provider,
			'isolation_effective' => self::effective(),
			'retained_route_count' => $count,
			'registry_available' => $registry,
			'run_available' => $run,
			'raw_routes_exposed' => false,
			'internal_permission_mode' => 'mad4b-governed-provider-permission-bypass',
		);
	}

	private static function method_allowed( array $handler, $method ) {
		$method = strtoupper( (string) $method );
		$methods = isset( $handler['methods'] ) ? $handler['methods'] : array();
		if ( is_string( $methods ) ) {
			$methods = array_map( 'trim', explode( ',', strtoupper( $methods ) ) );
			return in_array( $method, $methods, true ) || ( 'HEAD' === $method && in_array( 'GET', $methods, true ) );
		}
		if ( ! is_array( $methods ) ) return false;
		if ( ! empty( $methods[ $method ] ) ) return true;
		return 'HEAD' === $method && ! empty( $methods['GET'] );
	}

	private static function retained_route_match( $actual_route ) {
		$actual_route = '/' . ltrim( (string) $actual_route, '/' );
		foreach ( self::$internal_provider_routes as $route_pattern => $retained ) {
			$definition = isset( $retained['definition'] ) && is_array( $retained['definition'] ) ? $retained['definition'] : array();
			$descriptor = isset( $retained['descriptor'] ) && is_array( $retained['descriptor'] ) ? $retained['descriptor'] : self::descriptor_for_route( (string) $route_pattern );
			if ( ! $descriptor || empty( $descriptor['internal_retention'] ) || 'mcp_execution_surface' !== (string) $descriptor['class'] ) continue;
			$regex = '#^' . str_replace( '#', '\\#', (string) $route_pattern ) . '$#';
			$matches = array();
			if ( 1 !== @preg_match( $regex, $actual_route, $matches ) ) continue;
			$params = array();
			foreach ( $matches as $key => $value ) if ( is_string( $key ) ) $params[ $key ] = $value;
			return array( 'route' => (string) $route_pattern, 'definition' => $definition, 'params' => $params, 'descriptor' => $descriptor );
		}
		return null;
	}

	public static function dispatch_internal_provider_request( $provider, $request ) {
		if ( ! self::effective() ) return new WP_Error( 'mad4b_internal_provider_isolation_inactive', 'Internal provider handoff requires effective provider isolation.' );
		$provider = sanitize_key( (string) $provider );
		if ( '' === $provider ) return new WP_Error( 'mad4b_internal_provider_not_allowed', 'Internal provider handoff requires a cataloged provider.' );
		if ( ! ( $request instanceof WP_REST_Request ) ) return new WP_Error( 'mad4b_internal_provider_request_invalid', 'Internal provider handoff requires a REST request object.' );
		if ( ! class_exists( 'MAD4B_SCP_Internal_Provider_REST_Dispatcher', false ) ) return new WP_Error( 'mad4b_internal_provider_dispatcher_unavailable', 'Internal provider REST dispatcher is unavailable.' );

		$actual_route = '/' . ltrim( (string) $request->get_route(), '/' );
		$matched = self::retained_route_match( $actual_route );
		if ( ! is_array( $matched ) ) return new WP_Error( 'mad4b_internal_provider_route_unavailable', 'The isolated provider route was not retained for internal governed use.' );
		$descriptor = isset( $matched['descriptor'] ) && is_array( $matched['descriptor'] ) ? $matched['descriptor'] : array();
		if ( $provider !== sanitize_key( isset( $descriptor['provider'] ) ? (string) $descriptor['provider'] : '' ) || empty( $descriptor['internal_retention'] ) ) {
			return new WP_Error( 'mad4b_internal_provider_route_not_allowed', 'Requested provider route is not cataloged for internal retention.' );
		}
		$allowed_methods = isset( $descriptor['methods'] ) && is_array( $descriptor['methods'] ) ? array_map( 'strtoupper', $descriptor['methods'] ) : array();
		if ( ! in_array( strtoupper( $request->get_method() ), $allowed_methods, true ) ) {
			return new WP_Error( 'mad4b_internal_provider_method_not_allowed', 'Requested method is not cataloged for the retained provider route.' );
		}
		$definition = isset( $matched['definition'] ) && is_array( $matched['definition'] ) ? $matched['definition'] : array();
		$handlers = isset( $definition['callback'] ) ? array( $definition ) : $definition;
		foreach ( $handlers as $key => $handler ) {
			if ( ! is_int( $key ) && ! isset( $definition['callback'] ) ) continue;
			if ( ! is_array( $handler ) || ! self::method_allowed( $handler, $request->get_method() ) ) continue;
			$dispatcher = new MAD4B_SCP_Internal_Provider_REST_Dispatcher();
			return $dispatcher->dispatch_retained(
				$request,
				isset( $matched['route'] ) ? (string) $matched['route'] : $actual_route,
				$handler,
				isset( $matched['params'] ) && is_array( $matched['params'] ) ? $matched['params'] : array(),
				true
			);
		}
		return new WP_Error( 'mad4b_internal_provider_handler_unavailable', 'No retained provider handler accepts the requested method.' );
	}

	public static function descriptors() {
		if ( class_exists( 'MAD4B_SCP_Provider_Transport_Registry' ) ) {
			$registered = MAD4B_SCP_Provider_Transport_Registry::route_descriptors();
			if ( is_array( $registered ) && ! empty( $registered ) ) return $registered;
		}
		// Fail closed if the declarative registry is unavailable. An empty descriptor
		// set does not hide unknown transports; Peer Governance still observes them.
		return array();
	}

	public static function server_callback_descriptors() {
		if ( class_exists( 'MAD4B_SCP_Provider_Transport_Registry' ) ) {
			$registered = MAD4B_SCP_Provider_Transport_Registry::server_callback_descriptors();
			if ( is_array( $registered ) ) return $registered;
		}
		return array();
	}

	public static function descriptor_for_route( $route ) {
		$route = '/' . ltrim( (string) $route, '/' );
		foreach ( self::descriptors() as $descriptor ) if ( preg_match( $descriptor['pattern'], $route ) ) return $descriptor;
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
		foreach ( self::descriptors() as $descriptor ) $route_descriptors[] = array( 'provider' => sanitize_key( $descriptor['provider'] ), 'class' => sanitize_key( $descriptor['class'] ), 'pattern' => (string) $descriptor['pattern'] );
		$server_descriptors = array();
		foreach ( self::server_callback_descriptors() as $descriptor ) $server_descriptors[] = array( 'provider' => sanitize_key( $descriptor['provider'] ), 'server_id' => sanitize_text_field( $descriptor['server_id'] ), 'callback' => sanitize_text_field( $descriptor['callback_class'] . '::' . $descriptor['callback_method'] ) );
		$suppressed = array_values( self::$suppressed_server_callbacks );
		$server_ids = array();
		foreach ( $suppressed as $item ) if ( isset( $item['server_id'] ) ) $server_ids[] = sanitize_text_field( $item['server_id'] );

		return array(
			'contract' => self::CONTRACT,
			'configured' => self::configured(),
			'runtime_suppression_approved' => self::runtime_suppression_approved(),
			'effective' => self::effective(),
			'environment' => $environment,
			'production_approved' => self::production_approved(),
			'legacy_enable_flag_alone_is_non_mutating' => true,
			'staging_zero_touch_autoconfig_evaluated' => self::$staging_autoconfig_evaluated,
			'staging_zero_touch_autoconfig_applied' => self::$staging_autoconfig_applied,
			'staging_zero_touch_autoconfig_blocker' => self::$staging_autoconfig_blocker,
			'staging_zero_touch_autoconfig_source' => self::$staging_autoconfig_source,
			'governed_profile_origin' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_origin() : '',
			'production_auto_configured' => false,
			'runtime_suppression_requires_second_gate' => true,
			'default_server_suppressed' => self::effective(),
			'wpmedia_oauth_server_suppressed' => self::effective(),
			'server_registration_suppression_attempted' => (bool) self::$suppression_attempted,
			'suppressed_server_count' => count( $suppressed ),
			'suppressed_server_ids' => array_values( array_unique( $server_ids ) ),
			'suppressed_server_callbacks' => array_slice( $suppressed, 0, 100 ),
			'server_callback_descriptors' => $server_descriptors,
			'removed_route_count' => count( self::$removed_routes ),
			'internal_handoff' => self::internal_provider_transport_status( 'jetengine' ),
			'transport_registry' => class_exists( 'MAD4B_SCP_Provider_Transport_Registry' ) ? MAD4B_SCP_Provider_Transport_Registry::status() : array( 'ready' => false ),
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
