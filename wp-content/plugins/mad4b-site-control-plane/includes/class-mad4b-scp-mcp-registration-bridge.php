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
	private static $first_rest_observed = false;
	private static $plugins_loaded_count_at_first_rest = 0;
	private static $init_count_at_first_rest = 0;
	private static $wp_loaded_count_at_first_rest = 0;
	private static $doing_plugins_loaded_at_first_rest = false;
	private static $doing_init_at_first_rest = false;
	private static $jetengine_registry_class_loaded_at_first_rest = false;
	private static $jetengine_registry_callback_present_at_first_rest = false;
	private static $jetengine_rest_manager_class_loaded_at_first_rest = false;
	private static $jetengine_rest_manager_callback_present_at_first_rest = false;
	private static $mcp_adapter_callback_present_at_first_rest = false;
	private static $first_rest_classification = 'not_observed';
	private static $first_rest_caller_trace = array();

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


		// Observe the first REST bootstrap at the earliest practical hook priority.
		// This is passive lifecycle evidence only: no callback/provider invocation,
		// route registration, REST replay, server creation, or persistent mutation.
		add_action( 'rest_api_init', array( __CLASS__, 'observe_first_rest_init' ), PHP_INT_MIN );

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

	public static function observe_first_rest_init() {
		if ( self::$first_rest_observed ) return;
		self::$first_rest_observed = true;

		self::$plugins_loaded_count_at_first_rest = (int) did_action( 'plugins_loaded' );
		self::$init_count_at_first_rest = (int) did_action( 'init' );
		self::$wp_loaded_count_at_first_rest = (int) did_action( 'wp_loaded' );
		self::$doing_plugins_loaded_at_first_rest = function_exists( 'doing_action' ) ? (bool) doing_action( 'plugins_loaded' ) : false;
		self::$doing_init_at_first_rest = function_exists( 'doing_action' ) ? (bool) doing_action( 'init' ) : false;

		$registry_class = 'Jet_Engine\\MCP_Tools\\Registry';
		self::$jetengine_registry_class_loaded_at_first_rest = class_exists( $registry_class, false );
		self::$jetengine_registry_callback_present_at_first_rest = self::rest_callback_present( $registry_class, 'register_features_api' );

		$rest_manager_classes = self::loaded_jetengine_rest_manager_classes();
		self::$jetengine_rest_manager_class_loaded_at_first_rest = ! empty( $rest_manager_classes );
		self::$jetengine_rest_manager_callback_present_at_first_rest = self::rest_callback_present_for_classes( $rest_manager_classes );
		self::$mcp_adapter_callback_present_at_first_rest = self::official_mcp_adapter_rest_callback_present();

		if ( 0 === self::$plugins_loaded_count_at_first_rest ) {
			self::$first_rest_classification = 'rest_before_plugins_loaded';
		} elseif ( self::$doing_plugins_loaded_at_first_rest && ! self::$jetengine_registry_callback_present_at_first_rest ) {
			self::$first_rest_classification = 'rest_during_plugins_loaded_before_jetengine_registration';
		} elseif ( self::$jetengine_registry_callback_present_at_first_rest ) {
			self::$first_rest_classification = 'jetengine_callbacks_present_at_first_rest';
		} else {
			self::$first_rest_classification = 'first_rest_phase_undetermined';
		}

		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 );
		foreach ( is_array( $trace ) ? $trace : array() as $frame ) {
			self::$first_rest_caller_trace[] = array(
				'class' => isset( $frame['class'] ) ? self::bounded_trace_symbol( $frame['class'] ) : '',
				'function' => isset( $frame['function'] ) ? self::bounded_trace_symbol( $frame['function'] ) : '',
				'relative_file' => isset( $frame['file'] ) ? self::relative_wordpress_path( $frame['file'] ) : '',
				'line' => isset( $frame['line'] ) ? max( 0, (int) $frame['line'] ) : 0,
			);
			if ( count( self::$first_rest_caller_trace ) >= 16 ) break;
		}
	}

	private static function bounded_trace_symbol( $value ) {
		$value = sanitize_text_field( (string) $value );
		return strlen( $value ) > 191 ? substr( $value, 0, 191 ) : $value;
	}

	private static function relative_wordpress_path( $file ) {
		if ( ! is_string( $file ) || '' === $file ) return '';
		$file = wp_normalize_path( $file );
		$roots = array();
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) $roots[] = array( wp_normalize_path( WPMU_PLUGIN_DIR ), 'wp-content/mu-plugins/' );
		if ( defined( 'WP_PLUGIN_DIR' ) ) $roots[] = array( wp_normalize_path( WP_PLUGIN_DIR ), 'wp-content/plugins/' );
		if ( defined( 'WP_CONTENT_DIR' ) ) $roots[] = array( wp_normalize_path( WP_CONTENT_DIR ), 'wp-content/' );
		if ( defined( 'ABSPATH' ) ) $roots[] = array( wp_normalize_path( ABSPATH ), '' );

		foreach ( $roots as $entry ) {
			$root = rtrim( (string) $entry[0], '/' ) . '/';
			if ( 0 !== strpos( $file, $root ) ) continue;
			$relative = ltrim( substr( $file, strlen( $root ) ), '/' );
			if ( '' === $relative || false !== strpos( $relative, '../' ) ) return '';
			return sanitize_text_field( (string) $entry[1] . $relative );
		}
		return '';
	}

	private static function rest_callback_present( $owner_class, $method = '' ) {
		global $wp_filter;
		if ( ! isset( $wp_filter['rest_api_init'] ) || ! is_object( $wp_filter['rest_api_init'] ) || empty( $wp_filter['rest_api_init']->callbacks ) ) return false;
		foreach ( $wp_filter['rest_api_init']->callbacks as $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$function = isset( $callback['function'] ) ? $callback['function'] : null;
				if ( ! is_array( $function ) || 2 !== count( $function ) ) continue;
				$owner = is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0];
				if ( (string) $owner_class !== $owner ) continue;
				if ( '' !== $method && (string) $method !== (string) $function[1] ) continue;
				return true;
			}
		}
		return false;
	}

	private static function rest_callback_present_for_classes( array $classes ) {
		foreach ( $classes as $class ) if ( self::rest_callback_present( $class ) ) return true;
		return false;
	}

	private static function loaded_jetengine_rest_manager_classes() {
		$result = array();
		foreach ( get_declared_classes() as $class ) {
			$class = (string) $class;
			if ( 0 !== strpos( $class, 'Jet_Engine\\MCP_Tools\\Rest_API\\' ) ) continue;
			$short = strtolower( substr( $class, strrpos( $class, '\\' ) + 1 ) );
			if ( false === strpos( $short, 'manager' ) && false === strpos( $short, 'registry' ) ) continue;
			$result[] = $class;
			if ( count( $result ) >= 8 ) break;
		}
		return array_values( array_unique( $result ) );
	}

	private static function official_mcp_adapter_rest_callback_present() {
		global $wp_filter;
		if ( ! isset( $wp_filter['rest_api_init'] ) || ! is_object( $wp_filter['rest_api_init'] ) || empty( $wp_filter['rest_api_init']->callbacks ) ) return false;
		foreach ( $wp_filter['rest_api_init']->callbacks as $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$function = isset( $callback['function'] ) ? $callback['function'] : null;
				if ( ! is_array( $function ) || 2 !== count( $function ) ) continue;
				$owner = is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0];
				if ( 0 === strpos( $owner, 'WP\\MCP\\' ) ) return true;
				if ( ! method_exists( $owner, (string) $function[1] ) ) continue;
				try {
					$reflection = new ReflectionMethod( $owner, (string) $function[1] );
					$file = self::relative_wordpress_path( (string) $reflection->getFileName() );
					if ( 0 === strpos( $file, 'wp-content/plugins/mcp-adapter/' ) ) return true;
				} catch ( Throwable $ignored ) {
					continue;
				}
			}
		}
		return false;
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
		return class_exists( 'MAD4B_SCP_Site_Profile' )
			&& MAD4B_SCP_Site_Profile::origin_enrolled()
			&& MAD4B_SCP_Site_Profile::managed_runtime_enabled();
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
		// The official MCP Adapter may fire mcp_adapter_init before the public
		// Abilities registry has been materialized (notably during WP-CLI/plugin
		// activation). Server creation validates ability names immediately, so
		// initialize the canonical registry first instead of advertising tools that
		// do not exist yet. wp_get_abilities() is idempotent and fires the normal
		// wp_abilities_api_init lifecycle; it does not create grants or authority.
		if ( function_exists( 'wp_get_abilities' ) && did_action( 'wp_abilities_api_init' ) < 1 ) wp_get_abilities();
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
			'first_rest_observed' => self::$first_rest_observed,
			'plugins_loaded_count_at_first_rest' => self::$plugins_loaded_count_at_first_rest,
			'init_count_at_first_rest' => self::$init_count_at_first_rest,
			'wp_loaded_count_at_first_rest' => self::$wp_loaded_count_at_first_rest,
			'doing_plugins_loaded_at_first_rest' => self::$doing_plugins_loaded_at_first_rest,
			'doing_init_at_first_rest' => self::$doing_init_at_first_rest,
			'jetengine_registry_class_loaded_at_first_rest' => self::$jetengine_registry_class_loaded_at_first_rest,
			'jetengine_registry_callback_present_at_first_rest' => self::$jetengine_registry_callback_present_at_first_rest,
			'jetengine_rest_manager_class_loaded_at_first_rest' => self::$jetengine_rest_manager_class_loaded_at_first_rest,
			'jetengine_rest_manager_callback_present_at_first_rest' => self::$jetengine_rest_manager_callback_present_at_first_rest,
			'mcp_adapter_callback_present_at_first_rest' => self::$mcp_adapter_callback_present_at_first_rest,
			'first_rest_classification' => self::$first_rest_classification,
			'caller_trace' => self::$first_rest_caller_trace,
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
