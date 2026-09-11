<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Approval_Tickets {
	const MAX_CANONICAL_BYTES = 65536;
	const MAX_DEPTH = 8;
	const DEFAULT_TTL = 600;
	const MAX_TTL = 3600;
	const CANDIDATE_BINDING_CONTRACT = 'mad4b.approval-candidate-binding.v1';
	const CANDIDATE_BINDINGS_OPTION = 'mad4b_scp_approval_candidate_bindings_v1';
	const MAX_CANDIDATE_BINDINGS = 100;
	const STAGING_HOST = 'staging.egypttourgates.com';

	public static function canonical_payload_hash( $agent_public_id, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class = 'mutation' ) {
		$envelope = array(
			'contract' => 'mad4b.approval.v1',
			'site' => site_url(),
			'agent_public_id' => (string) $agent_public_id,
			'server_id' => sanitize_key( (string) $server_id ),
			'ability' => (string) $ability_name,
			'provider' => sanitize_key( (string) $provider ),
			'target' => (string) $target_fingerprint,
			'ticket_class' => sanitize_key( (string) $ticket_class ),
			'input' => $input,
		);
		$canonical = self::canonical_json( $envelope );
		if ( is_wp_error( $canonical ) ) return $canonical;
		return hash( 'sha256', $canonical );
	}

	public static function create_pending( $agent_public_id, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class, $reason, $ttl = self::DEFAULT_TTL ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_approval_admin_required', 'Administrator capability is required to create an approval plan.' );
		$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $agent_public_id );
		if ( ! $agent || 'enabled' !== $agent['status'] ) return new WP_Error( 'mad4b_approval_agent_invalid', 'Approval requires an enabled MAD4B agent.' );
		$ticket_class = sanitize_key( $ticket_class );
		if ( ! in_array( $ticket_class, array( 'mutation', 'breakglass', 'recovery' ), true ) ) return new WP_Error( 'mad4b_approval_class_invalid', 'Unknown approval ticket class.' );
		$reason = trim( sanitize_text_field( $reason ) );
		if ( strlen( $reason ) < 3 || strlen( $reason ) > 500 ) return new WP_Error( 'mad4b_approval_reason_invalid', 'Approval reason must be between 3 and 500 characters.' );
		$ttl = max( 60, min( self::MAX_TTL, absint( $ttl ) ) );
		$payload_hash = self::canonical_payload_hash( $agent_public_id, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class );
		if ( is_wp_error( $payload_hash ) ) return $payload_hash;
		$t = MAD4B_SCP_Schema::tables();
		$now = time();
		$ticket_id = wp_generate_uuid4();
		$ok = $wpdb->insert( $t['approvals'], array(
			'ticket_id' => $ticket_id,
			'ticket_class' => $ticket_class,
			'agent_id' => (int) $agent['id'],
			'server_id' => sanitize_key( $server_id ),
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
			'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
		), array( '%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $ok ) return new WP_Error( 'mad4b_approval_create_failed', 'Approval ticket could not be created.', array( 'db_error' => $wpdb->last_error ) );
		return self::get( $ticket_id );
	}

	public static function bind_ticket_to_current_candidate( $ticket_id ) {
		global $wpdb;
		$ticket_id = strtolower( trim( (string) $ticket_id ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) ) return new WP_Error( 'mad4b_approval_candidate_ticket_invalid', 'Candidate binding requires an exact approval ticket id.' );
		if ( ! self::exact_governed_staging() ) return new WP_Error( 'mad4b_approval_candidate_staging_only', 'Candidate-bound remote approval planning is restricted to the exact governed Staging origin.' );
		$ticket = self::get( $ticket_id );
		if ( ! is_array( $ticket ) ) return new WP_Error( 'mad4b_approval_missing', 'Approval ticket is missing.' );
		if ( 'pending' !== (string) $ticket['status'] || 'mutation' !== (string) $ticket['ticket_class'] || 'mad4b-write' !== sanitize_key( (string) $ticket['server_id'] ) ) {
			return new WP_Error( 'mad4b_approval_candidate_ticket_ineligible', 'Only fresh pending mad4b-write mutation tickets may receive a live candidate binding.' );
		}
		$payload_hash = isset( $ticket['payload_sha256'] ) ? strtolower( trim( (string) $ticket['payload_sha256'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $payload_hash ) ) return new WP_Error( 'mad4b_approval_candidate_payload_invalid', 'Approval ticket payload digest is invalid.' );
		$binding = self::current_candidate_binding( $ticket_id, $payload_hash );
		if ( is_wp_error( $binding ) || ! self::save_candidate_binding( $binding ) ) {
			$t = MAD4B_SCP_Schema::tables();
			$wpdb->delete( $t['approvals'], array( 'ticket_id' => $ticket_id, 'status' => 'pending' ), array( '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return is_wp_error( $binding ) ? $binding : new WP_Error( 'mad4b_approval_candidate_binding_failed', 'Pending remote approval ticket could not be bound durably to the exact Staging candidate.' );
		}
		return $binding;
	}

	public static function decide_pending( $ticket_id, $decision, $expected_payload_sha256, array $context = array() ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_approval_admin_required', 'Administrator capability is required to decide a ticket.' );
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

		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( 'approve' === $decision ) {
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$t['approvals']} SET status = 'approved', approved_by = %d, approved_at = %s WHERE id = %d AND ticket_id = %s AND status = 'pending' AND payload_sha256 = %s AND expires_at >= %s",
				get_current_user_id(), $now, (int) $ticket['id'], $ticket_id, $expected_payload_sha256, $now
			) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		} else {
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$t['approvals']} SET status = 'revoked' WHERE id = %d AND ticket_id = %s AND status = 'pending' AND payload_sha256 = %s AND expires_at >= %s",
				(int) $ticket['id'], $ticket_id, $expected_payload_sha256, $now
			) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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
			'candidate_sha' => isset( $context['candidate_sha'] ) ? strtolower( (string) $context['candidate_sha'] ) : '',
			'build_fingerprint' => isset( $context['build_fingerprint'] ) ? strtolower( (string) $context['build_fingerprint'] ) : '',
			'decision_contract' => isset( $context['contract'] ) ? (string) $context['contract'] : '',
		);
		MAD4B_SCP_Audit::record( 'mad4b/approval-decision', $summary, 'ok' );
		return self::get( $ticket_id );
	}

	public static function approve( $ticket_id ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_approval_admin_required', 'Administrator capability is required to approve a ticket.' );
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$t['approvals']} SET status = 'approved', approved_by = %d, approved_at = %s WHERE ticket_id = %s AND status = 'pending' AND expires_at >= %s",
			get_current_user_id(), $now, (string) $ticket_id, $now
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 !== (int) $updated ) return new WP_Error( 'mad4b_approval_not_approvable', 'Approval ticket is missing, expired, or no longer pending.' );
		MAD4B_SCP_Audit::record( 'mad4b/approval-approved', array( 'ticket_id' => $ticket_id ), 'ok' );
		return self::get( $ticket_id );
	}

	public static function revoke( $ticket_id ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_approval_admin_required', 'Administrator capability is required to revoke a ticket.' );
		$t = MAD4B_SCP_Schema::tables();
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['approvals']} SET status = 'revoked' WHERE ticket_id = %s AND status IN ('pending','approved')", (string) $ticket_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 !== (int) $updated ) return new WP_Error( 'mad4b_approval_not_revokable', 'Approval ticket is missing or cannot be revoked.' );
		MAD4B_SCP_Audit::record( 'mad4b/approval-revoked', array( 'ticket_id' => $ticket_id ), 'ok' );
		return true;
	}

	/**
	 * Pure/read-only exact-ticket validation. This method MUST NOT change ticket
	 * state, consume budgets, persist audit records, or otherwise mutate durable
	 * state because WordPress/MCP may call permission checks more than once.
	 */
	public static function validate_exact( $ticket_id, array $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class ) {
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
		if ( ! hash_equals( (string) $ticket['payload_sha256'], (string) $hash ) ) return new WP_Error( 'mad4b_approval_payload_mismatch', 'Approval ticket is not bound to this exact operation.' );

		// Remote governed Staging tickets are additionally bound to the exact
		// candidate SHA/build fingerprint. Permission checks only compare evidence;
		// they never write or refresh the binding.
		if ( 'mutation' === (string) $ticket_class && 'mad4b-write' === sanitize_key( (string) $server_id ) && self::exact_governed_staging() ) {
			$saved = self::candidate_binding( $ticket_id );
			if ( empty( $saved ) ) return new WP_Error( 'mad4b_approval_candidate_binding_missing', 'Remote Staging approval ticket is missing exact candidate binding evidence.' );
			$current = self::current_candidate_binding( $ticket_id, $hash );
			if ( is_wp_error( $current ) ) return $current;
			foreach ( array( 'ticket_id', 'payload_sha256', 'candidate_sha', 'build_fingerprint', 'environment', 'host' ) as $key ) {
				if ( ! isset( $saved[ $key ], $current[ $key ] ) || ! hash_equals( (string) $saved[ $key ], (string) $current[ $key ] ) ) {
					return new WP_Error( 'mad4b_approval_candidate_mismatch', 'Approval ticket candidate binding no longer matches the exact live Staging build.' );
				}
			}
		}
		return array( 'ticket' => $ticket, 'payload_sha256' => $hash );
	}

	/** Atomically claim one approved ticket at the execution boundary. */
	public static function claim_exact( $ticket_id, array $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class ) {
		global $wpdb;
		$validated = self::validate_exact( $ticket_id, $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class );
		if ( is_wp_error( $validated ) ) return $validated;
		$ticket = $validated['ticket'];
		$hash = $validated['payload_sha256'];
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$t['approvals']} SET status = 'executing' WHERE id = %d AND status = 'approved' AND payload_sha256 = %s AND expires_at >= %s",
			(int) $ticket['id'], $hash, $now
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 !== (int) $updated ) return new WP_Error( 'mad4b_approval_replay_denied', 'Approval ticket was already claimed, expired, or changed.' );
		MAD4B_SCP_Audit::record( 'mad4b/approval-claimed', array( 'ticket_id' => $ticket_id, 'ability' => $ability_name, 'agent_public_id' => $agent['public_id'] ), 'ok' );
		$ticket['status'] = 'executing';
		return $ticket;
	}

	/** Finalize an executing ticket. Failed tickets are terminal and never retryable. */
	public static function finalize_claim( $ticket_id, $terminal_status ) {
		global $wpdb;
		$terminal_status = sanitize_key( (string) $terminal_status );
		if ( ! in_array( $terminal_status, array( 'used', 'failed' ), true ) ) return new WP_Error( 'mad4b_approval_finalize_status_invalid', 'Approval ticket final status must be used or failed.' );
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( 'used' === $terminal_status ) {
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['approvals']} SET status = 'used', used_at = %s WHERE ticket_id = %s AND status = 'executing'", $now, (string) $ticket_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		} else {
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t['approvals']} SET status = 'failed' WHERE ticket_id = %s AND status = 'executing'", (string) $ticket_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}
		if ( 1 !== (int) $updated ) return new WP_Error( 'mad4b_approval_finalize_conflict', 'Approval ticket is not in the expected executing state.' );
		$event = 'used' === $terminal_status ? 'mad4b/approval-consumed' : 'mad4b/approval-execution-failed';
		MAD4B_SCP_Audit::record( $event, array( 'ticket_id' => $ticket_id, 'result_status' => $terminal_status ), 'used' === $terminal_status ? 'ok' : 'failed' );
		return self::get( $ticket_id );
	}

	/** Compatibility helper for direct non-Ability callers and legacy tests. */
	public static function consume_exact( $ticket_id, array $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class ) {
		$claim = self::claim_exact( $ticket_id, $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class );
		if ( is_wp_error( $claim ) ) return $claim;
		$final = self::finalize_claim( $ticket_id, 'used' );
		return is_wp_error( $final ) ? $final : $claim;
	}

	public static function get( $ticket_id ) {
		global $wpdb; $t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['approvals']} WHERE ticket_id = %s LIMIT 1", (string) $ticket_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $row ? $row : null;
	}

	public static function candidate_binding( $ticket_id ) {
		$ticket_id = strtolower( trim( (string) $ticket_id ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) ) return array();
		$bindings = get_option( self::CANDIDATE_BINDINGS_OPTION, array() );
		if ( ! is_array( $bindings ) || empty( $bindings[ $ticket_id ] ) || ! is_array( $bindings[ $ticket_id ] ) ) return array();
		return $bindings[ $ticket_id ];
	}

	private static function current_candidate_binding( $ticket_id, $payload_hash ) {
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_approval_candidate_unavailable', 'Exact build provenance is unavailable for remote approval planning.' );
		$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		$sha = is_array( $provenance ) && isset( $provenance['source_commit_sha'] ) ? strtolower( trim( (string) $provenance['source_commit_sha'] ) ) : '';
		$fingerprint = is_array( $provenance ) && isset( $provenance['build_fingerprint'] ) ? strtolower( trim( (string) $provenance['build_fingerprint'] ) : '';
		if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] )
			|| ! preg_match( '/^[a-f0-9]{40}$/', $sha ) || ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) return new WP_Error( 'mad4b_approval_candidate_unavailable', 'Remote approval planning requires exact current Staging build provenance.' );
		return array(
			'contract' => self::CANDIDATE_BINDING_CONTRACT,
			'ticket_id' => strtolower( (string) $ticket_id ),
			'payload_sha256' => strtolower( (string) $payload_hash ),
			'candidate_sha' => $sha,
			'build_fingerprint' => $fingerprint,
			'environment' => 'staging',
			'host' => self::STAGING_HOST,
			'bound_at' => gmdate( 'c' ),
		);
	}

	private static function save_candidate_binding( array $binding ) {
		if ( empty( $binding['ticket_id'] ) ) return false;
		$bindings = get_option( self::CANDIDATE_BINDINGS_OPTION, array() );
		if ( ! is_array( $bindings ) ) $bindings = array();
		$bindings[ (string) $binding['ticket_id'] ] = $binding;
		while ( count( $bindings ) > self::MAX_CANDIDATE_BINDINGS ) array_shift( $bindings );
		return false !== update_option( self::CANDIDATE_BINDINGS_OPTION, $bindings, false );
	}

	private static function exact_governed_staging() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$url = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		$host = is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( rtrim( (string) $parts['host'], '.' ) ) : '';
		return 'staging' === $environment && self::STAGING_HOST === $host;
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
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
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

require_once __DIR__ . '/class-mad4b-scp-approval-decision-admin.php';
MAD4B_SCP_Approval_Decision_Admin::boot();
