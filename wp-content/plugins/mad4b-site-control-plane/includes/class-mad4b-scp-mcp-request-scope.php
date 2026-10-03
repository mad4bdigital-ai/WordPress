<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-Profile-governed request scope for the official MCP Adapter runtime.
 *
 * MAD4B only needs the official runtime for its own seven MCP transports and for
 * explicit Control Plane diagnostics. On the governed site origin, unrelated
 * REST requests (WPML, Core/Site Health, WooCommerce, Elementor, etc.) must not
 * enter a MAD4B-owned MCP lifecycle. A provider-owned/bundled Adapter runtime is
 * left untouched so the request retains the same host/provider baseline it has
 * when the MAD4B Control Plane is absent.
 *
 * Protocol isolation is deny-only. A verified endpoint job may re-arm the
 * official callback for one catalog; it never grants transport authority,
 * changes provider settings or replaces a foreign Adapter runtime. Eligibility is bound
 * to the exact enrolled Site Profile and its managed-runtime feature.
 */
final class MAD4B_SCP_MCP_Request_Scope {
	const CONTRACT = 'mad4b.mcp-request-scope.v1';
	const MAX_PROTOCOL_REST_CALLBACK_SCAN = 512;
	const MAX_PROTOCOL_REST_CALLBACK_EVIDENCE = 100;

	private static $booted = false;
	private static $endpoint_diagnostic_server_id = '';
	private static $eligible = false;
	private static $current_request_requires_mcp = false;
	private static $adapter_init_removed = false;
	private static $adapter_runtime_from_official = false;
	private static $adapter_suppression_skipped_non_official = false;
	private static $adapter_runtime_source = 'unavailable';
	private static $bridge_watchdog_removed = false;
	private static $rescue_tail_removed = false;
	private static $rescue_pre_dispatch_removed = false;
	private static $deferred_recovery_removed = false;
	private static $protocol_core_rest_isolation_evaluated = false;
	private static $protocol_core_rest_callbacks_removed = array();
	private static $protocol_external_rest_isolation_evaluated = false;
	private static $protocol_external_rest_callbacks_removed = array();
	private static $protocol_external_rest_callbacks_removed_count = 0;
	private static $protocol_external_rest_scan_truncated = false;
	private static $protocol_external_rest_callbacks_preserved = array();

	public static function bootstrap() {
		if ( self::$booted ) return;
		self::$booted = true;
		self::$eligible = self::governed_staging();
		if ( ! self::$eligible ) return;

		self::$current_request_requires_mcp = self::current_request_requires_mcp_runtime();

		// Exact MAD4B MCP/OAuth protocol requests need the REST dispatcher and
		// transport-specific routes, but not WordPress' full Core/settings route
		// materialization or unrelated third-party REST registrars. Keep the
		// isolation request-local: Core REST defaults, MAD4B and the official MCP
		// Adapter remain intact, while external plugin rest_api_init callbacks are
		// removed before they can perform provider discovery or route expansion.
		if ( self::current_request_is_protocol_hotpath() ) {
			add_action( 'rest_api_init', array( __CLASS__, 'isolate_protocol_core_rest_bootstrap' ), PHP_INT_MIN );
			add_action( 'rest_api_init', array( __CLASS__, 'isolate_protocol_external_rest_bootstrap' ), PHP_INT_MIN + 1 );
		}

		if ( self::$current_request_requires_mcp ) return;

		// Enforce immediately during normal plugin loading, again after every
		// plugin has loaded, and once more at the very start of REST bootstrap.
		// Only an official mcp-adapter-owned singleton can be disarmed here. If a
		// host/provider bundle owns the class, leave that baseline untouched.
		self::enforce();
		add_action( 'plugins_loaded', array( __CLASS__, 'enforce' ), PHP_INT_MAX );
		add_action( 'rest_api_init', array( __CLASS__, 'enforce' ), PHP_INT_MIN );
	}

	public static function enforce() {
		if ( ! self::$eligible || self::$current_request_requires_mcp ) return;

		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter', false ) ) {
			try {
				$reflection = new ReflectionClass( '\\WP\\MCP\\Core\\McpAdapter' );
				$file = $reflection->getFileName();
				$resolved = $file ? realpath( $file ) : false;
				$plugin_root = defined( 'WP_PLUGIN_DIR' ) ? realpath( WP_PLUGIN_DIR ) : false;
				$official_root = defined( 'WP_PLUGIN_DIR' ) ? realpath( trailingslashit( WP_PLUGIN_DIR ) . 'mcp-adapter' ) : false;
				if ( $resolved && $plugin_root ) {
					$normalized = wp_normalize_path( $resolved );
					$plugins_prefix = rtrim( wp_normalize_path( $plugin_root ), '/' ) . '/';
					self::$adapter_runtime_source = 0 === strpos( $normalized, $plugins_prefix )
						? ltrim( substr( $normalized, strlen( $plugins_prefix ) ), '/' )
						: 'outside-wp-plugin-dir';
					if ( $official_root ) {
						$official_prefix = rtrim( wp_normalize_path( $official_root ), '/' ) . '/';
						self::$adapter_runtime_from_official = 0 === strpos( $normalized, $official_prefix );
					}
				}

				if ( self::$adapter_runtime_from_official ) {
					$adapter = \WP\MCP\Core\McpAdapter::instance();
					$priority = has_action( 'rest_api_init', array( $adapter, 'init' ) );
					if ( false !== $priority && remove_action( 'rest_api_init', array( $adapter, 'init' ), (int) $priority ) ) {
						self::$adapter_init_removed = true;
					}
				} else {
					self::$adapter_suppression_skipped_non_official = true;
				}
			} catch ( Throwable $e ) {
				// Fail closed with respect to MAD4B: never reconstruct or replace an
				// unknown Adapter lifecycle on an unrelated request.
			}
		}

		// These are MAD4B-only recovery callbacks. They are removed only from an
		// unrelated current request; no provider or WordPress callback is touched.
		if ( class_exists( 'MAD4B_SCP_MCP_Registration_Bridge', false ) ) {
			if ( remove_action( 'rest_api_init', array( 'MAD4B_SCP_MCP_Registration_Bridge', 'verify_adapter_init_after_rest' ), PHP_INT_MAX ) ) {
				self::$bridge_watchdog_removed = true;
			}
			if ( remove_action( 'init', array( 'MAD4B_SCP_MCP_Registration_Bridge', 'recover_missed_rest_lifecycle' ), 9999 ) ) {
				self::$deferred_recovery_removed = true;
			}
		}
		if ( class_exists( 'MAD4B_SCP_MCP_Registration_Rescue', false ) ) {
			if ( remove_action( 'rest_api_init', array( 'MAD4B_SCP_MCP_Registration_Rescue', 'after_rest_init' ), PHP_INT_MAX - 1 ) ) {
				self::$rescue_tail_removed = true;
			}
			if ( remove_filter( 'rest_pre_dispatch', array( 'MAD4B_SCP_MCP_Registration_Rescue', 'before_rest_dispatch' ), -PHP_INT_MAX ) ) {
				self::$rescue_pre_dispatch_removed = true;
			}
		}
	}

	/**
	 * Passive Connection/ChatGPT admin pages are request-serving read surfaces.
	 * They consume cached/runtime-identity projections and must not retain the
	 * MCP Adapter merely because they live under the Control Plane menu. The
	 * explicit Connection > Endpoints tab remains the deep runtime diagnostic.
	 */
	private static function passive_admin_route( $page, $tab = '' ) {
		$page = sanitize_key( (string) $page );
		$tab = sanitize_key( (string) $tab );

		// Every Control Plane GET/HEAD screen is request-serving by default,
		// including Connection > MCP Endpoints. Deep endpoint materialization is
		// an explicit POST + nonce action and therefore never enters this read-only
		// classifier. This keeps opening any tab from becoming a lifecycle job.
		if ( 'mad4b-control-plane-connection' === $page ) return true;
		if ( 'mad4b-control-plane' === $page || 0 === strpos( $page, 'mad4b-control-plane-' ) ) return true;
		return in_array( $page, array( 'mad4b-adapter-coverage', 'mad4b-runtime-components', 'mad4b-approval-decisions' ), true );
	}

	/** @internal Pure regression seam; does not inspect request globals or WP_CLI. */
	public static function passive_admin_route_for_test( $page, $tab = '' ) {
		return self::passive_admin_route( $page, $tab );
	}

	private static function current_admin_request_method_is_read_only() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( trim( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- method classification only.
		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	public static function current_request_is_passive_admin_hotpath() {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) return false;
		// Classification only. A raw AJAX action keeps boot passive; it cannot arm
		// the Adapter until the worker verifies the administrator, nonce and target.
		if ( self::current_request_is_endpoint_diagnostic_job() ) return true;
		$page = isset( $_GET['page'] ) ? wp_unslash( (string) $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		if ( 'mad4b-control-plane-connection' === $page ) return true;
		if ( ! self::current_admin_request_method_is_read_only() ) return false;
		$tab = isset( $_GET['tab'] ) ? wp_unslash( (string) $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		return self::passive_admin_route( $page, $tab );
	}

	public static function current_request_is_endpoint_diagnostic_job() {
		return function_exists( 'is_admin' ) && is_admin()
			&& function_exists( 'wp_doing_ajax' ) && wp_doing_ajax()
			&& isset( $_POST['action'] ) && is_string( $_POST['action'] )
			&& 'mad4b_connection_endpoint_diagnostic' === $_POST['action']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- passive boot classification, never authorization.
	}

	public static function endpoint_diagnostic_server_id() { return self::$endpoint_diagnostic_server_id; }

	/** Arm only the proven official singleton, after the explicit job is authorized. */
	public static function begin_endpoint_diagnostic( $server_id ) {
		if ( ! class_exists( 'MAD4B_SCP_Provider_Diagnostic_Policy', false ) || ! MAD4B_SCP_Provider_Diagnostic_Policy::explicit_rest_materialization_allowed()
			|| ! self::current_request_is_endpoint_diagnostic_job() || ! current_user_can( 'manage_options' )
			|| ! in_array( $server_id, MAD4B_SCP_Servers::expected_server_ids(), true ) ) return new WP_Error( 'mad4b_endpoint_diagnostic_forbidden', 'An authorized endpoint job is required.', array( 'status' => 403 ) );
		// Existing catalogs cannot prove which single target was materialized.
		if ( did_action( 'rest_api_init' ) || did_action( 'mcp_adapter_init' ) || class_exists( 'MAD4B_SCP_Provider_Diagnostic_Policy', false ) && is_object( MAD4B_SCP_Provider_Diagnostic_Policy::current_rest_server() ) ) return new WP_Error( 'mad4b_endpoint_diagnostic_runtime_already_materialized', 'REST was initialized before the endpoint job.' );
		if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter', false ) ) return new WP_Error( 'mad4b_endpoint_diagnostic_adapter_unavailable', 'Official MCP Adapter is unavailable.' );
		$reflection = new ReflectionClass( '\\WP\\MCP\\Core\\McpAdapter' );
		$file = $reflection->getFileName();
		$resolved = $file ? realpath( $file ) : false;
		$official = defined( 'WP_PLUGIN_DIR' ) ? realpath( trailingslashit( WP_PLUGIN_DIR ) . 'mcp-adapter' ) : false;
		if ( ! $resolved || ! $official || 0 !== strpos( wp_normalize_path( $resolved ), rtrim( wp_normalize_path( $official ), '/' ) . '/' ) ) return new WP_Error( 'mad4b_endpoint_diagnostic_noncanonical_adapter', 'The loaded Adapter is not the official plugin.' );
		self::$endpoint_diagnostic_server_id = $server_id;
		self::$current_request_requires_mcp = true;
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		if ( false === has_action( 'rest_api_init', array( $adapter, 'init' ) ) ) add_action( 'rest_api_init', array( $adapter, 'init' ), 15 );
		// Match the real governed MCP request's REST bootstrap. These callbacks
		// remain request-local; unknown and MU callbacks are still preserved.
		if ( self::$eligible ) {
			add_action( 'rest_api_init', array( __CLASS__, 'isolate_protocol_core_rest_bootstrap' ), PHP_INT_MIN );
			add_action( 'rest_api_init', array( __CLASS__, 'isolate_protocol_external_rest_bootstrap' ), PHP_INT_MIN + 1 );
		}
		return true;
	}

	public static function isolate_protocol_core_rest_bootstrap() {
		if ( self::$protocol_core_rest_isolation_evaluated ) return;
		self::$protocol_core_rest_isolation_evaluated = true;
		if ( ! self::$eligible || ( ! self::current_request_is_protocol_hotpath() && '' === self::$endpoint_diagnostic_server_id ) ) return;

		foreach ( array( 'register_initial_settings', 'create_initial_rest_routes' ) as $callback ) {
			$priority = has_action( 'rest_api_init', $callback );
			if ( false === $priority ) continue;
			if ( remove_action( 'rest_api_init', $callback, (int) $priority ) ) {
				self::$protocol_core_rest_callbacks_removed[] = $callback;
			}
		}
		self::$protocol_core_rest_callbacks_removed = array_values( array_unique( self::$protocol_core_rest_callbacks_removed ) );
	}


	/**
	 * Exact MAD4B protocol requests must not pay the registration cost of every
	 * unrelated plugin's REST surface. The callback remains installed globally;
	 * it is removed only from the current request after provenance is proven to
	 * live under WP_PLUGIN_DIR and outside the two protocol owners we require.
	 * Unknown/unresolvable callbacks fail open.
	 */
	public static function isolate_protocol_external_rest_bootstrap() {
		if ( self::$protocol_external_rest_isolation_evaluated ) return;
		self::$protocol_external_rest_isolation_evaluated = true;
		if ( ! self::$eligible || ( ! self::current_request_is_protocol_hotpath() && '' === self::$endpoint_diagnostic_server_id ) ) return;

		global $wp_filter;
		if ( ! isset( $wp_filter['rest_api_init'] ) || ! ( $wp_filter['rest_api_init'] instanceof WP_Hook ) ) return;
		$callbacks = $wp_filter['rest_api_init']->callbacks;
		if ( ! is_array( $callbacks ) ) return;

		$scanned = 0;
		foreach ( $callbacks as $priority => $entries ) {
			if ( ! is_array( $entries ) ) continue;
			foreach ( $entries as $entry ) {
				++$scanned;
				if ( $scanned > self::MAX_PROTOCOL_REST_CALLBACK_SCAN ) {
					self::$protocol_external_rest_scan_truncated = true;
					break 2;
				}

				$callback = is_array( $entry ) && array_key_exists( 'function', $entry ) ? $entry['function'] : null;
				$descriptor = self::protocol_external_rest_callback_descriptor( $callback );
				if ( ! is_array( $descriptor ) ) continue;
				if ( ! remove_action( 'rest_api_init', $callback, (int) $priority ) ) continue;

				++self::$protocol_external_rest_callbacks_removed_count;
				if ( count( self::$protocol_external_rest_callbacks_removed ) < self::MAX_PROTOCOL_REST_CALLBACK_EVIDENCE ) {
					self::$protocol_external_rest_callbacks_removed[] = array(
						'source_plugin' => sanitize_key( (string) $descriptor['source_plugin'] ),
						'callback_kind' => sanitize_key( (string) $descriptor['callback_kind'] ),
						'callback_class' => sanitize_text_field( (string) $descriptor['callback_class'] ),
						'callback_method' => sanitize_text_field( (string) $descriptor['callback_method'] ),
						'callback_name' => sanitize_text_field( (string) $descriptor['callback_name'] ),
						'priority' => (int) $priority,
					);
				}
			}
		}
	}

	/**
	 * Return a bounded descriptor only when callback provenance is provably an
	 * ordinary third-party plugin file. Realpath escapes/symlinks, Core, MU
	 * plugins, MAD4B, the official Adapter and unreflectable callbacks fail open.
	 */
	private static function protocol_external_rest_callback_descriptor( $callback ) {
		$file = '';
		$class = '';
		$method = '';
		$name = '';
		$kind = '';

		try {
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : ( is_string( $callback[0] ) ? ltrim( $callback[0], '\\' ) : '' );
				$method = is_string( $callback[1] ) ? $callback[1] : '';
				if ( '' === $class || '' === $method ) return null;
				if ( ! is_object( $callback[0] ) && ! class_exists( $class, false ) ) return null;
				$reflection = new ReflectionMethod( $callback[0], $method );
				$file = (string) $reflection->getFileName();
				$kind = 'method';
			} elseif ( $callback instanceof Closure ) {
				$reflection = new ReflectionFunction( $callback );
				$file = (string) $reflection->getFileName();
				$kind = 'closure';
				$name = 'Closure';
			} elseif ( is_string( $callback ) && '' !== $callback ) {
				$name = $callback;
				if ( false !== strpos( $callback, '::' ) ) {
					list( $class, $method ) = array_map( 'strval', explode( '::', $callback, 2 ) );
					$class = ltrim( $class, '\\' );
					if ( '' === $class || '' === $method || ! class_exists( $class, false ) ) return null;
					$reflection = new ReflectionMethod( $class, $method );
					$file = (string) $reflection->getFileName();
					$kind = 'static_method';
				} else {
					if ( ! function_exists( $callback ) ) return null;
					$reflection = new ReflectionFunction( $callback );
					$file = (string) $reflection->getFileName();
					$kind = 'function';
				}
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$class = get_class( $callback );
				$method = '__invoke';
				$reflection = new ReflectionMethod( $callback, '__invoke' );
				$file = (string) $reflection->getFileName();
				$kind = 'invokable';
			}
		} catch ( Throwable $e ) {
			return null;
		}

		if ( '' === $file || ! defined( 'WP_PLUGIN_DIR' ) ) return null;
		$resolved = realpath( $file );
		$plugin_root = realpath( WP_PLUGIN_DIR );
		if ( false === $resolved || false === $plugin_root ) return null;

		$normalized = wp_normalize_path( $resolved );
		$plugin_prefix = rtrim( wp_normalize_path( $plugin_root ), '/' ) . '/';
		if ( 0 !== strpos( strtolower( $normalized ), strtolower( $plugin_prefix ) ) ) return null;

		$relative = ltrim( substr( $normalized, strlen( $plugin_prefix ) ), '/' );
		if ( '' === $relative || false !== strpos( $relative, '../' ) ) return null;
		$parts = explode( '/', $relative );
		$source_plugin = isset( $parts[0] ) ? preg_replace( '/\.php$/i', '', (string) $parts[0] ) : '';
		$source_plugin = sanitize_key( (string) $source_plugin );
		if ( '' === $source_plugin || in_array( $source_plugin, array( 'mad4b-site-control-plane', 'mcp-adapter' ), true ) ) return null;

		return array(
			'source_plugin' => $source_plugin,
			'callback_kind' => $kind,
			'callback_class' => $class,
			'callback_method' => $method,
			'callback_name' => $name,
		);
	}


	/**
	 * Return the exact addressed MAD4B MCP server id, or an empty string for
	 * non-MCP protocol requests and generic lifecycle calls.
	 */
	public static function current_request_mcp_server_id() {
		if ( ! self::current_request_is_http_mcp_transport() ) return '';
		$route = isset( $_GET['rest_route'] ) ? wp_unslash( (string) $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $route && '' !== $uri ) {
			$query = wp_parse_url( $uri, PHP_URL_QUERY );
			if ( is_string( $query ) && '' !== $query ) {
				$parsed = array();
				parse_str( $query, $parsed );
				if ( isset( $parsed['rest_route'] ) && is_string( $parsed['rest_route'] ) ) $route = $parsed['rest_route'];
			}
		}
		if ( '' === $route && '' !== $uri ) {
			$path = wp_parse_url( $uri, PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				$path = '/' . ltrim( rawurldecode( $path ), '/' );
				$prefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
				$needle = '/' . $prefix . '/';
				$offset = strpos( $path, $needle );
				$route = false !== $offset ? '/' . ltrim( substr( $path, $offset + strlen( $needle ) ), '/' ) : $path;
			}
		}
		$route = '/' . ltrim( rtrim( (string) $route, '/' ), '/' );
		if ( 0 !== strpos( $route, '/mcp/mad4b-' ) ) return '';
		$server_id = substr( $route, strlen( '/mcp/' ) );
		return in_array( $server_id, array(
			'mad4b-read',
			'mad4b-chatgpt',
			'mad4b-enrollment',
			'mad4b-content',
			'mad4b-write',
			'mad4b-admin',
			'mad4b-developer',
			'mad4b-developer-breakglass',
			'mad4b-breakglass',
		), true ) ? $server_id : '';
	}

	private static function cli_mcp_opt_in() {
		if ( ! ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) ) return false;
		if ( defined( 'MAD4B_SCP_MCP_CLI_REQUEST' ) && constant( 'MAD4B_SCP_MCP_CLI_REQUEST' ) ) return true;
		return '1' === (string) getenv( 'MAD4B_SCP_MCP_CLI_REQUEST' );
	}

	public static function current_request_requires_mcp_runtime() {
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return self::cli_mcp_opt_in();
		if ( self::current_request_is_endpoint_diagnostic_job() ) return '' !== self::$endpoint_diagnostic_server_id;

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		if ( self::current_request_is_passive_admin_hotpath() ) return false;
		if ( '' !== $page && 0 === strpos( $page, 'mad4b-control-plane' ) ) return true;

		$route = '';
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
			$route = wp_unslash( (string) $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed only.
		if ( '' === $route && '' !== $uri ) {
			$query = wp_parse_url( $uri, PHP_URL_QUERY );
			if ( is_string( $query ) && '' !== $query ) {
				$parsed = array();
				parse_str( $query, $parsed );
				if ( isset( $parsed['rest_route'] ) && is_string( $parsed['rest_route'] ) ) $route = $parsed['rest_route'];
			}
		}

		if ( self::is_mad4b_mcp_route( $route ) ) return true;
		if ( '' === $uri ) return false;

		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) return false;
		$path = '/' . ltrim( rawurldecode( $path ), '/' );
		$prefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
		$needle = '/' . $prefix . '/';
		$offset = strpos( $path, $needle );
		if ( false !== $offset ) $path = '/' . ltrim( substr( $path, $offset + strlen( $needle ) ), '/' );
		return self::is_mad4b_mcp_route( $path );
	}

	/**
	 * True only for an actual HTTP MAD4B MCP transport request.
	 *
	 * WP-CLI and explicit deep Control Plane diagnostics still require the MCP
	 * runtime, but passive Connection/ChatGPT pages do not. None of these admin
	 * surfaces are client Refresh hot paths and
	 * must never inherit transport-only short circuits.
	 */
	public static function current_request_is_http_mcp_transport() {
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return false;

		$route = isset( $_GET['rest_route'] ) ? wp_unslash( (string) $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		if ( self::is_mad4b_mcp_route( $route ) ) return true;

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed only.
		if ( '' === $uri ) return false;
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) return false;
		$path = '/' . ltrim( rawurldecode( $path ), '/' );
		$prefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
		$needle = '/' . $prefix . '/';
		$offset = strpos( $path, $needle );
		if ( false !== $offset ) $path = '/' . ltrim( substr( $path, $offset + strlen( $needle ) ), '/' );
		return self::is_mad4b_mcp_route( $path );
	}

	/**
	 * Latency-sensitive MAD4B protocol requests. This is intentionally broader
	 * than the MCP Adapter runtime scope: OAuth discovery/protocol endpoints need
	 * a lightweight plugin boot, but must NOT keep the MCP Adapter enabled.
	 */
	public static function current_request_is_protocol_hotpath() {
		if ( self::current_request_is_http_mcp_transport() ) return true;

		$route = isset( $_GET['rest_route'] ) ? wp_unslash( (string) $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		$route = '/' . ltrim( rtrim( (string) $route, '/' ), '/' );
		if ( '/mad4b/v1/oauth-protected-resource' === $route ) return true;

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed only.
		if ( '' === $uri ) return false;
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) return false;
		$path = '/' . ltrim( rawurldecode( $path ), '/' );

		$prefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
		$needle = '/' . $prefix . '/';
		$offset = strpos( $path, $needle );
		$rest_path = false !== $offset ? '/' . ltrim( substr( $path, $offset + strlen( $needle ) ), '/' ) : $path;
		$rest_path = '/' . ltrim( rtrim( $rest_path, '/' ), '/' );
		if ( '/mad4b/v1/oauth-protected-resource' === $rest_path ) return true;

		$path = '/' . ltrim( rtrim( $path, '/' ), '/' );
		foreach ( array(
			'/.well-known/oauth-protected-resource',
			'/.well-known/oauth-authorization-server',
		) as $well_known ) {
			if ( $path === $well_known || 0 === strpos( $path, $well_known . '/' ) ) return true;
		}
		return in_array( $path, array(
			'/oauth/mcp/authorize',
			'/oauth/mcp/token',
			'/oauth/mcp/jwks',
			'/oauth/mcp/revoke',
		), true );
	}

	private static function is_mad4b_mcp_route( $route ) {
		$route = '/' . ltrim( rtrim( (string) $route, '/' ), '/' );
		return in_array( $route, array(
			'/mcp/mad4b-read',
			'/mcp/mad4b-chatgpt',
			'/mcp/mad4b-enrollment',
			'/mcp/mad4b-content',
			'/mcp/mad4b-write',
			'/mcp/mad4b-admin',
			'/mcp/mad4b-developer',
			'/mcp/mad4b-developer-breakglass',
			'/mcp/mad4b-breakglass',
		), true );
	}

	private static function governed_staging() {
		return class_exists( 'MAD4B_SCP_Site_Profile' )
			&& MAD4B_SCP_Site_Profile::origin_enrolled()
			&& MAD4B_SCP_Site_Profile::managed_runtime_enabled();
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'eligible' => self::$eligible,
			'endpoint_diagnostic_server_id' => self::$endpoint_diagnostic_server_id,
			'current_request_requires_mcp_runtime' => self::$current_request_requires_mcp,
			'current_request_is_passive_admin_hotpath' => self::current_request_is_passive_admin_hotpath(),
			'current_request_is_http_mcp_transport' => self::current_request_is_http_mcp_transport(),
			'current_request_is_protocol_hotpath' => self::current_request_is_protocol_hotpath(),
			'adapter_init_removed_for_unrelated_request' => self::$adapter_init_removed,
			'adapter_runtime_from_official_plugin' => self::$adapter_runtime_from_official,
			'adapter_suppression_skipped_non_official_runtime' => self::$adapter_suppression_skipped_non_official,
			'adapter_runtime_source' => self::$adapter_runtime_source,
			'bridge_watchdog_removed_for_unrelated_request' => self::$bridge_watchdog_removed,
			'rescue_tail_removed_for_unrelated_request' => self::$rescue_tail_removed,
			'rescue_pre_dispatch_removed_for_unrelated_request' => self::$rescue_pre_dispatch_removed,
			'deferred_recovery_removed_for_unrelated_request' => self::$deferred_recovery_removed,
			'protocol_core_rest_isolation_evaluated' => self::$protocol_core_rest_isolation_evaluated,
			'protocol_core_rest_callbacks_removed' => self::$protocol_core_rest_callbacks_removed,
			'protocol_external_rest_isolation_evaluated' => self::$protocol_external_rest_isolation_evaluated,
			'protocol_external_rest_callbacks_removed_count' => (int) self::$protocol_external_rest_callbacks_removed_count,
			'protocol_external_rest_callbacks_removed' => array_values( self::$protocol_external_rest_callbacks_removed ),
			'protocol_external_rest_callbacks_preserved' => array_values( self::$protocol_external_rest_callbacks_preserved ),
			'protocol_external_rest_scan_limit' => self::MAX_PROTOCOL_REST_CALLBACK_SCAN,
			'protocol_external_rest_scan_truncated' => self::$protocol_external_rest_scan_truncated,
			'protocol_external_rest_unknown_callbacks_preserved' => true,
			'protocol_external_rest_mu_plugin_callbacks_preserved' => true,
			'current_request_mcp_server_id' => self::current_request_mcp_server_id(),
			'protocol_core_rest_isolation_request_local_only' => true,
			'protocol_external_rest_isolation_request_local_only' => true,
			'production_changed' => false,
			'provider_settings_changed' => false,
			'wordpress_rest_routes_changed' => false,
		);
	}
}
