<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact Staging-only transition that enables governed write on an already
 * enrolled Site Profile without exposing the generic Site Profile save path.
 *
 * The initial bootstrap transition deliberately stops after the Site Profile
 * change. If write is already enabled but the canonical profile-owned authority
 * is blocked only by a legacy OAuth subject binding, the same exact-bound
 * bootstrap surface may perform one narrow atomic subject handoff after proving
 * the target authority already has the complete current exact grant set.
 */
final class MAD4B_SCP_Site_Profile_Write_Enablement {
	const CONTRACT = 'mad4b.site-profile-write-enablement.v1';
	const ABILITY = 'mad4b/site-profile-write-enable';
	const CONFIRMATION = 'ENABLE GOVERNED STAGING WRITE';
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

		$augment = array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' );
		$priority = function_exists( 'has_filter' ) ? has_filter( 'wp_register_ability_args', $augment ) : false;
		if ( false !== $priority ) remove_filter( 'wp_register_ability_args', $augment, (int) $priority );
		try {
			wp_register_ability( self::ABILITY, array(
				'label' => 'Enable Governed Write for Exact Site Profile',
				'description' => 'Enable governed write on one exact Staging Site Profile after App Mapping, Acceptance and Skills are enabled. If Write is already enabled, repair only an exact legacy OAuth subject binding into the canonical profile-owned authority when all current exact grants are already ready.',
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
						'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) ),
					),
					'required' => array( 'expected_revision', 'expected_profile_digest', 'expected_source_commit_sha', 'expected_build_fingerprint', 'confirmation' ),
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
		if ( ! isset( $input['expected_revision'], $input['expected_profile_digest'], $input['expected_source_commit_sha'], $input['expected_build_fingerprint'], $input['confirmation'] ) ) return new WP_Error( 'mad4b_site_profile_write_enable_binding_required', 'Exact revision, profile digest, build binding and literal confirmation are required.' );
		if ( self::CONFIRMATION !== (string) $input['confirmation'] ) return new WP_Error( 'mad4b_site_profile_write_enable_confirmation_required', 'Exact confirmation "ENABLE GOVERNED STAGING WRITE" is required.' );

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
		if ( MAD4B_SCP_Site_Profile::write_enabled() ) return self::repair_subject_handoff( $current_revision, $current_digest, $current_sha, $current_fingerprint );

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
		if ( ! MAD4B_SCP_Site_Profile::persist_record_exact( $next ) ) return new WP_Error( 'mad4b_site_profile_write_enable_save_failed', 'Governed write enablement could not be persisted and verified by readback.' );

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

	private static function repair_subject_handoff( $current_revision, $current_digest, $current_sha, $current_fingerprint ) {
		global $wpdb;
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) return new WP_Error( 'mad4b_site_profile_write_repair_schema_unavailable', 'Governance schema must be ready before authority repair.' );
		if ( ! class_exists( 'MAD4B_SCP_Agent_Registry' ) || ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! class_exists( 'MAD4B_SCP_Servers' ) ) return new WP_Error( 'mad4b_site_profile_write_repair_authority_unavailable', 'Governed write authority components are unavailable.' );
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ) return new WP_Error( 'mad4b_site_profile_write_repair_issuer_unavailable', 'Local OAuth issuer is unavailable.' );
		$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_site_profile_write_repair_audit_required', 'Ready append-only audit storage is required.' );

		$user_id = get_current_user_id();
		$environment = MAD4B_SCP_Site_Profile::current_environment();
		$canonical_slug = sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() );
		if ( 'staging' !== $environment || '' === $canonical_slug ) return new WP_Error( 'mad4b_site_profile_write_repair_scope_invalid', 'Authority repair is limited to the exact governed Staging profile.' );

		$t = MAD4B_SCP_Schema::tables();
		$target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['agents']} WHERE slug=%s LIMIT 1", $canonical_slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! $target ) return new WP_Error( 'mad4b_site_profile_write_repair_target_missing', 'Canonical profile-owned write agent does not exist.' );
		if ( 'enabled' !== (string) $target['status'] || (int) $target['wp_user_id'] !== $user_id || $environment !== (string) $target['environment'] ) return new WP_Error( 'mad4b_site_profile_write_repair_target_invalid', 'Canonical write agent does not exactly match the enrolled Staging administrator and environment.' );

		$issuer = rtrim( (string) MAD4B_SCP_Local_OAuth_Server::issuer(), '/' );
		if ( '' === $issuer ) return new WP_Error( 'mad4b_site_profile_write_repair_issuer_unavailable', 'Local OAuth issuer is unavailable.' );
		$subject_fingerprint = hash( 'sha256', 'oauth' . "\0" . $issuer . "\0" . 'user:' . absint( $user_id ) );
		$subjects = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['subjects']} WHERE subject_type=%s AND subject_fingerprint=%s ORDER BY id ASC", 'oauth', $subject_fingerprint ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 !== count( $subjects ) ) return new WP_Error( 'mad4b_site_profile_write_repair_subject_cardinality_invalid', 'Exact OAuth subject must have one and only one governance binding.' );
		$subject = reset( $subjects );
		if ( 'enabled' !== (string) $subject['status'] ) return new WP_Error( 'mad4b_site_profile_write_repair_subject_disabled', 'Exact OAuth subject binding must be enabled before repair.' );
		if ( (int) $subject['agent_id'] === (int) $target['id'] ) {
			$reconciled = MAD4B_SCP_Staging_Write_Authority::reconcile();
			if ( empty( $reconciled['ready'] ) ) return new WP_Error( 'mad4b_site_profile_write_repair_reconcile_incomplete', 'Subject is already canonical but write authority is still not ready.', array( 'status' => $reconciled ) );
			return self::handoff_result( $current_revision, $current_digest, $current_sha, $current_fingerprint, $target, $target, $subject_fingerprint, $reconciled, false );
		}

		$source = MAD4B_SCP_Agent_Registry::get_agent_by_id( (int) $subject['agent_id'] );
		if ( ! $source ) return new WP_Error( 'mad4b_site_profile_write_repair_source_missing', 'Current OAuth subject source agent does not exist.' );
		if ( 'enabled' !== (string) $source['status'] || (int) $source['wp_user_id'] !== $user_id || $environment !== (string) $source['environment'] ) return new WP_Error( 'mad4b_site_profile_write_repair_source_invalid', 'Current OAuth subject source agent is not a same-user same-environment legacy authority.' );
		if ( (string) $source['slug'] === $canonical_slug ) return new WP_Error( 'mad4b_site_profile_write_repair_source_ambiguous', 'Source agent collides with the canonical profile-owned authority slug.' );

		$tools = MAD4B_SCP_Staging_Write_Authority::write_tools();
		if ( empty( $tools ) ) return new WP_Error( 'mad4b_site_profile_write_repair_inventory_empty', 'Current governed write inventory is empty.' );
		$target_grants = array();
		foreach ( $tools as $ability ) {
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
			if ( null === $provider ) return new WP_Error( 'mad4b_site_profile_write_repair_unmounted_target', 'A current write ability is not mounted on mad4b-write.', array( 'ability' => $ability ) );
			$grant = MAD4B_SCP_Agent_Registry::exact_grant( (int) $target['id'], 'mad4b-write', $ability, $provider );
			if ( is_wp_error( $grant ) || $environment !== (string) $grant['environment'] ) return new WP_Error( 'mad4b_site_profile_write_repair_target_grants_incomplete', 'Canonical target does not already hold the complete exact current write grant set.', array( 'ability' => $ability, 'provider' => $provider ) );
			$target_grants[ (string) $ability . "\0" . (string) $provider ] = true;
		}

		$source_grants = MAD4B_SCP_Agent_Registry::grants_for_agent( (int) $source['id'], 'mad4b-write' );
		foreach ( $source_grants as $grant ) {
			if ( 'allow' !== (string) $grant['effect'] || ! in_array( (string) $grant['environment'], array( 'all', $environment ), true ) ) continue;
			$ability = (string) $grant['ability_name'];
			$provider = (string) $grant['provider'];
			if ( ! in_array( $ability, $tools, true ) ) continue;
			$mounted_provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
			if ( null === $mounted_provider || $provider !== (string) $mounted_provider ) continue;
			if ( ! isset( $target_grants[ $ability . "\0" . $provider ] ) ) return new WP_Error( 'mad4b_site_profile_write_repair_unique_effective_authority', 'Legacy source retains effective authority not already represented on the canonical target.', array( 'ability' => $ability, 'provider' => $provider ) );
		}

		$started = $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $started ) return new WP_Error( 'mad4b_site_profile_write_repair_transaction_failed', 'Could not start the atomic OAuth subject handoff transaction.' );
		$updated = $wpdb->update( $t['subjects'], array( 'agent_id' => (int) $target['id'], 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $subject['id'], 'agent_id' => (int) $source['id'], 'status' => 'enabled' ), array( '%d', '%s' ), array( '%d', '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new WP_Error( 'mad4b_site_profile_write_repair_subject_move_failed', 'Exact OAuth subject handoff became stale or could not be persisted.' );
		}

		$reconciled = MAD4B_SCP_Staging_Write_Authority::reconcile();
		$reconcile_ok = is_array( $reconciled ) && ! empty( $reconciled['ready'] ) && isset( $reconciled['agent_public_id'] ) && hash_equals( strtolower( (string) $target['public_id'] ), strtolower( (string) $reconciled['agent_public_id'] ) ) && empty( $reconciled['breakglass_included'] ) && empty( $reconciled['grant_blockers'] );
		if ( ! $reconcile_ok ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			MAD4B_SCP_Staging_Write_Authority::reconcile();
			return new WP_Error( 'mad4b_site_profile_write_repair_reconcile_failed', 'OAuth subject handoff was rolled back because canonical write authority did not reconcile to ready.', array( 'status' => $reconciled ) );
		}

		$audit = MAD4B_SCP_Audit::record( 'mad4b/governed-write-subject-handoff', array(
			'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
			'site_profile_revision' => $current_revision,
			'site_profile_digest' => $current_digest,
			'environment' => $environment,
			'canonical_origin' => MAD4B_SCP_Site_Profile::site_origin(),
			'wp_user_id' => $user_id,
			'subject_type' => 'oauth',
			'subject_fingerprint' => $subject_fingerprint,
			'from_agent_public_id' => (string) $source['public_id'],
			'to_agent_public_id' => (string) $target['public_id'],
			'to_agent_slug' => (string) $target['slug'],
			'write_tool_count' => count( $tools ),
			'source_commit_sha' => $current_sha,
			'build_fingerprint' => $current_fingerprint,
			'legacy_agent_disabled' => false,
			'legacy_grants_mutated' => false,
			'atomic_subject_move' => true,
		), 'ok' );
		if ( is_wp_error( $audit ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			MAD4B_SCP_Staging_Write_Authority::reconcile();
			return new WP_Error( 'mad4b_site_profile_write_repair_audit_failed', 'OAuth subject handoff was rolled back because append-only audit evidence could not be committed.', array( 'audit_error' => $audit->get_error_code() ) );
		}

		$committed = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $committed ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			MAD4B_SCP_Staging_Write_Authority::reconcile();
			return new WP_Error( 'mad4b_site_profile_write_repair_commit_failed', 'Atomic OAuth subject handoff could not be committed.' );
		}

		return self::handoff_result( $current_revision, $current_digest, $current_sha, $current_fingerprint, $source, $target, $subject_fingerprint, $reconciled, true );
	}

	private static function handoff_result( $current_revision, $current_digest, $current_sha, $current_fingerprint, array $source, array $target, $subject_fingerprint, array $reconciled, $moved ) {
		return array(
			'contract' => self::CONTRACT,
			'state' => $moved ? 'authority_reconciled' : 'authority_already_reconciled',
			'revision' => (int) $current_revision,
			'profile_digest' => (string) $current_digest,
			'source_commit_sha' => (string) $current_sha,
			'build_fingerprint' => (string) $current_fingerprint,
			'subject_type' => 'oauth',
			'subject_fingerprint' => (string) $subject_fingerprint,
			'from_agent_public_id' => (string) $source['public_id'],
			'to_agent_public_id' => (string) $target['public_id'],
			'atomic_subject_move' => (bool) $moved,
			'legacy_agent_disabled' => false,
			'legacy_grants_mutated' => false,
			'write_authority_ready' => ! empty( $reconciled['ready'] ),
			'runtime_reconciled' => ! empty( $reconciled['ready'] ),
			'grant_blockers' => isset( $reconciled['grant_blockers'] ) ? $reconciled['grant_blockers'] : array(),
			'breakglass_included' => ! empty( $reconciled['breakglass_included'] ),
		);
	}

	private static function restore_profile( array $profile ) {
		return MAD4B_SCP_Site_Profile::persist_record_exact( $profile );
	}
}
