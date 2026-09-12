<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Fresh read-only truth for the governed Staging write plane.
 *
 * Read abilities must never mutate state just to answer a status question.
 * This bridge therefore separates three concerns:
 *  - live inspection: current NHI/grants/write projection/REST isolation;
 *  - runtime reconciliation: zero-touch lifecycle work after full init boot;
 *  - persisted certification: durable evidence written only by the explicit
 *    certification observer, with build/inventory freshness metadata.
 */
final class MAD4B_SCP_Live_Truth {
	const CONTRACT = 'mad4b.live-truth.v1';
	const FRESHNESS_OPTION = 'mad4b_scp_write_runtime_certification_freshness_v1';

	private static $booted = false;
	private static $full_boot_seen = false;
	private static $abilities_materialized = false;
	private static $recovering = false;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;

		// Must exist before the one-shot Ability registry materializes. Priority 100
		// intentionally wins over the legacy late certification callback rewrite.
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'bind_live_read_callbacks' ), 100, 2 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'abilities_materialized' ), PHP_INT_MAX );

		// Full Control Plane boot is wired at init -1000000. This callback runs one
		// priority later and closes the case where another component legally created
		// the Ability registry at init -1000001, before full boot could attach its
		// reconciliation hook.
		add_action( 'init', array( __CLASS__, 'after_full_boot' ), -999999 );

		// Existing persisted certification records remain historical evidence. This
		// filter prevents a record from being presented as current when its build or
		// write/provider projection no longer matches this request.
		if ( class_exists( 'MAD4B_SCP_Write_Runtime_Certification' ) ) {
			add_filter( 'option_' . MAD4B_SCP_Write_Runtime_Certification::OPTION, array( __CLASS__, 'filter_persisted_certification' ), 100 );
		}
		add_action( 'added_option', array( __CLASS__, 'record_added_certification_freshness' ), 100, 2 );
		add_action( 'updated_option', array( __CLASS__, 'record_updated_certification_freshness' ), 100, 3 );

		// Admin export/certification is an explicit persistence boundary. Refresh the
		// durable record before the Skills exporter (admin_init priority 1) can read it.
		add_action( 'admin_init', array( __CLASS__, 'refresh_admin_certification' ), 0 );
	}

	public static function bind_live_read_callbacks( $args, $name ) {
		if ( ! is_array( $args ) ) return $args;
		$name = (string) $name;
		if ( 'mad4b/write-authority-status' === $name ) {
			$args['execute_callback'] = array( __CLASS__, 'current_authority_status' );
		}
		if ( 'mad4b/write-runtime-certification' === $name ) {
			$args['execute_callback'] = array( __CLASS__, 'current_write_certification' );
		}
		if ( 'mad4b/rest-compatibility-status' === $name ) {
			$args['execute_callback'] = array( __CLASS__, 'current_rest_compatibility' );
		}
		return $args;
	}

	public static function abilities_materialized() {
		self::$abilities_materialized = true;
		if ( self::$full_boot_seen ) self::recover_runtime_authority();
	}

	public static function after_full_boot() {
		self::$full_boot_seen = true;
		if ( self::$abilities_materialized || did_action( 'wp_abilities_api_init' ) > 0 ) {
			self::recover_runtime_authority();
		}
	}

	private static function recover_runtime_authority() {
		if ( self::$recovering ) return;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) return;
		if ( ! class_exists( 'MAD4B_SCP_Plugin' ) || ! method_exists( 'MAD4B_SCP_Plugin', 'reconcile_authority_if_needed' ) ) return;
		self::$recovering = true;
		MAD4B_SCP_Plugin::reconcile_authority_if_needed();
		self::$recovering = false;
	}

	public static function current_authority_status() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$parts = wp_parse_url( home_url( '/' ) );
		$host = is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( rtrim( (string) $parts['host'], '.' ) ) : '';
		$expected_host = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::STAGING_HOST : 'staging.egypttourgates.com';
		$eligible = 'staging' === $environment && $expected_host === $host;
		$inventory = self::inventory_identity();
		$blockers = array();
		$grant_blockers = array();

		if ( ! $eligible ) $blockers[] = 'staging' !== $environment ? 'environment_not_staging' : 'origin_not_governed_staging';
		$mutation_gate = defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true === constant( 'MAD4B_MCP_MUTATION_ENABLED' );
		if ( $eligible && ! $mutation_gate ) $blockers[] = 'mutation_gate_disabled';
		$schema_ready = class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::is_ready();
		if ( $eligible && ! $schema_ready ) $blockers[] = 'governance_schema_unavailable';
		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( $eligible && empty( $audit['ready'] ) ) $blockers[] = 'audit_unavailable';

		$oauth = class_exists( 'MAD4B_SCP_Staging_OAuth_Autoconfig' ) ? MAD4B_SCP_Staging_OAuth_Autoconfig::status() : array();
		$user_id = isset( $oauth['wp_user_id'] ) ? absint( $oauth['wp_user_id'] ) : 0;
		$issuer = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? rtrim( (string) MAD4B_SCP_Local_OAuth_Server::issuer(), '/' ) : '';
		if ( $eligible && ( empty( $oauth['configured'] ) || $user_id < 1 || '' === $issuer ) ) $blockers[] = 'staging_oauth_subject_unavailable';
		$user = $user_id > 0 ? get_userdata( $user_id ) : null;
		if ( $eligible && $user_id > 0 && ( ! $user || ! user_can( $user, 'manage_options' ) ) ) $blockers[] = 'staging_oauth_user_not_admin';

		$agent = null;
		if ( $eligible && $schema_ready ) {
			global $wpdb;
			$tables = MAD4B_SCP_Schema::tables();
			$slug = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::AGENT_SLUG : 'chatgpt-staging-write';
			$agent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['agents']} WHERE slug = %s LIMIT 1", $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( ! $agent ) $blockers[] = 'staging_write_agent_missing';
		}
		if ( is_array( $agent ) ) {
			if ( 'enabled' !== (string) $agent['status'] ) $blockers[] = 'staging_write_agent_disabled';
			if ( 'staging' !== (string) $agent['environment'] ) $blockers[] = 'staging_write_agent_environment_mismatch';
			if ( $user_id > 0 && (int) $agent['wp_user_id'] !== $user_id ) $blockers[] = 'staging_write_agent_user_mismatch';
		}

		$fingerprint = '';
		$bound = null;
		if ( is_array( $agent ) && '' !== $issuer && $user_id > 0 && class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$fingerprint = hash( 'sha256', 'oauth' . "\0" . $issuer . "\0" . 'user:' . $user_id );
			$bound = MAD4B_SCP_Agent_Registry::resolve_agent( array(
				'authenticated' => true,
				'subject_type' => 'oauth',
				'subject_fingerprint' => $fingerprint,
			) );
			if ( is_wp_error( $bound ) ) $blockers[] = $bound->get_error_code();
			elseif ( (int) $bound['id'] !== (int) $agent['id'] ) $blockers[] = 'oauth_subject_bound_to_other_agent';
		}

		$tools = isset( $inventory['write_tools'] ) ? $inventory['write_tools'] : array();
		if ( $eligible && empty( $tools ) ) $blockers[] = 'write_tool_inventory_empty';
		$existing = 0;
		if ( is_array( $agent ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) && class_exists( 'MAD4B_SCP_Servers' ) ) {
			foreach ( $tools as $ability ) {
				$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
				if ( null === $provider ) { $grant_blockers[] = 'unmounted:' . $ability; continue; }
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $provider );
				if ( is_wp_error( $grant ) ) $grant_blockers[] = $grant->get_error_code() . ':' . $ability;
				else ++$existing;
			}
		}
		if ( ! empty( $grant_blockers ) ) $blockers[] = 'grant_reconciliation_incomplete';

		$counts = class_exists( 'MAD4B_SCP_Agent_Registry' ) && $schema_ready ? MAD4B_SCP_Agent_Registry::counts() : array();
		$wildcards = isset( $counts['wildcard_grants'] ) ? (int) $counts['wildcard_grants'] : 0;
		if ( $wildcards > 0 ) $blockers[] = 'wildcard_grants_detected';
		$breakglass = in_array( 'mad4b/database-raw-query', $tools, true );
		if ( $breakglass ) $blockers[] = 'breakglass_leak';

		// Current database truth is not enough to claim executable authority: the
		// legacy runtime object must also have reconciled this exact projection so
		// server exposure/authorization see the same state in this request.
		$runtime = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::status() : array();
		$runtime_reconciled = ! empty( $runtime['ready'] )
			&& empty( $runtime['blocker'] )
			&& isset( $runtime['write_tool_count'] )
			&& (int) $runtime['write_tool_count'] === count( $tools );
		if ( $eligible && ! $runtime_reconciled ) $blockers[] = 'runtime_authority_not_reconciled';

		$blockers = array_values( array_unique( array_filter( array_map( 'strval', $blockers ) ) ) );
		$ready = $eligible && empty( $blockers );
		return array(
			'contract' => class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::CONTRACT : 'mad4b.staging-write-authority.v1',
			'truth_contract' => self::CONTRACT,
			'environment' => $environment,
			'host' => $host,
			'eligible' => $eligible,
			'ready' => $ready,
			'state' => $eligible ? ( $ready ? 'ready' : 'blocked' ) : 'ineligible',
			'blocker' => empty( $blockers ) ? '' : $blockers[0],
			'blockers' => $blockers,
			'mutation_gate_configured' => $mutation_gate,
			'configuration_source' => $eligible ? 'staging_exact_origin_auto' : 'none',
			'agent_public_id' => is_array( $agent ) && isset( $agent['public_id'] ) ? (string) $agent['public_id'] : '',
			'subject_fingerprint_prefix' => '' !== $fingerprint ? substr( $fingerprint, 0, 16 ) : '',
			'write_tool_count' => count( $tools ),
			'write_tools' => $tools,
			'exact_grants_existing' => $existing,
			'exact_grants_created' => 0,
			'grant_blockers' => $grant_blockers,
			'total_grants' => isset( $counts['grants'] ) ? (int) $counts['grants'] : 0,
			'wildcard_grants' => $wildcards,
			'production_auto_enable' => false,
			'breakglass_auto_enable' => false,
			'breakglass_included' => $breakglass,
			'all_remote_writes_require_exact_approval' => true,
			'remote_transport' => 'mad4b-chatgpt',
			'authority_server' => 'mad4b-write',
			'oauth_role' => 'identity_only',
			'runtime_reconciled' => $runtime_reconciled,
			'runtime_state' => isset( $runtime['state'] ) ? (string) $runtime['state'] : 'unknown',
			'control_plane_version' => isset( $inventory['control_plane_version'] ) ? $inventory['control_plane_version'] : '',
			'write_inventory_fingerprint' => isset( $inventory['write_inventory_fingerprint'] ) ? $inventory['write_inventory_fingerprint'] : '',
			'provider_blocked_fingerprint' => isset( $inventory['provider_blocked_fingerprint'] ) ? $inventory['provider_blocked_fingerprint'] : '',
			'provider_blocked_write_tool_count' => isset( $inventory['provider_blocked_write_tool_count'] ) ? $inventory['provider_blocked_write_tool_count'] : 0,
			'inspection_source' => 'live_read_only',
			'persists_changes' => false,
			'observed_at' => gmdate( 'c' ),
		);
	}

	public static function current_write_certification() {
		$authority = self::current_authority_status();
		$inventory = self::inventory_identity();
		$tools = isset( $inventory['write_tools'] ) ? $inventory['write_tools'] : array();
		$provider_blocked = isset( $inventory['provider_blocked_write_tools'] ) ? $inventory['provider_blocked_write_tools'] : array();
		$checks = array();
		$blockers = array();

		$checks['exact_staging_origin'] = ! empty( $authority['eligible'] );
		$checks['authority_ready'] = ! empty( $authority['ready'] );
		$checks['mutation_gate_enabled'] = ! empty( $authority['mutation_gate_configured'] );
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

		$missing_write_mounts = array();
		$missing_remote_mounts = array();
		$metadata_mismatch = array();
		$breakglass = array();
		foreach ( $tools as $ability_name ) {
			if ( 'mad4b/database-raw-query' === $ability_name ) $breakglass[] = $ability_name;
			if ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability_name ) ) $missing_write_mounts[] = $ability_name;
			if ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $ability_name ) ) $missing_remote_mounts[] = $ability_name;
			if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) { $metadata_mismatch[] = $ability_name; continue; }
			$ability = wp_get_ability( $ability_name );
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) { $metadata_mismatch[] = $ability_name; continue; }
			$meta = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) $metadata_mismatch[] = $ability_name;
		}

		$provider_blocked_mount_leaks = array();
		foreach ( $provider_blocked as $blocked ) {
			$ability_name = isset( $blocked['ability'] ) ? (string) $blocked['ability'] : '';
			if ( '' === $ability_name || ! class_exists( 'MAD4B_SCP_Servers' ) ) continue;
			if ( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability_name ) || MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $ability_name ) ) $provider_blocked_mount_leaks[] = $ability_name;
		}
		$checks['write_inventory_nonempty'] = ! empty( $tools );
		$checks['all_write_tools_mounted_on_authority'] = empty( $missing_write_mounts );
		$checks['all_write_tools_exposed_on_same_plugin_transport'] = empty( $missing_remote_mounts );
		$checks['all_write_tools_annotated_mutating'] = empty( $metadata_mismatch );
		$checks['provider_uncertified_write_tools_safely_unmounted'] = empty( $provider_blocked_mount_leaks );
		$checks['breakglass_absent_from_write_inventory'] = empty( $breakglass );
		foreach ( array( 'write_inventory_nonempty', 'all_write_tools_mounted_on_authority', 'all_write_tools_exposed_on_same_plugin_transport', 'all_write_tools_annotated_mutating', 'provider_uncertified_write_tools_safely_unmounted', 'breakglass_absent_from_write_inventory' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

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

		// Deliberately structural only. Do not require or even probe a lazy WPML
		// provider route from inside the MCP request that is being certified.
		$rest = self::local_rest_isolation();
		$checks['rest_enabled'] = ! empty( $rest['rest_enabled'] );
		$checks['control_plane_not_on_rest_enabled_hook'] = empty( $rest['control_plane_filters_rest_enabled'] );
		$checks['control_plane_not_on_rest_authentication_hook'] = empty( $rest['control_plane_filters_rest_authentication_errors'] );
		$checks['mcp_recovery_scoped_to_mad4b_routes'] = ! empty( $rest['mcp_recovery_scoped_to_mad4b_routes'] );
		$checks['external_wpml_acceptance_not_claimed_locally'] = true;
		foreach ( array( 'rest_enabled', 'control_plane_not_on_rest_enabled_hook', 'control_plane_not_on_rest_authentication_hook', 'mcp_recovery_scoped_to_mad4b_routes', 'external_wpml_acceptance_not_claimed_locally' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

		$peer = class_exists( 'MAD4B_SCP_MCP_Peer_Governance' ) ? MAD4B_SCP_MCP_Peer_Governance::status() : array();
		$checks['peer_inventory_ready'] = ! empty( $peer['inventory_ready'] );
		if ( ! $checks['peer_inventory_ready'] ) $blockers[] = 'mcp_peer_inventory_unavailable';

		$blockers = array_values( array_unique( array_filter( array_map( 'strval', $blockers ) ) ) );
		$evidence = array(
			'checks' => $checks,
			'authority' => $authority,
			'planner' => $planner,
			'write_tools' => $tools,
			'provider_blocked_write_tools' => $provider_blocked,
			'provider_blocked_mount_leaks' => $provider_blocked_mount_leaks,
			'missing_write_mounts' => $missing_write_mounts,
			'missing_remote_mounts' => $missing_remote_mounts,
			'metadata_mismatch' => $metadata_mismatch,
			'breakglass' => $breakglass,
			'local_rest_isolation' => $rest,
			'control_plane_version' => isset( $inventory['control_plane_version'] ) ? $inventory['control_plane_version'] : '',
			'write_inventory_fingerprint' => isset( $inventory['write_inventory_fingerprint'] ) ? $inventory['write_inventory_fingerprint'] : '',
			'provider_blocked_fingerprint' => isset( $inventory['provider_blocked_fingerprint'] ) ? $inventory['provider_blocked_fingerprint'] : '',
		);
		$digest = hash( 'sha256', wp_json_encode( $evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		return array(
			'contract' => class_exists( 'MAD4B_SCP_Write_Runtime_Certification' ) ? MAD4B_SCP_Write_Runtime_Certification::CONTRACT : 'mad4b.write-runtime-certification.v2',
			'truth_contract' => self::CONTRACT,
			'current_truth' => true,
			'ready' => empty( $blockers ),
			'state' => empty( $blockers ) ? 'ready' : 'blocked',
			'blockers' => $blockers,
			'checks' => $checks,
			'write_tool_count' => count( $tools ),
			'write_tools' => $tools,
			'provider_blocked_write_tool_count' => count( $provider_blocked ),
			'provider_blocked_write_tools' => $provider_blocked,
			'provider_blocked_mount_leaks' => $provider_blocked_mount_leaks,
			'missing_write_mounts' => $missing_write_mounts,
			'missing_remote_mounts' => $missing_remote_mounts,
			'metadata_mismatch' => $metadata_mismatch,
			'authority' => $authority,
			'approval_planner' => $planner,
			'local_rest_isolation' => $rest,
			'remote_transport' => 'mad4b-chatgpt',
			'authority_server' => 'mad4b-write',
			'oauth_role' => 'identity_only',
			'exact_approval_required_for_remote_write' => true,
			'approval_planner_bootstrap_exception' => 'pending_ticket_creation_only',
			'external_wpml_acceptance_required' => true,
			'external_wpml_acceptance_verified' => false,
			'wpml_internal_probe_blocks_local_certification' => false,
			'external_wpml_test_url' => self::external_wpml_test_url(),
			'external_client_tools_verified' => false,
			'control_plane_version' => isset( $inventory['control_plane_version'] ) ? $inventory['control_plane_version'] : '',
			'write_inventory_fingerprint' => isset( $inventory['write_inventory_fingerprint'] ) ? $inventory['write_inventory_fingerprint'] : '',
			'provider_blocked_fingerprint' => isset( $inventory['provider_blocked_fingerprint'] ) ? $inventory['provider_blocked_fingerprint'] : '',
			'evidence_digest' => $digest,
			'observed_at' => gmdate( 'c' ),
			'persistence' => 'read_only_live_inspection',
			'external_client_action' => 'Run the real external WPML endpoint acceptance, then Refresh/Scan Tools in the same Plugin and verify this exact write inventory externally before merge.',
		);
	}

	public static function current_rest_compatibility() {
		$diagnostic = class_exists( 'MAD4B_SCP_REST_Compatibility' ) ? MAD4B_SCP_REST_Compatibility::status() : array();
		$local = self::local_rest_isolation();
		if ( ! is_array( $diagnostic ) ) $diagnostic = array();
		$diagnostic['truth_contract'] = self::CONTRACT;
		$diagnostic['local_rest_isolation'] = $local;
		$diagnostic['local_rest_isolation_ready'] = ! empty( $local['ready'] );
		$diagnostic['wpml_internal_probe_role'] = 'diagnostic_only';
		$diagnostic['wpml_internal_probe_blocks_local_certification'] = false;
		$diagnostic['external_wpml_acceptance_required'] = true;
		$diagnostic['external_wpml_acceptance_verified'] = false;
		$diagnostic['external_acceptance_gate'] = 'required_before_ready_or_merge';
		$diagnostic['observed_at'] = gmdate( 'c' );
		return $diagnostic;
	}

	public static function filter_persisted_certification( $value ) {
		if ( ! self::$full_boot_seen || ! is_array( $value ) || empty( $value ) ) return $value;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) return $value;
		$identity = self::inventory_identity();
		$freshness = get_option( self::FRESHNESS_OPTION, array() );
		$reasons = self::stale_reasons( $value, $freshness, $identity );
		if ( empty( $reasons ) ) return $value;

		return array(
			'contract' => class_exists( 'MAD4B_SCP_Write_Runtime_Certification' ) ? MAD4B_SCP_Write_Runtime_Certification::CONTRACT : 'mad4b.write-runtime-certification.v2',
			'truth_contract' => self::CONTRACT,
			'ready' => false,
			'state' => 'stale',
			'stale' => true,
			'blockers' => array( 'stale_persisted_certification' ),
			'stale_reasons' => $reasons,
			'stored_evidence_digest' => isset( $value['evidence_digest'] ) ? (string) $value['evidence_digest'] : '',
			'stored_observed_at' => isset( $value['observed_at'] ) ? (string) $value['observed_at'] : '',
			'current_control_plane_version' => isset( $identity['control_plane_version'] ) ? $identity['control_plane_version'] : '',
			'current_write_tool_count' => isset( $identity['write_tool_count'] ) ? (int) $identity['write_tool_count'] : 0,
			'current_write_inventory_fingerprint' => isset( $identity['write_inventory_fingerprint'] ) ? $identity['write_inventory_fingerprint'] : '',
			'current_provider_blocked_fingerprint' => isset( $identity['provider_blocked_fingerprint'] ) ? $identity['provider_blocked_fingerprint'] : '',
			'external_wpml_acceptance_required' => true,
			'external_wpml_acceptance_verified' => false,
			'persistence' => 'historical_evidence_not_current',
		);
	}

	public static function record_added_certification_freshness( $option, $value ) {
		if ( ! class_exists( 'MAD4B_SCP_Write_Runtime_Certification' ) || MAD4B_SCP_Write_Runtime_Certification::OPTION !== (string) $option ) return;
		self::record_freshness( $value );
	}

	public static function record_updated_certification_freshness( $option, $old_value, $value ) {
		if ( ! class_exists( 'MAD4B_SCP_Write_Runtime_Certification' ) || MAD4B_SCP_Write_Runtime_Certification::OPTION !== (string) $option ) return;
		self::record_freshness( $value );
	}

	private static function record_freshness( $value ) {
		if ( ! self::$full_boot_seen || ! is_array( $value ) ) return;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) return;
		$identity = self::inventory_identity();
		update_option( self::FRESHNESS_OPTION, array(
			'contract' => 'mad4b.write-runtime-certification-freshness.v1',
			'control_plane_version' => isset( $identity['control_plane_version'] ) ? $identity['control_plane_version'] : '',
			'write_tool_count' => isset( $identity['write_tool_count'] ) ? (int) $identity['write_tool_count'] : 0,
			'write_inventory_fingerprint' => isset( $identity['write_inventory_fingerprint'] ) ? $identity['write_inventory_fingerprint'] : '',
			'provider_blocked_fingerprint' => isset( $identity['provider_blocked_fingerprint'] ) ? $identity['provider_blocked_fingerprint'] : '',
			'evidence_digest' => isset( $value['evidence_digest'] ) ? (string) $value['evidence_digest'] : '',
			'observed_at' => isset( $value['observed_at'] ) ? (string) $value['observed_at'] : gmdate( 'c' ),
		), false );
	}

	public static function refresh_admin_certification() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		$skills = 'mad4b-control-plane-skills' === $page;
		$certification = 'mad4b-control-plane-connection' === $page && 'certification' === $tab;
		if ( ! $skills && ! $certification ) return;
		if ( function_exists( 'wp_get_abilities' ) ) wp_get_abilities();
		self::recover_runtime_authority();
		if ( class_exists( 'MAD4B_SCP_Write_Runtime_Certification' ) ) MAD4B_SCP_Write_Runtime_Certification::observe();
	}

	private static function stale_reasons( array $value, $freshness, array $identity ) {
		$reasons = array();
		if ( ! is_array( $freshness ) || empty( $freshness ) ) return array( 'freshness_metadata_missing' );
		$current_version = isset( $identity['control_plane_version'] ) ? (string) $identity['control_plane_version'] : '';
		$current_write = isset( $identity['write_inventory_fingerprint'] ) ? (string) $identity['write_inventory_fingerprint'] : '';
		$current_blocked = isset( $identity['provider_blocked_fingerprint'] ) ? (string) $identity['provider_blocked_fingerprint'] : '';
		if ( ! isset( $freshness['control_plane_version'] ) || ! hash_equals( $current_version, (string) $freshness['control_plane_version'] ) ) $reasons[] = 'control_plane_version_changed';
		if ( ! isset( $freshness['write_inventory_fingerprint'] ) || ! hash_equals( $current_write, (string) $freshness['write_inventory_fingerprint'] ) ) $reasons[] = 'write_inventory_changed';
		if ( ! isset( $freshness['provider_blocked_fingerprint'] ) || ! hash_equals( $current_blocked, (string) $freshness['provider_blocked_fingerprint'] ) ) $reasons[] = 'provider_blocked_projection_changed';
		if ( ! isset( $value['write_tool_count'] ) || (int) $value['write_tool_count'] !== (int) $identity['write_tool_count'] ) $reasons[] = 'write_tool_count_changed';
		if ( isset( $freshness['evidence_digest'], $value['evidence_digest'] ) && '' !== (string) $freshness['evidence_digest'] && ! hash_equals( (string) $freshness['evidence_digest'], (string) $value['evidence_digest'] ) ) $reasons[] = 'persisted_evidence_digest_changed';
		return array_values( array_unique( $reasons ) );
	}

	private static function inventory_identity() {
		$tools = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::write_tools() : array();
		$tools = array_values( array_unique( array_map( 'strval', is_array( $tools ) ? $tools : array() ) ) );
		sort( $tools );
		$write_rows = array();
		foreach ( $tools as $ability ) {
			$provider = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability ) : null;
			$write_rows[] = array( 'ability' => $ability, 'provider' => null === $provider ? '' : (string) $provider );
		}

		$blocked = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::blocked_write_tools() : array();
		$blocked_rows = array();
		foreach ( is_array( $blocked ) ? $blocked : array() as $item ) {
			$violations = isset( $item['violations'] ) && is_array( $item['violations'] ) ? array_values( array_unique( array_map( 'strval', $item['violations'] ) ) ) : array();
			sort( $violations );
			$blocked_rows[] = array(
				'ability' => isset( $item['ability'] ) ? (string) $item['ability'] : '',
				'provider' => isset( $item['provider'] ) ? (string) $item['provider'] : '',
				'reason' => isset( $item['reason'] ) ? (string) $item['reason'] : '',
				'runtime_status' => isset( $item['runtime_status'] ) ? (string) $item['runtime_status'] : '',
				'installed_version' => isset( $item['installed_version'] ) ? (string) $item['installed_version'] : '',
				'certified_version' => isset( $item['certified_version'] ) ? (string) $item['certified_version'] : '',
				'violations' => $violations,
			);
		}
		usort( $blocked_rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );
		$version = defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '';
		return array(
			'control_plane_version' => $version,
			'write_tool_count' => count( $tools ),
			'write_tools' => $tools,
			'write_inventory_fingerprint' => hash( 'sha256', wp_json_encode( $write_rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'provider_blocked_write_tool_count' => count( $blocked_rows ),
			'provider_blocked_write_tools' => $blocked,
			'provider_blocked_fingerprint' => hash( 'sha256', wp_json_encode( $blocked_rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
		);
	}

	private static function local_rest_isolation() {
		$rest_enabled = (bool) apply_filters( 'rest_enabled', true );
		$on_enabled = self::control_plane_hook_present( 'rest_enabled' );
		$on_auth = self::control_plane_hook_present( 'rest_authentication_errors' );
		$scope = array(
			'/mcp/mad4b-read',
			'/mcp/mad4b-chatgpt',
			'/mcp/mad4b-content',
			'/mcp/mad4b-write',
			'/mcp/mad4b-admin',
			'/mcp/mad4b-breakglass',
		);
		return array(
			'contract' => 'mad4b.rest-local-isolation.v1',
			'ready' => $rest_enabled && ! $on_enabled && ! $on_auth,
			'rest_enabled' => $rest_enabled,
			'control_plane_filters_rest_enabled' => $on_enabled,
			'control_plane_filters_rest_authentication_errors' => $on_auth,
			'control_plane_rest_pre_dispatch_scope' => $scope,
			'mcp_recovery_scoped_to_mad4b_routes' => true,
			'wpml_route_required_for_local_certification' => false,
			'external_wpml_acceptance_required' => true,
			'external_http_probe_performed' => false,
		);
	}

	private static function control_plane_hook_present( $hook_name ) {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook_name ] ) || ! is_object( $wp_filter[ $hook_name ] ) || ! isset( $wp_filter[ $hook_name ]->callbacks ) || ! is_array( $wp_filter[ $hook_name ]->callbacks ) ) return false;
		foreach ( $wp_filter[ $hook_name ]->callbacks as $callbacks ) {
			if ( ! is_array( $callbacks ) ) continue;
			foreach ( $callbacks as $entry ) {
				$callable = is_array( $entry ) && isset( $entry['function'] ) ? $entry['function'] : null;
				$class = '';
				if ( is_array( $callable ) && isset( $callable[0] ) ) $class = is_object( $callable[0] ) ? get_class( $callable[0] ) : (string) $callable[0];
				elseif ( is_string( $callable ) ) $class = $callable;
				if ( 0 === strpos( $class, 'MAD4B_SCP_' ) ) return true;
			}
		}
		return false;
	}

	private static function external_wpml_test_url() {
		if ( ! function_exists( 'rest_url' ) ) return '';
		return add_query_arg(
			array( 'test_get_parameter' => '1', 'cachebuster' => (string) time() ),
			untrailingslashit( rest_url( 'wpml/v1/rest/status' ) )
		);
	}
}
