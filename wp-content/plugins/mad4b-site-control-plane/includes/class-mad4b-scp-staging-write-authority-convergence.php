<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact, audited Staging-only convergence for governed Write authority.
 *
 * This is deliberately independent from Developer and Developer Breakglass.
 * It may enable the Site Profile Write feature, reconcile the canonical
 * governed-write agent/subjects/exact grants, and bind the exact current
 * package candidate. It never provisions Developer, Developer Breakglass,
 * generic raw-SQL Breakglass, or Production mutation authority.
 */
final class MAD4B_SCP_Staging_Write_Authority_Convergence {
	const CONTRACT = 'mad4b.staging-write-authority-convergence.v1';
	const STATUS_ABILITY = 'mad4b/staging-write-authority-convergence-status';
	const PLAN_ABILITY = 'mad4b/staging-write-authority-convergence-plan';
	const HANDSHAKE_ABILITY = 'mad4b/staging-write-authority-convergence-handshake';
	const APPLY_ABILITY = 'mad4b/staging-write-authority-convergence-apply';
	const CONFIRMATION = 'ENABLE GOVERNED STAGING WRITE AUTHORITY';

	private static $booted = false;
	private static $running = false;

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ), 17 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 17 );
	}

	public static function enrollment_tools() {
		return array( self::STATUS_ABILITY, self::PLAN_ABILITY, self::HANDSHAKE_ABILITY, self::APPLY_ABILITY );
	}

	public static function chatgpt_read_tools() {
		return array( self::HANDSHAKE_ABILITY );
	}

	public static function chatgpt_catalog_read_tools() {
		return array( self::STATUS_ABILITY, self::PLAN_ABILITY, self::HANDSHAKE_ABILITY );
	}

	public static function chatgpt_step_up_tools() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return array();
		if ( 'staging' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) ) return array();
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return array();
		if ( self::generic_raw_sql_breakglass_gate_enabled() ) return array();
		return array( self::APPLY_ABILITY );
	}

	public static function register_category() {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category( 'mad4b-staging-write-authority', array(
				'label' => 'MAD4B Staging Write Authority',
				'description' => 'Exact plan-bound governed Write convergence without Developer or Breakglass authority.',
			) );
		}
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		$augment = array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' );
		$priority = function_exists( 'has_filter' ) ? has_filter( 'wp_register_ability_args', $augment ) : false;
		if ( false !== $priority ) remove_filter( 'wp_register_ability_args', $augment, (int) $priority );

		try {
			if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::STATUS_ABILITY ) ) {
				wp_register_ability( self::STATUS_ABILITY, array(
					'label' => 'Staging Write Authority Convergence Status',
					'description' => 'Read current governed Write readiness and exact candidate binding without enabling Developer or Breakglass.',
					'category' => 'mad4b-staging-write-authority',
					'execute_callback' => array( __CLASS__, 'status' ),
					'permission_callback' => array( __CLASS__, 'can_access' ),
					'input_schema' => self::schema( array() ),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::meta( true, 'read' ),
				) );
			}
			if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::PLAN_ABILITY ) ) {
				wp_register_ability( self::PLAN_ABILITY, array(
					'label' => 'Plan Governed Staging Write Authority',
					'description' => 'Build an exact read-only convergence plan for governed Write only. Developer and Breakglass are excluded.',
					'category' => 'mad4b-staging-write-authority',
					'execute_callback' => array( __CLASS__, 'plan' ),
					'permission_callback' => array( __CLASS__, 'can_access' ),
					'input_schema' => self::schema( array() ),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::meta( true, 'read' ),
				) );
			}
			if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::HANDSHAKE_ABILITY ) ) {
				wp_register_ability( self::HANDSHAKE_ABILITY, array(
					'label' => 'Governed Staging Write Authority Handshake',
					'description' => 'Return one compact generation-fenced exact plan envelope for governed Write-only convergence.',
					'category' => 'mad4b-staging-write-authority',
					'execute_callback' => array( __CLASS__, 'handshake' ),
					'permission_callback' => array( __CLASS__, 'can_access' ),
					'input_schema' => self::schema( array() ),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::meta( true, 'read' ),
				) );
			}
			if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::APPLY_ABILITY ) ) {
				wp_register_ability( self::APPLY_ABILITY, array(
					'label' => 'Enable Governed Staging Write Authority',
					'description' => 'Converge exact governed Write and bind the exact current package candidate on Staging without enabling Developer or any Breakglass authority.',
					'category' => 'mad4b-staging-write-authority',
					'execute_callback' => array( __CLASS__, 'apply' ),
					'permission_callback' => array( __CLASS__, 'can_apply' ),
					'input_schema' => self::schema(
						array(
							'expected_plan_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
							'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
							'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
							'expected_package_manifest_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
							'expected_artifact_identity' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 191 ),
							'expected_site_uuid' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
							'expected_profile_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
							'expected_profile_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
							'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) ),
						),
						array(
							'expected_plan_sha256',
							'expected_source_commit_sha',
							'expected_build_fingerprint',
							'expected_package_manifest_digest',
							'expected_artifact_identity',
							'expected_site_uuid',
							'expected_profile_revision',
							'expected_profile_digest',
							'confirmation',
						)
					),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::meta( false, 'enrollment' ),
				) );
			}
		} finally {
			if ( false !== $priority ) add_filter( 'wp_register_ability_args', $augment, (int) $priority, 2 );
		}
	}

	private static function schema( array $properties, array $required = array() ) {
		$schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $schema['required'] = $required;
		return $schema;
	}

	private static function meta( $readonly, $surface = 'enrollment' ) {
		$step_up = ! $readonly && 'enrollment' === sanitize_key( (string) $surface );
		return array(
			'public' => false,
			'show_in_rest' => false,
			'mcp' => array(
				'public' => false,
				'type' => 'tool',
				'surface' => sanitize_key( (string) $surface ),
				'mad4b_staging_write_authority_convergence' => self::CONTRACT,
				'production_allowed' => false,
				'enables_developer_authority' => false,
				'developer_breakglass_included' => false,
				'generic_raw_sql_breakglass_included' => false,
				'chatgpt_direct_step_up' => $step_up,
				'exact_chatgpt_client_required' => $step_up,
			),
			'annotations' => array(
				'readonly' => (bool) $readonly,
				'destructive' => ! $readonly,
				'idempotent' => $readonly,
			),
		);
	}

	public static function can_access( $input = null ) {
		unset( $input );
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_staging_write_authority_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_staging_write_authority_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_staging_write_authority_profile_required', 'An enrolled Site Profile is required.' );
		if ( 'staging' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) ) return new WP_Error( 'mad4b_staging_write_authority_staging_only', 'Governed Write convergence is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_staging_write_authority_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Staging Site Profile.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_staging_write_authority_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : new WP_Error( 'mad4b_staging_write_authority_identity_unavailable', 'Governance identity is unavailable.' );
		if ( is_wp_error( $identity ) ) return $identity;
		if ( empty( $identity['authenticated'] ) || 'oauth' !== ( isset( $identity['subject_type'] ) ? (string) $identity['subject_type'] : '' ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) {
			return new WP_Error( 'mad4b_staging_write_authority_normal_oauth_required', 'Governed Write convergence must originate from the enrolled normal OAuth identity.' );
		}
		if ( self::generic_raw_sql_breakglass_gate_enabled() || ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_breakglass() ) ) {
			return new WP_Error( 'mad4b_staging_write_authority_breakglass_denied', 'All Breakglass authority must remain disabled during governed Write convergence.' );
		}
		return true;
	}

	public static function can_apply( $input = null ) {
		$access = self::can_access( $input );
		if ( is_wp_error( $access ) || ! $access ) return $access;
		if ( ! defined( 'MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE' )
			|| ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ) {
			return new WP_Error( 'mad4b_staging_write_authority_step_up_scope_required', 'The OAuth bearer does not grant the dedicated authority step-up scope.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
			|| ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ) {
			return new WP_Error( 'mad4b_staging_write_authority_chatgpt_client_required', 'Governed Write convergence requires OAuth attribution to the exact ChatGPT CIMD client.' );
		}
		return true;
	}

	private static function generic_raw_sql_breakglass_gate_enabled() {
		return class_exists( 'MAD4B_SCP_Governed_Runtime_Gates' ) && MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled();
	}

	public static function status() {
		$write = self::write_plan();
		$binding = is_array( $write ) && isset( $write['candidate_binding'] ) && is_array( $write['candidate_binding'] ) ? $write['candidate_binding'] : array();
		$checkpoint_ready = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective();
		$grant_snapshot_ready = is_array( $write ) && ! empty( $write['current_ready'] );
		$effective = $checkpoint_ready && $grant_snapshot_ready && is_array( $write ) && ! empty( $write['effective_ready'] );
		return array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'authorizing' => false,
			'mutation_performed' => false,
			'environment' => 'staging',
			'production_allowed' => false,
			'developer_authority_requested' => false,
			'developer_breakglass_requested' => false,
			'generic_raw_sql_breakglass_requested' => false,
			'write_ready' => $effective,
			'self_confirmation_state' => $effective ? 'current_write_state_verified' : 'current_write_state_blocked',
			'self_confirmation_scope' => 'current_state_not_prior_commit_receipt',
			'write_checkpoint_ready' => $checkpoint_ready,
			'write_grants_ready' => $grant_snapshot_ready,
			'write_current_readiness_blockers' => is_array( $write ) && isset( $write['current_readiness_blockers'] ) && is_array( $write['current_readiness_blockers'] ) ? $write['current_readiness_blockers'] : array( 'write_reconciliation_plan_unavailable' ),
			'candidate_binding' => $binding,
			'write_plan' => is_array( $write ) ? $write : array(),
		);
	}

	public static function handshake() {
		$access = self::can_access();
		if ( is_wp_error( $access ) || ! $access ) return $access;
		if ( ! class_exists( 'MAD4B_SCP_Connector_Resilience' ) || ! method_exists( 'MAD4B_SCP_Connector_Resilience', 'generation_fenced_compact_read' ) ) {
			return new WP_Error( 'mad4b_staging_write_authority_handshake_resilience_unavailable', 'Shared connector resilience service is unavailable.' );
		}
		return MAD4B_SCP_Connector_Resilience::generation_fenced_compact_read(
			'staging_write_authority_convergence_handshake',
			array( __CLASS__, 'plan' ),
			static function ( $plan, $before, $after ) {
				unset( $before );
				if ( ! is_array( $plan ) ) return new WP_Error( 'mad4b_staging_write_authority_handshake_plan_invalid', 'Governed Write convergence plan is unavailable.' );
				$write = isset( $plan['write_reconciliation'] ) && is_array( $plan['write_reconciliation'] ) ? $plan['write_reconciliation'] : array();
				$binding = isset( $write['candidate_binding'] ) && is_array( $write['candidate_binding'] ) ? $write['candidate_binding'] : array();
				$checkpoint_ready = ! empty( $write['effective_ready'] );
				$grants_ready = ! empty( $write['current_ready'] );
				$already_current = $checkpoint_ready && $grants_ready
					&& ( empty( $binding['required'] ) || ! empty( $binding['match'] ) );
				return array(
					'contract' => 'mad4b.staging-write-authority-convergence-handshake.v1',
					'write_authority_contract' => self::CONTRACT,
					'read_only' => true,
					'authorizing' => false,
					'mutation_performed' => false,
					'production_allowed' => false,
					'developer_authority_requested' => false,
					'developer_breakglass_requested' => false,
					'generic_raw_sql_breakglass_included' => false,
					'observed_at' => isset( $after['observed_at'] ) ? (string) $after['observed_at'] : gmdate( 'c' ),
					'ready_to_apply' => ! empty( $plan['ready_to_apply'] ) && ! $already_current,
					'already_current' => $already_current,
					'self_confirmation_scope' => 'current_state_not_prior_commit_receipt',
					'hard_blockers' => self::compact_string_list( isset( $plan['hard_blockers'] ) ? $plan['hard_blockers'] : array(), 16 ),
					'write_ready' => $checkpoint_ready && $grants_ready,
					'write_checkpoint_ready' => $checkpoint_ready,
					'write_grants_ready' => $grants_ready,
					'write_reconciliation_required' => ! ( $checkpoint_ready && $grants_ready ),
					'write_current_readiness_blockers' => isset( $write['current_readiness_blockers'] ) && is_array( $write['current_readiness_blockers'] ) ? self::compact_string_list( $write['current_readiness_blockers'], 16 ) : array( 'write_reconciliation_plan_unavailable' ),
					'candidate_binding' => array(
						'required' => ! empty( $binding['required'] ),
						'match' => ! empty( $binding['match'] ),
						'current_source_commit_sha' => isset( $binding['current_source_commit_sha'] ) ? (string) $binding['current_source_commit_sha'] : '',
						'stored_source_commit_sha' => isset( $binding['stored_source_commit_sha'] ) ? (string) $binding['stored_source_commit_sha'] : '',
					),
					'exact_apply' => array(
						'expected_plan_sha256' => isset( $plan['plan_sha256'] ) ? (string) $plan['plan_sha256'] : '',
						'expected_source_commit_sha' => isset( $plan['source_commit_sha'] ) ? (string) $plan['source_commit_sha'] : '',
						'expected_build_fingerprint' => isset( $plan['build_fingerprint'] ) ? (string) $plan['build_fingerprint'] : '',
						'expected_package_manifest_digest' => isset( $plan['package_manifest_digest'] ) ? (string) $plan['package_manifest_digest'] : '',
						'expected_artifact_identity' => isset( $plan['artifact_identity'] ) ? (string) $plan['artifact_identity'] : '',
						'expected_site_uuid' => isset( $plan['site_uuid'] ) ? (string) $plan['site_uuid'] : '',
						'expected_profile_revision' => isset( $plan['site_profile_revision'] ) ? (int) $plan['site_profile_revision'] : 0,
						'expected_profile_digest' => isset( $plan['site_profile_digest'] ) ? (string) $plan['site_profile_digest'] : '',
						'confirmation' => self::CONFIRMATION,
					),
					'client_action' => $already_current ? 'readback_current_authority' : ( ! empty( $plan['ready_to_apply'] ) ? 'apply_exact_write_only_handshake' : 'repair_blockers_then_request_fresh_handshake' ),
					'deep_reads_available_via_governed_dispatch' => true,
				);
			},
			8192
		);
	}

	public static function plan() {
		$access = self::can_access();
		if ( is_wp_error( $access ) || ! $access ) return $access;
		$provenance = self::provenance();
		if ( is_wp_error( $provenance ) ) return $provenance;
		$write = self::write_plan();
		if ( is_wp_error( $write ) ) return $write;

		$hard_blockers = array();
		if ( isset( $write['global_registry_wildcard_grants'] ) && (int) $write['global_registry_wildcard_grants'] > 0 ) $hard_blockers[] = 'global_registry_wildcard_grants';
		if ( isset( $write['current_agent_wildcard_grants'] ) && (int) $write['current_agent_wildcard_grants'] > 0 ) $hard_blockers[] = 'current_agent_wildcard_grants';
		if ( isset( $write['unreviewed_stale_allow_grants_count'] ) && (int) $write['unreviewed_stale_allow_grants_count'] > 0 ) $hard_blockers[] = 'unreviewed_stale_write_authority';
		if ( ! empty( $write['breakglass_included'] ) ) $hard_blockers[] = 'breakglass_leak';
		$subject_blockers = isset( $write['subject_preflight_blockers'] ) && is_array( $write['subject_preflight_blockers'] )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', $write['subject_preflight_blockers'] ) ) ) )
			: array( 'write_subject_preflight_unavailable' );
		if ( ! empty( $subject_blockers ) ) $hard_blockers[] = 'write_subject_preflight_blocked';
		if ( defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true !== constant( 'MAD4B_MCP_MUTATION_ENABLED' ) ) $hard_blockers[] = 'explicit_mutation_disabled';

		$nonreconcilable_write_drift = array();
		$missing_rows = isset( $write['exact_grants_missing'] ) && is_array( $write['exact_grants_missing'] ) ? $write['exact_grants_missing'] : array();
		foreach ( $missing_rows as $row ) {
			if ( ! is_array( $row ) ) continue;
			$reason = isset( $row['reason'] ) ? sanitize_key( (string) $row['reason'] ) : '';
			if ( ! in_array( $reason, array( 'explicit_deny', 'write_provider_unmounted' ), true ) ) continue;
			$nonreconcilable_write_drift[] = array(
				'ability' => isset( $row['ability'] ) ? sanitize_text_field( (string) $row['ability'] ) : '',
				'provider' => isset( $row['provider'] ) ? sanitize_key( (string) $row['provider'] ) : '',
				'reason' => $reason,
			);
			$hard_blockers[] = 'explicit_deny' === $reason ? 'write_explicit_deny' : 'write_provider_unmounted';
		}
		if ( ! MAD4B_SCP_Site_Profile::oauth_enabled() ) $hard_blockers[] = 'oauth_disabled';
		if ( '' === MAD4B_SCP_Site_Profile::chatgpt_app_id() ) $hard_blockers[] = 'chatgpt_app_mapping_missing';

		$plan = array(
			'contract' => self::CONTRACT,
			'operation' => 'converge_governed_staging_write_authority',
			'production_allowed' => false,
			'developer_authority_requested' => false,
			'developer_breakglass_requested' => false,
			'generic_raw_sql_breakglass_requested' => false,
			'environment' => 'staging',
			'site_uuid' => strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ),
			'site_profile_revision' => (int) MAD4B_SCP_Site_Profile::revision(),
			'site_profile_digest' => strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() ),
			'source_commit_sha' => $provenance['source_commit_sha'],
			'build_fingerprint' => $provenance['build_fingerprint'],
			'package_manifest_digest' => $provenance['package_manifest_digest'],
			'artifact_identity' => $provenance['artifact_identity'],
			'write_enabled' => (bool) MAD4B_SCP_Site_Profile::write_enabled(),
			'write_feature_enable_required' => ! MAD4B_SCP_Site_Profile::write_enabled(),
			'write_reconciliation' => $write,
			'write_subject_preflight_blockers' => $subject_blockers,
			'nonreconcilable_write_drift' => $nonreconcilable_write_drift,
			'hard_blockers' => array_values( array_unique( $hard_blockers ) ),
			'ready_to_apply' => empty( $hard_blockers ),
		);
		$plan['plan_sha256'] = self::plan_sha256( $plan );
		return $plan;
	}

	public static function apply( $input ) {
		if ( self::$running ) return new WP_Error( 'mad4b_staging_write_authority_reentry_denied', 'Governed Staging Write Authority convergence is already running in this request.' );
		$access = self::can_apply( $input );
		if ( is_wp_error( $access ) || ! $access ) return $access;
		if ( ! is_array( $input ) || self::CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) {
			return new WP_Error( 'mad4b_staging_write_authority_confirmation_required', 'Exact confirmation is required.' );
		}

		self::$running = true;
		try {
			$plan = self::plan();
			if ( is_wp_error( $plan ) ) return $plan;
			if ( empty( $plan['ready_to_apply'] ) ) return new WP_Error( 'mad4b_staging_write_authority_plan_blocked', 'Governed Write convergence plan contains hard blockers.', array( 'blockers' => $plan['hard_blockers'] ) );
			$match = self::match_expected_plan( $plan, $input );
			if ( is_wp_error( $match ) ) return $match;
			if ( ! class_exists( 'MAD4B_SCP_Audit' ) || empty( MAD4B_SCP_Audit::storage_status()['ready'] ) ) return new WP_Error( 'mad4b_staging_write_authority_audit_required', 'Ready append-only audit storage is required.' );
			if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding' ) ) return new WP_Error( 'mad4b_staging_write_authority_candidate_binding_unavailable', 'Candidate-binding authority is unavailable.' );

			$reviewed_binding_status = isset( $plan['write_reconciliation']['candidate_binding'] ) && is_array( $plan['write_reconciliation']['candidate_binding'] )
				? $plan['write_reconciliation']['candidate_binding']
				: array();
			$reviewed_previous_binding = MAD4B_SCP_Staging_Write_Candidate_Binding::audit_binding_snapshot( $reviewed_binding_status );

			$intent = MAD4B_SCP_Audit::record( 'mad4b/staging-write-authority-convergence-authorized', array(
				'contract' => self::CONTRACT,
				'plan_sha256' => $plan['plan_sha256'],
				'source_commit_sha' => $plan['source_commit_sha'],
				'build_fingerprint' => $plan['build_fingerprint'],
				'package_manifest_digest' => $plan['package_manifest_digest'],
				'artifact_identity' => $plan['artifact_identity'],
				'site_uuid' => $plan['site_uuid'],
				'site_profile_revision' => $plan['site_profile_revision'],
				'reviewed_previous_binding' => $reviewed_previous_binding,
				'confirmation' => self::CONFIRMATION,
				'requested_authorities' => array( 'write' ),
				'developer_authority_requested' => false,
				'developer_breakglass_requested' => false,
				'generic_raw_sql_breakglass_requested' => false,
				'production_mutation' => false,
			), 'ok' );
			if ( is_wp_error( $intent ) ) return new WP_Error( 'mad4b_staging_write_authority_intent_audit_failed', 'Governed Write authorization evidence could not be committed.' );

			$write_feature_was_enabled = MAD4B_SCP_Site_Profile::write_enabled();
			if ( ! $write_feature_was_enabled ) {
				$result = MAD4B_SCP_Site_Profile_Write_Enablement::enable_write( array(
					'expected_revision' => $plan['site_profile_revision'],
					'expected_profile_digest' => $plan['site_profile_digest'],
					'expected_source_commit_sha' => $plan['source_commit_sha'],
					'expected_build_fingerprint' => $plan['build_fingerprint'],
					'confirmation' => MAD4B_SCP_Site_Profile_Write_Enablement::CONFIRMATION,
				) );
				if ( is_wp_error( $result ) ) return self::fail_closed( 'write_enable_failed', $result );
			}

			$write_runtime = MAD4B_SCP_Staging_Write_Authority::bootstrap();
			if ( ! is_array( $write_runtime ) || empty( $write_runtime['eligible'] ) || empty( $write_runtime['mutation_gate_configured'] ) ) {
				return self::fail_closed( 'write_runtime_gate_not_ready', new WP_Error( 'mad4b_staging_write_authority_runtime_gate_not_ready', 'Governed Write runtime gate did not become current-ready after Site Profile evaluation.' ) );
			}

			if ( ! $write_feature_was_enabled ) {
				$plan = self::plan();
				if ( is_wp_error( $plan ) ) return self::fail_closed( 'post_write_enable_plan_failed', $plan );
				if ( empty( $plan['ready_to_apply'] ) ) return self::fail_closed( 'post_write_enable_plan_blocked', new WP_Error( 'mad4b_staging_write_authority_post_write_enable_plan_blocked', 'Governed Write plan gained a hard blocker after Site Profile Write enablement.' ) );
			}

			$write = MAD4B_SCP_Staging_Write_Authority::reconcile();
			if ( ! is_array( $write ) || empty( $write['ready'] ) ) {
				$error = is_wp_error( $write ) ? $write : new WP_Error( 'mad4b_staging_write_authority_reconcile_incomplete', 'Governed Write reconciliation did not converge.' );
				return self::fail_closed( 'write_reconcile_failed', $error );
			}

			$write_plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
			if ( ! is_array( $write_plan ) ) return self::fail_closed( 'write_plan_unavailable_after_reconcile', new WP_Error( 'mad4b_staging_write_authority_write_plan_unavailable', 'Write reconciliation plan is unavailable after convergence.' ) );
			$write_reconciled = ! empty( $write_plan['current_ready'] )
				&& isset( $write_plan['exact_grants_existing'], $write_plan['write_tool_count'] )
				&& (int) $write_plan['exact_grants_existing'] === (int) $write_plan['write_tool_count']
				&& empty( $write_plan['exact_grants_missing_count'] )
				&& empty( $write_plan['stale_allow_grants_count'] )
				&& empty( $write_plan['unreviewed_stale_allow_grants_count'] )
				&& empty( $write_plan['broad_environment_grants_count'] )
				&& empty( $write_plan['duplicate_exact_allow_grants_count'] )
				&& empty( $write_plan['current_agent_wildcard_grants'] )
				&& empty( $write_plan['global_registry_wildcard_grants'] )
				&& empty( $write_plan['breakglass_included'] );
			if ( ! $write_reconciled ) {
				return self::fail_closed( 'write_postcondition_failed', new WP_Error( 'mad4b_staging_write_authority_write_postcondition_failed', 'Governed Write grants did not converge before the final package binding.' ) );
			}

			$binding = isset( $write_plan['candidate_binding'] ) && is_array( $write_plan['candidate_binding'] ) ? $write_plan['candidate_binding'] : array();
			$binding_required = ! empty( $binding['required'] );
			$binding_match_before = ! $binding_required || ! empty( $binding['match'] );
			$pre_bind_persisted_binding = MAD4B_SCP_Staging_Write_Candidate_Binding::audit_binding_snapshot( $binding );

			$prepared = MAD4B_SCP_Audit::record( 'mad4b/staging-write-authority-convergence-prepared', array(
				'contract' => self::CONTRACT,
				'state' => 'ready_for_final_candidate_binding',
				'source_commit_sha' => $plan['source_commit_sha'],
				'site_uuid' => $plan['site_uuid'],
				'write_reconciled' => true,
				'developer_authority_mutation' => false,
				'developer_breakglass_mutation' => false,
				'candidate_binding_required' => $binding_required,
				'candidate_binding_match_before' => $binding_match_before,
				'reviewed_previous_binding' => $reviewed_previous_binding,
				'pre_bind_persisted_binding' => $pre_bind_persisted_binding,
				'generic_raw_sql_breakglass_enabled' => false,
				'production_mutation' => false,
			), 'ok' );
			if ( is_wp_error( $prepared ) ) return self::fail_closed( 'prepared_audit_failed', new WP_Error( 'mad4b_staging_write_authority_prepared_audit_failed', 'Prepared Write authority evidence could not be committed before final binding.' ) );

			$bind = null;
			if ( $binding_required && ! $binding_match_before ) {
				$bind = MAD4B_SCP_Staging_Write_Candidate_Binding::bind( array(
					'expected_revision' => (int) MAD4B_SCP_Site_Profile::revision(),
					'expected_profile_digest' => strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() ),
					'expected_source_commit_sha' => (string) $binding['current_source_commit_sha'],
					'expected_build_fingerprint' => (string) $binding['current_build_fingerprint'],
					'expected_package_manifest_digest' => (string) $binding['current_package_manifest_digest'],
					'expected_artifact_identity' => (string) $binding['current_artifact_identity'],
					'expected_agent_public_id' => (string) $write_plan['agent_public_id'],
					'expected_write_tool_count' => (int) $write_plan['write_tool_count'],
					'expected_write_inventory_fingerprint' => (string) $write_plan['write_inventory_fingerprint'],
					'expected_grant_rows_fingerprint' => (string) $write_plan['grant_rows_fingerprint'],
					'confirmation' => MAD4B_SCP_Staging_Write_Candidate_Binding::CONFIRMATION,
				), $reviewed_previous_binding );
				if ( is_wp_error( $bind ) ) return self::fail_closed( 'candidate_binding_failed', $bind );
			}

			if ( ! $binding_required || $binding_match_before ) {
				$noop = MAD4B_SCP_Audit::record( 'mad4b/staging-write-authority-convergence-noop', array(
					'contract' => self::CONTRACT,
					'state' => 'already_current',
					'source_commit_sha' => $plan['source_commit_sha'],
					'site_uuid' => $plan['site_uuid'],
					'developer_authority_mutation' => false,
					'developer_breakglass_mutation' => false,
					'breakglass_included' => false,
					'production_mutation' => false,
				), 'ok' );
				if ( is_wp_error( $noop ) ) return self::fail_closed( 'noop_audit_failed', new WP_Error( 'mad4b_staging_write_authority_noop_audit_failed', 'Already-current Write authority evidence could not be committed.' ) );
			}

			$confirmation = self::confirm_live_postconditions( $plan, array(
				'write_tool_count' => $write_plan['write_tool_count'],
				'write_inventory_fingerprint' => $write_plan['write_inventory_fingerprint'],
				'grant_rows_fingerprint' => $write_plan['grant_rows_fingerprint'],
			) );
			if ( is_wp_error( $confirmation ) || empty( $confirmation['verified'] ) )
				return self::fail_closed( 'same_cycle_confirmation_unverified', is_wp_error( $confirmation )
					? $confirmation : new WP_Error( 'mad4b_staging_write_postcondition_unverified',
						'Exact current Write authority cannot be independently verified.' ) );
			$completion = MAD4B_SCP_Audit::record( 'mad4b/staging-write-authority-convergence-verified', array(
				'contract' => self::CONTRACT, 'plan_sha256' => $plan['plan_sha256'],
				'source_commit_sha' => $plan['source_commit_sha'], 'site_uuid' => $plan['site_uuid'],
				'postcondition_receipt_sha256' => $confirmation['receipt_sha256'],
				'candidate_binding_committed' => $binding_required && ! $binding_match_before,
				'developer_authority_mutation' => false, 'developer_breakglass_mutation' => false,
				'breakglass_included' => false, 'production_mutation' => false,
			), 'ok' );
			if ( is_wp_error( $completion ) )
				return self::fail_closed( 'postcondition_audit_failed', new WP_Error(
					'mad4b_staging_write_postcondition_audit_failed', 'Durable completion audit failed; reconcile before retry.' ) );

			return array(
				'contract' => self::CONTRACT,
				'state' => 'governed_staging_write_authority_ready',
				'write_ready' => true,
				'verified_current_state' => true,
				'confirmation_scope' => 'same_request_state_not_external_release',
				'confirmation' => $confirmation,
				'blind_retry_allowed' => false,
				'candidate_binding_committed' => $binding_required && ! $binding_match_before,
				'candidate_binding_result' => is_array( $bind ) ? $bind : array(),
				'candidate_binding_lineage' => array(
					'reviewed_previous_binding' => $reviewed_previous_binding,
					'pre_bind_persisted_binding' => $pre_bind_persisted_binding,
				),
				'developer_authority_mutation' => false,
				'developer_breakglass_mutation' => false,
				'generic_raw_sql_breakglass_enabled' => false,
				'production_mutation' => false,
			);
		} finally {
			self::$running = false;
		}
	}


	/**
	 * Pure, non-authorizing readback predicate. Caller-provided data must not
	 * be treated as a signed, independently collected runtime certificate.
	 */
	public static function evaluate_postconditions( array $expected, array $observed ) {
		$binding = isset( $observed['binding'] ) && is_array( $observed['binding'] ) ? $observed['binding'] : array();
		$write = isset( $observed['write'] ) && is_array( $observed['write'] ) ? $observed['write'] : array();
		$current = isset( $observed['current'] ) && is_array( $observed['current'] ) ? $observed['current'] : array();
		$site = isset( $observed['site'] ) && is_array( $observed['site'] ) ? $observed['site'] : array();
		$build = isset( $observed['provenance'] ) && is_array( $observed['provenance'] ) ? $observed['provenance'] : array();
		$grants = isset( $expected['write_snapshot'] ) && is_array( $expected['write_snapshot'] ) ? $expected['write_snapshot'] : array();
		$checks = array();
		$checks['site'] = ! empty( $expected['site_uuid'] ) && ! empty( $expected['site_profile_digest'] )
			&& 'staging' === ( isset( $site['environment'] ) ? (string) $site['environment'] : '' )
			&& ! empty( $site['write_enabled'] ) && empty( $site['raw_sql_breakglass_enabled'] )
			&& hash_equals( (string) $expected['site_uuid'], isset( $site['site_uuid'] ) ? (string) $site['site_uuid'] : '' )
			&& hash_equals( (string) $expected['site_profile_digest'], isset( $site['site_profile_digest'] ) ? (string) $site['site_profile_digest'] : '' )
			&& isset( $expected['site_profile_revision'], $site['site_profile_revision'] )
			&& (int) $expected['site_profile_revision'] === (int) $site['site_profile_revision'];
		$checks['candidate'] = ! empty( $binding['required'] ) && ! empty( $binding['match'] )
			&& 'complete' === ( isset( $binding['identity_completeness'] ) ? (string) $binding['identity_completeness'] : '' );
		$checks['provenance'] = true;
		foreach ( array(
			'source_commit_sha' => 'stored_source_commit_sha',
			'build_fingerprint' => 'stored_build_fingerprint',
			'package_manifest_digest' => 'stored_package_manifest_digest',
			'artifact_identity' => 'stored_artifact_identity',
		) as $field => $stored ) {
			$value = isset( $expected[ $field ] ) ? (string) $expected[ $field ] : '';
			$checks['candidate'] = $checks['candidate'] && '' !== $value
				&& hash_equals( $value, isset( $binding[ $stored ] ) ? (string) $binding[ $stored ] : '' );
			$checks['provenance'] = $checks['provenance'] && '' !== $value
				&& hash_equals( $value, isset( $build[ $field ] ) ? (string) $build[ $field ] : '' );
		}
		$checks['current'] = ! empty( $current['ready'] ) && ! empty( $current['cheap_effective'] )
			&& ! empty( $current['current_grant_snapshot_ready'] ) && ! empty( $current['candidate_binding_match'] )
			&& empty( $current['candidate_bootstrap_exception'] )
			&& isset( $current['blockers'] ) && is_array( $current['blockers'] ) && empty( $current['blockers'] );
		$checks['grants'] = ! empty( $write['current_ready'] ) && ! empty( $write['effective_ready'] )
			&& isset( $write['write_tool_count'], $write['exact_grants_existing'], $grants['write_tool_count'] )
			&& (int) $write['write_tool_count'] > 0
			&& (int) $write['write_tool_count'] === (int) $write['exact_grants_existing']
			&& (int) $write['write_tool_count'] === (int) $grants['write_tool_count'];
		foreach ( array( 'write_inventory_fingerprint', 'grant_rows_fingerprint' ) as $field )
			$checks['grants'] = $checks['grants'] && ! empty( $grants[ $field ] ) && isset( $write[ $field ] )
				&& hash_equals( (string) $grants[ $field ], (string) $write[ $field ] );
		foreach ( array( 'exact_grants_missing_count', 'stale_allow_grants_count',
			'unreviewed_stale_allow_grants_count', 'broad_environment_grants_count',
			'duplicate_exact_allow_grants_count', 'current_agent_wildcard_grants',
			'global_registry_wildcard_grants' ) as $field )
			$checks['grants'] = $checks['grants'] && isset( $write[ $field ] ) && 0 === (int) $write[ $field ];
		$checks['breakglass'] = empty( $write['breakglass_included'] );
		return array(
			'contract' => 'mad4b.staging-write-postcondition-evaluation.v1',
			'verified' => ! in_array( false, $checks, true ), 'checks' => $checks,
			'evidence_origin' => 'caller_supplied_not_trusted',
			'authorizing' => false, 'mutation_performed' => false,
		);
	}

	/** Build an exact same-request readback; never retries a mutation. */
	private static function confirm_live_postconditions( array $expected, array $write_snapshot ) {
		$build = self::provenance();
		if ( is_wp_error( $build ) ) return $build;
		$expected['write_snapshot'] = $write_snapshot;
		$receipt = self::evaluate_postconditions( $expected, array(
			'provenance' => $build,
			'site' => array(
				'environment' => MAD4B_SCP_Site_Profile::current_environment(),
				'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
				'site_profile_revision' => MAD4B_SCP_Site_Profile::revision(),
				'site_profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
				'write_enabled' => MAD4B_SCP_Site_Profile::write_enabled(),
				'raw_sql_breakglass_enabled' => self::generic_raw_sql_breakglass_gate_enabled(),
			),
			'binding' => MAD4B_SCP_Staging_Write_Authority::candidate_binding_status(),
			'write' => MAD4B_SCP_Staging_Write_Authority::reconciliation_plan(),
			'current' => MAD4B_SCP_Staging_Write_Authority::current_execution_readiness(),
		) );
		$receipt['evidence_origin'] = 'same_request_server_readback';
		$receipt['plan_sha256'] = $expected['plan_sha256'];
		$receipt['source_commit_sha'] = $expected['source_commit_sha'];
		$receipt['site_uuid'] = $expected['site_uuid'];
		$receipt['observed_at'] = gmdate( 'c' );
		$json = wp_json_encode( $receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) return new WP_Error( 'mad4b_staging_write_receipt_encode_failed', 'Postcondition receipt encoding failed.' );
		$receipt['receipt_sha256'] = hash( 'sha256', $json );
		return $receipt;
	}

	private static function write_plan() {
		return class_exists( 'MAD4B_SCP_Staging_Write_Authority' )
			? MAD4B_SCP_Staging_Write_Authority::reconciliation_plan()
			: new WP_Error( 'mad4b_staging_write_authority_unavailable', 'Governed Write authority is unavailable.' );
	}

	private static function provenance() {
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_staging_write_authority_provenance_unavailable', 'Build provenance authority is unavailable.' );
		$p = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $p ) || empty( $p['manifest_present'] ) || empty( $p['manifest_valid'] ) || empty( $p['runtime_manifest_match'] ) || ! empty( $p['stale'] ) || ! empty( $p['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_staging_write_authority_provenance_not_ready', 'Exact current build provenance is not ready.' );
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $key ) if ( empty( $p[ $key ] ) ) return new WP_Error( 'mad4b_staging_write_authority_provenance_incomplete', 'Build provenance identity is incomplete.' );
		return array(
			'source_commit_sha' => strtolower( (string) $p['source_commit_sha'] ),
			'build_fingerprint' => strtolower( (string) $p['build_fingerprint'] ),
			'package_manifest_digest' => strtolower( (string) $p['package_manifest_digest'] ),
			'artifact_identity' => (string) $p['artifact_identity'],
		);
	}

	private static function match_expected_plan( array $plan, array $input ) {
		$checks = array(
			'plan_sha256' => strtolower( trim( (string) $input['expected_plan_sha256'] ) ),
			'source_commit_sha' => strtolower( trim( (string) $input['expected_source_commit_sha'] ) ),
			'build_fingerprint' => strtolower( trim( (string) $input['expected_build_fingerprint'] ) ),
			'package_manifest_digest' => strtolower( trim( (string) $input['expected_package_manifest_digest'] ) ),
			'artifact_identity' => trim( (string) $input['expected_artifact_identity'] ),
			'site_uuid' => strtolower( trim( (string) $input['expected_site_uuid'] ) ),
			'site_profile_digest' => strtolower( trim( (string) $input['expected_profile_digest'] ) ),
		);
		foreach ( $checks as $key => $expected ) {
			$current = isset( $plan[ $key ] ) ? (string) $plan[ $key ] : '';
			if ( '' === $expected || ! hash_equals( strtolower( $current ), strtolower( $expected ) ) ) return new WP_Error( 'mad4b_staging_write_authority_plan_stale', 'Governed Write plan identity changed before apply.', array( 'field' => $key ) );
		}
		if ( (int) $plan['site_profile_revision'] !== absint( $input['expected_profile_revision'] ) ) return new WP_Error( 'mad4b_staging_write_authority_plan_stale', 'Site Profile revision changed before apply.', array( 'field' => 'site_profile_revision' ) );
		return true;
	}

	private static function plan_sha256( array $plan ) {
		unset( $plan['plan_sha256'] );
		$json = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $json ? '' : $json );
	}

	private static function compact_string_list( $items, $limit = 16 ) {
		if ( ! is_array( $items ) ) return array();
		$out = array();
		foreach ( $items as $item ) {
			$item = sanitize_key( (string) $item );
			if ( '' === $item ) continue;
			$out[] = $item;
			if ( count( $out ) >= max( 1, absint( $limit ) ) ) break;
		}
		return array_values( array_unique( $out ) );
	}

	private static function fail_closed( $reason, WP_Error $error ) {
		if ( class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'fail_closed_persisted_authority' ) ) {
			MAD4B_SCP_Staging_Write_Authority::fail_closed_persisted_authority( sanitize_key( (string) $reason ) );
		}
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/staging-write-authority-convergence-fail-closed', array(
				'contract' => self::CONTRACT,
				'reason' => sanitize_key( (string) $reason ),
				'cause' => $error->get_error_code(),
				'developer_authority_mutation' => false,
				'developer_breakglass_mutation' => false,
				'generic_raw_sql_breakglass_enabled' => false,
				'production_mutation' => false,
			), 'blocked' );
		}
		return new WP_Error(
			'mad4b_staging_write_authority_apply_failed',
			'Governed Staging Write Authority convergence failed closed.',
			array( 'reason' => sanitize_key( (string) $reason ), 'cause' => $error->get_error_code() )
		);
	}
}

MAD4B_SCP_Staging_Write_Authority_Convergence::boot();
