<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Declarative provider transport inventory used by isolation/governance layers.
 * Unknown transports are intentionally not hidden or auto-allowed.
 */
final class MAD4B_SCP_Provider_Transport_Registry {
	const CONTRACT = 'mad4b.provider-transport-registry.v1';
	const CATALOG_FILE = 'config/provider-transport-registry.json';
	private static $catalog = null;
	private static $validation_errors = array();

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 33 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/provider-transport-registry-status' ) ) ) return;
		wp_register_ability( 'mad4b/provider-transport-registry-status', array(
			'label' => 'Provider Transport Registry Status',
			'description' => 'Inspect declarative provider transport isolation descriptors. Unknown transports remain visible to peer governance.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'status' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	public static function catalog() {
		if ( null !== self::$catalog ) return self::$catalog;
		self::$validation_errors = array();
		$path = MAD4B_SCP_DIR . self::CATALOG_FILE;
		if ( ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			self::$validation_errors[] = 'catalog_missing_or_unreadable';
			return self::$catalog = array();
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || self::CONTRACT !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' ) ) {
			self::$validation_errors[] = 'catalog_contract_invalid';
			return self::$catalog = array();
		}
		if ( 1 !== ( isset( $data['version'] ) ? (int) $data['version'] : 0 ) ) self::$validation_errors[] = 'catalog_version_invalid';
		if ( 'visible_to_peer_governance' !== ( isset( $data['default_unknown_transport'] ) ? (string) $data['default_unknown_transport'] : '' ) ) self::$validation_errors[] = 'unknown_transport_default_invalid';
		if ( ! isset( $data['descriptors'] ) || ! is_array( $data['descriptors'] ) ) self::$validation_errors[] = 'descriptors_missing';

		$providers = array();
		foreach ( isset( $data['descriptors'] ) && is_array( $data['descriptors'] ) ? $data['descriptors'] : array() as $index => $descriptor ) {
			if ( ! is_array( $descriptor ) ) { self::$validation_errors[] = 'descriptor_invalid:' . (int) $index; continue; }
			$provider = isset( $descriptor['provider_id'] ) ? (string) $descriptor['provider_id'] : '';
			if ( '' === $provider || sanitize_key( $provider ) !== $provider ) self::$validation_errors[] = 'provider_id_invalid:' . (int) $index;
			elseif ( isset( $providers[ $provider ] ) ) self::$validation_errors[] = 'provider_id_duplicate:' . $provider;
			else $providers[ $provider ] = true;

			$visibility = isset( $descriptor['external_visibility'] ) ? (string) $descriptor['external_visibility'] : '';
			if ( 'suppress_when_isolation_effective' !== $visibility ) self::$validation_errors[] = 'external_visibility_invalid:' . $provider;
			$kind = isset( $descriptor['kind'] ) ? sanitize_key( (string) $descriptor['kind'] ) : '';
			if ( ! in_array( $kind, array( 'rest', 'mcp_server' ), true ) ) self::$validation_errors[] = 'transport_kind_invalid:' . $provider;
			if ( ! array_key_exists( 'internal_retention', $descriptor ) || ! is_bool( $descriptor['internal_retention'] ) ) self::$validation_errors[] = 'internal_retention_invalid:' . $provider;

			foreach ( isset( $descriptor['routes'] ) && is_array( $descriptor['routes'] ) ? $descriptor['routes'] : array() as $route_index => $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['pattern'] ) ) { self::$validation_errors[] = 'route_rule_invalid:' . $provider . ':' . (int) $route_index; continue; }
				$regex = '#'. str_replace( '#', '\\#', (string) $rule['pattern'] ) .'#';
				if ( false === @preg_match( $regex, '' ) ) self::$validation_errors[] = 'route_pattern_invalid:' . $provider . ':' . (int) $route_index;
				$methods = isset( $rule['methods'] ) && is_array( $rule['methods'] ) ? array_values( array_unique( array_map( 'strtoupper', $rule['methods'] ) ) ) : array();
				if ( empty( $methods ) || array_diff( $methods, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS' ) ) ) self::$validation_errors[] = 'route_methods_invalid:' . $provider . ':' . (int) $route_index;
				$class = isset( $rule['class'] ) ? sanitize_key( (string) $rule['class'] ) : '';
				if ( ! in_array( $class, array( 'mcp_transport', 'mcp_credential_control', 'mcp_control_surface', 'mcp_execution_surface' ), true ) ) self::$validation_errors[] = 'route_class_invalid:' . $provider . ':' . (int) $route_index;
				if ( ! empty( $descriptor['internal_retention'] ) && 'mcp_execution_surface' === $class ) {
					$purpose = isset( $rule['purpose'] ) ? sanitize_key( (string) $rule['purpose'] ) : '';
					if ( ! in_array( $purpose, array( 'registry', 'execute' ), true ) ) self::$validation_errors[] = 'internal_route_purpose_invalid:' . $provider . ':' . (int) $route_index;
				}
			}

			foreach ( isset( $descriptor['server_callbacks'] ) && is_array( $descriptor['server_callbacks'] ) ? $descriptor['server_callbacks'] : array() as $callback_index => $row ) {
				if ( ! is_array( $row ) || empty( $row['server_id'] ) || empty( $row['callback_class'] ) || empty( $row['callback_method'] ) ) {
					self::$validation_errors[] = 'server_callback_invalid:' . $provider . ':' . (int) $callback_index;
					continue;
				}
				if ( ! preg_match( '/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', (string) $row['callback_class'] ) ) self::$validation_errors[] = 'callback_class_invalid:' . $provider . ':' . (int) $callback_index;
				if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $row['callback_method'] ) ) self::$validation_errors[] = 'callback_method_invalid:' . $provider . ':' . (int) $callback_index;
			}
		}
		self::$validation_errors = array_values( array_unique( self::$validation_errors ) );
		if ( ! empty( self::$validation_errors ) ) return self::$catalog = array();
		return self::$catalog = $data;
	}

	public static function descriptors( $provider_id = '' ) {
		$provider_id = sanitize_key( (string) $provider_id );
		$catalog = self::catalog();
		$out = array();
		foreach ( isset( $catalog['descriptors'] ) && is_array( $catalog['descriptors'] ) ? $catalog['descriptors'] : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['provider_id'] ) ) continue;
			if ( '' !== $provider_id && $provider_id !== sanitize_key( (string) $row['provider_id'] ) ) continue;
			$out[] = $row;
		}
		return $out;
	}

	public static function match_route( $route ) {
		$route = '/' . ltrim( (string) $route, '/' );
		foreach ( self::descriptors() as $descriptor ) {
			if ( 'suppress_when_isolation_effective' !== ( isset( $descriptor['external_visibility'] ) ? (string) $descriptor['external_visibility'] : '' ) ) continue;
			foreach ( isset( $descriptor['routes'] ) && is_array( $descriptor['routes'] ) ? $descriptor['routes'] : array() as $rule ) {
				if ( empty( $rule['pattern'] ) || false === @preg_match( '#' . str_replace( '#', '\\#', (string) $rule['pattern'] ) . '#', $route ) ) continue;
				if ( 1 === @preg_match( '#' . str_replace( '#', '\\#', (string) $rule['pattern'] ) . '#', $route ) ) return array(
					'provider_id' => sanitize_key( (string) $descriptor['provider_id'] ),
					'descriptor' => $descriptor,
					'rule' => $rule,
				);
			}
		}
		return null;
	}

	public static function route_descriptors() {
		$out = array();
		foreach ( self::descriptors() as $descriptor ) {
			foreach ( isset( $descriptor['routes'] ) && is_array( $descriptor['routes'] ) ? $descriptor['routes'] : array() as $rule ) {
				if ( empty( $rule['pattern'] ) ) continue;
				$out[] = array(
					'provider' => sanitize_key( (string) $descriptor['provider_id'] ),
					'pattern' => '#'. str_replace( '#', '\\#', (string) $rule['pattern'] ) .'#',
					'class' => isset( $rule['class'] ) ? sanitize_key( (string) $rule['class'] ) : 'mcp_transport',
					'internal_retention' => ! empty( $descriptor['internal_retention'] ),
					'methods' => isset( $rule['methods'] ) && is_array( $rule['methods'] ) ? array_values( array_map( 'strtoupper', $rule['methods'] ) ) : array(),
					'purpose' => isset( $rule['purpose'] ) ? sanitize_key( (string) $rule['purpose'] ) : '',
				);
			}
		}
		return $out;
	}

	public static function server_callback_descriptors() {
		$out = array();
		foreach ( self::descriptors() as $descriptor ) {
			if ( 'suppress_when_isolation_effective' !== ( isset( $descriptor['external_visibility'] ) ? (string) $descriptor['external_visibility'] : '' ) ) continue;
			foreach ( isset( $descriptor['server_callbacks'] ) && is_array( $descriptor['server_callbacks'] ) ? $descriptor['server_callbacks'] : array() as $row ) {
				if ( empty( $row['server_id'] ) || empty( $row['callback_class'] ) || empty( $row['callback_method'] ) ) continue;
				$out[] = array(
					'provider' => sanitize_key( (string) $descriptor['provider_id'] ),
					'server_id' => sanitize_text_field( (string) $row['server_id'] ),
					'callback_class' => (string) $row['callback_class'],
					'callback_method' => (string) $row['callback_method'],
				);
			}
		}
		return $out;
	}

	public static function status() {
		$catalog = self::catalog();
		return array(
			'contract' => self::CONTRACT,
			'ready' => ! empty( $catalog ) && empty( self::$validation_errors ),
			'state' => ! empty( $catalog ) && empty( self::$validation_errors ) ? 'ready' : 'catalog_invalid',
			'validation_errors' => self::$validation_errors,
			'default_unknown_transport' => 'visible_to_peer_governance',
			'unknown_transport_auto_allowed' => false,
			'descriptors' => self::descriptors(),
			'mutation_performed' => false,
			'authority_created' => false,
		);
	}
}
