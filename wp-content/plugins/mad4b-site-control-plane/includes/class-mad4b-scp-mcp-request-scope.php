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
 * This class is deny-only. It never registers a route, never changes provider
 * settings, and never replaces a foreign Adapter runtime. Eligibility is bound
 * to the exact enrolled Site Profile and its managed-runtime feature.
 */
final class MAD4B_SCP_MCP_Request_Scope {
	const CONTRACT = 'mad4b.mcp-request-scope.v1';

	private static $booted = false;
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
		if ( 'mad4b-control-plane-chatgpt' === $page ) return true;
		if ( 'mad4b-control-plane-connection' !== $page ) return false;
		if ( '' === $tab ) $tab = 'readiness';
		return 'endpoints' !== $tab;
	}

	/** @internal Pure regression seam; does not inspect request globals or WP_CLI. */
	public static function passive_admin_route_for_test( $page, $tab = '' ) {
		return self::passive_admin_route( $page, $tab );
	}

	public static function current_request_is_passive_admin_hotpath() {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) return false;
		$page = isset( $_GET['page'] ) ? wp_unslash( (string) $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		$tab = isset( $_GET['tab'] ) ? wp_unslash( (string) $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		return self::passive_admin_route( $page, $tab );
	}

	public static function isolate_protocol_core_rest_bootstrap() {
		if ( self::$protocol_core_rest_isolation_evaluated ) return;
		self::$protocol_core_rest_isolation_evaluated = true;
		if ( ! self::$eligible || ! self::current_request_is_protocol_hotpath() ) return;

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
		if ( ! self::$eligible || ! self::current_request_is_protocol_hotpath() ) return;

		global $wp_filter;
		if ( ! isset( $wp_filter['rest_api_init'] ) || ! ( $wp_filter['rest_api_init'] instanceof WP_Hook ) ) return;
		$callbacks = $wp_filter['rest_api_init']->callbacks;
		if ( ! is_array( $callbacks ) ) return;

		foreach ( $callbacks as $priority => $entries ) {
			if ( ! is_array( $entries ) ) continue;
			foreach ( $entries as $entry ) {
				$callback = isset( $entry['function'] ) ? $entry['function'] : null;
				$descriptor = self::protocol_rest_callback_descriptor( $callback );
				if ( ! is_array( $descriptor ) ) continue;
				if ( empty( $descriptor['external_plugin'] ) ) {
					if ( ! empty( $descriptor['source'] ) ) self::$protocol_external_rest_callbacks_preserved[] = $descriptor['source'];
					continue;
				}
				if ( ! remove_action( 'rest_api_init', $callback, (int) $priority ) ) continue;
				self::$protocol_external_rest_callbacks_removed[] = array(
					'priority' => (int) $priority,
					'source' => isset( $descriptor['source'] ) ? sanitize_text_field( (string) $descriptor['source'] ) : '',
					'callback' => isset( $descriptor['callback'] ) ? sanitize_text_field( (string) $descriptor['callback'] ) : '',
				);
			}
		}
		self::$protocol_external_rest_callbacks_preserved = array_values( array_unique( self::$protocol_external_rest_callbacks_preserved ) );
	}

	private static function protocol_rest_callback_descriptor( $callback ) {
		$file = '';
		$label = '';
		try {
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$left = $callback[0];
				$class = is_object( $left ) ? get_class( $left ) : ( is_string( $left ) ? ltrim( $left, '\\' ) : '' );
				$method = is_string( $callback[1] ) ? $callback[1] : '';
				$label = $class . ( '' !== $method ? '::' . $method : '' );
				if ( '' !== $class && '' !== $method && method_exists( $class, $method ) ) {
					$file = (string) ( new ReflectionMethod( $class, $method ) )->getFileName();
				}
			} elseif ( $callback instanceof Closure ) {
				$reflection = new ReflectionFunction( $callback );
				$file = (string) $reflection->getFileName();
				$label = 'Closure';
			} elseif ( is_string( $callback ) && '' !== $callback ) {
				$label = $callback;
				if ( false !== strpos( $callback, '::' ) ) {
					list( $class, $method ) = array_map( 'strval', explode( '::', $callback, 2 ) );
					$class = ltrim( $class, '\\' );
					if ( '' !== $class && '' !== $method && method_exists( $class, $method ) ) {
						$file = (string) ( new ReflectionMethod( $class, $method ) )->getFileName();
					}
				} elseif ( function_exists( $callback ) ) {
					$file = (string) ( new ReflectionFunction( $callback ) )->getFileName();
				}
			}
		} catch ( Throwable $e ) {
			return null;
		}
		if ( '' === $file || ! defined( 'WP_PLUGIN_DIR' ) ) return null;

		$resolved = realpath( $file );
		$plugin_root = realpath( WP_PLUGIN_DIR );
		if ( ! $resolved || ! $plugin_root ) return null;
		$normalized = wp_normalize_path( $resolved );
		$plugins = rtrim( wp_normalize_path( $plugin_root ), '/' ) . '/';
		if ( 0 !== strpos( $normalized, $plugins ) ) return array(
			'external_plugin' => false,
			'source' => 'wordpress-or-outside-plugin-dir',
			'callback' => $label,
		);

		$relative = ltrim( substr( $normalized, strlen( $plugins ) ), '/' );
		foreach ( array( 'mad4b-site-control-plane/', 'mcp-adapter/' ) as $allowed_prefix ) {
			if ( 0 === strpos( $relative, $allowed_prefix ) ) return array(
				'external_plugin' => false,
				'source' => $relative,
				'callback' => $label,
			);
		}
		return array(
			'external_plugin' => true,
			'source' => $relative,
			'callback' => $label,
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

	public static function current_request_requires_mcp_runtime() {
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return true;

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
			'protocol_external_rest_callbacks_removed' => self::$protocol_external_rest_callbacks_removed,
			'protocol_external_rest_callbacks_preserved' => self::$protocol_external_rest_callbacks_preserved,
			'current_request_mcp_server_id' => self::current_request_mcp_server_id(),
			'protocol_core_rest_isolation_request_local_only' => true,
			'protocol_external_rest_isolation_request_local_only' => true,
			'production_changed' => false,
			'provider_settings_changed' => false,
			'wordpress_rest_routes_changed' => false,
		);
	}
}
