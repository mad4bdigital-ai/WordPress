<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Local certification for the exact enrolled governed write plane.
 *
 * This proves WordPress-side authority, mounting, approval bootstrapping,
 * local REST isolation and non-leakage. External WPML HTTP acceptance and
 * external ChatGPT tool refresh remain separate live-acceptance gates.
 */
final class MAD4B_SCP_Write_Runtime_Certification {
	const CONTRACT = 'mad4b.write-runtime-certification.v3';
	const OPTION = 'mad4b_scp_write_runtime_certification_v1';
	private static $observing = false;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 37 );
		// Never run certification/reconciliation on rest_api_init. Refreshing MCP
		// actions/tools must remain a read-only low-latency catalog operation.
		// Heavy reconciliation is performed explicitly by this certification ability
		// (execute_callback = observe) or from normal wp-admin requests.
		add_action( 'admin_init', array( __CLASS__, 'observe' ), 110 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( 'mad4b/write-runtime-certification' ) ) return;
		wp_register_ability( 'mad4b/write-runtime-certification', array(
			'label' => 'Get Governed Write Runtime Certification',
			'description' => 'Read exact enrolled-site write authority, NHI grants, approval bootstrap/enforcement, transport mounting and local REST isolation evidence.',
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
		if ( self::$observing ) return self::persisted_status();
		// Guard against accidental invocation while REST routes are still being
		// registered. This path performs authority reconciliation, audit writes and
		// persistence and must never sit on the MCP tools/list critical path.
		if ( function_exists( 'doing_action' ) && doing_action( 'rest_api_init' ) ) return self::status();
		// Certification is meaningful only on the exact enrolled governed-write origin.
		// Off-origin or non-authorized processes must stay fail-closed without producing
		// audit or option churn merely because WordPress booted.
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) {
			return self::ineligible_status();
		}

		self::$observing = true;
		$result = self::current_status();
		self::$observing = false;
		if ( ! is_array( $result ) ) return self::status();

		$previous = get_option( self::OPTION, array() );
		$previous_digest = is_array( $previous ) && isset( $previous['evidence_digest'] ) ? (string) $previous['evidence_digest'] : '';
		$current_digest = isset( $result['evidence_digest'] ) ? (string) $result['evidence_digest'] : '';
		if ( is_array( $previous ) && isset( $previous['contract'] ) && self::CONTRACT === (string) $previous['contract'] && '' !== $current_digest && '' !== $previous_digest && hash_equals( $previous_digest, $current_digest ) ) return $previous;

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
			'external_wpml_acceptance_required' => true,
			'external_wpml_acceptance_verified' => false,
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
		return self::current_status();
	}

	/**
	 * Current truth is recomputed from read-only runtime inspection.
	 * Persisted evidence is never promoted to current release truth.
	 */
	public static function current_status() {
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) {
			return self::ineligible_status();
		}
		if ( class_exists( 'MAD4B_SCP_Live_Truth' ) && method_exists( 'MAD4B_SCP_Live_Truth', 'current_write_certification' ) ) {
			return MAD4B_SCP_Live_Truth::current_write_certification();
		}
		$result = self::evaluate();
		if ( ! is_array( $result ) ) {
			return array(
				'contract' => self::CONTRACT,
				'ready' => false,
				'state' => 'blocked',
				'blockers' => array( 'write_runtime_live_inspection_unavailable' ),
				'current_truth' => true,
				'persistence' => 'read_only_live_inspection',
			);
		}
		$result['current_truth'] = true;
		$result['persistence'] = 'read_only_live_inspection';
		return $result;
	}

	/**
	 * Historical certification is audit evidence only.
	 */
	public static function persisted_status() {
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			$stored['current_truth'] = false;
			$stored['historical'] = true;
			$stored['persistence'] = 'historical_evidence_only';
			return $stored;
		}
		return array(
			'contract' => self::CONTRACT,
			'ready' => false,
			'state' => 'pending',
			'blockers' => array( 'write_runtime_certification_not_observed' ),
			'current_truth' => false,
			'historical' => true,
			'persistence' => 'historical_evidence_only',
			'remote_transport' => 'mad4b-chatgpt',
			'authority_server' => 'mad4b-write',
			'external_wpml_acceptance_required' => true,
			'external_wpml_acceptance_verified' => false,
			'external_client_tools_verified' => false,
		);
	}

	private static function ineligible_status() {
		$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$profile_origin_enrolled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
		$profile_write_enabled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::write_enabled();
		$blocker = empty( $profile['configured'] ) ? 'site_profile_unconfigured' : ( ! $profile_origin_enrolled ? 'site_profile_not_enrolled' : 'site_profile_write_disabled' );
		return array(
			'contract' => self::CONTRACT,
			'ready' => false,
			'state' => 'ineligible',
			'profile_origin_enrolled' => $profile_origin_enrolled,
			'profile_write_enabled' => $profile_write_enabled,
			'write_authority_eligible' => false,
			'blockers' => array( $blocker ),
			'remote_transport' => 'mad4b-chatgpt',
			'authority_server' => 'mad4b-write',
			'external_wpml_acceptance_required' => true,
			'external_wpml_acceptance_verified' => false,
			'external_client_tools_verified' => false,
			'persistence' => 'not_applicable',
			'external_client_action' => 'Write runtime certification is evaluated only on the exact enrolled origin with governed write enabled.',
		);
	}

	private static function evaluate() {
		$blockers = array();
		$checks = array();
		$authority = class_exists( 'MAD4B_SCP_Live_Truth' ) && method_exists( 'MAD4B_SCP_Live_Truth', 'current_authority_status' )
			? MAD4B_SCP_Live_Truth::current_authority_status()
			: ( class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::status() : array() );
		$checks['exact_enrolled_origin'] = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
		$checks['write_feature_enabled'] = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::write_enabled();
		$checks['write_authority_eligible'] = ! empty( $authority['eligible'] );
		$checks['authority_ready'] = ! empty( $authority['ready'] );
		$checks['mutation_gate_enabled'] = defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true === constant( 'MAD4B_MCP_MUTATION_ENABLED' );
		$checks['production_auto_enable_absent'] = empty( $authority['production_auto_enable'] );
		$checks['breakglass_auto_enable_absent'] = empty( $authority['breakglass_auto_enable'] );
		$checks['breakglass_not_included'] = empty( $authority['breakglass_included'] );
		$checks['normal_remote_approval_required'] = ! empty( $authority['normal_remote_writes_require_exact_approval'] );
		$approval_exceptions = isset( $authority['remote_write_approval_exceptions'] ) && is_array( $authority['remote_write_approval_exceptions'] ) ? array_values( array_unique( array_map( 'strval', $authority['remote_write_approval_exceptions'] ) ) ) : array();
		$bounded_exceptions = array();
		if ( ! empty( $authority['candidate_bootstrap_exception_active'] ) && class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ) {
			$bounded_exceptions[] = MAD4B_SCP_Staging_Write_Authority::CANDIDATE_BOOTSTRAP_ABILITY;
		}
		if ( class_exists( 'MAD4B_SCP_Context_Authority' ) && MAD4B_SCP_Context_Authority::ai_review_catalog_eligible() ) {
			$bounded_exceptions[] = MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY;
		}
		$checks['candidate_bootstrap_exception_bounded'] = empty( array_diff( $approval_exceptions, $bounded_exceptions ) );
		$bootstrap_closure = isset( $authority['candidate_bootstrap_closure'] ) && is_array( $authority['candidate_bootstrap_closure'] ) ? $authority['candidate_bootstrap_closure'] : array();
		$checks['candidate_bootstrap_closure_complete'] = empty( $bootstrap_closure['required'] ) || ! empty( $bootstrap_closure['closed'] );
		foreach ( $checks as $key => $ok ) if ( ! $ok ) $blockers[] = $key;

		$oauth = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		$checks['oauth_effective'] = ! empty( $oauth['effective'] );
		$checks['oauth_resource_is_chatgpt'] = isset( $oauth['resource'] ) && false !== strpos( (string) $oauth['resource'], '/wp-json/mcp/mad4b-chatgpt' );
		$checks['oauth_does_not_create_write_authority'] = empty( $oauth['write_surfaces_enabled'] );
		foreach ( array( 'oauth_effective', 'oauth_resource_is_chatgpt', 'oauth_does_not_create_write_authority' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

		$tools = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::write_tools() : array();
		$missing_write_mounts = array();
		$direct_write_schema_leaks = array();
		$write_transport_tools = array( 'mad4b/write-discover', 'mad4b/write-info', 'mad4b/write-execute' );
		$missing_write_transport = array();
		foreach ( $write_transport_tools as $transport_ability ) {
			if ( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $transport_ability ) ) $missing_write_transport[] = $transport_ability;
		}
		$metadata_mismatch = array();
		$breakglass = array();
		foreach ( $tools as $ability_name ) {
			if ( 'mad4b/database-raw-query' === $ability_name ) $breakglass[] = $ability_name;
			if ( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability_name ) ) $missing_write_mounts[] = $ability_name;
			if ( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $ability_name ) ) $direct_write_schema_leaks[] = $ability_name;
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
			// Stable ChatGPT discovery may expose a blocked provider contract. That is
			// not executable authority; only an unexpected mad4b-write mount is a leak.
			if ( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $blocked_ability ) ) {
				$provider_blocked_mount_leaks[] = $blocked_ability;
			}
		}

		$checks['write_inventory_nonempty'] = ! empty( $tools );
		$checks['all_write_tools_mounted_on_authority'] = empty( $missing_write_mounts );
		$checks['write_dispatch_transport_available'] = empty( $missing_write_transport );
		$checks['direct_write_schemas_hidden_from_chatgpt'] = empty( $direct_write_schema_leaks );
		// Backward-compatible field: "same plugin transport" now means every
		// logical write is reachable through the exact-target dispatcher on the
		// same MAD4B ChatGPT MCP resource, not that every large target schema is
		// enumerated directly in tools/list.
		$checks['all_write_tools_exposed_on_same_plugin_transport'] = $checks['write_dispatch_transport_available'] && $checks['direct_write_schemas_hidden_from_chatgpt'];
		$checks['all_write_tools_annotated_mutating'] = empty( $metadata_mismatch );
		$checks['provider_uncertified_write_tools_safely_unmounted'] = empty( $provider_blocked_mount_leaks );
		$checks['breakglass_absent_from_write_inventory'] = empty( $breakglass );
		foreach ( array( 'write_inventory_nonempty', 'all_write_tools_mounted_on_authority', 'write_dispatch_transport_available', 'direct_write_schemas_hidden_from_chatgpt', 'all_write_tools_exposed_on_same_plugin_transport', 'all_write_tools_annotated_mutating', 'provider_uncertified_write_tools_safely_unmounted', 'breakglass_absent_from_write_inventory' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

		// approval-plan is a mutation because it persists a pending ticket. It must
		// itself use NHI + exact mad4b-write grant + budget, but it is the only remote
		// write that cannot require a prior approval ticket. Its target is strictly
		// the same governed agent, mad4b-write, mutation class, never breakglass.
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
		$checks['approval_planner_self_agent_only'] = isset( $planner['target_agent'] ) && 'dedicated_governed_write_agent_only' === $planner['target_agent'];
		$checks['approval_planner_write_server_only'] = isset( $planner['target_server'] ) && 'mad4b-write' === $planner['target_server'];
		$checks['approval_planner_mutation_class_only'] = isset( $planner['target_ticket_class'] ) && 'mutation' === $planner['target_ticket_class'];
		$checks['approval_planner_breakglass_denied'] = isset( $planner['breakglass_target_allowed'] ) && false === $planner['breakglass_target_allowed'];
		foreach ( array( 'approval_planner_in_write_inventory', 'approval_planner_governed', 'approval_planner_nhi_required', 'approval_planner_exact_grant_required', 'approval_planner_budgeted', 'approval_planner_no_prior_ticket', 'approval_planner_self_agent_only', 'approval_planner_write_server_only', 'approval_planner_mutation_class_only', 'approval_planner_breakglass_denied' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

		$counts = class_exists( 'MAD4B_SCP_Agent_Registry' ) ? MAD4B_SCP_Agent_Registry::counts() : array();
		$checks['no_wildcard_grants'] = empty( $counts['wildcard_grants'] );
		if ( ! $checks['no_wildcard_grants'] ) $blockers[] = 'wildcard_grants_detected';

		// Local certification proves only MAD4B's isolation from unrelated REST.
		// The real WPML endpoint is an external acceptance gate because WPML may not
		// register its route inside an already-running MAD4B MCP request lifecycle.
		$rest = class_exists( 'MAD4B_SCP_REST_Compatibility' ) ? MAD4B_SCP_REST_Compatibility::status() : array();
		$checks['rest_enabled'] = ! empty( $rest['rest_enabled'] );
		$checks['control_plane_not_on_rest_enabled_hook'] = empty( $rest['control_plane_filters_rest_enabled'] );
		$checks['control_plane_not_on_rest_authentication_hook'] = empty( $rest['control_plane_filters_rest_authentication_errors'] );
		$checks['control_plane_does_not_block_wpml_rest'] = empty( $rest['wpml']['control_plane_block_detected'] );
		$expected_rest_scope = array(
			'/mcp/mad4b-read',
			'/mcp/mad4b-chatgpt',
			'/mcp/mad4b-enrollment',
			'/mcp/mad4b-content',
			'/mcp/mad4b-write',
			'/mcp/mad4b-admin',
			'/mcp/mad4b-breakglass',
		);
		$actual_rest_scope = isset( $rest['control_plane_rest_pre_dispatch_scope'] ) && is_array( $rest['control_plane_rest_pre_dispatch_scope'] )
			? array_values( $rest['control_plane_rest_pre_dispatch_scope'] )
			: array();
		$checks['mcp_recovery_scope_evaluated'] = ! empty( $rest['mcp_recovery_scope_evaluated'] );
		$checks['mcp_recovery_scoped_to_mad4b_routes'] = $expected_rest_scope === $actual_rest_scope;
		$checks['external_wpml_acceptance_not_claimed_locally'] = isset( $rest['external_http_probe_performed'] ) && false === $rest['external_http_probe_performed'];
		foreach ( array( 'rest_enabled', 'control_plane_not_on_rest_enabled_hook', 'control_plane_not_on_rest_authentication_hook', 'control_plane_does_not_block_wpml_rest', 'mcp_recovery_scope_evaluated', 'mcp_recovery_scoped_to_mad4b_routes', 'external_wpml_acceptance_not_claimed_locally' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

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
			'missing_write_transport' => $missing_write_transport,
			'direct_write_schema_leaks' => $direct_write_schema_leaks,
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
			'metadata_mismatch' => $metadata_mismatch,
			'authority' => $authority,
			'approval_planner' => $planner,
			'rest_compatibility' => $rest,
			'remote_transport' => 'mad4b-chatgpt',
			'authority_server' => 'mad4b-write',
			'oauth_role' => 'identity_only',
			'exact_approval_required_for_remote_write' => empty( $authority['remote_write_approval_exceptions'] ),
			'normal_remote_write_exact_approval_required' => true,
			'candidate_bootstrap_prior_approval_exception' => ! empty( $authority['candidate_bootstrap_exception_active'] ) ? MAD4B_SCP_Staging_Write_Authority::CANDIDATE_BOOTSTRAP_ABILITY : '',
			'candidate_bootstrap_closure' => $bootstrap_closure,
			'approval_planner_bootstrap_exception' => 'pending_ticket_creation_only',
			'external_wpml_acceptance_required' => true,
			'external_wpml_acceptance_verified' => false,
			'external_wpml_test_url' => isset( $rest['external_test_url'] ) ? esc_url_raw( (string) $rest['external_test_url'] ) : '',
			'external_client_tools_verified' => false,
			'evidence_digest' => $digest,
			'observed_at' => gmdate( 'c' ),
			'external_client_action' => 'Run the real external WPML endpoint acceptance, then Refresh/Scan Tools in the same Plugin and verify this exact write inventory externally before merge.',
		);
	}
}
