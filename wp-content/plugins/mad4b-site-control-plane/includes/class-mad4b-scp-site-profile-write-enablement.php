<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact Staging-only transition that enables governed write on an already
 * enrolled Site Profile without exposing the generic Site Profile save path.
 *
 * This bootstrap transition deliberately stops after the Site Profile change.
 * NHI/subject/grant reconciliation and mutation-gate activation happen on a
 * subsequent governed runtime bootstrap/request, never inside this invocation.
 */
final class MAD4B_SCP_Site_Profile_Write_Enablement {
	const CONTRACT = 'mad4b.site-profile-write-enablement.v1';
	const ABILITY = 'mad4b/site-profile-write-enable';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		if ( ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 10 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;

		// This is a bounded bootstrap transition, not a normal governed business
		// mutation. Do not let the normal write-authority augmenter inject a prior
		// approval-ticket input before the NHI/write authority exists.
		$augment = array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' );
		$priority = function_exists( 'has_filter' ) ? has_filter( 'wp_register_ability_args', $augment ) : false;
		if ( false !== $priority ) remove_filter( 'wp_register_ability_args', $augment, (int) $priority );
		try {
			wp_register_ability( self::ABILITY, array(
				'label' => 'Enable Governed Write for Exact Site Profile',
				'description' => 'Enable governed write on one exact Staging Site Profile after App Mapping, Acceptance and Skills are already enabled. Authority reconciliation is deferred to a later request.',
				'category' => 'mad4b-governance',
				'execute_callback' => array( __CLASS__, 'enable_write' ),
				'permission_callback' => array( __CLASS__, 'can_execute' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
						'expected_profile_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
						'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'write_enabled' => array( 'type' => 'boolean' ),
					),
					'required' => array( 'expected_revision', 'expected_profile_digest', 'expected_source_commit_sha', 'expected_build_fingerprint', 'write_enabled' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'enrollment', 'mad4b_write_enablement_authority' => self::CONTRACT ),
					'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
				),
			) );
		} finally {
			if ( false !== $priority ) add_filter( 'wp_register_ability_args', $augment, (int) $priority, 2 );
		}
	}

	public static function can_execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_site_profile_write_enable_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_site_profile_write_enable_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_site_profile_write_enable_profile_missing', 'An enrolled Site Profile is required.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_site_profile_write_enable_subject_not_enrolled', 'The authenticated WordPress administrator is not enrolled in this Site Profile.' );
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_site_profile_write_enable_staging_only', 'Remote governed write enablement is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_site_profile_write_enable_profile_not_exact', 'The current origin, environment, home URL and site URL must exactly match the enrolled Site Profile.' );
		if ( 'https' !== strtolower( (string) wp_parse_url( MAD4B_SCP_Site_Profile::current_origin(), PHP_URL_SCHEME ) ) ) return new WP_Error( 'mad4b_site_profile_write_enable_https_required', 'Remote governed write enablement requires HTTPS.' );
		return true;
	}

	public static function enable_write( $input ) {
		$permission = self::can_execute( $input );
		if ( is_wp_error( $permission ) || ! $permission ) return $permission;
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_site_profile_write_enable_input_invalid', 'Input must be an object.' );
		if ( ! isset( $input['expected_revision'], $input['expected_profile_digest'], $input['expected_source_commit_sha'], $input['expected_build_fingerprint'] ) || ! array_key_exists( 'write_enabled', $input ) ) return new WP_Error( 'mad4b_site_profile_write_enable_binding_required', 'Exact revision, profile digest, build binding and explicit write enablement are required.' );
		if ( true !== $input['write_enabled'] ) return new WP_Error( 'mad4b_site_profile_write_enable_true_required', 'This bounded transition only accepts write_enabled=true.' );

		$current_revision = MAD4B_SCP_Site_Profile::revision();
		$expected_revision = absint( $input['expected_revision'] );
		if ( $expected_revision < 1 || $current_revision !== $expected_revision ) return new WP_Error( 'mad4b_site_profile_write_enable_revision_stale', 'Site Profile revision changed before governed write enablement.' );
		$current_digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
		$expected_digest = strtolower( trim( (string) $input['expected_profile_digest'] ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_digest ) || ! hash_equals( $current_digest, $expected_digest ) ) return new WP_Error( 'mad4b_site_profile_write_enable_digest_stale', 'Site Profile digest changed before governed write enablement.' );

		$expected_sha = strtolower( trim( (string) $input['expected_source_commit_sha'] ) );
		$expected_fingerprint = strtolower( trim( (string) $input['expected_build_fingerprint'] ) );
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || ! preg_match( '/^[a-f0-9]{64}$/', $expected_fingerprint ) ) return new WP_Error( 'mad4b_site_profile_write_enable_candidate_invalid', 'Expected build identity is invalid.' );
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_site_profile_write_enable_provenance_unavailable', 'Build provenance authority is unavailable.' );
		$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) || ! empty( $provenance['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_site_profile_write_enable_provenance_not_ready', 'Exact current build provenance is not ready.' );
		$current_sha = isset( $provenance['source_commit_sha'] ) ? strtolower( (string) $provenance['source_commit_sha'] ) : '';
		$current_fingerprint = isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
		if ( ! hash_equals( $expected_sha, $current_sha ) ) return new WP_Error( 'mad4b_site_profile_write_enable_candidate_mismatch', 'Source commit does not match the exact requested candidate.' );
		if ( ! hash_equals( $expected_fingerprint, $current_fingerprint ) ) return new WP_Error( 'mad4b_site_profile_write_enable_fingerprint_mismatch', 'Build fingerprint does not match the exact requested candidate.' );

		if ( '' === MAD4B_SCP_Site_Profile::chatgpt_app_id() ) return new WP_Error( 'mad4b_site_profile_write_enable_app_mapping_missing', 'A valid stored ChatGPT App ID is required before governed write can be enabled.' );
		if ( ! MAD4B_SCP_Site_Profile::oauth_enabled() ) return new WP_Error( 'mad4b_site_profile_write_enable_oauth_required', 'OAuth must be enabled before governed write can be enabled.' );
		if ( ! MAD4B_SCP_Site_Profile::acceptance_enabled() || ! MAD4B_SCP_Site_Profile::skills_enabled() ) return new WP_Error( 'mad4b_site_profile_write_enable_phase_a_required', 'Acceptance and Skills must already be enabled before governed write can be enabled.' );
		if ( MAD4B_SCP_Site_Profile::write_enabled() ) return new WP_Error( 'mad4b_site_profile_write_enable_already_enabled', 'Governed write is already enabled.' );

		$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_site_profile_write_enable_audit_required', 'Ready append-only audit storage is required.' );
		$before = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
		if ( ! is_array( $before ) || ! isset( $before['features'] ) || ! is_array( $before['features'] ) ) return new WP_Error( 'mad4b_site_profile_write_enable_profile_invalid', 'Stored Site Profile is invalid.' );
		if ( empty( $before['features']['oauth'] ) || empty( $before['features']['acceptance'] ) || empty( $before['features']['skills'] ) || ! empty( $before['features']['write'] ) ) return new WP_Error( 'mad4b_site_profile_write_enable_feature_state_invalid', 'Stored OAuth, Acceptance and Skills must be enabled while Write remains disabled before this transition.' );

		$next = $before;
		$next['revision'] = $current_revision + 1;
		$next['features']['write'] = true;
		$next['features']['production_write_confirmed'] = false;
		$next['updated_at'] = gmdate( 'c' );
		if ( false === update_option( MAD4B_SCP_Site_Profile::OPTION, $next, false ) ) return new WP_Error( 'mad4b_site_profile_write_enable_save_failed', 'Governed write enablement could not be persisted.' );
		MAD4B_SCP_Site_Profile::reset_cache();

		$after = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
		$status = MAD4B_SCP_Site_Profile::status();
		$post_ok = is_array( $after )
			&& isset( $after['revision'] ) && (int) $after['revision'] === $current_revision + 1
			&& ! empty( $status['configured'] ) && MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
			&& MAD4B_SCP_Site_Profile::oauth_enabled() && MAD4B_SCP_Site_Profile::acceptance_enabled() && MAD4B_SCP_Site_Profile::skills_enabled() && MAD4B_SCP_Site_Profile::write_enabled()
			&& empty( $after['features']['production_write_confirmed'] );
		$expected_after = $before;
		$expected_after['revision'] = $current_revision + 1;
		$expected_after['features']['write'] = true;
		$expected_after['features']['production_write_confirmed'] = false;
		unset( $expected_after['updated_at'], $after['updated_at'] );
		$post_ok = $post_ok && $expected_after === $after;
		if ( ! $post_ok ) {
			if ( ! self::restore_profile( $before ) ) return new WP_Error( 'mad4b_site_profile_write_enable_rollback_failed', 'Postcondition failed and the previous Site Profile could not be restored.' );
			return new WP_Error( 'mad4b_site_profile_write_enable_postcondition_failed', 'Governed write enablement postconditions failed; the previous Site Profile was restored.' );
		}

		$after_digest = MAD4B_SCP_Site_Profile::profile_digest();
		$audit = MAD4B_SCP_Audit::record( 'mad4b/site-profile-write-enabled', array(
			'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
			'previous_revision' => $current_revision,
			'revision' => MAD4B_SCP_Site_Profile::revision(),
			'previous_profile_digest' => $current_digest,
			'profile_digest' => $after_digest,
			'environment' => MAD4B_SCP_Site_Profile::current_environment(),
			'canonical_origin' => MAD4B_SCP_Site_Profile::site_origin(),
			'oauth_enabled' => true,
			'acceptance_enabled' => true,
			'skills_enabled' => true,
			'write_enabled' => true,
			'production_write_confirmed' => false,
			'source_commit_sha' => $current_sha,
			'build_fingerprint' => $current_fingerprint,
			'authority_reconciliation_deferred' => true,
		), 'ok' );
		if ( is_wp_error( $audit ) ) {
			if ( ! self::restore_profile( $before ) ) return new WP_Error( 'mad4b_site_profile_write_enable_rollback_failed', 'Audit commit failed and the previous Site Profile could not be restored.' );
			return new WP_Error( 'mad4b_site_profile_write_enable_audit_failed', 'Governed write enablement was rolled back because append-only audit evidence could not be committed.', array( 'audit_error' => $audit->get_error_code() ) );
		}

		return array(
			'contract' => self::CONTRACT,
			'state' => 'write_enabled',
			'previous_revision' => $current_revision,
			'revision' => MAD4B_SCP_Site_Profile::revision(),
			'previous_profile_digest' => $current_digest,
			'profile_digest' => $after_digest,
			'exact_profile_bound' => MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),
			'oauth_enabled' => true,
			'acceptance_enabled' => true,
			'skills_enabled' => true,
			'write_enabled' => true,
			'production_write_confirmed' => false,
			'source_commit_sha' => $current_sha,
			'build_fingerprint' => $current_fingerprint,
			'authority_reconciliation_deferred' => true,
			'same_invocation_nhi_created' => false,
			'same_invocation_grants_created' => 0,
			'same_invocation_mutation_gate_enabled' => false,
		);
	}

	private static function restore_profile( array $profile ) {
		$restored = update_option( MAD4B_SCP_Site_Profile::OPTION, $profile, false );
		MAD4B_SCP_Site_Profile::reset_cache();
		if ( false === $restored ) {
			$current = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
			return is_array( $current ) && $current === $profile;
		}
		return true;
	}
}
