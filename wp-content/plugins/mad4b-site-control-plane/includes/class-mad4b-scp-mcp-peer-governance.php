<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_MCP_Peer_Governance {
	const CONTRACT = 'mad4b.mcp-peer-governance.v1';
	const MAX_SERVERS = 100;
	const MAX_TOOLS_PER_SERVER = 500;
	const MAX_RISK_DETAILS = 100;
	const GENERIC_EXECUTE_ABILITY = 'mcp-adapter/execute-ability';

	public static function mutation_guard() {
		$status = self::status();
		if ( empty( $status['inventory_ready'] ) ) {
			return new WP_Error( 'mcp_peer_inventory_unavailable', 'MCP peer inventory is unavailable; governed mutation fails closed.' );
		}
		if ( ! empty( $status['write_side_channel_detected'] ) ) {
			return new WP_Error(
				'mcp_write_side_channel_detected',
				'An MCP write-capable peer exists outside the MAD4B governed servers.',
				array(
					'external_peer_count' => isset( $status['external_peer_count'] ) ? (int) $status['external_peer_count'] : 0,
					'risk_count' => isset( $status['risk_count'] ) ? (int) $status['risk_count'] : 0,
				)
			);
		}
		return true;
	}

	public static function status() {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return self::unavailable( 'mcp_adapter_unavailable' );
		}
		if ( ! class_exists( '\WP\MCP\Abilities\McpAbilityExposure' ) ) {
			return self::unavailable( 'mcp_exposure_resolver_unavailable' );
		}
		try {
			$adapter = \WP\MCP\Core\McpAdapter::instance();
			if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'get_servers' ) ) {
				return self::unavailable( 'mcp_server_registry_unavailable' );
			}
			$servers = $adapter->get_servers();
		} catch ( Throwable $e ) {
			return self::unavailable( 'mcp_server_registry_exception' );
		}
		if ( ! is_array( $servers ) ) {
			return self::unavailable( 'mcp_server_registry_invalid' );
		}
		return self::analyze_servers( $servers );
	}

	public static function analyze_servers( array $servers ) {
		if ( count( $servers ) > self::MAX_SERVERS ) {
			return self::unavailable( 'mcp_server_inventory_overflow' );
		}

		$expected = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::expected_server_ids() : array();
		$public_write_abilities = self::public_write_abilities();
		if ( is_wp_error( $public_write_abilities ) ) {
			return self::unavailable( $public_write_abilities->get_error_code() );
		}

		$peers = array();
		$external_peer_count = 0;
		$risk_count = 0;
		foreach ( $servers as $server ) {
			$peer = self::inspect_server( $server, $expected, $public_write_abilities );
			$peers[] = $peer;
			if ( empty( $peer['governed'] ) ) ++$external_peer_count;
			$risk_count += isset( $peer['risk_count'] ) ? (int) $peer['risk_count'] : 0;
		}

		$detected = $risk_count > 0;
		return array(
			'contract' => self::CONTRACT,
			'inventory_ready' => true,
			'adapter_version' => defined( 'WP_MCP_VERSION' ) ? (string) WP_MCP_VERSION : (string) \WP\MCP\Core\McpAdapter::VERSION,
			'server_count' => count( $peers ),
			'external_peer_count' => $external_peer_count,
			'public_write_ability_count' => count( $public_write_abilities ),
			'write_side_channel_detected' => $detected,
			'risk_count' => $risk_count,
			'blockers' => $detected ? array( 'mcp_write_side_channel_detected' ) : array(),
			'peers' => $peers,
		);
	}

	private static function inspect_server( $server, array $expected, array $public_write_abilities ) {
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) || ! method_exists( $server, 'get_tools' ) || ! method_exists( $server, 'get_mcp_tool' ) ) {
			return array(
				'server_id' => 'uninspectable',
				'governed' => false,
				'tool_count' => 0,
				'risk_count' => 1,
				'risks' => array( array( 'reason' => 'uninspectable_mcp_server' ) ),
			);
		}

		try {
			$server_id = (string) $server->get_server_id();
			$tools = $server->get_tools();
		} catch ( Throwable $e ) {
			return array(
				'server_id' => 'uninspectable',
				'governed' => false,
				'tool_count' => 0,
				'risk_count' => 1,
				'risks' => array( array( 'reason' => 'mcp_server_inspection_exception' ) ),
			);
		}

		$governed = in_array( $server_id, $expected, true );
		if ( ! is_array( $tools ) ) {
			return array(
				'server_id' => $server_id,
				'governed' => $governed,
				'tool_count' => 0,
				'risk_count' => $governed ? 0 : 1,
				'risks' => $governed ? array() : array( array( 'reason' => 'invalid_tool_inventory' ) ),
			);
		}

		$tool_count = count( $tools );
		if ( $governed ) {
			return array(
				'server_id' => $server_id,
				'governed' => true,
				'tool_count' => $tool_count,
				'risk_count' => 0,
				'risks' => array(),
			);
		}

		$risks = array();
		if ( $tool_count > self::MAX_TOOLS_PER_SERVER ) {
			$risks[] = array( 'reason' => 'mcp_tool_inventory_overflow' );
		} else {
			foreach ( array_keys( $tools ) as $tool_name ) {
				$risk = self::inspect_external_tool( $server, (string) $tool_name, $public_write_abilities );
				if ( $risk && count( $risks ) < self::MAX_RISK_DETAILS ) $risks[] = $risk;
			}
		}

		return array(
			'server_id' => $server_id,
			'governed' => false,
			'tool_count' => $tool_count,
			'risk_count' => count( $risks ),
			'risks' => $risks,
		);
	}

	private static function inspect_external_tool( $server, $tool_name, array $public_write_abilities ) {
		try {
			$mcp_tool = $server->get_mcp_tool( $tool_name );
		} catch ( Throwable $e ) {
			return array( 'tool' => $tool_name, 'reason' => 'mcp_tool_inspection_exception' );
		}
		if ( ! is_object( $mcp_tool ) || ! method_exists( $mcp_tool, 'get_adapter_meta' ) ) {
			return array( 'tool' => $tool_name, 'reason' => 'unresolved_tool_semantics' );
		}
		$adapter_meta = $mcp_tool->get_adapter_meta();
		$ability_name = is_array( $adapter_meta ) && isset( $adapter_meta['ability'] ) ? (string) $adapter_meta['ability'] : '';
		if ( '' === $ability_name ) {
			return array( 'tool' => $tool_name, 'reason' => 'direct_callable_tool_unreviewed' );
		}

		if ( self::GENERIC_EXECUTE_ABILITY === $ability_name ) {
			if ( empty( $public_write_abilities ) ) return null;
			return array(
				'tool' => $tool_name,
				'ability' => $ability_name,
				'reason' => 'generic_execute_reaches_public_write',
				'reachable_public_writes' => array_slice( array_values( $public_write_abilities ), 0, self::MAX_RISK_DETAILS ),
			);
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability_name ) : null;
		if ( ! $ability || ! method_exists( $ability, 'get_meta' ) ) {
			return array( 'tool' => $tool_name, 'ability' => $ability_name, 'reason' => 'ability_semantics_unavailable' );
		}
		$meta = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		if ( isset( $annotations['readonly'] ) && true === $annotations['readonly'] ) return null;
		return array(
			'tool' => $tool_name,
			'ability' => $ability_name,
			'reason' => isset( $annotations['readonly'] ) ? 'non_readonly_external_tool' : 'readonly_annotation_missing',
		);
	}

	private static function public_write_abilities() {
		if ( ! function_exists( 'wp_get_abilities' ) || ! class_exists( '\WP\MCP\Abilities\McpAbilityExposure' ) ) {
			return new WP_Error( 'mcp_public_ability_inventory_unavailable', 'Public MCP ability inventory is unavailable.' );
		}
		$write = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) || ! method_exists( $ability, 'get_meta' ) ) continue;
			$name = (string) $ability->get_name();
			if ( self::GENERIC_EXECUTE_ABILITY === $name ) continue;
			if ( ! \WP\MCP\Abilities\McpAbilityExposure::is_public( $ability ) ) continue;
			$meta = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			if ( isset( $annotations['readonly'] ) && true === $annotations['readonly'] ) continue;
			$write[] = $name;
			if ( count( $write ) >= self::MAX_RISK_DETAILS ) break;
		}
		return array_values( array_unique( $write ) );
	}

	private static function unavailable( $reason ) {
		return array(
			'contract' => self::CONTRACT,
			'inventory_ready' => false,
			'adapter_version' => defined( 'WP_MCP_VERSION' ) ? (string) WP_MCP_VERSION : '',
			'server_count' => 0,
			'external_peer_count' => 0,
			'public_write_ability_count' => 0,
			'write_side_channel_detected' => false,
			'risk_count' => 0,
			'blockers' => array( 'mcp_peer_inventory_unavailable' ),
			'reason' => sanitize_key( (string) $reason ),
			'peers' => array(),
		);
	}
}
