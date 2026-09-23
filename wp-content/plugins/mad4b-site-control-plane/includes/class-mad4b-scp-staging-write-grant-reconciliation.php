<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact Staging-only bootstrap for reconciling newly eligible governed write
 * abilities into the canonical profile-owned NHI.
 *
 * This is deliberately NOT a normal mad4b-write ability. It can create exact
 * Staging allow grants only for the reviewed provider/ability pairs below.
 * It cannot create wildcard grants, cannot touch Production, cannot grant
 * import/export, cannot create/replace agents or subjects, and cannot revoke
 * pre-existing grants except grants created by the same failed invocation.
 */
final class MAD4B_SCP_Staging_Write_Grant_Reconciliation {
	const CONTRACT = 'mad4b.staging-write-grant-reconciliation.v2';
	const ABILITY = 'mad4b/staging-write-grant-reconcile';
	const CONFIRMATION = 'RECONCILE EXACT STAGING WRITE GRANTS';

	private static $booted = false;
	private static $running = false;

	public static function allowed_ability_providers() {
		return array(
			'mad4b/plugin-package-apply' => 'core',
			'jetengine/create-cct' => 'native-provider',
			'jetengine/create-cpt' => 'native-provider',
			'jetengine/create-glossary' => 'native-provider',
			'jetengine/create-listing' => 'native-provider',
			'jetengine/create-meta-box' => 'native-provider',
			'jetengine/create-query' => 'native-provider',
			'jetengine/create-taxonomy' => 'native-provider',
			'jetengine/manage-modules' => 'native-provider',
			'elementor/clone-subtree' => 'elementor',
			'elementor/move-element' => 'elementor',
			'elementor/delete-element' => 'elementor',
			'elementor/set-dynamic-tag' => 'elementor',
			'elementor/set-etg-dynamic-tag' => 'elementor',
			'context/update-drive-asset' => 'google_drive_context',
			'context/recreate-drive-asset' => 'google_drive_context',
			'mad4b/context-ai-review' => 'core',
		);
	}

	public static function allowed_abilities() {
		return array_keys( self::allowed_ability_providers() );
	}

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 11 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;

		// Bounded authority-bootstrap surface, not a normal governed-write ability.
		$augment = array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' );
		$priority = function_exists( 'has_filter' ) ? has_filter( 'wp_register_ability_args', $augment ) : false;
		if ( false !== $priority ) remove_filter( 'wp_register_ability_args', $augment, (int) $priority );

		try {
			wp_register_ability( self::ABILITY, array(
				'label' => 'Reconcile Exact Staging Write Grants',
				'description' => 'Create only the exact missing Staging grants for reviewed provider write expansions on the canonical profile-owned governed-write agent.',
				'category' => 'mad4b-governance',
				'execute_callback' => array( __CLASS__, 'reconcile' ),
				'permission_callback' => array( __CLASS__, 'can_execute' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'expected_plan_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}
						'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
						'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' ),
						'expected_write_tool_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
						'expected_write_inventory_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_missing_abilities' => array(
							'type' => 'array',
							'minItems' => 0,
							'maxItems' => 32,
							'uniqueItems' => true,
							'items' => array( 'type' => 'string', 'enum' => self::allowed_abilities() ),
						),
						'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) ),
					),
					'required' => array(
						'expected_plan_sha256',
						'expected_revision',
						'expected_profile_digest',
						'expected_source_commit_sha',
						'expected_build_fingerprint',
						'expected_agent_public_id',
						'expected_write_tool_count',
						'expected_write_inventory_fingerprint',
						'expected_missing_abilities',
						'confirmation',
					),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array(
						'public' => false,
						'type' => 'tool',
						'surface' => 'enrollment',
						'mad4b_grant_reconciliation_authority' => self::CONTRACT,
						'creates_exact_staging_grants_only' => true,
						'auto_approves' => false,
					),
					'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
				),
			) );
		} finally {
			if ( false !== $priority ) add_filter( 'wp_register_ability_args', $augment, (int) $priority, 2 );
		}
	}

	public static function can_execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_grant_reconcile_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_grant_reconcile_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_grant_reconcile_profile_missing', 'An enrolled Site Profile is required.' );
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_grant_reconcile_staging_only', 'Exact grant reconciliation is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_grant_reconcile_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Site Profile.' );
		if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) return new WP_Error( 'mad4b_grant_reconcile_write_disabled', 'Governed write must already be enabled.' );
		if ( 'chatgpt-governed-write' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() ) ) return new WP_Error( 'mad4b_grant_reconcile_canonical_agent_required', 'Grant reconciliation is limited to the canonical profile-owned governed-write agent.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_grant_reconcile_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		return true;
	}

	private static function normalized_expected_missing( $input ) {
		$items = isset( $input['expected_missing_abilities'] ) && is_array( $input['expected_missing_abilities'] ) ? array_values( array_unique( array_map( 'strval', $input['expected_missing_abilities'] ) ) ) : array();
		sort( $items, SORT_STRING );
		return $items;
	}

	private static function live_inventory() {
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! class_exists( 'MAD4B_SCP_Servers' ) ) return new WP_Error( 'mad4b_grant_reconcile_authority_unavailable', 'Governed write authority components are unavailable.' );
		$tools = MAD4B_SCP_Staging_Write_Authority::write_tools();
		if ( ! is_array( $tools ) || empty( $tools ) ) return new WP_Error( 'mad4b_grant_reconcile_inventory_empty', 'Current governed write inventory is empty.' );

		$rows = array();
		foreach ( $tools as $ability ) {
			$ability = (string) $ability;
			if ( 'mad4b/database-raw-query' === $ability ) return new WP_Error( 'mad4b_grant_reconcile_breakglass_leak', 'Breakglass/raw SQL must never enter governed grant reconciliation.' );
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
			if ( null === $provider ) return new WP_Error( 'mad4b_grant_reconcile_unmounted_ability', 'A current write ability has no exact mad4b-write provider mount.', array( 'ability' => $ability ) );
			$rows[] = array( 'ability' => $ability, 'provider' => sanitize_key( (string) $provider ) );
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );
		return array(
			'rows' => $rows,
			'count' => count( $rows ),
			'fingerprint' => hash( 'sha256', wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
		);
	}

	private static function current_agent() {
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return new WP_Error( 'mad4b_grant_reconcile_identity_unavailable', 'Governance identity components are unavailable.' );
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_oauth_identity_required', 'Verified OAuth bearer governance identity is required.' );
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;
		if ( 'chatgpt-governed-write' !== (string) $agent['slug'] || 'enabled' !== (string) $agent['status'] || 'staging' !== (string) $agent['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_agent_invalid', 'Resolved governance identity is not the canonical enabled Staging write agent.' );
		if ( (int) $agent['wp_user_id'] !== get_current_user_id() ) return new WP_Error( 'mad4b_grant_reconcile_agent_user_mismatch', 'Resolved governed-write agent belongs to another WordPress user.' );
		return $agent;
	}

	private static function rollback_created( array $agent, array $grant_ids ) {
		$errors = array();
		foreach ( array_reverse( $grant_ids ) as $grant_id ) {
			$result = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant_id, 'mad4b-write' );
			if ( is_wp_error( $result ) ) $errors[] = $result->get_error_code() . ':' . (int) $grant_id;
		}
		return $errors;
	}

	private static function rollback_transaction( array $agent, array $grant_ids, $authority_checkpoint ) {
		$errors = self::rollback_created( $agent, $grant_ids );
		if ( empty( $errors ) ) {
			$restored = MAD4B_SCP_Staging_Write_Authority::restore_persistence_checkpoint( $authority_checkpoint );
			if ( is_wp_error( $restored ) ) {
				$errors[] = $restored->get_error_code() . ':authority_checkpoint';
				$blocked = MAD4B_SCP_Staging_Write_Authority::fail_closed_persisted_authority( 'authority_checkpoint_restore_failed' );
				if ( is_wp_error( $blocked ) ) $errors[] = $blocked->get_error_code() . ':authority_fail_closed';
			}
		} else {
			// Never restore a previously-ready persisted snapshot while any newly-created
			// grant could still exist. Force the hot path blocked across fresh requests.
			$blocked = MAD4B_SCP_Staging_Write_Authority::fail_closed_persisted_authority( 'grant_rollback_incomplete' );
			if ( is_wp_error( $blocked ) ) $errors[] = $blocked->get_error_code() . ':authority_fail_closed';
		}
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			$audit = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-rolled-back', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => isset( $agent['public_id'] ) ? (string) $agent['public_id'] : '',
				'created_grant_ids' => array_values( array_map( 'intval', $grant_ids ) ),
				'rollback_complete' => empty( $errors ),
				'rollback_errors' => array_values( $errors ),
				'authority_forced_blocked' => ! empty( $errors ),
				'production_mutation' => false,
			), empty( $errors ) ? 'ok' : 'error' );
			if ( is_wp_error( $audit ) ) $errors[] = $audit->get_error_code() . ':rollback_audit';
		}
		return $errors;
	}

	public static function reconcile( $input ) {
		if ( self::$running ) return new WP_Error( 'mad4b_grant_reconcile_reentry_denied', 'Grant reconciliation is already running in this request.' );
		$permission = self::can_execute( $input );
		if ( is_wp_error( $permission ) || ! $permission ) return $permission;
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_grant_reconcile_input_invalid', 'Input must be an object.' );
		if ( self::CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_confirmation_required', 'Exact reconciliation confirmation is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan' ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_unavailable', 'Exact reconciliation plan authority is unavailable.' );
		$plan = MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan::plan();
		if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $plan['eligible'] ) || ! empty( $plan['blockers'] ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_blocked', 'Current exact reconciliation plan is blocked.', array( 'blockers' => isset( $plan['blockers'] ) ? $plan['blockers'] : array() ) );
		$expected_plan_sha = isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '';
		$current_plan_sha = isset( $plan['plan_sha256'] ) ? strtolower( trim( (string) $plan['plan_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_plan_sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $current_plan_sha ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_digest_required', 'A valid expected_plan_sha256 from the reviewed reconciliation plan is required.' );
		if ( ! hash_equals( $current_plan_sha, $expected_plan_sha ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_changed', 'Reconciliation plan changed since review.', array( 'current_plan_sha256' => $current_plan_sha, 'expected_plan_sha256' => $expected_plan_sha ) );

		self::$running = true;
		try {
			if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) return new WP_Error( 'mad4b_grant_reconcile_schema_unavailable', 'Governance schema must be physically ready.' );
			$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
			if ( empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_grant_reconcile_audit_required', 'Ready append-only audit storage is required.' );

			$current_revision = MAD4B_SCP_Site_Profile::revision();
			$expected_revision = isset( $input['expected_revision'] ) ? absint( $input['expected_revision'] ) : 0;
			if ( $current_revision !== $expected_revision ) return new WP_Error( 'mad4b_grant_reconcile_revision_stale', 'Site Profile revision changed before reconciliation.' );
			$current_digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
			$expected_digest = isset( $input['expected_profile_digest'] ) ? strtolower( trim( (string) $input['expected_profile_digest'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_digest ) || ! hash_equals( $current_digest, $expected_digest ) ) return new WP_Error( 'mad4b_grant_reconcile_profile_stale', 'Site Profile digest changed before reconciliation.' );

			if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_grant_reconcile_provenance_unavailable', 'Build provenance authority is unavailable.' );
			$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
			$current_sha = is_array( $provenance ) && isset( $provenance['source_commit_sha'] ) ? strtolower( (string) $provenance['source_commit_sha'] ) : '';
			$current_fingerprint = is_array( $provenance ) && isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
			$expected_sha = isset( $input['expected_source_commit_sha'] ) ? strtolower( trim( (string) $input['expected_source_commit_sha'] ) ) : '';
			$expected_fingerprint = isset( $input['expected_build_fingerprint'] ) ? strtolower( trim( (string) $input['expected_build_fingerprint'] ) ) : '';
			if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) || ! empty( $provenance['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_grant_reconcile_provenance_not_ready', 'Exact current build provenance is not ready.' );
			if ( ! preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || ! hash_equals( $expected_sha, $current_sha ) ) return new WP_Error( 'mad4b_grant_reconcile_candidate_mismatch', 'Source commit does not match the exact requested candidate.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_fingerprint ) || ! hash_equals( $expected_fingerprint, $current_fingerprint ) ) return new WP_Error( 'mad4b_grant_reconcile_fingerprint_mismatch', 'Build fingerprint does not match the exact requested candidate.' );

			$agent = self::current_agent();
			if ( is_wp_error( $agent ) ) return $agent;
			$expected_agent = isset( $input['expected_agent_public_id'] ) ? strtolower( trim( (string) $input['expected_agent_public_id'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9-]{36}$/', $expected_agent ) || ! hash_equals( strtolower( (string) $agent['public_id'] ), $expected_agent ) ) return new WP_Error( 'mad4b_grant_reconcile_agent_mismatch', 'Resolved canonical agent does not match the explicitly requested agent.' );

			$inventory = self::live_inventory();
			if ( is_wp_error( $inventory ) ) return $inventory;
			$expected_count = isset( $input['expected_write_tool_count'] ) ? absint( $input['expected_write_tool_count'] ) : 0;
			$expected_inventory = isset( $input['expected_write_inventory_fingerprint'] ) ? strtolower( trim( (string) $input['expected_write_inventory_fingerprint'] ) ) : '';
			if ( $expected_count !== (int) $inventory['count'] ) return new WP_Error( 'mad4b_grant_reconcile_inventory_count_mismatch', 'Governed write inventory count changed before reconciliation.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_inventory ) || ! hash_equals( (string) $inventory['fingerprint'], $expected_inventory ) ) return new WP_Error( 'mad4b_grant_reconcile_inventory_fingerprint_mismatch', 'Governed write inventory fingerprint changed before reconciliation.' );

			$counts = MAD4B_SCP_Agent_Registry::counts();
			if ( ! empty( $counts['wildcard_grants'] ) ) return new WP_Error( 'mad4b_grant_reconcile_wildcard_grant_detected', 'Wildcard grants must be absent before exact reconciliation.' );

			$desired = array();
			foreach ( $inventory['rows'] as $row ) $desired[ $row['ability'] . "\0" . $row['provider'] ] = $row;
			$existing_rows = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
			$seen_allow = array();
			foreach ( $existing_rows as $grant ) {
				if ( 'allow' !== (string) $grant['effect'] ) continue;
				$key = (string) $grant['ability_name'] . "\0" . sanitize_key( (string) $grant['provider'] );
				if ( ! isset( $desired[ $key ] ) ) return new WP_Error( 'mad4b_grant_reconcile_stale_allow_present', 'Unexpected/stale mad4b-write allow grant must be reconciled separately.', array( 'ability' => $grant['ability_name'], 'provider' => $grant['provider'] ) );
				if ( 'staging' !== (string) $grant['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_non_staging_allow_present', 'Exact reconciliation refuses all-environment or non-Staging grants.', array( 'ability' => $grant['ability_name'] ) );
				if ( isset( $seen_allow[ $key ] ) ) return new WP_Error( 'mad4b_grant_reconcile_duplicate_allow_present', 'Duplicate exact mad4b-write allow grant detected.', array( 'ability' => $grant['ability_name'] ) );
				$seen_allow[ $key ] = true;
			}

			$missing = array();
			$providers = array();
			foreach ( $inventory['rows'] as $row ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $row['ability'], $row['provider'] );
				if ( is_wp_error( $grant ) ) {
					if ( 'mad4b_nhi_grant_missing' !== $grant->get_error_code() ) return new WP_Error( 'mad4b_grant_reconcile_existing_grant_blocked', 'Existing exact grant state is not reconcilable by this bounded operation.', array( 'ability' => $row['ability'], 'code' => $grant->get_error_code() ) );
					$missing[] = $row['ability'];
					$providers[ $row['ability'] ] = $row['provider'];
				}
			}
			sort( $missing, SORT_STRING );

			$allowed_providers = self::allowed_ability_providers();
			$allowed = array_keys( $allowed_providers );
			sort( $allowed, SORT_STRING );
			foreach ( $missing as $ability ) {
				if ( ! in_array( $ability, $allowed, true ) ) return new WP_Error( 'mad4b_grant_reconcile_missing_outside_allowlist', 'A missing grant exists outside the reviewed provider reconciliation allowlist.', array( 'ability' => $ability ) );
				$expected_provider = isset( $allowed_providers[ $ability ] ) ? sanitize_key( (string) $allowed_providers[ $ability ] ) : '';
				if ( ! isset( $providers[ $ability ] ) || $expected_provider !== sanitize_key( (string) $providers[ $ability ] ) ) return new WP_Error( 'mad4b_grant_reconcile_provider_mismatch', 'Reviewed grant does not resolve to its exact allowlisted provider.', array( 'ability' => $ability, 'expected_provider' => $expected_provider, 'provider' => isset( $providers[ $ability ] ) ? $providers[ $ability ] : '' ) );
			}
			$expected_missing = self::normalized_expected_missing( $input );
			if ( $missing !== $expected_missing ) return new WP_Error( 'mad4b_grant_reconcile_missing_set_mismatch', 'Live missing-grant set changed or does not match the explicit authorization input.', array( 'live_missing' => $missing, 'expected_missing' => $expected_missing ) );
			$intent = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-authorized', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'environment' => 'staging',
				'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
				'site_profile_revision' => $current_revision,
				'site_profile_digest' => $current_digest,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'write_tool_count' => (int) $inventory['count'],
				'write_inventory_fingerprint' => (string) $inventory['fingerprint'],
				'exact_missing_abilities' => $missing,
				'confirmation' => self::CONFIRMATION,
				'plan_sha256' => $current_plan_sha,
				'production_mutation' => false,
				'breakglass_included' => false,
			), 'ok' );
			if ( is_wp_error( $intent ) ) return new WP_Error( 'mad4b_grant_reconcile_intent_audit_failed', 'Authorization evidence could not be committed before grant mutation.' );
			$authority_checkpoint = MAD4B_SCP_Staging_Write_Authority::persistence_checkpoint();

			$created_ids = array();
			$created_abilities = array();
			foreach ( $missing as $ability ) {
				$provider = $providers[ $ability ];
				$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', $ability, $provider, array(), 'allow', 'staging' );
				if ( is_wp_error( $created ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_create_failed', 'Exact grant creation failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'code' => $created->get_error_code(), 'rollback_errors' => $rollback ) );
				}
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $provider );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || 'staging' !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_postcondition_failed', 'New exact grant failed immediate postcondition verification.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
				$created_ids[] = (int) $grant['id'];
				$created_abilities[] = $ability;
				$grant_audit = MAD4B_SCP_Audit::record( 'mad4b/exact-staging-write-grant-reconciled', array(
					'contract' => self::CONTRACT,
					'agent_public_id' => (string) $agent['public_id'],
					'server_id' => 'mad4b-write',
					'ability' => $ability,
					'provider' => $provider,
					'environment' => 'staging',
					'grant_id' => (int) $grant['id'],
					'source_commit_sha' => $current_sha,
					'write_inventory_fingerprint' => (string) $inventory['fingerprint'],
				), 'ok' );
				if ( is_wp_error( $grant_audit ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_grant_audit_failed', 'Per-grant audit failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			foreach ( $missing as $ability ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $providers[ $ability ] );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || 'staging' !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_final_grant_check_failed', 'Exact grant set did not converge after mutation; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			$authority = MAD4B_SCP_Staging_Write_Authority::finalize_exact_existing_authority();
			if ( ! is_array( $authority ) || empty( $authority['ready'] ) || 'ready' !== ( isset( $authority['state'] ) ? (string) $authority['state'] : '' ) || ! empty( $authority['grant_blockers'] ) || (int) ( isset( $authority['write_tool_count'] ) ? $authority['write_tool_count'] : 0 ) !== (int) $inventory['count'] || ! isset( $authority['write_inventory_fingerprint'] ) || ! hash_equals( (string) $inventory['fingerprint'], (string) $authority['write_inventory_fingerprint'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_authority_not_ready', 'Authority did not converge after exact grant reconciliation; newly-created grants were rolled back.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}
			if ( ! empty( $authority['exact_grants_created'] ) || ! empty( $authority['stale_allow_grants_revoked'] ) || ! empty( $authority['stale_subjects_disabled'] ) || ! empty( $authority['duplicate_exact_allow_grants_revoked'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_unexpected_authority_mutation', 'Authority reconciliation attempted mutations outside the explicitly created exact grant set.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}

			if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding' ) || ! method_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding', 'operation_context' ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_unavailable', 'Audited candidate-binding context factory is unavailable; newly-created grants were rolled back.', array( 'rollback_errors' => $rollback ) );
			}
			$binding_plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
			$binding_snapshot = isset( $binding_plan['candidate_binding'] ) && is_array( $binding_plan['candidate_binding'] ) ? $binding_plan['candidate_binding'] : array();
			$binding_context = MAD4B_SCP_Staging_Write_Candidate_Binding::operation_context(
				$binding_plan,
				$binding_snapshot,
				$current_revision,
				$current_digest,
				self::CONFIRMATION,
				'grant_reconciliation',
				self::CONTRACT
			);
			if ( is_wp_error( $binding_context ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_failed', 'Audited candidate-binding context could not be created after grant reconciliation; newly-created grants were rolled back.', array( 'code' => $binding_context->get_error_code(), 'rollback_errors' => $rollback ) );
			}
			$bound = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( $current_sha, $current_fingerprint, $binding_context );
			if ( is_wp_error( $bound ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_candidate_binding_failed', 'Exact package candidate could not be bound after grant reconciliation; newly-created grants were rolled back.', array( 'code' => $bound->get_error_code(), 'rollback_errors' => $rollback ) );
			}
			$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
			if ( empty( $binding['required'] ) || empty( $binding['match'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_candidate_not_effective', 'Exact package candidate binding is not effective after reconciliation.', array( 'binding' => $binding, 'rollback_errors' => $rollback ) );
			}
			$authority = $bound;

			$completion = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-complete', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'created_count' => count( $created_abilities ),
				'created_abilities' => $created_abilities,
				'write_tool_count' => (int) $authority['write_tool_count'],
				'write_inventory_fingerprint' => (string) $authority['write_inventory_fingerprint'],
				'exact_grants_existing' => isset( $authority['exact_grants_existing'] ) ? (int) $authority['exact_grants_existing'] : 0,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'plan_sha256' => $current_plan_sha,
				'candidate_rebound_without_grant_changes' => empty( $created_abilities ),
				'authority_ready' => true,
				'production_mutation' => false,
			), 'ok' );
			if ( is_wp_error( $completion ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_completion_audit_failed', 'Completion audit failed; newly-created grants and persisted authority state were rolled back.', array( 'rollback_errors' => $rollback ) );
			}

			return array(
				'contract' => self::CONTRACT,
				'state' => empty( $created_abilities ) ? 'candidate_rebound' : 'reconciled',
				'agent_public_id' => (string) $agent['public_id'],
				'created_count' => count( $created_abilities ),
				'created_abilities' => $created_abilities,
				'write_tool_count' => (int) $authority['write_tool_count'],
				'write_inventory_fingerprint' => (string) $authority['write_inventory_fingerprint'],
				'exact_grants_existing' => isset( $authority['exact_grants_existing'] ) ? (int) $authority['exact_grants_existing'] : 0,
				'exact_grants_created_by_authority_reconcile' => isset( $authority['exact_grants_created'] ) ? (int) $authority['exact_grants_created'] : 0,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'plan_sha256' => $current_plan_sha,
				'candidate_binding_match' => ! empty( $binding['match'] ),
				'candidate_rebound_without_grant_changes' => empty( $created_abilities ),
				'runtime_reconciled' => true,
				'authority_ready' => true,
				'completion_audit_recorded' => true,
				'breakglass_included' => false,
				'production_mutation' => false,
			);
		} finally {
			self::$running = false;
		}
	}
}
 ),
						'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
						'expected_profile_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
						'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' ),
						'expected_write_tool_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
						'expected_write_inventory_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_missing_abilities' => array(
							'type' => 'array',
							'minItems' => 0,
							'maxItems' => 32,
							'uniqueItems' => true,
							'items' => array( 'type' => 'string', 'enum' => self::allowed_abilities() ),
						),
						'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) ),
					),
					'required' => array(
						'expected_revision',
						'expected_profile_digest',
						'expected_source_commit_sha',
						'expected_build_fingerprint',
						'expected_agent_public_id',
						'expected_write_tool_count',
						'expected_write_inventory_fingerprint',
						'expected_missing_abilities',
						'confirmation',
					),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array(
						'public' => false,
						'type' => 'tool',
						'surface' => 'enrollment',
						'mad4b_grant_reconciliation_authority' => self::CONTRACT,
						'creates_exact_staging_grants_only' => true,
						'auto_approves' => false,
					),
					'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
				),
			) );
		} finally {
			if ( false !== $priority ) add_filter( 'wp_register_ability_args', $augment, (int) $priority, 2 );
		}
	}

	public static function can_execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_grant_reconcile_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_grant_reconcile_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_grant_reconcile_profile_missing', 'An enrolled Site Profile is required.' );
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_grant_reconcile_staging_only', 'Exact grant reconciliation is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_grant_reconcile_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Site Profile.' );
		if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) return new WP_Error( 'mad4b_grant_reconcile_write_disabled', 'Governed write must already be enabled.' );
		if ( 'chatgpt-governed-write' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() ) ) return new WP_Error( 'mad4b_grant_reconcile_canonical_agent_required', 'Grant reconciliation is limited to the canonical profile-owned governed-write agent.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_grant_reconcile_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		return true;
	}

	private static function normalized_expected_missing( $input ) {
		$items = isset( $input['expected_missing_abilities'] ) && is_array( $input['expected_missing_abilities'] ) ? array_values( array_unique( array_map( 'strval', $input['expected_missing_abilities'] ) ) ) : array();
		sort( $items, SORT_STRING );
		return $items;
	}

	private static function live_inventory() {
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! class_exists( 'MAD4B_SCP_Servers' ) ) return new WP_Error( 'mad4b_grant_reconcile_authority_unavailable', 'Governed write authority components are unavailable.' );
		$tools = MAD4B_SCP_Staging_Write_Authority::write_tools();
		if ( ! is_array( $tools ) || empty( $tools ) ) return new WP_Error( 'mad4b_grant_reconcile_inventory_empty', 'Current governed write inventory is empty.' );

		$rows = array();
		foreach ( $tools as $ability ) {
			$ability = (string) $ability;
			if ( 'mad4b/database-raw-query' === $ability ) return new WP_Error( 'mad4b_grant_reconcile_breakglass_leak', 'Breakglass/raw SQL must never enter governed grant reconciliation.' );
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
			if ( null === $provider ) return new WP_Error( 'mad4b_grant_reconcile_unmounted_ability', 'A current write ability has no exact mad4b-write provider mount.', array( 'ability' => $ability ) );
			$rows[] = array( 'ability' => $ability, 'provider' => sanitize_key( (string) $provider ) );
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );
		return array(
			'rows' => $rows,
			'count' => count( $rows ),
			'fingerprint' => hash( 'sha256', wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
		);
	}

	private static function current_agent() {
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return new WP_Error( 'mad4b_grant_reconcile_identity_unavailable', 'Governance identity components are unavailable.' );
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_oauth_identity_required', 'Verified OAuth bearer governance identity is required.' );
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;
		if ( 'chatgpt-governed-write' !== (string) $agent['slug'] || 'enabled' !== (string) $agent['status'] || 'staging' !== (string) $agent['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_agent_invalid', 'Resolved governance identity is not the canonical enabled Staging write agent.' );
		if ( (int) $agent['wp_user_id'] !== get_current_user_id() ) return new WP_Error( 'mad4b_grant_reconcile_agent_user_mismatch', 'Resolved governed-write agent belongs to another WordPress user.' );
		return $agent;
	}

	private static function rollback_created( array $agent, array $grant_ids ) {
		$errors = array();
		foreach ( array_reverse( $grant_ids ) as $grant_id ) {
			$result = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant_id, 'mad4b-write' );
			if ( is_wp_error( $result ) ) $errors[] = $result->get_error_code() . ':' . (int) $grant_id;
		}
		return $errors;
	}

	public static function reconcile( $input ) {
		if ( self::$running ) return new WP_Error( 'mad4b_grant_reconcile_reentry_denied', 'Grant reconciliation is already running in this request.' );
		$permission = self::can_execute( $input );
		if ( is_wp_error( $permission ) || ! $permission ) return $permission;
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_grant_reconcile_input_invalid', 'Input must be an object.' );
		if ( self::CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_confirmation_required', 'Exact reconciliation confirmation is required.' );

		self::$running = true;
		try {
			if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) return new WP_Error( 'mad4b_grant_reconcile_schema_unavailable', 'Governance schema must be physically ready.' );
			$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
			if ( empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_grant_reconcile_audit_required', 'Ready append-only audit storage is required.' );

			$current_revision = MAD4B_SCP_Site_Profile::revision();
			$expected_revision = isset( $input['expected_revision'] ) ? absint( $input['expected_revision'] ) : 0;
			if ( $current_revision !== $expected_revision ) return new WP_Error( 'mad4b_grant_reconcile_revision_stale', 'Site Profile revision changed before reconciliation.' );
			$current_digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
			$expected_digest = isset( $input['expected_profile_digest'] ) ? strtolower( trim( (string) $input['expected_profile_digest'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_digest ) || ! hash_equals( $current_digest, $expected_digest ) ) return new WP_Error( 'mad4b_grant_reconcile_profile_stale', 'Site Profile digest changed before reconciliation.' );

			if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_grant_reconcile_provenance_unavailable', 'Build provenance authority is unavailable.' );
			$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
			$current_sha = is_array( $provenance ) && isset( $provenance['source_commit_sha'] ) ? strtolower( (string) $provenance['source_commit_sha'] ) : '';
			$current_fingerprint = is_array( $provenance ) && isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
			$expected_sha = isset( $input['expected_source_commit_sha'] ) ? strtolower( trim( (string) $input['expected_source_commit_sha'] ) ) : '';
			$expected_fingerprint = isset( $input['expected_build_fingerprint'] ) ? strtolower( trim( (string) $input['expected_build_fingerprint'] ) ) : '';
			if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) || ! empty( $provenance['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_grant_reconcile_provenance_not_ready', 'Exact current build provenance is not ready.' );
			if ( ! preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || ! hash_equals( $expected_sha, $current_sha ) ) return new WP_Error( 'mad4b_grant_reconcile_candidate_mismatch', 'Source commit does not match the exact requested candidate.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_fingerprint ) || ! hash_equals( $expected_fingerprint, $current_fingerprint ) ) return new WP_Error( 'mad4b_grant_reconcile_fingerprint_mismatch', 'Build fingerprint does not match the exact requested candidate.' );

			$agent = self::current_agent();
			if ( is_wp_error( $agent ) ) return $agent;
			$expected_agent = isset( $input['expected_agent_public_id'] ) ? strtolower( trim( (string) $input['expected_agent_public_id'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9-]{36}$/', $expected_agent ) || ! hash_equals( strtolower( (string) $agent['public_id'] ), $expected_agent ) ) return new WP_Error( 'mad4b_grant_reconcile_agent_mismatch', 'Resolved canonical agent does not match the explicitly requested agent.' );

			$inventory = self::live_inventory();
			if ( is_wp_error( $inventory ) ) return $inventory;
			$expected_count = isset( $input['expected_write_tool_count'] ) ? absint( $input['expected_write_tool_count'] ) : 0;
			$expected_inventory = isset( $input['expected_write_inventory_fingerprint'] ) ? strtolower( trim( (string) $input['expected_write_inventory_fingerprint'] ) ) : '';
			if ( $expected_count !== (int) $inventory['count'] ) return new WP_Error( 'mad4b_grant_reconcile_inventory_count_mismatch', 'Governed write inventory count changed before reconciliation.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_inventory ) || ! hash_equals( (string) $inventory['fingerprint'], $expected_inventory ) ) return new WP_Error( 'mad4b_grant_reconcile_inventory_fingerprint_mismatch', 'Governed write inventory fingerprint changed before reconciliation.' );

			$counts = MAD4B_SCP_Agent_Registry::counts();
			if ( ! empty( $counts['wildcard_grants'] ) ) return new WP_Error( 'mad4b_grant_reconcile_wildcard_grant_detected', 'Wildcard grants must be absent before exact reconciliation.' );

			$desired = array();
			foreach ( $inventory['rows'] as $row ) $desired[ $row['ability'] . "\0" . $row['provider'] ] = $row;
			$existing_rows = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
			$seen_allow = array();
			foreach ( $existing_rows as $grant ) {
				if ( 'allow' !== (string) $grant['effect'] ) continue;
				$key = (string) $grant['ability_name'] . "\0" . sanitize_key( (string) $grant['provider'] );
				if ( ! isset( $desired[ $key ] ) ) return new WP_Error( 'mad4b_grant_reconcile_stale_allow_present', 'Unexpected/stale mad4b-write allow grant must be reconciled separately.', array( 'ability' => $grant['ability_name'], 'provider' => $grant['provider'] ) );
				if ( 'staging' !== (string) $grant['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_non_staging_allow_present', 'Exact reconciliation refuses all-environment or non-Staging grants.', array( 'ability' => $grant['ability_name'] ) );
				if ( isset( $seen_allow[ $key ] ) ) return new WP_Error( 'mad4b_grant_reconcile_duplicate_allow_present', 'Duplicate exact mad4b-write allow grant detected.', array( 'ability' => $grant['ability_name'] ) );
				$seen_allow[ $key ] = true;
			}

			$missing = array();
			$providers = array();
			foreach ( $inventory['rows'] as $row ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $row['ability'], $row['provider'] );
				if ( is_wp_error( $grant ) ) {
					if ( 'mad4b_nhi_grant_missing' !== $grant->get_error_code() ) return new WP_Error( 'mad4b_grant_reconcile_existing_grant_blocked', 'Existing exact grant state is not reconcilable by this bounded operation.', array( 'ability' => $row['ability'], 'code' => $grant->get_error_code() ) );
					$missing[] = $row['ability'];
					$providers[ $row['ability'] ] = $row['provider'];
				}
			}
			sort( $missing, SORT_STRING );

			$allowed_providers = self::allowed_ability_providers();
			$allowed = array_keys( $allowed_providers );
			sort( $allowed, SORT_STRING );
			foreach ( $missing as $ability ) {
				if ( ! in_array( $ability, $allowed, true ) ) return new WP_Error( 'mad4b_grant_reconcile_missing_outside_allowlist', 'A missing grant exists outside the reviewed provider reconciliation allowlist.', array( 'ability' => $ability ) );
				$expected_provider = isset( $allowed_providers[ $ability ] ) ? sanitize_key( (string) $allowed_providers[ $ability ] ) : '';
				if ( ! isset( $providers[ $ability ] ) || $expected_provider !== sanitize_key( (string) $providers[ $ability ] ) ) return new WP_Error( 'mad4b_grant_reconcile_provider_mismatch', 'Reviewed grant does not resolve to its exact allowlisted provider.', array( 'ability' => $ability, 'expected_provider' => $expected_provider, 'provider' => isset( $providers[ $ability ] ) ? $providers[ $ability ] : '' ) );
			}
			$expected_missing = self::normalized_expected_missing( $input );
			if ( $missing !== $expected_missing ) return new WP_Error( 'mad4b_grant_reconcile_missing_set_mismatch', 'Live missing-grant set changed or does not match the explicit authorization input.', array( 'live_missing' => $missing, 'expected_missing' => $expected_missing ) );
			$intent = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-authorized', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'environment' => 'staging',
				'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
				'site_profile_revision' => $current_revision,
				'site_profile_digest' => $current_digest,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'write_tool_count' => (int) $inventory['count'],
				'write_inventory_fingerprint' => (string) $inventory['fingerprint'],
				'exact_missing_abilities' => $missing,
				'confirmation' => self::CONFIRMATION,
				'production_mutation' => false,
				'breakglass_included' => false,
			), 'ok' );
			if ( is_wp_error( $intent ) ) return new WP_Error( 'mad4b_grant_reconcile_intent_audit_failed', 'Authorization evidence could not be committed before grant mutation.' );

			$created_ids = array();
			$created_abilities = array();
			foreach ( $missing as $ability ) {
				$provider = $providers[ $ability ];
				$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', $ability, $provider, array(), 'allow', 'staging' );
				if ( is_wp_error( $created ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_create_failed', 'Exact grant creation failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'code' => $created->get_error_code(), 'rollback_errors' => $rollback ) );
				}
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $provider );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || 'staging' !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_postcondition_failed', 'New exact grant failed immediate postcondition verification.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
				$created_ids[] = (int) $grant['id'];
				$created_abilities[] = $ability;
				$grant_audit = MAD4B_SCP_Audit::record( 'mad4b/exact-staging-write-grant-reconciled', array(
					'contract' => self::CONTRACT,
					'agent_public_id' => (string) $agent['public_id'],
					'server_id' => 'mad4b-write',
					'ability' => $ability,
					'provider' => $provider,
					'environment' => 'staging',
					'grant_id' => (int) $grant['id'],
					'source_commit_sha' => $current_sha,
					'write_inventory_fingerprint' => (string) $inventory['fingerprint'],
				), 'ok' );
				if ( is_wp_error( $grant_audit ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_grant_audit_failed', 'Per-grant audit failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			foreach ( $missing as $ability ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $providers[ $ability ] );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || 'staging' !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_final_grant_check_failed', 'Exact grant set did not converge after mutation; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			$authority = MAD4B_SCP_Staging_Write_Authority::reconcile();
			if ( ! is_array( $authority ) || empty( $authority['ready'] ) || 'ready' !== ( isset( $authority['state'] ) ? (string) $authority['state'] : '' ) || ! empty( $authority['grant_blockers'] ) || (int) ( isset( $authority['write_tool_count'] ) ? $authority['write_tool_count'] : 0 ) !== (int) $inventory['count'] || ! isset( $authority['write_inventory_fingerprint'] ) || ! hash_equals( (string) $inventory['fingerprint'], (string) $authority['write_inventory_fingerprint'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_authority_not_ready', 'Authority did not converge after exact grant reconciliation; newly-created grants were rolled back.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}
			if ( ! empty( $authority['exact_grants_created'] ) || ! empty( $authority['stale_allow_grants_revoked'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_unexpected_authority_mutation', 'Authority reconciliation attempted mutations outside the explicitly created exact grant set.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}

			if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding' ) || ! method_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding', 'operation_context' ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_unavailable', 'Audited candidate-binding context factory is unavailable; newly-created grants were rolled back.', array( 'rollback_errors' => $rollback ) );
			}
			$binding_plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
			$binding_snapshot = isset( $binding_plan['candidate_binding'] ) && is_array( $binding_plan['candidate_binding'] ) ? $binding_plan['candidate_binding'] : array();
			$binding_context = MAD4B_SCP_Staging_Write_Candidate_Binding::operation_context(
				$binding_plan,
				$binding_snapshot,
				$current_revision,
				$current_digest,
				self::CONFIRMATION,
				'grant_reconciliation',
				self::CONTRACT
			);
			if ( is_wp_error( $binding_context ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_failed', 'Audited candidate-binding context could not be created after grant reconciliation; newly-created grants were rolled back.', array( 'code' => $binding_context->get_error_code(), 'rollback_errors' => $rollback ) );
			}
			$bound = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( $current_sha, $current_fingerprint, $binding_context );
			if ( is_wp_error( $bound ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_candidate_binding_failed', 'Exact package candidate could not be bound after grant reconciliation; newly-created grants were rolled back.', array( 'code' => $bound->get_error_code(), 'rollback_errors' => $rollback ) );
			}
			$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
			if ( empty( $binding['required'] ) || empty( $binding['match'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_candidate_not_effective', 'Exact package candidate binding is not effective after reconciliation.', array( 'binding' => $binding, 'rollback_errors' => $rollback ) );
			}
			$authority = $bound;

			$completion = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-complete', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'created_count' => count( $created_abilities ),
				'created_abilities' => $created_abilities,
				'write_tool_count' => (int) $authority['write_tool_count'],
				'write_inventory_fingerprint' => (string) $authority['write_inventory_fingerprint'],
				'exact_grants_existing' => isset( $authority['exact_grants_existing'] ) ? (int) $authority['exact_grants_existing'] : 0,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'candidate_rebound_without_grant_changes' => empty( $created_abilities ),
				'authority_ready' => true,
				'production_mutation' => false,
			), 'ok' );

			return array(
				'contract' => self::CONTRACT,
				'state' => empty( $created_abilities ) ? 'candidate_rebound' : 'reconciled',
				'agent_public_id' => (string) $agent['public_id'],
				'created_count' => count( $created_abilities ),
				'created_abilities' => $created_abilities,
				'write_tool_count' => (int) $authority['write_tool_count'],
				'write_inventory_fingerprint' => (string) $authority['write_inventory_fingerprint'],
				'exact_grants_existing' => isset( $authority['exact_grants_existing'] ) ? (int) $authority['exact_grants_existing'] : 0,
				'exact_grants_created_by_authority_reconcile' => isset( $authority['exact_grants_created'] ) ? (int) $authority['exact_grants_created'] : 0,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'candidate_binding_match' => ! empty( $binding['match'] ),
				'candidate_rebound_without_grant_changes' => empty( $created_abilities ),
				'runtime_reconciled' => true,
				'authority_ready' => true,
				'completion_audit_recorded' => ! is_wp_error( $completion ),
				'breakglass_included' => false,
				'production_mutation' => false,
			);
		} finally {
			self::$running = false;
		}
	}
}
 ),
						'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
						'expected_profile_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}
						'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
						'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' ),
						'expected_write_tool_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
						'expected_write_inventory_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_missing_abilities' => array(
							'type' => 'array',
							'minItems' => 0,
							'maxItems' => 32,
							'uniqueItems' => true,
							'items' => array( 'type' => 'string', 'enum' => self::allowed_abilities() ),
						),
						'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) ),
					),
					'required' => array(
						'expected_plan_sha256',
						'expected_revision',
						'expected_profile_digest',
						'expected_source_commit_sha',
						'expected_build_fingerprint',
						'expected_agent_public_id',
						'expected_write_tool_count',
						'expected_write_inventory_fingerprint',
						'expected_missing_abilities',
						'confirmation',
					),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array(
						'public' => false,
						'type' => 'tool',
						'surface' => 'enrollment',
						'mad4b_grant_reconciliation_authority' => self::CONTRACT,
						'creates_exact_staging_grants_only' => true,
						'auto_approves' => false,
					),
					'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
				),
			) );
		} finally {
			if ( false !== $priority ) add_filter( 'wp_register_ability_args', $augment, (int) $priority, 2 );
		}
	}

	public static function can_execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_grant_reconcile_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_grant_reconcile_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_grant_reconcile_profile_missing', 'An enrolled Site Profile is required.' );
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_grant_reconcile_staging_only', 'Exact grant reconciliation is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_grant_reconcile_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Site Profile.' );
		if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) return new WP_Error( 'mad4b_grant_reconcile_write_disabled', 'Governed write must already be enabled.' );
		if ( 'chatgpt-governed-write' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() ) ) return new WP_Error( 'mad4b_grant_reconcile_canonical_agent_required', 'Grant reconciliation is limited to the canonical profile-owned governed-write agent.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_grant_reconcile_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		return true;
	}

	private static function normalized_expected_missing( $input ) {
		$items = isset( $input['expected_missing_abilities'] ) && is_array( $input['expected_missing_abilities'] ) ? array_values( array_unique( array_map( 'strval', $input['expected_missing_abilities'] ) ) ) : array();
		sort( $items, SORT_STRING );
		return $items;
	}

	private static function live_inventory() {
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! class_exists( 'MAD4B_SCP_Servers' ) ) return new WP_Error( 'mad4b_grant_reconcile_authority_unavailable', 'Governed write authority components are unavailable.' );
		$tools = MAD4B_SCP_Staging_Write_Authority::write_tools();
		if ( ! is_array( $tools ) || empty( $tools ) ) return new WP_Error( 'mad4b_grant_reconcile_inventory_empty', 'Current governed write inventory is empty.' );

		$rows = array();
		foreach ( $tools as $ability ) {
			$ability = (string) $ability;
			if ( 'mad4b/database-raw-query' === $ability ) return new WP_Error( 'mad4b_grant_reconcile_breakglass_leak', 'Breakglass/raw SQL must never enter governed grant reconciliation.' );
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
			if ( null === $provider ) return new WP_Error( 'mad4b_grant_reconcile_unmounted_ability', 'A current write ability has no exact mad4b-write provider mount.', array( 'ability' => $ability ) );
			$rows[] = array( 'ability' => $ability, 'provider' => sanitize_key( (string) $provider ) );
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );
		return array(
			'rows' => $rows,
			'count' => count( $rows ),
			'fingerprint' => hash( 'sha256', wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
		);
	}

	private static function current_agent() {
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return new WP_Error( 'mad4b_grant_reconcile_identity_unavailable', 'Governance identity components are unavailable.' );
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_oauth_identity_required', 'Verified OAuth bearer governance identity is required.' );
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;
		if ( 'chatgpt-governed-write' !== (string) $agent['slug'] || 'enabled' !== (string) $agent['status'] || 'staging' !== (string) $agent['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_agent_invalid', 'Resolved governance identity is not the canonical enabled Staging write agent.' );
		if ( (int) $agent['wp_user_id'] !== get_current_user_id() ) return new WP_Error( 'mad4b_grant_reconcile_agent_user_mismatch', 'Resolved governed-write agent belongs to another WordPress user.' );
		return $agent;
	}

	private static function rollback_created( array $agent, array $grant_ids ) {
		$errors = array();
		foreach ( array_reverse( $grant_ids ) as $grant_id ) {
			$result = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant_id, 'mad4b-write' );
			if ( is_wp_error( $result ) ) $errors[] = $result->get_error_code() . ':' . (int) $grant_id;
		}
		return $errors;
	}

	private static function rollback_transaction( array $agent, array $grant_ids, $authority_checkpoint ) {
		$errors = self::rollback_created( $agent, $grant_ids );
		if ( empty( $errors ) ) {
			$restored = MAD4B_SCP_Staging_Write_Authority::restore_persistence_checkpoint( $authority_checkpoint );
			if ( is_wp_error( $restored ) ) {
				$errors[] = $restored->get_error_code() . ':authority_checkpoint';
				$blocked = MAD4B_SCP_Staging_Write_Authority::fail_closed_persisted_authority( 'authority_checkpoint_restore_failed' );
				if ( is_wp_error( $blocked ) ) $errors[] = $blocked->get_error_code() . ':authority_fail_closed';
			}
		} else {
			// Never restore a previously-ready persisted snapshot while any newly-created
			// grant could still exist. Force the hot path blocked across fresh requests.
			$blocked = MAD4B_SCP_Staging_Write_Authority::fail_closed_persisted_authority( 'grant_rollback_incomplete' );
			if ( is_wp_error( $blocked ) ) $errors[] = $blocked->get_error_code() . ':authority_fail_closed';
		}
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			$audit = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-rolled-back', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => isset( $agent['public_id'] ) ? (string) $agent['public_id'] : '',
				'created_grant_ids' => array_values( array_map( 'intval', $grant_ids ) ),
				'rollback_complete' => empty( $errors ),
				'rollback_errors' => array_values( $errors ),
				'authority_forced_blocked' => ! empty( $errors ),
				'production_mutation' => false,
			), empty( $errors ) ? 'ok' : 'error' );
			if ( is_wp_error( $audit ) ) $errors[] = $audit->get_error_code() . ':rollback_audit';
		}
		return $errors;
	}

	public static function reconcile( $input ) {
		if ( self::$running ) return new WP_Error( 'mad4b_grant_reconcile_reentry_denied', 'Grant reconciliation is already running in this request.' );
		$permission = self::can_execute( $input );
		if ( is_wp_error( $permission ) || ! $permission ) return $permission;
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_grant_reconcile_input_invalid', 'Input must be an object.' );
		if ( self::CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_confirmation_required', 'Exact reconciliation confirmation is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan' ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_unavailable', 'Exact reconciliation plan authority is unavailable.' );
		$plan = MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan::plan();
		if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $plan['eligible'] ) || ! empty( $plan['blockers'] ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_blocked', 'Current exact reconciliation plan is blocked.', array( 'blockers' => isset( $plan['blockers'] ) ? $plan['blockers'] : array() ) );
		$expected_plan_sha = isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '';
		$current_plan_sha = isset( $plan['plan_sha256'] ) ? strtolower( trim( (string) $plan['plan_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_plan_sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $current_plan_sha ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_digest_required', 'A valid expected_plan_sha256 from the reviewed reconciliation plan is required.' );
		if ( ! hash_equals( $current_plan_sha, $expected_plan_sha ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_changed', 'Reconciliation plan changed since review.', array( 'current_plan_sha256' => $current_plan_sha, 'expected_plan_sha256' => $expected_plan_sha ) );

		self::$running = true;
		try {
			if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) return new WP_Error( 'mad4b_grant_reconcile_schema_unavailable', 'Governance schema must be physically ready.' );
			$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
			if ( empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_grant_reconcile_audit_required', 'Ready append-only audit storage is required.' );

			$current_revision = MAD4B_SCP_Site_Profile::revision();
			$expected_revision = isset( $input['expected_revision'] ) ? absint( $input['expected_revision'] ) : 0;
			if ( $current_revision !== $expected_revision ) return new WP_Error( 'mad4b_grant_reconcile_revision_stale', 'Site Profile revision changed before reconciliation.' );
			$current_digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
			$expected_digest = isset( $input['expected_profile_digest'] ) ? strtolower( trim( (string) $input['expected_profile_digest'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_digest ) || ! hash_equals( $current_digest, $expected_digest ) ) return new WP_Error( 'mad4b_grant_reconcile_profile_stale', 'Site Profile digest changed before reconciliation.' );

			if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_grant_reconcile_provenance_unavailable', 'Build provenance authority is unavailable.' );
			$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
			$current_sha = is_array( $provenance ) && isset( $provenance['source_commit_sha'] ) ? strtolower( (string) $provenance['source_commit_sha'] ) : '';
			$current_fingerprint = is_array( $provenance ) && isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
			$expected_sha = isset( $input['expected_source_commit_sha'] ) ? strtolower( trim( (string) $input['expected_source_commit_sha'] ) ) : '';
			$expected_fingerprint = isset( $input['expected_build_fingerprint'] ) ? strtolower( trim( (string) $input['expected_build_fingerprint'] ) ) : '';
			if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) || ! empty( $provenance['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_grant_reconcile_provenance_not_ready', 'Exact current build provenance is not ready.' );
			if ( ! preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || ! hash_equals( $expected_sha, $current_sha ) ) return new WP_Error( 'mad4b_grant_reconcile_candidate_mismatch', 'Source commit does not match the exact requested candidate.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_fingerprint ) || ! hash_equals( $expected_fingerprint, $current_fingerprint ) ) return new WP_Error( 'mad4b_grant_reconcile_fingerprint_mismatch', 'Build fingerprint does not match the exact requested candidate.' );

			$agent = self::current_agent();
			if ( is_wp_error( $agent ) ) return $agent;
			$expected_agent = isset( $input['expected_agent_public_id'] ) ? strtolower( trim( (string) $input['expected_agent_public_id'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9-]{36}$/', $expected_agent ) || ! hash_equals( strtolower( (string) $agent['public_id'] ), $expected_agent ) ) return new WP_Error( 'mad4b_grant_reconcile_agent_mismatch', 'Resolved canonical agent does not match the explicitly requested agent.' );

			$inventory = self::live_inventory();
			if ( is_wp_error( $inventory ) ) return $inventory;
			$expected_count = isset( $input['expected_write_tool_count'] ) ? absint( $input['expected_write_tool_count'] ) : 0;
			$expected_inventory = isset( $input['expected_write_inventory_fingerprint'] ) ? strtolower( trim( (string) $input['expected_write_inventory_fingerprint'] ) ) : '';
			if ( $expected_count !== (int) $inventory['count'] ) return new WP_Error( 'mad4b_grant_reconcile_inventory_count_mismatch', 'Governed write inventory count changed before reconciliation.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_inventory ) || ! hash_equals( (string) $inventory['fingerprint'], $expected_inventory ) ) return new WP_Error( 'mad4b_grant_reconcile_inventory_fingerprint_mismatch', 'Governed write inventory fingerprint changed before reconciliation.' );

			$counts = MAD4B_SCP_Agent_Registry::counts();
			if ( ! empty( $counts['wildcard_grants'] ) ) return new WP_Error( 'mad4b_grant_reconcile_wildcard_grant_detected', 'Wildcard grants must be absent before exact reconciliation.' );

			$desired = array();
			foreach ( $inventory['rows'] as $row ) $desired[ $row['ability'] . "\0" . $row['provider'] ] = $row;
			$existing_rows = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
			$seen_allow = array();
			foreach ( $existing_rows as $grant ) {
				if ( 'allow' !== (string) $grant['effect'] ) continue;
				$key = (string) $grant['ability_name'] . "\0" . sanitize_key( (string) $grant['provider'] );
				if ( ! isset( $desired[ $key ] ) ) return new WP_Error( 'mad4b_grant_reconcile_stale_allow_present', 'Unexpected/stale mad4b-write allow grant must be reconciled separately.', array( 'ability' => $grant['ability_name'], 'provider' => $grant['provider'] ) );
				if ( 'staging' !== (string) $grant['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_non_staging_allow_present', 'Exact reconciliation refuses all-environment or non-Staging grants.', array( 'ability' => $grant['ability_name'] ) );
				if ( isset( $seen_allow[ $key ] ) ) return new WP_Error( 'mad4b_grant_reconcile_duplicate_allow_present', 'Duplicate exact mad4b-write allow grant detected.', array( 'ability' => $grant['ability_name'] ) );
				$seen_allow[ $key ] = true;
			}

			$missing = array();
			$providers = array();
			foreach ( $inventory['rows'] as $row ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $row['ability'], $row['provider'] );
				if ( is_wp_error( $grant ) ) {
					if ( 'mad4b_nhi_grant_missing' !== $grant->get_error_code() ) return new WP_Error( 'mad4b_grant_reconcile_existing_grant_blocked', 'Existing exact grant state is not reconcilable by this bounded operation.', array( 'ability' => $row['ability'], 'code' => $grant->get_error_code() ) );
					$missing[] = $row['ability'];
					$providers[ $row['ability'] ] = $row['provider'];
				}
			}
			sort( $missing, SORT_STRING );

			$allowed_providers = self::allowed_ability_providers();
			$allowed = array_keys( $allowed_providers );
			sort( $allowed, SORT_STRING );
			foreach ( $missing as $ability ) {
				if ( ! in_array( $ability, $allowed, true ) ) return new WP_Error( 'mad4b_grant_reconcile_missing_outside_allowlist', 'A missing grant exists outside the reviewed provider reconciliation allowlist.', array( 'ability' => $ability ) );
				$expected_provider = isset( $allowed_providers[ $ability ] ) ? sanitize_key( (string) $allowed_providers[ $ability ] ) : '';
				if ( ! isset( $providers[ $ability ] ) || $expected_provider !== sanitize_key( (string) $providers[ $ability ] ) ) return new WP_Error( 'mad4b_grant_reconcile_provider_mismatch', 'Reviewed grant does not resolve to its exact allowlisted provider.', array( 'ability' => $ability, 'expected_provider' => $expected_provider, 'provider' => isset( $providers[ $ability ] ) ? $providers[ $ability ] : '' ) );
			}
			$expected_missing = self::normalized_expected_missing( $input );
			if ( $missing !== $expected_missing ) return new WP_Error( 'mad4b_grant_reconcile_missing_set_mismatch', 'Live missing-grant set changed or does not match the explicit authorization input.', array( 'live_missing' => $missing, 'expected_missing' => $expected_missing ) );
			$intent = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-authorized', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'environment' => 'staging',
				'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
				'site_profile_revision' => $current_revision,
				'site_profile_digest' => $current_digest,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'write_tool_count' => (int) $inventory['count'],
				'write_inventory_fingerprint' => (string) $inventory['fingerprint'],
				'exact_missing_abilities' => $missing,
				'confirmation' => self::CONFIRMATION,
				'plan_sha256' => $current_plan_sha,
				'production_mutation' => false,
				'breakglass_included' => false,
			), 'ok' );
			if ( is_wp_error( $intent ) ) return new WP_Error( 'mad4b_grant_reconcile_intent_audit_failed', 'Authorization evidence could not be committed before grant mutation.' );
			$authority_checkpoint = MAD4B_SCP_Staging_Write_Authority::persistence_checkpoint();

			$created_ids = array();
			$created_abilities = array();
			foreach ( $missing as $ability ) {
				$provider = $providers[ $ability ];
				$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', $ability, $provider, array(), 'allow', 'staging' );
				if ( is_wp_error( $created ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_create_failed', 'Exact grant creation failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'code' => $created->get_error_code(), 'rollback_errors' => $rollback ) );
				}
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $provider );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || 'staging' !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_postcondition_failed', 'New exact grant failed immediate postcondition verification.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
				$created_ids[] = (int) $grant['id'];
				$created_abilities[] = $ability;
				$grant_audit = MAD4B_SCP_Audit::record( 'mad4b/exact-staging-write-grant-reconciled', array(
					'contract' => self::CONTRACT,
					'agent_public_id' => (string) $agent['public_id'],
					'server_id' => 'mad4b-write',
					'ability' => $ability,
					'provider' => $provider,
					'environment' => 'staging',
					'grant_id' => (int) $grant['id'],
					'source_commit_sha' => $current_sha,
					'write_inventory_fingerprint' => (string) $inventory['fingerprint'],
				), 'ok' );
				if ( is_wp_error( $grant_audit ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_grant_audit_failed', 'Per-grant audit failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			foreach ( $missing as $ability ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $providers[ $ability ] );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || 'staging' !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_final_grant_check_failed', 'Exact grant set did not converge after mutation; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			$authority = MAD4B_SCP_Staging_Write_Authority::finalize_exact_existing_authority();
			if ( ! is_array( $authority ) || empty( $authority['ready'] ) || 'ready' !== ( isset( $authority['state'] ) ? (string) $authority['state'] : '' ) || ! empty( $authority['grant_blockers'] ) || (int) ( isset( $authority['write_tool_count'] ) ? $authority['write_tool_count'] : 0 ) !== (int) $inventory['count'] || ! isset( $authority['write_inventory_fingerprint'] ) || ! hash_equals( (string) $inventory['fingerprint'], (string) $authority['write_inventory_fingerprint'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_authority_not_ready', 'Authority did not converge after exact grant reconciliation; newly-created grants were rolled back.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}
			if ( ! empty( $authority['exact_grants_created'] ) || ! empty( $authority['stale_allow_grants_revoked'] ) || ! empty( $authority['stale_subjects_disabled'] ) || ! empty( $authority['duplicate_exact_allow_grants_revoked'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_unexpected_authority_mutation', 'Authority reconciliation attempted mutations outside the explicitly created exact grant set.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}

			if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding' ) || ! method_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding', 'operation_context' ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_unavailable', 'Audited candidate-binding context factory is unavailable; newly-created grants were rolled back.', array( 'rollback_errors' => $rollback ) );
			}
			$binding_plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
			$binding_snapshot = isset( $binding_plan['candidate_binding'] ) && is_array( $binding_plan['candidate_binding'] ) ? $binding_plan['candidate_binding'] : array();
			$binding_context = MAD4B_SCP_Staging_Write_Candidate_Binding::operation_context(
				$binding_plan,
				$binding_snapshot,
				$current_revision,
				$current_digest,
				self::CONFIRMATION,
				'grant_reconciliation',
				self::CONTRACT
			);
			if ( is_wp_error( $binding_context ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_failed', 'Audited candidate-binding context could not be created after grant reconciliation; newly-created grants were rolled back.', array( 'code' => $binding_context->get_error_code(), 'rollback_errors' => $rollback ) );
			}
			$bound = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( $current_sha, $current_fingerprint, $binding_context );
			if ( is_wp_error( $bound ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_candidate_binding_failed', 'Exact package candidate could not be bound after grant reconciliation; newly-created grants were rolled back.', array( 'code' => $bound->get_error_code(), 'rollback_errors' => $rollback ) );
			}
			$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
			if ( empty( $binding['required'] ) || empty( $binding['match'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_candidate_not_effective', 'Exact package candidate binding is not effective after reconciliation.', array( 'binding' => $binding, 'rollback_errors' => $rollback ) );
			}
			$authority = $bound;

			$completion = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-complete', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'created_count' => count( $created_abilities ),
				'created_abilities' => $created_abilities,
				'write_tool_count' => (int) $authority['write_tool_count'],
				'write_inventory_fingerprint' => (string) $authority['write_inventory_fingerprint'],
				'exact_grants_existing' => isset( $authority['exact_grants_existing'] ) ? (int) $authority['exact_grants_existing'] : 0,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'plan_sha256' => $current_plan_sha,
				'candidate_rebound_without_grant_changes' => empty( $created_abilities ),
				'authority_ready' => true,
				'production_mutation' => false,
			), 'ok' );
			if ( is_wp_error( $completion ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_completion_audit_failed', 'Completion audit failed; newly-created grants and persisted authority state were rolled back.', array( 'rollback_errors' => $rollback ) );
			}

			return array(
				'contract' => self::CONTRACT,
				'state' => empty( $created_abilities ) ? 'candidate_rebound' : 'reconciled',
				'agent_public_id' => (string) $agent['public_id'],
				'created_count' => count( $created_abilities ),
				'created_abilities' => $created_abilities,
				'write_tool_count' => (int) $authority['write_tool_count'],
				'write_inventory_fingerprint' => (string) $authority['write_inventory_fingerprint'],
				'exact_grants_existing' => isset( $authority['exact_grants_existing'] ) ? (int) $authority['exact_grants_existing'] : 0,
				'exact_grants_created_by_authority_reconcile' => isset( $authority['exact_grants_created'] ) ? (int) $authority['exact_grants_created'] : 0,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'plan_sha256' => $current_plan_sha,
				'candidate_binding_match' => ! empty( $binding['match'] ),
				'candidate_rebound_without_grant_changes' => empty( $created_abilities ),
				'runtime_reconciled' => true,
				'authority_ready' => true,
				'completion_audit_recorded' => true,
				'breakglass_included' => false,
				'production_mutation' => false,
			);
		} finally {
			self::$running = false;
		}
	}
}
 ),
						'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
						'expected_profile_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
						'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' ),
						'expected_write_tool_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
						'expected_write_inventory_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_missing_abilities' => array(
							'type' => 'array',
							'minItems' => 0,
							'maxItems' => 32,
							'uniqueItems' => true,
							'items' => array( 'type' => 'string', 'enum' => self::allowed_abilities() ),
						),
						'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) ),
					),
					'required' => array(
						'expected_revision',
						'expected_profile_digest',
						'expected_source_commit_sha',
						'expected_build_fingerprint',
						'expected_agent_public_id',
						'expected_write_tool_count',
						'expected_write_inventory_fingerprint',
						'expected_missing_abilities',
						'confirmation',
					),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array(
						'public' => false,
						'type' => 'tool',
						'surface' => 'enrollment',
						'mad4b_grant_reconciliation_authority' => self::CONTRACT,
						'creates_exact_staging_grants_only' => true,
						'auto_approves' => false,
					),
					'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
				),
			) );
		} finally {
			if ( false !== $priority ) add_filter( 'wp_register_ability_args', $augment, (int) $priority, 2 );
		}
	}

	public static function can_execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_grant_reconcile_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_grant_reconcile_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_grant_reconcile_profile_missing', 'An enrolled Site Profile is required.' );
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_grant_reconcile_staging_only', 'Exact grant reconciliation is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_grant_reconcile_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Site Profile.' );
		if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) return new WP_Error( 'mad4b_grant_reconcile_write_disabled', 'Governed write must already be enabled.' );
		if ( 'chatgpt-governed-write' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() ) ) return new WP_Error( 'mad4b_grant_reconcile_canonical_agent_required', 'Grant reconciliation is limited to the canonical profile-owned governed-write agent.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_grant_reconcile_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		return true;
	}

	private static function normalized_expected_missing( $input ) {
		$items = isset( $input['expected_missing_abilities'] ) && is_array( $input['expected_missing_abilities'] ) ? array_values( array_unique( array_map( 'strval', $input['expected_missing_abilities'] ) ) ) : array();
		sort( $items, SORT_STRING );
		return $items;
	}

	private static function live_inventory() {
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! class_exists( 'MAD4B_SCP_Servers' ) ) return new WP_Error( 'mad4b_grant_reconcile_authority_unavailable', 'Governed write authority components are unavailable.' );
		$tools = MAD4B_SCP_Staging_Write_Authority::write_tools();
		if ( ! is_array( $tools ) || empty( $tools ) ) return new WP_Error( 'mad4b_grant_reconcile_inventory_empty', 'Current governed write inventory is empty.' );

		$rows = array();
		foreach ( $tools as $ability ) {
			$ability = (string) $ability;
			if ( 'mad4b/database-raw-query' === $ability ) return new WP_Error( 'mad4b_grant_reconcile_breakglass_leak', 'Breakglass/raw SQL must never enter governed grant reconciliation.' );
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
			if ( null === $provider ) return new WP_Error( 'mad4b_grant_reconcile_unmounted_ability', 'A current write ability has no exact mad4b-write provider mount.', array( 'ability' => $ability ) );
			$rows[] = array( 'ability' => $ability, 'provider' => sanitize_key( (string) $provider ) );
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );
		return array(
			'rows' => $rows,
			'count' => count( $rows ),
			'fingerprint' => hash( 'sha256', wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
		);
	}

	private static function current_agent() {
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return new WP_Error( 'mad4b_grant_reconcile_identity_unavailable', 'Governance identity components are unavailable.' );
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_oauth_identity_required', 'Verified OAuth bearer governance identity is required.' );
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;
		if ( 'chatgpt-governed-write' !== (string) $agent['slug'] || 'enabled' !== (string) $agent['status'] || 'staging' !== (string) $agent['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_agent_invalid', 'Resolved governance identity is not the canonical enabled Staging write agent.' );
		if ( (int) $agent['wp_user_id'] !== get_current_user_id() ) return new WP_Error( 'mad4b_grant_reconcile_agent_user_mismatch', 'Resolved governed-write agent belongs to another WordPress user.' );
		return $agent;
	}

	private static function rollback_created( array $agent, array $grant_ids ) {
		$errors = array();
		foreach ( array_reverse( $grant_ids ) as $grant_id ) {
			$result = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant_id, 'mad4b-write' );
			if ( is_wp_error( $result ) ) $errors[] = $result->get_error_code() . ':' . (int) $grant_id;
		}
		return $errors;
	}

	public static function reconcile( $input ) {
		if ( self::$running ) return new WP_Error( 'mad4b_grant_reconcile_reentry_denied', 'Grant reconciliation is already running in this request.' );
		$permission = self::can_execute( $input );
		if ( is_wp_error( $permission ) || ! $permission ) return $permission;
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_grant_reconcile_input_invalid', 'Input must be an object.' );
		if ( self::CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_confirmation_required', 'Exact reconciliation confirmation is required.' );

		self::$running = true;
		try {
			if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) return new WP_Error( 'mad4b_grant_reconcile_schema_unavailable', 'Governance schema must be physically ready.' );
			$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
			if ( empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_grant_reconcile_audit_required', 'Ready append-only audit storage is required.' );

			$current_revision = MAD4B_SCP_Site_Profile::revision();
			$expected_revision = isset( $input['expected_revision'] ) ? absint( $input['expected_revision'] ) : 0;
			if ( $current_revision !== $expected_revision ) return new WP_Error( 'mad4b_grant_reconcile_revision_stale', 'Site Profile revision changed before reconciliation.' );
			$current_digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
			$expected_digest = isset( $input['expected_profile_digest'] ) ? strtolower( trim( (string) $input['expected_profile_digest'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_digest ) || ! hash_equals( $current_digest, $expected_digest ) ) return new WP_Error( 'mad4b_grant_reconcile_profile_stale', 'Site Profile digest changed before reconciliation.' );

			if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_grant_reconcile_provenance_unavailable', 'Build provenance authority is unavailable.' );
			$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
			$current_sha = is_array( $provenance ) && isset( $provenance['source_commit_sha'] ) ? strtolower( (string) $provenance['source_commit_sha'] ) : '';
			$current_fingerprint = is_array( $provenance ) && isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
			$expected_sha = isset( $input['expected_source_commit_sha'] ) ? strtolower( trim( (string) $input['expected_source_commit_sha'] ) ) : '';
			$expected_fingerprint = isset( $input['expected_build_fingerprint'] ) ? strtolower( trim( (string) $input['expected_build_fingerprint'] ) ) : '';
			if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) || ! empty( $provenance['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_grant_reconcile_provenance_not_ready', 'Exact current build provenance is not ready.' );
			if ( ! preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || ! hash_equals( $expected_sha, $current_sha ) ) return new WP_Error( 'mad4b_grant_reconcile_candidate_mismatch', 'Source commit does not match the exact requested candidate.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_fingerprint ) || ! hash_equals( $expected_fingerprint, $current_fingerprint ) ) return new WP_Error( 'mad4b_grant_reconcile_fingerprint_mismatch', 'Build fingerprint does not match the exact requested candidate.' );

			$agent = self::current_agent();
			if ( is_wp_error( $agent ) ) return $agent;
			$expected_agent = isset( $input['expected_agent_public_id'] ) ? strtolower( trim( (string) $input['expected_agent_public_id'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9-]{36}$/', $expected_agent ) || ! hash_equals( strtolower( (string) $agent['public_id'] ), $expected_agent ) ) return new WP_Error( 'mad4b_grant_reconcile_agent_mismatch', 'Resolved canonical agent does not match the explicitly requested agent.' );

			$inventory = self::live_inventory();
			if ( is_wp_error( $inventory ) ) return $inventory;
			$expected_count = isset( $input['expected_write_tool_count'] ) ? absint( $input['expected_write_tool_count'] ) : 0;
			$expected_inventory = isset( $input['expected_write_inventory_fingerprint'] ) ? strtolower( trim( (string) $input['expected_write_inventory_fingerprint'] ) ) : '';
			if ( $expected_count !== (int) $inventory['count'] ) return new WP_Error( 'mad4b_grant_reconcile_inventory_count_mismatch', 'Governed write inventory count changed before reconciliation.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_inventory ) || ! hash_equals( (string) $inventory['fingerprint'], $expected_inventory ) ) return new WP_Error( 'mad4b_grant_reconcile_inventory_fingerprint_mismatch', 'Governed write inventory fingerprint changed before reconciliation.' );

			$counts = MAD4B_SCP_Agent_Registry::counts();
			if ( ! empty( $counts['wildcard_grants'] ) ) return new WP_Error( 'mad4b_grant_reconcile_wildcard_grant_detected', 'Wildcard grants must be absent before exact reconciliation.' );

			$desired = array();
			foreach ( $inventory['rows'] as $row ) $desired[ $row['ability'] . "\0" . $row['provider'] ] = $row;
			$existing_rows = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
			$seen_allow = array();
			foreach ( $existing_rows as $grant ) {
				if ( 'allow' !== (string) $grant['effect'] ) continue;
				$key = (string) $grant['ability_name'] . "\0" . sanitize_key( (string) $grant['provider'] );
				if ( ! isset( $desired[ $key ] ) ) return new WP_Error( 'mad4b_grant_reconcile_stale_allow_present', 'Unexpected/stale mad4b-write allow grant must be reconciled separately.', array( 'ability' => $grant['ability_name'], 'provider' => $grant['provider'] ) );
				if ( 'staging' !== (string) $grant['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_non_staging_allow_present', 'Exact reconciliation refuses all-environment or non-Staging grants.', array( 'ability' => $grant['ability_name'] ) );
				if ( isset( $seen_allow[ $key ] ) ) return new WP_Error( 'mad4b_grant_reconcile_duplicate_allow_present', 'Duplicate exact mad4b-write allow grant detected.', array( 'ability' => $grant['ability_name'] ) );
				$seen_allow[ $key ] = true;
			}

			$missing = array();
			$providers = array();
			foreach ( $inventory['rows'] as $row ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $row['ability'], $row['provider'] );
				if ( is_wp_error( $grant ) ) {
					if ( 'mad4b_nhi_grant_missing' !== $grant->get_error_code() ) return new WP_Error( 'mad4b_grant_reconcile_existing_grant_blocked', 'Existing exact grant state is not reconcilable by this bounded operation.', array( 'ability' => $row['ability'], 'code' => $grant->get_error_code() ) );
					$missing[] = $row['ability'];
					$providers[ $row['ability'] ] = $row['provider'];
				}
			}
			sort( $missing, SORT_STRING );

			$allowed_providers = self::allowed_ability_providers();
			$allowed = array_keys( $allowed_providers );
			sort( $allowed, SORT_STRING );
			foreach ( $missing as $ability ) {
				if ( ! in_array( $ability, $allowed, true ) ) return new WP_Error( 'mad4b_grant_reconcile_missing_outside_allowlist', 'A missing grant exists outside the reviewed provider reconciliation allowlist.', array( 'ability' => $ability ) );
				$expected_provider = isset( $allowed_providers[ $ability ] ) ? sanitize_key( (string) $allowed_providers[ $ability ] ) : '';
				if ( ! isset( $providers[ $ability ] ) || $expected_provider !== sanitize_key( (string) $providers[ $ability ] ) ) return new WP_Error( 'mad4b_grant_reconcile_provider_mismatch', 'Reviewed grant does not resolve to its exact allowlisted provider.', array( 'ability' => $ability, 'expected_provider' => $expected_provider, 'provider' => isset( $providers[ $ability ] ) ? $providers[ $ability ] : '' ) );
			}
			$expected_missing = self::normalized_expected_missing( $input );
			if ( $missing !== $expected_missing ) return new WP_Error( 'mad4b_grant_reconcile_missing_set_mismatch', 'Live missing-grant set changed or does not match the explicit authorization input.', array( 'live_missing' => $missing, 'expected_missing' => $expected_missing ) );
			$intent = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-authorized', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'environment' => 'staging',
				'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
				'site_profile_revision' => $current_revision,
				'site_profile_digest' => $current_digest,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'write_tool_count' => (int) $inventory['count'],
				'write_inventory_fingerprint' => (string) $inventory['fingerprint'],
				'exact_missing_abilities' => $missing,
				'confirmation' => self::CONFIRMATION,
				'production_mutation' => false,
				'breakglass_included' => false,
			), 'ok' );
			if ( is_wp_error( $intent ) ) return new WP_Error( 'mad4b_grant_reconcile_intent_audit_failed', 'Authorization evidence could not be committed before grant mutation.' );

			$created_ids = array();
			$created_abilities = array();
			foreach ( $missing as $ability ) {
				$provider = $providers[ $ability ];
				$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', $ability, $provider, array(), 'allow', 'staging' );
				if ( is_wp_error( $created ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_create_failed', 'Exact grant creation failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'code' => $created->get_error_code(), 'rollback_errors' => $rollback ) );
				}
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $provider );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || 'staging' !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_postcondition_failed', 'New exact grant failed immediate postcondition verification.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
				$created_ids[] = (int) $grant['id'];
				$created_abilities[] = $ability;
				$grant_audit = MAD4B_SCP_Audit::record( 'mad4b/exact-staging-write-grant-reconciled', array(
					'contract' => self::CONTRACT,
					'agent_public_id' => (string) $agent['public_id'],
					'server_id' => 'mad4b-write',
					'ability' => $ability,
					'provider' => $provider,
					'environment' => 'staging',
					'grant_id' => (int) $grant['id'],
					'source_commit_sha' => $current_sha,
					'write_inventory_fingerprint' => (string) $inventory['fingerprint'],
				), 'ok' );
				if ( is_wp_error( $grant_audit ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_grant_audit_failed', 'Per-grant audit failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			foreach ( $missing as $ability ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $providers[ $ability ] );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || 'staging' !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_final_grant_check_failed', 'Exact grant set did not converge after mutation; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			$authority = MAD4B_SCP_Staging_Write_Authority::reconcile();
			if ( ! is_array( $authority ) || empty( $authority['ready'] ) || 'ready' !== ( isset( $authority['state'] ) ? (string) $authority['state'] : '' ) || ! empty( $authority['grant_blockers'] ) || (int) ( isset( $authority['write_tool_count'] ) ? $authority['write_tool_count'] : 0 ) !== (int) $inventory['count'] || ! isset( $authority['write_inventory_fingerprint'] ) || ! hash_equals( (string) $inventory['fingerprint'], (string) $authority['write_inventory_fingerprint'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_authority_not_ready', 'Authority did not converge after exact grant reconciliation; newly-created grants were rolled back.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}
			if ( ! empty( $authority['exact_grants_created'] ) || ! empty( $authority['stale_allow_grants_revoked'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_unexpected_authority_mutation', 'Authority reconciliation attempted mutations outside the explicitly created exact grant set.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}

			if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding' ) || ! method_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding', 'operation_context' ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_unavailable', 'Audited candidate-binding context factory is unavailable; newly-created grants were rolled back.', array( 'rollback_errors' => $rollback ) );
			}
			$binding_plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
			$binding_snapshot = isset( $binding_plan['candidate_binding'] ) && is_array( $binding_plan['candidate_binding'] ) ? $binding_plan['candidate_binding'] : array();
			$binding_context = MAD4B_SCP_Staging_Write_Candidate_Binding::operation_context(
				$binding_plan,
				$binding_snapshot,
				$current_revision,
				$current_digest,
				self::CONFIRMATION,
				'grant_reconciliation',
				self::CONTRACT
			);
			if ( is_wp_error( $binding_context ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_failed', 'Audited candidate-binding context could not be created after grant reconciliation; newly-created grants were rolled back.', array( 'code' => $binding_context->get_error_code(), 'rollback_errors' => $rollback ) );
			}
			$bound = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( $current_sha, $current_fingerprint, $binding_context );
			if ( is_wp_error( $bound ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_candidate_binding_failed', 'Exact package candidate could not be bound after grant reconciliation; newly-created grants were rolled back.', array( 'code' => $bound->get_error_code(), 'rollback_errors' => $rollback ) );
			}
			$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
			if ( empty( $binding['required'] ) || empty( $binding['match'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_candidate_not_effective', 'Exact package candidate binding is not effective after reconciliation.', array( 'binding' => $binding, 'rollback_errors' => $rollback ) );
			}
			$authority = $bound;

			$completion = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-complete', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'created_count' => count( $created_abilities ),
				'created_abilities' => $created_abilities,
				'write_tool_count' => (int) $authority['write_tool_count'],
				'write_inventory_fingerprint' => (string) $authority['write_inventory_fingerprint'],
				'exact_grants_existing' => isset( $authority['exact_grants_existing'] ) ? (int) $authority['exact_grants_existing'] : 0,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'candidate_rebound_without_grant_changes' => empty( $created_abilities ),
				'authority_ready' => true,
				'production_mutation' => false,
			), 'ok' );

			return array(
				'contract' => self::CONTRACT,
				'state' => empty( $created_abilities ) ? 'candidate_rebound' : 'reconciled',
				'agent_public_id' => (string) $agent['public_id'],
				'created_count' => count( $created_abilities ),
				'created_abilities' => $created_abilities,
				'write_tool_count' => (int) $authority['write_tool_count'],
				'write_inventory_fingerprint' => (string) $authority['write_inventory_fingerprint'],
				'exact_grants_existing' => isset( $authority['exact_grants_existing'] ) ? (int) $authority['exact_grants_existing'] : 0,
				'exact_grants_created_by_authority_reconcile' => isset( $authority['exact_grants_created'] ) ? (int) $authority['exact_grants_created'] : 0,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'candidate_binding_match' => ! empty( $binding['match'] ),
				'candidate_rebound_without_grant_changes' => empty( $created_abilities ),
				'runtime_reconciled' => true,
				'authority_ready' => true,
				'completion_audit_recorded' => ! is_wp_error( $completion ),
				'breakglass_included' => false,
				'production_mutation' => false,
			);
		} finally {
			self::$running = false;
		}
	}
}
 ),
						'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
						'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' ),
						'expected_write_tool_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
						'expected_write_inventory_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_missing_abilities' => array(
							'type' => 'array',
							'minItems' => 0,
							'maxItems' => 32,
							'uniqueItems' => true,
							'items' => array( 'type' => 'string', 'enum' => self::allowed_abilities() ),
						),
						'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) ),
					),
					'required' => array(
						'expected_plan_sha256',
						'expected_revision',
						'expected_profile_digest',
						'expected_source_commit_sha',
						'expected_build_fingerprint',
						'expected_agent_public_id',
						'expected_write_tool_count',
						'expected_write_inventory_fingerprint',
						'expected_missing_abilities',
						'confirmation',
					),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array(
						'public' => false,
						'type' => 'tool',
						'surface' => 'enrollment',
						'mad4b_grant_reconciliation_authority' => self::CONTRACT,
						'creates_exact_staging_grants_only' => true,
						'auto_approves' => false,
					),
					'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
				),
			) );
		} finally {
			if ( false !== $priority ) add_filter( 'wp_register_ability_args', $augment, (int) $priority, 2 );
		}
	}

	public static function can_execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_grant_reconcile_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_grant_reconcile_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_grant_reconcile_profile_missing', 'An enrolled Site Profile is required.' );
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_grant_reconcile_staging_only', 'Exact grant reconciliation is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_grant_reconcile_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Site Profile.' );
		if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) return new WP_Error( 'mad4b_grant_reconcile_write_disabled', 'Governed write must already be enabled.' );
		if ( 'chatgpt-governed-write' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() ) ) return new WP_Error( 'mad4b_grant_reconcile_canonical_agent_required', 'Grant reconciliation is limited to the canonical profile-owned governed-write agent.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_grant_reconcile_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		return true;
	}

	private static function normalized_expected_missing( $input ) {
		$items = isset( $input['expected_missing_abilities'] ) && is_array( $input['expected_missing_abilities'] ) ? array_values( array_unique( array_map( 'strval', $input['expected_missing_abilities'] ) ) ) : array();
		sort( $items, SORT_STRING );
		return $items;
	}

	private static function live_inventory() {
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! class_exists( 'MAD4B_SCP_Servers' ) ) return new WP_Error( 'mad4b_grant_reconcile_authority_unavailable', 'Governed write authority components are unavailable.' );
		$tools = MAD4B_SCP_Staging_Write_Authority::write_tools();
		if ( ! is_array( $tools ) || empty( $tools ) ) return new WP_Error( 'mad4b_grant_reconcile_inventory_empty', 'Current governed write inventory is empty.' );

		$rows = array();
		foreach ( $tools as $ability ) {
			$ability = (string) $ability;
			if ( 'mad4b/database-raw-query' === $ability ) return new WP_Error( 'mad4b_grant_reconcile_breakglass_leak', 'Breakglass/raw SQL must never enter governed grant reconciliation.' );
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
			if ( null === $provider ) return new WP_Error( 'mad4b_grant_reconcile_unmounted_ability', 'A current write ability has no exact mad4b-write provider mount.', array( 'ability' => $ability ) );
			$rows[] = array( 'ability' => $ability, 'provider' => sanitize_key( (string) $provider ) );
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );
		return array(
			'rows' => $rows,
			'count' => count( $rows ),
			'fingerprint' => hash( 'sha256', wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
		);
	}

	private static function current_agent() {
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return new WP_Error( 'mad4b_grant_reconcile_identity_unavailable', 'Governance identity components are unavailable.' );
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_oauth_identity_required', 'Verified OAuth bearer governance identity is required.' );
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;
		if ( 'chatgpt-governed-write' !== (string) $agent['slug'] || 'enabled' !== (string) $agent['status'] || 'staging' !== (string) $agent['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_agent_invalid', 'Resolved governance identity is not the canonical enabled Staging write agent.' );
		if ( (int) $agent['wp_user_id'] !== get_current_user_id() ) return new WP_Error( 'mad4b_grant_reconcile_agent_user_mismatch', 'Resolved governed-write agent belongs to another WordPress user.' );
		return $agent;
	}

	private static function rollback_created( array $agent, array $grant_ids ) {
		$errors = array();
		foreach ( array_reverse( $grant_ids ) as $grant_id ) {
			$result = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant_id, 'mad4b-write' );
			if ( is_wp_error( $result ) ) $errors[] = $result->get_error_code() . ':' . (int) $grant_id;
		}
		return $errors;
	}

	private static function rollback_transaction( array $agent, array $grant_ids, $authority_checkpoint ) {
		$errors = self::rollback_created( $agent, $grant_ids );
		if ( empty( $errors ) ) {
			$restored = MAD4B_SCP_Staging_Write_Authority::restore_persistence_checkpoint( $authority_checkpoint );
			if ( is_wp_error( $restored ) ) {
				$errors[] = $restored->get_error_code() . ':authority_checkpoint';
				$blocked = MAD4B_SCP_Staging_Write_Authority::fail_closed_persisted_authority( 'authority_checkpoint_restore_failed' );
				if ( is_wp_error( $blocked ) ) $errors[] = $blocked->get_error_code() . ':authority_fail_closed';
			}
		} else {
			// Never restore a previously-ready persisted snapshot while any newly-created
			// grant could still exist. Force the hot path blocked across fresh requests.
			$blocked = MAD4B_SCP_Staging_Write_Authority::fail_closed_persisted_authority( 'grant_rollback_incomplete' );
			if ( is_wp_error( $blocked ) ) $errors[] = $blocked->get_error_code() . ':authority_fail_closed';
		}
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			$audit = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-rolled-back', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => isset( $agent['public_id'] ) ? (string) $agent['public_id'] : '',
				'created_grant_ids' => array_values( array_map( 'intval', $grant_ids ) ),
				'rollback_complete' => empty( $errors ),
				'rollback_errors' => array_values( $errors ),
				'authority_forced_blocked' => ! empty( $errors ),
				'production_mutation' => false,
			), empty( $errors ) ? 'ok' : 'error' );
			if ( is_wp_error( $audit ) ) $errors[] = $audit->get_error_code() . ':rollback_audit';
		}
		return $errors;
	}

	public static function reconcile( $input ) {
		if ( self::$running ) return new WP_Error( 'mad4b_grant_reconcile_reentry_denied', 'Grant reconciliation is already running in this request.' );
		$permission = self::can_execute( $input );
		if ( is_wp_error( $permission ) || ! $permission ) return $permission;
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_grant_reconcile_input_invalid', 'Input must be an object.' );
		if ( self::CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_confirmation_required', 'Exact reconciliation confirmation is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan' ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_unavailable', 'Exact reconciliation plan authority is unavailable.' );
		$plan = MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan::plan();
		if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $plan['eligible'] ) || ! empty( $plan['blockers'] ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_blocked', 'Current exact reconciliation plan is blocked.', array( 'blockers' => isset( $plan['blockers'] ) ? $plan['blockers'] : array() ) );
		$expected_plan_sha = isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '';
		$current_plan_sha = isset( $plan['plan_sha256'] ) ? strtolower( trim( (string) $plan['plan_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_plan_sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $current_plan_sha ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_digest_required', 'A valid expected_plan_sha256 from the reviewed reconciliation plan is required.' );
		if ( ! hash_equals( $current_plan_sha, $expected_plan_sha ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_changed', 'Reconciliation plan changed since review.', array( 'current_plan_sha256' => $current_plan_sha, 'expected_plan_sha256' => $expected_plan_sha ) );

		self::$running = true;
		try {
			if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) return new WP_Error( 'mad4b_grant_reconcile_schema_unavailable', 'Governance schema must be physically ready.' );
			$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
			if ( empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_grant_reconcile_audit_required', 'Ready append-only audit storage is required.' );

			$current_revision = MAD4B_SCP_Site_Profile::revision();
			$expected_revision = isset( $input['expected_revision'] ) ? absint( $input['expected_revision'] ) : 0;
			if ( $current_revision !== $expected_revision ) return new WP_Error( 'mad4b_grant_reconcile_revision_stale', 'Site Profile revision changed before reconciliation.' );
			$current_digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
			$expected_digest = isset( $input['expected_profile_digest'] ) ? strtolower( trim( (string) $input['expected_profile_digest'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_digest ) || ! hash_equals( $current_digest, $expected_digest ) ) return new WP_Error( 'mad4b_grant_reconcile_profile_stale', 'Site Profile digest changed before reconciliation.' );

			if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_grant_reconcile_provenance_unavailable', 'Build provenance authority is unavailable.' );
			$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
			$current_sha = is_array( $provenance ) && isset( $provenance['source_commit_sha'] ) ? strtolower( (string) $provenance['source_commit_sha'] ) : '';
			$current_fingerprint = is_array( $provenance ) && isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
			$expected_sha = isset( $input['expected_source_commit_sha'] ) ? strtolower( trim( (string) $input['expected_source_commit_sha'] ) ) : '';
			$expected_fingerprint = isset( $input['expected_build_fingerprint'] ) ? strtolower( trim( (string) $input['expected_build_fingerprint'] ) ) : '';
			if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) || ! empty( $provenance['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_grant_reconcile_provenance_not_ready', 'Exact current build provenance is not ready.' );
			if ( ! preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || ! hash_equals( $expected_sha, $current_sha ) ) return new WP_Error( 'mad4b_grant_reconcile_candidate_mismatch', 'Source commit does not match the exact requested candidate.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_fingerprint ) || ! hash_equals( $expected_fingerprint, $current_fingerprint ) ) return new WP_Error( 'mad4b_grant_reconcile_fingerprint_mismatch', 'Build fingerprint does not match the exact requested candidate.' );

			$agent = self::current_agent();
			if ( is_wp_error( $agent ) ) return $agent;
			$expected_agent = isset( $input['expected_agent_public_id'] ) ? strtolower( trim( (string) $input['expected_agent_public_id'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9-]{36}$/', $expected_agent ) || ! hash_equals( strtolower( (string) $agent['public_id'] ), $expected_agent ) ) return new WP_Error( 'mad4b_grant_reconcile_agent_mismatch', 'Resolved canonical agent does not match the explicitly requested agent.' );

			$inventory = self::live_inventory();
			if ( is_wp_error( $inventory ) ) return $inventory;
			$expected_count = isset( $input['expected_write_tool_count'] ) ? absint( $input['expected_write_tool_count'] ) : 0;
			$expected_inventory = isset( $input['expected_write_inventory_fingerprint'] ) ? strtolower( trim( (string) $input['expected_write_inventory_fingerprint'] ) ) : '';
			if ( $expected_count !== (int) $inventory['count'] ) return new WP_Error( 'mad4b_grant_reconcile_inventory_count_mismatch', 'Governed write inventory count changed before reconciliation.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_inventory ) || ! hash_equals( (string) $inventory['fingerprint'], $expected_inventory ) ) return new WP_Error( 'mad4b_grant_reconcile_inventory_fingerprint_mismatch', 'Governed write inventory fingerprint changed before reconciliation.' );

			$counts = MAD4B_SCP_Agent_Registry::counts();
			if ( ! empty( $counts['wildcard_grants'] ) ) return new WP_Error( 'mad4b_grant_reconcile_wildcard_grant_detected', 'Wildcard grants must be absent before exact reconciliation.' );

			$desired = array();
			foreach ( $inventory['rows'] as $row ) $desired[ $row['ability'] . "\0" . $row['provider'] ] = $row;
			$existing_rows = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
			$seen_allow = array();
			foreach ( $existing_rows as $grant ) {
				if ( 'allow' !== (string) $grant['effect'] ) continue;
				$key = (string) $grant['ability_name'] . "\0" . sanitize_key( (string) $grant['provider'] );
				if ( ! isset( $desired[ $key ] ) ) return new WP_Error( 'mad4b_grant_reconcile_stale_allow_present', 'Unexpected/stale mad4b-write allow grant must be reconciled separately.', array( 'ability' => $grant['ability_name'], 'provider' => $grant['provider'] ) );
				if ( 'staging' !== (string) $grant['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_non_staging_allow_present', 'Exact reconciliation refuses all-environment or non-Staging grants.', array( 'ability' => $grant['ability_name'] ) );
				if ( isset( $seen_allow[ $key ] ) ) return new WP_Error( 'mad4b_grant_reconcile_duplicate_allow_present', 'Duplicate exact mad4b-write allow grant detected.', array( 'ability' => $grant['ability_name'] ) );
				$seen_allow[ $key ] = true;
			}

			$missing = array();
			$providers = array();
			foreach ( $inventory['rows'] as $row ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $row['ability'], $row['provider'] );
				if ( is_wp_error( $grant ) ) {
					if ( 'mad4b_nhi_grant_missing' !== $grant->get_error_code() ) return new WP_Error( 'mad4b_grant_reconcile_existing_grant_blocked', 'Existing exact grant state is not reconcilable by this bounded operation.', array( 'ability' => $row['ability'], 'code' => $grant->get_error_code() ) );
					$missing[] = $row['ability'];
					$providers[ $row['ability'] ] = $row['provider'];
				}
			}
			sort( $missing, SORT_STRING );

			$allowed_providers = self::allowed_ability_providers();
			$allowed = array_keys( $allowed_providers );
			sort( $allowed, SORT_STRING );
			foreach ( $missing as $ability ) {
				if ( ! in_array( $ability, $allowed, true ) ) return new WP_Error( 'mad4b_grant_reconcile_missing_outside_allowlist', 'A missing grant exists outside the reviewed provider reconciliation allowlist.', array( 'ability' => $ability ) );
				$expected_provider = isset( $allowed_providers[ $ability ] ) ? sanitize_key( (string) $allowed_providers[ $ability ] ) : '';
				if ( ! isset( $providers[ $ability ] ) || $expected_provider !== sanitize_key( (string) $providers[ $ability ] ) ) return new WP_Error( 'mad4b_grant_reconcile_provider_mismatch', 'Reviewed grant does not resolve to its exact allowlisted provider.', array( 'ability' => $ability, 'expected_provider' => $expected_provider, 'provider' => isset( $providers[ $ability ] ) ? $providers[ $ability ] : '' ) );
			}
			$expected_missing = self::normalized_expected_missing( $input );
			if ( $missing !== $expected_missing ) return new WP_Error( 'mad4b_grant_reconcile_missing_set_mismatch', 'Live missing-grant set changed or does not match the explicit authorization input.', array( 'live_missing' => $missing, 'expected_missing' => $expected_missing ) );
			$intent = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-authorized', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'environment' => 'staging',
				'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
				'site_profile_revision' => $current_revision,
				'site_profile_digest' => $current_digest,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'write_tool_count' => (int) $inventory['count'],
				'write_inventory_fingerprint' => (string) $inventory['fingerprint'],
				'exact_missing_abilities' => $missing,
				'confirmation' => self::CONFIRMATION,
				'plan_sha256' => $current_plan_sha,
				'production_mutation' => false,
				'breakglass_included' => false,
			), 'ok' );
			if ( is_wp_error( $intent ) ) return new WP_Error( 'mad4b_grant_reconcile_intent_audit_failed', 'Authorization evidence could not be committed before grant mutation.' );
			$authority_checkpoint = MAD4B_SCP_Staging_Write_Authority::persistence_checkpoint();

			$created_ids = array();
			$created_abilities = array();
			foreach ( $missing as $ability ) {
				$provider = $providers[ $ability ];
				$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', $ability, $provider, array(), 'allow', 'staging' );
				if ( is_wp_error( $created ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_create_failed', 'Exact grant creation failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'code' => $created->get_error_code(), 'rollback_errors' => $rollback ) );
				}
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $provider );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || 'staging' !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_postcondition_failed', 'New exact grant failed immediate postcondition verification.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
				$created_ids[] = (int) $grant['id'];
				$created_abilities[] = $ability;
				$grant_audit = MAD4B_SCP_Audit::record( 'mad4b/exact-staging-write-grant-reconciled', array(
					'contract' => self::CONTRACT,
					'agent_public_id' => (string) $agent['public_id'],
					'server_id' => 'mad4b-write',
					'ability' => $ability,
					'provider' => $provider,
					'environment' => 'staging',
					'grant_id' => (int) $grant['id'],
					'source_commit_sha' => $current_sha,
					'write_inventory_fingerprint' => (string) $inventory['fingerprint'],
				), 'ok' );
				if ( is_wp_error( $grant_audit ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_grant_audit_failed', 'Per-grant audit failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			foreach ( $missing as $ability ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $providers[ $ability ] );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || 'staging' !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_final_grant_check_failed', 'Exact grant set did not converge after mutation; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			$authority = MAD4B_SCP_Staging_Write_Authority::finalize_exact_existing_authority();
			if ( ! is_array( $authority ) || empty( $authority['ready'] ) || 'ready' !== ( isset( $authority['state'] ) ? (string) $authority['state'] : '' ) || ! empty( $authority['grant_blockers'] ) || (int) ( isset( $authority['write_tool_count'] ) ? $authority['write_tool_count'] : 0 ) !== (int) $inventory['count'] || ! isset( $authority['write_inventory_fingerprint'] ) || ! hash_equals( (string) $inventory['fingerprint'], (string) $authority['write_inventory_fingerprint'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_authority_not_ready', 'Authority did not converge after exact grant reconciliation; newly-created grants were rolled back.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}
			if ( ! empty( $authority['exact_grants_created'] ) || ! empty( $authority['stale_allow_grants_revoked'] ) || ! empty( $authority['stale_subjects_disabled'] ) || ! empty( $authority['duplicate_exact_allow_grants_revoked'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_unexpected_authority_mutation', 'Authority reconciliation attempted mutations outside the explicitly created exact grant set.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}

			if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding' ) || ! method_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding', 'operation_context' ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_unavailable', 'Audited candidate-binding context factory is unavailable; newly-created grants were rolled back.', array( 'rollback_errors' => $rollback ) );
			}
			$binding_plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
			$binding_snapshot = isset( $binding_plan['candidate_binding'] ) && is_array( $binding_plan['candidate_binding'] ) ? $binding_plan['candidate_binding'] : array();
			$binding_context = MAD4B_SCP_Staging_Write_Candidate_Binding::operation_context(
				$binding_plan,
				$binding_snapshot,
				$current_revision,
				$current_digest,
				self::CONFIRMATION,
				'grant_reconciliation',
				self::CONTRACT
			);
			if ( is_wp_error( $binding_context ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_failed', 'Audited candidate-binding context could not be created after grant reconciliation; newly-created grants were rolled back.', array( 'code' => $binding_context->get_error_code(), 'rollback_errors' => $rollback ) );
			}
			$bound = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( $current_sha, $current_fingerprint, $binding_context );
			if ( is_wp_error( $bound ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_candidate_binding_failed', 'Exact package candidate could not be bound after grant reconciliation; newly-created grants were rolled back.', array( 'code' => $bound->get_error_code(), 'rollback_errors' => $rollback ) );
			}
			$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
			if ( empty( $binding['required'] ) || empty( $binding['match'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_candidate_not_effective', 'Exact package candidate binding is not effective after reconciliation.', array( 'binding' => $binding, 'rollback_errors' => $rollback ) );
			}
			$authority = $bound;

			$completion = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-complete', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'created_count' => count( $created_abilities ),
				'created_abilities' => $created_abilities,
				'write_tool_count' => (int) $authority['write_tool_count'],
				'write_inventory_fingerprint' => (string) $authority['write_inventory_fingerprint'],
				'exact_grants_existing' => isset( $authority['exact_grants_existing'] ) ? (int) $authority['exact_grants_existing'] : 0,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'plan_sha256' => $current_plan_sha,
				'candidate_rebound_without_grant_changes' => empty( $created_abilities ),
				'authority_ready' => true,
				'production_mutation' => false,
			), 'ok' );
			if ( is_wp_error( $completion ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_completion_audit_failed', 'Completion audit failed; newly-created grants and persisted authority state were rolled back.', array( 'rollback_errors' => $rollback ) );
			}

			return array(
				'contract' => self::CONTRACT,
				'state' => empty( $created_abilities ) ? 'candidate_rebound' : 'reconciled',
				'agent_public_id' => (string) $agent['public_id'],
				'created_count' => count( $created_abilities ),
				'created_abilities' => $created_abilities,
				'write_tool_count' => (int) $authority['write_tool_count'],
				'write_inventory_fingerprint' => (string) $authority['write_inventory_fingerprint'],
				'exact_grants_existing' => isset( $authority['exact_grants_existing'] ) ? (int) $authority['exact_grants_existing'] : 0,
				'exact_grants_created_by_authority_reconcile' => isset( $authority['exact_grants_created'] ) ? (int) $authority['exact_grants_created'] : 0,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'plan_sha256' => $current_plan_sha,
				'candidate_binding_match' => ! empty( $binding['match'] ),
				'candidate_rebound_without_grant_changes' => empty( $created_abilities ),
				'runtime_reconciled' => true,
				'authority_ready' => true,
				'completion_audit_recorded' => true,
				'breakglass_included' => false,
				'production_mutation' => false,
			);
		} finally {
			self::$running = false;
		}
	}
}
 ),
						'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
						'expected_profile_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
						'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' ),
						'expected_write_tool_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
						'expected_write_inventory_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_missing_abilities' => array(
							'type' => 'array',
							'minItems' => 0,
							'maxItems' => 32,
							'uniqueItems' => true,
							'items' => array( 'type' => 'string', 'enum' => self::allowed_abilities() ),
						),
						'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) ),
					),
					'required' => array(
						'expected_revision',
						'expected_profile_digest',
						'expected_source_commit_sha',
						'expected_build_fingerprint',
						'expected_agent_public_id',
						'expected_write_tool_count',
						'expected_write_inventory_fingerprint',
						'expected_missing_abilities',
						'confirmation',
					),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array(
						'public' => false,
						'type' => 'tool',
						'surface' => 'enrollment',
						'mad4b_grant_reconciliation_authority' => self::CONTRACT,
						'creates_exact_staging_grants_only' => true,
						'auto_approves' => false,
					),
					'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
				),
			) );
		} finally {
			if ( false !== $priority ) add_filter( 'wp_register_ability_args', $augment, (int) $priority, 2 );
		}
	}

	public static function can_execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_grant_reconcile_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_grant_reconcile_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_grant_reconcile_profile_missing', 'An enrolled Site Profile is required.' );
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_grant_reconcile_staging_only', 'Exact grant reconciliation is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_grant_reconcile_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Site Profile.' );
		if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) return new WP_Error( 'mad4b_grant_reconcile_write_disabled', 'Governed write must already be enabled.' );
		if ( 'chatgpt-governed-write' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() ) ) return new WP_Error( 'mad4b_grant_reconcile_canonical_agent_required', 'Grant reconciliation is limited to the canonical profile-owned governed-write agent.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_grant_reconcile_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		return true;
	}

	private static function normalized_expected_missing( $input ) {
		$items = isset( $input['expected_missing_abilities'] ) && is_array( $input['expected_missing_abilities'] ) ? array_values( array_unique( array_map( 'strval', $input['expected_missing_abilities'] ) ) ) : array();
		sort( $items, SORT_STRING );
		return $items;
	}

	private static function live_inventory() {
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! class_exists( 'MAD4B_SCP_Servers' ) ) return new WP_Error( 'mad4b_grant_reconcile_authority_unavailable', 'Governed write authority components are unavailable.' );
		$tools = MAD4B_SCP_Staging_Write_Authority::write_tools();
		if ( ! is_array( $tools ) || empty( $tools ) ) return new WP_Error( 'mad4b_grant_reconcile_inventory_empty', 'Current governed write inventory is empty.' );

		$rows = array();
		foreach ( $tools as $ability ) {
			$ability = (string) $ability;
			if ( 'mad4b/database-raw-query' === $ability ) return new WP_Error( 'mad4b_grant_reconcile_breakglass_leak', 'Breakglass/raw SQL must never enter governed grant reconciliation.' );
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
			if ( null === $provider ) return new WP_Error( 'mad4b_grant_reconcile_unmounted_ability', 'A current write ability has no exact mad4b-write provider mount.', array( 'ability' => $ability ) );
			$rows[] = array( 'ability' => $ability, 'provider' => sanitize_key( (string) $provider ) );
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );
		return array(
			'rows' => $rows,
			'count' => count( $rows ),
			'fingerprint' => hash( 'sha256', wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
		);
	}

	private static function current_agent() {
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return new WP_Error( 'mad4b_grant_reconcile_identity_unavailable', 'Governance identity components are unavailable.' );
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_oauth_identity_required', 'Verified OAuth bearer governance identity is required.' );
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;
		if ( 'chatgpt-governed-write' !== (string) $agent['slug'] || 'enabled' !== (string) $agent['status'] || 'staging' !== (string) $agent['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_agent_invalid', 'Resolved governance identity is not the canonical enabled Staging write agent.' );
		if ( (int) $agent['wp_user_id'] !== get_current_user_id() ) return new WP_Error( 'mad4b_grant_reconcile_agent_user_mismatch', 'Resolved governed-write agent belongs to another WordPress user.' );
		return $agent;
	}

	private static function rollback_created( array $agent, array $grant_ids ) {
		$errors = array();
		foreach ( array_reverse( $grant_ids ) as $grant_id ) {
			$result = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant_id, 'mad4b-write' );
			if ( is_wp_error( $result ) ) $errors[] = $result->get_error_code() . ':' . (int) $grant_id;
		}
		return $errors;
	}

	public static function reconcile( $input ) {
		if ( self::$running ) return new WP_Error( 'mad4b_grant_reconcile_reentry_denied', 'Grant reconciliation is already running in this request.' );
		$permission = self::can_execute( $input );
		if ( is_wp_error( $permission ) || ! $permission ) return $permission;
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_grant_reconcile_input_invalid', 'Input must be an object.' );
		if ( self::CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_confirmation_required', 'Exact reconciliation confirmation is required.' );

		self::$running = true;
		try {
			if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) return new WP_Error( 'mad4b_grant_reconcile_schema_unavailable', 'Governance schema must be physically ready.' );
			$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
			if ( empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_grant_reconcile_audit_required', 'Ready append-only audit storage is required.' );

			$current_revision = MAD4B_SCP_Site_Profile::revision();
			$expected_revision = isset( $input['expected_revision'] ) ? absint( $input['expected_revision'] ) : 0;
			if ( $current_revision !== $expected_revision ) return new WP_Error( 'mad4b_grant_reconcile_revision_stale', 'Site Profile revision changed before reconciliation.' );
			$current_digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
			$expected_digest = isset( $input['expected_profile_digest'] ) ? strtolower( trim( (string) $input['expected_profile_digest'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_digest ) || ! hash_equals( $current_digest, $expected_digest ) ) return new WP_Error( 'mad4b_grant_reconcile_profile_stale', 'Site Profile digest changed before reconciliation.' );

			if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_grant_reconcile_provenance_unavailable', 'Build provenance authority is unavailable.' );
			$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
			$current_sha = is_array( $provenance ) && isset( $provenance['source_commit_sha'] ) ? strtolower( (string) $provenance['source_commit_sha'] ) : '';
			$current_fingerprint = is_array( $provenance ) && isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
			$expected_sha = isset( $input['expected_source_commit_sha'] ) ? strtolower( trim( (string) $input['expected_source_commit_sha'] ) ) : '';
			$expected_fingerprint = isset( $input['expected_build_fingerprint'] ) ? strtolower( trim( (string) $input['expected_build_fingerprint'] ) ) : '';
			if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) || ! empty( $provenance['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_grant_reconcile_provenance_not_ready', 'Exact current build provenance is not ready.' );
			if ( ! preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || ! hash_equals( $expected_sha, $current_sha ) ) return new WP_Error( 'mad4b_grant_reconcile_candidate_mismatch', 'Source commit does not match the exact requested candidate.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_fingerprint ) || ! hash_equals( $expected_fingerprint, $current_fingerprint ) ) return new WP_Error( 'mad4b_grant_reconcile_fingerprint_mismatch', 'Build fingerprint does not match the exact requested candidate.' );

			$agent = self::current_agent();
			if ( is_wp_error( $agent ) ) return $agent;
			$expected_agent = isset( $input['expected_agent_public_id'] ) ? strtolower( trim( (string) $input['expected_agent_public_id'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9-]{36}$/', $expected_agent ) || ! hash_equals( strtolower( (string) $agent['public_id'] ), $expected_agent ) ) return new WP_Error( 'mad4b_grant_reconcile_agent_mismatch', 'Resolved canonical agent does not match the explicitly requested agent.' );

			$inventory = self::live_inventory();
			if ( is_wp_error( $inventory ) ) return $inventory;
			$expected_count = isset( $input['expected_write_tool_count'] ) ? absint( $input['expected_write_tool_count'] ) : 0;
			$expected_inventory = isset( $input['expected_write_inventory_fingerprint'] ) ? strtolower( trim( (string) $input['expected_write_inventory_fingerprint'] ) ) : '';
			if ( $expected_count !== (int) $inventory['count'] ) return new WP_Error( 'mad4b_grant_reconcile_inventory_count_mismatch', 'Governed write inventory count changed before reconciliation.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_inventory ) || ! hash_equals( (string) $inventory['fingerprint'], $expected_inventory ) ) return new WP_Error( 'mad4b_grant_reconcile_inventory_fingerprint_mismatch', 'Governed write inventory fingerprint changed before reconciliation.' );

			$counts = MAD4B_SCP_Agent_Registry::counts();
			if ( ! empty( $counts['wildcard_grants'] ) ) return new WP_Error( 'mad4b_grant_reconcile_wildcard_grant_detected', 'Wildcard grants must be absent before exact reconciliation.' );

			$desired = array();
			foreach ( $inventory['rows'] as $row ) $desired[ $row['ability'] . "\0" . $row['provider'] ] = $row;
			$existing_rows = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
			$seen_allow = array();
			foreach ( $existing_rows as $grant ) {
				if ( 'allow' !== (string) $grant['effect'] ) continue;
				$key = (string) $grant['ability_name'] . "\0" . sanitize_key( (string) $grant['provider'] );
				if ( ! isset( $desired[ $key ] ) ) return new WP_Error( 'mad4b_grant_reconcile_stale_allow_present', 'Unexpected/stale mad4b-write allow grant must be reconciled separately.', array( 'ability' => $grant['ability_name'], 'provider' => $grant['provider'] ) );
				if ( 'staging' !== (string) $grant['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_non_staging_allow_present', 'Exact reconciliation refuses all-environment or non-Staging grants.', array( 'ability' => $grant['ability_name'] ) );
				if ( isset( $seen_allow[ $key ] ) ) return new WP_Error( 'mad4b_grant_reconcile_duplicate_allow_present', 'Duplicate exact mad4b-write allow grant detected.', array( 'ability' => $grant['ability_name'] ) );
				$seen_allow[ $key ] = true;
			}

			$missing = array();
			$providers = array();
			foreach ( $inventory['rows'] as $row ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $row['ability'], $row['provider'] );
				if ( is_wp_error( $grant ) ) {
					if ( 'mad4b_nhi_grant_missing' !== $grant->get_error_code() ) return new WP_Error( 'mad4b_grant_reconcile_existing_grant_blocked', 'Existing exact grant state is not reconcilable by this bounded operation.', array( 'ability' => $row['ability'], 'code' => $grant->get_error_code() ) );
					$missing[] = $row['ability'];
					$providers[ $row['ability'] ] = $row['provider'];
				}
			}
			sort( $missing, SORT_STRING );

			$allowed_providers = self::allowed_ability_providers();
			$allowed = array_keys( $allowed_providers );
			sort( $allowed, SORT_STRING );
			foreach ( $missing as $ability ) {
				if ( ! in_array( $ability, $allowed, true ) ) return new WP_Error( 'mad4b_grant_reconcile_missing_outside_allowlist', 'A missing grant exists outside the reviewed provider reconciliation allowlist.', array( 'ability' => $ability ) );
				$expected_provider = isset( $allowed_providers[ $ability ] ) ? sanitize_key( (string) $allowed_providers[ $ability ] ) : '';
				if ( ! isset( $providers[ $ability ] ) || $expected_provider !== sanitize_key( (string) $providers[ $ability ] ) ) return new WP_Error( 'mad4b_grant_reconcile_provider_mismatch', 'Reviewed grant does not resolve to its exact allowlisted provider.', array( 'ability' => $ability, 'expected_provider' => $expected_provider, 'provider' => isset( $providers[ $ability ] ) ? $providers[ $ability ] : '' ) );
			}
			$expected_missing = self::normalized_expected_missing( $input );
			if ( $missing !== $expected_missing ) return new WP_Error( 'mad4b_grant_reconcile_missing_set_mismatch', 'Live missing-grant set changed or does not match the explicit authorization input.', array( 'live_missing' => $missing, 'expected_missing' => $expected_missing ) );
			$intent = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-authorized', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'environment' => 'staging',
				'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
				'site_profile_revision' => $current_revision,
				'site_profile_digest' => $current_digest,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'write_tool_count' => (int) $inventory['count'],
				'write_inventory_fingerprint' => (string) $inventory['fingerprint'],
				'exact_missing_abilities' => $missing,
				'confirmation' => self::CONFIRMATION,
				'production_mutation' => false,
				'breakglass_included' => false,
			), 'ok' );
			if ( is_wp_error( $intent ) ) return new WP_Error( 'mad4b_grant_reconcile_intent_audit_failed', 'Authorization evidence could not be committed before grant mutation.' );

			$created_ids = array();
			$created_abilities = array();
			foreach ( $missing as $ability ) {
				$provider = $providers[ $ability ];
				$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', $ability, $provider, array(), 'allow', 'staging' );
				if ( is_wp_error( $created ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_create_failed', 'Exact grant creation failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'code' => $created->get_error_code(), 'rollback_errors' => $rollback ) );
				}
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $provider );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || 'staging' !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_postcondition_failed', 'New exact grant failed immediate postcondition verification.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
				$created_ids[] = (int) $grant['id'];
				$created_abilities[] = $ability;
				$grant_audit = MAD4B_SCP_Audit::record( 'mad4b/exact-staging-write-grant-reconciled', array(
					'contract' => self::CONTRACT,
					'agent_public_id' => (string) $agent['public_id'],
					'server_id' => 'mad4b-write',
					'ability' => $ability,
					'provider' => $provider,
					'environment' => 'staging',
					'grant_id' => (int) $grant['id'],
					'source_commit_sha' => $current_sha,
					'write_inventory_fingerprint' => (string) $inventory['fingerprint'],
				), 'ok' );
				if ( is_wp_error( $grant_audit ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_grant_audit_failed', 'Per-grant audit failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			foreach ( $missing as $ability ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $providers[ $ability ] );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || 'staging' !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
					return new WP_Error( 'mad4b_grant_reconcile_final_grant_check_failed', 'Exact grant set did not converge after mutation; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			$authority = MAD4B_SCP_Staging_Write_Authority::reconcile();
			if ( ! is_array( $authority ) || empty( $authority['ready'] ) || 'ready' !== ( isset( $authority['state'] ) ? (string) $authority['state'] : '' ) || ! empty( $authority['grant_blockers'] ) || (int) ( isset( $authority['write_tool_count'] ) ? $authority['write_tool_count'] : 0 ) !== (int) $inventory['count'] || ! isset( $authority['write_inventory_fingerprint'] ) || ! hash_equals( (string) $inventory['fingerprint'], (string) $authority['write_inventory_fingerprint'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_authority_not_ready', 'Authority did not converge after exact grant reconciliation; newly-created grants were rolled back.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}
			if ( ! empty( $authority['exact_grants_created'] ) || ! empty( $authority['stale_allow_grants_revoked'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_unexpected_authority_mutation', 'Authority reconciliation attempted mutations outside the explicitly created exact grant set.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}

			if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding' ) || ! method_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding', 'operation_context' ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_unavailable', 'Audited candidate-binding context factory is unavailable; newly-created grants were rolled back.', array( 'rollback_errors' => $rollback ) );
			}
			$binding_plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
			$binding_snapshot = isset( $binding_plan['candidate_binding'] ) && is_array( $binding_plan['candidate_binding'] ) ? $binding_plan['candidate_binding'] : array();
			$binding_context = MAD4B_SCP_Staging_Write_Candidate_Binding::operation_context(
				$binding_plan,
				$binding_snapshot,
				$current_revision,
				$current_digest,
				self::CONFIRMATION,
				'grant_reconciliation',
				self::CONTRACT
			);
			if ( is_wp_error( $binding_context ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_failed', 'Audited candidate-binding context could not be created after grant reconciliation; newly-created grants were rolled back.', array( 'code' => $binding_context->get_error_code(), 'rollback_errors' => $rollback ) );
			}
			$bound = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( $current_sha, $current_fingerprint, $binding_context );
			if ( is_wp_error( $bound ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_candidate_binding_failed', 'Exact package candidate could not be bound after grant reconciliation; newly-created grants were rolled back.', array( 'code' => $bound->get_error_code(), 'rollback_errors' => $rollback ) );
			}
			$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
			if ( empty( $binding['required'] ) || empty( $binding['match'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint );
				return new WP_Error( 'mad4b_grant_reconcile_candidate_not_effective', 'Exact package candidate binding is not effective after reconciliation.', array( 'binding' => $binding, 'rollback_errors' => $rollback ) );
			}
			$authority = $bound;

			$completion = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-complete', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'created_count' => count( $created_abilities ),
				'created_abilities' => $created_abilities,
				'write_tool_count' => (int) $authority['write_tool_count'],
				'write_inventory_fingerprint' => (string) $authority['write_inventory_fingerprint'],
				'exact_grants_existing' => isset( $authority['exact_grants_existing'] ) ? (int) $authority['exact_grants_existing'] : 0,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'candidate_rebound_without_grant_changes' => empty( $created_abilities ),
				'authority_ready' => true,
				'production_mutation' => false,
			), 'ok' );

			return array(
				'contract' => self::CONTRACT,
				'state' => empty( $created_abilities ) ? 'candidate_rebound' : 'reconciled',
				'agent_public_id' => (string) $agent['public_id'],
				'created_count' => count( $created_abilities ),
				'created_abilities' => $created_abilities,
				'write_tool_count' => (int) $authority['write_tool_count'],
				'write_inventory_fingerprint' => (string) $authority['write_inventory_fingerprint'],
				'exact_grants_existing' => isset( $authority['exact_grants_existing'] ) ? (int) $authority['exact_grants_existing'] : 0,
				'exact_grants_created_by_authority_reconcile' => isset( $authority['exact_grants_created'] ) ? (int) $authority['exact_grants_created'] : 0,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'candidate_binding_match' => ! empty( $binding['match'] ),
				'candidate_rebound_without_grant_changes' => empty( $created_abilities ),
				'runtime_reconciled' => true,
				'authority_ready' => true,
				'completion_audit_recorded' => ! is_wp_error( $completion ),
				'breakglass_included' => false,
				'production_mutation' => false,
			);
		} finally {
			self::$running = false;
		}
	}
}
