<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact Staging-only candidate binding bootstrap.
 *
 * This surface is intentionally narrower than grant reconciliation:
 * - it never creates/revokes grants;
 * - it never creates/rebinds subjects or agents;
 * - it never calls MAD4B_SCP_Staging_Write_Authority::reconcile();
 * - it binds only the already-reconciled persisted authority to the exact
 *   currently-installed package candidate after proving the grant snapshot is
 *   clean and unchanged from the operator-reviewed read-only plan.
 */
final class MAD4B_SCP_Staging_Write_Candidate_Binding {
	const CONTRACT = 'mad4b.staging-write-candidate-binding.v1';
	const ABILITY = 'mad4b/staging-write-candidate-bind';
	const SERVER_ID = 'mad4b-enrollment';
	const CONFIRMATION = 'BIND EXACT CURRENT STAGING WRITE CANDIDATE';

	private static $booted = false;
	private static $running = false;

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 12 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;

		// Enrollment bootstrap is intentionally separate from the normal
		// mad4b-write inventory so exposing this tool never creates grant #41.
		$augment = array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' );
		$priority = function_exists( 'has_filter' ) ? has_filter( 'wp_register_ability_args', $augment ) : false;
		if ( false !== $priority ) remove_filter( 'wp_register_ability_args', $augment, (int) $priority );

		try {
			wp_register_ability( self::ABILITY, array(
				'label' => 'Bind Exact Staging Write Candidate',
				'description' => 'Bind an already-clean governed-write authority snapshot to the exact current Staging package candidate without reconciling grants, subjects or agents.',
				'category' => 'mad4b-governance',
				'execute_callback' => array( __CLASS__, 'bind' ),
				'permission_callback' => array( __CLASS__, 'can_execute' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
						'expected_profile_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
						'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_package_manifest_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_artifact_identity' => array(
							'type' => 'string',
							'minLength' => 40,
							'maxLength' => 191,
							'pattern' => '^mad4b-site-control-plane-general-distribution-kit-[A-Fa-f0-9]{40}$',
						),
						'expected_agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' ),
						'expected_write_tool_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
						'expected_write_inventory_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_grant_rows_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) ),
					),
					'required' => array(
						'expected_revision',
						'expected_profile_digest',
						'expected_source_commit_sha',
						'expected_build_fingerprint',
						'expected_package_manifest_digest',
						'expected_artifact_identity',
						'expected_agent_public_id',
						'expected_write_tool_count',
						'expected_write_inventory_fingerprint',
						'expected_grant_rows_fingerprint',
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
						'mad4b_candidate_binding_authority' => self::CONTRACT,
						'binding_only' => true,
						'grant_mutation_allowed' => false,
						'subject_mutation_allowed' => false,
						'agent_mutation_allowed' => false,
						'auto_approves' => false,
					),
					'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		} finally {
			if ( false !== $priority ) add_filter( 'wp_register_ability_args', $augment, (int) $priority, 2 );
		}
	}

	public static function can_execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_candidate_bind_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_candidate_bind_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_candidate_bind_profile_missing', 'An enrolled Site Profile is required.' );
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_candidate_bind_staging_only', 'Candidate binding is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_candidate_bind_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Site Profile.' );
		if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) return new WP_Error( 'mad4b_candidate_bind_write_disabled', 'Governed write must already be enabled.' );
		if ( 'chatgpt-governed-write' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() ) ) return new WP_Error( 'mad4b_candidate_bind_canonical_agent_required', 'Candidate binding is limited to the canonical profile-owned governed-write agent.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_candidate_bind_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		if ( 'https' !== strtolower( (string) wp_parse_url( MAD4B_SCP_Site_Profile::current_origin(), PHP_URL_SCHEME ) ) ) return new WP_Error( 'mad4b_candidate_bind_https_required', 'Remote candidate binding requires HTTPS.' );
		return true;
	}

	private static function clean_plan_or_error( array $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ) return new WP_Error( 'mad4b_candidate_bind_authority_unavailable', 'Governed write authority is unavailable.' );
		$plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
		if ( ! is_array( $plan ) || empty( $plan['read_only'] ) || ! empty( $plan['mutation_performed'] ) ) return new WP_Error( 'mad4b_candidate_bind_plan_invalid', 'Read-only reconciliation evidence is unavailable.' );
		if ( empty( $plan['eligible'] ) || empty( $plan['current_ready'] ) ) return new WP_Error( 'mad4b_candidate_bind_authority_not_persisted_ready', 'Persisted governed-write authority must already be ready.' );
		if ( empty( $plan['agent_present'] ) ) return new WP_Error( 'mad4b_candidate_bind_agent_missing', 'Canonical governed-write agent is unavailable.' );

		$write_tool_count = isset( $plan['write_tool_count'] ) ? (int) $plan['write_tool_count'] : 0;
		$existing = isset( $plan['exact_grants_existing'] ) ? (int) $plan['exact_grants_existing'] : 0;
		if ( $write_tool_count < 1 || $existing !== $write_tool_count ) return new WP_Error( 'mad4b_candidate_bind_exact_grants_incomplete', 'All runtime-eligible write tools must already have exact current-environment grants.' );
		foreach ( array(
			'exact_grants_missing_count',
			'stale_allow_grants_count',
			'broad_environment_grants_count',
			'duplicate_exact_allow_grants_count',
			'current_agent_wildcard_grants',
			'global_registry_wildcard_grants',
		) as $key ) {
			if ( ! empty( $plan[ $key ] ) ) return new WP_Error( 'mad4b_candidate_bind_grant_snapshot_not_clean', 'Candidate binding requires a clean grant snapshot.', array( 'field' => $key, 'value' => $plan[ $key ] ) );
		}
		if ( ! empty( $plan['breakglass_included'] ) ) return new WP_Error( 'mad4b_candidate_bind_breakglass_leak', 'Breakglass/raw SQL must remain outside governed write authority.' );

		$rows = isset( $plan['rows'] ) && is_array( $plan['rows'] ) ? $plan['rows'] : array();
		if ( count( $rows ) !== $write_tool_count ) return new WP_Error( 'mad4b_candidate_bind_row_count_mismatch', 'Reconciliation rows do not match the current write inventory.' );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row )
				|| empty( $row['mounted'] )
				|| empty( $row['exact_grant_present'] )
				|| 'exact_current_environment' !== ( isset( $row['grant_state'] ) ? (string) $row['grant_state'] : '' ) ) {
				return new WP_Error( 'mad4b_candidate_bind_row_not_exact', 'Every governed write row must already be exact/current before candidate binding.' );
			}
		}

		$expected_agent = strtolower( trim( (string) $input['expected_agent_public_id'] ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $expected_agent ) || ! hash_equals( $expected_agent, strtolower( (string) $plan['agent_public_id'] ) ) ) return new WP_Error( 'mad4b_candidate_bind_agent_mismatch', 'Canonical governed-write agent changed after operator review.' );
		if ( (int) $input['expected_write_tool_count'] !== $write_tool_count ) return new WP_Error( 'mad4b_candidate_bind_write_count_mismatch', 'Governed write tool count changed after operator review.' );

		$expected_inventory = strtolower( trim( (string) $input['expected_write_inventory_fingerprint'] ) );
		$live_inventory = isset( $plan['write_inventory_fingerprint'] ) ? strtolower( (string) $plan['write_inventory_fingerprint'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_inventory ) || ! preg_match( '/^[a-f0-9]{64}$/', $live_inventory ) || ! hash_equals( $expected_inventory, $live_inventory ) ) return new WP_Error( 'mad4b_candidate_bind_inventory_fingerprint_mismatch', 'Governed write inventory changed after operator review.' );

		$expected_rows = strtolower( trim( (string) $input['expected_grant_rows_fingerprint'] ) );
		$live_rows = isset( $plan['grant_rows_fingerprint'] ) ? strtolower( (string) $plan['grant_rows_fingerprint'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_rows ) || ! preg_match( '/^[a-f0-9]{64}$/', $live_rows ) || ! hash_equals( $expected_rows, $live_rows ) ) return new WP_Error( 'mad4b_candidate_bind_grant_rows_fingerprint_mismatch', 'Exact grant snapshot changed after operator review.' );

		return $plan;
	}

	private static function rollback_binding_option( $before ) {
		if ( ! is_array( $before ) ) return false;
		$updated = update_option( MAD4B_SCP_Staging_Write_Authority::OPTION, $before, false );
		MAD4B_SCP_Staging_Write_Authority::bootstrap();
		$after = get_option( MAD4B_SCP_Staging_Write_Authority::OPTION, array() );
		return false !== $updated && is_array( $after ) && $before === $after;
	}

	public static function bind( $input ) {
		if ( self::$running ) return new WP_Error( 'mad4b_candidate_bind_reentry_denied', 'Candidate binding is already running in this request.' );
		$permission = self::can_execute( $input );
		if ( is_wp_error( $permission ) || ! $permission ) return $permission;
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_candidate_bind_input_invalid', 'Input must be an object.' );
		if ( self::CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_candidate_bind_confirmation_required', 'Exact candidate binding confirmation is required.' );

		self::$running = true;
		try {
			$current_revision = MAD4B_SCP_Site_Profile::revision();
			$expected_revision = absint( $input['expected_revision'] );
			if ( $current_revision !== $expected_revision ) return new WP_Error( 'mad4b_candidate_bind_revision_stale', 'Site Profile revision changed before candidate binding.' );
			$current_digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
			$expected_digest = strtolower( trim( (string) $input['expected_profile_digest'] ) );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_digest ) || ! hash_equals( $current_digest, $expected_digest ) ) return new WP_Error( 'mad4b_candidate_bind_profile_stale', 'Site Profile digest changed before candidate binding.' );

			$plan = self::clean_plan_or_error( $input );
			if ( is_wp_error( $plan ) ) return $plan;
			$binding = isset( $plan['candidate_binding'] ) && is_array( $plan['candidate_binding'] ) ? $plan['candidate_binding'] : array();
			if ( empty( $binding['required'] ) ) return new WP_Error( 'mad4b_candidate_bind_not_required', 'Current package does not require candidate binding.' );

			$expected_sha = strtolower( trim( (string) $input['expected_source_commit_sha'] ) );
			$expected_build = strtolower( trim( (string) $input['expected_build_fingerprint'] ) );
			$expected_manifest = strtolower( trim( (string) $input['expected_package_manifest_digest'] ) );
			$expected_artifact = trim( (string) $input['expected_artifact_identity'] );
			$current_sha = isset( $binding['current_source_commit_sha'] ) ? strtolower( (string) $binding['current_source_commit_sha'] ) : '';
			$current_build = isset( $binding['current_build_fingerprint'] ) ? strtolower( (string) $binding['current_build_fingerprint'] ) : '';
			$current_manifest = isset( $binding['current_package_manifest_digest'] ) ? strtolower( (string) $binding['current_package_manifest_digest'] ) : '';
			$current_artifact = isset( $binding['current_artifact_identity'] ) ? (string) $binding['current_artifact_identity'] : '';
			if ( ! hash_equals( $expected_sha, $current_sha ) ) return new WP_Error( 'mad4b_candidate_bind_source_mismatch', 'Source commit does not match the exact current package.' );
			if ( ! hash_equals( $expected_build, $current_build ) ) return new WP_Error( 'mad4b_candidate_bind_build_mismatch', 'Build fingerprint does not match the exact current package.' );
			if ( ! hash_equals( $expected_manifest, $current_manifest ) ) return new WP_Error( 'mad4b_candidate_bind_manifest_mismatch', 'Package manifest digest does not match the exact current package.' );
			if ( ! hash_equals( $expected_artifact, $current_artifact ) ) return new WP_Error( 'mad4b_candidate_bind_artifact_mismatch', 'Artifact identity does not match the exact current package.' );

			if ( ! empty( $binding['match'] ) ) {
				return array(
					'contract' => self::CONTRACT,
					'state' => 'already_bound',
					'idempotent' => true,
					'mutation_performed' => false,
					'grant_mutation_performed' => false,
					'reconcile_called' => false,
					'effective' => MAD4B_SCP_Staging_Write_Authority::effective(),
					'candidate_binding' => $binding,
					'write_tool_count' => (int) $plan['write_tool_count'],
					'grant_rows_fingerprint' => (string) $plan['grant_rows_fingerprint'],
				);
			}

			$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
			if ( empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_candidate_bind_audit_required', 'Ready append-only audit storage is required.' );
			$before_option = get_option( MAD4B_SCP_Staging_Write_Authority::OPTION, null );
			if ( ! is_array( $before_option ) ) return new WP_Error( 'mad4b_candidate_bind_persisted_authority_missing', 'Persisted governed-write authority is unavailable.' );

			$intent = MAD4B_SCP_Audit::record( 'mad4b/staging-write-candidate-binding-authorized', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $plan['agent_public_id'],
				'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
				'site_profile_revision' => $current_revision,
				'site_profile_digest' => $current_digest,
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_build,
				'package_manifest_digest' => $current_manifest,
				'artifact_identity' => $current_artifact,
				'write_tool_count' => (int) $plan['write_tool_count'],
				'write_inventory_fingerprint' => (string) $plan['write_inventory_fingerprint'],
				'grant_rows_fingerprint' => (string) $plan['grant_rows_fingerprint'],
				'confirmation' => self::CONFIRMATION,
				'grant_mutation_performed' => false,
				'reconcile_called' => false,
				'production_mutation' => false,
			), 'ok' );
			if ( is_wp_error( $intent ) ) return new WP_Error( 'mad4b_candidate_bind_intent_audit_failed', 'Candidate binding authorization evidence could not be committed before mutation.' );

			$bound = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( $current_sha, $current_build );
			if ( is_wp_error( $bound ) ) return $bound;

			$after_binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
			$after_plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
			$post_ok = is_array( $after_binding )
				&& ! empty( $after_binding['required'] )
				&& ! empty( $after_binding['match'] )
				&& hash_equals( $current_sha, (string) $after_binding['stored_source_commit_sha'] )
				&& hash_equals( $current_build, (string) $after_binding['stored_build_fingerprint'] )
				&& is_array( $after_plan )
				&& (int) $after_plan['write_tool_count'] === (int) $plan['write_tool_count']
				&& (int) $after_plan['exact_grants_existing'] === (int) $plan['exact_grants_existing']
				&& 0 === (int) $after_plan['exact_grants_missing_count']
				&& 0 === (int) $after_plan['stale_allow_grants_count']
				&& 0 === (int) $after_plan['broad_environment_grants_count']
				&& 0 === (int) $after_plan['duplicate_exact_allow_grants_count']
				&& 0 === (int) $after_plan['current_agent_wildcard_grants']
				&& 0 === (int) $after_plan['global_registry_wildcard_grants']
				&& hash_equals( (string) $plan['write_inventory_fingerprint'], (string) $after_plan['write_inventory_fingerprint'] )
				&& hash_equals( (string) $plan['grant_rows_fingerprint'], (string) $after_plan['grant_rows_fingerprint'] )
				&& MAD4B_SCP_Staging_Write_Authority::effective();

			if ( ! $post_ok ) {
				$rolled_back = self::rollback_binding_option( $before_option );
				return new WP_Error(
					'mad4b_candidate_bind_postcondition_failed',
					'Candidate binding postconditions failed; the previous persisted authority binding was restored when possible.',
					array( 'rollback_restored' => $rolled_back, 'candidate_binding' => $after_binding )
				);
			}

			$completion = MAD4B_SCP_Audit::record( 'mad4b/staging-write-candidate-binding-complete', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $plan['agent_public_id'],
				'source_commit_sha' => $current_sha,
				'build_fingerprint' => $current_build,
				'package_manifest_digest' => $current_manifest,
				'artifact_identity' => $current_artifact,
				'write_tool_count' => (int) $after_plan['write_tool_count'],
				'write_inventory_fingerprint' => (string) $after_plan['write_inventory_fingerprint'],
				'grant_rows_fingerprint' => (string) $after_plan['grant_rows_fingerprint'],
				'candidate_binding_match' => true,
				'authority_effective' => true,
				'grant_mutation_performed' => false,
				'subject_mutation_performed' => false,
				'agent_mutation_performed' => false,
				'reconcile_called' => false,
				'production_mutation' => false,
			), 'ok' );
			if ( is_wp_error( $completion ) ) {
				$rolled_back = self::rollback_binding_option( $before_option );
				return new WP_Error(
					'mad4b_candidate_bind_completion_audit_failed',
					'Candidate binding completion audit failed; the previous persisted authority binding was restored when possible.',
					array( 'rollback_restored' => $rolled_back )
				);
			}

			return array(
				'contract' => self::CONTRACT,
				'state' => 'bound',
				'idempotent' => false,
				'mutation_performed' => true,
				'grant_mutation_performed' => false,
				'subject_mutation_performed' => false,
				'agent_mutation_performed' => false,
				'reconcile_called' => false,
				'production_mutation' => false,
				'effective' => true,
				'candidate_binding' => $after_binding,
				'write_tool_count' => (int) $after_plan['write_tool_count'],
				'exact_grants_existing' => (int) $after_plan['exact_grants_existing'],
				'write_inventory_fingerprint' => (string) $after_plan['write_inventory_fingerprint'],
				'grant_rows_fingerprint' => (string) $after_plan['grant_rows_fingerprint'],
			);
		} finally {
			self::$running = false;
		}
	}
}
