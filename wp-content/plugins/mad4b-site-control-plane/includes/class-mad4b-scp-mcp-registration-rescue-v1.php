<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Fresh-path exact-Staging MCP registration rescue.
 *
 * This class is intentionally loaded from a new filename. It is independent of
 * cached bridge bytecode and of the mutable rest_api_init callback list. It only
 * repairs the in-process MCP/REST registration lifecycle; it never changes
 * credentials, grants, Production state, plugins, database rows, or global REST.
 */
final class MAD4B_SCP_MCP_Registration_Rescue {
	const CONTRACT = 'mad4b.mcp-registration-rescue.v1';
	const STAGING_HOST = 'staging.egypttourgates.com';

	private static $booted = false;
	private static $rest_tail_bound_initial = false;
	private static $pre_dispatch_bound_initial = false;
	private static $attempted = false;
	private static $succeeded = false;
	private static $trigger = '';
	private static $state = 'not_required';
	private static $blocker = '';
	private static $route_count = 0;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		// Do not even bind global REST hooks outside the exact governed Staging
		// origin. Production and every other origin remain completely untouched.
		if ( ! self::governed_staging() ) {
			self::$state = 'ineligible_origin';
			return;
		}

		add_action( 'rest_api_init', array( __CLASS__, 'after_rest_init' ), PHP_INT_MAX - 1 );
		self::$rest_tail_bound_initial = false !== has_action( 'rest_api_init', array( __CLASS__, 'after_rest_init' ) );

		// This fallback survives a provider removing rest_api_init callbacks. Core
		// reaches rest_pre_dispatch after REST bootstrap and before route matching.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'before_rest_dispatch' ), -PHP_INT_MAX, 3 );
		self::$pre_dispatch_bound_initial = false !== has_filter( 'rest_pre_dispatch', array( __CLASS__, 'before_rest_dispatch' ) );

		// The endpoints diagnostics page itself primes REST. A later admin notice is
		// an independent checkpoint if the rest_api_init callback list was rewritten.
		add_action( 'admin_notices', array( __CLASS__, 'admin_checkpoint' ), 4 );
	}

	public static function after_rest_init() {
		self::reconcile( 'rest_api_init_rescue_tail' );
	}

	public static function before_rest_dispatch( $result, $server, $request ) {
		self::reconcile( 'rest_pre_dispatch_rescue' );
		return $result;
	}

	public static function admin_checkpoint() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lifecycle checkpoint.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lifecycle checkpoint.
		if ( 'mad4b-control-plane-connection' !== $page || 'endpoints' !== $tab ) return;

		if ( function_exists( 'rest_get_server' ) ) rest_get_server();
		self::reconcile( 'admin_endpoints_checkpoint' );
		self::render_notice();
	}

	private static function governed_staging() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$host = '';
		if ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) ) {
			$value = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$host = is_string( $value ) ? strtolower( rtrim( trim( $value ), '.' ) ) : '';
		}
		return 'staging' === $environment && self::STAGING_HOST === $host;
	}

	private static function official_runtime() {
		if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter', false ) ) return false;
		try {
			$reflection = new ReflectionClass( '\\WP\\MCP\\Core\\McpAdapter' );
			$file = $reflection->getFileName();
			$resolved = $file ? realpath( $file ) : false;
			$official_root = realpath( trailingslashit( WP_PLUGIN_DIR ) . 'mcp-adapter' );
			if ( ! $resolved || ! $official_root ) return false;
			$normalized = wp_normalize_path( $resolved );
			$official = rtrim( wp_normalize_path( $official_root ), '/' ) . '/';
			return 0 === strpos( $normalized, $official );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	public static function disable_default_server( $enabled ) {
		return false;
	}

	/** Canonical temporary binder; called only while mcp_adapter_init is running. */
	public static function register_servers_during_adapter_init( $adapter ) {
		$servers = new MAD4B_SCP_Servers();
		$servers->register_servers( $adapter );
	}

	/** One bounded in-process repair attempt; no persistent state is changed. */
	public static function reconcile( $trigger = 'explicit_rescue' ) {
		if ( did_action( 'mcp_adapter_init' ) > 0 && self::all_servers_registered() ) {
			if ( ! self::$attempted ) self::$state = 'canonical_runtime_already_ready';
			return;
		}
		if ( self::$attempted ) return;
		if ( did_action( 'rest_api_init' ) < 1 ) return;
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return;
		if ( ! self::governed_staging() ) return;

		self::$attempted = true;
		self::$trigger = sanitize_key( (string) $trigger );
		self::$state = 'recovery_started';

		if ( ! function_exists( 'wp_get_abilities' ) || ! function_exists( 'wp_get_ability' ) ) {
			return self::block( 'abilities_api_unavailable' );
		}
		wp_get_abilities();
		if ( did_action( 'wp_abilities_api_init' ) < 1 ) return self::block( 'abilities_api_not_initialized' );

		// Recovery is intentionally limited to MCP registration. If the Abilities
		// registry itself is incomplete, fail closed rather than reconstructing a
		// second lifecycle from this rescue path.
		foreach ( array( 'mad4b/site-info', 'mad4b/content-update-post' ) as $sentinel ) {
			if ( ! is_object( wp_get_ability( $sentinel ) ) ) return self::block( 'mad4b_ability_registry_incomplete' );
		}

		if ( ! self::official_runtime() ) return self::block( 'official_mcp_adapter_runtime_required' );
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'init' ) ) return self::block( 'official_adapter_init_unavailable' );

		if ( did_action( 'mcp_adapter_init' ) < 1 ) {
			// Server creation is legal only while the Adapter is firing its canonical
			// mcp_adapter_init action. If the host removed MAD4B's normal binder, add
			// one temporary rescue binder for that single canonical init call.
			$bridge_binder = false !== has_action( 'mcp_adapter_init', array( 'MAD4B_SCP_MCP_Registration_Bridge', 'register_servers' ) );
			$temporary_binder = ! $bridge_binder;
			if ( $temporary_binder ) {
				add_action( 'mcp_adapter_init', array( __CLASS__, 'register_servers_during_adapter_init' ), PHP_INT_MAX, 1 );
			}

			add_filter( 'mcp_adapter_create_default_server', array( __CLASS__, 'disable_default_server' ), PHP_INT_MAX );
			try {
				$adapter->init();
			} catch ( Throwable $e ) {
				remove_filter( 'mcp_adapter_create_default_server', array( __CLASS__, 'disable_default_server' ), PHP_INT_MAX );
				if ( $temporary_binder ) remove_action( 'mcp_adapter_init', array( __CLASS__, 'register_servers_during_adapter_init' ), PHP_INT_MAX );
				return self::block( 'official_adapter_init_failed' );
			}
			remove_filter( 'mcp_adapter_create_default_server', array( __CLASS__, 'disable_default_server' ), PHP_INT_MAX );
			if ( $temporary_binder ) remove_action( 'mcp_adapter_init', array( __CLASS__, 'register_servers_during_adapter_init' ), PHP_INT_MAX );
		} elseif ( ! self::all_servers_registered() ) {
			// Do not replay the global Adapter lifecycle once it has passed.
			return self::block( 'adapter_init_already_passed_servers_missing' );
		}

		if ( ! self::all_servers_registered() ) return self::block( 'mad4b_servers_not_registered' );

		$http_transport = '\\WP\\MCP\\Transport\\HttpTransport';
		if ( ! class_exists( $http_transport ) ) return self::block( 'official_http_transport_unavailable' );
		$rest_server = function_exists( 'rest_get_server' ) ? rest_get_server() : null;
		if ( ! is_object( $rest_server ) || ! method_exists( $rest_server, 'get_routes' ) ) return self::block( 'rest_server_unavailable' );

		self::$route_count = 0;
		foreach ( MAD4B_SCP_Servers::expected_server_ids() as $server_id ) {
			$server = method_exists( $adapter, 'get_server' ) ? $adapter->get_server( $server_id ) : null;
			if ( ! is_object( $server ) || ! method_exists( $server, 'create_transport_context' ) || ! method_exists( $server, 'get_server_route_namespace' ) || ! method_exists( $server, 'get_server_route' ) ) {
				return self::block( 'mad4b_server_missing_after_binding' );
			}

			$route_key = '/' . trim( (string) $server->get_server_route_namespace(), '/' ) . '/' . trim( (string) $server->get_server_route(), '/' );
			$routes = $rest_server->get_routes();
			if ( ! isset( $routes[ $route_key ] ) ) {
				try {
					$transport = new $http_transport( $server->create_transport_context() );
					remove_action( 'rest_api_init', array( $transport, 'register_routes' ), 16 );
					$transport->register_routes();
				} catch ( Throwable $e ) {
					return self::block( 'targeted_mad4b_route_registration_failed' );
				}
				$routes = $rest_server->get_routes();
			}
			if ( ! isset( $routes[ $route_key ] ) ) return self::block( 'targeted_mad4b_route_missing' );
			self::$route_count++;
		}

		if ( self::$route_count !== count( MAD4B_SCP_Servers::expected_server_ids() ) ) return self::block( 'targeted_mad4b_route_count_incomplete' );
		self::$succeeded = true;
		self::$state = 'registration_lifecycle_recovered';
		self::$blocker = '';
	}

	private static function all_servers_registered() {
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) return false;
		$status = MAD4B_SCP_Servers::registration_status();
		foreach ( MAD4B_SCP_Servers::expected_server_ids() as $server_id ) {
			if ( empty( $status[ $server_id ]['registered'] ) ) return false;
		}
		return true;
	}

	private static function block( $blocker ) {
		self::$blocker = sanitize_key( (string) $blocker );
		self::$state = 'blocked';
	}

	public static function status() {
		$sha = '';
		if ( is_readable( __FILE__ ) ) {
			$value = hash_file( 'sha256', __FILE__ );
			if ( is_string( $value ) ) $sha = substr( strtolower( $value ), 0, 16 );
		}
		return array(
			'contract' => self::CONTRACT,
			'source_basename' => basename( __FILE__ ),
			'source_sha256_prefix' => $sha,
			'booted' => self::$booted,
			'rest_tail_bound_initial' => self::$rest_tail_bound_initial,
			'rest_tail_bound_current' => false !== has_action( 'rest_api_init', array( __CLASS__, 'after_rest_init' ) ),
			'pre_dispatch_bound_initial' => self::$pre_dispatch_bound_initial,
			'pre_dispatch_bound_current' => false !== has_filter( 'rest_pre_dispatch', array( __CLASS__, 'before_rest_dispatch' ) ),
			'attempted' => self::$attempted,
			'succeeded' => self::$succeeded,
			'trigger' => self::$trigger,
			'state' => self::$state,
			'blocker' => self::$blocker,
			'route_count' => self::$route_count,
			'mcp_adapter_init_count' => did_action( 'mcp_adapter_init' ),
			'rest_api_init_count' => did_action( 'rest_api_init' ),
		);
	}

	private static function render_notice() {
		$status = self::status();
		$type = ! empty( $status['succeeded'] ) || ( did_action( 'mcp_adapter_init' ) > 0 && self::all_servers_registered() ) ? 'success' : 'warning';
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p><strong>' . esc_html__( 'MAD4B MCP registration rescue', 'mad4b-site-control-plane' ) . '</strong></p>';
		echo '<table class="widefat striped" style="max-width:1100px;margin:8px 0 12px"><tbody>';
		self::row( 'Rescue contract', $status['contract'] );
		self::row( 'Rescue source basename', $status['source_basename'] );
		self::row( 'Rescue source SHA-256 prefix', $status['source_sha256_prefix'] );
		self::row( 'REST rescue tail initially bound', ! empty( $status['rest_tail_bound_initial'] ) ? 'yes' : 'no' );
		self::row( 'REST rescue tail currently bound', ! empty( $status['rest_tail_bound_current'] ) ? 'yes' : 'no' );
		self::row( 'REST pre-dispatch rescue initially bound', ! empty( $status['pre_dispatch_bound_initial'] ) ? 'yes' : 'no' );
		self::row( 'REST pre-dispatch rescue currently bound', ! empty( $status['pre_dispatch_bound_current'] ) ? 'yes' : 'no' );
		self::row( 'Rescue attempted', ! empty( $status['attempted'] ) ? 'yes' : 'no' );
		self::row( 'Rescue succeeded', ! empty( $status['succeeded'] ) ? 'yes' : 'no' );
		self::row( 'Rescue trigger', '' !== (string) $status['trigger'] ? sanitize_key( (string) $status['trigger'] ) : 'none' );
		self::row( 'Rescue state', sanitize_key( (string) $status['state'] ) );
		self::row( 'Rescue blocker', '' !== (string) $status['blocker'] ? sanitize_key( (string) $status['blocker'] ) : 'none' );
		self::row( 'Rescue route count', (string) (int) $status['route_count'] );
		self::row( 'mcp_adapter_init count after rescue checkpoint', (string) (int) $status['mcp_adapter_init_count'] );
		echo '</tbody></table></div>';
	}

	private static function row( $label, $value ) {
		echo '<tr><th style="width:330px">' . esc_html( $label ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
	}
}
