<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only JetEngine MCP/Features diagnostics.
 *
 * This adapter intentionally exposes derived, allowlisted evidence only. It
 * never mutates JetEngine settings, never dumps provider options, never reads
 * credentials, and never instantiates the WordPress REST server early.
 */
final class MAD4B_SCP_JetEngine_Diagnostics_Bootstrap {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register' ), 8, 1 );
	}

	public static function register( $registry ) {
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Base' ) || ! $registry instanceof MAD4B_SCP_Adapter_Registry ) return;
		$registry->register( new MAD4B_SCP_JetEngine_Diagnostics_Adapter() );
	}
}

final class MAD4B_SCP_JetEngine_Diagnostics_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.jetengine-native-diagnostics.v1';
	const ABILITY = 'jetengine/native-diagnostics';
	const PLUGIN_FILE = 'jet-engine/jet-engine.php';
	const MAX_SOURCE_FILES = 700;
	const MAX_SOURCE_BYTES = 16777216;
	const MAX_SINGLE_FILE_BYTES = 786432;

	public function id() { return 'jetengine-diagnostics'; }
	public function label() { return 'JetEngine Diagnostics'; }
	protected function certified_provider_key() { return 'native-provider'; }
	protected function mutation_requires_certification() { return false; }

	public function is_available() {
		return function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' ) || is_file( $this->plugin_path() );
	}

	public function ability_names() {
		return array(
			'read' => array( self::ABILITY ),
			'content' => array(),
			'admin' => array(),
			'write' => array(),
		);
	}

	public function register_abilities() {
		if ( wp_has_ability( self::ABILITY ) ) return;
		$this->add_ability(
			self::ABILITY,
			'JetEngine Native Diagnostics',
			'diagnostics',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			null,
			'read',
			true,
			false,
			true
		);
	}

	protected function detect_plugin_version() {
		$meta = $this->plugin_metadata();
		return isset( $meta['version'] ) ? (string) $meta['version'] : '';
	}

	public function diagnostics() {
		$plugin = $this->plugin_metadata();
		$source = $this->source_evidence();
		$settings = $this->resolve_settings_from_source( $source );
		$routes = $this->route_inventory();
		$transport = class_exists( 'MAD4B_SCP_JetEngine_MCP_Client' )
			? MAD4B_SCP_JetEngine_MCP_Client::transport_status()
			: array( 'available' => false, 'preferred_transport' => 'unavailable' );
		$tools = array( 'count' => 0, 'error' => '', 'names' => array() );
		if ( class_exists( 'MAD4B_SCP_JetEngine_MCP_Client' ) && ! empty( $transport['available'] ) ) {
			$native = MAD4B_SCP_JetEngine_MCP_Client::tools();
			if ( is_wp_error( $native ) ) {
				$tools['error'] = (string) $native->get_error_code();
			} elseif ( is_array( $native ) ) {
				$tools['count'] = count( $native );
				$tools['names'] = array_slice( array_values( array_map( 'strval', array_keys( $native ) ) ), 0, 200 );
			}
		}

		$loaded = array();
		foreach ( (array) $source['declared_classes'] as $class ) {
			$loaded[] = array( 'class' => (string) $class, 'loaded' => class_exists( $class, false ) );
		}

		$capabilities = $this->capability_evidence( $source );
		$root_causes = $this->classify_root_causes( $plugin, $settings, $routes, $transport, $loaded );

		return array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'plugin' => $plugin,
			'settings' => $settings,
			'transport' => $transport,
			'routes' => $routes,
			'native_tools' => $tools,
			'components' => array(
				'declared_classes' => $loaded,
				'mcp_source_files_present' => $source['mcp_source_files_present'],
				'matched_source_files' => $source['matched_source_files'],
				'mcp_component_loaded' => $this->any_loaded_component( $loaded, array( 'mcp', 'tool' ) ),
				'features_component_loaded' => $this->any_loaded_component( $loaded, array( 'feature' ) ),
				'command_center_component_loaded' => $this->any_loaded_component( $loaded, array( 'command', 'center' ) ),
			),
			'capabilities' => $capabilities,
			'source_signals' => array(
				'setting_identifiers' => $source['setting_identifiers'],
				'option_keys' => $source['option_keys'],
				'route_candidates' => $source['route_candidates'],
				'capability_identifiers' => $source['capability_identifiers'],
				'scan_files' => $source['scan_files'],
				'scan_bytes' => $source['scan_bytes'],
				'scan_truncated' => $source['scan_truncated'],
			),
			'provider_self_description' => array(
				'mcp_url' => ! empty( $transport['mcp_jsonrpc_endpoint'] ) ? rest_url( ltrim( $transport['mcp_jsonrpc_endpoint'], '/' ) ) : '',
				'native_rest_registry_url' => ! empty( $transport['native_rest_registry_endpoint'] ) ? rest_url( ltrim( $transport['native_rest_registry_endpoint'], '/' ) ) : '',
				'tool_count' => (int) $tools['count'],
				'resource_count' => null,
			),
			'root_causes' => $root_causes,
			'no_secrets_exposed' => true,
			'settings_mutation' => false,
			'tools_call_executed' => false,
		);
	}

	private function plugin_path() {
		return defined( 'WP_PLUGIN_DIR' ) ? trailingslashit( WP_PLUGIN_DIR ) . self::PLUGIN_FILE : '';
	}

	private function plugin_root() {
		$path = $this->plugin_path();
		return '' !== $path ? dirname( $path ) : '';
	}

	private function plugin_metadata() {
		$path = $this->plugin_path();
		$version = '';
		$name = 'JetEngine';
		if ( '' !== $path && is_file( $path ) && function_exists( 'get_file_data' ) ) {
			$data = get_file_data( $path, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ), 'plugin' );
			if ( is_array( $data ) ) {
				if ( ! empty( $data['Name'] ) ) $name = (string) $data['Name'];
				if ( ! empty( $data['Version'] ) ) $version = (string) $data['Version'];
			}
		}
		$active = function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' );
		return array(
			'plugin_file' => self::PLUGIN_FILE,
			'plugin_name' => $name,
			'active' => (bool) $active,
			'version' => $version,
			'version_gte_3_8_0' => '' !== $version ? version_compare( $version, '3.8.0', '>=' ) : null,
			'version_gte_3_8_8' => '' !== $version ? version_compare( $version, '3.8.8', '>=' ) : null,
		);
	}

	private function route_inventory() {
		$result = array(
			'rest_api_initialized' => function_exists( 'did_action' ) ? did_action( 'rest_api_init' ) > 0 : false,
			'count' => 0,
			'mcp_related_count' => 0,
			'jsonrpc_mcp_route_found' => false,
			'mcp_tools_route_found' => false,
			'mcp_tool_run_route_found' => false,
			'items' => array(),
		);
		if ( empty( $result['rest_api_initialized'] ) ) return $result;
		global $wp_rest_server;
		if ( ! is_object( $wp_rest_server ) || ! method_exists( $wp_rest_server, 'get_routes' ) ) return $result;
		$routes = $wp_rest_server->get_routes();
		if ( ! is_array( $routes ) ) return $result;
		foreach ( $routes as $route => $endpoints ) {
			$lower = strtolower( (string) $route );
			if ( false === strpos( $lower, 'jet-engine' ) && false === strpos( $lower, 'jetengine' ) ) continue;
			$result['count']++;
			$is_mcp = false !== strpos( $lower, 'mcp' ) || false !== strpos( $lower, 'command' ) || false !== strpos( $lower, 'tool' );
			if ( $is_mcp ) $result['mcp_related_count']++;
			if ( preg_match( '#/mcp/?$#', $lower ) ) $result['jsonrpc_mcp_route_found'] = true;
			if ( preg_match( '#/mcp-tools/?$#', $lower ) ) $result['mcp_tools_route_found'] = true;
			if ( false !== strpos( $lower, '/mcp-tools/run/' ) ) $result['mcp_tool_run_route_found'] = true;
			$methods = array();
			$callbacks = array();
			$permissions = array();
			foreach ( (array) $endpoints as $endpoint ) {
				if ( ! is_array( $endpoint ) ) continue;
				if ( isset( $endpoint['methods'] ) ) {
					foreach ( $this->normalize_methods( $endpoint['methods'] ) as $method ) $methods[ $method ] = true;
				}
				if ( isset( $endpoint['callback'] ) ) $callbacks[ $this->callable_label( $endpoint['callback'] ) ] = true;
				if ( isset( $endpoint['permission_callback'] ) ) $permissions[ $this->callable_label( $endpoint['permission_callback'] ) ] = true;
			}
			$result['items'][] = array(
				'route' => (string) $route,
				'methods' => array_values( array_keys( $methods ) ),
				'callbacks' => array_values( array_filter( array_keys( $callbacks ) ) ),
				'permission_callbacks' => array_values( array_filter( array_keys( $permissions ) ) ),
				'mcp_related' => $is_mcp,
			);
			if ( count( $result['items'] ) >= 100 ) break;
		}
		return $result;
	}

	private function normalize_methods( $methods ) {
		if ( is_array( $methods ) ) return array_values( array_map( 'strval', array_keys( array_filter( $methods ) ) ) );
		if ( is_string( $methods ) ) return array_values( array_filter( array_map( 'trim', explode( ',', $methods ) ) ) );
		return array();
	}

	private function callable_label( $callable ) {
		if ( is_string( $callable ) ) return sanitize_text_field( $callable );
		if ( is_array( $callable ) && 2 === count( $callable ) ) {
			$owner = is_object( $callable[0] ) ? get_class( $callable[0] ) : (string) $callable[0];
			return sanitize_text_field( $owner . '::' . (string) $callable[1] );
		}
		if ( $callable instanceof Closure ) return 'Closure';
		if ( is_object( $callable ) ) return get_class( $callable );
		return '';
	}

	private function source_evidence() {
		$result = array(
			'mcp_source_files_present' => array(),
			'matched_source_files' => array(),
			'setting_identifiers' => array(),
			'option_keys' => array(),
			'route_candidates' => array(),
			'capability_identifiers' => array(),
			'declared_classes' => array(),
			'scan_files' => 0,
			'scan_bytes' => 0,
			'scan_truncated' => false,
		);
		$root = $this->plugin_root();
		if ( '' === $root || ! is_dir( $root ) ) return $result;

		$known = array(
			'includes/core/mcp-tools/feature.php',
			'includes/core/mcp-tools/registry.php',
			'includes/core/mcp-tools/rest-api/mcp-controller.php',
			'includes/core/mcp-tools/rest-api/get-controller.php',
			'includes/core/mcp-tools/rest-api/run-controller.php',
		);
		foreach ( $known as $relative ) if ( is_file( trailingslashit( $root ) . $relative ) ) $result['mcp_source_files_present'][] = $relative;

		try {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		} catch ( Exception $e ) {
			return $result;
		}

		foreach ( $iterator as $file ) {
			if ( $result['scan_files'] >= self::MAX_SOURCE_FILES || $result['scan_bytes'] >= self::MAX_SOURCE_BYTES ) { $result['scan_truncated'] = true; break; }
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) continue;
			$size = (int) $file->getSize();
			if ( $size <= 0 || $size > self::MAX_SINGLE_FILE_BYTES || $result['scan_bytes'] + $size > self::MAX_SOURCE_BYTES ) continue;
			$path = $file->getPathname();
			$src = @file_get_contents( $path );
			$result['scan_files']++;
			$result['scan_bytes'] += $size;
			if ( ! is_string( $src ) || '' === $src ) continue;
			$lower = strtolower( $src );
			if ( false === strpos( $lower, 'mcp' ) && false === strpos( $lower, 'features api' ) && false === strpos( $lower, 'command center' ) ) continue;

			$relative = ltrim( str_replace( '\\', '/', substr( $path, strlen( $root ) ) ), '/' );
			$result['matched_source_files'][ $relative ] = true;

			if ( preg_match_all( '/[\'\"]([a-z0-9_.:\/-]*(?:mcp|feature|command)[a-z0-9_.:\/-]*)[\'\"]/i', $src, $matches ) ) {
				foreach ( $matches[1] as $value ) {
					$value = trim( (string) $value );
					if ( '' !== $value && strlen( $value ) <= 191 ) $result['setting_identifiers'][ $value ] = true;
				}
			}
			if ( preg_match_all( '/(?:get_option|update_option|add_option)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/i', $src, $matches ) ) {
				foreach ( $matches[1] as $value ) if ( $this->safe_option_key( $value ) ) $result['option_keys'][ (string) $value ] = true;
			}
			if ( preg_match_all( '#[\'\"](/?jet-engine/v[0-9]+/[^\'\"]*(?:mcp|tool|command)[^\'\"]*)[\'\"]#i', $src, $matches ) ) {
				foreach ( $matches[1] as $value ) $result['route_candidates'][ (string) $value ] = true;
			}
			if ( preg_match_all( '/[\'\"]((?:manage|edit|read)_[a-z0-9_]*(?:jet|engine)[a-z0-9_]*)[\'\"]/i', $src, $matches ) ) {
				foreach ( $matches[1] as $value ) $result['capability_identifiers'][ (string) $value ] = true;
			}
			$namespace = '';
			if ( preg_match( '/namespace\s+([A-Za-z0-9_\\\\]+)\s*;/', $src, $m ) ) $namespace = trim( (string) $m[1], '\\' );
			if ( preg_match_all( '/(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/', $src, $matches ) ) {
				foreach ( $matches[1] as $class ) {
					$fqcn = '' !== $namespace ? $namespace . '\\' . $class : $class;
					if ( false !== stripos( $fqcn, 'mcp' ) || false !== stripos( $fqcn, 'feature' ) || false !== stripos( $fqcn, 'command' ) ) $result['declared_classes'][ $fqcn ] = true;
				}
			}
		}

		foreach ( array( 'matched_source_files', 'setting_identifiers', 'option_keys', 'route_candidates', 'capability_identifiers', 'declared_classes' ) as $key ) {
			$result[ $key ] = array_slice( array_values( array_keys( $result[ $key ] ) ), 0, 200 );
			sort( $result[ $key ], SORT_STRING );
		}
		return $result;
	}

	private function safe_option_key( $key ) {
		$key = (string) $key;
		if ( '' === $key || strlen( $key ) > 191 || ! preg_match( '/^[A-Za-z0-9_.:-]+$/', $key ) ) return false;
		$lower = strtolower( $key );
		if ( false === strpos( $lower, 'jet' ) && false === strpos( $lower, 'croco' ) ) return false;
		foreach ( array( 'secret', 'token', 'password', 'passwd', 'api_key', 'apikey', 'credential', 'license' ) as $sensitive ) if ( false !== strpos( $lower, $sensitive ) ) return false;
		return true;
	}

	private function resolve_settings_from_source( array $source ) {
		$matches = array();
		$setting_ids = (array) $source['setting_identifiers'];
		foreach ( array_slice( (array) $source['option_keys'], 0, 24 ) as $option_key ) {
			$sentinel = new stdClass();
			$value = get_option( $option_key, $sentinel );
			if ( $sentinel === $value ) continue;
			$this->collect_setting_matches( $value, (string) $option_key, '', $setting_ids, $matches, 0 );
		}
		$mcp_state = $this->resolve_feature_state( $matches, array( 'mcp' ) );
		$features_state = $this->resolve_feature_state( $matches, array( 'feature' ) );
		return array(
			'mcp_server_setting_discoverable' => $this->has_source_signal( $setting_ids, array( 'mcp' ) ),
			'mcp_server_enabled' => $mcp_state,
			'features_api_setting_discoverable' => $this->has_source_signal( $setting_ids, array( 'feature' ) ),
			'features_api_enabled' => $features_state,
			'command_center_discoverable' => $this->has_source_signal( $setting_ids, array( 'command' ) ),
			'matched_setting_paths' => array_slice( $matches, 0, 50 ),
			'values_redacted' => true,
		);
	}

	private function collect_setting_matches( $value, $option_key, $path, array $setting_ids, array &$matches, $depth ) {
		if ( $depth > 8 || count( $matches ) >= 100 ) return;
		if ( ! is_array( $value ) ) return;
		foreach ( $value as $key => $child ) {
			$key_string = is_scalar( $key ) ? (string) $key : '';
			$child_path = '' === $path ? $key_string : $path . '.' . $key_string;
			$lower = strtolower( $key_string );
			$relevant = false !== strpos( $lower, 'mcp' ) || false !== strpos( $lower, 'feature' ) || false !== strpos( $lower, 'command' );
			if ( ! $relevant ) {
				foreach ( $setting_ids as $setting_id ) {
					if ( '' !== $setting_id && strtolower( (string) $setting_id ) === $lower ) { $relevant = true; break; }
				}
			}
			if ( $relevant && ! is_array( $child ) && ! is_object( $child ) ) {
				$matches[] = array(
					'option_key' => $option_key,
					'path' => $child_path,
					'signal' => $key_string,
					'state' => $this->normalize_boolean_state( $child ),
					'raw_value_redacted' => true,
				);
			}
			if ( is_array( $child ) ) $this->collect_setting_matches( $child, $option_key, $child_path, $setting_ids, $matches, $depth + 1 );
		}
	}

	private function normalize_boolean_state( $value ) {
		if ( is_bool( $value ) ) return $value ? 'enabled' : 'disabled';
		if ( is_int( $value ) || is_float( $value ) ) {
			if ( 1 === (int) $value ) return 'enabled';
			if ( 0 === (int) $value ) return 'disabled';
		}
		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );
			if ( in_array( $normalized, array( '1', 'true', 'yes', 'on', 'enabled', 'enable' ), true ) ) return 'enabled';
			if ( in_array( $normalized, array( '0', 'false', 'no', 'off', 'disabled', 'disable', '' ), true ) ) return 'disabled';
		}
		return 'unknown';
	}

	private function resolve_feature_state( array $matches, array $tokens ) {
		$states = array();
		foreach ( $matches as $match ) {
			$text = strtolower( (string) $match['path'] . ' ' . (string) $match['signal'] );
			$ok = true;
			foreach ( $tokens as $token ) if ( false === strpos( $text, strtolower( (string) $token ) ) ) { $ok = false; break; }
			if ( $ok && isset( $match['state'] ) && 'unknown' !== $match['state'] ) $states[ $match['state'] ] = true;
		}
		if ( 1 === count( $states ) ) return (string) key( $states );
		return 'unknown';
	}

	private function has_source_signal( array $signals, array $tokens ) {
		foreach ( $signals as $signal ) {
			$text = strtolower( (string) $signal );
			$ok = true;
			foreach ( $tokens as $token ) if ( false === strpos( $text, strtolower( (string) $token ) ) ) { $ok = false; break; }
			if ( $ok ) return true;
		}
		return false;
	}

	private function capability_evidence( array $source ) {
		$jet_caps = array();
		$user = wp_get_current_user();
		foreach ( (array) $source['capability_identifiers'] as $cap ) $jet_caps[ (string) $cap ] = current_user_can( (string) $cap );
		if ( is_object( $user ) && isset( $user->allcaps ) && is_array( $user->allcaps ) ) {
			foreach ( $user->allcaps as $cap => $allowed ) {
				if ( ! $allowed ) continue;
				$lower = strtolower( (string) $cap );
				if ( false !== strpos( $lower, 'jet' ) || false !== strpos( $lower, 'engine' ) ) $jet_caps[ (string) $cap ] = true;
				if ( count( $jet_caps ) >= 50 ) break;
			}
		}
		ksort( $jet_caps, SORT_STRING );
		return array(
			'user_id' => get_current_user_id(),
			'can_manage_options' => current_user_can( 'manage_options' ),
			'can_edit_posts' => current_user_can( 'edit_posts' ),
			'jetengine_capabilities' => $jet_caps,
			'application_password' => 'not_required_for_internal_dispatch',
			'no_credentials_inspected' => true,
		);
	}

	private function any_loaded_component( array $loaded, array $tokens ) {
		foreach ( $loaded as $row ) {
			if ( empty( $row['loaded'] ) ) continue;
			$text = strtolower( (string) $row['class'] );
			$ok = true;
			foreach ( $tokens as $token ) if ( false === strpos( $text, strtolower( (string) $token ) ) ) { $ok = false; break; }
			if ( $ok ) return true;
		}
		return false;
	}

	private function classify_root_causes( array $plugin, array $settings, array $routes, array $transport, array $loaded ) {
		$causes = array();
		if ( ! empty( $plugin['version'] ) && version_compare( $plugin['version'], '3.8.0', '<' ) ) $causes[] = 'jetengine_version_below_mcp_minimum';
		if ( 'disabled' === $settings['mcp_server_enabled'] ) $causes[] = 'jetengine_mcp_server_disabled';
		if ( 'disabled' === $settings['features_api_enabled'] ) $causes[] = 'jetengine_features_api_disabled';
		if ( 'enabled' === $settings['mcp_server_enabled'] && 'enabled' === $settings['features_api_enabled'] && empty( $routes['mcp_related_count'] ) ) $causes[] = 'jetengine_routes_not_registered_despite_feature_enabled';
		if ( ! empty( $routes['mcp_related_count'] ) && empty( $transport['available'] ) ) $causes[] = 'jetengine_uses_different_native_route_contract';
		$mcp_loaded = $this->any_loaded_component( $loaded, array( 'mcp' ) );
		if ( 'enabled' === $settings['mcp_server_enabled'] && ! $mcp_loaded && empty( $routes['mcp_related_count'] ) ) $causes[] = 'jetengine_mcp_component_not_loaded';
		if ( empty( $causes ) ) $causes[] = 'unknown';
		return array_values( array_unique( $causes ) );
	}
}
