<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact, audited Staging-only convergence of the three requested authority
 * planes: governed write, Developer, and Developer Breakglass.
 *
 * OAuth remains identity/read consent only. Read-only status/plan are exposed
 * on mad4b-chatgpt for diagnosis. The single ChatGPT app may also project the
 * composite apply ability as one Staging-only step-up tool for the enrolled
 * normal OAuth identity. tools/list never computes the full authority plan;
 * execution itself recomputes and exact-matches the plan before any mutation.
 * Low-level enrollment/developer primitives remain internal and the composite
 * apply path reuses them rather than creating parallel grant semantics.
 */
final class MAD4B_SCP_Full_Staging_Authority {
	const CONTRACT = 'mad4b.full-staging-authority.v1';
	const STATUS_ABILITY = 'mad4b/full-staging-authority-status';
	const PLAN_ABILITY = 'mad4b/full-staging-authority-plan';
	const APPLY_ABILITY = 'mad4b/full-staging-authority-apply';
	const CONFIRMATION = 'ENABLE FULL STAGING AUTHORITY';

	private static $booted = false;
	private static $running = false;

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ), 18 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 18 );
	}

	public static function enrollment_tools() {
		return array( self::STATUS_ABILITY, self::PLAN_ABILITY, self::APPLY_ABILITY );
	}

	public static function chatgpt_read_tools() {
		return array( self::STATUS_ABILITY, self::PLAN_ABILITY );
	}

	/**
	 * Project only the composite authority mutation onto the single ChatGPT app.
	 *
	 * Keep tools/list cheap and lifecycle-stable: catalog construction can happen
	 * before transport permission binds the request OAuth identity, so projection
	 * depends only on exact enrolled Staging site facts and the raw-SQL
	 * Breakglass gate. The apply callback remains the authority boundary: it
	 * requires the verified enrolled OAuth administrator, recomputes the full
	 * plan, requires ready_to_apply with no hard blockers, exact-matches every
	 * expected identity/digest/revision field, and fails closed before mutation.
	 */
	public static function chatgpt_step_up_tools() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return array();
		if ( 'staging' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) ) return array();
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return array();
		if ( self::generic_raw_sql_breakglass_gate_enabled() ) return array();
		return array( self::APPLY_ABILITY );
	}

	public static function register_category() {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category( 'mad4b-full-staging-authority', array(
				'label' => 'MAD4B Full Staging Authority',
				'description' => 'Exact plan-bound Staging authority convergence for Write, Developer and Developer Breakglass.',
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
					'label' => 'Full Staging Authority Status',
					'description' => 'Read the combined governed Write, Developer and Developer Breakglass authority state.',
					'category' => 'mad4b-full-staging-authority',
					'execute_callback' => array( __CLASS__, 'status' ),
					'permission_callback' => array( __CLASS__, 'can_access' ),
					'input_schema' => self::schema( array() ),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::meta( true, 'read' ),
				) );
			}
			if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::PLAN_ABILITY ) ) {
				wp_register_ability( self::PLAN_ABILITY, array(
					'label' => 'Plan Full Staging Authority',
					'description' => 'Build an exact read-only convergence plan for Write, Developer and Developer Breakglass.',
					'category' => 'mad4b-full-staging-authority',
					'execute_callback' => array( __CLASS__, 'plan' ),
					'permission_callback' => array( __CLASS__, 'can_access' ),
					'input_schema' => self::schema( array() ),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::meta( true, 'read' ),
				) );
			}
			if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::APPLY_ABILITY ) ) {
				wp_register_ability( self::APPLY_ABILITY, array(
					'label' => 'Enable Full Staging Authority',
					'description' => 'Converge exact governed Write, bind the current package candidate, and provision isolated Developer plus Developer Breakglass authority on Staging only.',
					'category' => 'mad4b-full-staging-authority',
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
		return array(
			'public' => false,
			'show_in_rest' => false,
			'mcp' => array(
				'public' => false,
				'type' => 'tool',
				'surface' => sanitize_key( (string) $surface ),
				'mad4b_full_staging_authority' => self::CONTRACT,
				'production_allowed' => false,
				'generic_raw_sql_breakglass_included' => false,
			),
			'annotations' => array(
				'readonly' => (bool) $readonly,
				'destructive' => ! $readonly,
				'idempotent' => $readonly,
			),
		);
	}

	public static function can_access( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_full_authority_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_full_authority_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_full_authority_profile_required', 'An enrolled Site Profile is required.' );
		if ( 'staging' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) ) return new WP_Error( 'mad4b_full_authority_staging_only', 'Full authority convergence is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_full_authority_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Staging Site Profile.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_full_authority_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : new WP_Error( 'mad4b_full_authority_identity_unavailable', 'Governance identity is unavailable.' );
		if ( is_wp_error( $identity ) ) return $identity;
		if ( empty( $identity['authenticated'] ) || 'oauth' !== ( isset( $identity['subject_type'] ) ? (string) $identity['subject_type'] : '' ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) {
			return new WP_Error( 'mad4b_full_authority_normal_oauth_required', 'Full authority convergence must originate from the enrolled normal OAuth identity.' );
		}
		if ( self::generic_raw_sql_breakglass_gate_enabled() || ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_breakglass() ) ) {
			return new WP_Error( 'mad4b_full_authority_raw_sql_breakglass_denied', 'Generic raw-SQL Breakglass must remain disabled during Full Staging Authority convergence.' );
		}
		return true;
	}

	public static function can_apply( $input = null ) {
		$access = self::can_access( $input );
		if ( is_wp_error( $access ) || ! $access ) return $access;
		if ( ! defined( 'MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE' )
			|| ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ) {
			return new WP_Error(
				'mad4b_full_authority_step_up_scope_required',
				'The OAuth bearer does not grant the dedicated Full Staging Authority step-up scope.'
			);
		}
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
			|| ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ) {
			return new WP_Error(
				'mad4b_full_authority_chatgpt_client_required',
				'Full Staging Authority step-up requires OAuth attribution to the exact ChatGPT CIMD client.'
			);
		}
		return true;
	}

	private static function generic_raw_sql_breakglass_gate_enabled() {
		return defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) && true === constant( 'MAD4B_MCP_BREAKGLASS_ENABLED' );
	}

	public static function status() {
		$write_plan = self::write_plan();
		$developer = class_exists( 'MAD4B_SCP_Developer_Authority' ) ? MAD4B_SCP_Developer_Authority::status() : array();
		$write_ready = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective();
		$normal_ready = ! empty( $developer['developer_enabled'] )
			&& ! empty( $developer['direct_execution_enabled'] )
			&& empty( $developer['kill_switch_enabled'] )
			&& ! empty( $developer['normal_authority']['ready'] );
		$breakglass_ready = $normal_ready
			&& ! empty( $developer['breakglass_enabled'] )
			&& ! empty( $developer['breakglass_authority']['ready'] );
		return array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'authorizing' => false,
			'mutation_performed' => false,
			'environment' => 'staging',
			'production_allowed' => false,
			'generic_raw_sql_breakglass_enabled' => self::generic_raw_sql_breakglass_gate_enabled(),
			'write' => array(
				'ready' => $write_ready,
				'plan' => is_array( $write_plan ) ? $write_plan : array(),
			),
			'developer' => array(
				'ready' => $normal_ready,
				'status' => $developer,
			),
			'developer_breakglass' => array(
				'ready' => $breakglass_ready,
				'status' => $developer,
			),
			'ready' => $write_ready && $normal_ready && $breakglass_ready,
		);
	}

	public static function plan() {
		$access = self::can_access();
		if ( is_wp_error( $access ) || ! $access ) return $access;

		$provenance = self::provenance();
		if ( is_wp_error( $provenance ) ) return $provenance;
		$write_plan = self::write_plan();
		if ( is_wp_error( $write_plan ) ) return $write_plan;
		$developer_status = MAD4B_SCP_Developer_Authority::status();
		$developer_plan = MAD4B_SCP_Developer_Authority::plan();
		if ( is_wp_error( $developer_plan ) ) return $developer_plan;
		$developer_breakglass_plan = MAD4B_SCP_Developer_Authority::breakglass_plan();
		if ( is_wp_error( $developer_breakglass_plan ) ) return $developer_breakglass_plan;

		$hard_blockers = array();
		if ( isset( $write_plan['global_registry_wildcard_grants'] ) && (int) $write_plan['global_registry_wildcard_grants'] > 0 ) $hard_blockers[] = 'global_registry_wildcard_grants';
		if ( isset( $write_plan['current_agent_wildcard_grants'] ) && (int) $write_plan['current_agent_wildcard_grants'] > 0 ) $hard_blockers[] = 'current_agent_wildcard_grants';
		if ( ! MAD4B_SCP_Site_Profile::oauth_enabled() ) $hard_blockers[] = 'oauth_disabled';
		if ( ! MAD4B_SCP_Site_Profile::acceptance_enabled() ) $hard_blockers[] = 'acceptance_disabled';
		if ( ! MAD4B_SCP_Site_Profile::skills_enabled() ) $hard_blockers[] = 'skills_disabled';
		if ( '' === MAD4B_SCP_Site_Profile::chatgpt_app_id() ) $hard_blockers[] = 'chatgpt_app_mapping_missing';
		if ( empty( $developer_plan['ready_to_apply'] ) ) {
			$hard_blockers[] = 'developer_authority_plan_blocked';
		}
		$breakglass_blockers = isset( $developer_breakglass_plan['blockers'] ) && is_array( $developer_breakglass_plan['blockers'] )
			? array_values( array_unique( array_map( 'strval', $developer_breakglass_plan['blockers'] ) ) )
			: array();
		$breakglass_fixable_before_normal = array( 'normal_developer_agent_missing', 'normal_developer_authority_not_ready' );
		$breakglass_hard_blockers = array_values( array_diff( $breakglass_blockers, $breakglass_fixable_before_normal ) );
		if ( ! empty( $breakglass_hard_blockers ) ) $hard_blockers[] = 'developer_breakglass_plan_blocked';

		$plan = array(
			'contract' => self::CONTRACT,
			'operation' => 'converge_full_staging_authority',
			'production_allowed' => false,
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
			'write_reconciliation' => $write_plan,
			'developer_status' => $developer_status,
			'developer_plan' => $developer_plan,
			'developer_breakglass_plan' => $developer_breakglass_plan,
			'developer_breakglass_hard_blockers' => $breakglass_hard_blockers,
			'fixable_write_drift' => array(
				'exact_grants_missing_count' => isset( $write_plan['exact_grants_missing_count'] ) ? (int) $write_plan['exact_grants_missing_count'] : 0,
				'stale_allow_grants_count' => isset( $write_plan['stale_allow_grants_count'] ) ? (int) $write_plan['stale_allow_grants_count'] : 0,
				'broad_environment_grants_count' => isset( $write_plan['broad_environment_grants_count'] ) ? (int) $write_plan['broad_environment_grants_count'] : 0,
				'duplicate_exact_allow_grants_count' => isset( $write_plan['duplicate_exact_allow_grants_count'] ) ? (int) $write_plan['duplicate_exact_allow_grants_count'] : 0,
				'candidate_binding_match' => ! empty( $write_plan['candidate_binding']['match'] ),
			),
			'hard_blockers' => array_values( array_unique( $hard_blockers ) ),
			'ready_to_apply' => empty( $hard_blockers ),
		);
		$plan['plan_sha256'] = self::plan_sha256( $plan );
		return $plan;
	}

	public static function apply( $input ) {
		if ( self::$running ) return new WP_Error( 'mad4b_full_authority_reentry_denied', 'Full Staging Authority convergence is already running in this request.' );
		$access = self::can_apply( $input );
		if ( is_wp_error( $access ) || ! $access ) return $access;
		if ( ! is_array( $input ) || self::CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) {
			return new WP_Error( 'mad4b_full_authority_confirmation_required', 'Exact confirmation is required.' );
		}

		self::$running = true;
		try {
			$plan = self::plan();
			if ( is_wp_error( $plan ) ) return $plan;
			if ( empty( $plan['ready_to_apply'] ) ) return new WP_Error( 'mad4b_full_authority_plan_blocked', 'Full Staging Authority plan contains hard blockers.', array( 'blockers' => $plan['hard_blockers'] ) );
			$match = self::match_expected_plan( $plan, $input );
			if ( is_wp_error( $match ) ) return $match;
			if ( ! class_exists( 'MAD4B_SCP_Audit' ) || empty( MAD4B_SCP_Audit::storage_status()['ready'] ) ) return new WP_Error( 'mad4b_full_authority_audit_required', 'Ready append-only audit storage is required.' );
			if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding' ) ) return new WP_Error( 'mad4b_full_authority_candidate_binding_unavailable', 'Candidate-binding authority is unavailable.' );
			$reviewed_binding_status = isset( $plan['write_reconciliation']['candidate_binding'] ) && is_array( $plan['write_reconciliation']['candidate_binding'] )
				? $plan['write_reconciliation']['candidate_binding']
				: array();
			$reviewed_previous_binding = MAD4B_SCP_Staging_Write_Candidate_Binding::audit_binding_snapshot( $reviewed_binding_status );

			$intent = MAD4B_SCP_Audit::record( 'mad4b/full-staging-authority-authorized', array(
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
				'requested_authorities' => array( 'write', 'developer', 'developer_breakglass' ),
				'generic_raw_sql_breakglass_requested' => false,
				'production_mutation' => false,
			), 'ok' );
			if ( is_wp_error( $intent ) ) return new WP_Error( 'mad4b_full_authority_intent_audit_failed', 'Full authority authorization evidence could not be committed.' );

			// Enable Site Profile write if needed using the existing exact-bound primitive.
			if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) {
				$result = MAD4B_SCP_Site_Profile_Write_Enablement::enable_write( array(
					'expected_revision' => $plan['site_profile_revision'],
					'expected_profile_digest' => $plan['site_profile_digest'],
					'expected_source_commit_sha' => $plan['source_commit_sha'],
					'expected_build_fingerprint' => $plan['build_fingerprint'],
					'confirmation' => MAD4B_SCP_Site_Profile_Write_Enablement::CONFIRMATION,
				) );
				if ( is_wp_error( $result ) ) return self::fail_closed( 'write_enable_failed', $result );
				// Feature enablement changes the Site Profile revision/digest.
				$plan = self::plan();
				if ( is_wp_error( $plan ) ) return self::fail_closed( 'post_write_enable_plan_failed', $plan );
				if ( empty( $plan['ready_to_apply'] ) ) {
					return self::fail_closed(
						'post_write_enable_plan_blocked',
						new WP_Error( 'mad4b_full_authority_post_write_enable_plan_blocked', 'Full authority plan gained a hard blocker after Site Profile Write enablement.', array( 'blockers' => $plan['hard_blockers'] ) )
					);
				}
			}

			// Provision normal Developer authority first. If any later stage fails,
			// fail_closed() disables Developer execution before Write can become
			// effective through the final exact-candidate binding.
			$developer_plan = MAD4B_SCP_Developer_Authority::plan();
			if ( is_wp_error( $developer_plan ) ) return self::fail_closed( 'developer_plan_failed', $developer_plan );
			if ( empty( $developer_plan['ready_to_apply'] ) ) {
				return self::fail_closed(
					'developer_plan_blocked',
					new WP_Error( 'mad4b_full_authority_developer_plan_blocked', 'Developer authority plan contains non-reconcilable drift.', array( 'blockers' => $developer_plan['blockers'] ) )
				);
			}
			$developer = MAD4B_SCP_Developer_Authority::apply( self::developer_apply_input( $developer_plan, MAD4B_SCP_Developer_Authority::CONFIRM_PROVISION ) );
			if ( is_wp_error( $developer ) ) return self::fail_closed( 'developer_apply_failed', $developer );

			$breakglass_plan = MAD4B_SCP_Developer_Authority::breakglass_plan();
			if ( is_wp_error( $breakglass_plan ) ) return self::fail_closed( 'developer_breakglass_plan_failed', $breakglass_plan );
			if ( empty( $breakglass_plan['ready_to_apply'] ) ) {
				return self::fail_closed(
					'developer_breakglass_plan_blocked',
					new WP_Error( 'mad4b_full_authority_developer_breakglass_plan_blocked', 'Developer Breakglass authority plan contains non-reconcilable drift.', array( 'blockers' => $breakglass_plan['blockers'] ) )
				);
			}
			$breakglass = MAD4B_SCP_Developer_Authority::breakglass_apply( self::developer_apply_input( $breakglass_plan, MAD4B_SCP_Developer_Authority::CONFIRM_BREAKGLASS ) );
			if ( is_wp_error( $breakglass ) ) return self::fail_closed( 'developer_breakglass_apply_failed', $breakglass );

			// Reconcile only the current runtime-eligible Write inventory. This
			// removes stale/broad/duplicate current-agent allows and creates exact
			// missing current-environment grants. Provider-gated catalog entries
			// remain gated until provider certification.
			$write = MAD4B_SCP_Staging_Write_Authority::reconcile();
			if ( is_wp_error( $write ) || empty( $write['ready'] ) ) {
				$error = is_wp_error( $write ) ? $write : new WP_Error( 'mad4b_full_authority_write_reconcile_incomplete', 'Governed write reconciliation did not converge.' );
				return self::fail_closed( 'write_reconcile_failed', $error );
			}

			$write_plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
			if ( ! is_array( $write_plan ) ) return self::fail_closed( 'write_plan_unavailable_after_reconcile', new WP_Error( 'mad4b_full_authority_write_plan_unavailable', 'Write reconciliation plan is unavailable after convergence.' ) );

			// Prove every authority except the final package binding is already ready.
			// Nothing after the binding may introduce a second fallible governance
			// mutation, otherwise a composite failure could leave Write effective.
			$developer_after = MAD4B_SCP_Developer_Authority::status();
			$developer_ready = ! empty( $developer_after['developer_enabled'] )
				&& ! empty( $developer_after['direct_execution_enabled'] )
				&& empty( $developer_after['kill_switch_enabled'] )
				&& ! empty( $developer_after['normal_authority']['ready'] );
			$developer_breakglass_ready = $developer_ready
				&& ! empty( $developer_after['breakglass_enabled'] )
				&& ! empty( $developer_after['breakglass_authority']['ready'] );
			if ( ! $developer_ready || ! $developer_breakglass_ready ) {
				return self::fail_closed( 'developer_postcondition_failed', new WP_Error( 'mad4b_full_authority_developer_postcondition_failed', 'Developer authorities did not converge before the final Write commit point.' ) );
			}
			$write_reconciled = ! empty( $write_plan['current_ready'] )
				&& isset( $write_plan['exact_grants_existing'], $write_plan['write_tool_count'] )
				&& (int) $write_plan['exact_grants_existing'] === (int) $write_plan['write_tool_count']
				&& empty( $write_plan['exact_grants_missing_count'] )
				&& empty( $write_plan['stale_allow_grants_count'] )
				&& empty( $write_plan['broad_environment_grants_count'] )
				&& empty( $write_plan['duplicate_exact_allow_grants_count'] )
				&& empty( $write_plan['current_agent_wildcard_grants'] )
				&& empty( $write_plan['global_registry_wildcard_grants'] );
			if ( ! $write_reconciled ) {
				return self::fail_closed( 'write_postcondition_failed', new WP_Error( 'mad4b_full_authority_write_postcondition_failed', 'Governed Write grants did not converge before the final package binding.' ) );
			}

			$binding = isset( $write_plan['candidate_binding'] ) && is_array( $write_plan['candidate_binding'] ) ? $write_plan['candidate_binding'] : array();
			$binding_required = ! empty( $binding['required'] );
			$binding_match_before = ! $binding_required || ! empty( $binding['match'] );
			$pre_bind_persisted_binding = MAD4B_SCP_Staging_Write_Candidate_Binding::audit_binding_snapshot( $binding );

			$prepared = MAD4B_SCP_Audit::record( 'mad4b/full-staging-authority-prepared', array(
				'contract' => self::CONTRACT,
				'state' => 'ready_for_final_candidate_binding',
				'source_commit_sha' => $plan['source_commit_sha'],
				'site_uuid' => $plan['site_uuid'],
				'write_reconciled' => true,
				'developer_ready' => true,
				'developer_breakglass_ready' => true,
				'candidate_binding_required' => $binding_required,
				'candidate_binding_match_before' => $binding_match_before,
				'reviewed_previous_binding' => $reviewed_previous_binding,
				'pre_bind_persisted_binding' => $pre_bind_persisted_binding,
				'generic_raw_sql_breakglass_enabled' => false,
				'production_mutation' => false,
			), 'ok' );
			if ( is_wp_error( $prepared ) ) return self::fail_closed( 'prepared_audit_failed', new WP_Error( 'mad4b_full_authority_prepared_audit_failed', 'Full authority prepared evidence could not be committed before the final binding.' ) );

			// Exact candidate binding is the commit point. Its own primitive writes
			// authorized/completion/rollback audit records transactionally and proves
			// MAD4B_SCP_Staging_Write_Authority::effective() before returning success.
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

			// No fallible governance mutation follows the commit point. For an
			// already-bound idempotent run, append the composite completion record
			// because no new Write authority is being committed by this invocation.
			if ( $binding_match_before ) {
				$complete = MAD4B_SCP_Audit::record( 'mad4b/full-staging-authority-complete', array(
					'contract' => self::CONTRACT,
					'state' => 'already_bound_ready',
					'source_commit_sha' => $plan['source_commit_sha'],
					'site_uuid' => $plan['site_uuid'],
					'write_ready' => true,
					'developer_ready' => true,
					'developer_breakglass_ready' => true,
					'reviewed_previous_binding' => $reviewed_previous_binding,
					'pre_bind_persisted_binding' => $pre_bind_persisted_binding,
					'generic_raw_sql_breakglass_enabled' => false,
					'production_mutation' => false,
				), 'ok' );
				if ( is_wp_error( $complete ) ) return self::fail_closed( 'completion_audit_failed', new WP_Error( 'mad4b_full_authority_completion_audit_failed', 'Idempotent full authority completion audit failed.' ) );
			}

			return array(
				'contract' => self::CONTRACT,
				'state' => 'full_staging_authority_ready',
				'write_ready' => true,
				'developer_ready' => true,
				'developer_breakglass_ready' => true,
				'candidate_binding_committed' => $binding_required && ! $binding_match_before,
				'candidate_binding_result' => is_array( $bind ) ? $bind : array(),
				'candidate_binding_lineage' => array(
					'reviewed_previous_binding' => $reviewed_previous_binding,
					'pre_bind_persisted_binding' => $pre_bind_persisted_binding,
				),
				'generic_raw_sql_breakglass_enabled' => false,
				'production_mutation' => false,
			);
		} finally {
			self::$running = false;
		}
	}

	private static function developer_apply_input( array $plan, $confirmation ) {
		return array(
			'expected_plan_sha256' => (string) $plan['plan_sha256'],
			'expected_source_commit_sha' => (string) $plan['source_commit_sha'],
			'expected_site_uuid' => (string) $plan['site_uuid'],
			'expected_environment' => (string) $plan['environment'],
			'expected_profile_revision' => (int) $plan['site_profile_revision'],
			'expected_profile_digest' => (string) $plan['site_profile_digest'],
			'confirmation' => (string) $confirmation,
		);
	}

	private static function write_plan() {
		return class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::reconciliation_plan() : new WP_Error( 'mad4b_full_authority_write_unavailable', 'Governed write authority is unavailable.' );
	}

	private static function provenance() {
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_full_authority_provenance_unavailable', 'Build provenance authority is unavailable.' );
		$p = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $p ) || empty( $p['manifest_present'] ) || empty( $p['manifest_valid'] ) || empty( $p['runtime_manifest_match'] ) || ! empty( $p['stale'] ) || ! empty( $p['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_full_authority_provenance_not_ready', 'Exact current build provenance is not ready.' );
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $key ) if ( empty( $p[ $key ] ) ) return new WP_Error( 'mad4b_full_authority_provenance_incomplete', 'Build provenance identity is incomplete.' );
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
			if ( '' === $expected || ! hash_equals( strtolower( $current ), strtolower( $expected ) ) ) return new WP_Error( 'mad4b_full_authority_plan_stale', 'Full authority plan identity changed before apply.', array( 'field' => $key ) );
		}
		if ( (int) $plan['site_profile_revision'] !== absint( $input['expected_profile_revision'] ) ) return new WP_Error( 'mad4b_full_authority_plan_stale', 'Site Profile revision changed before apply.', array( 'field' => 'site_profile_revision' ) );
		return true;
	}

	private static function plan_sha256( array $plan ) {
		unset( $plan['plan_sha256'] );
		$json = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $json ? '' : $json );
	}

	private static function fail_closed( $reason, WP_Error $error ) {
		// Developer authority has a dedicated kill switch. If any composite
		// activation stage fails, disable Developer execution so partially
		// converged developer grants cannot execute. Governed Write remains
		// independently fail-closed through its candidate binding/effective gate.
		update_option( 'mad4b_scp_developer_kill_switch', '1', false );
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/full-staging-authority-fail-closed', array(
				'contract' => self::CONTRACT,
				'reason' => sanitize_key( (string) $reason ),
				'cause' => $error->get_error_code(),
				'developer_kill_switch_enabled' => true,
				'generic_raw_sql_breakglass_enabled' => false,
				'production_mutation' => false,
			), 'blocked' );
		}
		return new WP_Error(
			'mad4b_full_authority_apply_failed',
			'Full Staging Authority convergence failed closed.',
			array( 'reason' => sanitize_key( (string) $reason ), 'cause' => $error->get_error_code() )
		);
	}
}

MAD4B_SCP_Full_Staging_Authority::boot();
