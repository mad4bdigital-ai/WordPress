<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * One-time post-update continuation authority.
 *
 * This is not a grant reconciler and cannot create authority. It can only carry
 * a previously reviewed, exact Staging write snapshot across a verified package
 * replacement and authorize one candidate-binding-only mutation when authority
 * delta remains exactly zero.
 */
final class MAD4B_SCP_Post_Update_Continuation {
	const BASELINE_OPTION = 'mad4b_scp_staging_authority_baseline_v1';
	const BASELINE_CONTRACT = 'mad4b.staging-authority-baseline.v1';
	const BASELINE_TTL = 604800;
	const CONTRACT = 'mad4b.post-update-continuation.v1';
	const OPTION = 'mad4b_scp_post_update_continuation_v1';
	const TTL = 900;
	const OPERATOR_WITNESS_OPTION = 'mad4b_scp_operator_witnessed_replacement_v1';
	const OPERATOR_WITNESS_CONTRACT = 'mad4b.operator-witnessed-replacement.v1';
	const OPERATOR_WITNESS_TTL = 1800;
	const CLASS_ZERO = 'ZERO_DELTA_CONTINUATION';
	const CLASS_REVIEW = 'REVIEW_REQUIRED_DELTA';
	const CLASS_HARD = 'HARD_BLOCK_DELTA';
	private static $executing_context_digest = null;

	private static function clean_fields() {
		return array(
			'exact_grants_missing_count',
			'stale_allow_grants_count',
			'unreviewed_stale_allow_grants_count',
			'broad_environment_grants_count',
			'duplicate_exact_allow_grants_count',
			'current_agent_wildcard_grants',
			'global_registry_wildcard_grants',
		);
	}

	public static function status() {
		$permit = self::read_permit();
		if ( ! is_array( $permit ) || self::CONTRACT !== ( isset( $permit['contract'] ) ? (string) $permit['contract'] : '' ) ) {
			return array(
				'contract' => self::CONTRACT,
				'state' => 'absent',
				'active' => false,
				'consumed' => false,
				'mutation_performed' => false,
			);
		}
		$permit['active'] = self::permit_active( $permit );
		$permit['expired'] = isset( $permit['expires_at'] ) && absint( $permit['expires_at'] ) < time();
		$permit['mutation_performed'] = false;
		return $permit;
	}


	private static function operator_witness_seal( array $witness ) {
		unset( $witness['seal'] );
		return hash_hmac( 'sha256', self::digest( $witness ), wp_salt( 'auth' ) );
	}

	private static function operator_witness_valid( $witness ) {
		if ( ! is_array( $witness ) || self::OPERATOR_WITNESS_CONTRACT !== ( isset( $witness['contract'] ) ? (string) $witness['contract'] : '' ) ) return false;
		if ( empty( $witness['seal'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $witness['seal'] ) ) return false;
		if ( ! hash_equals( self::operator_witness_seal( $witness ), (string) $witness['seal'] ) ) return false;
		$created = isset( $witness['created_at'] ) ? (int) $witness['created_at'] : 0;
		$expires = isset( $witness['expires_at'] ) ? (int) $witness['expires_at'] : 0;
		if ( $created < 1 || $expires <= time() || $expires <= $created || $expires - $created > self::OPERATOR_WITNESS_TTL ) return false;
		if ( empty( $witness['witness_id'] ) || empty( $witness['target_identity'] ) || ! is_array( $witness['target_identity'] ) ) return false;
		if ( empty( $witness['previous_binding'] ) || ! is_array( $witness['previous_binding'] ) ) return false;
		if ( empty( $witness['baseline_digest'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $witness['baseline_digest'] ) ) return false;
		if ( empty( $witness['actor_wp_user_id'] ) || empty( $witness['site_uuid'] ) || empty( $witness['profile_digest'] ) ) return false;
		if ( empty( $witness['zero_delta_required'] ) || ! empty( $witness['production_allowed'] ) || ! empty( $witness['breakglass_allowed'] ) || ! empty( $witness['grant_mutation_allowed'] ) ) return false;
		$target = self::target_identity( $witness['target_identity'] );
		if ( is_wp_error( $target ) ) return false;
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $field ) {
			if ( empty( $witness['previous_binding'][ $field ] ) ) return false;
		}
		return true;
	}

	public static function operator_witness_status() {
		$witness = get_option( self::OPERATOR_WITNESS_OPTION, array() );
		$valid = self::operator_witness_valid( $witness );
		if ( ! is_array( $witness ) ) $witness = array();
		return array(
			'contract' => self::OPERATOR_WITNESS_CONTRACT,
			'state' => $valid ? 'available' : ( empty( $witness ) ? 'absent' : 'invalid_or_expired' ),
			'available' => $valid,
			'witness_id' => $valid && isset( $witness['witness_id'] ) ? (string) $witness['witness_id'] : '',
			'source' => $valid && isset( $witness['source'] ) ? sanitize_key( (string) $witness['source'] ) : '',
			'target_identity' => $valid && isset( $witness['target_identity'] ) ? $witness['target_identity'] : array(),
			'expires_at' => $valid && isset( $witness['expires_at'] ) ? (int) $witness['expires_at'] : 0,
			'zero_delta_required' => true,
			'mutation_performed' => false,
			'authorizing' => false,
			'production_mutation' => false,
		);
	}

	/**
	 * Record a Staging-only operator witness after WordPress replaced this plugin.
	 *
	 * The currently executing PHP code is the pre-replacement trusted runtime.
	 * This method does not bind authority and cannot create grants. It only seals
	 * the new physical package identity to the pre-existing healthy authority
	 * baseline and the same enrolled administrator who initiated the replacement.
	 */
	public static function record_operator_witnessed_replacement( $source = 'wordpress_upgrader' ) {
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) : '';
		if ( 'staging' !== $environment ) return new WP_Error( 'mad4b_operator_witness_staging_only', 'Operator-witnessed replacement is Staging-only.' );
		if ( self::breakglass_enabled() ) return new WP_Error( 'mad4b_operator_witness_breakglass_excluded', 'Operator-witnessed replacement is disabled while Breakglass is enabled.' );

		$baseline = get_option( self::BASELINE_OPTION, array() );
		if ( ! self::baseline_valid( $baseline ) ) return new WP_Error( 'mad4b_operator_witness_baseline_required', 'A current sealed healthy authority baseline is required before a manual replacement can be witnessed.' );
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$baseline_user = isset( $baseline['actor']['wp_user_id'] ) ? (int) $baseline['actor']['wp_user_id'] : 0;
		$user = $user_id > 0 && function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
		if ( $user_id < 1 || $baseline_user < 1 || $user_id !== $baseline_user || ! $user
			|| ! user_can( $user, 'manage_options' ) || ! user_can( $user, 'update_plugins' )
			|| ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) {
			return new WP_Error( 'mad4b_operator_witness_actor_mismatch', 'The manual replacement must be performed by the same enrolled administrator captured by the healthy baseline.' );
		}

		$current_site_uuid = strtolower( (string) MAD4B_SCP_Site_Profile::site_uuid() );
		$current_origin = untrailingslashit( (string) MAD4B_SCP_Site_Profile::current_origin() );
		$current_profile_digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
		$current_profile_revision = (int) MAD4B_SCP_Site_Profile::revision();
		if ( ! hash_equals( (string) ( $baseline['site']['site_uuid'] ?? '' ), $current_site_uuid )
			|| ! hash_equals( (string) ( $baseline['site']['origin'] ?? '' ), $current_origin )
			|| ! hash_equals( (string) ( $baseline['site']['profile_digest'] ?? '' ), $current_profile_digest )
			|| (int) ( $baseline['site']['profile_revision'] ?? -1 ) !== $current_profile_revision ) {
			return new WP_Error( 'mad4b_operator_witness_site_profile_drift', 'Site Profile changed before the replacement witness could be sealed.' );
		}

		$physical = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
		$mismatch = isset( $physical['provenance_mismatch'] ) && is_array( $physical['provenance_mismatch'] )
			? array_values( array_unique( array_map( 'sanitize_key', $physical['provenance_mismatch'] ) ) )
			: array();
		$restart_version_boundary_only = 1 === count( $mismatch ) && 'control_plane_version_mismatch' === $mismatch[0];
		if ( empty( $physical['manifest_valid'] ) || ( empty( $physical['runtime_manifest_match'] ) && ! $restart_version_boundary_only ) ) {
			return new WP_Error( 'mad4b_operator_witness_package_integrity_required', 'The replacement package must pass full file and manifest verification before a witness is recorded.' );
		}
		$target = self::target_identity( is_array( $physical ) ? $physical : array() );
		if ( is_wp_error( $target ) ) return $target;

		$binding = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::candidate_binding_status() : array();
		$stored = self::bounded_binding( is_array( $binding ) ? $binding : array() );
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $field ) {
			if ( empty( $baseline['previous_binding'][ $field ] ) || empty( $stored[ $field ] ) || ! hash_equals( (string) $baseline['previous_binding'][ $field ], (string) $stored[ $field ] ) ) {
				return new WP_Error( 'mad4b_operator_witness_previous_binding_changed', 'Stored candidate binding changed since the healthy baseline was captured.' );
			}
		}
		if ( self::identity_matches( $baseline['previous_binding'], $target ) ) {
			return new WP_Error( 'mad4b_operator_witness_no_package_change', 'The installed package still matches the previously bound candidate.' );
		}

		$source = sanitize_key( (string) $source );
		if ( ! in_array( $source, array( 'wordpress_upgrader', 'manual_replacement', 'plugin_upload' ), true ) ) $source = 'wordpress_upgrader';
		$witness = array(
			'contract' => self::OPERATOR_WITNESS_CONTRACT,
			'witness_id' => wp_generate_uuid4(),
			'source' => $source,
			'target_identity' => $target,
			'previous_binding' => $baseline['previous_binding'],
			'baseline_digest' => self::digest( $baseline ),
			'actor_wp_user_id' => $user_id,
			'actor_match_basis' => 'baseline_wp_user_plus_enrollment',
			'site_uuid' => $current_site_uuid,
			'origin' => $current_origin,
			'profile_revision' => $current_profile_revision,
			'profile_digest' => $current_profile_digest,
			'physical_verification_basis' => $restart_version_boundary_only ? 'full_manifest_pre_restart_version_boundary' : 'full_runtime_manifest_match',
			'zero_delta_required' => true,
			'mutation_class' => 'candidate_binding_only',
			'production_allowed' => false,
			'breakglass_allowed' => false,
			'grant_mutation_allowed' => false,
			'authorizing' => false,
			'created_at' => time(),
			'expires_at' => time() + self::OPERATOR_WITNESS_TTL,
		);
		$witness['seal'] = self::operator_witness_seal( $witness );
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			$audit = MAD4B_SCP_Audit::record( 'mad4b/post-update-continuation-operator-witnessed-replacement', array(
				'contract' => self::OPERATOR_WITNESS_CONTRACT,
				'witness_id' => $witness['witness_id'],
				'source' => $source,
				'target_identity' => $target,
				'previous_binding' => $baseline['previous_binding'],
				'baseline_digest' => $witness['baseline_digest'],
				'actor_wp_user_id' => $user_id,
				'zero_delta_required' => true,
				'mutation_performed' => false,
				'production_mutation' => false,
				'breakglass' => false,
			), 'ok' );
			if ( is_wp_error( $audit ) ) return $audit;
		}
		update_option( self::OPERATOR_WITNESS_OPTION, $witness, false );
		if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::OPERATOR_WITNESS_OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
		$stored_witness = get_option( self::OPERATOR_WITNESS_OPTION, array() );
		if ( ! self::operator_witness_valid( $stored_witness ) || ! hash_equals( (string) $witness['seal'], (string) ( $stored_witness['seal'] ?? '' ) ) ) {
			return new WP_Error( 'mad4b_operator_witness_readback_failed', 'Operator replacement witness did not persist exactly.' );
		}
		return self::operator_witness_status();
	}

	private static function operator_witness_matches_permit( array $permit ) {
		$release = isset( $permit['release'] ) && is_array( $permit['release'] ) ? $permit['release'] : array();
		$witness = isset( $release['operator_witness'] ) && is_array( $release['operator_witness'] ) ? $release['operator_witness'] : array();
		if ( 'staging_operator_witnessed_replacement' !== ( isset( $release['trust_role'] ) ? (string) $release['trust_role'] : '' ) || ! self::operator_witness_valid( $witness ) ) return false;
		if ( empty( $permit['target_identity'] ) || ! self::identity_matches( $witness['target_identity'], $permit['target_identity'] ) ) return false;
		if ( empty( $permit['previous_binding'] ) || ! self::identity_matches( $witness['previous_binding'], $permit['previous_binding'] ) ) return false;
		if ( empty( $permit['baseline_digest'] ) || ! hash_equals( (string) $witness['baseline_digest'], (string) $permit['baseline_digest'] ) ) return false;
		if ( (int) $witness['actor_wp_user_id'] !== (int) ( $permit['actor']['wp_user_id'] ?? 0 ) ) return false;
		if ( ! hash_equals( (string) $witness['site_uuid'], (string) ( $permit['site']['site_uuid'] ?? '' ) )
			|| ! hash_equals( (string) $witness['origin'], (string) ( $permit['site']['origin'] ?? '' ) )
			|| ! hash_equals( (string) $witness['profile_digest'], (string) ( $permit['site']['profile_digest'] ?? '' ) )
			|| (int) $witness['profile_revision'] !== (int) ( $permit['site']['profile_revision'] ?? -1 ) ) return false;
		$physical = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
		return ! empty( $physical['runtime_manifest_match'] ) && self::identity_matches( $permit['target_identity'], $physical );
	}

	private static function permit_package_trusted( array $permit ) {
		$release = isset( $permit['release'] ) && is_array( $permit['release'] ) ? $permit['release'] : array();
		if ( self::release_trusted( $release ) ) return true;
		return 'operator_witnessed_manual_replacement' === ( isset( $permit['channel'] ) ? (string) $permit['channel'] : '' )
			&& self::operator_witness_matches_permit( $permit );
	}

	public static function operator_witnessed_reconciliation_preflight() {
		$result = array(
			'contract' => 'mad4b.operator-witnessed-reconciliation-preflight.v1',
			'disposition' => 'HARD_BLOCK',
			'eligible' => false,
			'reasons' => array(),
			'authority_delta' => 'zero_required',
			'mutation_class' => 'candidate_binding_only',
			'production_allowed' => false,
			'breakglass_allowed' => false,
			'grant_mutation_allowed' => false,
			'subject_mutation_allowed' => false,
			'agent_mutation_allowed' => false,
			'read_only' => true,
			'mutation_performed' => false,
			'retryable' => false,
			'retry_after_seconds' => 0,
		);
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) {
			$result['reasons'][] = 'production_or_environment_changed';
			return $result;
		}
		if ( self::breakglass_enabled() ) {
			$result['reasons'][] = 'breakglass_excluded';
			return $result;
		}
		$witness = get_option( self::OPERATOR_WITNESS_OPTION, array() );
		if ( ! self::operator_witness_valid( $witness ) ) {
			$result['reasons'][] = 'operator_witness_missing_or_expired';
			return $result;
		}
		$baseline = get_option( self::BASELINE_OPTION, array() );
		if ( ! self::baseline_valid( $baseline ) || ! hash_equals( (string) $witness['baseline_digest'], self::digest( $baseline ) ) ) {
			$result['disposition'] = 'REVIEW_REQUIRED';
			$result['reasons'][] = 'sealed_baseline_changed_or_expired';
			return $result;
		}
		$target = self::current_identity();
		if ( is_wp_error( $target ) || ! self::identity_matches( $witness['target_identity'], $target ) ) {
			$result['reasons'][] = 'operator_witness_target_drift';
			return $result;
		}
		$physical = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( empty( $physical['runtime_manifest_match'] ) || ! self::identity_matches( $target, $physical ) ) {
			$result['reasons'][] = 'package_integrity_unverified';
			return $result;
		}
		$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
		if ( empty( $binding['required'] ) || ! empty( $binding['match'] ) ) {
			$result['disposition'] = 'NO_OP';
			$result['eligible'] = true;
			$result['reasons'][] = empty( $binding['required'] ) ? 'candidate_binding_not_required' : 'candidate_binding_already_current';
			return $result;
		}
		$current = self::read_permit();
		if ( self::permit_active( $current, true ) || ( is_array( $current ) && 'executing' === ( $current['state'] ?? '' ) ) ) {
			$result['disposition'] = 'DEFER';
			$result['reasons'][] = 'continuation_already_active';
			$result['retryable'] = true;
			$result['retry_after_seconds'] = 15;
			return $result;
		}
		$preview = array(
			'channel' => 'operator_witnessed_manual_replacement',
			'target_identity' => $target,
			'release' => array(
				'trust_role' => 'staging_operator_witnessed_replacement',
				'master_release' => false,
				'operator_witness' => $witness,
			),
			'site' => $baseline['site'],
			'actor' => $baseline['actor'],
			'previous_binding' => $baseline['previous_binding'],
			'write_snapshot' => $baseline['write_snapshot'],
			'transport_snapshot' => $baseline['transport_snapshot'],
			'write_contract_fingerprint' => $baseline['write_contract_fingerprint'],
			'baseline_digest' => self::digest( $baseline ),
		);
		$delta = self::classify_current_delta( $preview );
		$result['classification'] = isset( $delta['classification'] ) ? (string) $delta['classification'] : self::CLASS_HARD;
		$result['reasons'] = isset( $delta['reasons'] ) && is_array( $delta['reasons'] ) ? array_values( array_unique( array_map( 'sanitize_key', $delta['reasons'] ) ) ) : array( 'delta_classification_unavailable' );
		if ( self::CLASS_HARD === $result['classification'] ) return $result;
		if ( self::CLASS_REVIEW === $result['classification'] ) {
			$result['disposition'] = 'REVIEW_REQUIRED';
			return $result;
		}
		$skills = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) ? MAD4B_SCP_Skill_Runtime_Certification::persisted_status() : array();
		if ( empty( $skills['ready'] ) || empty( $skills['build_identity_current'] ) || ! self::identity_matches( $target, $skills ) ) {
			$result['disposition'] = 'DEFER';
			$result['reasons'] = array( 'current_build_skill_certification_pending' );
			$result['retryable'] = true;
			$result['retry_after_seconds'] = 15;
			return $result;
		}
		$result['disposition'] = 'AUTO_REBIND';
		$result['eligible'] = true;
		$result['reasons'] = array();
		return $result;
	}

	public static function prepare_operator_witnessed_update( $lease_token = '' ) {
		$fence = class_exists( 'MAD4B_SCP_Runtime_Maintenance_Lease' ) ? MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lease_token, 'runtime_convergence' ) : new WP_Error( 'mad4b_post_update_continuation_lease_required', 'Convergence maintenance lease is required.' );
		if ( is_wp_error( $fence ) ) return $fence;
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_operator_witness_staging_only', 'Operator-witnessed continuation is Staging-only.' );
		if ( self::breakglass_enabled() ) return new WP_Error( 'mad4b_operator_witness_breakglass_excluded', 'Operator-witnessed continuation is disabled while Breakglass is enabled.' );

		$witness = get_option( self::OPERATOR_WITNESS_OPTION, array() );
		if ( ! self::operator_witness_valid( $witness ) ) return new WP_Error( 'mad4b_operator_witness_missing_or_expired', 'A current sealed operator replacement witness is required.' );
		$baseline = get_option( self::BASELINE_OPTION, array() );
		if ( ! self::baseline_valid( $baseline ) || ! hash_equals( (string) $witness['baseline_digest'], self::digest( $baseline ) ) ) {
			return new WP_Error( 'mad4b_operator_witness_baseline_changed', 'The sealed authority baseline changed or expired after the replacement was witnessed.' );
		}
		$target = self::current_identity();
		if ( is_wp_error( $target ) || ! self::identity_matches( $witness['target_identity'], $target ) ) return new WP_Error( 'mad4b_operator_witness_target_drift', 'Installed package identity changed after the replacement witness was recorded.' );
		$physical = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( empty( $physical['runtime_manifest_match'] ) || ! self::identity_matches( $target, $physical ) ) return new WP_Error( 'mad4b_operator_witness_package_integrity_required', 'Full package manifest verification is required before continuation.' );

		$current = self::read_permit();
		if ( self::permit_active( $current, true ) || ( is_array( $current ) && 'executing' === ( $current['state'] ?? '' ) ) ) return new WP_Error( 'mad4b_post_update_continuation_active_permit_exists', 'Another continuation is already active.' );
		$release = array(
			'trust_role' => 'staging_operator_witnessed_replacement',
			'master_release' => false,
			'operator_witness' => $witness,
		);
		$permit = array(
			'contract' => self::CONTRACT,
			'permit_id' => wp_generate_uuid4(),
			'generation' => (int) ( is_array( $current ) ? ( $current['generation'] ?? 0 ) : 0 ) + 1,
			'state' => 'pending_convergence',
			'classification' => self::CLASS_ZERO,
			'classification_reasons' => array(),
			'channel' => 'operator_witnessed_manual_replacement',
			'target_identity' => $target,
			'release' => $release,
			'site' => $baseline['site'],
			'actor' => $baseline['actor'],
			'previous_binding' => $baseline['previous_binding'],
			'write_snapshot' => $baseline['write_snapshot'],
			'transport_snapshot' => $baseline['transport_snapshot'],
			'write_contract_fingerprint' => $baseline['write_contract_fingerprint'],
			'baseline_digest' => self::digest( $baseline ),
			'authority_delta' => 'zero_required',
			'mutation_class' => 'candidate_binding_only',
			'production_allowed' => false,
			'breakglass_allowed' => false,
			'one_time' => true,
			'consumed' => false,
			'claimed' => false,
			'created_at' => gmdate( 'c' ),
			'expires_at' => time() + self::TTL,
			'ttl_seconds' => self::TTL,
		);
		$permit['update_plan_sha256'] = self::digest( array(
			'contract' => 'mad4b.operator-witnessed-rebind-intent.v1',
			'target' => $target,
			'witness_id' => $witness['witness_id'],
			'baseline_digest' => $permit['baseline_digest'],
		) );
		$delta = self::classify_current_delta( $permit );
		if ( self::CLASS_ZERO !== $delta['classification'] ) {
			return new WP_Error( 'mad4b_operator_witness_authority_delta', 'Operator-witnessed carry-forward requires identical contracts, grants, actor, transport and Site Profile.', array(
				'classification' => $delta['classification'],
				'reasons' => $delta['reasons'],
				'operation_state' => 'EXTERNAL_ACTION_REQUIRED',
			) );
		}
		$permit['permit_digest'] = self::permit_digest( $permit );
		$permit['permit_seal'] = self::permit_seal( $permit );
		$audit = self::audit( 'operator-witness-authorized', $permit, array(
			'witness_id' => $witness['witness_id'],
			'trust_role' => 'staging_operator_witnessed_replacement',
			'mutation_performed' => false,
		) );
		if ( is_wp_error( $audit ) ) return $audit;
		if ( ! self::replace_permit( $current, $permit ) ) return new WP_Error( 'mad4b_operator_witness_permit_raced', 'Continuation changed before the operator witness could be claimed.' );
		delete_option( self::OPERATOR_WITNESS_OPTION );
		return self::status();
	}

	public static function prepare( array $target, $channel, $update_plan_sha256 = '', $lease_token = '' ) {
		$fence = class_exists( 'MAD4B_SCP_Runtime_Maintenance_Lease' ) ? MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lease_token, 'self_update_replacement' ) : new WP_Error( 'mad4b_post_update_continuation_lease_required', 'Replacement maintenance lease is required.' );
		if ( is_wp_error( $fence ) ) return $fence;
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) : '';
		if ( 'staging' !== $environment ) return new WP_Error( 'mad4b_post_update_continuation_staging_only', 'Post-update continuation is Staging-only.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::write_enabled() ) {
			return new WP_Error( 'mad4b_post_update_continuation_profile_ineligible', 'Exact enrolled Staging write profile is required for continuation.' );
		}
		$target_identity = self::target_identity( $target );
		if ( is_wp_error( $target_identity ) ) return $target_identity;
		$release = self::release_identity( $target );
		if ( is_wp_error( $release ) ) return $release;

		$plan = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::reconciliation_plan() : array();
		if ( ! is_array( $plan ) || empty( $plan['write_inventory_fingerprint'] ) || empty( $plan['grant_rows_fingerprint'] ) ) {
			return new WP_Error( 'mad4b_post_update_continuation_write_snapshot_unavailable', 'Current write authority snapshot is unavailable.' );
		}
		$binding = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::candidate_binding_status() : array();
		$previous_binding = class_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding' ) && method_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding', 'audit_binding_snapshot' )
			? MAD4B_SCP_Staging_Write_Candidate_Binding::audit_binding_snapshot( is_array( $binding ) ? $binding : array() )
			: self::bounded_binding( is_array( $binding ) ? $binding : array() );

		if ( empty( $plan['eligible'] ) || empty( $plan['current_ready'] ) || empty( $plan['agent_present'] ) || empty( $binding['match'] ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) return new WP_Error( 'mad4b_post_update_continuation_previous_authority_required', 'Continuation requires an already effective exact previous candidate binding.' );
		$profile_revision = MAD4B_SCP_Site_Profile::revision();
		$profile_digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
		$site_uuid = strtolower( (string) MAD4B_SCP_Site_Profile::site_uuid() );
		$origin = untrailingslashit( (string) MAD4B_SCP_Site_Profile::current_origin() );
		$actor = self::capture_actor();
		$transport = self::transport_snapshot();
		if ( empty( $transport['inventory_ready'] ) ) {
			$code = ! empty( $transport['foreign_transport_unreviewed'] )
				? 'mad4b_post_update_continuation_foreign_transport_unreviewed'
				: ( ! empty( $transport['write_side_channel_detected'] )
					? 'mad4b_post_update_continuation_write_side_channel_detected'
					: 'mad4b_post_update_continuation_transport_unavailable' );
			return new WP_Error(
				$code,
				'Verified live transport inventory is required before update continuation.',
				array(
					'failure_phase' => 'pre_update_continuation_prepare',
					'transport_inventory_observed' => ! empty( $transport['inventory_observed'] ),
					'transport_inventory_reason' => isset( $transport['inventory_reason'] ) ? (string) $transport['inventory_reason'] : '',
					'transport_inventory_lifecycle_state' => isset( $transport['inventory_lifecycle_state'] ) ? (string) $transport['inventory_lifecycle_state'] : '',
					'transport_server_count' => isset( $transport['server_count'] ) ? (int) $transport['server_count'] : 0,
					'transport_blockers' => isset( $transport['transport_blockers'] ) ? array_values( $transport['transport_blockers'] ) : array(),
					'filesystem_replacement_attempted' => false,
					'operator_action_required' => true,
				)
			);
		}
		$write_snapshot = self::write_snapshot( $plan );
		$write_contracts = self::write_contract_fingerprint();
		if ( '' === $write_contracts ) return new WP_Error( 'mad4b_post_update_continuation_contract_unavailable', 'Exact write contracts must be observed before preparing update continuation.' );
		$update_plan_sha256 = strtolower( trim( (string) $update_plan_sha256 ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $update_plan_sha256 ) ) {
			$update_plan_sha256 = self::digest( array(
				'contract' => 'mad4b.exact-self-update-intent.v1',
				'channel' => sanitize_key( (string) $channel ),
				'target_identity' => $target_identity,
				'release' => $release,
				'site_uuid' => $site_uuid,
				'profile_revision' => $profile_revision,
				'profile_digest' => $profile_digest,
			) );
		}

		$classification = self::CLASS_ZERO;
		$reasons = array();
		if ( empty( $actor['oauth_attribution_complete'] ) ) {
			$classification = self::CLASS_REVIEW;
			$reasons[] = 'oauth_actor_attribution_unavailable';
		}
		if ( ! self::snapshot_clean( $write_snapshot ) ) {
			$classification = self::contains_hard_authority_drift( $write_snapshot ) ? self::CLASS_HARD : self::CLASS_REVIEW;
			$reasons[] = 'pre_update_authority_not_zero_delta';
		}
		if ( ! empty( $write_snapshot['breakglass_included'] ) || self::breakglass_enabled() ) {
			$classification = self::CLASS_HARD;
			$reasons[] = 'breakglass_excluded';
		}

		$current = self::read_permit();
		$current_generation = is_array( $current ) && isset( $current['generation'] ) ? absint( $current['generation'] ) : 0;
		if ( self::permit_active( $current, true ) || ( is_array( $current ) && 'executing' === ( $current['state'] ?? '' ) ) ) return new WP_Error( 'mad4b_post_update_continuation_active_permit_exists', 'Another unconsumed post-update continuation is already active.' );

		$permit = array(
			'contract' => self::CONTRACT,
			'permit_id' => wp_generate_uuid4(),
			'generation' => $current_generation + 1,
			'state' => 'prepared',
			'classification' => $classification,
			'classification_reasons' => array_values( array_unique( array_map( 'sanitize_key', $reasons ) ) ),
			'channel' => sanitize_key( (string) $channel ),
			'update_plan_sha256' => $update_plan_sha256,
			'target_identity' => $target_identity,
			'release' => $release,
			'site' => array(
				'site_uuid' => $site_uuid,
				'origin' => $origin,
				'environment' => 'staging',
				'profile_revision' => (int) $profile_revision,
				'profile_digest' => $profile_digest,
			),
			'actor' => $actor,
			'write_snapshot' => $write_snapshot,
			'write_contract_fingerprint' => $write_contracts,
			'transport_snapshot' => $transport,
			'previous_binding' => $previous_binding,
			'authority_delta' => 'zero_required',
			'mutation_class' => 'candidate_binding_only',
			'production_allowed' => false,
			'breakglass_allowed' => false,
			'one_time' => true,
			'consumed' => false,
			'claimed' => false,
			'created_at' => gmdate( 'c' ),
			'expires_at' => time() + self::TTL,
			'ttl_seconds' => self::TTL,
		);
		$permit['permit_digest'] = self::permit_digest( $permit );
		$permit['permit_seal'] = self::permit_seal( $permit );
		$saved = self::replace_permit( $current, $permit );
		if ( is_wp_error( $saved ) ) return $saved;
		$audit = self::audit( 'prepared', $permit, array( 'mutation_performed' => false ) );
		if ( is_wp_error( $audit ) ) { self::cancel( 'prepare_audit_failed' ); return $audit; }
		return $permit;
	}

	public static function mark_readback_verified( array $target_identity ) {
		$permit = self::active_permit_or_error();
		if ( is_wp_error( $permit ) ) return $permit;
		$current_target = self::target_identity( $target_identity );
		if ( is_wp_error( $current_target ) || ! self::identity_matches( $permit['target_identity'], $current_target ) ) {
			return self::transition_terminal( $permit, 'blocked', self::CLASS_HARD, array( 'package_identity_mismatch' ) );
		}
		return self::transition( $permit, array(
			'state' => 'exact_readback_verified',
			'readback_verified_at' => gmdate( 'c' ),
		) );
	}

	/** Observe existing exact authority. This creates no permission or update intent. */
	public static function capture_ready_baseline( $lease_token = '', $lease_owner = 'runtime_convergence' ) {
		if ( '' !== $lease_token ) {
			$fence = MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lease_token, $lease_owner );
			if ( is_wp_error( $fence ) ) return $fence;
		} elseif ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) {
			return new WP_Error( 'mad4b_authority_baseline_observation_context_required', 'An existing governed lifecycle lease or verified OAuth request is required.' );
		}
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() || self::breakglass_enabled()
			|| ! MAD4B_SCP_Site_Profile::write_enabled() || ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_authority_baseline_profile_ineligible', 'Only exact enrolled Staging authority can be observed.' );
		$plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
		$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
		$snapshot = self::write_snapshot( is_array( $plan ) ? $plan : array() );
		if ( empty( $plan['eligible'] ) || empty( $plan['current_ready'] ) || empty( $binding['match'] ) || ! self::snapshot_clean( $snapshot )
			|| ! MAD4B_SCP_Staging_Write_Authority::effective() ) return new WP_Error( 'mad4b_authority_baseline_current_authority_required', 'A healthy exact candidate and unchanged grant inventory are required.' );
		$actor = self::capture_actor();
		$transport = self::transport_snapshot();
		$contracts = self::write_contract_fingerprint();
		if ( empty( $transport['inventory_ready'] ) || '' === $contracts ) return new WP_Error( 'mad4b_authority_baseline_contract_unavailable', 'Exact transport and write contracts must be available.' );
		if ( empty( $actor['oauth_attribution_complete'] ) ) {
			$completed = self::read_permit();
			// The transaction persists "consumed"; "completed" is the public result.
			if ( is_array( $completed ) && self::integrity_valid( $completed ) && 'consumed' === ( $completed['state'] ?? '' )
				&& ! empty( $completed['consumed'] ) && self::identity_matches( $completed['target_identity'], self::bounded_binding( $binding ) )
				&& ( $completed['site']['profile_digest'] ?? '' ) === MAD4B_SCP_Site_Profile::profile_digest()
				&& ( $completed['site']['profile_revision'] ?? -1 ) === MAD4B_SCP_Site_Profile::revision()
				&& ( $completed['site']['site_uuid'] ?? '' ) === strtolower( MAD4B_SCP_Site_Profile::site_uuid() )
				&& ( $completed['site']['origin'] ?? '' ) === untrailingslashit( MAD4B_SCP_Site_Profile::current_origin() )
				&& ( $completed['transport_snapshot']['fingerprint'] ?? '' ) === ( $transport['fingerprint'] ?? '' )
				&& ( $completed['write_contract_fingerprint'] ?? '' ) === $contracts
				&& self::digest( $completed['write_snapshot'] ?? array() ) === self::digest( $snapshot ) ) $actor = $completed['actor'];
		}
		if ( empty( $actor['oauth_attribution_complete'] ) ) {
			$observed = get_option( self::BASELINE_OPTION, array() );
			// Refresh an unexpired sealed observation only while its complete authority
			// remains identical. Cron cannot create an actor or revive expired evidence.
			if ( self::baseline_valid( $observed )
				&& ( $observed['site']['site_uuid'] ?? '' ) === strtolower( MAD4B_SCP_Site_Profile::site_uuid() )
				&& ( $observed['site']['origin'] ?? '' ) === untrailingslashit( MAD4B_SCP_Site_Profile::current_origin() )
				&& ( $observed['site']['environment'] ?? '' ) === 'staging'
				&& ( $observed['site']['profile_digest'] ?? '' ) === MAD4B_SCP_Site_Profile::profile_digest()
				&& ( $observed['site']['profile_revision'] ?? -1 ) === MAD4B_SCP_Site_Profile::revision()
				&& self::identity_matches( $observed['previous_binding'], self::bounded_binding( $binding ) )
				&& ( $observed['transport_snapshot']['fingerprint'] ?? '' ) === ( $transport['fingerprint'] ?? '' )
				&& ( $observed['write_contract_fingerprint'] ?? '' ) === $contracts
				&& self::digest( $observed['write_snapshot'] ?? array() ) === self::digest( $snapshot ) ) $actor = $observed['actor'];
		}
		if ( empty( $actor['oauth_attribution_complete'] ) ) return new WP_Error( 'mad4b_authority_baseline_actor_missing', 'Previously verified OAuth attribution is required; it cannot be inferred from an admin login.' );
		$actor_user = get_userdata( (int) $actor['wp_user_id'] );
		if ( ! $actor_user || ! user_can( $actor_user, 'manage_options' ) || ! MAD4B_SCP_Site_Profile::user_is_enrolled( (int) $actor['wp_user_id'] ) ) return new WP_Error( 'mad4b_authority_baseline_actor_revoked', 'The observed actor must still have enrolled administrator authority.' );
		$baseline = array( 'contract' => self::BASELINE_CONTRACT,
			'site' => array( 'site_uuid' => strtolower( MAD4B_SCP_Site_Profile::site_uuid() ), 'origin' => untrailingslashit( MAD4B_SCP_Site_Profile::current_origin() ), 'environment' => 'staging', 'profile_revision' => MAD4B_SCP_Site_Profile::revision(), 'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest() ),
			'actor' => $actor, 'previous_binding' => self::bounded_binding( $binding ), 'write_snapshot' => $snapshot, 'transport_snapshot' => $transport,
			'write_contract_fingerprint' => $contracts, 'captured_at' => time(), 'expires_at' => time() + self::BASELINE_TTL, 'authorizing' => false );
		$baseline['seal'] = self::baseline_seal( $baseline );
		$audit = self::audit( 'baseline-observed', array(), array( 'baseline_digest' => self::digest( $baseline ), 'previous_binding' => $baseline['previous_binding'], 'authorizing' => false ) );
		if ( is_wp_error( $audit ) ) return $audit;
		update_option( self::BASELINE_OPTION, $baseline, false );
		if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::BASELINE_OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
		$stored = get_option( self::BASELINE_OPTION, array() );
		if ( ! self::baseline_valid( $stored ) || ! hash_equals( $baseline['seal'], $stored['seal'] ) ) return new WP_Error( 'mad4b_authority_baseline_readback_failed', 'Authority observation was not persisted exactly.' );
		return array( 'state' => 'OBSERVED', 'expires_at' => $baseline['expires_at'], 'authorizing' => false );
	}

	/** Recover a manual replacement only from a pre-existing sealed healthy observation. */
	public static function prepare_observed_update( array $trusted_target, $lease_token = '' ) {
		$fence = MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lease_token, 'runtime_convergence' );
		if ( is_wp_error( $fence ) ) return $fence;
		$target = self::target_identity( $trusted_target ); $release = self::release_identity( $trusted_target );
		if ( is_wp_error( $target ) ) return $target;
		if ( is_wp_error( $release ) ) return $release;
		if ( ! self::identity_matches( $target, self::current_identity() ) ) return new WP_Error( 'mad4b_observed_update_target_mismatch', 'The trusted release must match the actual installed package.' );
		$physical = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( empty( $physical['runtime_manifest_match'] ) || ! self::identity_matches( $target, $physical ) ) return new WP_Error( 'mad4b_observed_update_package_integrity_required', 'Full actual-package verification is required before carrying previous authority.' );
		$baseline = get_option( self::BASELINE_OPTION, array() );
		if ( ! self::baseline_valid( $baseline ) ) return new WP_Error( 'mad4b_observed_update_baseline_required', 'EXTERNAL_ACTION_REQUIRED: no current sealed previous-authority observation is available.' );
		$current = self::read_permit();
		if ( self::permit_active( $current, true ) || is_array( $current ) && 'executing' === ( $current['state'] ?? '' ) ) return new WP_Error( 'mad4b_post_update_continuation_active_permit_exists', 'Another continuation is already active.' );
		$permit = array( 'contract' => self::CONTRACT, 'permit_id' => wp_generate_uuid4(), 'generation' => (int) ( $current['generation'] ?? 0 ) + 1,
			'state' => 'pending_convergence', 'classification' => self::CLASS_ZERO, 'classification_reasons' => array(), 'channel' => 'observed_manual_replacement',
			'target_identity' => $target, 'release' => $release, 'site' => $baseline['site'], 'actor' => $baseline['actor'],
			'previous_binding' => $baseline['previous_binding'], 'write_snapshot' => $baseline['write_snapshot'], 'transport_snapshot' => $baseline['transport_snapshot'],
			'write_contract_fingerprint' => $baseline['write_contract_fingerprint'], 'baseline_digest' => self::digest( $baseline ),
			'authority_delta' => 'zero_required', 'mutation_class' => 'candidate_binding_only', 'production_allowed' => false, 'breakglass_allowed' => false,
			'one_time' => true, 'consumed' => false, 'claimed' => false, 'created_at' => gmdate( 'c' ), 'expires_at' => time() + self::TTL, 'ttl_seconds' => self::TTL );
		$permit['update_plan_sha256'] = self::digest( array( 'target' => $target, 'baseline_digest' => $permit['baseline_digest'] ) );
		$delta = self::classify_current_delta( $permit );
		if ( self::CLASS_ZERO !== $delta['classification'] ) return new WP_Error( 'mad4b_observed_update_authority_delta', 'Automatic carry-forward requires identical contracts, grants, actor and Site Profile.', array( 'classification' => $delta['classification'], 'reasons' => $delta['reasons'], 'operation_state' => 'EXTERNAL_ACTION_REQUIRED' ) );
		$permit['permit_digest'] = self::permit_digest( $permit ); $permit['permit_seal'] = self::permit_seal( $permit );
		$audit = self::audit( 'observed-update-authorized', $permit );
		if ( is_wp_error( $audit ) ) return $audit;
		if ( ! self::replace_permit( $current, $permit ) ) return new WP_Error( 'mad4b_observed_update_permit_raced', 'Continuation changed before the observed update could be claimed.' );
		return self::status();
	}


	/**
	 * Read-only preflight for automatic observed-package reconciliation.
	 *
	 * This method never creates a permit and never mutates grants, subjects,
	 * agents or candidate binding.  It reuses the exact continuation delta
	 * classifier so future scenario discovery cannot weaken ZERO_DELTA.
	 */
	public static function observed_reconciliation_preflight( array $trusted_target ) {
		$result = array(
			'contract' => 'mad4b.observed-reconciliation-preflight.v1',
			'disposition' => 'HARD_BLOCK',
			'eligible' => false,
			'reasons' => array(),
			'authority_delta' => 'zero_required',
			'mutation_class' => 'candidate_binding_only',
			'production_allowed' => false,
			'breakglass_allowed' => false,
			'grant_mutation_allowed' => false,
			'subject_mutation_allowed' => false,
			'agent_mutation_allowed' => false,
			'read_only' => true,
			'mutation_performed' => false,
			'retryable' => false,
			'retry_after_seconds' => 0,
		);

		$environment = class_exists( 'MAD4B_SCP_Site_Profile' )
			? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() )
			: '';
		if ( 'staging' !== $environment ) {
			$result['reasons'][] = 'production_or_environment_changed';
			return $result;
		}
		if ( self::breakglass_enabled() ) {
			$result['reasons'][] = 'breakglass_excluded';
			return $result;
		}

		$target = self::target_identity( $trusted_target );
		if ( is_wp_error( $target ) ) {
			$result['reasons'][] = sanitize_key( (string) $target->get_error_code() );
			return $result;
		}
		$release = self::release_identity( $trusted_target );
		if ( is_wp_error( $release ) ) {
			$result['reasons'][] = sanitize_key( (string) $release->get_error_code() );
			return $result;
		}
		if ( ! self::identity_matches( $target, self::current_identity() ) ) {
			$result['reasons'][] = 'package_identity_mismatch';
			return $result;
		}

		$physical = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' )
			? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status()
			: array();
		if ( empty( $physical['runtime_manifest_match'] ) || ! self::identity_matches( $target, $physical ) ) {
			$result['reasons'][] = 'package_integrity_unverified';
			return $result;
		}

		$binding = class_exists( 'MAD4B_SCP_Staging_Write_Authority' )
			? MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()
			: array();
		if ( empty( $binding['required'] ) || ! empty( $binding['match'] ) ) {
			$result['disposition'] = 'NO_OP';
			$result['eligible'] = true;
			$result['reasons'][] = empty( $binding['required'] ) ? 'candidate_binding_not_required' : 'candidate_binding_already_current';
			return $result;
		}

		$baseline = get_option( self::BASELINE_OPTION, array() );
		if ( ! self::baseline_valid( $baseline ) ) {
			$result['disposition'] = 'REVIEW_REQUIRED';
			$result['reasons'][] = 'sealed_baseline_missing_or_expired';
			return $result;
		}

		$current = self::read_permit();
		if ( self::permit_active( $current, true ) || ( is_array( $current ) && 'executing' === ( $current['state'] ?? '' ) ) ) {
			$result['disposition'] = 'DEFER';
			$result['reasons'][] = 'continuation_already_active';
			return $result;
		}

		$preview = array(
			'target_identity' => $target,
			'release' => $release,
			'site' => $baseline['site'],
			'actor' => $baseline['actor'],
			'previous_binding' => $baseline['previous_binding'],
			'write_snapshot' => $baseline['write_snapshot'],
			'transport_snapshot' => $baseline['transport_snapshot'],
			'write_contract_fingerprint' => $baseline['write_contract_fingerprint'],
		);
		$delta = self::classify_current_delta( $preview );
		$result['classification'] = isset( $delta['classification'] ) ? (string) $delta['classification'] : self::CLASS_HARD;
		$result['reasons'] = isset( $delta['reasons'] ) && is_array( $delta['reasons'] )
			? array_values( array_unique( array_map( 'sanitize_key', $delta['reasons'] ) ) )
			: array( 'delta_classification_unavailable' );

		if ( self::CLASS_HARD === $result['classification'] ) {
			$result['disposition'] = 'HARD_BLOCK';
			return $result;
		}
		if ( self::CLASS_REVIEW === $result['classification'] ) {
			$result['disposition'] = 'REVIEW_REQUIRED';
			return $result;
		}

		$skills = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' )
			? MAD4B_SCP_Skill_Runtime_Certification::persisted_status()
			: array();
		if ( empty( $skills['ready'] ) || empty( $skills['build_identity_current'] ) || ! self::identity_matches( $target, $skills ) ) {
			$result['disposition'] = 'DEFER';
			$result['reasons'] = array( 'current_build_skill_certification_pending' );
			$result['retryable'] = true;
			$result['retry_after_seconds'] = 15;
			return $result;
		}

		$result['disposition'] = 'AUTO_REBIND';
		$result['eligible'] = true;
		$result['reasons'] = array();
		return $result;
	}

	private static function baseline_seal( array $baseline ) { unset( $baseline['seal'] ); return hash_hmac( 'sha256', self::digest( $baseline ), wp_salt( 'auth' ) ); }
	private static function baseline_valid( $baseline ) {
		return is_array( $baseline ) && self::BASELINE_CONTRACT === ( $baseline['contract'] ?? '' ) && is_string( $baseline['seal'] ?? null ) && preg_match( '/^[a-f0-9]{64}$/D', $baseline['seal'] )
			&& is_int( $baseline['captured_at'] ?? null ) && is_int( $baseline['expires_at'] ?? null ) && $baseline['captured_at'] <= time() && $baseline['expires_at'] > time()
			&& $baseline['expires_at'] - $baseline['captured_at'] <= self::BASELINE_TTL && hash_equals( self::baseline_seal( $baseline ), (string) $baseline['seal'] );
	}

	private static function write_contract_fingerprint() {
		if ( ! function_exists( 'wp_get_ability' ) || ! method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'write_tools' ) ) return '';
		$contracts = array();
		foreach ( MAD4B_SCP_Staging_Write_Authority::write_tools() as $name ) {
			$ability = wp_get_ability( $name );
			foreach ( array( 'get_meta', 'get_input_schema', 'get_output_schema' ) as $method ) if ( ! is_object( $ability ) || ! is_callable( array( $ability, $method ) ) ) return '';
			try {
				$meta = $ability->get_meta();
				if ( ! is_array( $meta ) ) return '';
				$mcp = is_array( $meta['mcp'] ?? null ) ? $meta['mcp'] : array();
				// Custom MAD4B metadata also carries authority and execution contracts.
				unset( $mcp['label'], $mcp['description'] );
				$contracts[ $name ] = array( 'input' => $ability->get_input_schema(), 'output' => $ability->get_output_schema(), 'annotations' => $meta['annotations'] ?? array(), 'governance' => $mcp );
			} catch ( Throwable $error ) { return ''; }
		}
		return empty( $contracts ) ? '' : self::digest( $contracts );
	}

	public static function evaluate_and_rebind( $lease_token = '' ) {
		$fence = class_exists( 'MAD4B_SCP_Runtime_Maintenance_Lease' ) ? MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lease_token, 'runtime_convergence' ) : new WP_Error( 'mad4b_post_update_continuation_lease_required', 'Convergence maintenance lease is required.' );
		if ( is_wp_error( $fence ) ) return $fence;
		$permit = self::active_permit_or_error();
		if ( is_wp_error( $permit ) ) return $permit;
		if ( ! in_array( isset( $permit['state'] ) ? (string) $permit['state'] : '', array( 'exact_readback_verified', 'pending_convergence' ), true ) ) {
			return new WP_Error( 'mad4b_post_update_continuation_state_invalid', 'Continuation is not ready for zero-delta evaluation.' );
		}

		$classification = self::classify_current_delta( $permit );
		if ( self::CLASS_ZERO !== $classification['classification'] ) {
			$terminal = self::CLASS_HARD === $classification['classification'] ? 'blocked' : 'owner_gate';
			return self::transition_terminal( $permit, $terminal, $classification['classification'], $classification['reasons'], $classification );
		}

		$skills = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) ? MAD4B_SCP_Skill_Runtime_Certification::persisted_status() : array();
		if ( empty( $skills['ready'] ) || empty( $skills['build_identity_current'] ) || ! self::identity_matches( $permit['target_identity'], $skills ) ) return new WP_Error( 'mad4b_post_update_continuation_skills_certification_required', 'Exact current-build persisted Skill certification is required before claiming continuation.' );

		$claimed = self::transition( $permit, array(
			'state' => 'executing',
			'claimed' => true,
			'claimed_at' => gmdate( 'c' ),
			'classification' => self::CLASS_ZERO,
			'classification_reasons' => array(),
		) );
		if ( is_wp_error( $claimed ) ) return $claimed;
		$permit = $claimed;

		$plan = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::reconciliation_plan() : array();
		if ( ! is_array( $plan ) ) return self::execution_failed( $permit, 'write_reconciliation_plan_unavailable' );
		$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
		$context = self::binding_context( $permit, $plan, $binding );
		if ( is_wp_error( $context ) ) return self::execution_failed( $permit, $context->get_error_code() );

		self::$executing_context_digest = self::digest( $context );
		try {
		$result = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity(
			(string) $permit['target_identity']['source_commit_sha'],
			(string) $permit['target_identity']['build_fingerprint'],
			$context
		);
		} finally { self::$executing_context_digest = null; }
		if ( is_wp_error( $result ) ) return self::execution_failed( $permit, $result->get_error_code() );

		$post = self::classify_current_delta( $permit, true );
		$binding_after = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
		if ( self::CLASS_ZERO !== $post['classification'] || empty( $binding_after['match'] ) ) {
			return self::execution_failed( $permit, 'post_update_continuation_postcondition_failed', $post );
		}

		$consumed = self::read_permit();
		if ( ! is_array( $consumed ) || empty( $consumed['consumed'] ) || 'consumed' !== $consumed['state'] || $consumed['permit_id'] !== $permit['permit_id'] ) return self::execution_failed( $permit, 'atomic_consume_readback_failed' );
		return array(
			'contract' => self::CONTRACT,
			'state' => 'completed',
			'classification' => self::CLASS_ZERO,
			'candidate_binding' => $binding_after,
			'permit_id' => (string) $consumed['permit_id'],
			'permit_digest' => (string) $consumed['permit_digest'],
			'grant_changes' => 0,
			'production_mutation' => false,
			'breakglass' => false,
		);
	}

	public static function validate_binding_context( array $context, array $current_identity ) {
		if ( null === self::$executing_context_digest || ! hash_equals( self::$executing_context_digest, self::digest( $context ) ) ) return new WP_Error( 'mad4b_post_update_continuation_request_proof_required', 'Continuation binding can only execute within the claimed lifecycle request.' );
		$permit = self::read_permit();
		if ( ! is_array( $permit ) || self::CONTRACT !== ( isset( $permit['contract'] ) ? (string) $permit['contract'] : '' ) ) {
			return new WP_Error( 'mad4b_post_update_continuation_permit_missing', 'Post-update continuation permit is unavailable.' );
		}
		if ( 'executing' !== ( isset( $permit['state'] ) ? (string) $permit['state'] : '' ) || empty( $permit['claimed'] ) || ! empty( $permit['consumed'] ) ) {
			return new WP_Error( 'mad4b_post_update_continuation_not_claimed', 'Post-update continuation must be atomically claimed before candidate binding.' );
		}
		if ( ! self::permit_active( $permit, true ) ) return new WP_Error( 'mad4b_post_update_continuation_expired', 'Post-update continuation is expired or terminal.' );
		if ( ! self::integrity_valid( $permit ) ) return new WP_Error( 'mad4b_post_update_continuation_integrity_failed', 'Claimed continuation permit integrity failed.' );
		$delta = self::classify_current_delta( $permit );
		if ( self::CLASS_ZERO !== $delta['classification'] ) return new WP_Error( 'mad4b_post_update_continuation_live_delta_changed', 'Continuation authority changed before candidate binding.' );
		$ctx = isset( $context['continuation_permit'] ) && is_array( $context['continuation_permit'] ) ? $context['continuation_permit'] : array();
		if ( empty( $ctx['permit_id'] ) || empty( $ctx['permit_digest'] )
			|| ! hash_equals( (string) $permit['permit_id'], (string) $ctx['permit_id'] )
			|| ! hash_equals( (string) $permit['permit_digest'], (string) $ctx['permit_digest'] ) ) {
			return new WP_Error( 'mad4b_post_update_continuation_context_mismatch', 'Candidate binding continuation context does not match the claimed permit.' );
		}
		if ( ! self::identity_matches( $permit['target_identity'], $current_identity ) ) return new WP_Error( 'mad4b_post_update_continuation_target_drift', 'Current package identity no longer matches the continuation target.' );
		$actor = isset( $context['actor'] ) && is_array( $context['actor'] ) ? $context['actor'] : array();
		$stored_actor = isset( $permit['actor'] ) && is_array( $permit['actor'] ) ? $permit['actor'] : array();
		foreach ( array( 'subject_fingerprint', 'issuer_fingerprint', 'client_fingerprint', 'session_fingerprint' ) as $field ) {
			if ( empty( $actor[ $field ] ) || empty( $stored_actor[ $field ] ) || ! hash_equals( (string) $stored_actor[ $field ], (string) $actor[ $field ] ) ) {
				return new WP_Error( 'mad4b_post_update_continuation_actor_mismatch', 'Captured OAuth actor attribution does not match the continuation permit.', array( 'field' => $field ) );
			}
		}
		if ( (int) ( $actor['wp_user_id'] ?? 0 ) !== (int) ( $stored_actor['wp_user_id'] ?? -1 ) ) return new WP_Error( 'mad4b_post_update_continuation_actor_user_mismatch', 'Continuation WordPress actor changed.' );
		return true;
	}

	public static function consume_binding_context( array $context ) {
		if ( null === self::$executing_context_digest || ! hash_equals( self::$executing_context_digest, self::digest( $context ) ) ) return new WP_Error( 'mad4b_post_update_continuation_request_proof_required', 'Atomic consume requires the claimed lifecycle request.' );
		$permit = self::read_permit();
		if ( ! is_array( $permit ) || ! self::integrity_valid( $permit ) || ! self::permit_active( $permit, true ) || 'executing' !== $permit['state'] || (int) $permit['generation'] !== (int) ( $context['continuation_permit']['generation'] ?? -1 ) ) return new WP_Error( 'mad4b_post_update_continuation_consume_fence_lost', 'Continuation claim changed inside candidate transaction.' );
		$delta = self::classify_current_delta( $permit, true );
		if ( self::CLASS_ZERO !== $delta['classification'] ) return new WP_Error( 'mad4b_post_update_continuation_postcondition_drift', 'Continuation postconditions changed inside candidate transaction.' );
		$consumed = self::transition( $permit, array( 'state' => 'consumed', 'consumed' => true, 'consumed_at' => gmdate( 'c' ), 'classification' => self::CLASS_ZERO, 'candidate_binding_match' => true ) );
		if ( is_wp_error( $consumed ) ) return $consumed;
		$audit = self::audit( 'consumed', $consumed, array( 'binding_mutation_performed' => true, 'grant_mutation_performed' => false ), true );
		return is_wp_error( $audit ) ? $audit : $consumed;
	}

	public static function cancel( $reason = 'cancelled', $target_identity = array() ) {
		$permit = self::read_permit();
		if ( ! is_array( $permit ) || self::CONTRACT !== ( isset( $permit['contract'] ) ? (string) $permit['contract'] : '' ) ) return true;
		if ( ! empty( $target_identity ) && isset( $permit['target_identity'] ) && is_array( $permit['target_identity'] ) ) {
			$target = self::target_identity( $target_identity );
			if ( is_wp_error( $target ) || ! self::identity_matches( $permit['target_identity'], $target ) ) return true;
		}
		if ( ! empty( $permit['consumed'] ) ) return true;
		$cancelled = self::transition( $permit, array(
			'state' => 'cancelled',
			'classification' => self::CLASS_HARD,
			'classification_reasons' => array( sanitize_key( (string) $reason ) ),
			'cancelled_at' => gmdate( 'c' ),
			'claimed' => false,
		) );
		if ( ! is_wp_error( $cancelled ) ) self::audit( 'cancelled', $cancelled, array( 'reason' => sanitize_key( (string) $reason ) ) );
		return $cancelled;
	}

	private static function classify_current_delta( array $permit, $post_bind = false ) {
		$reasons = array();
		$hard = array();
		$review = array();
		if ( 'staging' !== ( class_exists( 'MAD4B_SCP_Site_Profile' ) ? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) : '' ) ) $hard[] = 'production_or_environment_changed';
		if ( self::breakglass_enabled() ) $hard[] = 'breakglass_excluded';
		if ( isset( $permit['write_contract_fingerprint'] ) && ( '' === self::write_contract_fingerprint()
			|| ! hash_equals( (string) $permit['write_contract_fingerprint'], self::write_contract_fingerprint() ) ) ) $review[] = 'write_contract_changed';

		$current_identity_result = self::current_identity();
		$current_identity = is_wp_error( $current_identity_result ) ? array( 'available' => false ) : $current_identity_result;
		if ( is_wp_error( $current_identity_result ) || ! self::identity_matches( $permit['target_identity'], $current_identity_result ) ) $hard[] = 'package_identity_mismatch';
		if ( ! self::permit_package_trusted( $permit ) ) $hard[] = 'release_trust_invalid';

		$site = isset( $permit['site'] ) && is_array( $permit['site'] ) ? $permit['site'] : array();
		$current_revision = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::revision() : 0;
		$current_digest = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() ) : '';
		$current_origin = class_exists( 'MAD4B_SCP_Site_Profile' ) ? untrailingslashit( (string) MAD4B_SCP_Site_Profile::current_origin() ) : '';
		if ( (int) ( isset( $site['profile_revision'] ) ? $site['profile_revision'] : -1 ) !== (int) $current_revision
			|| empty( $site['profile_digest'] ) || ! hash_equals( (string) $site['profile_digest'], $current_digest )
			|| empty( $site['origin'] ) || ! hash_equals( (string) $site['origin'], $current_origin ) ) $review[] = 'site_profile_changed';

		if ( empty( $site['site_uuid'] ) || ! hash_equals( (string) $site['site_uuid'], strtolower( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) ) $hard[] = 'site_uuid_changed';
		$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
		$expected_previous = $post_bind ? $permit['target_identity'] : $permit['previous_binding'];
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $field ) {
			if ( empty( $expected_previous[ $field ] ) || ! hash_equals( (string) $expected_previous[ $field ], (string) ( $binding[ 'stored_' . $field ] ?? '' ) ) ) $hard[] = 'previous_binding_changed';
		}
		$transport = self::transport_snapshot();
		$pre_transport = isset( $permit['transport_snapshot']['fingerprint'] ) ? (string) $permit['transport_snapshot']['fingerprint'] : '';
		if ( empty( $transport['inventory_ready'] ) || '' === $pre_transport || empty( $transport['fingerprint'] ) || ! hash_equals( $pre_transport, (string) $transport['fingerprint'] ) ) $review[] = 'transport_inventory_changed';

		$plan = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::reconciliation_plan() : array();
		if ( ! is_array( $plan ) ) $hard[] = 'write_reconciliation_plan_unavailable';
		else {
			$post = self::write_snapshot( $plan );
			if ( ! empty( $post['breakglass_included'] ) ) $hard[] = 'breakglass_excluded';
			foreach ( array( 'unreviewed_stale_allow_grants_count', 'broad_environment_grants_count', 'duplicate_exact_allow_grants_count', 'current_agent_wildcard_grants', 'global_registry_wildcard_grants' ) as $field ) {
				if ( ! empty( $post[ $field ] ) ) $hard[] = $field;
			}
			if ( ! empty( $post['stale_allow_grants_count'] ) ) $hard[] = 'stale_allow_grants_present';
			$pre = isset( $permit['write_snapshot'] ) && is_array( $permit['write_snapshot'] ) ? $permit['write_snapshot'] : array();
			if ( (int) ( isset( $post['write_tool_count'] ) ? $post['write_tool_count'] : -1 ) !== (int) ( isset( $pre['write_tool_count'] ) ? $pre['write_tool_count'] : -2 )
				|| empty( $post['write_inventory_fingerprint'] ) || empty( $pre['write_inventory_fingerprint'] )
				|| ! hash_equals( (string) $pre['write_inventory_fingerprint'], (string) $post['write_inventory_fingerprint'] ) ) $review[] = 'write_inventory_changed';
			if ( empty( $pre['persisted_grant_records_fingerprint'] ) || empty( $post['persisted_grant_records_fingerprint'] ) || ! hash_equals( (string) $pre['persisted_grant_records_fingerprint'], (string) $post['persisted_grant_records_fingerprint'] ) ) $hard[] = 'persisted_grant_records_changed';
			if ( empty( $pre['agent_public_id'] ) || $pre['agent_public_id'] !== $post['agent_public_id'] ) $hard[] = 'authority_agent_changed';
			if ( ! empty( $post['exact_grants_missing_count'] ) ) $review[] = 'exact_grants_changed';
			if ( empty( $post['grant_rows_fingerprint'] ) || empty( $pre['grant_rows_fingerprint'] )
				|| ! hash_equals( (string) $pre['grant_rows_fingerprint'], (string) $post['grant_rows_fingerprint'] ) ) {
				// Clean shape + changed row fingerprint means a bounded authority
				// delta and requires owner review. An unexplained same-shape TOCTOU
				// after binding claim is treated as hard.
				if ( $post_bind ) $hard[] = 'grant_rows_fingerprint_changed';
				else $review[] = 'grant_rows_fingerprint_changed';
			}
		}
		if ( empty( $permit['actor']['oauth_attribution_complete'] ) ) $review[] = 'oauth_actor_attribution_unavailable';
		$actor_user_id = (int) ( $permit['actor']['wp_user_id'] ?? 0 );
		$actor_user = $actor_user_id > 0 ? get_userdata( $actor_user_id ) : false;
		if ( ! $actor_user || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $actor_user_id ) || ! user_can( $actor_user, 'manage_options' ) ) $hard[] = 'captured_actor_authority_revoked';

		$hard = array_values( array_unique( array_map( 'sanitize_key', $hard ) ) );
		$review = array_values( array_unique( array_map( 'sanitize_key', $review ) ) );
		if ( ! empty( $hard ) ) {
			$classification = self::CLASS_HARD;
			$reasons = array_merge( $hard, $review );
		} elseif ( ! empty( $review ) ) {
			$classification = self::CLASS_REVIEW;
			$reasons = $review;
		} else {
			$classification = self::CLASS_ZERO;
		}
		return array(
			'classification' => $classification,
			'reasons' => array_values( array_unique( $reasons ) ),
			'current_identity' => $current_identity,
			'transport_snapshot' => $transport,
			'write_snapshot' => isset( $post ) ? $post : array(),
		);
	}

	private static function binding_context( array $permit, array $plan, array $binding ) {
		if ( empty( $permit['actor']['oauth_attribution_complete'] ) ) return new WP_Error( 'mad4b_post_update_continuation_actor_required', 'Exact captured OAuth actor attribution is required for automatic continuation.' );
		$actor = $permit['actor'];
		$actor['identity_method'] = 'self_update_continuation';
		$actor['agent_public_id'] = isset( $plan['agent_public_id'] ) ? (string) $plan['agent_public_id'] : '';
		$actor['transport_server_id'] = 'self-update-continuation';
		return array(
			'contract' => 'mad4b.staging-write-candidate-binding.v2',
			'operation_basis' => array(
				'source' => 'self_update_continuation',
				'contract' => self::CONTRACT,
				'confirmation' => (string) $permit['permit_digest'],
			),
			'operation_id' => wp_generate_uuid4(),
			'correlation_id' => 'post-update:' . (string) $permit['permit_id'],
			'actor' => $actor,
			'site' => isset( $permit['site'] ) ? $permit['site'] : array(),
			'reviewed_previous_binding' => isset( $permit['previous_binding'] ) ? $permit['previous_binding'] : array(),
			'pre_bind_persisted_binding' => class_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding' )
				? MAD4B_SCP_Staging_Write_Candidate_Binding::audit_binding_snapshot( $binding )
				: self::bounded_binding( $binding ),
			'previous_binding' => isset( $permit['previous_binding'] ) ? $permit['previous_binding'] : array(),
			'target_binding' => $permit['target_identity'],
			'write_snapshot' => self::write_snapshot( $plan ),
			'confirmation' => (string) $permit['permit_digest'],
			'mutation_class' => 'candidate_binding_only',
			'continuation_permit' => array(
				'permit_id' => (string) $permit['permit_id'],
				'permit_digest' => (string) $permit['permit_digest'],
				'generation' => (int) $permit['generation'],
			),
		);
	}

	private static function capture_actor() {
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		$actor = array(
			'actor_type' => 'oauth',
			'wp_user_id' => 0,
			'subject_fingerprint' => '',
			'issuer_fingerprint' => '',
			'client_fingerprint' => '',
			'session_fingerprint' => '',
			'oauth_attribution_complete' => false,
		);
		if ( is_wp_error( $identity ) || ! is_array( $identity ) || empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return $actor;
		$actor['wp_user_id'] = isset( $identity['wp_user_id'] ) ? absint( $identity['wp_user_id'] ) : 0;
		foreach ( array( 'subject_fingerprint', 'issuer_fingerprint', 'client_fingerprint', 'session_fingerprint' ) as $field ) {
			$actor[ $field ] = isset( $identity[ $field ] ) ? strtolower( trim( (string) $identity[ $field ] ) ) : '';
		}
		$actor['oauth_attribution_complete'] = $actor['wp_user_id'] > 0;
		foreach ( array( 'subject_fingerprint', 'issuer_fingerprint', 'client_fingerprint', 'session_fingerprint' ) as $field ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $actor[ $field ] ) ) $actor['oauth_attribution_complete'] = false;
		}
		return $actor;
	}

	private static function transport_snapshot() {
		$ids = class_exists( 'MAD4B_SCP_Servers' ) && method_exists( 'MAD4B_SCP_Servers', 'expected_server_ids' ) ? MAD4B_SCP_Servers::expected_server_ids() : array();
		$rows = array();
		foreach ( $ids as $id ) {
			$rows[] = array( 'server_id' => sanitize_key( (string) $id ) );
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['server_id'], $b['server_id'] ); } );
		$kernel = class_exists( 'MAD4B_SCP_Connection_Identity_Resolver' ) ? MAD4B_SCP_Connection_Identity_Resolver::kernel() : array();
		$snapshot = array(
			'adapter_version' => defined( 'WP_MCP_VERSION' ) ? (string) WP_MCP_VERSION : '',
			'servers' => $rows,
			'connection_kernel_fingerprint' => isset( $kernel['kernel_fingerprint'] ) ? (string) $kernel['kernel_fingerprint'] : '',
			'issuer' => isset( $kernel['oauth']['issuer'] ) ? (string) $kernel['oauth']['issuer'] : '',
			'resource' => isset( $kernel['resource']['url'] ) ? (string) $kernel['resource']['url'] : '',
		);
		$peer = class_exists( 'MAD4B_SCP_MCP_Peer_Governance' ) ? MAD4B_SCP_MCP_Peer_Governance::status() : array();
		$peer_blockers = isset( $peer['blockers'] ) && is_array( $peer['blockers'] )
			? array_values( array_unique( array_map( 'sanitize_key', $peer['blockers'] ) ) )
			: array();
		sort( $peer_blockers, SORT_STRING );
		$snapshot['inventory_observed'] = ! empty( $peer['inventory_ready'] ) && ! empty( $peer['transport_inventory_fingerprint'] );
		$snapshot['inventory_ready'] = ! empty( $snapshot['inventory_observed'] ) && empty( $peer_blockers );
		$snapshot['inventory_reason'] = isset( $peer['reason'] ) ? sanitize_key( (string) $peer['reason'] ) : '';
		$snapshot['inventory_lifecycle_state'] = isset( $peer['inventory_lifecycle_state'] ) ? sanitize_key( (string) $peer['inventory_lifecycle_state'] ) : '';
		$snapshot['server_count'] = isset( $peer['server_count'] ) ? max( 0, (int) $peer['server_count'] ) : 0;
		$snapshot['transport_blockers'] = $peer_blockers;
		$snapshot['foreign_transport_unreviewed'] = ! empty( $peer['foreign_transport_unreviewed'] );
		$snapshot['write_side_channel_detected'] = ! empty( $peer['write_side_channel_detected'] );
		$snapshot['tool_inventory_fingerprint'] = isset( $peer['transport_inventory_fingerprint'] ) ? (string) $peer['transport_inventory_fingerprint'] : '';
		$snapshot['foreign_transport_inventory'] = isset( $peer['foreign_transport_inventory'] ) ? $peer['foreign_transport_inventory'] : array();
		$snapshot['fingerprint'] = self::digest( $snapshot );
		return $snapshot;
	}

	private static function write_snapshot( array $plan ) {
		$out = array(
			'write_tool_count' => isset( $plan['write_tool_count'] ) ? (int) $plan['write_tool_count'] : 0,
			'exact_grants_existing' => isset( $plan['exact_grants_existing'] ) ? (int) $plan['exact_grants_existing'] : 0,
			'write_inventory_fingerprint' => isset( $plan['write_inventory_fingerprint'] ) ? strtolower( (string) $plan['write_inventory_fingerprint'] ) : '',
			'grant_rows_fingerprint' => isset( $plan['grant_rows_fingerprint'] ) ? strtolower( (string) $plan['grant_rows_fingerprint'] ) : '',
			'persisted_grant_records_fingerprint' => isset( $plan['persisted_grant_records_fingerprint'] ) ? (string) $plan['persisted_grant_records_fingerprint'] : '',
			'agent_public_id' => isset( $plan['agent_public_id'] ) ? (string) $plan['agent_public_id'] : '',
			'breakglass_included' => ! empty( $plan['breakglass_included'] ),
		);
		foreach ( self::clean_fields() as $field ) $out[ $field ] = isset( $plan[ $field ] ) ? (int) $plan[ $field ] : -1;
		return $out;
	}

	private static function snapshot_clean( array $snapshot ) {
		if ( empty( $snapshot['write_tool_count'] ) || empty( $snapshot['write_inventory_fingerprint'] ) || empty( $snapshot['grant_rows_fingerprint'] ) || ! empty( $snapshot['breakglass_included'] ) ) return false;
		if ( (int) $snapshot['exact_grants_existing'] !== (int) $snapshot['write_tool_count'] || empty( $snapshot['persisted_grant_records_fingerprint'] ) || empty( $snapshot['agent_public_id'] ) ) return false;
		foreach ( self::clean_fields() as $field ) if ( ! array_key_exists( $field, $snapshot ) || 0 !== (int) $snapshot[ $field ] ) return false;
		return true;
	}

	private static function contains_hard_authority_drift( array $snapshot ) {
		foreach ( array( 'stale_allow_grants_count', 'unreviewed_stale_allow_grants_count', 'broad_environment_grants_count', 'duplicate_exact_allow_grants_count', 'current_agent_wildcard_grants', 'global_registry_wildcard_grants' ) as $field ) {
			if ( ! empty( $snapshot[ $field ] ) ) return true;
		}
		return ! empty( $snapshot['breakglass_included'] );
	}

	private static function current_identity() {
		if ( class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) && method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_identity_status' ) ) {
			$status = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status();
			if ( is_array( $status ) && ! empty( $status['identity_ready'] ) ) return self::target_identity( $status );
		}
		return new WP_Error( 'mad4b_post_update_continuation_current_identity_unavailable', 'Exact current four-part package identity is unavailable.' );
	}

	private static function target_identity( array $target ) {
		$identity = array(
			'source_commit_sha' => isset( $target['source_commit_sha'] ) ? strtolower( trim( (string) $target['source_commit_sha'] ) ) : '',
			'build_fingerprint' => isset( $target['build_fingerprint'] ) ? strtolower( trim( (string) $target['build_fingerprint'] ) ) : '',
			'package_manifest_digest' => isset( $target['package_manifest_digest'] ) ? strtolower( trim( (string) $target['package_manifest_digest'] ) ) : '',
			'artifact_identity' => isset( $target['artifact_identity'] ) ? trim( (string) $target['artifact_identity'] ) : '',
		);
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $identity['source_commit_sha'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $identity['build_fingerprint'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $identity['package_manifest_digest'] )
			|| '' === $identity['artifact_identity'] ) {
			return new WP_Error( 'mad4b_post_update_continuation_target_identity_invalid', 'Exact four-part target identity is required.' );
		}
		return $identity;
	}

	private static function release_identity( array $target ) {
		$release = array(
			'archive_sha256' => isset( $target['archive_sha256'] ) ? strtolower( trim( (string) $target['archive_sha256'] ) ) : '',
			'release_verdict_run_id' => isset( $target['release_verdict_run_id'] ) ? absint( $target['release_verdict_run_id'] ) : 0,
			'release_verdict_success' => ! empty( $target['release_verdict_success'] ),
			'release_root_trust_verified' => ! empty( $target['release_root_trust_verified'] ),
			'published_from_master' => ! empty( $target['published_from_master'] ),
		);
		if ( ! self::release_trusted( $release ) ) return new WP_Error( 'mad4b_post_update_continuation_release_untrusted', 'Continuation requires an immutable archive bound to a successful trusted master Release Verdict.' );
		return $release;
	}

	private static function release_trusted( array $release ) {
		return ! empty( $release['release_verdict_success'] )
			&& ! empty( $release['release_root_trust_verified'] )
			&& ! empty( $release['published_from_master'] )
			&& ! empty( $release['release_verdict_run_id'] )
			&& isset( $release['archive_sha256'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $release['archive_sha256'] );
	}

	private static function bounded_binding( array $binding ) {
		$out = array();
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $field ) {
			$stored = 'stored_' . $field;
			$out[ $field ] = isset( $binding[ $stored ] ) ? (string) $binding[ $stored ] : ( isset( $binding[ $field ] ) ? (string) $binding[ $field ] : '' );
		}
		return $out;
	}

	private static function breakglass_enabled() {
		return ( defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) && true === constant( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) )
			|| ( defined( 'MAD4B_SCP_RAW_SQL_BREAKGLASS_ENABLED' ) && true === constant( 'MAD4B_SCP_RAW_SQL_BREAKGLASS_ENABLED' ) );
	}

	private static function identity_matches( array $expected, $actual ) {
		if ( is_wp_error( $actual ) || ! is_array( $actual ) ) return false;
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $field ) {
			if ( empty( $expected[ $field ] ) || empty( $actual[ $field ] ) || ! hash_equals( (string) $expected[ $field ], (string) $actual[ $field ] ) ) return false;
		}
		return true;
	}

	private static function permit_active( $permit, $allow_executing = false ) {
		if ( ! is_array( $permit ) || self::CONTRACT !== ( isset( $permit['contract'] ) ? (string) $permit['contract'] : '' ) || ! empty( $permit['consumed'] ) ) return false;
		if ( empty( $permit['expires_at'] ) || absint( $permit['expires_at'] ) < time() ) return false;
		$states = array( 'prepared', 'exact_readback_verified', 'pending_convergence' );
		if ( $allow_executing ) $states[] = 'executing';
		return in_array( isset( $permit['state'] ) ? (string) $permit['state'] : '', $states, true );
	}

	private static function active_permit_or_error() {
		$permit = self::read_permit();
		if ( ! self::permit_active( $permit ) ) {
			$state = is_array( $permit ) && isset( $permit['state'] ) ? sanitize_key( (string) $permit['state'] ) : 'absent';
			$code = ! empty( $permit['consumed'] ) ? 'mad4b_post_update_continuation_replay_denied' : 'mad4b_post_update_continuation_unavailable';
			return new WP_Error( $code, 'No active one-time post-update continuation permit is available.', array( 'state' => $state ) );
		}
		if ( ! self::integrity_valid( $permit ) ) {
			return new WP_Error( 'mad4b_post_update_continuation_integrity_failed', 'Continuation permit integrity check failed.' );
		}
		return $permit;
	}

	private static function transition( array $permit, array $changes ) {
		$current = self::read_permit();
		if ( ! is_array( $current ) || empty( $current['permit_id'] ) || ! hash_equals( (string) $permit['permit_id'], (string) $current['permit_id'] )
			|| (int) $permit['generation'] !== (int) ( isset( $current['generation'] ) ? $current['generation'] : -1 ) ) {
			return new WP_Error( 'mad4b_post_update_continuation_generation_conflict', 'Continuation permit generation changed concurrently.' );
		}
		$next = array_merge( $current, $changes );
		$next['generation'] = (int) $current['generation'] + 1;
		$next['updated_at'] = gmdate( 'c' );
		$next['permit_digest'] = self::permit_digest( $next );
		$next['permit_seal'] = self::permit_seal( $next );
		return self::replace_permit( $current, $next );
	}

	private static function transition_terminal( array $permit, $state, $classification, array $reasons, array $extra = array() ) {
		$next = self::transition( $permit, array_merge( array(
			'state' => sanitize_key( (string) $state ),
			'classification' => (string) $classification,
			'classification_reasons' => array_values( array_unique( array_map( 'sanitize_key', $reasons ) ) ),
			'claimed' => false,
		), $extra ) );
		if ( ! is_wp_error( $next ) ) self::audit( $state, $next, array( 'mutation_performed' => false ) );
		return $next;
	}

	private static function execution_failed( array $permit, $reason, array $extra = array() ) {
		$blocked = self::transition_terminal( $permit, 'blocked', self::CLASS_HARD, array( sanitize_key( (string) $reason ) ), $extra );
		if ( is_wp_error( $blocked ) ) return $blocked;
		return new WP_Error( 'mad4b_post_update_continuation_execution_failed', 'Zero-delta candidate continuation failed closed and will not be retried automatically.', array(
			'reason' => sanitize_key( (string) $reason ),
			'permit_id' => isset( $permit['permit_id'] ) ? (string) $permit['permit_id'] : '',
		) );
	}

	private static function replace_permit( $expected, array $next ) {
		global $wpdb;
		$expected_exists = is_array( $expected ) && ! empty( $expected );
		if ( ! $expected_exists ) {
			$added = add_option( self::OPTION, $next, '', false );
			if ( ! $added ) return new WP_Error( 'mad4b_post_update_continuation_cas_conflict', 'Continuation permit was created concurrently.' );
			$stored = self::read_permit();
			return $stored === $next ? $stored : new WP_Error( 'mad4b_post_update_continuation_persist_readback_failed', 'New continuation permit did not read back exactly.' );
		}
		$old = maybe_serialize( $expected );
		$new = maybe_serialize( $next );
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			$new,
			self::OPTION,
			$old
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 !== (int) $updated ) return new WP_Error( 'mad4b_post_update_continuation_cas_conflict', 'Continuation permit changed concurrently.' );
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$stored = self::read_permit();
		if ( $stored !== $next || ! is_array( $stored ) || empty( $stored['permit_id'] ) || ! hash_equals( (string) $next['permit_id'], (string) $stored['permit_id'] )
			|| (int) $next['generation'] !== (int) ( isset( $stored['generation'] ) ? $stored['generation'] : -1 ) ) {
			return new WP_Error( 'mad4b_post_update_continuation_persist_readback_failed', 'Continuation permit CAS write did not read back exactly.' );
		}
		return $stored;
	}

	private static function read_permit() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		return is_string( $raw ) ? maybe_unserialize( $raw ) : array();
	}
	private static function permit_seal( array $permit ) { return hash_hmac( 'sha256', self::permit_digest( $permit ), wp_salt( 'auth' ) ); }
	private static function integrity_valid( array $permit ) {
		return ! empty( $permit['permit_digest'] ) && ! empty( $permit['permit_seal'] ) && hash_equals( (string) $permit['permit_digest'], self::permit_digest( $permit ) ) && hash_equals( (string) $permit['permit_seal'], self::permit_seal( $permit ) );
	}

	private static function permit_digest( array $permit ) {
		$copy = $permit;
		unset( $copy['permit_digest'], $copy['permit_seal'], $copy['active'], $copy['expired'], $copy['mutation_performed'] );
		return self::digest( $copy );
	}

	private static function audit( $event, array $permit, array $extra = array(), $join_transaction = false ) {
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) return new WP_Error( 'mad4b_post_update_continuation_audit_required', 'Continuation audit is unavailable.' );
		return MAD4B_SCP_Audit::record( 'mad4b/post-update-continuation-' . sanitize_key( (string) $event ), array_merge( array(
			'contract' => self::CONTRACT,
			'permit_id' => isset( $permit['permit_id'] ) ? (string) $permit['permit_id'] : '',
			'permit_digest' => isset( $permit['permit_digest'] ) ? (string) $permit['permit_digest'] : '',
			'generation' => isset( $permit['generation'] ) ? (int) $permit['generation'] : 0,
			'state' => isset( $permit['state'] ) ? sanitize_key( (string) $permit['state'] ) : '',
			'classification' => isset( $permit['classification'] ) ? (string) $permit['classification'] : '',
			'target_identity' => isset( $permit['target_identity'] ) ? $permit['target_identity'] : array(),
			'production_mutation' => false,
			'breakglass' => false,
		), $extra ), 'blocked' === ( isset( $permit['state'] ) ? (string) $permit['state'] : '' ) ? 'error' : 'ok', (bool) $join_transaction );
	}

	private static function digest( $value ) {
		$encoded = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}
