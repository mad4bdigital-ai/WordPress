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
	const CONTRACT = 'mad4b.post-update-continuation.v1';
	const OPTION = 'mad4b_scp_post_update_continuation_v1';
	const TTL = 900;
	const CLASS_ZERO = 'ZERO_DELTA_CONTINUATION';
	const CLASS_REVIEW = 'REVIEW_REQUIRED_DELTA';
	const CLASS_HARD = 'HARD_BLOCK_DELTA';

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
		$permit = get_option( self::OPTION, array() );
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

	public static function prepare( array $target, $channel, $update_plan_sha256 = '' ) {
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

		$profile_revision = MAD4B_SCP_Site_Profile::revision();
		$profile_digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
		$site_uuid = strtolower( (string) MAD4B_SCP_Site_Profile::site_uuid() );
		$origin = untrailingslashit( (string) MAD4B_SCP_Site_Profile::current_origin() );
		$actor = self::capture_actor();
		$transport = self::transport_snapshot();
		$write_snapshot = self::write_snapshot( $plan );
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

		$current = get_option( self::OPTION, array() );
		$current_generation = is_array( $current ) && isset( $current['generation'] ) ? absint( $current['generation'] ) : 0;
		if ( self::permit_active( $current ) ) return new WP_Error( 'mad4b_post_update_continuation_active_permit_exists', 'Another unconsumed post-update continuation is already active.' );

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
		$saved = self::replace_permit( $current, $permit );
		if ( is_wp_error( $saved ) ) return $saved;
		self::audit( 'prepared', $permit, array( 'mutation_performed' => false ) );
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

	public static function evaluate_and_rebind() {
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

		$result = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity(
			(string) $permit['target_identity']['source_commit_sha'],
			(string) $permit['target_identity']['build_fingerprint'],
			$context
		);
		if ( is_wp_error( $result ) ) return self::execution_failed( $permit, $result->get_error_code() );

		$post = self::classify_current_delta( $permit, true );
		$binding_after = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
		if ( self::CLASS_ZERO !== $post['classification'] || empty( $binding_after['match'] ) ) {
			return self::execution_failed( $permit, 'post_update_continuation_postcondition_failed', $post );
		}

		$consumed = self::transition( $permit, array(
			'state' => 'consumed',
			'consumed' => true,
			'consumed_at' => gmdate( 'c' ),
			'classification' => self::CLASS_ZERO,
			'candidate_binding_match' => true,
		) );
		if ( is_wp_error( $consumed ) ) return $consumed;
		self::audit( 'consumed', $consumed, array(
			'mutation_performed' => true,
			'binding_mutation_performed' => empty( $result['idempotent'] ),
			'grant_mutation_performed' => false,
			'production_mutation' => false,
		) );
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
		$permit = get_option( self::OPTION, array() );
		if ( ! is_array( $permit ) || self::CONTRACT !== ( isset( $permit['contract'] ) ? (string) $permit['contract'] : '' ) ) {
			return new WP_Error( 'mad4b_post_update_continuation_permit_missing', 'Post-update continuation permit is unavailable.' );
		}
		if ( 'executing' !== ( isset( $permit['state'] ) ? (string) $permit['state'] : '' ) || empty( $permit['claimed'] ) || ! empty( $permit['consumed'] ) ) {
			return new WP_Error( 'mad4b_post_update_continuation_not_claimed', 'Post-update continuation must be atomically claimed before candidate binding.' );
		}
		if ( ! self::permit_active( $permit, true ) ) return new WP_Error( 'mad4b_post_update_continuation_expired', 'Post-update continuation is expired or terminal.' );
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
		return true;
	}

	public static function cancel( $reason = 'cancelled', $target_identity = array() ) {
		$permit = get_option( self::OPTION, array() );
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

		$current_identity = self::current_identity();
		if ( ! self::identity_matches( $permit['target_identity'], $current_identity ) ) $hard[] = 'package_identity_mismatch';
		if ( ! self::release_trusted( isset( $permit['release'] ) ? $permit['release'] : array() ) ) $hard[] = 'release_trust_invalid';

		$site = isset( $permit['site'] ) && is_array( $permit['site'] ) ? $permit['site'] : array();
		$current_revision = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::revision() : 0;
		$current_digest = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() ) : '';
		$current_origin = class_exists( 'MAD4B_SCP_Site_Profile' ) ? untrailingslashit( (string) MAD4B_SCP_Site_Profile::current_origin() ) : '';
		if ( (int) ( isset( $site['profile_revision'] ) ? $site['profile_revision'] : -1 ) !== (int) $current_revision
			|| empty( $site['profile_digest'] ) || ! hash_equals( (string) $site['profile_digest'], $current_digest )
			|| empty( $site['origin'] ) || ! hash_equals( (string) $site['origin'], $current_origin ) ) $review[] = 'site_profile_changed';

		$transport = self::transport_snapshot();
		$pre_transport = isset( $permit['transport_snapshot']['fingerprint'] ) ? (string) $permit['transport_snapshot']['fingerprint'] : '';
		if ( '' === $pre_transport || empty( $transport['fingerprint'] ) || ! hash_equals( $pre_transport, (string) $transport['fingerprint'] ) ) $review[] = 'transport_inventory_changed';

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
			if ( ! empty( $post['exact_grants_missing_count'] ) ) $review[] = 'exact_grants_changed';
			if ( empty( $post['grant_rows_fingerprint'] ) || empty( $pre['grant_rows_fingerprint'] )
				|| ! hash_equals( (string) $pre['grant_rows_fingerprint'], (string) $post['grant_rows_fingerprint'] ) ) {
				// Clean shape + changed row fingerprint means a bounded authority
				// delta and requires owner review. An unexplained same-shape TOCTOU
				// after binding claim is treated as hard.
				if ( $post_bind || ( empty( $post['exact_grants_missing_count'] ) && empty( $review ) ) ) $hard[] = 'grant_rows_fingerprint_changed';
				else $review[] = 'grant_rows_fingerprint_changed';
			}
		}
		if ( empty( $permit['actor']['oauth_attribution_complete'] ) ) $review[] = 'oauth_actor_attribution_unavailable';

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
		$registration = class_exists( 'MAD4B_SCP_Servers' ) && method_exists( 'MAD4B_SCP_Servers', 'registration_status' ) ? MAD4B_SCP_Servers::registration_status() : array();
		$rows = array();
		foreach ( $ids as $id ) {
			$id = sanitize_key( (string) $id );
			$row = isset( $registration[ $id ] ) && is_array( $registration[ $id ] ) ? $registration[ $id ] : array();
			$rows[] = array(
				'server_id' => $id,
				'registered' => ! empty( $row['registered'] ),
				'error' => isset( $row['error'] ) ? sanitize_key( (string) $row['error'] ) : '',
			);
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['server_id'], $b['server_id'] ); } );
		$snapshot = array(
			'adapter_version' => defined( 'WP_MCP_VERSION' ) ? (string) WP_MCP_VERSION : '',
			'servers' => $rows,
			'connection_fingerprint' => class_exists( 'MAD4B_SCP_Connection_Identity_Resolver' ) ? MAD4B_SCP_Connection_Identity_Resolver::fingerprint() : '',
		);
		$snapshot['fingerprint'] = self::digest( $snapshot );
		return $snapshot;
	}

	private static function write_snapshot( array $plan ) {
		$out = array(
			'write_tool_count' => isset( $plan['write_tool_count'] ) ? (int) $plan['write_tool_count'] : 0,
			'exact_grants_existing' => isset( $plan['exact_grants_existing'] ) ? (int) $plan['exact_grants_existing'] : 0,
			'write_inventory_fingerprint' => isset( $plan['write_inventory_fingerprint'] ) ? strtolower( (string) $plan['write_inventory_fingerprint'] ) : '',
			'grant_rows_fingerprint' => isset( $plan['grant_rows_fingerprint'] ) ? strtolower( (string) $plan['grant_rows_fingerprint'] ) : '',
			'breakglass_included' => ! empty( $plan['breakglass_included'] ),
		);
		foreach ( self::clean_fields() as $field ) $out[ $field ] = isset( $plan[ $field ] ) ? (int) $plan[ $field ] : 0;
		return $out;
	}

	private static function snapshot_clean( array $snapshot ) {
		if ( empty( $snapshot['write_tool_count'] ) || empty( $snapshot['write_inventory_fingerprint'] ) || empty( $snapshot['grant_rows_fingerprint'] ) || ! empty( $snapshot['breakglass_included'] ) ) return false;
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
		$permit = get_option( self::OPTION, array() );
		if ( ! self::permit_active( $permit ) ) {
			$state = is_array( $permit ) && isset( $permit['state'] ) ? sanitize_key( (string) $permit['state'] ) : 'absent';
			$code = ! empty( $permit['consumed'] ) ? 'mad4b_post_update_continuation_replay_denied' : 'mad4b_post_update_continuation_unavailable';
			return new WP_Error( $code, 'No active one-time post-update continuation permit is available.', array( 'state' => $state ) );
		}
		if ( empty( $permit['permit_digest'] ) || ! hash_equals( (string) $permit['permit_digest'], self::permit_digest( $permit ) ) ) {
			return new WP_Error( 'mad4b_post_update_continuation_integrity_failed', 'Continuation permit integrity check failed.' );
		}
		return $permit;
	}

	private static function transition( array $permit, array $changes ) {
		$current = get_option( self::OPTION, array() );
		if ( ! is_array( $current ) || empty( $current['permit_id'] ) || ! hash_equals( (string) $permit['permit_id'], (string) $current['permit_id'] )
			|| (int) $permit['generation'] !== (int) ( isset( $current['generation'] ) ? $current['generation'] : -1 ) ) {
			return new WP_Error( 'mad4b_post_update_continuation_generation_conflict', 'Continuation permit generation changed concurrently.' );
		}
		$next = array_merge( $current, $changes );
		$next['generation'] = (int) $current['generation'] + 1;
		$next['updated_at'] = gmdate( 'c' );
		$next['permit_digest'] = self::permit_digest( $next );
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
			return get_option( self::OPTION, $next );
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
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored['permit_id'] ) || ! hash_equals( (string) $next['permit_id'], (string) $stored['permit_id'] )
			|| (int) $next['generation'] !== (int) ( isset( $stored['generation'] ) ? $stored['generation'] : -1 ) ) {
			return new WP_Error( 'mad4b_post_update_continuation_persist_readback_failed', 'Continuation permit CAS write did not read back exactly.' );
		}
		return $stored;
	}

	private static function permit_digest( array $permit ) {
		$copy = $permit;
		unset( $copy['permit_digest'], $copy['active'], $copy['expired'], $copy['mutation_performed'] );
		return self::digest( $copy );
	}

	private static function audit( $event, array $permit, array $extra = array() ) {
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) return;
		MAD4B_SCP_Audit::record( 'mad4b/post-update-continuation-' . sanitize_key( (string) $event ), array_merge( array(
			'contract' => self::CONTRACT,
			'permit_id' => isset( $permit['permit_id'] ) ? (string) $permit['permit_id'] : '',
			'permit_digest' => isset( $permit['permit_digest'] ) ? (string) $permit['permit_digest'] : '',
			'generation' => isset( $permit['generation'] ) ? (int) $permit['generation'] : 0,
			'state' => isset( $permit['state'] ) ? sanitize_key( (string) $permit['state'] ) : '',
			'classification' => isset( $permit['classification'] ) ? (string) $permit['classification'] : '',
			'target_identity' => isset( $permit['target_identity'] ) ? $permit['target_identity'] : array(),
			'production_mutation' => false,
			'breakglass' => false,
		), $extra ), 'blocked' === ( isset( $permit['state'] ) ? (string) $permit['state'] : '' ) ? 'error' : 'ok' );
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
