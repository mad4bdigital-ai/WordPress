<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact environment-bound bounded Write Authority convergence.
 *
 * Supports exact Staging and exact Production profiles. Production remains
 * approval-only: this bootstrap never enables standing delegation, Developer,
 * Developer Breakglass, or raw-SQL Breakglass.
 *
 * This is deliberately NOT a normal mad4b-write ability. It can create exact
 * current-environment allow grants only for the reviewed provider/ability pairs below,
 * prove the clean runtime-eligible grant snapshot, then bind the exact current
 * four-part package candidate as the final commit point. It cannot create
 * wildcard grants, enable Developer/Developer Breakglass,
 * grant import/export or create/replace agents or subjects. Reviewed stale exact
 * current-environment grants may be retired as monotonic authority narrowing. Unknown stale
 * authority remains fail-closed and retired stale authority is never restored.
 */
final class MAD4B_SCP_Staging_Write_Grant_Reconciliation {
	const CONTRACT = 'mad4b.staging-write-grant-reconciliation.v3';
	const ABILITY = 'mad4b/staging-write-grant-reconcile';
	const CONFIRMATION = 'RECONCILE EXACT STAGING WRITE AUTHORITY';
	const PRODUCTION_CONFIRMATION = 'RECONCILE EXACT PRODUCTION WRITE AUTHORITY';

	private static $booted = false;
	private static $running = false;

	public static function current_environment() {
		return class_exists( 'MAD4B_SCP_Site_Profile' )
			? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() )
			: 'unknown';
	}

	public static function required_confirmation() {
		return 'production' === self::current_environment() ? self::PRODUCTION_CONFIRMATION : self::CONFIRMATION;
	}

	public static function allowed_ability_providers() {
		$allowed = array(
			'mad4b/plugin-package-apply' => 'core',
			'mad4b/plugin-remote-update-apply' => 'core',
			'mad4b/control-plane-upload-apply' => 'core',
			'mad4b/control-plane-native-apply' => 'core',
			'mad4b/context-ai-review' => 'core',
			'mad4b/approval-ai-decide' => 'core',
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
			'context/brand-draft-append' => 'google_drive_context',
			'context/brand-draft-create' => 'google_drive_context',
			'context/materialize-brand-draft' => 'google_drive_context',
			'context/reconcile-brand-materialization' => 'google_drive_context',
			'context/rollback-materialized-brand-draft' => 'google_drive_context',
			'context/source-scan-apply' => 'google_drive_context',
		);

		// Generic content authority remains exact and code-reviewed. Site runtime
		// models (CPTs/taxonomies/terms/meta) are dynamic, but the abilities that
		// may receive current-environment grants are restricted to the bundled core adapters
		// and their explicit reversible contracts. Never derive this allowlist
		// from third-party adapter registration hooks.
		if ( class_exists( 'MAD4B_SCP_Core_Content_Modeling_Adapter' ) ) {
			$allowed[ MAD4B_SCP_Core_Content_Modeling_Adapter::CREATE_POST_ABILITY ] = 'core';
			$allowed[ MAD4B_SCP_Core_Content_Modeling_Adapter::CREATE_TERM_ABILITY ] = 'core';
			$allowed[ MAD4B_SCP_Core_Content_Modeling_Adapter::SET_TERMS_ABILITY ] = 'core';
		}
		if ( class_exists( 'MAD4B_SCP_Dynamic_Content_Adapter' ) ) {
			$allowed[ MAD4B_SCP_Dynamic_Content_Adapter::APPLY ] = 'core';
			$allowed[ MAD4B_SCP_Dynamic_Content_Adapter::PIPELINE_UPDATE ] = 'core';
		}

		ksort( $allowed, SORT_STRING );
		return $allowed;
	}

	public static function allowed_abilities() {
		return array_keys( self::allowed_ability_providers() );
	}

	public static function retirable_stale_ability_providers() {
		// Retirement may recognize a strictly broader historical set than grant
		// creation. This is authority-narrowing only; these legacy pairs are never
		// eligible for new grant creation.
		$providers = self::allowed_ability_providers();
		$providers['elementor/update-widget-settings'] = 'elementor';
		ksort( $providers, SORT_STRING );
		return $providers;
	}

	public static function allowed_transport_ability_providers() {
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! method_exists( 'MAD4B_SCP_Servers', 'chatgpt_dispatch_transport_tools' ) ) return array();
		$providers = array();
		foreach ( MAD4B_SCP_Servers::chatgpt_dispatch_transport_tools() as $ability ) {
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-chatgpt', $ability );
			if ( null !== $provider ) $providers[ (string) $ability ] = sanitize_key( (string) $provider );
		}
		ksort( $providers, SORT_STRING );
		return $providers;
	}

	public static function allowed_transport_abilities() {
		return array_keys( self::allowed_transport_ability_providers() );
	}

	public static function chatgpt_read_tools() {
		return class_exists( 'MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan' )
			? array( MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan::ABILITY )
			: array();
	}

	public static function chatgpt_step_up_tools() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return array();
		if ( ! in_array( self::current_environment(), array( 'staging', 'production' ), true ) ) return array();
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return array();
		if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) return array();
		if ( defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) && true === constant( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) ) return array();
		return array( self::ABILITY );
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
				'label' => 'Converge Exact Governed Write Authority',
				'description' => 'Converge reviewed exact current-environment authority by creating exact missing grants and retiring reviewed stale exact grants, then prove the clean governed-write snapshot and bind the exact current package candidate without enabling Developer or Breakglass authority.',
				'category' => 'mad4b-governance',
				'execute_callback' => array( __CLASS__, 'reconcile' ),
				'permission_callback' => array( __CLASS__, 'can_execute' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'expected_plan_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
						'expected_profile_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
						'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_package_manifest_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_artifact_identity' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9._-]+$' ),
						'expected_agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' ),
						'expected_write_tool_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
						'expected_write_inventory_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_missing_abilities' => array(
							'type' => 'array',
							'minItems' => 0,
							'maxItems' => 32,
							'uniqueItems' => true,
							'items' => array(
								'type' => 'string',
								'minLength' => 3,
								'maxLength' => 191,
								'pattern' => '^[A-Za-z0-9._+\\/-]+$',
							),
						),
						'expected_stale_grant_ids' => array(
							'type' => 'array',
							'minItems' => 0,
							'maxItems' => 64,
							'uniqueItems' => true,
							'items' => array( 'type' => 'integer', 'minimum' => 1 ),
							'default' => array(),
							'description' => 'Optional transport assertion for backward-compatible clients. Omission means the caller asserts an empty stale-grant set; any live stale grant still fails closed.',
						),
						'expected_transport_tool_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 8 ),
						'expected_transport_inventory_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_missing_transport_abilities' => array(
							'type' => 'array',
							'minItems' => 0,
							'maxItems' => 8,
							'uniqueItems' => true,
							'items' => array(
								'type' => 'string',
								'minLength' => 3,
								'maxLength' => 191,
								'pattern' => '^[A-Za-z0-9._+\\/-]+$',
							),
						),
						'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION, self::PRODUCTION_CONFIRMATION ) ),
					),
					'required' => array(
						'expected_plan_sha256',
						'expected_revision',
						'expected_profile_digest',
						'expected_source_commit_sha',
						'expected_build_fingerprint',
						'expected_package_manifest_digest',
						'expected_artifact_identity',
						'expected_agent_public_id',
						'expected_write_tool_count',
						'expected_write_inventory_fingerprint',
						'expected_missing_abilities',
						'expected_transport_tool_count',
						'expected_transport_inventory_fingerprint',
						'expected_missing_transport_abilities',
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
						'creates_exact_environment_grants_only' => true,
						'retires_reviewed_stale_environment_grants' => true,
						'stale_retirement_is_authority_narrowing' => true,
						'expected_stale_grant_ids_optional_when_empty' => true,
						'stale_grant_omission_fails_closed_on_live_stale' => true,
						'binds_exact_package_candidate' => true,
						'candidate_binding_is_commit_point' => true,
						'enables_developer_authority' => false,
						'production_allowed' => true,
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
		$environment = self::current_environment();
		if ( ! in_array( $environment, array( 'staging', 'production' ), true ) ) return new WP_Error( 'mad4b_grant_reconcile_environment_denied', 'Exact grant reconciliation is limited to Staging or Production.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_grant_reconcile_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Site Profile.' );
		if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) return new WP_Error( 'mad4b_grant_reconcile_write_disabled', 'Governed write must already be enabled.' );
		if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) || true !== constant( 'MAD4B_MCP_MUTATION_ENABLED' ) ) return new WP_Error( 'mad4b_grant_reconcile_mutation_gate_disabled', 'The governed mutation master gate must already be enabled.' );
		if ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_breakglass() ) return new WP_Error( 'mad4b_grant_reconcile_breakglass_enabled', 'Bounded Write Authority convergence is denied while Breakglass authority is enabled.' );
		if ( 'https' !== strtolower( (string) wp_parse_url( MAD4B_SCP_Site_Profile::current_origin(), PHP_URL_SCHEME ) ) ) return new WP_Error( 'mad4b_grant_reconcile_https_required', 'Remote Write Authority convergence requires HTTPS.' );
		if ( ! defined( 'MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE' )
			|| ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ) {
			return new WP_Error( 'mad4b_grant_reconcile_step_up_scope_required', 'The OAuth bearer does not grant the dedicated authority step-up scope.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
			|| ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ) {
			return new WP_Error( 'mad4b_grant_reconcile_chatgpt_client_required', 'Bounded Write Authority convergence requires OAuth attribution to the exact ChatGPT CIMD client.' );
		}
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

	/**
	 * Omission is a compatibility-safe assertion that no stale grants are expected.
	 * The live stale set is still compared exactly, so any non-empty live stale set
	 * fails closed with mad4b_grant_reconcile_stale_set_mismatch.
	 */
	private static function normalized_expected_stale_grant_ids( $input ) {
		$items = isset( $input['expected_stale_grant_ids'] ) && is_array( $input['expected_stale_grant_ids'] ) ? array_values( array_unique( array_map( 'absint', $input['expected_stale_grant_ids'] ) ) ) : array();
		$items = array_values( array_filter( $items ) );
		sort( $items, SORT_NUMERIC );
		return $items;
	}

	private static function normalized_expected_missing_transport( $input ) {
		$items = isset( $input['expected_missing_transport_abilities'] ) && is_array( $input['expected_missing_transport_abilities'] )
			? array_values( array_unique( array_map( 'strval', $input['expected_missing_transport_abilities'] ) ) )
			: array();
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

	private static function live_transport_inventory() {
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! method_exists( 'MAD4B_SCP_Servers', 'chatgpt_dispatch_transport_tools' ) ) {
			return new WP_Error( 'mad4b_grant_reconcile_transport_unavailable', 'Bounded ChatGPT mutation transport inventory is unavailable.' );
		}
		$rows = array();
		foreach ( MAD4B_SCP_Servers::chatgpt_dispatch_transport_tools() as $ability ) {
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-chatgpt', $ability );
			if ( null === $provider ) return new WP_Error( 'mad4b_grant_reconcile_transport_unmounted', 'A required ChatGPT mutation dispatcher is not mounted on the canonical transport.', array( 'ability' => (string) $ability ) );
			$rows[] = array(
				'server_id' => 'mad4b-chatgpt',
				'ability' => (string) $ability,
				'provider' => sanitize_key( (string) $provider ),
			);
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
		$environment = self::current_environment();
		if ( 'chatgpt-governed-write' !== (string) $agent['slug'] || 'enabled' !== (string) $agent['status'] || $environment !== (string) $agent['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_agent_invalid', 'Resolved governance identity is not the canonical enabled current-environment write agent.' );
		if ( (int) $agent['wp_user_id'] !== get_current_user_id() ) return new WP_Error( 'mad4b_grant_reconcile_agent_user_mismatch', 'Resolved governed-write agent belongs to another WordPress user.' );
		return $agent;
	}

	private static function rollback_created( array $agent, array $grant_ids, $server_id ) {
		$errors = array();
		$server_id = sanitize_key( (string) $server_id );
		foreach ( array_reverse( $grant_ids ) as $grant_id ) {
			$result = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant_id, $server_id );
			if ( is_wp_error( $result ) ) $errors[] = $result->get_error_code() . ':' . $server_id . ':' . (int) $grant_id;
		}
		return $errors;
	}

	private static function rollback_transaction( array $agent, array $grant_ids, $authority_checkpoint, array $transport_grant_ids = array(), $restore_authority = true ) {
		$environment = self::current_environment();
		$errors = self::rollback_created( $agent, $transport_grant_ids, 'mad4b-chatgpt' );
		$errors = array_merge( $errors, self::rollback_created( $agent, $grant_ids, 'mad4b-write' ) );
		if ( empty( $errors ) && $restore_authority ) {
			$restored = MAD4B_SCP_Staging_Write_Authority::restore_persistence_checkpoint( $authority_checkpoint );
			if ( is_wp_error( $restored ) ) {
				$errors[] = $restored->get_error_code() . ':authority_checkpoint';
				$blocked = MAD4B_SCP_Staging_Write_Authority::fail_closed_persisted_authority( 'authority_checkpoint_restore_failed' );
				if ( is_wp_error( $blocked ) ) $errors[] = $blocked->get_error_code() . ':authority_fail_closed';
			}
		} else {
			// Never restore a previously-ready persisted snapshot while any newly-created
			// grant could still exist, or after monotonic stale-authority retirement.
			$reason = $restore_authority ? 'grant_rollback_incomplete' : 'stale_grants_retired_pending_reconciliation';
			$blocked = MAD4B_SCP_Staging_Write_Authority::fail_closed_persisted_authority( $reason );
			if ( is_wp_error( $blocked ) ) $errors[] = $blocked->get_error_code() . ':authority_fail_closed';
		}
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			$audit = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-rolled-back', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => isset( $agent['public_id'] ) ? (string) $agent['public_id'] : '',
				'created_grant_ids' => array_values( array_map( 'intval', $grant_ids ) ),
				'created_transport_grant_ids' => array_values( array_map( 'intval', $transport_grant_ids ) ),
				'rollback_complete' => empty( $errors ),
				'rollback_errors' => array_values( $errors ),
				'authority_forced_blocked' => ! $restore_authority || ! empty( $errors ),
				'authority_checkpoint_restored' => (bool) $restore_authority && empty( $errors ),
				'production_mutation' => 'production' === $environment,
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
		$environment = self::current_environment();
		$required_confirmation = self::required_confirmation();
		if ( ! hash_equals( $required_confirmation, isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_confirmation_required', 'Exact environment-specific reconciliation confirmation is required.' );

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
			$current_manifest = is_array( $provenance ) && isset( $provenance['package_manifest_digest'] ) ? strtolower( (string) $provenance['package_manifest_digest'] ) : '';
			$current_artifact = is_array( $provenance ) && isset( $provenance['artifact_identity'] ) ? trim( (string) $provenance['artifact_identity'] ) : '';
			$expected_sha = isset( $input['expected_source_commit_sha'] ) ? strtolower( trim( (string) $input['expected_source_commit_sha'] ) ) : '';
			$expected_fingerprint = isset( $input['expected_build_fingerprint'] ) ? strtolower( trim( (string) $input['expected_build_fingerprint'] ) ) : '';
			$expected_manifest = isset( $input['expected_package_manifest_digest'] ) ? strtolower( trim( (string) $input['expected_package_manifest_digest'] ) ) : '';
			$expected_artifact = isset( $input['expected_artifact_identity'] ) ? trim( (string) $input['expected_artifact_identity'] ) : '';
			if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) || ! empty( $provenance['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_grant_reconcile_provenance_not_ready', 'Exact current build provenance is not ready.' );
			if ( ! preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || ! hash_equals( $expected_sha, $current_sha ) ) return new WP_Error( 'mad4b_grant_reconcile_candidate_mismatch', 'Source commit does not match the exact requested candidate.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_fingerprint ) || ! hash_equals( $expected_fingerprint, $current_fingerprint ) ) return new WP_Error( 'mad4b_grant_reconcile_fingerprint_mismatch', 'Build fingerprint does not match the exact requested candidate.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_manifest ) || ! hash_equals( $expected_manifest, $current_manifest ) ) return new WP_Error( 'mad4b_grant_reconcile_manifest_mismatch', 'Package manifest digest does not match the exact requested candidate.' );
			if ( '' === $expected_artifact || strlen( $expected_artifact ) > 191 || ! hash_equals( $expected_artifact, $current_artifact ) ) return new WP_Error( 'mad4b_grant_reconcile_artifact_mismatch', 'Artifact identity does not match the exact requested candidate.' );

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

			$transport_inventory = self::live_transport_inventory();
			if ( is_wp_error( $transport_inventory ) ) return $transport_inventory;
			$expected_transport_count = isset( $input['expected_transport_tool_count'] ) ? absint( $input['expected_transport_tool_count'] ) : 0;
			$expected_transport_inventory = isset( $input['expected_transport_inventory_fingerprint'] ) ? strtolower( trim( (string) $input['expected_transport_inventory_fingerprint'] ) ) : '';
			if ( $expected_transport_count !== (int) $transport_inventory['count'] ) return new WP_Error( 'mad4b_grant_reconcile_transport_count_mismatch', 'Bounded ChatGPT transport inventory count changed before reconciliation.' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_transport_inventory ) || ! hash_equals( (string) $transport_inventory['fingerprint'], $expected_transport_inventory ) ) return new WP_Error( 'mad4b_grant_reconcile_transport_fingerprint_mismatch', 'Bounded ChatGPT transport inventory fingerprint changed before reconciliation.' );

			$counts = MAD4B_SCP_Agent_Registry::counts();
			if ( ! empty( $counts['wildcard_grants'] ) ) return new WP_Error( 'mad4b_grant_reconcile_wildcard_grant_detected', 'Wildcard grants must be absent before exact reconciliation.' );

			$desired = array();
			foreach ( $inventory['rows'] as $row ) $desired[ $row['ability'] . "\0" . $row['provider'] ] = $row;
			$allowed_providers = self::allowed_ability_providers();
			$retirement_providers = self::retirable_stale_ability_providers();
			$existing_rows = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
			$seen_allow = array();
			$stale_rows = array();
			foreach ( $existing_rows as $grant ) {
				if ( 'allow' !== (string) $grant['effect'] ) continue;
				$ability = (string) $grant['ability_name'];
				$provider = sanitize_key( (string) $grant['provider'] );
				$key = $ability . "\0" . $provider;
				if ( $environment !== (string) $grant['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_non_staging_allow_present', 'Exact reconciliation refuses all-environment or non-current-environment grants.', array( 'ability' => $ability ) );
				if ( isset( $seen_allow[ $key ] ) ) return new WP_Error( 'mad4b_grant_reconcile_duplicate_allow_present', 'Duplicate exact mad4b-write allow grant detected.', array( 'ability' => $ability ) );
				$seen_allow[ $key ] = true;
				if ( ! isset( $desired[ $key ] ) ) {
					if ( ! isset( $retirement_providers[ $ability ] ) || sanitize_key( (string) $retirement_providers[ $ability ] ) !== $provider ) {
						return new WP_Error( 'mad4b_grant_reconcile_stale_allow_unreviewed', 'Unexpected stale authority exists outside the reviewed reconciliation universe.', array( 'ability' => $ability, 'provider' => $provider ) );
					}
					$stale_rows[] = $grant;
				}
			}
			usort( $stale_rows, static function ( $a, $b ) { return (int) $a['id'] <=> (int) $b['id']; } );
			$stale_ids = array_values( array_map( static function ( $row ) { return (int) $row['id']; }, $stale_rows ) );
			$expected_stale_ids = self::normalized_expected_stale_grant_ids( $input );
			if ( $stale_ids !== $expected_stale_ids ) return new WP_Error( 'mad4b_grant_reconcile_stale_set_mismatch', 'Live stale-grant set changed or does not match the exact reviewed plan.', array( 'live_stale_grant_ids' => $stale_ids, 'expected_stale_grant_ids' => $expected_stale_ids ) );

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

			$allowed = array_keys( $allowed_providers );
			sort( $allowed, SORT_STRING );
			foreach ( $missing as $ability ) {
				if ( ! in_array( $ability, $allowed, true ) ) return new WP_Error( 'mad4b_grant_reconcile_missing_outside_allowlist', 'A missing grant exists outside the reviewed provider reconciliation allowlist.', array( 'ability' => $ability ) );
				$expected_provider = isset( $allowed_providers[ $ability ] ) ? sanitize_key( (string) $allowed_providers[ $ability ] ) : '';
				if ( ! isset( $providers[ $ability ] ) || $expected_provider !== sanitize_key( (string) $providers[ $ability ] ) ) return new WP_Error( 'mad4b_grant_reconcile_provider_mismatch', 'Reviewed grant does not resolve to its exact allowlisted provider.', array( 'ability' => $ability, 'expected_provider' => $expected_provider, 'provider' => isset( $providers[ $ability ] ) ? $providers[ $ability ] : '' ) );
			}
			$expected_missing = self::normalized_expected_missing( $input );
			if ( $missing !== $expected_missing ) return new WP_Error( 'mad4b_grant_reconcile_missing_set_mismatch', 'Live missing-grant set changed or does not match the explicit authorization input.', array( 'live_missing' => $missing, 'expected_missing' => $expected_missing ) );

			$transport_missing = array();
			$transport_providers = array();
			foreach ( $transport_inventory['rows'] as $row ) {
				$matching_allows = array();
				foreach ( MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], $row['server_id'] ) as $grant_row ) {
					if ( (string) $grant_row['ability_name'] !== (string) $row['ability']
						|| sanitize_key( (string) $grant_row['provider'] ) !== sanitize_key( (string) $row['provider'] ) ) continue;
					if ( 'allow' === (string) $grant_row['effect'] ) $matching_allows[] = $grant_row;
				}
				if ( count( $matching_allows ) > 1 ) return new WP_Error( 'mad4b_grant_reconcile_transport_duplicate_allow_present', 'Duplicate exact ChatGPT transport allow grant detected.', array( 'ability' => $row['ability'] ) );
				if ( 1 === count( $matching_allows ) && $environment !== (string) $matching_allows[0]['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_transport_non_staging_allow_present', 'Enrollment transport reconciliation refuses all-environment or non-current-environment grants.', array( 'ability' => $row['ability'] ) );
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], $row['server_id'], $row['ability'], $row['provider'] );
				if ( is_wp_error( $grant ) ) {
					if ( 'mad4b_nhi_grant_missing' !== $grant->get_error_code() ) return new WP_Error( 'mad4b_grant_reconcile_transport_existing_grant_blocked', 'Enrollment transport grant state is not reconcilable by this bounded operation.', array( 'ability' => $row['ability'], 'code' => $grant->get_error_code() ) );
					$transport_missing[] = $row['ability'];
					$transport_providers[ $row['ability'] ] = $row['provider'];
				}
			}
			sort( $transport_missing, SORT_STRING );
			$allowed_transport_providers = self::allowed_transport_ability_providers();
			foreach ( $transport_missing as $ability ) {
				if ( ! isset( $allowed_transport_providers[ $ability ] ) ) return new WP_Error( 'mad4b_grant_reconcile_transport_outside_allowlist', 'A missing ChatGPT transport grant exists outside the reviewed transport allowlist.', array( 'ability' => $ability ) );
				if ( sanitize_key( (string) $allowed_transport_providers[ $ability ] ) !== sanitize_key( (string) $transport_providers[ $ability ] ) ) return new WP_Error( 'mad4b_grant_reconcile_transport_provider_mismatch', 'Reviewed transport grant does not resolve to its exact allowlisted provider.', array( 'ability' => $ability ) );
			}
			$expected_transport_missing = self::normalized_expected_missing_transport( $input );
			if ( $transport_missing !== $expected_transport_missing ) return new WP_Error( 'mad4b_grant_reconcile_transport_missing_set_mismatch', 'Live missing transport-grant set changed or does not match the explicit authorization input.', array( 'live_missing' => $transport_missing, 'expected_missing' => $expected_transport_missing ) );

			$intent = MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-authorized', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'environment' => $environment,
				'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
				'site_profile_revision' => $current_revision,
				'site_profile_digest' => $current_digest,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'package_manifest_digest' => $current_manifest,
				'artifact_identity' => $current_artifact,
				'write_tool_count' => (int) $inventory['count'],
				'write_inventory_fingerprint' => (string) $inventory['fingerprint'],
				'exact_missing_abilities' => $missing,
				'exact_stale_grant_ids' => $stale_ids,
				'transport_tool_count' => (int) $transport_inventory['count'],
				'transport_inventory_fingerprint' => (string) $transport_inventory['fingerprint'],
				'exact_missing_transport_abilities' => $transport_missing,
				'confirmation' => $required_confirmation,
				'plan_sha256' => $current_plan_sha,
				'production_mutation' => 'production' === $environment,
				'breakglass_included' => false,
			), 'ok' );
			if ( is_wp_error( $intent ) ) return new WP_Error( 'mad4b_grant_reconcile_intent_audit_failed', 'Authorization evidence could not be committed before grant mutation.' );
			$authority_checkpoint = MAD4B_SCP_Staging_Write_Authority::persistence_checkpoint();
			$revoked_stale_ids = array();
			if ( ! empty( $stale_rows ) ) {
				$blocked = MAD4B_SCP_Staging_Write_Authority::fail_closed_persisted_authority( 'stale_grants_retiring' );
				if ( is_wp_error( $blocked ) ) return new WP_Error( 'mad4b_grant_reconcile_stale_fail_closed_failed', 'Unable to fail-close persisted authority before stale grant retirement.' );
				foreach ( $stale_rows as $stale ) {
					$grant_id = (int) $stale['id'];
					$revoked = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], $grant_id, 'mad4b-write' );
					if ( is_wp_error( $revoked ) ) return new WP_Error( 'mad4b_grant_reconcile_stale_revoke_failed', 'Reviewed stale authority retirement stopped fail-closed; retry requires a fresh exact plan.', array( 'grant_id' => $grant_id, 'revoked_stale_grant_ids' => $revoked_stale_ids, 'code' => $revoked->get_error_code() ) );
					$revoked_stale_ids[] = $grant_id;
					$stale_audit = MAD4B_SCP_Audit::record( 'mad4b/exact-staging-write-stale-grant-retired', array(
						'contract' => self::CONTRACT,
						'agent_public_id' => (string) $agent['public_id'],
						'server_id' => 'mad4b-write',
						'grant_id' => $grant_id,
						'ability' => (string) $stale['ability_name'],
						'provider' => sanitize_key( (string) $stale['provider'] ),
						'environment' => $environment,
						'authority_change' => 'narrowing',
						'production_mutation' => 'production' === $environment,
					), 'ok' );
					if ( is_wp_error( $stale_audit ) ) return new WP_Error( 'mad4b_grant_reconcile_stale_audit_failed', 'Stale grant was retired but its audit write failed; authority remains fail-closed.', array( 'grant_id' => $grant_id, 'revoked_stale_grant_ids' => $revoked_stale_ids ) );
				}
			}

			$created_ids = array();
			$created_abilities = array();
			foreach ( $missing as $ability ) {
				$provider = $providers[ $ability ];
				$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', $ability, $provider, array(), 'allow', $environment );
				if ( is_wp_error( $created ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, array(), empty( $revoked_stale_ids ) );
					return new WP_Error( 'mad4b_grant_reconcile_create_failed', 'Exact grant creation failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'code' => $created->get_error_code(), 'rollback_errors' => $rollback ) );
				}
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $provider );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || $environment !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, array(), empty( $revoked_stale_ids ) );
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
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, array(), empty( $revoked_stale_ids ) );
					return new WP_Error( 'mad4b_grant_reconcile_grant_audit_failed', 'Per-grant audit failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			foreach ( $missing as $ability ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $providers[ $ability ] );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || $environment !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, array(), empty( $revoked_stale_ids ) );
					return new WP_Error( 'mad4b_grant_reconcile_final_grant_check_failed', 'Exact grant set did not converge after mutation; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			$authority = MAD4B_SCP_Staging_Write_Authority::finalize_exact_existing_authority();
			if ( ! is_array( $authority ) || empty( $authority['ready'] ) || 'ready' !== ( isset( $authority['state'] ) ? (string) $authority['state'] : '' ) || ! empty( $authority['grant_blockers'] ) || (int) ( isset( $authority['write_tool_count'] ) ? $authority['write_tool_count'] : 0 ) !== (int) $inventory['count'] || ! isset( $authority['write_inventory_fingerprint'] ) || ! hash_equals( (string) $inventory['fingerprint'], (string) $authority['write_inventory_fingerprint'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, array(), empty( $revoked_stale_ids ) );
				return new WP_Error( 'mad4b_grant_reconcile_authority_not_ready', 'Exact existing authority did not converge after grant reconciliation; newly-created grants and persisted authority state were rolled back.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}
			if ( ! empty( $authority['exact_grants_created'] ) || ! empty( $authority['stale_allow_grants_revoked'] ) || ! empty( $authority['stale_subjects_disabled'] ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, array(), empty( $revoked_stale_ids ) );
				return new WP_Error( 'mad4b_grant_reconcile_unexpected_authority_mutation', 'Exact authority finalization reported mutation outside the explicitly created exact grant set.', array( 'authority' => $authority, 'rollback_errors' => $rollback ) );
			}

			if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding' ) || ! method_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding', 'operation_context' ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, array(), empty( $revoked_stale_ids ) );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_unavailable', 'Audited candidate-binding context factory is unavailable; newly-created grants and persisted authority state were rolled back.', array( 'rollback_errors' => $rollback ) );
			}
			$binding_plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
			if ( ! is_array( $binding_plan ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, array(), empty( $revoked_stale_ids ) );
				return new WP_Error( 'mad4b_grant_reconcile_binding_plan_unavailable', 'Exact binding plan is unavailable after grant convergence; newly-created grants and persisted authority state were rolled back.', array( 'rollback_errors' => $rollback ) );
			}
			$binding_snapshot = isset( $binding_plan['candidate_binding'] ) && is_array( $binding_plan['candidate_binding'] ) ? $binding_plan['candidate_binding'] : array();
			$binding_identity_matches = isset(
				$binding_snapshot['current_source_commit_sha'],
				$binding_snapshot['current_build_fingerprint'],
				$binding_snapshot['current_package_manifest_digest'],
				$binding_snapshot['current_artifact_identity']
			)
				&& hash_equals( $expected_sha, strtolower( (string) $binding_snapshot['current_source_commit_sha'] ) )
				&& hash_equals( $expected_fingerprint, strtolower( (string) $binding_snapshot['current_build_fingerprint'] ) )
				&& hash_equals( $expected_manifest, strtolower( (string) $binding_snapshot['current_package_manifest_digest'] ) )
				&& hash_equals( $expected_artifact, (string) $binding_snapshot['current_artifact_identity'] );
			if ( ! $binding_identity_matches ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, array(), empty( $revoked_stale_ids ) );
				return new WP_Error( 'mad4b_grant_reconcile_binding_identity_drift', 'Exact four-part package identity changed before the candidate-binding commit point; newly-created grants and persisted authority state were rolled back.', array( 'rollback_errors' => $rollback ) );
			}
			$binding_context = MAD4B_SCP_Staging_Write_Candidate_Binding::operation_context(
				$binding_plan,
				$binding_snapshot,
				$current_revision,
				$current_digest,
				$required_confirmation,
				'grant_reconciliation',
				self::CONTRACT
			);
			if ( is_wp_error( $binding_context ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, array(), empty( $revoked_stale_ids ) );
				return new WP_Error( 'mad4b_grant_reconcile_binding_context_failed', 'Audited candidate-binding context could not be created after grant reconciliation; newly-created grants and persisted authority state were rolled back.', array( 'code' => $binding_context->get_error_code(), 'rollback_errors' => $rollback ) );
			}

			// Create the single bounded ChatGPT transport grant only after normal
			// write authority and candidate-binding context have passed every
			// precondition. It remains rollbackable until the candidate bind commits.
			$created_transport_ids = array();
			$created_transport_abilities = array();
			foreach ( $transport_missing as $ability ) {
				$provider = $transport_providers[ $ability ];
				$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-chatgpt', $ability, $provider, array(), 'allow', $environment );
				if ( is_wp_error( $created ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, $created_transport_ids, empty( $revoked_stale_ids ) );
					return new WP_Error( 'mad4b_grant_reconcile_transport_create_failed', 'Exact enrollment transport grant creation failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'code' => $created->get_error_code(), 'rollback_errors' => $rollback ) );
				}
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-chatgpt', $ability, $provider );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || $environment !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, $created_transport_ids, empty( $revoked_stale_ids ) );
					return new WP_Error( 'mad4b_grant_reconcile_transport_postcondition_failed', 'New enrollment transport grant failed immediate postcondition verification.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
				$created_transport_ids[] = (int) $grant['id'];
				$created_transport_abilities[] = $ability;
				$grant_audit = MAD4B_SCP_Audit::record( 'mad4b/exact-staging-transport-grant-reconciled', array(
					'contract' => self::CONTRACT,
					'agent_public_id' => (string) $agent['public_id'],
					'server_id' => 'mad4b-chatgpt',
					'ability' => $ability,
					'provider' => $provider,
					'environment' => 'staging',
					'grant_id' => (int) $grant['id'],
					'source_commit_sha' => $current_sha,
					'transport_inventory_fingerprint' => (string) $transport_inventory['fingerprint'],
					'production_mutation' => 'production' === $environment,
					'breakglass_included' => false,
				), 'ok' );
				if ( is_wp_error( $grant_audit ) ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, $created_transport_ids, empty( $revoked_stale_ids ) );
					return new WP_Error( 'mad4b_grant_reconcile_transport_audit_failed', 'Enrollment transport grant audit failed; newly-created grants were rolled back.', array( 'ability' => $ability, 'rollback_errors' => $rollback ) );
				}
			}

			foreach ( $transport_inventory['rows'] as $row ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], $row['server_id'], $row['ability'], $row['provider'] );
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] || $environment !== (string) $grant['environment'] ) {
					$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, $created_transport_ids, empty( $revoked_stale_ids ) );
					return new WP_Error( 'mad4b_grant_reconcile_transport_final_check_failed', 'Enrollment transport grant set did not converge before candidate binding.', array( 'ability' => $row['ability'], 'rollback_errors' => $rollback ) );
				}
			}

			$prepared = MAD4B_SCP_Audit::record( 'mad4b/staging-write-authority-prepared', array(
				'contract' => self::CONTRACT,
				'operation_id' => isset( $binding_context['operation_id'] ) ? (string) $binding_context['operation_id'] : '',
				'plan_sha256' => $current_plan_sha,
				'agent_public_id' => (string) $agent['public_id'],
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'package_manifest_digest' => $current_manifest,
				'artifact_identity' => $current_artifact,
				'write_tool_count' => (int) $binding_plan['write_tool_count'],
				'exact_grants_existing' => (int) $binding_plan['exact_grants_existing'],
				'write_inventory_fingerprint' => (string) $binding_plan['write_inventory_fingerprint'],
				'grant_rows_fingerprint' => (string) $binding_plan['grant_rows_fingerprint'],
				'transport_tool_count' => (int) $transport_inventory['count'],
				'transport_inventory_fingerprint' => (string) $transport_inventory['fingerprint'],
				'created_transport_abilities' => $created_transport_abilities,
				'candidate_binding_is_commit_point' => true,
				'production_mutation' => 'production' === $environment,
				'developer_authority_mutation' => false,
				'breakglass_included' => false,
			), 'ok' );
			if ( is_wp_error( $prepared ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, $created_transport_ids, empty( $revoked_stale_ids ) );
				return new WP_Error( 'mad4b_grant_reconcile_prepared_audit_failed', 'Prepared authority evidence could not be committed before the candidate-binding commit point; newly-created grants and persisted authority state were rolled back.', array( 'rollback_errors' => $rollback ) );
			}
			// Final commit point: candidate binding commits the exact four-part package
			// identity and its authorization/completion audit atomically.
			// No fallible governance mutation is permitted after successful return.
			$bound = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( $current_sha, $current_fingerprint, $binding_context );
			if ( is_wp_error( $bound ) ) {
				$rollback = self::rollback_transaction( $agent, $created_ids, $authority_checkpoint, $created_transport_ids, empty( $revoked_stale_ids ) );
				return new WP_Error( 'mad4b_grant_reconcile_candidate_binding_failed', 'Exact package candidate could not be bound after grant reconciliation; newly-created grants and persisted authority state were rolled back.', array( 'code' => $bound->get_error_code(), 'rollback_errors' => $rollback ) );
			}
			$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
			$authority = $bound;
			if ( empty( $authority['effective'] ) ) {
				return new WP_Error( 'mad4b_grant_reconcile_post_commit_invariant_failed', 'Candidate-binding transaction committed without the effective=true invariant reported by the binding primitive; no post-commit rollback was attempted.' );
			}

			return array(
				'contract' => self::CONTRACT,
				'state' => ( empty( $created_abilities ) && empty( $created_transport_abilities ) && empty( $revoked_stale_ids ) ) ? 'candidate_rebound' : 'reconciled',
				'agent_public_id' => (string) $agent['public_id'],
				'created_count' => count( $created_abilities ),
				'revoked_stale_count' => count( $revoked_stale_ids ),
				'revoked_stale_grant_ids' => $revoked_stale_ids,
				'created_abilities' => $created_abilities,
				'created_transport_count' => count( $created_transport_abilities ),
				'created_transport_abilities' => $created_transport_abilities,
				'transport_tool_count' => (int) $transport_inventory['count'],
				'transport_inventory_fingerprint' => (string) $transport_inventory['fingerprint'],
				'transport_grant_ready' => true,
				'write_tool_count' => (int) $authority['write_tool_count'],
				'write_inventory_fingerprint' => (string) $authority['write_inventory_fingerprint'],
				'exact_grants_existing' => isset( $authority['exact_grants_existing'] ) ? (int) $authority['exact_grants_existing'] : 0,
				'exact_grants_created_by_authority_reconcile' => count( $created_abilities ),
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_fingerprint,
				'package_manifest_digest' => $current_manifest,
				'artifact_identity' => $current_artifact,
				'plan_sha256' => $current_plan_sha,
				'candidate_binding_match' => ! empty( $binding['match'] ),
				'candidate_rebound_without_grant_changes' => empty( $created_abilities ) && empty( $created_transport_abilities ) && empty( $revoked_stale_ids ),
				'runtime_reconciled' => true,
				'authority_ready' => true,
				'effective' => true,
				'commit_point' => 'candidate_binding_transaction',
				'candidate_binding_audit_recorded' => true,
				'candidate_binding_audit_source' => 'candidate_binding_transaction',
				'post_commit_governance_mutation' => false,
				'developer_authority_mutation' => false,
				'breakglass_included' => false,
				'production_mutation' => 'production' === $environment,
			);
		} finally {
			self::$running = false;
		}
	}
}
