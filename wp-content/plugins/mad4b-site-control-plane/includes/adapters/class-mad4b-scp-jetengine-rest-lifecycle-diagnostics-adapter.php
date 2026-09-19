<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only JetEngine REST lifecycle diagnostics.
 *
 * This adapter never invokes provider callbacks, never registers routes, never
 * creates a REST server, and never mutates settings. It inspects the already
 * materialized WordPress hook registry plus Reflection/source metadata for the
 * loaded JetEngine MCP controllers in order to explain why routes are absent.
 */
final class MAD4B_SCP_JetEngine_REST_Lifecycle_Diagnostics_Bootstrap {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register' ), 9, 1 );
	}

	public static function register( $registry ) {
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Base' ) || ! $registry instanceof MAD4B_SCP_Adapter_Registry ) return;
		$registry->register( new MAD4B_SCP_JetEngine_REST_Lifecycle_Diagnostics_Adapter() );
	}
}

final class MAD4B_SCP_JetEngine_REST_Lifecycle_Diagnostics_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.jetengine-rest-lifecycle-diagnostics.v1';
	const ABILITY = 'jetengine/rest-lifecycle-diagnostics';
	const PLUGIN_FILE = 'jet-engine/jet-engine.php';

	private static $target_classes = array(
		'Jet_Engine\\MCP_Tools\\Feature',
		'Jet_Engine\\MCP_Tools\\Registry',
		'Jet_Engine\\MCP_Tools\\Rest_API\\Get_Controller',
		'Jet_Engine\\MCP_Tools\\Rest_API\\MCP_Controller',
		'Jet_Engine\\MCP_Tools\\Rest_API\\Run_Controller',
		'Jet_Engine\\Post_Types\\MCP\\Controller',
		'Jet_Engine\\Taxonomies\\MCP\\Controller',
		'Jet_Engine\\Meta_Boxes\\MCP\\Controller',
		'Jet_Engine\\Query_Builder\\MCP\\Controller',
		'Jet_Engine\\Listings\\MCP\\Controller',
	);

	public function id() { return 'jetengine-rest-lifecycle-diagnostics'; }
	public function label() { return 'JetEngine REST Lifecycle Diagnostics'; }
	protected function certified_provider_key() { return 'native-provider'; }
	protected function mutation_requires_certification() { return false; }
	public function is_available() { return function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' ) || is_file( $this->plugin_path() ); }

	public function ability_names() {
		return array( 'read' => array( self::ABILITY ), 'content' => array(), 'admin' => array(), 'write' => array() );
	}

	public function register_abilities() {
		if ( wp_has_ability( self::ABILITY ) ) return;
		$this->add_ability(
			self::ABILITY,
			'JetEngine REST Lifecycle Diagnostics',
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
		$rest_hook = $this->hook_snapshot( 'rest_api_init' );
		$related_hooks = array();
		foreach ( array( 'plugins_loaded', 'init', 'rest_api_init', 'jet-engine/init', 'jet-engine/rest-api/init' ) as $hook ) {
			$related_hooks[ $hook ] = $this->hook_snapshot( $hook );
		}

		$classes = array();
		$source_files = array();
		foreach ( self::$target_classes as $class ) {
			$info = $this->class_evidence( $class );
			$classes[] = $info;
			if ( ! empty( $info['absolute_file'] ) && is_string( $info['absolute_file'] ) ) $source_files[ $info['absolute_file'] ] = true;
		}

		foreach ( (array) $rest_hook['jetengine_callbacks'] as $callback ) {
			if ( ! empty( $callback['absolute_file'] ) ) $source_files[ $callback['absolute_file'] ] = true;
		}

		$source = array();
		foreach ( array_keys( $source_files ) as $file ) {
			$entry = $this->source_lifecycle_evidence( $file );
			if ( ! empty( $entry ) ) $source[] = $entry;
			if ( count( $source ) >= 40 ) break;
		}

		$route_state = $this->route_state();
		$classification = $this->classify( $rest_hook, $classes, $source, $route_state );

		return array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'rest_api_init' => array(
				'did_action' => function_exists( 'did_action' ) ? (int) did_action( 'rest_api_init' ) : 0,
				'current_filter' => function_exists( 'current_filter' ) ? (string) current_filter() : '',
				'total_callback_count' => (int) $rest_hook['total_callback_count'],
				'jetengine_callback_count' => count( $rest_hook['jetengine_callbacks'] ),
				'jetengine_callbacks' => $rest_hook['jetengine_callbacks'],
			),
			'related_hooks' => $related_hooks,
			'controllers' => $this->strip_absolute_paths( $classes ),
			'source_lifecycle_evidence' => $this->strip_absolute_paths( $source ),
			'route_state' => $route_state,
			'classification' => $classification,
			'callback_execution_attempted' => false,
			'route_registration_attempted' => false,
			'rest_server_instantiated' => false,
			'settings_mutation' => false,
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
		if ( '' === $root || ! is_string( $file ) || 0 !== strpos( wp_normalize_path( $file ), wp_normalize_path( $root ) ) ) return '';
		return ltrim( substr( wp_normalize_path( $file ), strlen( wp_normalize_path( $root ) ) ), '/' );
	}

	private function hook_snapshot( $hook_name ) {
		global $wp_filter;
		$result = array( 'hook' => (string) $hook_name, 'did_action' => function_exists( 'did_action' ) ? (int) did_action( $hook_name ) : 0, 'total_callback_count' => 0, 'jetengine_callbacks' => array() );
		if ( ! isset( $wp_filter[ $hook_name ] ) || ! is_object( $wp_filter[ $hook_name ] ) || ! isset( $wp_filter[ $hook_name ]->callbacks ) || ! is_array( $wp_filter[ $hook_name ]->callbacks ) ) return $result;

		foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$result['total_callback_count']++;
				$function = isset( $callback['function'] ) ? $callback['function'] : null;
				$evidence = $this->callable_evidence( $function );
				if ( ! $this->is_jetengine_callable( $evidence ) ) continue;
				$evidence['priority'] = (int) $priority;
				$evidence['accepted_args'] = isset( $callback['accepted_args'] ) ? (int) $callback['accepted_args'] : null;
				$result['jetengine_callbacks'][] = $evidence;
				if ( count( $result['jetengine_callbacks'] ) >= 100 ) break 2;
			}
		}
		return $result;
	}

	private function callable_evidence( $callable ) {
		$result = array( 'label' => '', 'owner_class' => '', 'method' => '', 'file' => '', 'absolute_file' => '', 'start_line' => null, 'end_line' => null );
		try {
			if ( is_array( $callable ) && 2 === count( $callable ) ) {
				$owner = is_object( $callable[0] ) ? get_class( $callable[0] ) : (string) $callable[0];
				$method = (string) $callable[1];
				$result['label'] = $owner . '::' . $method;
				$result['owner_class'] = $owner;
				$result['method'] = $method;
				if ( method_exists( $owner, $method ) ) {
					$ref = new ReflectionMethod( $owner, $method );
					$result['absolute_file'] = (string) $ref->getFileName();
					$result['file'] = $this->relative_file( $result['absolute_file'] );
					$result['start_line'] = (int) $ref->getStartLine();
					$result['end_line'] = (int) $ref->getEndLine();
				}
			} elseif ( is_string( $callable ) ) {
				$result['label'] = $callable;
				if ( function_exists( $callable ) ) {
					$ref = new ReflectionFunction( $callable );
					$result['absolute_file'] = (string) $ref->getFileName();
					$result['file'] = $this->relative_file( $result['absolute_file'] );
					$result['start_line'] = (int) $ref->getStartLine();
					$result['end_line'] = (int) $ref->getEndLine();
				}
			} elseif ( $callable instanceof Closure ) {
				$result['label'] = 'Closure';
				$ref = new ReflectionFunction( $callable );
				$result['absolute_file'] = (string) $ref->getFileName();
				$result['file'] = $this->relative_file( $result['absolute_file'] );
				$result['start_line'] = (int) $ref->getStartLine();
				$result['end_line'] = (int) $ref->getEndLine();
			} elseif ( is_object( $callable ) ) {
				$result['label'] = get_class( $callable );
				$result['owner_class'] = get_class( $callable );
			}
		} catch ( Exception $e ) {
			$result['reflection_error'] = get_class( $e );
		}
		return $result;
	}

	private function is_jetengine_callable( array $evidence ) {
		// relative_file() only returns a non-empty path for source physically under
		// the installed JetEngine plugin root. Treat that as authoritative provider
		// ownership so anonymous closures/functions cannot be missed merely because
		// their callable label does not contain a JetEngine namespace token.
		if ( ! empty( $evidence['file'] ) ) return true;
		$text = strtolower( (string) $evidence['label'] . ' ' . (string) $evidence['owner_class'] );
		return false !== strpos( $text, 'jet_engine' ) || false !== strpos( $text, 'jet-engine' ) || false !== strpos( $text, 'jetengine' );
	}

	private function class_evidence( $class ) {
		$result = array( 'class' => (string) $class, 'loaded' => class_exists( $class, false ), 'file' => '', 'absolute_file' => '', 'start_line' => null, 'end_line' => null, 'constructor' => false, 'lifecycle_methods' => array() );
		if ( ! $result['loaded'] ) return $result;
		try {
			$ref = new ReflectionClass( $class );
			$result['absolute_file'] = (string) $ref->getFileName();
			$result['file'] = $this->relative_file( $result['absolute_file'] );
			$result['start_line'] = (int) $ref->getStartLine();
			$result['end_line'] = (int) $ref->getEndLine();
			$result['constructor'] = $ref->hasMethod( '__construct' );
			foreach ( $ref->getMethods() as $method ) {
				$name = strtolower( $method->getName() );
				if ( false === strpos( $name, 'rest' ) && false === strpos( $name, 'route' ) && false === strpos( $name, 'register' ) && false === strpos( $name, 'init' ) && false === strpos( $name, 'hook' ) ) continue;
				$result['lifecycle_methods'][] = array(
					'name' => $method->getName(),
					'public' => $method->isPublic(),
					'static' => $method->isStatic(),
					'start_line' => (int) $method->getStartLine(),
					'end_line' => (int) $method->getEndLine(),
				);
				if ( count( $result['lifecycle_methods'] ) >= 30 ) break;
			}
		} catch ( Exception $e ) {
			$result['reflection_error'] = get_class( $e );
		}
		return $result;
	}

	private function source_lifecycle_evidence( $file ) {
		if ( ! is_string( $file ) || '' === $file || ! is_file( $file ) ) return array();
		$root = $this->plugin_root();
		if ( '' === $root || 0 !== strpos( wp_normalize_path( $file ), wp_normalize_path( $root ) ) ) return array();
		$size = filesize( $file );
		if ( false === $size || $size <= 0 || $size > 1048576 ) return array();
		$src = @file_get_contents( $file );
		if ( ! is_string( $src ) || '' === $src ) return array();
		$lines = preg_split( '/\R/', $src );
		if ( ! is_array( $lines ) ) return array();

		$signals = array();
		$tokens = array( 'rest_api_init', 'register_rest_route', 'enable_mcp_server', 'enable_features_api', 'current_user_can', 'is_admin', 'wp_doing_ajax', 'REST_REQUEST', 'return false', 'return null', 'return;' );
		foreach ( $lines as $index => $line ) {
			$lower = strtolower( (string) $line );
			$matched = array();
			foreach ( $tokens as $token ) if ( false !== strpos( $lower, strtolower( $token ) ) ) $matched[] = $token;
			if ( empty( $matched ) ) continue;
			$signals[] = array( 'line' => $index + 1, 'tokens' => array_values( array_unique( $matched ) ) );
			if ( count( $signals ) >= 120 ) break;
		}

		preg_match_all( '/add_action\s*\(\s*[\'\"]rest_api_init[\'\"][^;]{0,500};/i', $src, $hook_matches );
		preg_match_all( '/register_rest_route\s*\(/i', $src, $route_matches );
		preg_match_all( '/(?:if|elseif)\s*\([^\)]{0,500}(?:enable_mcp_server|enable_features_api|current_user_can|is_admin|wp_doing_ajax|REST_REQUEST)[^\)]{0,500}\)/i', $src, $guard_matches );

		$guard_tokens = array();
		foreach ( isset( $guard_matches[0] ) ? $guard_matches[0] : array() as $guard ) {
			$lower = strtolower( (string) $guard );
			foreach ( array( 'enable_mcp_server', 'enable_features_api', 'current_user_can', 'is_admin', 'wp_doing_ajax', 'rest_request' ) as $token ) if ( false !== strpos( $lower, $token ) ) $guard_tokens[ $token ] = true;
		}

		return array(
			'file' => $this->relative_file( $file ),
			'absolute_file' => $file,
			'rest_api_init_hook_statement_count' => isset( $hook_matches[0] ) ? count( $hook_matches[0] ) : 0,
			'register_rest_route_call_count' => isset( $route_matches[0] ) ? count( $route_matches[0] ) : 0,
			'conditional_guard_tokens' => array_values( array_keys( $guard_tokens ) ),
			'lifecycle_signals' => $signals,
			'source_sha256' => hash( 'sha256', $src ),
		);
	}

	private function route_state() {
		$result = array( 'rest_api_initialized' => function_exists( 'did_action' ) ? did_action( 'rest_api_init' ) > 0 : false, 'jetengine_route_count' => 0, 'mcp_related_route_count' => 0, 'routes' => array() );
		if ( empty( $result['rest_api_initialized'] ) ) return $result;
		global $wp_rest_server;
		if ( ! is_object( $wp_rest_server ) || ! method_exists( $wp_rest_server, 'get_routes' ) ) return $result;
		$routes = $wp_rest_server->get_routes();
		if ( ! is_array( $routes ) ) return $result;
		foreach ( array_keys( $routes ) as $route ) {
			$lower = strtolower( (string) $route );
			if ( false === strpos( $lower, 'jet-engine' ) && false === strpos( $lower, 'jetengine' ) ) continue;
			$result['jetengine_route_count']++;
			if ( false !== strpos( $lower, 'mcp' ) || false !== strpos( $lower, 'tool' ) || false !== strpos( $lower, 'command' ) ) $result['mcp_related_route_count']++;
			$result['routes'][] = (string) $route;
			if ( count( $result['routes'] ) >= 100 ) break;
		}
		return $result;
	}

	private function classify( array $rest_hook, array $classes, array $source, array $route_state ) {
		$loaded_controller_count = 0;
		foreach ( $classes as $class ) if ( ! empty( $class['loaded'] ) && false !== strpos( strtolower( (string) $class['class'] ), 'controller' ) ) $loaded_controller_count++;
		$source_route_calls = 0;
		$source_rest_hooks = 0;
		$guard_tokens = array();
		foreach ( $source as $entry ) {
			$source_route_calls += isset( $entry['register_rest_route_call_count'] ) ? (int) $entry['register_rest_route_call_count'] : 0;
			$source_rest_hooks += isset( $entry['rest_api_init_hook_statement_count'] ) ? (int) $entry['rest_api_init_hook_statement_count'] : 0;
			foreach ( isset( $entry['conditional_guard_tokens'] ) ? (array) $entry['conditional_guard_tokens'] : array() as $token ) $guard_tokens[ $token ] = true;
		}

		$codes = array();
		if ( ! empty( $route_state['rest_api_initialized'] ) && 0 === (int) $route_state['jetengine_route_count'] && $loaded_controller_count > 0 ) {
			if ( 0 === count( $rest_hook['jetengine_callbacks'] ) ) {
				$codes[] = 'jetengine_rest_callbacks_not_attached_to_rest_api_init';
				if ( $source_rest_hooks > 0 || $source_route_calls > 0 ) $codes[] = 'jetengine_controller_bootstrap_missing_or_completed_after_rest_api_init';
			} else {
				$codes[] = 'jetengine_rest_callbacks_attached_but_routes_absent';
				$codes[] = 'jetengine_registration_callback_returned_without_routes_or_registration_failed';
			}
		}
		if ( empty( $codes ) ) $codes[] = 'no_specific_lifecycle_failure_classified';
		return array(
			'codes' => $codes,
			'loaded_controller_count' => $loaded_controller_count,
			'source_register_rest_route_call_count' => $source_route_calls,
			'source_rest_api_init_hook_statement_count' => $source_rest_hooks,
			'conditional_guard_tokens' => array_values( array_keys( $guard_tokens ) ),
			'interpretation' => 'Evidence is observational only; callback execution is never replayed by this diagnostic.',
		);
	}

	private function strip_absolute_paths( array $items ) {
		foreach ( $items as &$item ) {
			if ( is_array( $item ) ) {
				unset( $item['absolute_file'] );
				foreach ( $item as $key => $value ) if ( is_array( $value ) ) $item[ $key ] = $this->strip_absolute_paths( $value );
			}
		}
		unset( $item );
		return $items;
	}
}

MAD4B_SCP_JetEngine_REST_Lifecycle_Diagnostics_Bootstrap::boot();
