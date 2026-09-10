<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Local certification for the exact-origin governed Staging write plane.
 *
 * This proves WordPress-side authority, mounting, approval bootstrapping,
 * REST/WPML compatibility and non-leakage. It does not claim that the external
 * ChatGPT client has refreshed its tool snapshot or executed a mutation.
 */
final class MAD4B_SCP_Write_Runtime_Certification {
	const CONTRACT = 'mad4b.write-runtime-certification.v2';
	const OPTION = 'mad4b_scp_write_runtime_certification_v1';
	private static $observing = false;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 37 );
		// Do not inspect REST while abilities are still registering. MCP Adapter's
		// HTTP transports attach their rest_api_init callbacks only when servers are
		// constructed. Observing here used to let rest_get_server() fire too early,
		// leaving every /mcp/* route unregistered in WP-CLI/runtime processes.
		add_action( 'mcp_adapter_init', array( __CLASS__, 'observe' ), 110 );
		add_action( 'admin_init', array( __CLASS__, 'observe' ), 110 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( 'mad4b/write-runtime-certification' ) ) return;
		wp_register_ability( 'mad4b/write-runtime-certification', array(
			'label' => 'Get Governed Write Runtime Certification',
			'description' => 'Read exact-origin Staging write authority, NHI grants, approval bootstrap/enforcement, transport mounting and REST compatibility evidence.',
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

	public static function observe() {
		if ( self::$observing ) return self::status();
		// Certification is meaningful only on the exact governed Staging origin.
		// Off-origin/Production processes must stay fail-closed without producing
		// audit or option churn merely because WordPress booted.
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) {
			return self::ineligible_status();
		}

		self::$observing = true;
		$result = self::evaluate();
		self::$observing = false;
		if ( ! is_array( $result ) ) return self::status();

		$previous = get_option( self::OPTION, array() );
		$previous_digest = is_array( $previous ) && isset( $previous['evidence_digest'] ) ? (string) $previous['evidence_digest'] : '';
		$current_digest = isset( $result['evidence_digest'] ) ? (string) $result['evidence_digest'] : '';
		if ( '' !== $current_digest && '' !== $previous_digest && hash_equals( $previous_digest, $current_digest ) ) return $previous;

		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit['ready'] ) ) {
			$result['ready'] = false;
			$result['state'] = 'blocked';
			$result['blockers'][] = 'audit_unavailable';
			$result['blockers'] = array_values( array_unique( $result['blockers'] ) );
			$result['persistence'] = 'not_recorded';
			return $result;
		}

		$record = MAD4B_SCP_Audit::record( 'mad4b/write-runtime-certification', array(
			'ready' => ! empty( $result['ready'] ),
			'evidence_digest' => $current_digest,
			'write_tool_count' => isset( $result['write_tool_count'] ) ? (int) $result['write_tool_count'] : 0,
			'provider_blocked_write_tool_count' => isset( $result['provider_blocked_write_tool_count'] ) ? (int) $result['provider_blocked_write_tool_count'] : 0,
			'blockers' => isset( $result['blockers'] ) ? $result['blockers'] : array(),
			'remote_transport' => 'mad4b-chatgpt',
			'authority_server' => 'mad4b-write',
			'external_client_tools_verified' => false,
		), ! empty( $result['ready'] ) ? 'ok' : 'blocked' );
		if ( is_wp_error( $record ) ) {
			$result['ready'] = false;
			$result['state'] = 'blocked';
			$result['blockers'][] = 'audit_commit_failed';
			$result['blockers'] = array_values( array_unique( $result['blockers'] ) );
			$result['persistence'] = 'not_recorded';
			return $result;
		}
		$result['persistence'] = 'recorded';
		update_option( self::OPTION, $result, false );
		return $result;
	}

	public static function status() {
		// Never surface a previously persisted exact-Staging certification as current
		// truth after this database/site is moved to another origin or environment.
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) {
			return self::ineligible_status();
		}
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && isset( $stored['contract'] ) && self::CONTRACT === $stored['contract'] ) return $stored;
		return array(
			'contract' => self::CONTRACT,
			'ready' => false,
			'state' => 'pending',
			'blockers' => array( 'write_runtime_certification_not_observed' ),
			'remote_transport' => 'mad4b-chatgpt',
			'authority_server' => 'mad4b-write',
			'external_client_tools_verified' => false,
			'external_client_action' => 'Refresh/Scan Tools for the same Plugin after this exact Staging build is deployed, then verify the write tool inventory through ChatGPT.',
		);
	}

	private static function ineligible_status() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$blocker = 'staging' === $environment ? 'origin_not_governed_staging' : 'environment_not_staging';
		return array(
			'contract' => self::CONTRACT,
			'ready' => false,
			'state' => 'ineligible',
			'blockers' => array( $blocker ),
			'remote_transport' => 'mad4b-chatgpt',
			'authority_server' => 'mad4b-write',
			'external_client_tools_verified' => false,
			'persistence' => 'not_applicable',
			'external_client_action' => 'Write runtime certification is evaluated only on the exact governed Staging origin.',
		);
	}

	private static function evaluate() {
		$blockers = array();
		$checks = array();
		$authority = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::reconcile() : array();
		$checks['exact_staging_origin'] = ! empty( $authority['eligible'] );
		$checks['authority_ready'] = ! empty( $authority['ready'] );
		$checks['mutation_gate_enabled'] = defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true === constant( 'MAD4B_MCP_MUTATION_ENABLED' );
		$checks['production_auto_enable_absent'] = empty( $authority['production_auto_enable'] );
		$checks['breakglass_auto_enable_absent'] = empty( $authority['breakglass_auto_enable'] );
		$checks['breakglass_not_included'] = empty( $authority['breakglass_included'] );
		$checks['remote_approval_required'] = ! empty( $authority['all_remote_writes_require_exact_approval'] );
		foreach ( $checks as $key => $ok ) if ( ! $ok ) $blockers[] = $key;

		$oauth = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		$checks['oauth_effective'] = ! empty( $oauth['effective'] );
		$checks['oauth_resource_is_chatgpt'] = isset( $oauth['resource'] ) && false !== strpos( (string) $oauth['resource'], '/wp-json/mcp/mad4b-chatgpt' );
		$checks['oauth_does_not_create_write_authority'] = empty( $oauth['write_surfaces_enabled'] );
		foreach ( array( 'oauth_effective', 'oauth_resource_is_chatgpt', 'oauth_does_not_create_write_authority' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

		$tools = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::write_tools() : array();
		$missing_write_mounts = array();
		$missing_remote_mounts = array();
		$metadata_mismatch = array();
		$breakglass = array();
		foreach ( $tools as $ability_name ) {
			if ( 'mad4b/database-raw-query' === $ability_name ) $breakglass[] = $ability_name;
			if ( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability_name ) ) $missing_write_mounts[] = $ability_name;
			if ( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $ability_name ) ) $missing_remote_mounts[] = $ability_name;
			if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) {
				$metadata_mismatch[] = $ability_name;
				continue;
			}
			$ability = wp_get_ability( $ability_name );
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) { $metadata_mismatch[] = $ability_name; continue; }
			$meta = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) $metadata_mismatch[] = $ability_name;
		}
		$provider_blocked_write_tools = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::blocked_write_tools() : array();
		$provider_blocked_mount_leaks = array();
		foreach ( $provider_blocked_write_tools as $blocked_tool ) {
			$blocked_ability = isset( $blocked_tool['ability'] ) ? (string) $blocked_tool['ability'] : '';
			if ( '' === $blocked_ability ) continue;
			if ( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $blocked_ability )
				|| MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $blocked_ability ) ) {
				$provider_blocked_mount_leaks[] = $blocked_ability;
			}
		}

		$checks['write_inventory_nonempty'] = ! empty( $tools );
		$checks['all_write_tools_mounted_on_authority'] = empty( $missing_write_mounts );
		$checks['all_write_tools_exposed_on_same_plugin_transport'] = empty( $missing_remote_mounts );
		$checks['all_write_tools_annotated_mutating'] = empty( $metadata_mismatch );
		$checks['provider_uncertified_write_tools_safely_unmounted'] = empty( $provider_blocked_mount_leaks );
		$checks['breakglass_absent_from_write_inventory'] = empty( $breakglass );
		foreach ( array( 'write_inventory_nonempty', 'all_write_tools_mounted_on_authority', 'all_write_tools_exposed_on_same_plugin_transport', 'all_write_tools_annotated_mutating', 'provider_uncertified_write_tools_safely_unmounted', 'breakglass_absent_from_write_inventory' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

		// approval-plan is a mutation because it persists a pending ticket. It must
		// itself use NHI + exact mad4b-write grant + budget, but it is the only remote
		// write that cannot require a prior approval ticket. Its target is strictly
		// the same Staging agent, mad4b-write, mutation class, never breakglass.
		$planner = class_exists( 'MAD4B_SCP_Staging_Write_Planning_Guard' ) ? MAD4B_SCP_Staging_Write_Planning_Guard::status() : array();
		$planner_ability = function_exists( 'wp_has_ability' ) && function_exists( 'wp_get_ability' ) && wp_has_ability( 'mad4b/approval-plan' ) ? wp_get_ability( 'mad4b/approval-plan' ) : null;
		$planner_meta = is_object( $planner_ability ) && method_exists( $planner_ability, 'get_meta' ) ? $planner_ability->get_meta() : array();
		$planner_mcp = isset( $planner_meta['mcp'] ) && is_array( $planner_meta['mcp'] ) ? $planner_meta['mcp'] : array();
		$checks['approval_planner_in_write_inventory'] = in_array( 'mad4b/approval-plan', $tools, true );
		$checks['approval_planner_governed'] = ! empty( $planner_mcp['mad4b_approval_bootstrap_operation'] ) && ! empty( $planner_mcp['mad4b_creates_pending_ticket_only'] );
		$checks['approval_planner_nhi_required'] = ! empty( $planner['remote_planner_requires_nhi'] );
		$checks['approval_planner_exact_grant_required'] = ! empty( $planner['remote_planner_requires_exact_mad4b_write_grant'] );
		$checks['approval_planner_budgeted'] = ! empty( $planner['remote_planner_budgeted'] );
		$checks['approval_planner_no_prior_ticket'] = isset( $planner['remote_planner_requires_prior_ticket'] ) && false === $planner['remote_planner_requires_prior_ticket'];
		$checks['approval_planner_self_agent_only'] = isset( $planner['target_agent'] ) && 'dedicated_staging_write_agent_only' === $planner['target_agent'];
		$checks['approval_planner_write_server_only'] = isset( $planner['target_server'] ) && 'mad4b-write' === $planner['target_server'];
		$checks['approval_planner_mutation_class_only'] = isset( $planner['target_ticket_class'] ) && 'mutation' === $planner['target_ticket_class'];
		$checks['approval_planner_breakglass_denied'] = isset( $planner['breakglass_target_allowed'] ) && false === $planner['breakglass_target_allowed'];
		foreach ( array( 'approval_planner_in_write_inventory', 'approval_planner_governed', 'approval_planner_nhi_required', 'approval_planner_exact_grant_required', 'approval_planner_budgeted', 'approval_planner_no_prior_ticket', 'approval_planner_self_agent_only', 'approval_planner_write_server_only', 'approval_planner_mutation_class_only', 'approval_planner_breakglass_denied' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

		$counts = class_exists( 'MAD4B_SCP_Agent_Registry' ) ? MAD4B_SCP_Agent_Registry::counts() : array();
		$checks['no_wildcard_grants'] = empty( $counts['wildcard_grants'] );
		if ( ! $checks['no_wildcard_grants'] ) $blockers[] = 'wildcard_grants_detected';

		$rest = class_exists( 'MAD4B_SCP_REST_Compatibility' ) ? MAD4B_SCP_REST_Compatibility::status() : array();
		$checks['rest_enabled'] = ! empty( $rest['rest_enabled'] );
		$checks['control_plane_not_on_rest_enabled_hook'] = empty( $rest['control_plane_filters_rest_enabled'] );
		$checks['control_plane_not_on_rest_authentication_hook'] = empty( $rest['control_plane_filters_rest_authentication_errors'] );
		$checks['control_plane_does_not_block_wpml_rest'] = empty( $rest['wpml']['control_plane_block_detected'] );
		$checks['wpml_query_parameters_preserved'] = ! empty( $rest['wpml']['query_parameters_preserved'] );
		$checks['wpml_internal_probe_ready_or_not_active'] = ! empty( $rest['wpml']['ready'] );
		foreach ( array( 'rest_enabled', 'control_plane_not_on_rest_enabled_hook', 'control_plane_not_on_rest_authentication_hook', 'control_plane_does_not_block_wpml_rest', 'wpml_query_parameters_preserved', 'wpml_internal_probe_ready_or_not_active' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

		$peer = class_exists( 'MAD4B_SCP_MCP_Peer_Governance' ) ? MAD4B_SCP_MCP_Peer_Governance::status() : array();
		$checks['peer_inventory_ready'] = ! empty( $peer['inventory_ready'] );
		if ( ! $checks['peer_inventory_ready'] ) $blockers[] = 'mcp_peer_inventory_unavailable';

		$evidence = array(
			'checks' => $checks,
			'authority' => $authority,
			'planner' => $planner,
			'write_tools' => $tools,
			'provider_blocked_write_tools' => $provider_blocked_write_tools,
			'provider_blocked_mount_leaks' => $provider_blocked_mount_leaks,
			'missing_write_mounts' => $missing_write_mounts,
			'missing_remote_mounts' => $missing_remote_mounts,
			'metadata_mismatch' => $metadata_mismatch,
			'breakglass' => $breakglass,
			'rest' => $rest,
		);
		$digest = hash( 'sha256', wp_json_encode( $evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$blockers = array_values( array_unique( $blockers ) );
		return array(
			'contract' => self::CONTRACT,
			'ready' => empty( $blockers ),
			'state' => empty( $blockers ) ? 'ready' : 'blocked',
			'blockers' => $blockers,
			'checks' => $checks,
			'write_tool_count' => count( $tools ),
			'write_tools' => $tools,
			'provider_blocked_write_tool_count' => count( $provider_blocked_write_tools ),
			'provider_blocked_write_tools' => $provider_blocked_write_tools,
			'provider_blocked_mount_leaks' => $provider_blocked_mount_leaks,
			'missing_write_mounts' => $missing_write_mounts,
			'missing_remote_mounts' => $missing_remote_mounts,
			'metadata_mismatch' => $metadata_mismatch,
			'authority' => $authority,
			'approval_planner' => $planner,
			'rest_compatibility' => $rest,
			'remote_transport' => 'mad4b-chatgpt',
			'authority_server' => 'mad4b-write',
			'oauth_role' => 'identity_only',
			'exact_approval_required_for_remote_write' => true,
			'approval_planner_bootstrap_exception' => 'pending_ticket_creation_only',
			'external_client_tools_verified' => false,
			'evidence_digest' => $digest,
			'observed_at' => gmdate( 'c' ),
			'external_client_action' => 'Refresh/Scan Tools in the same Plugin and verify this exact write inventory externally before merge.',
		);
	}
}
