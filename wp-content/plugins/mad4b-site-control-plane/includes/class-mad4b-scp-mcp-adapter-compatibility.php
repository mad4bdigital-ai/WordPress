<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Version-neutral seam across MCP Adapter 0.6.x DTO and 0.7.x exact-revision APIs.
 *
 * MAD4B keeps one catalog/governance implementation while the official Adapter
 * owns wire-schema evolution. This helper performs only representation
 * adaptation; it creates no authority and executes no Ability.
 */
final class MAD4B_SCP_MCP_Adapter_Compatibility {
	const CONTRACT = 'mad4b.mcp-adapter-compatibility.v1';
	const DEFAULT_REVISION = '2025-11-25';

	public static function mode() {
		return class_exists( '\\WP\\MCP\\Domain\\Tools\\McpTool' )
			&& method_exists( '\\WP\\MCP\\Domain\\Tools\\McpTool', 'get_protocol_record' )
			? 'revision_aware'
			: 'legacy_dto';
	}

	public static function schema( $server = null, $revision = self::DEFAULT_REVISION ) {
		$revision = trim( (string) $revision );
		if ( '' === $revision ) $revision = self::DEFAULT_REVISION;

		try {
			if ( is_object( $server ) && method_exists( $server, 'get_schemas' ) ) {
				$schemas = $server->get_schemas();
				if ( is_object( $schemas ) && method_exists( $schemas, 'forVersion' ) ) return $schemas->forVersion( $revision );
			}
			if ( class_exists( '\\WP\\McpSchema\\Schemas' ) && method_exists( '\\WP\\McpSchema\\Schemas', 'create' ) ) {
				$schemas = \WP\McpSchema\Schemas::create();
				if ( is_object( $schemas ) && method_exists( $schemas, 'forVersion' ) ) return $schemas->forVersion( $revision );
			}
		} catch ( Throwable $error ) {
			return new WP_Error( 'mad4b_mcp_schema_resolution_failed', 'Unable to resolve the MCP wire schema for this Adapter generation.' );
		}
		return new WP_Error( 'mad4b_mcp_schema_unavailable', 'MCP exact-revision schema catalog is unavailable.' );
	}

	public static function build_ability_wire( $ability, $revision = self::DEFAULT_REVISION ) {
		if ( ! is_object( $ability ) ) return new WP_Error( 'mad4b_mcp_ability_invalid', 'MCP Ability object is unavailable.' );
		if ( ! class_exists( '\\WP\\MCP\\Domain\\Tools\\RegisterAbilityAsMcpTool' ) ) {
			return new WP_Error( 'mad4b_mcp_builder_unavailable', 'Official MCP Ability tool builder is unavailable.' );
		}

		try {
			if ( 'revision_aware' === self::mode() ) {
				if ( ! class_exists( '\\WP\\MCP\\Domain\\Tools\\McpTool' ) || ! method_exists( '\\WP\\MCP\\Domain\\Tools\\McpTool', 'fromAbility' ) ) {
					return new WP_Error( 'mad4b_mcp_runtime_tool_builder_unavailable', 'Revision-aware MCP tool builder is unavailable.' );
				}
				$runtime_tool = \WP\MCP\Domain\Tools\McpTool::fromAbility( $ability );
				if ( is_wp_error( $runtime_tool ) ) return $runtime_tool;
				$schema = self::schema( null, $revision );
				if ( is_wp_error( $schema ) ) return $schema;
				$wire = $runtime_tool->get_protocol_record( $schema );
				$meta = method_exists( $runtime_tool, 'get_adapter_meta' ) ? $runtime_tool->get_adapter_meta() : array();
				return array( 'wire' => $wire, 'adapter_meta' => is_array( $meta ) ? $meta : array(), 'runtime_tool' => $runtime_tool );
			}

			$built = \WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool::build( $ability );
			if ( is_wp_error( $built ) ) return $built;
			if ( ! is_array( $built ) || ! isset( $built['tool'] ) || ! is_object( $built['tool'] ) ) {
				return new WP_Error( 'mad4b_mcp_legacy_builder_contract_invalid', 'Legacy MCP builder returned an unexpected contract.' );
			}
			return array(
				'wire' => $built['tool'],
				'adapter_meta' => isset( $built['adapter_meta'] ) && is_array( $built['adapter_meta'] ) ? $built['adapter_meta'] : array(),
				'runtime_tool' => null,
			);
		} catch ( Throwable $error ) {
			return new WP_Error( 'mad4b_mcp_tool_projection_failed', 'Unable to project Ability into the selected MCP wire revision.' );
		}
	}

	public static function server_tools( $server, $revision = self::DEFAULT_REVISION ) {
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_tools' ) ) {
			return new WP_Error( 'mad4b_mcp_server_tools_unavailable', 'MCP server tool inventory is unavailable.' );
		}
		try {
			$method = new ReflectionMethod( $server, 'get_tools' );
			if ( 0 === $method->getNumberOfRequiredParameters() ) return $server->get_tools();
			$schema = self::schema( $server, $revision );
			if ( is_wp_error( $schema ) ) return $schema;
			return $server->get_tools( $schema );
		} catch ( Throwable $error ) {
			return new WP_Error( 'mad4b_mcp_server_tools_projection_failed', 'Unable to project the MCP server tool inventory.' );
		}
	}

	public static function runtime_tool_wire( $tool, $server = null, $revision = self::DEFAULT_REVISION ) {
		if ( ! is_object( $tool ) ) return new WP_Error( 'mad4b_mcp_runtime_tool_unavailable', 'MCP runtime tool is unavailable.' );
		try {
			if ( method_exists( $tool, 'get_protocol_dto' ) ) return $tool->get_protocol_dto();
			if ( method_exists( $tool, 'get_protocol_record' ) ) {
				$schema = self::schema( $server, $revision );
				if ( is_wp_error( $schema ) ) return $schema;
				return $tool->get_protocol_record( $schema );
			}
		} catch ( Throwable $error ) {
			return new WP_Error( 'mad4b_mcp_runtime_tool_projection_failed', 'Unable to project the MCP runtime tool.' );
		}
		return new WP_Error( 'mad4b_mcp_runtime_tool_contract_unknown', 'MCP runtime tool projection contract is unknown.' );
	}

	public static function wire_data( $wire ) {
		try {
			if ( is_array( $wire ) ) return $wire;
			// Convert only the top-level record/object to an associative array.
			// Nested stdClass values (notably empty JSON Schema objects such as
			// properties:{}) must remain objects or their wire shape changes to [].
			if ( $wire instanceof stdClass ) return get_object_vars( $wire );
			if ( is_object( $wire ) && method_exists( $wire, 'toArray' ) ) {
				$data = $wire->toArray();
				if ( is_array( $data ) ) return $data;
				if ( $data instanceof stdClass ) return get_object_vars( $data );
			}
			if ( is_object( $wire ) && method_exists( $wire, 'jsonSerialize' ) ) {
				$data = $wire->jsonSerialize();
				if ( is_array( $data ) ) return $data;
				if ( $data instanceof stdClass ) return get_object_vars( $data );
			}
			if ( $wire instanceof JsonSerializable ) {
				$data = $wire->jsonSerialize();
				if ( is_array( $data ) ) return $data;
				if ( $data instanceof stdClass ) return get_object_vars( $data );
			}
		} catch ( Throwable $error ) {
			return new WP_Error( 'mad4b_mcp_wire_serialization_failed', 'MCP wire representation could not be serialized.' );
		}
		return new WP_Error( 'mad4b_mcp_wire_contract_unknown', 'MCP wire representation contract is unknown.' );
	}

	public static function wire_name( $wire ) {
		try {
			if ( is_object( $wire ) && method_exists( $wire, 'getName' ) ) {
				$name = $wire->getName();
				return is_string( $name ) ? $name : '';
			}
			if ( is_object( $wire ) && method_exists( $wire, 'get' ) ) {
				$name = $wire->get( 'name' );
				if ( is_string( $name ) ) return $name;
			}
			$data = self::wire_data( $wire );
			if ( is_wp_error( $data ) ) return '';
			return isset( $data['name'] ) && is_string( $data['name'] ) ? $data['name'] : '';
		} catch ( Throwable $error ) {
			return '';
		}
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'mode' => self::mode(),
			'default_revision' => self::DEFAULT_REVISION,
			'creates_authority' => false,
			'executes_abilities' => false,
		);
	}
}
