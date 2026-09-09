<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Early lifecycle bridge for WordPress Abilities + MCP Adapter registration.
 *
 * The MCP Adapter exposes `mcp_adapter_init` as a one-shot lazy action. Binding
 * the MAD4B callback only from `plugins_loaded` is therefore too late if another
 * component primes the REST server earlier in the request. This bridge binds the
 * hooks as soon as the Control Plane plugin file is loaded, while keeping all
 * actual ability/server creation on the canonical WordPress/MCP actions.
 */
final class MAD4B_SCP_MCP_Registration_Bridge {
	const CONTRACT = 'mad4b.mcp-registration-bridge.v1';

	private static $booted = false;
	private static $abilities = null;
	private static $registry = null;
	private static $servers = null;
	private static $adapter_init_seen_before_boot = false;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;
		self::$adapter_init_seen_before_boot = did_action( 'mcp_adapter_init' ) > 0;

		self::$abilities = new MAD4B_SCP_Abilities();
		self::$registry = MAD4B_SCP_Adapter_Registry::instance();
		self::$servers = new MAD4B_SCP_Servers();

		// Preserve the established registration ordering: core first (10),
		// certified adapters second (20). Hooks are bound immediately, while the
		// actual registrations still execute only on the canonical lazy actions.
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_core_categories' ), 10 );
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_registry_categories' ), 20 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_core_abilities' ), 10 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_registry_abilities' ), 20 );
		add_action( 'mcp_adapter_init', array( __CLASS__, 'register_servers' ), 10, 1 );
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

	/** Read-only lifecycle/provenance evidence. No absolute server path is exposed. */
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
