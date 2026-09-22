<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Passive JetEngine Features registration diagnostics.
 *
 * This adapter observes source and already-existing runtime residue only. It
 * never invokes Registry::register_features_api(), never instantiates a
 * controller, never calls register_routes(), never replays rest_api_init, and
 * never creates the WordPress REST server.
 */
final class MAD4B_SCP_JetEngine_Features_Registration_Diagnostics_Bootstrap {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register' ), 10, 1 );
	}

	public static function register( $registry ) {
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Base' ) || ! $registry instanceof MAD4B_SCP_Adapter_Registry ) return;
		$registry->register( new MAD4B_SCP_JetEngine_Features_Registration_Diagnostics_Adapter() );
	}
}

final class MAD4B_SCP_JetEngine_Features_Registration_Diagnostics_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.jetengine-features-registration-diagnostics.v1';
	const ABILITY = 'jetengine/features-registration-diagnostics';
	const REGISTRY_CLASS = 'Jet_Engine\\MCP_Tools\\Registry';
	const TARGET_METHOD = 'register_features_api';
	const PLUGIN_FILE = 'jet-engine/jet-engine.php';

	private static $controllers = array(
		'Jet_Engine\\MCP_Tools\\Rest_API\\Get_Controller',
		'Jet_Engine\\MCP_Tools\\Rest_API\\MCP_Controller',
		'Jet_Engine\\MCP_Tools\\Rest_API\\Run_Controller',
	);

	public function id() { return 'jetengine-features-registration-diagnostics'; }
	public function label() { return 'JetEngine Features Registration Diagnostics'; }
	protected function certified_provider_key() { return 'native-provider'; }
	protected function mutation_requires_certification() { return false; }

	public function is_available() {
		return class_exists( self::REGISTRY_CLASS, false ) || function_exists( 'jet_engine' ) || is_file( $this->plugin_path() );
	}

	public function ability_names() {
		return array( 'read' => array( self::ABILITY ), 'content' => array(), 'admin' => array(), 'write' => array() );
	}

	public function register_abilities() {
		if ( wp_has_ability( self::ABILITY ) ) return;
		$this->add_ability(
			self::ABILITY,
			'JetEngine Features Registration Diagnostics',
			'diagnostics',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			null,
			'read',
			true,
			false,
			true
		);
	}

	public function diagnostics() {
		$registry_method = $this->method_evidence( self::REGISTRY_CLASS, self::TARGET_METHOD );
		$registry_source = isset( $registry_method['source'] ) ? (string) $registry_method['source'] : '';
		$registry_object = $this->registry_callback_object();
		$controllers = array();

		foreach ( self::$controllers as $class ) {
			$constructor = $this->method_evidence( $class, '__construct' );
			$register_routes = $this->method_evidence( $class, 'register_routes' );
			$constructor_source = isset( $constructor['source'] ) ? (string) $constructor['source'] : '';
			$route_source = isset( $register_routes['source'] ) ? (string) $register_routes['source'] : '';
			$guards = $this->pre_route_guards(
				isset( $register_routes['lines'] ) ? $register_routes['lines'] : array(),
				isset( $register_routes['start_line'] ) ? (int) $register_routes['start_line'] : 0
			);
			$residue = $this->instance_residue( $class, $registry_object );
			$route_call_count = $this->token_count( $route_source, 'register_rest_route' );
			$constructor_direct_route_call = false !== strpos( $constructor_source, '$this->register_routes' );
			$constructor_rest_hook = false !== strpos( $constructor_source, 'rest_api_init' );
			$instantiation_declared = $this->registry_declares_instantiation( $registry_source, $class );

			$classification = 'registration_path_not_proven';
			if ( empty( $register_routes['available'] ) ) {
				$classification = 'register_routes_method_unavailable';
			} elseif ( ! $instantiation_declared ) {
				$classification = 'registry_instantiation_not_declared';
			} elseif ( ! empty( $residue['instance_creation_proven'] ) && 0 === $this->observed_route_count_for_class( $class ) ) {
				$classification = 'instance_residue_without_observed_route';
			} elseif ( $constructor_direct_route_call && $route_call_count > 0 ) {
				$classification = 'declared_direct_registration_path_without_instance_proof';
			} elseif ( $constructor_rest_hook ) {
				$classification = 'constructor_declares_deferred_rest_hook';
			}

			$controllers[] = array(
				'class' => $class,
				'class_loaded' => class_exists( $class, false ),
				'registry_instantiation_declared' => $instantiation_declared,
				'instance_creation_proven' => ! empty( $residue['instance_creation_proven'] ),
				'instance_creation_evidence' => $residue,
				'constructor' => $this->public_method_evidence( $constructor ),
				'constructor_calls_register_routes' => $constructor_direct_route_call,
				'constructor_attaches_rest_api_init' => $constructor_rest_hook,
				'register_routes' => $this->public_method_evidence( $register_routes ),
				'register_rest_route_call_count' => $route_call_count,
				'pre_register_routes_guards' => $guards,
				'pre_register_routes_guard_result' => empty( $guards ) ? 'NOT_APPLICABLE' : 'UNKNOWN',
				'observed_route_count' => $this->observed_route_count_for_class( $class ),
				'classification' => $classification,
			);
		}

		return array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'registry' => array(
				'class' => self::REGISTRY_CLASS,
				'method' => self::TARGET_METHOD,
				'class_loaded' => class_exists( self::REGISTRY_CLASS, false ),
				'method' => $this->public_method_evidence( $registry_method ),
				'callback_attached' => is_object( $registry_object ),
			),
			'runtime' => array(
				'rest_api_init_executed' => did_action( 'rest_api_init' ) > 0,
				'rest_api_init_count' => (int) did_action( 'rest_api_init' ),
				'rest_server_already_present' => isset( $GLOBALS['wp_rest_server'] ) && is_object( $GLOBALS['wp_rest_server'] ),
				'rest_request_defined' => defined( 'REST_REQUEST' ),
				'rest_request' => defined( 'REST_REQUEST' ) ? (bool) REST_REQUEST : null,
				'current_filter' => function_exists( 'current_filter' ) ? (string) current_filter() : '',
			),
			'controllers' => $controllers,
			'route_inventory' => $this->observed_jetengine_routes(),
			'callback_execution_attempted' => false,
			'controller_instantiation_attempted' => false,
			'route_registration_attempted' => false,
			'rest_api_init_replayed' => false,
			'rest_server_instantiated' => false,
			'settings_mutation' => false,
			'filesystem_mutation' => false,
			'database_mutation' => false,
			'no_secrets_exposed' => true,
		);
	}

	private function plugin_path() {
		return defined( 'WP_PLUGIN_DIR' ) ? trailingslashit( WP_PLUGIN_DIR ) . self::PLUGIN_FILE : '';
	}

	private function plugin_root() {
		$path = $this->plugin_path();
		return '' !== $path ? dirname( $path ) : '';
	}

	private function relative_file( $file ) {
		$root = $this->plugin_root();
		if ( '' === $root || ! is_string( $file ) ) return '';
		$file = wp_normalize_path( $file );
		$root = wp_normalize_path( $root );
		if ( 0 !== strpos( $file, $root ) ) return '';
		return ltrim( substr( $file, strlen( $root ) ), '/' );
	}

	private function method_evidence( $class, $method ) {
		$result = array(
			'available' => false,
			'file' => '',
			'start_line' => null,
			'end_line' => null,
			'source_sha256' => '',
			'source' => '',
			'lines' => array(),
			'reflection_error' => '',
		);
		if ( ! class_exists( $class, false ) || ! method_exists( $class, $method ) ) return $result;
		try {
			$reflection = new ReflectionMethod( $class, $method );
			$file = (string) $reflection->getFileName();
			$root = $this->plugin_root();
			if ( '' === $file || '' === $root || 0 !== strpos( wp_normalize_path( $file ), wp_normalize_path( $root ) ) || ! is_file( $file ) ) return $result;
			$all_lines = @file( $file, FILE_IGNORE_NEW_LINES );
			if ( ! is_array( $all_lines ) ) return $result;
			$start = (int) $reflection->getStartLine();
			$end = (int) $reflection->getEndLine();
			$lines = array_slice( $all_lines, max( 0, $start - 1 ), max( 0, $end - $start + 1 ) );
			$source = implode( "\n", $lines );
			$result['available'] = true;
			$result['file'] = $this->relative_file( $file );
			$result['start_line'] = $start;
			$result['end_line'] = $end;
			$result['source_sha256'] = hash( 'sha256', $source );
			$result['source'] = $source;
			$result['lines'] = $lines;
		} catch ( Throwable $error ) {
			$result['reflection_error'] = get_class( $error );
		}
		return $result;
	}

	private function public_method_evidence( array $method ) {
		return array(
			'available' => ! empty( $method['available'] ),
			'file' => isset( $method['file'] ) ? (string) $method['file'] : '',
			'start_line' => isset( $method['start_line'] ) ? $method['start_line'] : null,
			'end_line' => isset( $method['end_line'] ) ? $method['end_line'] : null,
			'source_sha256' => isset( $method['source_sha256'] ) ? (string) $method['source_sha256'] : '',
			'reflection_error' => isset( $method['reflection_error'] ) ? sanitize_key( (string) $method['reflection_error'] ) : '',
		);
	}

	private function token_count( $source, $token ) {
		if ( ! is_string( $source ) || '' === $source ) return 0;
		return substr_count( $source, (string) $token );
	}

	private function registry_declares_instantiation( $source, $class ) {
		if ( ! is_string( $source ) || '' === $source ) return false;
		$parts = explode( '\\', (string) $class );
		$short = end( $parts );
		if ( ! is_string( $short ) || '' === $short ) return false;
		return 1 === preg_match( '/\bnew\s+(?:[A-Za-z0-9_\\\\]+\\\\)?' . preg_quote( $short, '/' ) . '\s*\(/', $source );
	}

	private function registry_callback_object() {
		global $wp_filter;
		if ( ! isset( $wp_filter['rest_api_init'] ) || ! is_object( $wp_filter['rest_api_init'] ) || empty( $wp_filter['rest_api_init']->callbacks ) ) return null;
		foreach ( $wp_filter['rest_api_init']->callbacks as $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$function = isset( $callback['function'] ) ? $callback['function'] : null;
				if ( ! is_array( $function ) || 2 !== count( $function ) || ! is_object( $function[0] ) ) continue;
				if ( self::REGISTRY_CLASS === get_class( $function[0] ) && self::TARGET_METHOD === (string) $function[1] ) return $function[0];
			}
		}
		return null;
	}

	private function instance_residue( $class, $registry_object ) {
		$evidence = array(
			'instance_creation_proven' => false,
			'registry_property_matches' => array(),
			'hook_callback_matches' => array(),
		);

		if ( is_object( $registry_object ) ) {
			try {
				$reflection = new ReflectionObject( $registry_object );
				foreach ( $reflection->getProperties() as $property ) {
					try {
						$property->setAccessible( true );
						$value = $property->getValue( $registry_object );
						if ( $value instanceof $class ) {
							$evidence['registry_property_matches'][] = sanitize_key( $property->getName() );
						} elseif ( is_array( $value ) ) {
							foreach ( $value as $item ) {
								if ( $item instanceof $class ) {
									$evidence['registry_property_matches'][] = sanitize_key( $property->getName() );
									break;
								}
							}
						}
					} catch ( Throwable $ignored ) {
						continue;
					}
				}
			} catch ( Throwable $ignored ) {
				// Absence of introspection evidence remains NOT_PROVEN.
			}
		}

		global $wp_filter;
		foreach ( (array) $wp_filter as $hook_name => $hook ) {
			if ( ! is_object( $hook ) || empty( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) continue;
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( (array) $callbacks as $callback ) {
					$function = isset( $callback['function'] ) ? $callback['function'] : null;
					$owner = is_array( $function ) && isset( $function[0] ) && is_object( $function[0] ) ? $function[0] : null;
					if ( ! $owner || ! $owner instanceof $class ) continue;
					$evidence['hook_callback_matches'][] = array(
						'hook' => sanitize_key( (string) $hook_name ),
						'priority' => (int) $priority,
						'method' => isset( $function[1] ) ? sanitize_key( (string) $function[1] ) : '',
					);
					if ( count( $evidence['hook_callback_matches'] ) >= 20 ) break 3;
				}
			}
		}

		$evidence['registry_property_matches'] = array_values( array_unique( $evidence['registry_property_matches'] ) );
		$evidence['instance_creation_proven'] = ! empty( $evidence['registry_property_matches'] ) || ! empty( $evidence['hook_callback_matches'] );
		return $evidence;
	}

	private function pre_route_guards( array $lines, $start_line ) {
		$guards = array();
		$route_line_index = null;
		foreach ( $lines as $index => $line ) {
			if ( false !== strpos( (string) $line, 'register_rest_route' ) ) { $route_line_index = $index; break; }
		}
		if ( null === $route_line_index ) return $guards;

		for ( $index = 0; $index < $route_line_index; $index++ ) {
			$line = trim( (string) $lines[ $index ] );
			if ( ! preg_match( '/\b(?:if|elseif)\s*\((.*)\)/i', $line, $match ) ) continue;
			$predicate = trim( (string) $match[1] );
			$guards[] = array(
				'line' => (int) $start_line + $index,
				'predicate_sha256' => hash( 'sha256', $predicate ),
				'predicate_tokens' => $this->predicate_tokens( $predicate ),
				'evaluation' => 'UNKNOWN',
			);
			if ( count( $guards ) >= 20 ) break;
		}
		return $guards;
	}

	private function predicate_tokens( $predicate ) {
		$tokens = array();
		$lower = strtolower( (string) $predicate );
		foreach ( array( 'class_exists', 'function_exists', 'current_user_can', 'is_admin', 'wp_doing_ajax', 'rest_request' ) as $token ) {
			if ( false !== strpos( $lower, $token ) ) $tokens[] = $token;
		}
		return array_values( array_unique( $tokens ) );
	}

	private function observed_jetengine_routes() {
		$result = array(
			'rest_server_already_present' => isset( $GLOBALS['wp_rest_server'] ) && is_object( $GLOBALS['wp_rest_server'] ),
			'jetengine_route_count' => 0,
			'mcp_related_route_count' => 0,
			'routes' => array(),
		);
		if ( empty( $result['rest_server_already_present'] ) || ! method_exists( $GLOBALS['wp_rest_server'], 'get_routes' ) ) return $result;
		try {
			$routes = $GLOBALS['wp_rest_server']->get_routes();
			foreach ( is_array( $routes ) ? array_keys( $routes ) : array() as $route ) {
				$route = (string) $route;
				if ( 0 !== strpos( $route, '/jet-engine/' ) && 0 !== strpos( $route, '/jet-engine/v1/' ) ) continue;
				++$result['jetengine_route_count'];
				if ( false !== stripos( $route, 'mcp' ) || false !== stripos( $route, 'feature' ) ) ++$result['mcp_related_route_count'];
				if ( count( $result['routes'] ) < 100 ) $result['routes'][] = $route;
			}
		} catch ( Throwable $ignored ) {
			// Passive diagnostics never attempts to repair or replay route state.
		}
		return $result;
	}

	private function observed_route_count_for_class( $class ) {
		$routes = $this->observed_jetengine_routes();
		if ( empty( $routes['routes'] ) ) return 0;
		$parts = explode( '\\', (string) $class );
		$short = strtolower( (string) end( $parts ) );
		$token = str_replace( '_controller', '', $short );
		$count = 0;
		foreach ( $routes['routes'] as $route ) if ( '' !== $token && false !== stripos( (string) $route, $token ) ) ++$count;
		return $count;
	}
}

MAD4B_SCP_JetEngine_Features_Registration_Diagnostics_Bootstrap::boot();
