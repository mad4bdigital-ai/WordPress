<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Early lifecycle bridge for WordPress Abilities + MCP Adapter registration.
 *
 * A host/MU component can prime WordPress REST before the managed MAD4B MU
 * bootstrap runs. In that case the official MCP Adapter can be correctly pinned
 * and its rest_api_init callback can be bound, but that one-shot action has
 * already passed. This bridge binds MAD4B registration callbacks immediately and
 * performs one bounded recovery on `init`: initialize the public Abilities API,
 * invoke the official Adapter init once, then register only MAD4B HTTP routes on
 * the already-existing REST server. It never replays rest_api_init globally.
 */
final class MAD4B_SCP_MCP_Registration_Bridge {
	const CONTRACT = 'mad4b.mcp-registration-bridge.v2';
	const STAGING_HOST = 'staging.egypttourgates.com';

	private static $booted = false;
	private static $abilities = null;
	private static $registry = null;
	private static $servers = null;
	private static $adapter_init_seen_before_boot = false;
	private static $rest_init_seen_before_boot = false;
	private static $missed_rest_recovery_scheduled = false;
	private static $missed_rest_recovery_attempted = false;
	private static $missed_rest_recovery_succeeded = false;
	private static $missed_rest_recovery_route_count = 0;
	private static $missed_rest_recovery_state = 'not_required';
	private static $missed_rest_recovery_blocker = '';
	private static $rest_postcondition_watchdog_bound = false;
	private static $rest_postcondition_recovery_triggered = false;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;
		self::$adapter_init_seen_before_boot = did_action( 'mcp_adapter_init' ) > 0;
		self::$rest_init_seen_before_boot = did_action( 'rest_api_init' ) > 0;

		self::$abilities = new MAD4B_SCP_Abilities();
		self::$registry = MAD4B_SCP_Adapter_Registry::instance();
		self::$servers = new MAD4B_SCP_Servers();

		// Preserve registration ordering: core first (10), certified adapters
		// second (20). All actual ability/server creation remains on canonical
		// WordPress/MCP actions.
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_core_categories' ), 10 );
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_registry_categories' ), 20 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_core_abilities' ), 10 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_registry_abilities' ), 20 );
		add_action( 'mcp_adapter_init', array( __CLASS__, 'register_servers' ), 10, 1 );

		// Tail-check every REST bootstrap. The official Adapter normally initializes
		// at priority 15. If a host/provider removes or bypasses that callback after
		// the MU bootstrap armed it, recover only the missing Adapter/MAD4B lifecycle
		// without replaying rest_api_init globally.
		add_action( 'rest_api_init', array( __CLASS__, 'verify_adapter_init_after_rest' ), PHP_INT_MAX );
		self::$rest_postcondition_watchdog_bound = false !== has_action( 'rest_api_init', array( __CLASS__, 'verify_adapter_init_after_rest' ) );

		if ( self::$rest_init_seen_before_boot && ! self::$adapter_init_seen_before_boot && self::governed_staging() && ! ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) ) {
			if ( did_action( 'init' ) > 0 ) {
				self::$missed_rest_recovery_state = 'missed_rest_detected_too_late';
				self::$missed_rest_recovery_blocker = 'wordpress_init_already_completed_before_recovery_schedule';
			} else {
				self::$missed_rest_recovery_scheduled = true;
				self::$missed_rest_recovery_state = 'scheduled_for_init';
				add_action( 'init', array( __CLASS__, 'recover_missed_rest_lifecycle' ), 9999 );
			}
		}
	}

	public static function verify_adapter_init_after_rest() {
		if ( did_action( 'mcp_adapter_init' ) > 0 ) return;
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return;
		if ( ! self::governed_staging() ) return;
		if ( self::$missed_rest_recovery_attempted ) return;

		self::$rest_postcondition_recovery_triggered = true;

		// REST can be primed before WordPress init. In that case defer recovery
		// until init so the public Abilities API is legal to initialize.
		if ( did_action( 'init' ) < 1 ) {
			if ( ! self::$missed_rest_recovery_scheduled ) {
				self::$missed_rest_recovery_scheduled = true;
				self::$missed_rest_recovery_state = 'adapter_init_missing_after_rest_scheduled_for_init';
				add_action( 'init', array( __CLASS__, 'recover_missed_rest_lifecycle' ), 9999 );
			}
			return;
		}

		self::$missed_rest_recovery_state = 'adapter_init_missing_after_rest';
		self::recover_missed_rest_lifecycle();
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

	public static function disable_default_server_for_missed_rest_recovery( $enabled ) {
		return false;
	}

	/**
	 * Recover only the canonical MCP lifecycle that was missed after REST has
	 * already initialized. No persistent state is changed.
	 */
	public static function recover_missed_rest_lifecycle() {
		if ( self::$missed_rest_recovery_attempted ) return;
		self::$missed_rest_recovery_attempted = true;
		self::$missed_rest_recovery_state = 'recovery_started';

		if ( ! self::governed_staging() ) {
			self::$missed_rest_recovery_blocker = 'recovery_origin_not_governed_staging';
			self::$missed_rest_recovery_state = 'blocked';
			return;
		}
		if ( did_action( 'rest_api_init' ) < 1 ) {
			self::$missed_rest_recovery_blocker = 'rest_api_init_not_seen';
			self::$missed_rest_recovery_state = 'blocked';
			return;
		}
		if ( did_action( 'mcp_adapter_init' ) > 0 ) {
			self::$missed_rest_recovery_succeeded = true;
			self::$missed_rest_recovery_state = 'adapter_initialized_before_recovery_execution';
			return;
		}
		if ( ! function_exists( 'wp_get_abilities' ) || ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) ) {
			self::$missed_rest_recovery_blocker = 'abilities_api_unavailable';
			self::$missed_rest_recovery_state = 'blocked';
			return;
		}

		wp_get_abilities();
		if ( did_action( 'wp_abilities_api_init' ) < 1 ) {
			self::$missed_rest_recovery_blocker = 'abilities_api_not_initialized';
			self::$missed_rest_recovery_state = 'blocked';
			return;
		}
		foreach ( array( 'mad4b/site-info', 'mad4b/content-update-post' ) as $sentinel ) {
			if ( ! wp_has_ability( $sentinel ) || ! is_object( wp_get_ability( $sentinel ) ) ) {
				self::$missed_rest_recovery_blocker = 'mad4b_ability_registry_incomplete';
				self::$missed_rest_recovery_state = 'blocked';
				return;
			}
		}

		if ( ! self::official_runtime() ) {
			self::$missed_rest_recovery_blocker = 'official_mcp_adapter_runtime_required';
			self::$missed_rest_recovery_state = 'blocked';
			return;
		}

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'init' ) ) {
			self::$missed_rest_recovery_blocker = 'official_adapter_init_unavailable';
			self::$missed_rest_recovery_state = 'blocked';
			return;
		}

		add_filter( 'mcp_adapter_create_default_server', array( __CLASS__, 'disable_default_server_for_missed_rest_recovery' ), PHP_INT_MAX );
		try {
			$adapter->init();
		} catch ( Throwable $e ) {
			self::$missed_rest_recovery_blocker = 'official_adapter_init_failed';
			self::$missed_rest_recovery_state = 'blocked';
			remove_filter( 'mcp_adapter_create_default_server', array( __CLASS__, 'disable_default_server_for_missed_rest_recovery' ), PHP_INT_MAX );
			return;
		}
		remove_filter( 'mcp_adapter_create_default_server', array( __CLASS__, 'disable_default_server_for_missed_rest_recovery' ), PHP_INT_MAX );

		if ( did_action( 'mcp_adapter_init' ) < 1 ) {
			self::$missed_rest_recovery_blocker = 'official_adapter_init_did_not_fire';
			self::$missed_rest_recovery_state = 'blocked';
			return;
		}

		$http_transport = '\\WP\\MCP\\Transport\\HttpTransport';
		if ( ! class_exists( $http_transport ) ) {
			self::$missed_rest_recovery_blocker = 'official_http_transport_unavailable';
			self::$missed_rest_recovery_state = 'blocked';
			return;
		}

		$rest_server = function_exists( 'rest_get_server' ) ? rest_get_server() : null;
		if ( ! is_object( $rest_server ) || ! method_exists( $rest_server, 'get_routes' ) ) {
			self::$missed_rest_recovery_blocker = 'rest_server_unavailable_for_targeted_route_recovery';
			self::$missed_rest_recovery_state = 'blocked';
			return;
		}

		foreach ( MAD4B_SCP_Servers::expected_server_ids() as $server_id ) {
			$server = method_exists( $adapter, 'get_server' ) ? $adapter->get_server( $server_id ) : null;
			if ( ! is_object( $server ) || ! method_exists( $server, 'create_transport_context' ) || ! method_exists( $server, 'get_server_route_namespace' ) || ! method_exists( $server, 'get_server_route' ) ) {
				self::$missed_rest_recovery_blocker = 'mad4b_server_missing_after_adapter_init';
				self::$missed_rest_recovery_state = 'blocked';
				return;
			}

			$route_key = '/' . trim( (string) $server->get_server_route_namespace(), '/' ) . '/' . trim( (string) $server->get_server_route(), '/' );
			$routes = $rest_server->get_routes();
			if ( isset( $routes[ $route_key ] ) ) {
				self::$missed_rest_recovery_route_count++;
				continue;
			}

			try {
				$transport = new $http_transport( $server->create_transport_context() );
				remove_action( 'rest_api_init', array( $transport, 'register_routes' ), 16 );
				$transport->register_routes();
			} catch ( Throwable $e ) {
				self::$missed_rest_recovery_blocker = 'targeted_mad4b_route_registration_failed';
				self::$missed_rest_recovery_state = 'blocked';
				return;
			}

			$routes = $rest_server->get_routes();
			if ( ! isset( $routes[ $route_key ] ) ) {
				self::$missed_rest_recovery_blocker = 'targeted_mad4b_route_missing_after_registration';
				self::$missed_rest_recovery_state = 'blocked';
				return;
			}
			self::$missed_rest_recovery_route_count++;
		}

		if ( self::$missed_rest_recovery_route_count !== count( MAD4B_SCP_Servers::expected_server_ids() ) ) {
			self::$missed_rest_recovery_blocker = 'targeted_mad4b_route_count_incomplete';
			self::$missed_rest_recovery_state = 'blocked';
			return;
		}

		self::$missed_rest_recovery_succeeded = true;
		self::$missed_rest_recovery_state = 'missed_rest_lifecycle_recovered';
	}

	private static function prepare_registry() {
		if ( ! self::$registry ) self::$registry = MAD4B_SCP_Adapter_Registry::instance();
		self::$registry->register_defaults();
	}

	public static function register_core_categories() {
		if ( self::$abilities ) self::$abilities->register_categories();
	}

	public static function register_registry_categories() {
		self::prepare_registry();
		if ( self::$registry ) self::$registry->register_categories();
	}

	public static function register_core_abilities() {
		if ( self::$abilities ) self::$abilities->register_abilities();
	}

	public static function register_registry_abilities() {
		self::prepare_registry();
		if ( self::$registry ) self::$registry->register_abilities();
	}

	public static function register_servers( $adapter ) {
		self::prepare_registry();
		if ( ! self::$servers ) self::$servers = new MAD4B_SCP_Servers();
		self::$servers->register_servers( $adapter );
	}

	public static function status() {
		$runtime_source = 'unavailable';
		$runtime_from_official_plugin = false;
		$runtime_version = '';

		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			if ( defined( 'WP\\MCP\\Core\\McpAdapter::VERSION' ) ) $runtime_version = (string) \WP\MCP\Core\McpAdapter::VERSION;
			try {
				$reflection = new ReflectionClass( '\\WP\\MCP\\Core\\McpAdapter' );
				$file = $reflection->getFileName();
				$resolved = $file ? realpath( $file ) : false;
				$plugin_root = realpath( WP_PLUGIN_DIR );
				$official_root = realpath( trailingslashit( WP_PLUGIN_DIR ) . 'mcp-adapter' );
				if ( $resolved && $plugin_root ) {
					$normalized = wp_normalize_path( $resolved );
					$plugins = rtrim( wp_normalize_path( $plugin_root ), '/' ) . '/';
					if ( 0 === strpos( $normalized, $plugins ) ) $runtime_source = ltrim( substr( $normalized, strlen( $plugins ) ), '/' );
					else $runtime_source = 'outside-wp-plugin-dir';
					if ( $official_root ) {
						$official = rtrim( wp_normalize_path( $official_root ), '/' ) . '/';
						$runtime_from_official_plugin = 0 === strpos( $normalized, $official );
					}
				}
			} catch ( Throwable $e ) {
				$runtime_source = 'reflection-unavailable';
			}
		}

		$registrations = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::registration_status() : array();
		$errors = array();
		foreach ( is_array( $registrations ) ? $registrations : array() as $server_id => $entry ) {
			$errors[ sanitize_key( (string) $server_id ) ] = isset( $entry['error'] ) ? sanitize_key( (string) $entry['error'] ) : '';
		}

		$core_ability_hook_bound = false !== has_action( 'wp_abilities_api_init', array( __CLASS__, 'register_core_abilities' ) );
		$registry_ability_hook_bound = false !== has_action( 'wp_abilities_api_init', array( __CLASS__, 'register_registry_abilities' ) );
		$core_category_hook_bound = false !== has_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_core_categories' ) );
		$registry_category_hook_bound = false !== has_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_registry_categories' ) );

		return array(
			'contract' => self::CONTRACT,
			'bridge_booted' => self::$booted,
			'adapter_init_seen_before_bridge_boot' => self::$adapter_init_seen_before_boot,
			'rest_init_seen_before_bridge_boot' => self::$rest_init_seen_before_boot,
			'missed_rest_recovery_scheduled' => self::$missed_rest_recovery_scheduled,
			'missed_rest_recovery_attempted' => self::$missed_rest_recovery_attempted,
			'missed_rest_recovery_succeeded' => self::$missed_rest_recovery_succeeded,
			'missed_rest_recovery_route_count' => self::$missed_rest_recovery_route_count,
			'missed_rest_recovery_state' => self::$missed_rest_recovery_state,
			'missed_rest_recovery_blocker' => self::$missed_rest_recovery_blocker,
			'rest_postcondition_watchdog_bound' => self::$rest_postcondition_watchdog_bound,
			'rest_postcondition_recovery_triggered' => self::$rest_postcondition_recovery_triggered,
			'mcp_adapter_init_count' => did_action( 'mcp_adapter_init' ),
			'rest_api_init_count' => did_action( 'rest_api_init' ),
			'abilities_init_count' => did_action( 'wp_abilities_api_init' ),
			'category_init_count' => did_action( 'wp_abilities_api_categories_init' ),
			'server_hook_bound' => false !== has_action( 'mcp_adapter_init', array( __CLASS__, 'register_servers' ) ),
			'core_ability_hook_bound' => $core_ability_hook_bound,
			'registry_ability_hook_bound' => $registry_ability_hook_bound,
			'ability_hook_bound' => $core_ability_hook_bound && $registry_ability_hook_bound,
			'core_category_hook_bound' => $core_category_hook_bound,
			'registry_category_hook_bound' => $registry_category_hook_bound,
			'category_hook_bound' => $core_category_hook_bound && $registry_category_hook_bound,
			'adapter_runtime_version' => $runtime_version,
			'adapter_runtime_source' => sanitize_text_field( $runtime_source ),
			'adapter_runtime_from_official_plugin' => $runtime_from_official_plugin,
			'registration_errors' => $errors,
		);
	}
}
