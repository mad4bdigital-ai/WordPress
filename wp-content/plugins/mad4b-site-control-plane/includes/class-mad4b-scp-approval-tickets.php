<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Approval_Tickets {
	const MAX_CANONICAL_BYTES = 65536;
	const MAX_DEPTH = 8;
	const DEFAULT_TTL = 600;
	const MAX_TTL = 3600;

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

	public static function consume_exact( $ticket_id, array $agent, $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class ) {
		global $wpdb;
		$ticket = self::get( $ticket_id );
		if ( ! $ticket ) return new WP_Error( 'mad4b_approval_missing', 'Approval ticket is missing.' );
		if ( 'approved' !== $ticket['status'] ) return new WP_Error( 'mad4b_approval_not_approved', 'Approval ticket is not approved or has already been used.' );
		if ( strtotime( $ticket['expires_at'] . ' UTC' ) < time() ) return new WP_Error( 'mad4b_approval_expired', 'Approval ticket has expired.' );
		if ( (int) $ticket['agent_id'] !== (int) $agent['id'] ) return new WP_Error( 'mad4b_approval_agent_mismatch', 'Approval ticket belongs to another agent.' );
		if ( $ticket['ticket_class'] !== $ticket_class ) return new WP_Error( 'mad4b_approval_class_mismatch', 'Approval ticket class does not match this operation.' );
		$hash = self::canonical_payload_hash( $agent['public_id'], $server_id, $ability_name, $provider, $target_fingerprint, $input, $ticket_class );
		if ( is_wp_error( $hash ) ) return $hash;
		if ( ! hash_equals( (string) $ticket['payload_sha256'], (string) $hash ) ) return new WP_Error( 'mad4b_approval_payload_mismatch', 'Approval ticket is not bound to this exact operation.' );
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$t['approvals']} SET status = 'used', used_at = %s WHERE id = %d AND status = 'approved' AND payload_sha256 = %s AND expires_at >= %s",
			$now, (int) $ticket['id'], $hash, $now
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 !== (int) $updated ) return new WP_Error( 'mad4b_approval_replay_denied', 'Approval ticket was already consumed, expired, or changed.' );
		MAD4B_SCP_Audit::record( 'mad4b/approval-consumed', array( 'ticket_id' => $ticket_id, 'ability' => $ability_name, 'agent_public_id' => $agent['public_id'] ), 'ok' );
		return $ticket;
	}

	public static function get( $ticket_id ) {
		global $wpdb; $t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['approvals']} WHERE ticket_id = %s LIMIT 1", (string) $ticket_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $row ? $row : null;
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
