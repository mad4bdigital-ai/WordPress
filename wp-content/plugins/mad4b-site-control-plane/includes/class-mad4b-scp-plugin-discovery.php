<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only discovery of installed plugins and their MAD4B adapter coverage.
 *
 * Discovery never grants mutation authority, installs plugins or creates code. Unknown
 * plugins produce deterministic adapter-support requests and remain fail-closed for
 * plugin-specific writes until an explicit adapter contract is registered/certified.
 */
final class MAD4B_SCP_Plugin_Discovery {
	const CONTRACT = 'mad4b.plugin-adapter-discovery.v1';
	const MAX_PLUGINS = 500;

	private static $catalog = null;

	public static function catalog() {
		if ( null !== self::$catalog ) return self::$catalog;
		$path = MAD4B_SCP_DIR . 'config/adapter-support-catalog.json';
		$data = array();
		if ( is_readable( $path ) ) {
			$raw = file_get_contents( $path );
			$decoded = false === $raw ? null : json_decode( $raw, true );
			if ( is_array( $decoded ) ) $data = $decoded;
		}
		$data = apply_filters( 'mad4b_scp_plugin_adapter_discovery_catalog', $data );
		self::$catalog = is_array( $data ) ? $data : array();
		return self::$catalog;
	}

	public static function coverage() {
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		if ( ! is_array( $plugins ) ) $plugins = array();
		ksort( $plugins, SORT_STRING );
		$items = array();
		$requests = array();
		$counts = array(
			'installed' => 0,
			'active' => 0,
			'supported_reversible' => 0,
			'supported_governed' => 0,
			'adapter_required' => 0,
			'excluded_high_risk' => 0,
			'priority_external_missing' => 0,
		);

		foreach ( $plugins as $plugin_file => $headers ) {
			if ( count( $items ) >= self::MAX_PLUGINS ) break;
			$item = self::describe_installed_plugin( (string) $plugin_file, is_array( $headers ) ? $headers : array() );
			$items[] = $item;
			++$counts['installed'];
			if ( ! empty( $item['active'] ) ) ++$counts['active'];
			if ( isset( $counts[ $item['coverage_state'] ] ) ) ++$counts[ $item['coverage_state'] ];
			if ( ! empty( $item['support_request'] ) ) $requests[] = $item['support_request'];
		}

		$priority = self::priority_external_items( $plugins );
		foreach ( $priority as $item ) {
			if ( 'priority_external_missing' === $item['coverage_state'] ) ++$counts['priority_external_missing'];
			if ( ! empty( $item['support_request'] ) ) $requests[] = $item['support_request'];
		}

		return array(
			'contract' => self::CONTRACT,
			'discovery_only' => true,
			'auto_install' => false,
			'auto_generate_adapter' => false,
			'unknown_plugin_write_default' => 'deny',
			'plugins' => $items,
			'priority_external' => $priority,
			'support_requests' => self::dedupe_requests( $requests ),
			'counts' => $counts,
			'truncated' => count( $plugins ) > self::MAX_PLUGINS,
		);
	}

	public static function support_requests() {
		$coverage = self::coverage();
		return array(
			'contract' => 'mad4b.adapter-support-requests.v1',
			'discovery_only' => true,
			'network_request_sent' => false,
			'authority_created' => false,
			'requests' => isset( $coverage['support_requests'] ) ? $coverage['support_requests'] : array(),
			'count' => isset( $coverage['support_requests'] ) ? count( $coverage['support_requests'] ) : 0,
		);
	}

	private static function describe_installed_plugin( $plugin_file, array $headers ) {
		$plugin_file = self::normalize_plugin_file( $plugin_file );
		$descriptor = self::descriptor_for( $plugin_file );
		$adapter_id = isset( $descriptor['adapter_id'] ) ? sanitize_key( (string) $descriptor['adapter_id'] ) : '';
		$adapter = '' !== $adapter_id && class_exists( 'MAD4B_SCP_Adapter_Registry' ) ? MAD4B_SCP_Adapter_Registry::instance()->get( $adapter_id ) : null;
		$active = self::is_active( $plugin_file );
		$network_active = self::is_network_active( $plugin_file );
		$strategy = isset( $descriptor['strategy'] ) ? sanitize_key( (string) $descriptor['strategy'] ) : 'adapter_required';
		$risk = isset( $descriptor['risk'] ) ? sanitize_key( (string) $descriptor['risk'] ) : 'unknown';
		$state = self::coverage_state( $strategy, $adapter, $active );
		$reversible = self::adapter_reversible_contracts( $adapter );
		$request = self::support_request_for(
			$plugin_file,
			isset( $headers['Name'] ) ? (string) $headers['Name'] : self::plugin_slug( $plugin_file ),
			isset( $headers['Version'] ) ? (string) $headers['Version'] : '',
			$descriptor,
			$state,
			$active
		);

		return array(
			'plugin_file' => $plugin_file,
			'slug' => self::plugin_slug( $plugin_file ),
			'name' => isset( $headers['Name'] ) ? sanitize_text_field( (string) $headers['Name'] ) : '',
			'version' => isset( $headers['Version'] ) ? sanitize_text_field( (string) $headers['Version'] ) : '',
			'active' => $active,
			'network_active' => $network_active,
			'family' => isset( $descriptor['id'] ) ? sanitize_key( (string) $descriptor['id'] ) : 'unknown',
			'adapter_id' => $adapter_id,
			'adapter_registered' => is_object( $adapter ),
			'adapter_runtime_available' => is_object( $adapter ) ? (bool) $adapter->is_available() : false,
			'coverage_state' => $state,
			'risk' => $risk,
			'reversible_contracts' => $reversible,
			'mutation_auto_enabled' => false,
			'support_request' => $request,
		);
	}

	private static function priority_external_items( array $installed ) {
		$catalog = self::catalog();
		$priority = isset( $catalog['priority_external'] ) && is_array( $catalog['priority_external'] ) ? $catalog['priority_external'] : array();
		$items = array();
		foreach ( $priority as $descriptor ) {
			if ( ! is_array( $descriptor ) ) continue;
			$files = isset( $descriptor['plugin_files'] ) && is_array( $descriptor['plugin_files'] ) ? $descriptor['plugin_files'] : array();
			$found = '';
			foreach ( $files as $file ) {
				$file = self::normalize_plugin_file( $file );
				if ( isset( $installed[ $file ] ) ) { $found = $file; break; }
			}
			if ( '' !== $found ) continue;
			$adapter_id = isset( $descriptor['adapter_id'] ) ? sanitize_key( (string) $descriptor['adapter_id'] ) : '';
			$adapter = '' !== $adapter_id && class_exists( 'MAD4B_SCP_Adapter_Registry' ) ? MAD4B_SCP_Adapter_Registry::instance()->get( $adapter_id ) : null;
			$name = isset( $descriptor['label'] ) ? sanitize_text_field( (string) $descriptor['label'] ) : ( isset( $descriptor['id'] ) ? sanitize_text_field( (string) $descriptor['id'] ) : '' );
			$request = self::support_request_for( isset( $files[0] ) ? self::normalize_plugin_file( $files[0] ) : '', $name, '', $descriptor, 'priority_external_missing', false );
			$items[] = array(
				'id' => isset( $descriptor['id'] ) ? sanitize_key( (string) $descriptor['id'] ) : '',
				'name' => $name,
				'installed' => false,
				'active' => false,
				'adapter_id' => $adapter_id,
				'adapter_registered' => is_object( $adapter ),
				'coverage_state' => 'priority_external_missing',
				'risk' => isset( $descriptor['risk'] ) ? sanitize_key( (string) $descriptor['risk'] ) : 'unknown',
				'reversible_contracts' => self::adapter_reversible_contracts( $adapter ),
				'support_request' => $request,
			);
		}
		return $items;
	}

	private static function descriptor_for( $plugin_file ) {
		$catalog = self::catalog();
		$families = isset( $catalog['families'] ) && is_array( $catalog['families'] ) ? $catalog['families'] : array();
		foreach ( $families as $descriptor ) {
			if ( ! is_array( $descriptor ) || empty( $descriptor['match'] ) || ! is_array( $descriptor['match'] ) ) continue;
			foreach ( $descriptor['match'] as $prefix ) {
				$prefix = self::normalize_plugin_file( $prefix );
				if ( '' !== $prefix && 0 === strpos( $plugin_file, $prefix ) ) return $descriptor;
			}
		}
		$default = isset( $catalog['default'] ) && is_array( $catalog['default'] ) ? $catalog['default'] : array();
		$default['id'] = 'unknown';
		$default['adapter_id'] = '';
		return $default;
	}

	private static function coverage_state( $strategy, $adapter, $active ) {
		if ( 'platform' === $strategy ) return 'supported_governed';
		if ( 'excluded_high_risk' === $strategy ) return 'excluded_high_risk';
		if ( ! is_object( $adapter ) ) return 'adapter_required';
		$contracts = self::adapter_reversible_contracts( $adapter );
		if ( ! empty( $contracts ) ) return $active && $adapter->is_available() ? 'supported_reversible' : 'supported_governed';
		$map = method_exists( $adapter, 'ability_names' ) ? $adapter->ability_names() : array();
		$writes = array_merge( isset( $map['content'] ) && is_array( $map['content'] ) ? $map['content'] : array(), isset( $map['admin'] ) && is_array( $map['admin'] ) ? $map['admin'] : array() );
		return ! empty( $writes ) ? 'supported_governed' : 'read_only_supported';
	}

	private static function adapter_reversible_contracts( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'reversible_contracts' ) ) return array();
		$contracts = $adapter->reversible_contracts();
		if ( ! is_array( $contracts ) ) return array();
		$out = array();
		foreach ( $contracts as $ability => $contract ) {
			$ability = (string) $ability;
			$contract = sanitize_key( str_replace( '.', '-', (string) $contract ) );
			if ( '' !== $ability && '' !== $contract ) $out[ $ability ] = $contract;
		}
		return $out;
	}

	private static function support_request_for( $plugin_file, $name, $version, array $descriptor, $state, $active ) {
		if ( ! in_array( $state, array( 'adapter_required', 'supported_governed', 'excluded_high_risk', 'priority_external_missing' ), true ) ) return null;
		$reason = 'adapter_required' === $state ? 'no_registered_adapter' : ( 'supported_governed' === $state ? 'reversible_certification_incomplete' : ( 'excluded_high_risk' === $state ? 'normal_writer_excluded_by_risk' : 'priority_external_not_installed' ) );
		$requested = isset( $descriptor['requested_contracts'] ) && is_array( $descriptor['requested_contracts'] ) ? array_values( array_map( 'sanitize_key', $descriptor['requested_contracts'] ) ) : array( 'read', 'bounded_write', 'reversible_restore' );
		$seed = array( 'plugin_file' => $plugin_file, 'version' => (string) $version, 'reason' => $reason, 'contracts' => $requested );
		return array(
			'support_request_id' => 'asr-' . substr( hash( 'sha256', wp_json_encode( $seed ) ), 0, 24 ),
			'plugin_file' => $plugin_file,
			'plugin_name' => sanitize_text_field( (string) $name ),
			'plugin_version' => sanitize_text_field( (string) $version ),
			'active' => (bool) $active,
			'family' => isset( $descriptor['id'] ) ? sanitize_key( (string) $descriptor['id'] ) : 'unknown',
			'adapter_id' => isset( $descriptor['adapter_id'] ) ? sanitize_key( (string) $descriptor['adapter_id'] ) : '',
			'reason_code' => $reason,
			'risk' => isset( $descriptor['risk'] ) ? sanitize_key( (string) $descriptor['risk'] ) : 'unknown',
			'requested_contracts' => $requested,
			'auto_create_authority' => false,
			'auto_install' => false,
			'normal_write_allowed' => false,
		);
	}

	private static function dedupe_requests( array $requests ) {
		$out = array();
		foreach ( $requests as $request ) {
			if ( ! is_array( $request ) || empty( $request['support_request_id'] ) ) continue;
			$out[ $request['support_request_id'] ] = $request;
		}
		ksort( $out, SORT_STRING );
		return array_values( $out );
	}

	private static function normalize_plugin_file( $plugin_file ) {
		return ltrim( str_replace( '\\', '/', sanitize_text_field( (string) $plugin_file ) ), '/' );
	}

	private static function plugin_slug( $plugin_file ) {
		$plugin_file = self::normalize_plugin_file( $plugin_file );
		$parts = explode( '/', $plugin_file );
		return sanitize_key( isset( $parts[0] ) ? $parts[0] : $plugin_file );
	}

	private static function is_active( $plugin_file ) {
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) ) return true;
		return self::is_network_active( $plugin_file );
	}

	private static function is_network_active( $plugin_file ) {
		return function_exists( 'is_plugin_active_for_network' ) && is_multisite() && is_plugin_active_for_network( $plugin_file );
	}
}
