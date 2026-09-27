<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only connection tools.
 *
 * The compact preflight is the preferred first call for remote clients. It
 * executes independent checks sequentially through the shared resilience layer,
 * returns partial truth when one check fails, and observes an overall request
 * budget so a single diagnostics request does not become an unbounded fan-out.
 */
final class MAD4B_SCP_Connection_Ability {
	const ABILITY = 'mad4b/connection-status';
	const PREFLIGHT_ABILITY = 'mad4b/connector-preflight';
	const PREFLIGHT_CONTRACT = 'mad4b.connector-preflight.v2';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 25 );
	}

	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;

		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) {
			wp_register_ability(
				self::ABILITY,
				array(
					'label' => 'Connection Status',
					'description' => 'Read-only MAD4B endpoint, transport, subject-bridge and MCP peer readiness.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'execute' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::read_meta(),
				)
			);
		}

		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::PREFLIGHT_ABILITY ) ) ) {
			wp_register_ability(
				self::PREFLIGHT_ABILITY,
				array(
					'label' => 'Connector Compact Preflight',
					'description' => 'Return compact fail-soft runtime, provenance, authority and certification truth for resilient MCP clients without large diagnostic payloads.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'preflight' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(
							'operation_query' => array( 'type' => 'string', 'maxLength' => 160, 'default' => '' ),
							'include_operation_discovery' => array( 'type' => 'boolean', 'default' => false ),
							'include_staging_certification' => array( 'type' => 'boolean', 'default' => false ),
							'retry_transient_reads' => array( 'type' => 'boolean', 'default' => true ),
							'budget_ms' => array( 'type' => 'integer', 'minimum' => 1000, 'maximum' => 30000, 'default' => 12000 ),
						),
						'additionalProperties' => false,
					),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::read_meta(),
				)
			);
		}
	}

	private static function read_meta() {
		return array(
			'public' => false,
			'show_in_rest' => false,
			'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		);
	}

	public static function execute() {
		if ( ! class_exists( 'MAD4B_SCP_Connection_Status' ) ) return new WP_Error( 'mad4b_connection_status_unavailable', 'MAD4B connection readiness service is unavailable.' );
		return MAD4B_SCP_Connection_Status::status();
	}

	public static function preflight( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! class_exists( 'MAD4B_SCP_Connector_Resilience' ) ) {
			return new WP_Error( 'mad4b_connector_resilience_unavailable', 'Shared connector resilience service is unavailable.' );
		}

		$retry = ! array_key_exists( 'retry_transient_reads', $input ) || ! empty( $input['retry_transient_reads'] );
		$budget_ms = isset( $input['budget_ms'] ) ? absint( $input['budget_ms'] ) : MAD4B_SCP_Connector_Resilience::DEFAULT_REQUEST_BUDGET_MS;
		$include_discovery = ! empty( $input['include_operation_discovery'] );
		$include_certification = ! empty( $input['include_staging_certification'] );
		$operation_query = isset( $input['operation_query'] ) ? sanitize_text_field( (string) $input['operation_query'] ) : '';

		$checks = array(
			'site_profile' => static function () {
				$status = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
				return array(
					'configured' => ! empty( $status['configured'] ),
					'environment' => isset( $status['environment'] ) ? (string) $status['environment'] : '',
					'revision' => isset( $status['revision'] ) ? (int) $status['revision'] : 0,
					'profile_digest' => isset( $status['profile_digest'] ) ? (string) $status['profile_digest'] : '',
					'exact_profile_bound' => ! empty( $status['exact_profile_bound'] ),
					'write_enabled' => ! empty( $status['write_enabled'] ),
					'skills_enabled' => ! empty( $status['skills_enabled'] ),
					'blockers' => isset( $status['blockers'] ) && is_array( $status['blockers'] ) ? array_values( array_slice( $status['blockers'], 0, 20 ) ) : array(),
				);
			},
			'build' => static function () {
				$status = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
				return array(
					'version' => isset( $status['version'] ) ? (string) $status['version'] : '',
					'source_commit_sha' => isset( $status['source_commit_sha'] ) ? (string) $status['source_commit_sha'] : '',
					'build_fingerprint' => isset( $status['build_fingerprint'] ) ? (string) $status['build_fingerprint'] : '',
					'package_manifest_digest' => isset( $status['package_manifest_digest'] ) ? (string) $status['package_manifest_digest'] : '',
					'artifact_identity' => isset( $status['artifact_identity'] ) ? (string) $status['artifact_identity'] : '',
					'mcp_adapter_version' => isset( $status['mcp_adapter_version'] ) ? (string) $status['mcp_adapter_version'] : '',
					'runtime_manifest_match' => ! empty( $status['runtime_manifest_match'] ),
					'stale' => ! empty( $status['stale'] ),
					'provenance_mismatch' => isset( $status['provenance_mismatch'] ) && is_array( $status['provenance_mismatch'] ) ? array_values( array_slice( $status['provenance_mismatch'], 0, 20 ) ) : array(),
				);
			},
			'connection' => static function () {
				$status = class_exists( 'MAD4B_SCP_Connection_Status' ) ? MAD4B_SCP_Connection_Status::status() : array();
				return array(
					'environment' => isset( $status['environment'] ) ? (string) $status['environment'] : '',
					'control_plane_version' => isset( $status['control_plane_version'] ) ? (string) $status['control_plane_version'] : '',
					'mcp_adapter_version' => isset( $status['mcp_adapter_version'] ) ? (string) $status['mcp_adapter_version'] : '',
					'local_transport_ready' => ! empty( $status['local_transport_ready'] ),
					'remote_endpoint_preflight_ready' => ! empty( $status['remote_endpoint_preflight_ready'] ),
					'connection_certified' => ! empty( $status['connection_certified'] ),
					'certification_blockers' => isset( $status['certification_blockers'] ) && is_array( $status['certification_blockers'] ) ? array_values( array_slice( $status['certification_blockers'], 0, 20 ) ) : array(),
				);
			},
			'write_authority' => static function () {
				$status = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::status() : array();
				return array(
					'ready' => ! empty( $status['ready'] ),
					'state' => isset( $status['state'] ) ? (string) $status['state'] : '',
					'candidate_binding_match' => ! empty( $status['candidate_binding_match'] ),
					'current_source_commit_sha' => isset( $status['current_source_commit_sha'] ) ? (string) $status['current_source_commit_sha'] : '',
					'candidate_source_commit_sha' => isset( $status['candidate_source_commit_sha'] ) ? (string) $status['candidate_source_commit_sha'] : '',
					'write_tool_count' => isset( $status['write_tool_count'] ) ? (int) $status['write_tool_count'] : 0,
					'wildcard_grants' => isset( $status['wildcard_grants'] ) ? (int) $status['wildcard_grants'] : 0,
					'blockers' => isset( $status['blockers'] ) && is_array( $status['blockers'] ) ? array_values( array_slice( $status['blockers'], 0, 20 ) ) : array(),
				);
			},
		);

		if ( $include_certification ) {
			$checks['staging_certification'] = static function () {
				return class_exists( 'MAD4B_SCP_Staging_Certification' )
					? MAD4B_SCP_Staging_Certification::status( array( 'compact' => true ) )
					: array();
			};
		}

		if ( $include_discovery ) {
			$checks['operation_discovery'] = static function () use ( $operation_query ) {
				return class_exists( 'MAD4B_SCP_Remote_Operation_Parity' )
					? MAD4B_SCP_Remote_Operation_Parity::discover( array(
						'query' => $operation_query,
						'limit' => 10,
						'include_ability_hints' => false,
						'remote_ready_only' => false,
					) )
					: array();
			};
		}

		$result = MAD4B_SCP_Connector_Resilience::run_checks( $checks, array(
			'budget_ms' => $budget_ms,
			'retry_transient' => $retry,
		) );

		$result['contract'] = self::PREFLIGHT_CONTRACT;
		$result['payload_profile'] = 'compact';
		$result['included_staging_certification'] = $include_certification;
		$result['included_operation_discovery'] = $include_discovery;
		$result['resilience_contract'] = MAD4B_SCP_Connector_Resilience::CONTRACT;
		$result['client_guidance'] = MAD4B_SCP_Connector_Resilience::client_guidance();
		return $result;
	}
}
