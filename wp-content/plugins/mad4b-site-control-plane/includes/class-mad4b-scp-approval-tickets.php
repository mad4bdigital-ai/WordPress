<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * One-time approval tickets with exact payload and tenant/build bindings.
 *
 * Normal remote governed-write approvals are bound to four independent axes:
 * operation payload, enabled NHI, exact deployed build, and exact Site Profile
 * identity/revision/digest. A clone, environment/origin drift, profile policy
 * edit, deployment change, expiry, claim, or replay therefore fails closed.
 * Breakglass remains a separate authority and is not made dependent on normal
 * Site Profile write enrollment by this class.
 */
final class MAD4B_SCP_Approval_Tickets {
	const MAX_CANONICAL_BYTES = 65536;
	const MAX_DEPTH = 8;
	const DEFAULT_TTL = 600;
	const MAX_TTL = 3600;
	const CANDIDATE_BINDING_CONTRACT = 'mad4b.approval-candidate-binding.v2';
	const LEGACY_CANDIDATE_BINDING_CONTRACT = 'mad4b.approval-candidate-binding.v1';
	const CANDIDATE_BINDINGS_OPTION = 'mad4b_scp_approval_candidate_bindings_v1';
	const MAX_CANDIDATE_BINDINGS = 100;

	public static function canonical_payload_hash( $agent_public_id, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class = 'mutation' ) {
		$server_id = sanitize_key( (string) $server_id );
		$ticket_class = sanitize_key( (string) $ticket_class );
		$site = function_exists( 'site_url' ) ? site_url() : '';
		$profile_binding = array();
		if ( self::is_governed_remote_mutation( $ticket_class, $server_id ) ) {
			$profile_binding = self::profile_snapshot( false );
			if ( ! empty( $profile_binding['origin'] ) ) $site = $profile_binding['origin'];
		}
		$envelope = array(
			'contract' => 'mad4b.approval.v1',
			'site' => $site,
			'agent_public_id' => (string) $agent_public_id,
			'server_id' => $server_id,
			'ability' => (string) $ability_name,
			'provider' => sanitize_key( (string) $provider ),
			'target' => (string) $target_fingerprint,
			'ticket_class' => $ticket_class,
			'input' => $input,
		);
		if ( ! empty( $profile_binding ) ) {
			$envelope['site_profile_binding'] = array(
				'site_uuid' => $profile_binding['site_uuid'],
				'profile_revision' => $profile_binding['profile_revision'],
				'profile_digest' => $profile_binding['profile_digest'],
				'environment' => $profile_binding['environment'],
				'origin' => $profile_binding['origin'],
			);
		}
		$canonical = self::canonical_json( $envelope );
		if ( is_wp_error( $canonical ) ) return $canonical;
		return hash( 'sha256', $canonical );
	}

	public static function create_pending( $agent_public_id, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class, $reason, $ttl = self::DEFAULT_TTL ) {
		global $wpdb;
		$schema = self::require_critical_schema();
		if ( is_wp_error( $schema ) ) return $schema;

		$server_id = sanitize_key( (string) $server_id );
		$ticket_class = sanitize_key( (string) $ticket_class );
		if ( ! self::can_plan_ticket( $ticket_class, $server_id ) ) return new WP_Error( 'mad4b_approval_planner_required', 'Current WordPress user is not allowed to create this approval plan.' );
		$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $agent_public_id );
		if ( ! $agent || 'enabled' !== (string) $agent['status'] ) return new WP_Error( 'mad4b_approval_agent_invalid', 'Approval requires an enabled MAD4B agent.' );
		if ( ! in_array( $ticket_class, array( 'mutation', 'breakglass', 'recovery' ), true ) ) return new WP_Error( 'mad4b_approval_class_invalid', 'Unknown approval ticket class.' );

		$profile_binding = array();
		if ( self::is_governed_remote_mutation( $ticket_class, $server_id ) ) {
			$profile_binding = self::profile_snapshot( true );
			if ( is_wp_error( $profile_binding ) ) return $profile_binding;
		}

		$reason = trim( sanitize_text_field( $reason ) );
		if ( strlen( $reason ) < 3 || strlen( $reason ) > 500 ) return new WP_Error( 'mad4b_approval_reason_invalid', 'Approval reason must be between 3 and 500 characters.' );
		$ttl = max( 60, min( self::MAX_TTL, absint( $ttl ) ) );
		$payload_hash = self::canonical_payload_hash( $agent_public_id, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class );
		if ( is_wp_error( $payload_hash ) ) return $payload_hash;

		$t = MAD4B_SCP_Schema::tables();
		$now = time();
		$ticket_id = wp_generate_uuid4();
		$data = array(
			'ticket_id' => $ticket_id,
			'ticket_class' => $ticket_class,
			'agent_id' => (int) $agent['id'],
			'server_id' => $server_id,
			'ability_name' => (string) $ability_name,
			'provider' => sanitize_key( $provider ) ?: 'core',
			'target_fingerprint' => substr( (string) $target_fingerprint, 0, 191 ),
			'payload_sha256' => $payload_hash,
			'status' => 'pending',
			'reason' => $reason,
			'approved_by' => 0,
			'approved_at' => null,
			'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $ttl ),
			'used_at' => null,
			'candidate_binding_contract' => '',
			'candidate_sha' => '',
			'build_fingerprint' => '',
			'binding_environment' => ! empty( $profile_binding['environment'] ) ? $profile_binding['environment'] : '',
			'binding_host' => ! empty( $profile_binding['host'] ) ? $profile_binding['host'] : '',
			'site_uuid' => ! empty( $profile_binding['site_uuid'] ) ? $profile_binding['site_uuid'] : '',
			'site_profile_revision' => ! empty( $profile_binding['profile_revision'] ) ? (int) $profile_binding['profile_revision'] : 0,
			'site_profile_digest' => ! empty( $profile_binding['profile_digest'] ) ? $profile_binding['profile_digest'] : '',
			'bound_at' => null,
			'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
		);
		$formats = array( '%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s' );
		$ok = $wpdb->insert( $t['approvals'], $data, $formats );
		if ( false === $ok ) return new WP_Error( 'mad4b_approval_create_failed', 'Approval ticket could not be created.', array( 'db_error' => $wpdb->last_error ) );
		return self::get( $ticket_id );
	}

	public static function bind_ticket_to_current_candidate( $ticket_id ) {
		global $wpdb;
		$schema = self::require_critical_schema();
		if ( is_wp_error( $schema ) ) return $schema;
		$ticket_id = strtolower( trim( (string) $ticket_id ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) ) return new WP_Error( 'mad4b_approval_candidate_ticket_invalid', 'Candidate binding requires an exact approval ticket id.' );
		$profile = self::profile_snapshot( true );
		if ( is_wp_error( $profile ) ) return $profile;
		$ticket = self::get( $ticket_id );
		if ( ! is_array( $ticket ) ) return new WP_Error( 'mad4b_approval_missing', 'Approval ticket is missing.' );
		if ( 'pending' !== (string) $ticket['status'] || 'mutation' !== (string) $ticket['ticket_class'] || 'mad4b-write' !== sanitize_key( (string) $ticket['server_id'] ) ) return new WP_Error( 'mad4b_approval_candidate_ticket_ineligible', 'Only fresh pending mad4b-write mutation tickets may receive live candidate binding evidence.' );
		$payload_hash = isset( $ticket['payload_sha256'] ) ? strtolower( trim( (string) $ticket['payload_sha256'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $payload_hash ) ) return new WP_Error( 'mad4b_approval_candidate_payload_invalid', 'Approval ticket payload digest is invalid.' );

		$prebound = self::profile_binding_from_ticket( $ticket );
		if ( empty( $prebound ) || ! self::profile_bindings_equal( $prebound, $profile ) ) {
			$t = MAD4B_SCP_Schema::tables();
			$wpdb->delete( $t['approvals'], array( 'ticket_id' => $ticket_id, 'status' => 'pending' ), array( '%s', '%s' ) );
			return new WP_Error( 'mad4b_approval_site_profile_mismatch', 'Pending ticket Site Profile changed before candidate binding; the ticket was discarded.' );
		}

		$binding = self::current_candidate_binding( $ticket_id, $payload_hash );
		if ( is_wp_error( $binding ) || ! self::save_candidate_binding( $binding ) ) {
			$t = MAD4B_SCP_Schema::tables();
			$wpdb->delete( $t['approvals'], array( 'ticket_id' => $ticket_id, 'status' => 'pending' ), array( '%s', '%s' ) );
			return is_wp_error( $binding ) ? $binding : new WP_Error( 'mad4b_approval_candidate_binding_failed', 'Pending remote approval ticket could not be bound durably to the exact site/build candidate.' );
		}
		return $binding;
	}

	public static function decide_pending( $ticket_id, $decision, $expected_payload_sha256, array $context = array() ) {
		global $wpdb;
		$schema = self::require_critical_schema();
		if ( is_wp_error( $schema ) ) return $schema;
		if ( ! self::can_approve() ) return new WP_Error( 'mad4b_approval_admin_required', 'Approval capability is required to decide a ticket.' );
		$ticket_id = strtolower( trim( (string) $ticket_id ) );
		$decision = sanitize_key( (string) $decision );
		$expected_payload_sha256 = strtolower( trim( (string) $expected_payload_sha256 ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) ) return new WP_Error( 'mad4b_approval_decision_ticket_invalid', 'Approval ticket id is invalid.' );
		if ( ! in_array( $decision, array( 'approve', 'reject' ), true ) ) return new WP_Error( 'mad4b_approval_decision_invalid', 'Decision must be approve or reject.' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_payload_sha256 ) ) return new WP_Error( 'mad4b_approval_payload_mismatch', 'Expected approval payload digest is invalid.' );
		$ticket = self::get( $ticket_id );
		if ( ! $ticket ) return new WP_Error( 'mad4b_approval_missing', 'Approval ticket is missing.' );
		if ( 'pending' !== (string) $ticket['status'] ) return new WP_Error( 'mad4b_approval_not_approvable', 'Approval ticket is no longer pending.' );
		if ( strtotime( $ticket['expires_at'] . ' UTC' ) < time() ) return new WP_Error( 'mad4b_approval_expired', 'Approval ticket has expired.' );
		if ( empty( $ticket['payload_sha256'] ) || ! hash_equals( strtolower( (string) $ticket['payload_sha256'] ), $expected_payload_sha256 ) ) return new WP_Error( 'mad4b_approval_payload_mismatch', 'Approval ticket payload changed or does not match the reviewed operation.' );
		if ( 'approve' === $decision && self::is_governed_remote_mutation( $ticket['ticket_class'], $ticket['server_id'] ) ) {
			$fresh = self::validate_ticket_candidate_binding( $ticket, $expected_payload_sha256 );
			if ( is_wp_error( $fresh ) ) return $fresh;
		}

		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( 'approve' === $decision ) {
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['approvals']} SET status = 'approved', approved_by = %d, approved_at = %s WHERE id = %d AND ticket_id = %s AND status = 'pending' AND payload_sha256 = %s AND expires_at >= %s", get_current_user_id(), $now, (int) $ticket['id'], $ticket_id, $expected_payload_sha256, $now ) );
		} else {
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['approvals']} SET status = 'revoked' WHERE id = %d AND ticket_id = %s AND status = 'pending' AND payload_sha256 = %s AND expires_at >= %s", (int) $ticket['id'], $ticket_id, $expected_payload_sha256, $now ) );
		}
		if ( 1 !== (int) $updated ) return new WP_Error( 'mad4b_approval_decision_conflict', 'Approval ticket changed, expired, or was decided concurrently.' );
		$summary = array(
			'ticket_id' => $ticket_id,
			'decision' => $decision,
			'result_status' => 'approve' === $decision ? 'approved' : 'revoked',
			'payload_sha256' => $expected_payload_sha256,
			'ticket_class' => isset( $ticket['ticket_class'] ) ? (string) $ticket['ticket_class'] : '',
			'server_id' => isset( $ticket['server_id'] ) ? (string) $ticket['server_id'] : '',
			'ability' => isset( $ticket['ability_name'] ) ? (string) $ticket['ability_name'] : '',
			'provider' => isset( $ticket['provider'] ) ? (string) $ticket['provider'] : '',
			'approver_user_id' => get_current_user_id(),
			'candidate_sha' => isset( $ticket['candidate_sha'] ) ? strtolower( (string) $ticket['candidate_sha'] ) : '',
			'build_fingerprint' => isset( $ticket['build_fingerprint'] ) ? strtolower( (string) $ticket['build_fingerprint'] ) : '',
			'site_uuid' => isset( $ticket['site_uuid'] ) ? strtolower( (string) $ticket['site_uuid'] ) : '',
			'site_profile_revision' => isset( $ticket['site_profile_revision'] ) ? (int) $ticket['site_profile_revision'] : 0,
			'site_profile_digest' => isset( $ticket['site_profile_digest'] ) ? strtolower( (string) $ticket['site_profile_digest'] ) : '',
			'decision_contract' => isset( $context['contract'] ) ? (string) $context['contract'] : '',
		);
		MAD4B_SCP_Audit::record( 'mad4b/approval-decision', $summary, 'ok' );
		return self::get( $ticket_id );
	}

	public static function approve( $ticket_id ) {
		global $wpdb;
		$schema = self::require_critical_schema();
		if ( is_wp_error( $schema ) ) return $schema;
		if ( ! self::can_approve() ) return new WP_Error( 'mad4b_approval_admin_required', 'Approval capability is required to approve a ticket.' );
		$ticket = self::get( $ticket_id );
		if ( ! $ticket || 'pending' !== (string) $ticket['status'] ) return new WP_Error( 'mad4b_approval_not_approvable', 'Approval ticket is missing, expired, or no longer pending.' );
		if ( self::is_governed_remote_mutation( $ticket['ticket_class'], $ticket['server_id'] ) ) {
			$fresh = self::validate_ticket_candidate_binding( $ticket, (string) $ticket['payload_sha256'] );
			if ( is_wp_error( $fresh ) ) return $fresh;
		}
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['approvals']} SET status = 'approved', approved_by = %d, approved_at = %s WHERE ticket_id = %s AND status = 'pending' AND expires_at >= %s", get_current_user_id(), $now, (string) $ticket_id, $now ) );
		if ( 1 !== (int) $updated ) return new WP_Error( 'mad4b_approval_not_approvable', 'Approval ticket is missing, expired, stale, or no longer pending.' );
		MAD4B_SCP_Audit::record( 'mad4b/approval-approved', array( 'ticket_id' => $ticket_id ), 'ok' );
		return self::get( $ticket_id );
	}

	public static function revoke( $ticket_id ) {
		global $wpdb;
		$schema = self::require_critical_schema();
		if ( is_wp_error( $schema ) ) return $schema;
		if ( ! self::can_approve() ) return new WP_Error( 'mad4b_approval_admin_required', 'Approval capability is required to revoke a ticket.' );
		$t = MAD4B_SCP_Schema::tables();
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['approvals']} SET status = 'revoked' WHERE ticket_id = %s AND status IN ('pending','approved')", (string) $ticket_id ) );
		if ( 1 !== (int) $updated ) return new WP_Error( 'mad4b_approval_not_revokable', 'Approval ticket is missing or cannot be revoked.' );
		MAD4B_SCP_Audit::record( 'mad4b/approval-revoked', array( 'ticket_id' => $ticket_id ), 'ok' );
		return true;
	}

	public static function validate_exact( $ticket_id, array $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class ) {
		$schema = self::require_critical_schema();
		if ( is_wp_error( $schema ) ) return $schema;
		$ticket = self::get( $ticket_id );
		if ( ! $ticket ) return new WP_Error( 'mad4b_approval_missing', 'Approval ticket is missing.' );
		$status = isset( $ticket['status'] ) ? (string) $ticket['status'] : '';
		if ( in_array( $status, array( 'used', 'executing', 'failed' ), true ) ) return new WP_Error( 'mad4b_approval_replay_denied', 'Approval ticket is terminal or already claimed; replay is denied.' );
		if ( 'approved' !== $status ) return new WP_Error( 'mad4b_approval_not_approved', 'Approval ticket is not approved.' );
		if ( strtotime( $ticket['expires_at'] . ' UTC' ) < time() ) return new WP_Error( 'mad4b_approval_expired', 'Approval ticket has expired.' );
		if ( (int) $ticket['agent_id'] !== (int) $agent['id'] ) return new WP_Error( 'mad4b_approval_agent_mismatch', 'Approval ticket belongs to another agent.' );
		if ( sanitize_key( (string) $ticket['server_id'] ) !== sanitize_key( (string) $server_id ) ) return new WP_Error( 'mad4b_approval_server_mismatch', 'Approval ticket server does not match this operation.' );
		if ( (string) $ticket['ability_name'] !== (string) $ability_name ) return new WP_Error( 'mad4b_approval_ability_mismatch', 'Approval ticket ability does not match this operation.' );
		if ( sanitize_key( (string) $ticket['provider'] ) !== sanitize_key( (string) $provider ) ) return new WP_Error( 'mad4b_approval_provider_mismatch', 'Approval ticket provider does not match this operation.' );
		if ( (string) $ticket['target_fingerprint'] !== (string) $target_fingerprint ) return new WP_Error( 'mad4b_approval_target_mismatch', 'Approval ticket target does not match this operation.' );
		if ( (string) $ticket['ticket_class'] !== (string) $ticket_class ) return new WP_Error( 'mad4b_approval_class_mismatch', 'Approval ticket class does not match this operation.' );
		$hash = self::canonical_payload_hash( $agent['public_id'], $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class );
		if ( is_wp_error( $hash ) ) return $hash;
		if ( ! hash_equals( (string) $ticket['payload_sha256'], (string) $hash ) ) return new WP_Error( 'mad4b_approval_payload_mismatch', 'Approval ticket is not bound to this exact operation/Site Profile.' );
		if ( self::is_governed_remote_mutation( $ticket_class, $server_id ) ) {
			$fresh = self::validate_ticket_candidate_binding( $ticket, $hash );
			if ( is_wp_error( $fresh ) ) return $fresh;
		}
		return array( 'ticket' => $ticket, 'payload_sha256' => $hash );
	}

	public static function authorize_exact( $ticket_id, array $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class ) {
		return self::validate_exact( $ticket_id, $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class );
	}

	public static function claim_exact( $ticket_id, array $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class ) {
		global $wpdb;
		$validated = self::validate_exact( $ticket_id, $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class );
		if ( is_wp_error( $validated ) ) return $validated;
		$ticket = $validated['ticket'];
		$hash = $validated['payload_sha256'];
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['approvals']} SET status = 'executing' WHERE id = %d AND status = 'approved' AND payload_sha256 = %s AND expires_at >= %s", (int) $ticket['id'], $hash, $now ) );
		if ( 1 !== (int) $updated ) return new WP_Error( 'mad4b_approval_replay_denied', 'Approval ticket was already claimed, expired, or changed.' );
		MAD4B_SCP_Audit::record( 'mad4b/approval-claimed', array( 'ticket_id' => $ticket_id, 'ability' => $ability_name, 'agent_public_id' => $agent['public_id'] ), 'ok' );
		$ticket['status'] = 'executing';
		return $ticket;
	}

	public static function finalize_claim( $ticket_id, $terminal_status ) {
		global $wpdb;
		$schema = self::require_critical_schema();
		if ( is_wp_error( $schema ) ) return $schema;
		$terminal_status = sanitize_key( (string) $terminal_status );
		if ( ! in_array( $terminal_status, array( 'used', 'failed' ), true ) ) return new WP_Error( 'mad4b_approval_finalize_status_invalid', 'Approval ticket final status must be used or failed.' );
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( 'used' === $terminal_status ) $updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['approvals']} SET status = 'used', used_at = %s WHERE ticket_id = %s AND status = 'executing'", $now, (string) $ticket_id ) );
		else $updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['approvals']} SET status = 'failed' WHERE ticket_id = %s AND status = 'executing'", (string) $ticket_id ) );
		if ( 1 !== (int) $updated ) return new WP_Error( 'mad4b_approval_finalize_conflict', 'Approval ticket is not in the expected executing state.' );
		$event = 'used' === $terminal_status ? 'mad4b/approval-consumed' : 'mad4b/approval-execution-failed';
		MAD4B_SCP_Audit::record( $event, array( 'ticket_id' => $ticket_id, 'result_status' => $terminal_status ), 'used' === $terminal_status ? 'ok' : 'failed' );
		return self::get( $ticket_id );
	}

	public static function consume_exact( $ticket_id, array $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class ) {
		$claim = self::claim_exact( $ticket_id, $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class );
		if ( is_wp_error( $claim ) ) return $claim;
		$final = self::finalize_claim( $ticket_id, 'used' );
		return is_wp_error( $final ) ? $final : $claim;
	}

	public static function get( $ticket_id ) {
		global $wpdb; $t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['approvals']} WHERE ticket_id = %s LIMIT 1", (string) $ticket_id ), ARRAY_A );
		return $row ? $row : null;
	}

	public static function candidate_binding( $ticket_id ) {
		$ticket_id = strtolower( trim( (string) $ticket_id ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) ) return array();
		$ticket = self::get( $ticket_id );
		if ( is_array( $ticket ) ) {
			$binding = self::candidate_binding_from_ticket( $ticket );
			if ( ! empty( $binding ) ) return $binding;
		}
		if ( (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ) < MAD4B_SCP_Schema::VERSION ) return self::legacy_candidate_binding( $ticket_id );
		return array();
	}

	public static function candidate_binding_from_ticket( array $ticket ) {
		$ticket_id = isset( $ticket['ticket_id'] ) ? strtolower( trim( (string) $ticket['ticket_id'] ) ) : '';
		$payload = isset( $ticket['payload_sha256'] ) ? strtolower( trim( (string) $ticket['payload_sha256'] ) ) : '';
		$sha = isset( $ticket['candidate_sha'] ) ? strtolower( trim( (string) $ticket['candidate_sha'] ) ) : '';
		$build = isset( $ticket['build_fingerprint'] ) ? strtolower( trim( (string) $ticket['build_fingerprint'] ) ) : '';
		$contract = isset( $ticket['candidate_binding_contract'] ) ? (string) $ticket['candidate_binding_contract'] : '';
		$site_uuid = isset( $ticket['site_uuid'] ) ? strtolower( trim( (string) $ticket['site_uuid'] ) ) : '';
		$profile_revision = isset( $ticket['site_profile_revision'] ) ? absint( $ticket['site_profile_revision'] ) : 0;
		$profile_digest = isset( $ticket['site_profile_digest'] ) ? strtolower( trim( (string) $ticket['site_profile_digest'] ) ) : '';
		$environment = isset( $ticket['binding_environment'] ) ? sanitize_key( (string) $ticket['binding_environment'] ) : '';
		$host = isset( $ticket['binding_host'] ) ? strtolower( rtrim( (string) $ticket['binding_host'], '.' ) ) : '';
		if ( self::CANDIDATE_BINDING_CONTRACT !== $contract || ! preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) || ! preg_match( '/^[a-f0-9]{64}$/', $payload ) || ! preg_match( '/^[a-f0-9]{40}$/', $sha ) || ! preg_match( '/^[a-f0-9]{64}$/', $build ) || ! preg_match( '/^[a-f0-9-]{36}$/', $site_uuid ) || $profile_revision < 1 || ! preg_match( '/^[a-f0-9]{64}$/', $profile_digest ) || '' === $environment || '' === $host ) return array();
		$bound_at = ! empty( $ticket['bound_at'] ) ? strtotime( (string) $ticket['bound_at'] . ' UTC' ) : false;
		return array(
			'contract' => $contract,
			'ticket_id' => $ticket_id,
			'payload_sha256' => $payload,
			'candidate_sha' => $sha,
			'build_fingerprint' => $build,
			'site_uuid' => $site_uuid,
			'profile_revision' => $profile_revision,
			'profile_digest' => $profile_digest,
			'environment' => $environment,
			'host' => $host,
			'bound_at' => false === $bound_at ? '' : gmdate( 'c', $bound_at ),
		);
	}

	private static function current_candidate_binding( $ticket_id, $payload_hash ) {
		$profile = self::profile_snapshot( true );
		if ( is_wp_error( $profile ) ) return $profile;
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_approval_candidate_unavailable', 'Exact build provenance is unavailable for remote approval planning.' );
		$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		$sha = is_array( $provenance ) && isset( $provenance['source_commit_sha'] ) ? strtolower( trim( (string) $provenance['source_commit_sha'] ) ) : '';
		$fingerprint = is_array( $provenance ) && isset( $provenance['build_fingerprint'] ) ? strtolower( trim( (string) $provenance['build_fingerprint'] ) ) : '';
		if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) || ! preg_match( '/^[a-f0-9]{40}$/', $sha ) || ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) return new WP_Error( 'mad4b_approval_candidate_unavailable', 'Remote approval planning requires exact current build provenance.' );
		return array(
			'contract' => self::CANDIDATE_BINDING_CONTRACT,
			'ticket_id' => strtolower( (string) $ticket_id ),
			'payload_sha256' => strtolower( (string) $payload_hash ),
			'candidate_sha' => $sha,
			'build_fingerprint' => $fingerprint,
			'site_uuid' => $profile['site_uuid'],
			'profile_revision' => $profile['profile_revision'],
			'profile_digest' => $profile['profile_digest'],
			'environment' => $profile['environment'],
			'host' => $profile['host'],
			'origin' => $profile['origin'],
			'bound_at' => gmdate( 'c' ),
		);
	}

	private static function save_candidate_binding( array $binding ) {
		global $wpdb;
		if ( empty( $binding['ticket_id'] ) || ! MAD4B_SCP_Schema::critical_ready() ) return false;
		$t = MAD4B_SCP_Schema::tables();
		$bound_at = ! empty( $binding['bound_at'] ) ? strtotime( (string) $binding['bound_at'] ) : false;
		$updated = $wpdb->update(
			$t['approvals'],
			array(
				'candidate_binding_contract' => self::CANDIDATE_BINDING_CONTRACT,
				'candidate_sha' => isset( $binding['candidate_sha'] ) ? strtolower( (string) $binding['candidate_sha'] ) : '',
				'build_fingerprint' => isset( $binding['build_fingerprint'] ) ? strtolower( (string) $binding['build_fingerprint'] ) : '',
				'binding_environment' => isset( $binding['environment'] ) ? sanitize_key( (string) $binding['environment'] ) : '',
				'binding_host' => isset( $binding['host'] ) ? strtolower( rtrim( (string) $binding['host'], '.' ) ) : '',
				'site_uuid' => isset( $binding['site_uuid'] ) ? strtolower( (string) $binding['site_uuid'] ) : '',
				'site_profile_revision' => isset( $binding['profile_revision'] ) ? absint( $binding['profile_revision'] ) : 0,
				'site_profile_digest' => isset( $binding['profile_digest'] ) ? strtolower( (string) $binding['profile_digest'] ) : '',
				'bound_at' => false === $bound_at ? gmdate( 'Y-m-d H:i:s' ) : gmdate( 'Y-m-d H:i:s', $bound_at ),
			),
			array( 'ticket_id' => (string) $binding['ticket_id'], 'status' => 'pending' ),
			array( '%s','%s','%s','%s','%s','%s','%d','%s','%s' ),
			array( '%s','%s' )
		);
		return 1 === (int) $updated;
	}

	private static function validate_ticket_candidate_binding( array $ticket, $payload_hash ) {
		$saved = self::candidate_binding_from_ticket( $ticket );
		if ( empty( $saved ) ) return new WP_Error( 'mad4b_approval_candidate_binding_missing', 'Remote governed-write ticket is missing v2 Site Profile/build binding evidence.' );
		$current = self::current_candidate_binding( $ticket['ticket_id'], $payload_hash );
		if ( is_wp_error( $current ) ) return $current;
		foreach ( array( 'ticket_id', 'payload_sha256', 'candidate_sha', 'build_fingerprint', 'site_uuid', 'profile_revision', 'profile_digest', 'environment', 'host' ) as $key ) {
			if ( ! isset( $saved[ $key ], $current[ $key ] ) || ! hash_equals( (string) $saved[ $key ], (string) $current[ $key ] ) ) return new WP_Error( 'mad4b_approval_candidate_mismatch', 'Approval ticket no longer matches the exact live site/profile/build candidate.' );
		}
		return true;
	}

	private static function profile_binding_from_ticket( array $ticket ) {
		$binding = array(
			'site_uuid' => isset( $ticket['site_uuid'] ) ? strtolower( trim( (string) $ticket['site_uuid'] ) ) : '',
			'profile_revision' => isset( $ticket['site_profile_revision'] ) ? absint( $ticket['site_profile_revision'] ) : 0,
			'profile_digest' => isset( $ticket['site_profile_digest'] ) ? strtolower( trim( (string) $ticket['site_profile_digest'] ) ) : '',
			'environment' => isset( $ticket['binding_environment'] ) ? sanitize_key( (string) $ticket['binding_environment'] ) : '',
			'host' => isset( $ticket['binding_host'] ) ? strtolower( rtrim( (string) $ticket['binding_host'], '.' ) ) : '',
		);
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $binding['site_uuid'] ) || $binding['profile_revision'] < 1 || ! preg_match( '/^[a-f0-9]{64}$/', $binding['profile_digest'] ) || '' === $binding['environment'] || '' === $binding['host'] ) return array();
		return $binding;
	}

	private static function profile_snapshot( $require_write ) {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return $require_write ? new WP_Error( 'mad4b_approval_site_profile_unconfigured', 'Governed remote approval requires an enrolled Site Profile.' ) : array();
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() ) return $require_write ? new WP_Error( 'mad4b_approval_site_profile_drift', 'Governed remote approval requires the exact enrolled origin/environment.' ) : array();
		if ( $require_write && ! MAD4B_SCP_Site_Profile::write_enabled() ) return new WP_Error( 'mad4b_approval_site_profile_write_disabled', 'Governed remote approval requires Site Profile write authority.' );
		$site_uuid = strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) );
		$revision = absint( MAD4B_SCP_Site_Profile::revision() );
		$digest = strtolower( trim( (string) MAD4B_SCP_Site_Profile::profile_digest() ) );
		$environment = sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
		$origin = (string) MAD4B_SCP_Site_Profile::current_origin();
		$host = strtolower( rtrim( (string) MAD4B_SCP_Site_Profile::current_host(), '.' ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $site_uuid ) || $revision < 1 || ! preg_match( '/^[a-f0-9]{64}$/', $digest ) || '' === $environment || '' === $origin || '' === $host ) return $require_write ? new WP_Error( 'mad4b_approval_site_profile_invalid', 'Site Profile identity is incomplete or invalid.' ) : array();
		return array( 'site_uuid' => $site_uuid, 'profile_revision' => $revision, 'profile_digest' => $digest, 'environment' => $environment, 'origin' => $origin, 'host' => $host );
	}

	private static function profile_bindings_equal( array $a, array $b ) {
		foreach ( array( 'site_uuid', 'profile_revision', 'profile_digest', 'environment', 'host' ) as $key ) {
			if ( ! isset( $a[ $key ], $b[ $key ] ) || ! hash_equals( (string) $a[ $key ], (string) $b[ $key ] ) ) return false;
		}
		return true;
	}

	private static function is_governed_remote_mutation( $ticket_class, $server_id ) {
		return 'mutation' === sanitize_key( (string) $ticket_class ) && 'mad4b-write' === sanitize_key( (string) $server_id );
	}

	private static function can_plan_ticket( $ticket_class, $server_id ) {
		if ( self::is_governed_remote_mutation( $ticket_class, $server_id ) && class_exists( 'MAD4B_SCP_Policy' ) && method_exists( 'MAD4B_SCP_Policy', 'can_plan_mutations' ) ) return (bool) MAD4B_SCP_Policy::can_plan_mutations();
		return current_user_can( 'manage_options' );
	}

	private static function can_approve() {
		if ( class_exists( 'MAD4B_SCP_Policy' ) && method_exists( 'MAD4B_SCP_Policy', 'can_approve_mutations' ) ) return (bool) MAD4B_SCP_Policy::can_approve_mutations();
		return current_user_can( 'manage_options' );
	}

	private static function legacy_candidate_binding( $ticket_id ) {
		$bindings = get_option( self::CANDIDATE_BINDINGS_OPTION, array() );
		if ( ! is_array( $bindings ) || empty( $bindings[ $ticket_id ] ) || ! is_array( $bindings[ $ticket_id ] ) ) return array();
		return $bindings[ $ticket_id ];
	}

	private static function require_critical_schema() {
		return MAD4B_SCP_Schema::critical_ready() ? true : new WP_Error( 'mad4b_governance_schema_unavailable', 'MAD4B governance schema physical integrity is unavailable; approval and mutation authority remain fail-closed.' );
	}

	private static function canonical_json( $value ) {
		$normalized = self::canonicalize( $value, 0 );
		if ( is_wp_error( $normalized ) ) return $normalized;
		$json = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) return new WP_Error( 'mad4b_approval_payload_invalid', 'Approval payload cannot be encoded as JSON.' );
		if ( strlen( $json ) > self::MAX_CANONICAL_BYTES ) return new WP_Error( 'mad4b_approval_payload_too_large', 'Approval payload exceeds the canonical size limit.' );
		return $json;
	}

	private static function canonicalize( $value, $depth ) {
		if ( $depth > self::MAX_DEPTH ) return new WP_Error( 'mad4b_approval_payload_too_deep', 'Approval payload exceeds the maximum nesting depth.' );
		if ( is_array( $value ) ) {
			$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				$out = array();
				foreach ( $value as $item ) {
					$normalized = self::canonicalize( $item, $depth + 1 );
					if ( is_wp_error( $normalized ) ) return $normalized;
					$out[] = $normalized;
				}
				return $out;
			}
			$keys = array_keys( $value );
			sort( $keys, SORT_STRING );
			$out = array();
			foreach ( $keys as $key ) {
				if ( ! is_string( $key ) && ! is_int( $key ) ) return new WP_Error( 'mad4b_approval_payload_invalid', 'Approval object keys must be scalar.' );
				$normalized = self::canonicalize( $value[ $key ], $depth + 1 );
				if ( is_wp_error( $normalized ) ) return $normalized;
				$out[ (string) $key ] = $normalized;
			}
			return $out;
		}
		if ( is_string( $value ) || is_int( $value ) || is_bool( $value ) || null === $value ) return $value;
		if ( is_float( $value ) && is_finite( $value ) ) return $value;
		return new WP_Error( 'mad4b_approval_payload_invalid', 'Approval payload contains an unsupported value type.' );
	}
}

require_once __DIR__ . '/class-mad4b-scp-approval-repository.php';
require_once __DIR__ . '/class-mad4b-scp-approval-decision-admin.php';
MAD4B_SCP_Approval_Decision_Admin::boot();
