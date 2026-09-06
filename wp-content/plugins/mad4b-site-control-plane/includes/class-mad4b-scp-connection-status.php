<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only local truth for MAD4B MCP connection readiness.
 *
 * This service performs no outbound HTTP request, creates no credential, stores
 * no handshake result and changes no authority. A real external MCP handshake
 * remains a separate Staging certification boundary.
 */
final class MAD4B_SCP_Connection_Status {
	const CONTRACT = 'mad4b.connection-readiness.v1';

	public static function status() {
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
		$https = function_exists( 'wp_is_using_https' ) ? wp_is_using_https() : ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) );
		$adapter_available = class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
		$adapter_version = '';
		if ( defined( 'WP_MCP_VERSION' ) ) {
			$adapter_version = (string) WP_MCP_VERSION;
		} elseif ( $adapter_available && defined( 'WP\\MCP\\Core\\McpAdapter::VERSION' ) ) {
			$adapter_version = (string) \\WP\\MCP\\Core\\McpAdapter::VERSION;
		}

		$provider = class_exists( 'MAD4B_SCP_Provider_Contracts' )
			? MAD4B_SCP_Provider_Contracts::runtime_status( 'mcp_adapter', $adapter_available )
			: array( 'status' => 'unavailable', 'runtime_contract_ok' => false );
		$provider_ok = ! empty( $provider['runtime_contract_ok'] );

		$servers = self::server_status();
		$server_ok = count( $servers ) === 4;
		foreach ( $servers as $server ) {
			if ( empty( $server['registered'] ) || empty( $server['route_registered'] ) || empty( $server['permission_callback_match'] ) ) {
				$server_ok = false;
				break;
			}
		}

		$peer = class_exists( 'MAD4B_SCP_MCP_Peer_Governance' )
			? MAD4B_SCP_MCP_Peer_Governance::status()
			: array( 'inventory_ready' => false, 'write_side_channel_detected' => false, 'blockers' => array( 'mcp_peer_inventory_unavailable' ) );

		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : new WP_Error( 'mad4b_identity_context_unavailable', 'Identity context is unavailable.' );
		$identity_status = self::identity_status( $identity );

		$local_blockers = array();
		if ( ! $adapter_available ) $local_blockers[] = 'mcp_adapter_unavailable';
		if ( $adapter_available && ! $provider_ok ) $local_blockers[] = 'mcp_adapter_not_certified';
		if ( ! $server_ok ) $local_blockers[] = 'mad4b_transport_registration_incomplete';
		if ( empty( $peer['inventory_ready'] ) ) $local_blockers[] = 'mcp_peer_inventory_unavailable';
		if ( ! empty( $peer['write_side_channel_detected'] ) ) $local_blockers[] = 'mcp_write_side_channel_detected';
		if ( ! empty( $peer['blockers'] ) && is_array( $peer['blockers'] ) ) $local_blockers = array_merge( $local_blockers, $peer['blockers'] );
		$local_blockers = array_values( array_unique( array_map( 'sanitize_key', $local_blockers ) ) );

		$remote_preflight_blockers = $local_blockers;
		if ( ! $https ) $remote_preflight_blockers[] = 'https_required_for_remote_mcp';
		$remote_preflight_blockers = array_values( array_unique( $remote_preflight_blockers ) );

		$certification_blockers = $remote_preflight_blockers;
		$certification_blockers[] = 'external_handshake_unverified';
		$certification_blockers = array_values( array_unique( $certification_blockers ) );

		return array(
			'contract' => self::CONTRACT,
			'environment' => sanitize_key( (string) $environment ),
			'environment_is_staging' => 'staging' === sanitize_key( (string) $environment ),
			'site_url' => esc_url_raw( site_url() ),
			'home_url' => esc_url_raw( home_url() ),
			'rest_url' => esc_url_raw( rest_url() ),
			'https' => (bool) $https,
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '',
			'mcp_adapter_available' => (bool) $adapter_available,
			'mcp_adapter_version' => $adapter_version,
			'mcp_adapter_certification' => $provider,
			'mcp_adapter_certified' => (bool) $provider_ok,
			'local_transport_ready' => empty( $local_blockers ),
			'local_blockers' => $local_blockers,
			'remote_endpoint_preflight_ready' => empty( $remote_preflight_blockers ),
			'remote_preflight_blockers' => $remote_preflight_blockers,
			'connection_certified' => false,
			'certification_blockers' => $certification_blockers,
			'servers' => $servers,
			'authentication' => array(
				'transport_model' => 'wordpress-authenticated-request-plus-mad4b-server-permission',
				'credential_material_exposed' => false,
				'credential_creation_supported_here' => false,
				'current_request_subject' => $identity_status,
				'remote_subject_bridge_required' => true,
			),
			'external_handshake' => array(
				'verified' => false,
				'status' => 'requires_real_remote_mcp_session',
				'note' => 'Local readiness never certifies Internet reachability, OAuth/Application Password behavior, MCP session establishment, or the remote subject bridge.',
			),
			'mcp_peer_governance' => self::bounded_peer_summary( $peer ),
			'breakglass' => array(
				'configured_enabled' => defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) && true === constant( 'MAD4B_MCP_BREAKGLASS_ENABLED' ),
				'effective_for_current_request' => class_exists( 'MAD4B_SCP_Policy' ) ? (bool) MAD4B_SCP_Policy::can_breakglass() : false,
			),
		);
	}

	private static function server_status() {
		$ids = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::expected_server_ids() : array( 'mad4b-read', 'mad4b-content', 'mad4b-admin', 'mad4b-breakglass' );
		$expected_permissions = array(
			'mad4b-read' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'mad4b-content' => array( 'MAD4B_SCP_Policy', 'can_content' ),
			'mad4b-admin' => array( 'MAD4B_SCP_Policy', 'can_admin' ),
			'mad4b-breakglass' => array( 'MAD4B_SCP_Policy', 'can_breakglass' ),
		);
		$registration = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::registration_status() : array();
		$adapter_servers = array();
		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			try {
				$adapter = \\WP\\MCP\\Core\\McpAdapter::instance();
				if ( is_object( $adapter ) && method_exists( $adapter, 'get_servers' ) ) {
					$found = $adapter->get_servers();
					if ( is_array( $found ) ) {
						foreach ( $found as $server ) {
							if ( is_object( $server ) && method_exists( $server, 'get_server_id' ) ) $adapter_servers[ (string) $server->get_server_id() ] = $server;
						}
					}
				}
			} catch ( Throwable $e ) {
				$adapter_servers = array();
			}
		}

		$routes = array();
		try {
			$rest = function_exists( 'rest_get_server' ) ? rest_get_server() : null;
			$routes = is_object( $rest ) && method_exists( $rest, 'get_routes' ) ? $rest->get_routes() : array();
		} catch ( Throwable $e ) {
			$routes = array();
		}
		if ( ! is_array( $routes ) ) $routes = array();

		$out = array();
		foreach ( $ids as $id ) {
			$server = isset( $adapter_servers[ $id ] ) ? $adapter_servers[ $id ] : null;
			$namespace = 'mcp';
			$route = '/' . $id;
			$permission = null;
			$server_version = '';
			if ( is_object( $server ) ) {
				try {
					if ( method_exists( $server, 'get_server_route_namespace' ) ) $namespace = trim( (string) $server->get_server_route_namespace(), '/' );
					if ( method_exists( $server, 'get_server_route' ) ) $route = '/' . ltrim( (string) $server->get_server_route(), '/' );
					if ( method_exists( $server, 'get_transport_permission_callback' ) ) $permission = $server->get_transport_permission_callback();
					if ( method_exists( $server, 'get_server_version' ) ) $server_version = (string) $server->get_server_version();
				} catch ( Throwable $e ) {
					$permission = null;
				}
			}
			$full_route = '/' . trim( $namespace, '/' ) . $route;
			$expected_permission = isset( $expected_permissions[ $id ] ) ? $expected_permissions[ $id ] : array();
			$out[] = array(
				'server_id' => $id,
				'registered' => ! empty( $registration[ $id ]['registered'] ) && is_object( $server ),
				'registration_error' => isset( $registration[ $id ]['error'] ) ? sanitize_key( (string) $registration[ $id ]['error'] ) : '',
				'route_namespace' => $namespace,
				'route' => $route,
				'endpoint' => esc_url_raw( rest_url( trim( $namespace, '/' ) . $route ) ),
				'route_registered' => array_key_exists( $full_route, $routes ),
				'permission_callback' => self::callback_label( $permission ),
				'permission_callback_match' => self::callbacks_equal( $permission, $expected_permission ),
				'server_version' => $server_version,
				'surface' => self::surface_label( $id ),
			);
		}
		return $out;
	}

	private static function identity_status( $identity ) {
		if ( is_wp_error( $identity ) ) {
			return array( 'valid' => false, 'authenticated' => false, 'subject_type' => '', 'auth_method' => '', 'wp_user_id' => get_current_user_id(), 'error' => sanitize_key( $identity->get_error_code() ) );
		}
		return array(
			'valid' => is_array( $identity ),
			'authenticated' => ! empty( $identity['authenticated'] ),
			'subject_type' => isset( $identity['subject_type'] ) ? sanitize_key( (string) $identity['subject_type'] ) : '',
			'auth_method' => isset( $identity['auth_method'] ) ? sanitize_key( (string) $identity['auth_method'] ) : '',
			'wp_user_id' => isset( $identity['wp_user_id'] ) ? absint( $identity['wp_user_id'] ) : get_current_user_id(),
			'subject_fingerprint_present' => ! empty( $identity['subject_fingerprint'] ),
			'token_scope_count' => isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? count( $identity['token_scopes'] ) : 0,
			'error' => '',
		);
	}

	private static function bounded_peer_summary( $peer ) {
		if ( ! is_array( $peer ) ) return array( 'inventory_ready' => false, 'blockers' => array( 'mcp_peer_inventory_unavailable' ) );
		$foreign = isset( $peer['foreign_transport_inventory'] ) && is_array( $peer['foreign_transport_inventory'] ) ? $peer['foreign_transport_inventory'] : array();
		$peers = array();
		foreach ( isset( $peer['peers'] ) && is_array( $peer['peers'] ) ? array_slice( $peer['peers'], 0, 100 ) : array() as $item ) {
			$reasons = array();
			foreach ( isset( $item['risks'] ) && is_array( $item['risks'] ) ? array_slice( $item['risks'], 0, 20 ) : array() as $risk ) if ( is_array( $risk ) && isset( $risk['reason'] ) ) $reasons[] = sanitize_key( (string) $risk['reason'] );
			$peers[] = array(
				'server_id' => isset( $item['server_id'] ) ? sanitize_text_field( (string) $item['server_id'] ) : '',
				'governed' => ! empty( $item['governed'] ),
				'tool_count' => isset( $item['tool_count'] ) ? (int) $item['tool_count'] : 0,
				'risk_count' => isset( $item['risk_count'] ) ? (int) $item['risk_count'] : 0,
				'risk_reasons' => array_values( array_unique( $reasons ) ),
			);
		}
		return array(
			'inventory_ready' => ! empty( $peer['inventory_ready'] ),
			'write_side_channel_detected' => ! empty( $peer['write_side_channel_detected'] ),
			'server_count' => isset( $peer['server_count'] ) ? (int) $peer['server_count'] : 0,
			'external_peer_count' => isset( $peer['external_peer_count'] ) ? (int) $peer['external_peer_count'] : 0,
			'risk_count' => isset( $peer['risk_count'] ) ? (int) $peer['risk_count'] : 0,
			'blockers' => isset( $peer['blockers'] ) && is_array( $peer['blockers'] ) ? array_values( array_map( 'sanitize_key', $peer['blockers'] ) ) : array(),
			'peers' => $peers,
			'foreign_transport' => array(
				'inventory_ready' => ! empty( $foreign['inventory_ready'] ),
				'detected' => ! empty( $foreign['foreign_mcp_detected'] ),
				'route_count' => isset( $foreign['foreign_route_count'] ) ? (int) $foreign['foreign_route_count'] : 0,
				'routes' => isset( $foreign['foreign_routes'] ) && is_array( $foreign['foreign_routes'] ) ? array_slice( array_map( 'sanitize_text_field', $foreign['foreign_routes'] ), 0, 100 ) : array(),
				'plugin_count' => isset( $foreign['foreign_plugin_count'] ) ? (int) $foreign['foreign_plugin_count'] : 0,
				'plugins' => isset( $foreign['foreign_plugins'] ) && is_array( $foreign['foreign_plugins'] ) ? array_slice( array_map( 'sanitize_text_field', $foreign['foreign_plugins'] ), 0, 100 ) : array(),
			),
		);
	}

	private static function callback_label( $callback ) {
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$left = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return sanitize_text_field( $left . '::' . (string) $callback[1] );
		}
		if ( is_string( $callback ) ) return sanitize_text_field( $callback );
		if ( null === $callback ) return '';
		return 'callable';
	}

	private static function callbacks_equal( $actual, array $expected ) {
		if ( ! is_array( $actual ) || 2 !== count( $actual ) || 2 !== count( $expected ) ) return false;
		$actual_class = is_object( $actual[0] ) ? get_class( $actual[0] ) : (string) $actual[0];
		return $actual_class === (string) $expected[0] && (string) $actual[1] === (string) $expected[1];
	}

	private static function surface_label( $id ) {
		if ( 'mad4b-read' === $id ) return 'read';
		if ( 'mad4b-content' === $id ) return 'content';
		if ( 'mad4b-admin' === $id ) return 'admin';
		if ( 'mad4b-breakglass' === $id ) return 'breakglass';
		return 'unknown';
	}
}
