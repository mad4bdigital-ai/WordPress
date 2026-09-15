<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Staging-only administrative bootstrap for Site Profile Phase A. */
final class MAD4B_SCP_Site_Profile_Enrollment {
	const CONTRACT = 'mad4b.site-profile-feature-reenrollment.v1';
	const ABILITY = 'mad4b/site-profile-feature-reenroll';
	const SERVER_ID = 'mad4b-enrollment';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		if ( ! function_exists( 'add_filter' ) || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'filter_registration_args' ), 45, 2 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 9 );
	}

	public static function filter_registration_args( $args, $name ) {
		if ( ! is_array( $args ) || 'mad4b/site-profile-status' !== (string) $name ) return $args;
		$args['execute_callback'] = array( __CLASS__, 'status_readonly' );
		$args['description'] = 'Read exact Site Profile binding and bounded feature state without exposing App IDs, OAuth subjects, nonces or credentials.';
		return $args;
	}

	public static function status_readonly() {
		$status = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		if ( ! is_array( $status ) ) $status = array();
		$origin_enrolled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled();
		$urls_match = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
		$status['profile_origin_enrolled'] = $origin_enrolled;
		$status['profile_site_urls_match'] = $urls_match;
		$status['exact_profile_bound'] = $origin_enrolled && $urls_match;
		$status['oauth_enabled'] = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::oauth_enabled();
		$status['acceptance_enabled'] = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::acceptance_enabled();
		$status['skills_enabled'] = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::skills_enabled();
		$status['write_enabled'] = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::write_enabled();
		$status['provider_isolation_enabled'] = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::provider_isolation_enabled();
		$status['managed_runtime_enabled'] = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::managed_runtime_enabled();
		$status['chatgpt_app_id_configured'] = class_exists( 'MAD4B_SCP_Site_Profile' ) && '' !== MAD4B_SCP_Site_Profile::chatgpt_app_id();
		$status['oauth_user_count'] = class_exists( 'MAD4B_SCP_Site_Profile' ) ? count( MAD4B_SCP_Site_Profile::oauth_user_ids() ) : 0;
		return $status;
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
		$augment = array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' );
		$priority = function_exists( 'has_filter' ) ? has_filter( 'wp_register_ability_args', $augment ) : false;
		if ( false !== $priority ) remove_filter( 'wp_register_ability_args', $augment, (int) $priority );
		try {
			wp_register_ability( self::ABILITY, array(
				'label' => 'Re-enroll Site Profile Phase A Features',
				'description' => 'Enable Acceptance and Skills on one exact Staging Site Profile while governed write remains disabled.',
				'category' => 'mad4b-governance',
				'execute_callback' => array( __CLASS__, 'reenroll_features' ),
				'permission_callback' => array( __CLASS__, 'can_execute' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
						'expected_profile_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
						'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'acceptance_enabled' => array( 'type' => 'boolean' ),
						'skills_enabled' => array( 'type' => 'boolean' ),
					),
					'required' => array( 'expected_revision', 'expected_profile_digest', 'expected_source_commit_sha', 'expected_build_fingerprint', 'acceptance_enabled', 'skills_enabled' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'enrollment', 'mad4b_enrollment_authority' => self::CONTRACT ),
					'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
				),
			) );
		} finally {
			if ( false !== $priority ) add_filter( 'wp_register_ability_args', $augment, (int) $priority, 2 );
		}
	}

	public static function can_access_transport( $input = null ) { return self::can_execute( $input ); }

	public static function can_execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_profile_missing', 'An enrolled Site Profile is required.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_subject_not_enrolled', 'The authenticated WordPress administrator is not enrolled in this Site Profile.' );
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_staging_only', 'Remote feature re-enrollment is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_profile_not_exact', 'The current origin, environment, home URL and site URL must exactly match the enrolled Site Profile.' );
		if ( 'https' !== strtolower( (string) wp_parse_url( MAD4B_SCP_Site_Profile::current_origin(), PHP_URL_SCHEME ) ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_https_required', 'Remote Site Profile re-enrollment requires HTTPS.' );
		return true;
	}

	public static function reenroll_features( $input ) {
		$permission = self::can_execute( $input );
		if ( is_wp_error( $permission ) || ! $permission ) return $permission;
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_input_invalid', 'Input must be an object.' );
		if ( array_key_exists( 'write_enabled', $input ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_write_input_denied', 'Governed write cannot be requested through the Phase A enrollment surface.' );
		if ( ! isset( $input['expected_revision'], $input['expected_profile_digest'], $input['expected_source_commit_sha'], $input['expected_build_fingerprint'] ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_binding_required', 'Exact revision, profile digest and build binding are required.' );
		if ( ! array_key_exists( 'acceptance_enabled', $input ) || true !== $input['acceptance_enabled'] || ! array_key_exists( 'skills_enabled', $input ) || true !== $input['skills_enabled'] ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_phase_a_only', 'Phase A requires enabling Acceptance and Skills together.' );

		$current_revision = MAD4B_SCP_Site_Profile::revision();
		$expected_revision = absint( $input['expected_revision'] );
		if ( $expected_revision < 1 || $current_revision !== $expected_revision ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_revision_stale', 'Site Profile revision changed before re-enrollment.' );
		$current_digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
		$expected_digest = strtolower( trim( (string) $input['expected_profile_digest'] ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_digest ) || ! hash_equals( $current_digest, $expected_digest ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_digest_stale', 'Site Profile digest changed before re-enrollment.' );

		$expected_sha = strtolower( trim( (string) $input['expected_source_commit_sha'] ) );
		$expected_fingerprint = strtolower( trim( (string) $input['expected_build_fingerprint'] ) );
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || ! preg_match( '/^[a-f0-9]{64}$/', $expected_fingerprint ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_candidate_invalid', 'Expected build identity is invalid.' );
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_provenance_unavailable', 'Build provenance authority is unavailable.' );
		$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) || ! empty( $provenance['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_provenance_not_ready', 'Exact current build provenance is not ready.' );
		$current_sha = isset( $provenance['source_commit_sha'] ) ? strtolower( (string) $provenance['source_commit_sha'] ) : '';
		$current_fingerprint = isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
		if ( ! hash_equals( $expected_sha, $current_sha ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_candidate_mismatch', 'Source commit does not match the exact requested candidate.' );
		if ( ! hash_equals( $expected_fingerprint, $current_fingerprint ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_fingerprint_mismatch', 'Build fingerprint does not match the exact requested candidate.' );

		if ( '' === MAD4B_SCP_Site_Profile::chatgpt_app_id() ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_app_mapping_missing', 'A valid stored ChatGPT App ID is required before Skills can be enabled.' );
		if ( MAD4B_SCP_Site_Profile::acceptance_enabled() || MAD4B_SCP_Site_Profile::skills_enabled() ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_unexpected_feature_state', 'Phase A requires Acceptance and Skills to be disabled before the exact transition.' );
		if ( MAD4B_SCP_Site_Profile::write_enabled() ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_write_must_remain_disabled', 'Governed write must remain disabled during Phase A.' );

		$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_audit_required', 'Ready append-only audit storage is required.' );
		$before = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
		if ( ! is_array( $before ) || ! isset( $before['features'] ) || ! is_array( $before['features'] ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_profile_invalid', 'Stored Site Profile is invalid.' );
		if ( ! empty( $before['features']['write'] ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_write_must_remain_disabled', 'Stored governed write authority must remain disabled during Phase A.' );

		$next = $before;
		$next['revision'] = $current_revision + 1;
		$next['features']['acceptance'] = true;
		$next['features']['skills'] = true;
		$next['updated_at'] = gmdate( 'c' );
		if ( false === update_option( MAD4B_SCP_Site_Profile::OPTION, $next, false ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_save_failed', 'Site Profile feature re-enrollment could not be persisted.' );
		MAD4B_SCP_Site_Profile::reset_cache();

		$after = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
		$status = MAD4B_SCP_Site_Profile::status();
		$post_ok = is_array( $after )
			&& isset( $after['revision'] ) && (int) $after['revision'] === $current_revision + 1
			&& ! empty( $status['configured'] ) && MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
			&& MAD4B_SCP_Site_Profile::acceptance_enabled() && MAD4B_SCP_Site_Profile::skills_enabled() && ! MAD4B_SCP_Site_Profile::write_enabled()
			&& isset( $before['site_uuid'], $after['site_uuid'] ) && hash_equals( (string) $before['site_uuid'], (string) $after['site_uuid'] )
			&& isset( $before['canonical_origin'], $after['canonical_origin'] ) && hash_equals( (string) $before['canonical_origin'], (string) $after['canonical_origin'] );
		$expected_after = $before;
		$expected_after['revision'] = $current_revision + 1;
		$expected_after['features']['acceptance'] = true;
		$expected_after['features']['skills'] = true;
		unset( $expected_after['updated_at'], $after['updated_at'] );
		$post_ok = $post_ok && $expected_after === $after;
		if ( ! $post_ok ) {
			if ( ! self::restore_profile( $before ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_rollback_failed', 'Postcondition failed and the previous Site Profile could not be restored.' );
			return new WP_Error( 'mad4b_site_profile_feature_reenroll_postcondition_failed', 'Phase A postconditions failed; the previous Site Profile was restored.' );
		}

		$after_digest = MAD4B_SCP_Site_Profile::profile_digest();
		$audit = MAD4B_SCP_Audit::record( 'mad4b/site-profile-feature-reenrolled', array(
			'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
			'previous_revision' => $current_revision,
			'revision' => MAD4B_SCP_Site_Profile::revision(),
			'previous_profile_digest' => $current_digest,
			'profile_digest' => $after_digest,
			'environment' => MAD4B_SCP_Site_Profile::current_environment(),
			'canonical_origin' => MAD4B_SCP_Site_Profile::site_origin(),
			'acceptance_enabled' => true,
			'skills_enabled' => true,
			'write_enabled' => false,
			'source_commit_sha' => $current_sha,
			'build_fingerprint' => $current_fingerprint,
		), 'ok' );
		if ( is_wp_error( $audit ) ) {
			if ( ! self::restore_profile( $before ) ) return new WP_Error( 'mad4b_site_profile_feature_reenroll_rollback_failed', 'Audit commit failed and the previous Site Profile could not be restored.' );
			return new WP_Error( 'mad4b_site_profile_feature_reenroll_audit_failed', 'Phase A was rolled back because append-only audit evidence could not be committed.', array( 'audit_error' => $audit->get_error_code() ) );
		}

		return array(
			'contract' => self::CONTRACT,
			'state' => 'reenrolled',
			'previous_revision' => $current_revision,
			'revision' => MAD4B_SCP_Site_Profile::revision(),
			'previous_profile_digest' => $current_digest,
			'profile_digest' => $after_digest,
			'exact_profile_bound' => MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),
			'acceptance_enabled' => true,
			'skills_enabled' => true,
			'write_enabled' => false,
			'source_commit_sha' => $current_sha,
			'build_fingerprint' => $current_fingerprint,
		);
	}

	private static function restore_profile( array $before ) {
		update_option( MAD4B_SCP_Site_Profile::OPTION, $before, false );
		MAD4B_SCP_Site_Profile::reset_cache();
		$restored = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
		return is_array( $restored ) && $before === $restored;
	}
}
