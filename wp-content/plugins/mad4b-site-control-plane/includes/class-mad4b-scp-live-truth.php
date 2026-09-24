<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Fresh read-only truth for the tenant-bound governed write plane.
 *
 * Installation is not authority. Read abilities never mutate state merely to
 * answer a status question. Runtime eligibility is derived from the exact
 * enrolled Site Profile and the governed-write authority rather than a product-
 * specific hostname. The legacy Staging class names remain compatibility names
 * only; tenant identity is site_uuid + profile revision/digest + exact origin.
 */
final class MAD4B_SCP_Live_Truth {
	const CONTRACT = 'mad4b.live-truth.v2';
	const FRESHNESS_OPTION = 'mad4b_scp_write_runtime_certification_freshness_v1';

	private static $booted = false;
	private static $full_boot_seen = false;
	private static $abilities_materialized = false;
	private static $recovering = false;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;

		add_filter( 'wp_register_ability_args', array( __CLASS__, 'bind_live_read_callbacks' ), 100, 2 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'abilities_materialized' ), PHP_INT_MAX );
		add_action( 'init', array( __CLASS__, 'after_full_boot' ), -999999 );

		if ( class_exists( 'MAD4B_SCP_Write_Runtime_Certification' ) ) {
			add_filter( 'option_' . MAD4B_SCP_Write_Runtime_Certification::OPTION, array( __CLASS__, 'filter_persisted_certification' ), 100 );
		}
		add_action( 'added_option', array( __CLASS__, 'record_added_certification_freshness' ), 100, 2 );
		add_action( 'updated_option', array( __CLASS__, 'record_updated_certification_freshness' ), 100, 3 );
		add_action( 'admin_init', array( __CLASS__, 'refresh_admin_certification' ), 0 );
	}

	public static function bind_live_read_callbacks( $args, $name ) {
		if ( ! is_array( $args ) ) return $args;
		$name = (string) $name;
		if ( 'mad4b/write-authority-status' === $name ) $args['execute_callback'] = array( __CLASS__, 'current_authority_status' );
		if ( 'mad4b/write-runtime-certification' === $name ) $args['execute_callback'] = array( __CLASS__, 'current_write_certification' );
		if ( 'mad4b/rest-compatibility-status' === $name ) $args['execute_callback'] = array( __CLASS__, 'current_rest_compatibility' );
		return $args;
	}

	public static function abilities_materialized() {
		self::$abilities_materialized = true;
		if ( self::$full_boot_seen ) self::recover_runtime_authority();
	}

	public static function after_full_boot() {
		self::$full_boot_seen = true;
		if ( self::$abilities_materialized || did_action( 'wp_abilities_api_init' ) > 0 ) self::recover_runtime_authority();
	}

	private static function recover_runtime_authority() {
		if ( self::$recovering ) return;
		// Merely materializing the Abilities registry for MCP initialize/tools-list
		// must not trigger full authority/grant/provider reconciliation reads.
		// Explicit status/certification tool calls still execute current truth.
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
			&& method_exists( 'MAD4B_SCP_MCP_Request_Scope', 'current_request_is_protocol_hotpath' )
			&& MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) return;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) return;
		self::$recovering = true;
		// Runtime recovery means refreshing truth, never reconciling grants/subjects.
		self::current_authority_status();
		self::$recovering = false;
	}

	public static function current_authority_status() {
		$profile_available = class_exists( 'MAD4B_SCP_Site_Profile' );
		$environment = $profile_available ? MAD4B_SCP_Site_Profile::current_environment() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );
		$origin = $profile_available ? MAD4B_SCP_Site_Profile::current_origin() : ( function_exists( 'home_url' ) ? untrailingslashit( (string) home_url( '/' ) ) : '' );
		$parts = '' !== $origin && function_exists( 'wp_parse_url' ) ? wp_parse_url( $origin ) : parse_url( $origin );
		$host = is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( rtrim( (string) $parts['host'], '.' ) ) : '';

		$profile_configured = $profile_available && MAD4B_SCP_Site_Profile::configured();
		$profile_origin_enrolled = $profile_available && MAD4B_SCP_Site_Profile::origin_enrolled();
		$profile_site_urls_match = $profile_available && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
		$exact_profile_bound = $profile_origin_enrolled && $profile_site_urls_match;
		$profile_write_enabled = $profile_available && MAD4B_SCP_Site_Profile::write_enabled();
		$site_uuid = $profile_available ? MAD4B_SCP_Site_Profile::site_uuid() : '';
		$profile_revision = $profile_available ? MAD4B_SCP_Site_Profile::revision() : 0;
		$profile_digest = $profile_available ? MAD4B_SCP_Site_Profile::profile_digest() : '';
		$eligible = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::eligible();
		$inventory = self::inventory_identity();
		$blockers = array();
		$grant_blockers = array();

		if ( ! $eligible ) {
			if ( ! $profile_configured ) $blockers[] = 'site_profile_unconfigured';
			elseif ( ! $profile_origin_enrolled ) $blockers[] = 'site_profile_not_enrolled';
			elseif ( ! $profile_write_enabled ) $blockers[] = 'site_profile_write_disabled';
			else $blockers[] = 'governed_write_authority_ineligible';
		}

		$mutation_gate = defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true === constant( 'MAD4B_MCP_MUTATION_ENABLED' );
		if ( $eligible && ! $mutation_gate ) $blockers[] = 'mutation_gate_disabled';
		$schema_ready = class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::is_ready();
		if ( $eligible && ! $schema_ready ) $blockers[] = 'governance_schema_unavailable';
		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( $eligible && empty( $audit['ready'] ) ) $blockers[] = 'audit_unavailable';

		$oauth = class_exists( 'MAD4B_SCP_Staging_OAuth_Autoconfig' ) ? MAD4B_SCP_Staging_OAuth_Autoconfig::status() : array();
		$user_ids = isset( $oauth['oauth_user_ids'] ) && is_array( $oauth['oauth_user_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', $oauth['oauth_user_ids'] ) ) ) ) : array();
		$owner_user_id = isset( $oauth['primary_owner_user_id'] ) ? absint( $oauth['primary_owner_user_id'] ) : ( isset( $oauth['wp_user_id'] ) ? absint( $oauth['wp_user_id'] ) : 0 );
		if ( empty( $user_ids ) && $owner_user_id > 0 ) $user_ids = array( $owner_user_id );
		$issuer = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? rtrim( (string) MAD4B_SCP_Local_OAuth_Server::issuer(), '/' ) : '';
		if ( $eligible && ( empty( $oauth['configured'] ) || empty( $user_ids ) || '' === $issuer ) ) $blockers[] = 'oauth_subject_unavailable';
		$owner = $owner_user_id > 0 ? get_userdata( $owner_user_id ) : null;
		if ( $eligible && $owner_user_id > 0 && ( ! $owner || ! user_can( $owner, 'manage_options' ) ) ) $blockers[] = 'oauth_trust_owner_not_admin';

		$agent = null;
		$agent_slug = $profile_available ? MAD4B_SCP_Site_Profile::agent_slug() : 'chatgpt-governed-write';
		if ( $eligible && $schema_ready ) {
			global $wpdb;
			$tables = MAD4B_SCP_Schema::tables();
			$agent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['agents']} WHERE slug = %s LIMIT 1", $agent_slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( ! $agent ) $blockers[] = 'governed_write_agent_missing';
		}
		if ( is_array( $agent ) ) {
			if ( 'enabled' !== (string) $agent['status'] ) $blockers[] = 'governed_write_agent_disabled';
			if ( $environment !== (string) $agent['environment'] ) $blockers[] = 'governed_write_agent_environment_mismatch';
			if ( $owner_user_id > 0 && (int) $agent['wp_user_id'] !== $owner_user_id ) $blockers[] = 'governed_write_agent_user_mismatch';
		}

		$subject_fingerprints = array();
		$subject_blockers = array();
		if ( is_array( $agent ) && '' !== $issuer && ! empty( $user_ids ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			foreach ( $user_ids as $user_id ) {
				$fingerprint = hash( 'sha256', 'oauth' . "\0" . $issuer . "\0" . 'user:' . $user_id );
				$subject_fingerprints[] = $fingerprint;
				$bound = MAD4B_SCP_Agent_Registry::resolve_agent( array(
					'authenticated' => true,
					'subject_type' => 'oauth',
					'subject_fingerprint' => $fingerprint,
				) );
				if ( is_wp_error( $bound ) ) $subject_blockers[] = $bound->get_error_code() . ':user:' . $user_id;
				elseif ( (int) $bound['id'] !== (int) $agent['id'] ) $subject_blockers[] = 'oauth_subject_bound_to_other_agent:user:' . $user_id;
			}
		}
		if ( $eligible && ! empty( $subject_blockers ) ) $blockers[] = 'oauth_subject_binding_incomplete';

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
		if ( $eligible && ! empty( $grant_blockers ) ) $blockers[] = 'grant_reconciliation_incomplete';

		$counts = class_exists( 'MAD4B_SCP_Agent_Registry' ) && $schema_ready ? MAD4B_SCP_Agent_Registry::counts() : array();
		$wildcards = isset( $counts['wildcard_grants'] ) ? (int) $counts['wildcard_grants'] : 0;
		if ( $wildcards > 0 ) $blockers[] = 'wildcard_grants_detected';
		$breakglass = in_array( 'mad4b/database-raw-query', $tools, true );
		if ( $breakglass ) $blockers[] = 'breakglass_leak';

		$runtime = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::status() : array();
		$candidate_binding = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_binding_status' )
			? MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()
			: array( 'required' => false, 'match' => true );
		$candidate_reconciled = empty( $candidate_binding['required'] ) || ! empty( $candidate_binding['match'] );
		$candidate_bootstrap = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && defined( 'MAD4B_SCP_Staging_Write_Authority::CANDIDATE_BOOTSTRAP_ABILITY' )
			? MAD4B_SCP_Staging_Write_Authority::candidate_bootstrap_status( MAD4B_SCP_Staging_Write_Authority::CANDIDATE_BOOTSTRAP_ABILITY )
			: array();
		$candidate_bootstrap_exception_active = ! $candidate_reconciled && ! empty( $candidate_bootstrap['policy_available'] );
		$approval_policy = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'approval_policy_projection' )
			? MAD4B_SCP_Staging_Write_Authority::approval_policy_projection( $candidate_bootstrap_exception_active )
			: array(
				'all_remote_writes_require_exact_approval' => ! $candidate_bootstrap_exception_active,
				'normal_remote_writes_require_exact_approval' => true,
				'remote_write_approval_policy' => $candidate_bootstrap_exception_active ? 'exact_approval_except_bounded_candidate_bootstrap' : 'exact_approval_required',
				'remote_write_approval_exceptions' => $candidate_bootstrap_exception_active ? array( MAD4B_SCP_Staging_Write_Authority::CANDIDATE_BOOTSTRAP_ABILITY ) : array(),
			);
		$runtime_reconciled = ! empty( $runtime['ready'] )
			&& empty( $runtime['blocker'] )
			&& isset( $runtime['write_tool_count'] )
			&& (int) $runtime['write_tool_count'] === count( $tools )
			&& isset( $runtime['write_inventory_fingerprint'], $inventory['write_inventory_fingerprint'] )
			&& '' !== (string) $inventory['write_inventory_fingerprint']
			&& hash_equals( (string) $inventory['write_inventory_fingerprint'], (string) $runtime['write_inventory_fingerprint'] )
			&& $candidate_reconciled;
		if ( $eligible && ! $candidate_reconciled ) $blockers[] = 'runtime_authority_candidate_not_reconciled';
		if ( $eligible && ! $runtime_reconciled ) $blockers[] = 'runtime_authority_not_reconciled';

		$blockers = array_values( array_unique( array_filter( array_map( 'strval', $blockers ) ) ) );
		$ready = $eligible && empty( $blockers );
		$bootstrap_closure_required = ! empty( $candidate_binding['required'] );
		$bootstrap_closure_closed = ! $bootstrap_closure_required || ( $candidate_reconciled && $runtime_reconciled && $ready );
		if ( ! $bootstrap_closure_required ) $bootstrap_closure_state = 'not_required';
		elseif ( $bootstrap_closure_closed ) $bootstrap_closure_state = 'closed';
		elseif ( $candidate_bootstrap_exception_active ) $bootstrap_closure_state = 'acceptance_target_provision_required';
		elseif ( ! $candidate_reconciled ) $bootstrap_closure_state = 'candidate_reconciliation_required';
		else $bootstrap_closure_state = 'authority_reconciliation_required';
		$candidate_bootstrap_closure = array(
			'contract' => 'mad4b.governed-write-candidate-bootstrap-closure.v1',
			'required' => $bootstrap_closure_required,
			'sequence' => array( 'mad4b/acceptance-target-provision', 'mad4b/staging-write-grant-reconcile' ),
			'required_postconditions' => array( 'candidate_binding_match', 'runtime_reconciled', 'write_authority_ready' ),
			'candidate_binding_match' => ! empty( $candidate_binding['match'] ),
			'runtime_reconciled' => $runtime_reconciled,
			'write_authority_ready' => $ready,
			'closed' => $bootstrap_closure_closed,
			'state' => $bootstrap_closure_state,
			'atomic_single_mutation' => false,
			'retry_provider_mutation_on_reconciliation_failure' => false,
		);
		return array(
			'contract' => class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::CONTRACT : 'mad4b.governed-write-authority.v2',
			'truth_contract' => self::CONTRACT,
			'environment' => $environment,
			'origin' => $origin,
			'host' => $host,
			'site_uuid' => $site_uuid,
			'site_profile_revision' => $profile_revision,
			'site_profile_digest' => $profile_digest,
			'profile_configured' => $profile_configured,
			'profile_origin_enrolled' => $profile_origin_enrolled,
			'profile_site_urls_match' => $profile_site_urls_match,
			'exact_profile_bound' => $exact_profile_bound,
			'profile_write_enabled' => $profile_write_enabled,
			'eligible' => $eligible,
			'ready' => $ready,
			'state' => $eligible ? ( $ready ? 'ready' : 'blocked' ) : 'ineligible',
			'blocker' => empty( $blockers ) ? '' : $blockers[0],
			'blockers' => $blockers,
			'mutation_gate_configured' => $mutation_gate,
			'configuration_source' => $eligible ? 'site_profile' : 'none',
			'agent_slug' => $agent_slug,
			'agent_public_id' => is_array( $agent ) && isset( $agent['public_id'] ) ? (string) $agent['public_id'] : '',
			'primary_owner_user_id' => $owner_user_id,
			'oauth_user_ids' => $user_ids,
			'subject_fingerprint_prefix' => ! empty( $subject_fingerprints ) ? substr( (string) $subject_fingerprints[0], 0, 16 ) : '',
			'subject_binding_blockers' => $subject_blockers,
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
			'approval_policy_contract' => isset( $approval_policy['approval_policy_contract'] ) ? (string) $approval_policy['approval_policy_contract'] : '',
			'approval_policy_scope' => isset( $approval_policy['approval_policy_scope'] ) ? (string) $approval_policy['approval_policy_scope'] : 'effective_runtime',
			'approval_policy_effective_state_resolved' => true,
			'all_remote_writes_require_exact_approval' => ! empty( $approval_policy['all_remote_writes_require_exact_approval'] ),
			'normal_remote_writes_require_exact_approval' => ! empty( $approval_policy['normal_remote_writes_require_exact_approval'] ),
			'remote_write_approval_policy' => isset( $approval_policy['remote_write_approval_policy'] ) ? (string) $approval_policy['remote_write_approval_policy'] : '',
			'remote_write_approval_exceptions' => isset( $approval_policy['remote_write_approval_exceptions'] ) ? (array) $approval_policy['remote_write_approval_exceptions'] : array(),
			'candidate_bootstrap_exception_defined' => ! empty( $approval_policy['candidate_bootstrap_exception_defined'] ),
			'candidate_bootstrap_exception_active' => $candidate_bootstrap_exception_active,
			'candidate_bootstrap_contract' => isset( $candidate_bootstrap['contract'] ) ? (string) $candidate_bootstrap['contract'] : '',
			'candidate_bootstrap_closure' => $candidate_bootstrap_closure,
			'remote_transport' => 'mad4b-chatgpt',
			'authority_server' => 'mad4b-write',
			'oauth_role' => 'identity_only',
			'runtime_reconciled' => $runtime_reconciled,
			'runtime_state' => isset( $runtime['state'] ) ? (string) $runtime['state'] : 'unknown',
			'candidate_binding_required' => ! empty( $candidate_binding['required'] ),
			'candidate_binding_match' => ! empty( $candidate_binding['match'] ),
			'candidate_binding_contract' => isset( $candidate_binding['contract'] ) ? (string) $candidate_binding['contract'] : '',
			'candidate_source_commit_sha' => isset( $candidate_binding['stored_source_commit_sha'] ) ? (string) $candidate_binding['stored_source_commit_sha'] : '',
			'candidate_build_fingerprint' => isset( $candidate_binding['stored_build_fingerprint'] ) ? (string) $candidate_binding['stored_build_fingerprint'] : '',
			'candidate_package_manifest_digest' => isset( $candidate_binding['stored_package_manifest_digest'] ) ? (string) $candidate_binding['stored_package_manifest_digest'] : '',
			'candidate_artifact_identity' => isset( $candidate_binding['stored_artifact_identity'] ) ? (string) $candidate_binding['stored_artifact_identity'] : '',
			'current_source_commit_sha' => isset( $candidate_binding['current_source_commit_sha'] ) ? (string) $candidate_binding['current_source_commit_sha'] : '',
			'current_build_fingerprint' => isset( $candidate_binding['current_build_fingerprint'] ) ? (string) $candidate_binding['current_build_fingerprint'] : '',
			'current_package_manifest_digest' => isset( $candidate_binding['current_package_manifest_digest'] ) ? (string) $candidate_binding['current_package_manifest_digest'] : '',
			'current_artifact_identity' => isset( $candidate_binding['current_artifact_identity'] ) ? (string) $candidate_binding['current_artifact_identity'] : '',
			'candidate_binding_fingerprint' => self::candidate_binding_fingerprint( $candidate_binding ),
			'control_plane_version' => isset( $inventory['control_plane_version'] ) ? $inventory['control_plane_version'] : '',
			'write_inventory_fingerprint' => isset( $inventory['write_inventory_fingerprint'] ) ? $inventory['write_inventory_fingerprint'] : '',
			'provider_blocked_fingerprint' => isset( $inventory['provider_blocked_fingerprint'] ) ? $inventory['provider_blocked_fingerprint'] : '',
			'provider_blocked_write_tool_count' => isset( $inventory['provider_blocked_write_tool_count'] ) ? $inventory['provider_blocked_write_tool_count'] : 0,
			'provider_blocked_write_tools' => isset( $inventory['provider_blocked_write_tools'] ) && is_array( $inventory['provider_blocked_write_tools'] ) ? $inventory['provider_blocked_write_tools'] : array(),
			'inspection_source' => 'live_read_only',
			'persists_changes' => false,
			'observed_at' => gmdate( 'c' ),
		);
	}

	public static function current_write_certification() {
		$authority = self::current_authority_status();
		// Reuse the exact inventory observed by current_authority_status(). This
		// avoids recomputing provider/write projection twice in one read while
		// deliberately avoiding cross-call memoization that could become stale
		// after an in-request governed mutation.
		$inventory = array(
			'control_plane_version' => isset( $authority['control_plane_version'] ) ? (string) $authority['control_plane_version'] : '',
			'write_tools' => isset( $authority['write_tools'] ) && is_array( $authority['write_tools'] ) ? $authority['write_tools'] : array(),
			'write_inventory_fingerprint' => isset( $authority['write_inventory_fingerprint'] ) ? (string) $authority['write_inventory_fingerprint'] : '',
			'provider_blocked_write_tool_count' => isset( $authority['provider_blocked_write_tool_count'] ) ? (int) $authority['provider_blocked_write_tool_count'] : 0,
			'provider_blocked_write_tools' => isset( $authority['provider_blocked_write_tools'] ) && is_array( $authority['provider_blocked_write_tools'] ) ? $authority['provider_blocked_write_tools'] : array(),
			'provider_blocked_fingerprint' => isset( $authority['provider_blocked_fingerprint'] ) ? (string) $authority['provider_blocked_fingerprint'] : '',
		);
		$candidate_binding = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_binding_status' )
			? MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()
			: array( 'required' => false, 'match' => true );
		$binding_fingerprint = self::candidate_binding_fingerprint( $candidate_binding );
		$package_identity_complete = empty( $candidate_binding['required'] ) || (
			isset( $candidate_binding['current_source_commit_sha'], $candidate_binding['current_build_fingerprint'], $candidate_binding['current_package_manifest_digest'], $candidate_binding['current_artifact_identity'] )
			&& 1 === preg_match( '/^[a-f0-9]{40}$/', (string) $candidate_binding['current_source_commit_sha'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $candidate_binding['current_build_fingerprint'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $candidate_binding['current_package_manifest_digest'] )
			&& 1 === preg_match( '/^mad4b-site-control-plane-general-distribution-kit-[a-f0-9]{40}$/', (string) $candidate_binding['current_artifact_identity'] )
		);
		$tools = isset( $inventory['write_tools'] ) ? $inventory['write_tools'] : array();
		$provider_blocked = isset( $inventory['provider_blocked_write_tools'] ) ? $inventory['provider_blocked_write_tools'] : array();
		$checks = array();
		$blockers = array();

		$checks['site_profile_bound'] = ! empty( $authority['exact_profile_bound'] );
		// Compatibility alias retained while older evidence consumers migrate.
		$checks['exact_staging_origin'] = $checks['site_profile_bound'];
		$checks['write_feature_enabled'] = ! empty( $authority['profile_write_enabled'] );
		$checks['write_authority_eligible'] = ! empty( $authority['eligible'] );
		$checks['authority_ready'] = ! empty( $authority['ready'] );
		$checks['mutation_gate_enabled'] = ! empty( $authority['mutation_gate_configured'] );
		$checks['production_auto_enable_absent'] = empty( $authority['production_auto_enable'] );
		$checks['breakglass_auto_enable_absent'] = empty( $authority['breakglass_auto_enable'] );
		$checks['breakglass_not_included'] = empty( $authority['breakglass_included'] );
		$checks['normal_remote_approval_required'] = ! empty( $authority['normal_remote_writes_require_exact_approval'] );
		$checks['package_identity_complete'] = $package_identity_complete;
		$checks['candidate_binding_current'] = empty( $candidate_binding['required'] ) || ! empty( $candidate_binding['match'] );
		$exceptions = isset( $authority['remote_write_approval_exceptions'] ) && is_array( $authority['remote_write_approval_exceptions'] ) ? array_values( $authority['remote_write_approval_exceptions'] ) : array();
		$checks['candidate_bootstrap_exception_bounded'] = empty( $exceptions ) || ( 1 === count( $exceptions ) && class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::CANDIDATE_BOOTSTRAP_ABILITY === (string) $exceptions[0] );
		foreach ( array( 'site_profile_bound', 'write_feature_enabled', 'write_authority_eligible', 'authority_ready', 'mutation_gate_enabled', 'production_auto_enable_absent', 'breakglass_auto_enable_absent', 'breakglass_not_included', 'normal_remote_approval_required', 'package_identity_complete', 'candidate_binding_current', 'candidate_bootstrap_exception_bounded' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

		$oauth = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		$checks['oauth_effective'] = ! empty( $oauth['effective'] );
		$checks['oauth_resource_is_chatgpt'] = isset( $oauth['resource'] ) && false !== strpos( (string) $oauth['resource'], '/wp-json/mcp/mad4b-chatgpt' );
		$checks['oauth_does_not_create_write_authority'] = empty( $oauth['write_surfaces_enabled'] );
		foreach ( array( 'oauth_effective', 'oauth_resource_is_chatgpt', 'oauth_does_not_create_write_authority' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

		$missing_write_mounts = array();
		// Legacy compatibility alias retained in the response contract. Direct
		// remote write schemas are intentionally hidden behind write-execute, so
		// there is no separate remote mount inventory to populate.
		$missing_remote_mounts = array();
		$direct_write_schema_leaks = array();
		$write_transport_tools = array( 'mad4b/write-discover', 'mad4b/write-info', 'mad4b/write-execute' );
		$missing_write_transport = array();
		foreach ( $write_transport_tools as $transport_ability ) {
			if ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $transport_ability ) ) $missing_write_transport[] = $transport_ability;
		}
		$metadata_mismatch = array();
		$breakglass = array();
		foreach ( $tools as $ability_name ) {
			if ( 'mad4b/database-raw-query' === $ability_name ) $breakglass[] = $ability_name;
			if ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability_name ) ) $missing_write_mounts[] = $ability_name;
			if ( class_exists( 'MAD4B_SCP_Servers' ) && MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $ability_name ) ) $direct_write_schema_leaks[] = $ability_name;
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
			if ( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability_name ) ) $provider_blocked_mount_leaks[] = $ability_name;
		}
		$checks['write_inventory_nonempty'] = ! empty( $tools );
		$checks['all_write_tools_mounted_on_authority'] = empty( $missing_write_mounts );
		$checks['write_dispatch_transport_available'] = empty( $missing_write_transport );
		$checks['direct_write_schemas_hidden_from_chatgpt'] = empty( $direct_write_schema_leaks );
		// Compatibility alias: "same plugin transport" now means the logical
		// write inventory is reachable through the exact-target dispatcher on the
		// same ChatGPT MCP resource, without enumerating target schemas directly.
		$checks['all_write_tools_exposed_on_same_plugin_transport'] = $checks['write_dispatch_transport_available'] && $checks['direct_write_schemas_hidden_from_chatgpt'];
		$checks['all_write_tools_annotated_mutating'] = empty( $metadata_mismatch );
		$checks['provider_uncertified_write_tools_safely_unmounted'] = empty( $provider_blocked_mount_leaks );
		$checks['breakglass_absent_from_write_inventory'] = empty( $breakglass );
		foreach ( array( 'write_inventory_nonempty', 'all_write_tools_mounted_on_authority', 'write_dispatch_transport_available', 'direct_write_schemas_hidden_from_chatgpt', 'all_write_tools_exposed_on_same_plugin_transport', 'all_write_tools_annotated_mutating', 'provider_uncertified_write_tools_safely_unmounted', 'breakglass_absent_from_write_inventory' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

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
		$checks['approval_planner_self_agent_only'] = isset( $planner['target_agent'] ) && in_array( (string) $planner['target_agent'], array( 'dedicated_staging_write_agent_only', 'dedicated_governed_write_agent_only' ), true );
		$checks['approval_planner_write_server_only'] = isset( $planner['target_server'] ) && 'mad4b-write' === $planner['target_server'];
		$checks['approval_planner_mutation_class_only'] = isset( $planner['target_ticket_class'] ) && 'mutation' === $planner['target_ticket_class'];
		$checks['approval_planner_breakglass_denied'] = isset( $planner['breakglass_target_allowed'] ) && false === $planner['breakglass_target_allowed'];
		foreach ( array( 'approval_planner_in_write_inventory', 'approval_planner_governed', 'approval_planner_nhi_required', 'approval_planner_exact_grant_required', 'approval_planner_budgeted', 'approval_planner_no_prior_ticket', 'approval_planner_self_agent_only', 'approval_planner_write_server_only', 'approval_planner_mutation_class_only', 'approval_planner_breakglass_denied' ) as $key ) if ( empty( $checks[ $key ] ) ) $blockers[] = $key;

		$counts = class_exists( 'MAD4B_SCP_Agent_Registry' ) ? MAD4B_SCP_Agent_Registry::counts() : array();
		$checks['no_wildcard_grants'] = empty( $counts['wildcard_grants'] );
		if ( ! $checks['no_wildcard_grants'] ) $blockers[] = 'wildcard_grants_detected';

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
			'missing_write_transport' => $missing_write_transport,
			'direct_write_schema_leaks' => $direct_write_schema_leaks,
			'metadata_mismatch' => $metadata_mismatch,
			'breakglass' => $breakglass,
			'local_rest_isolation' => $rest,
			'control_plane_version' => isset( $inventory['control_plane_version'] ) ? $inventory['control_plane_version'] : '',
			'write_inventory_fingerprint' => isset( $inventory['write_inventory_fingerprint'] ) ? $inventory['write_inventory_fingerprint'] : '',
			'provider_blocked_fingerprint' => isset( $inventory['provider_blocked_fingerprint'] ) ? $inventory['provider_blocked_fingerprint'] : '',
			'evidence_schema_revision' => 3,
			'candidate_binding' => $candidate_binding,
			'candidate_binding_fingerprint' => $binding_fingerprint,
		);
		$digest = hash( 'sha256', wp_json_encode( $evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		return array(
			'contract' => class_exists( 'MAD4B_SCP_Write_Runtime_Certification' ) ? MAD4B_SCP_Write_Runtime_Certification::CONTRACT : 'mad4b.write-runtime-certification.v3',
			'truth_contract' => self::CONTRACT,
			'current_truth' => true,
			'evidence_schema_revision' => 3,
			'source_commit_sha' => isset( $candidate_binding['current_source_commit_sha'] ) ? (string) $candidate_binding['current_source_commit_sha'] : '',
			'build_fingerprint' => isset( $candidate_binding['current_build_fingerprint'] ) ? (string) $candidate_binding['current_build_fingerprint'] : '',
			'package_manifest_digest' => isset( $candidate_binding['current_package_manifest_digest'] ) ? (string) $candidate_binding['current_package_manifest_digest'] : '',
			'artifact_identity' => isset( $candidate_binding['current_artifact_identity'] ) ? (string) $candidate_binding['current_artifact_identity'] : '',
			'candidate_binding_fingerprint' => $binding_fingerprint,
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
			'exact_approval_required_for_remote_write' => empty( $exceptions ),
			'normal_remote_write_exact_approval_required' => ! empty( $authority['normal_remote_writes_require_exact_approval'] ),
			'candidate_bootstrap_prior_approval_exception' => ! empty( $authority['candidate_bootstrap_exception_active'] ) && class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::CANDIDATE_BOOTSTRAP_ABILITY : '',
			'candidate_bootstrap_closure' => isset( $authority['candidate_bootstrap_closure'] ) && is_array( $authority['candidate_bootstrap_closure'] ) ? $authority['candidate_bootstrap_closure'] : array(),
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
			'external_client_action' => 'Run deployment-specific external acceptance, then Refresh/Scan Tools in the same Plugin and verify this exact write inventory externally before merge.',
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
			'contract' => class_exists( 'MAD4B_SCP_Write_Runtime_Certification' ) ? MAD4B_SCP_Write_Runtime_Certification::CONTRACT : 'mad4b.write-runtime-certification.v3',
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
		$candidate_binding = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_binding_status' )
			? MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()
			: array();
		update_option( self::FRESHNESS_OPTION, array(
			'contract' => 'mad4b.write-runtime-certification-freshness.v2',
			'evidence_schema_revision' => 3,
			'source_commit_sha' => isset( $candidate_binding['current_source_commit_sha'] ) ? (string) $candidate_binding['current_source_commit_sha'] : '',
			'build_fingerprint' => isset( $candidate_binding['current_build_fingerprint'] ) ? (string) $candidate_binding['current_build_fingerprint'] : '',
			'package_manifest_digest' => isset( $candidate_binding['current_package_manifest_digest'] ) ? (string) $candidate_binding['current_package_manifest_digest'] : '',
			'artifact_identity' => isset( $candidate_binding['current_artifact_identity'] ) ? (string) $candidate_binding['current_artifact_identity'] : '',
			'candidate_binding_fingerprint' => self::candidate_binding_fingerprint( $candidate_binding ),
			'control_plane_version' => isset( $identity['control_plane_version'] ) ? $identity['control_plane_version'] : '',
			'write_tool_count' => isset( $identity['write_tool_count'] ) ? (int) $identity['write_tool_count'] : 0,
			'write_inventory_fingerprint' => isset( $identity['write_inventory_fingerprint'] ) ? $identity['write_inventory_fingerprint'] : '',
			'provider_blocked_fingerprint' => isset( $identity['provider_blocked_fingerprint'] ) ? $identity['provider_blocked_fingerprint'] : '',
			'evidence_digest' => isset( $value['evidence_digest'] ) ? (string) $value['evidence_digest'] : '',
			'observed_at' => isset( $value['observed_at'] ) ? (string) $value['observed_at'] : gmdate( 'c' ),
			'site_uuid' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '',
			'site_profile_revision' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::revision() : 0,
			'site_profile_digest' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::profile_digest() : '',
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
		$candidate_binding = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_binding_status' )
			? MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()
			: array();
		$current_binding_fingerprint = self::candidate_binding_fingerprint( $candidate_binding );
		if ( ! isset( $freshness['evidence_schema_revision'] ) || 3 !== (int) $freshness['evidence_schema_revision'] ) $reasons[] = 'evidence_schema_revision_changed';
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $field ) {
			$current_key = 'current_' . $field;
			$current_value = isset( $candidate_binding[ $current_key ] ) ? (string) $candidate_binding[ $current_key ] : '';
			if ( ! isset( $freshness[ $field ] ) || ! hash_equals( $current_value, (string) $freshness[ $field ] ) ) $reasons[] = $field . '_changed';
		}
		if ( ! isset( $freshness['candidate_binding_fingerprint'] ) || ! hash_equals( $current_binding_fingerprint, (string) $freshness['candidate_binding_fingerprint'] ) ) $reasons[] = 'candidate_binding_fingerprint_changed';
		if ( ! isset( $freshness['control_plane_version'] ) || ! hash_equals( $current_version, (string) $freshness['control_plane_version'] ) ) $reasons[] = 'control_plane_version_changed';
		if ( ! isset( $freshness['write_inventory_fingerprint'] ) || ! hash_equals( $current_write, (string) $freshness['write_inventory_fingerprint'] ) ) $reasons[] = 'write_inventory_changed';
		if ( ! isset( $freshness['provider_blocked_fingerprint'] ) || ! hash_equals( $current_blocked, (string) $freshness['provider_blocked_fingerprint'] ) ) $reasons[] = 'provider_blocked_projection_changed';
		if ( ! isset( $value['write_tool_count'] ) || (int) $value['write_tool_count'] !== (int) $identity['write_tool_count'] ) $reasons[] = 'write_tool_count_changed';
		if ( isset( $freshness['evidence_digest'], $value['evidence_digest'] ) && '' !== (string) $freshness['evidence_digest'] && ! hash_equals( (string) $freshness['evidence_digest'], (string) $value['evidence_digest'] ) ) $reasons[] = 'persisted_evidence_digest_changed';
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
			$site_uuid = MAD4B_SCP_Site_Profile::site_uuid();
			$revision = MAD4B_SCP_Site_Profile::revision();
			$digest = MAD4B_SCP_Site_Profile::profile_digest();
			if ( ! isset( $freshness['site_uuid'] ) || '' === $site_uuid || ! hash_equals( $site_uuid, (string) $freshness['site_uuid'] ) ) $reasons[] = 'site_identity_changed';
			if ( ! isset( $freshness['site_profile_revision'] ) || $revision !== (int) $freshness['site_profile_revision'] ) $reasons[] = 'site_profile_revision_changed';
			if ( ! isset( $freshness['site_profile_digest'] ) || '' === $digest || ! hash_equals( $digest, (string) $freshness['site_profile_digest'] ) ) $reasons[] = 'site_profile_digest_changed';
		}
		return array_values( array_unique( $reasons ) );
	}

	private static function candidate_binding_fingerprint( array $binding ) {
		$payload = array(
			'contract' => isset( $binding['contract'] ) ? (string) $binding['contract'] : '',
			'required' => ! empty( $binding['required'] ),
			'match' => ! empty( $binding['match'] ),
			'source_commit_sha' => isset( $binding['current_source_commit_sha'] ) ? (string) $binding['current_source_commit_sha'] : '',
			'build_fingerprint' => isset( $binding['current_build_fingerprint'] ) ? (string) $binding['current_build_fingerprint'] : '',
			'package_manifest_digest' => isset( $binding['current_package_manifest_digest'] ) ? (string) $binding['current_package_manifest_digest'] : '',
			'artifact_identity' => isset( $binding['current_artifact_identity'] ) ? (string) $binding['current_artifact_identity'] : '',
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
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
