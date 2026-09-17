<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only branch diagnostics for JetEngine MCP Features API registration.
 *
 * This adapter never invokes JetEngine callbacks and never registers REST
 * routes. It inspects the already-loaded Registry method plus derived runtime
 * facts to determine whether a recognized early-return branch is provably
 * satisfied. Unknown or compound predicates remain UNKNOWN.
 */
final class MAD4B_SCP_JetEngine_Features_API_Branch_Diagnostics_Bootstrap {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register' ), 9, 1 );
	}

	public static function register( $registry ) {
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Base' ) || ! $registry instanceof MAD4B_SCP_Adapter_Registry ) return;
		$registry->register( new MAD4B_SCP_JetEngine_Features_API_Branch_Diagnostics_Adapter() );
	}
}

final class MAD4B_SCP_JetEngine_Features_API_Branch_Diagnostics_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.jetengine-features-api-branch-diagnostics.v1';
	const ABILITY = 'jetengine/features-api-branch-diagnostics';
	const REGISTRY_CLASS = 'Jet_Engine\\MCP_Tools\\Registry';
	const TARGET_METHOD = 'register_features_api';
	const OPTION_KEY = 'jet-engine-misc-settings';
	const PLUGIN_FILE = 'jet-engine/jet-engine.php';

	private static $known_settings = array( 'enable_features_api', 'enable_mcp_server' );

	public function id() { return 'jetengine-features-api-branch-diagnostics'; }
	public function label() { return 'JetEngine Features API Branch Diagnostics'; }
	protected function certified_provider_key() { return 'native-provider'; }
	protected function mutation_requires_certification() { return false; }
	public function is_available() { return class_exists( self::REGISTRY_CLASS, false ) || function_exists( 'jet_engine' ) || is_file( $this->plugin_path() ); }

	public function ability_names() {
		return array( 'read' => array( self::ABILITY ), 'content' => array(), 'admin' => array(), 'write' => array() );
	}

	public function register_abilities() {
		if ( wp_has_ability( self::ABILITY ) ) return;
		$this->add_ability(
			self::ABILITY,
			'JetEngine Features API Branch Diagnostics',
			'branch_diagnostics',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			null,
			'read',
			true,
			false,
			true
		);
	}

	public function branch_diagnostics() {
		$method = $this->method_source_evidence();
		$settings = $this->setting_state();
		$capabilities = $this->capability_state( isset( $method['source'] ) ? $method['source'] : '' );
		$runtime = $this->runtime_state();
		$callback = $this->callback_state();
		$branches = $this->branch_evidence( isset( $method['lines'] ) ? $method['lines'] : array(), isset( $method['start_line'] ) ? (int) $method['start_line'] : 0, $settings, $capabilities, $runtime );
		$first_early_return = null;
		foreach ( $branches as $branch ) {
			if ( true === $branch['predicate_result'] && ! empty( $branch['early_return'] ) ) {
				$first_early_return = array(
					'line' => $branch['line'],
					'predicate_tokens' => $branch['predicate_tokens'],
					'evaluation' => 'TRUE',
					'return_line' => $branch['return_line'],
				);
				break;
			}
		}

		$classification = 'no_recognized_early_return_proven';
		if ( null !== $first_early_return ) $classification = 'recognized_early_return_proven';
		elseif ( empty( $callback['attached'] ) ) $classification = 'registry_callback_not_attached';
		elseif ( empty( $runtime['rest_api_init_executed'] ) ) $classification = 'rest_api_init_not_executed';
		elseif ( empty( $method['available'] ) ) $classification = 'registry_method_source_unavailable';

		return array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'target' => array(
				'class' => self::REGISTRY_CLASS,
				'method' => self::TARGET_METHOD,
				'class_loaded' => class_exists( self::REGISTRY_CLASS, false ),
				'method_available' => ! empty( $method['available'] ),
				'file' => isset( $method['file'] ) ? $method['file'] : '',
				'start_line' => isset( $method['start_line'] ) ? $method['start_line'] : null,
				'end_line' => isset( $method['end_line'] ) ? $method['end_line'] : null,
				'source_sha256' => isset( $method['source_sha256'] ) ? $method['source_sha256'] : '',
			),
			'callback' => $callback,
			'settings' => $settings,
			'capabilities' => $capabilities,
			'runtime' => $runtime,
			'branches' => $branches,
			'first_proven_early_return' => $first_early_return,
			'classification' => $classification,
			'callback_execution_attempted' => false,
			'route_registration_attempted' => false,
			'rest_api_init_replayed' => false,
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
		if ( '' === $root || ! is_string( $file ) ) return '';
		$normalized_file = wp_normalize_path( $file );
		$normalized_root = wp_normalize_path( $root );
		if ( 0 !== strpos( $normalized_file, $normalized_root ) ) return '';
		return ltrim( substr( $normalized_file, strlen( $normalized_root ) ), '/' );
	}

	private function method_source_evidence() {
		$result = array( 'available' => false, 'file' => '', 'start_line' => null, 'end_line' => null, 'source_sha256' => '', 'source' => '', 'lines' => array() );
		if ( ! class_exists( self::REGISTRY_CLASS, false ) || ! method_exists( self::REGISTRY_CLASS, self::TARGET_METHOD ) ) return $result;
		try {
			$ref = new ReflectionMethod( self::REGISTRY_CLASS, self::TARGET_METHOD );
			$file = (string) $ref->getFileName();
			$root = $this->plugin_root();
			if ( '' === $file || '' === $root || 0 !== strpos( wp_normalize_path( $file ), wp_normalize_path( $root ) ) || ! is_file( $file ) ) return $result;
			$all_lines = @file( $file, FILE_IGNORE_NEW_LINES );
			if ( ! is_array( $all_lines ) ) return $result;
			$start = (int) $ref->getStartLine();
			$end = (int) $ref->getEndLine();
			$length = max( 0, $end - $start + 1 );
			$lines = array_slice( $all_lines, max( 0, $start - 1 ), $length );
			$source = implode( "\n", $lines );
			$result = array(
				'available' => true,
				'file' => $this->relative_file( $file ),
				'start_line' => $start,
				'end_line' => $end,
				'source_sha256' => hash( 'sha256', $source ),
				'source' => $source,
				'lines' => $lines,
			);
		} catch ( Exception $e ) {
			$result['reflection_error'] = get_class( $e );
		}
		return $result;
	}

	private function normalize_bool_state( $value ) {
		if ( true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'on' === $value || 'enabled' === $value || 'true' === $value ) return true;
		if ( false === $value || 0 === $value || '0' === $value || 'no' === $value || 'off' === $value || 'disabled' === $value || 'false' === $value || '' === $value || null === $value ) return false;
		return null;
	}

	private function setting_state() {
		$result = array();
		$option = function_exists( 'get_option' ) ? get_option( self::OPTION_KEY, array() ) : array();
		foreach ( self::$known_settings as $key ) {
			$present = is_array( $option ) && array_key_exists( $key, $option );
			$value = $present ? $this->normalize_bool_state( $option[ $key ] ) : null;
			$result[ $key ] = array(
				'discoverable' => $present,
				'state' => true === $value ? 'enabled' : ( false === $value ? 'disabled' : 'unknown' ),
				'boolean' => $value,
			);
		}
		return $result;
	}

	private function capability_state( $source ) {
		$result = array();
		if ( ! is_string( $source ) || '' === $source ) return $result;
		preg_match_all( '/current_user_can\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/i', $source, $matches );
		foreach ( isset( $matches[1] ) ? $matches[1] : array() as $capability ) {
			$capability = sanitize_key( (string) $capability );
			if ( '' === $capability || isset( $result[ $capability ] ) ) continue;
			$result[ $capability ] = function_exists( 'current_user_can' ) ? (bool) current_user_can( $capability ) : null;
		}
		return $result;
	}

	private function runtime_state() {
		return array(
			'rest_api_init_executed' => function_exists( 'did_action' ) ? did_action( 'rest_api_init' ) > 0 : false,
			'rest_api_init_count' => function_exists( 'did_action' ) ? (int) did_action( 'rest_api_init' ) : 0,
			'is_admin' => function_exists( 'is_admin' ) ? (bool) is_admin() : null,
			'wp_doing_ajax' => function_exists( 'wp_doing_ajax' ) ? (bool) wp_doing_ajax() : null,
			'rest_request_defined' => defined( 'REST_REQUEST' ),
			'rest_request' => defined( 'REST_REQUEST' ) ? (bool) REST_REQUEST : null,
			'current_filter' => function_exists( 'current_filter' ) ? (string) current_filter() : '',
		);
	}

	private function callback_state() {
		global $wp_filter;
		$result = array( 'attached' => false, 'matches' => array() );
		if ( ! isset( $wp_filter['rest_api_init'] ) || ! is_object( $wp_filter['rest_api_init'] ) || ! isset( $wp_filter['rest_api_init']->callbacks ) || ! is_array( $wp_filter['rest_api_init']->callbacks ) ) return $result;
		foreach ( $wp_filter['rest_api_init']->callbacks as $priority => $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$function = isset( $callback['function'] ) ? $callback['function'] : null;
				if ( ! is_array( $function ) || 2 !== count( $function ) ) continue;
				$owner = is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0];
				$method = (string) $function[1];
				if ( self::REGISTRY_CLASS !== $owner || self::TARGET_METHOD !== $method ) continue;
				$result['attached'] = true;
				$result['matches'][] = array(
					'priority' => (int) $priority,
					'accepted_args' => isset( $callback['accepted_args'] ) ? (int) $callback['accepted_args'] : null,
				);
			}
		}
		return $result;
	}

	private function branch_evidence( array $lines, $start_line, array $settings, array $capabilities, array $runtime ) {
		$result = array();
		$count = count( $lines );
		for ( $i = 0; $i < $count; $i++ ) {
			$line = (string) $lines[ $i ];
			if ( ! preg_match( '/\b(?:if|elseif)\s*\((.*)\)/i', $line, $match ) ) continue;
			$condition = trim( (string) $match[1] );
			$tokens = $this->predicate_tokens( $condition );
			if ( empty( $tokens ) ) continue;
			$evaluation = $this->evaluate_condition( $condition, $tokens, $settings, $capabilities, $runtime );
			$return_line = null;
			for ( $j = $i; $j < min( $count, $i + 8 ); $j++ ) {
				if ( preg_match( '/\breturn\b/', (string) $lines[ $j ] ) ) { $return_line = $start_line + $j; break; }
				if ( $j > $i && preg_match( '/\b(?:if|elseif|else)\b/', (string) $lines[ $j ] ) ) break;
			}
			$result[] = array(
				'line' => $start_line + $i,
				'predicate_tokens' => $tokens,
				'predicate_result' => $evaluation,
				'evaluation' => true === $evaluation ? 'TRUE' : ( false === $evaluation ? 'FALSE' : 'UNKNOWN' ),
				'early_return' => null !== $return_line,
				'return_line' => $return_line,
			);
			if ( count( $result ) >= 50 ) break;
		}
		return $result;
	}

	private function predicate_tokens( $condition ) {
		$tokens = array();
		$lower = strtolower( (string) $condition );
		foreach ( self::$known_settings as $setting ) if ( false !== strpos( $lower, $setting ) ) $tokens[] = $setting;
		if ( false !== strpos( $lower, 'current_user_can' ) ) $tokens[] = 'current_user_can';
		if ( false !== strpos( $lower, 'is_admin' ) ) $tokens[] = 'is_admin';
		if ( false !== strpos( $lower, 'wp_doing_ajax' ) ) $tokens[] = 'wp_doing_ajax';
		if ( false !== strpos( $lower, 'rest_request' ) ) $tokens[] = 'rest_request';
		return array_values( array_unique( $tokens ) );
	}

	private function evaluate_condition( $condition, array $tokens, array $settings, array $capabilities, array $runtime ) {
		if ( 1 !== count( $tokens ) ) return null;
		$token = $tokens[0];
		$lower = strtolower( preg_replace( '/\s+/', '', (string) $condition ) );
		$negated = false !== strpos( $lower, '!' . strtolower( $token ) ) || false !== strpos( $lower, '!(' );
		$value = null;

		if ( isset( $settings[ $token ] ) ) {
			$value = $settings[ $token ]['boolean'];
		} elseif ( 'is_admin' === $token ) {
			$value = $runtime['is_admin'];
		} elseif ( 'wp_doing_ajax' === $token ) {
			$value = $runtime['wp_doing_ajax'];
		} elseif ( 'rest_request' === $token ) {
			$value = $runtime['rest_request'];
		} elseif ( 'current_user_can' === $token ) {
			preg_match( '/current_user_can\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/i', (string) $condition, $match );
			$cap = isset( $match[1] ) ? sanitize_key( (string) $match[1] ) : '';
			$value = '' !== $cap && array_key_exists( $cap, $capabilities ) ? $capabilities[ $cap ] : null;
		}
		if ( ! is_bool( $value ) ) return null;
		return $negated ? ! $value : $value;
	}
}

MAD4B_SCP_JetEngine_Features_API_Branch_Diagnostics_Bootstrap::boot();
