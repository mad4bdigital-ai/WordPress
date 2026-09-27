<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only Approval Console projection for the exact current tenant/build.
 */
final class MAD4B_SCP_Approval_Repository {
	const CONTRACT = 'mad4b.approval-read-model.v3';
	const ACTIONABLE_LIMIT = 20;
	const HISTORY_LIMIT = 25;
	const MAX_HISTORY_PAGE = 1000;

	public static function actionable( array $candidate, $limit = self::ACTIONABLE_LIMIT ) {
		global $wpdb;
		if ( ! MAD4B_SCP_Schema::is_ready() ) return new WP_Error( 'mad4b_approval_read_model_schema_unavailable', 'Approval read model requires the current governance schema.' );
		if ( ! self::candidate_ready( $candidate ) ) return new WP_Error( 'mad4b_approval_read_model_candidate_unavailable', 'Approval read model requires exact current build and Site Profile provenance.' );
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$sql = $wpdb->prepare(
			"SELECT * FROM {$t['approvals']}
			 WHERE status='pending'
			   AND expires_at >= %s
			   AND ticket_class='mutation'
			   AND server_id='mad4b-write'
			   AND candidate_binding_contract=%s
			   AND candidate_sha=%s
			   AND build_fingerprint=%s
			   AND site_uuid=%s
			   AND site_profile_revision=%d
			   AND site_profile_digest=%s
			   AND binding_environment=%s
			   AND binding_host=%s
			 ORDER BY id DESC
			 LIMIT %d",
			$now,
			MAD4B_SCP_Approval_Tickets::CANDIDATE_BINDING_CONTRACT,
			(string) $candidate['source_commit_sha'],
			(string) $candidate['build_fingerprint'],
			(string) $candidate['site_uuid'],
			(int) $candidate['site_profile_revision'],
			(string) $candidate['site_profile_digest'],
			(string) $candidate['environment'],
			(string) $candidate['host'],
			$limit
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! is_array( $rows ) ) return new WP_Error( 'mad4b_approval_read_model_query_failed', 'Approval read model could not load actionable tickets.' );
		return array_map( static function ( $row ) use ( $candidate ) { return self::normalize( $row, $candidate, time() ); }, $rows );
	}

	public static function history( array $candidate, $page = 1, $per_page = self::HISTORY_LIMIT ) {
		global $wpdb;
		if ( ! MAD4B_SCP_Schema::is_ready() ) return new WP_Error( 'mad4b_approval_read_model_schema_unavailable', 'Approval read model requires the current governance schema.' );
		if ( ! self::candidate_ready( $candidate ) ) return new WP_Error( 'mad4b_approval_read_model_candidate_unavailable', 'Approval read model requires exact current build and Site Profile provenance.' );
		$page = max( 1, min( self::MAX_HISTORY_PAGE, absint( $page ) ) );
		$per_page = max( 1, min( 100, absint( $per_page ) ) );
		$offset = ( $page - 1 ) * $per_page;
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t['approvals']} ORDER BY id DESC LIMIT %d OFFSET %d",
			$per_page + 1,
			$offset
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! is_array( $rows ) ) return new WP_Error( 'mad4b_approval_read_model_query_failed', 'Approval history could not be loaded.' );
		$has_more = count( $rows ) > $per_page;
		if ( $has_more ) array_pop( $rows );
		$now = time();
		return array(
			'contract' => self::CONTRACT,
			'page' => $page,
			'per_page' => $per_page,
			'has_more' => $has_more,
			'rows' => array_map( static function ( $row ) use ( $candidate, $now ) { return self::normalize( $row, $candidate, $now ); }, $rows ),
		);
	}

	public static function reconcile_plan( array $candidate, $agent_public_id, $ability, $provider, $target_fingerprint, $input ) {
		global $wpdb;
		if ( ! MAD4B_SCP_Schema::is_ready() ) return new WP_Error( 'mad4b_approval_reconcile_schema_unavailable', 'Approval plan reconciliation requires the current governance schema.' );
		$agent_public_id = strtolower( trim( (string) $agent_public_id ) );
		$ability = trim( (string) $ability );
		$provider = sanitize_key( (string) $provider );
		$target_fingerprint = (string) $target_fingerprint;
		$input = is_array( $input ) ? $input : array();
		if ( '' === $agent_public_id || '' === $ability || '' === $provider ) return new WP_Error( 'mad4b_approval_reconcile_input_invalid', 'Exact agent, ability and provider are required to reconcile an approval plan.' );
		if ( ! class_exists( 'MAD4B_SCP_Agent_Registry' ) || ! class_exists( 'MAD4B_SCP_Approval_Tickets' ) ) return new WP_Error( 'mad4b_approval_reconcile_runtime_unavailable', 'Approval reconciliation dependencies are unavailable.' );
		$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $agent_public_id );
		if ( ! is_array( $agent ) || empty( $agent['id'] ) || 'enabled' !== ( isset( $agent['status'] ) ? (string) $agent['status'] : '' ) ) return new WP_Error( 'mad4b_approval_reconcile_agent_invalid', 'Approval reconciliation requires the exact enabled MAD4B agent.' );

		$payload = MAD4B_SCP_Approval_Tickets::canonical_payload_hash(
			$agent_public_id,
			'mad4b-write',
			$ability,
			$provider,
			$target_fingerprint,
			$input,
			'mutation'
		);
		if ( is_wp_error( $payload ) ) return $payload;

		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t['approvals']}
			 WHERE agent_id=%d
			   AND server_id='mad4b-write'
			   AND ability_name=%s
			   AND provider=%s
			   AND target_fingerprint=%s
			   AND ticket_class='mutation'
			   AND payload_sha256=%s
			 ORDER BY id DESC
			 LIMIT 2",
			(int) $agent['id'],
			$ability,
			$provider,
			$target_fingerprint,
			$payload
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! is_array( $rows ) ) return new WP_Error( 'mad4b_approval_reconcile_query_failed', 'Approval plan reconciliation could not inspect the exact payload.' );

		if ( empty( $rows ) ) {
			return array(
				'contract' => 'mad4b.approval-plan-reconciliation.v1',
				'found' => false,
				'payload_sha256' => $payload,
				'duplicate_matches_detected' => false,
				'retry_plan_safe' => true,
				'next_action' => 'create_new_plan_if_operation_is_still_required',
				'read_only' => true,
				'mutation_performed' => false,
			);
		}

		$now = time();
		$normalized = self::normalize( $rows[0], $candidate, $now );
		$effective = isset( $normalized['effective_status'] ) ? (string) $normalized['effective_status'] : '';
		$duplicates = count( $rows ) > 1;
		$retry_safe = ! $duplicates && in_array( $effective, array( 'expired', 'revoked', 'stale' ), true );
		$next_action = 'do_not_replay_plan';
		if ( 'pending' === $effective ) $next_action = 'use_existing_ticket_or_human_handoff';
		elseif ( 'approved' === $effective ) $next_action = 'execute_with_existing_ticket_only';
		elseif ( 'executing' === $effective || 'used' === $effective || 'failed' === $effective ) $next_action = 'reconcile_target_postcondition_before_any_new_plan';
		elseif ( $retry_safe ) $next_action = 'create_new_plan_if_operation_is_still_required';
		if ( $duplicates ) $next_action = 'inspect_duplicate_exact_payload_tickets_before_any_retry';

		return array(
			'contract' => 'mad4b.approval-plan-reconciliation.v1',
			'found' => true,
			'payload_sha256' => $payload,
			'ticket_id' => isset( $normalized['ticket_id'] ) ? (string) $normalized['ticket_id'] : '',
			'status' => isset( $normalized['status'] ) ? (string) $normalized['status'] : '',
			'effective_status' => $effective,
			'expires_at' => isset( $normalized['expires_at'] ) ? (string) $normalized['expires_at'] : '',
			'candidate_binding_exact' => ! empty( $normalized['binding_exact'] ),
			'duplicate_matches_detected' => $duplicates,
			'retry_plan_safe' => $retry_safe,
			'next_action' => $next_action,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	public static function effective_status( array $row, array $candidate, $now = null ) {
		$normalized = self::normalize( $row, $candidate, null === $now ? time() : (int) $now );
		return isset( $normalized['effective_status'] ) ? (string) $normalized['effective_status'] : '';
	}

	/** @internal Pure evaluator used by CI/runtime tests. */
	public static function normalize_for_test( array $row, array $candidate, $now ) {
		return self::normalize( $row, $candidate, (int) $now );
	}

	private static function normalize( array $row, array $candidate, $now ) {
		$expires = ! empty( $row['expires_at'] ) ? strtotime( (string) $row['expires_at'] . ' UTC' ) : false;
		$binding_exact = self::binding_exact( $row, $candidate );
		$status = isset( $row['status'] ) ? (string) $row['status'] : '';
		$effective = $status;
		if ( 'pending' === $status && ( false === $expires || $expires < $now ) ) $effective = 'expired';
		elseif ( 'pending' === $status && ! $binding_exact ) $effective = 'stale';
		$row['binding_exact'] = $binding_exact;
		$row['effective_status'] = $effective;
		$row['actionable'] = 'pending' === $status
			&& false !== $expires
			&& $expires >= $now
			&& $binding_exact
			&& 'mutation' === ( isset( $row['ticket_class'] ) ? (string) $row['ticket_class'] : '' )
			&& 'mad4b-write' === ( isset( $row['server_id'] ) ? sanitize_key( (string) $row['server_id'] ) : '' );
		return $row;
	}

	private static function binding_exact( array $row, array $candidate ) {
		if ( ! self::candidate_ready( $candidate ) ) return false;
		$expected = array(
			'candidate_binding_contract' => MAD4B_SCP_Approval_Tickets::CANDIDATE_BINDING_CONTRACT,
			'candidate_sha' => (string) $candidate['source_commit_sha'],
			'build_fingerprint' => (string) $candidate['build_fingerprint'],
			'site_uuid' => (string) $candidate['site_uuid'],
			'site_profile_revision' => (string) (int) $candidate['site_profile_revision'],
			'site_profile_digest' => (string) $candidate['site_profile_digest'],
			'binding_environment' => (string) $candidate['environment'],
			'binding_host' => (string) $candidate['host'],
		);
		foreach ( $expected as $key => $value ) {
			$actual = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
			if ( 'binding_host' === $key ) $actual = strtolower( rtrim( $actual, '.' ) );
			if ( ! hash_equals( (string) $value, $actual ) ) return false;
		}
		return true;
	}

	private static function candidate_ready( array $candidate ) {
		return ! empty( $candidate['ready'] )
			&& ! empty( $candidate['source_commit_sha'] )
			&& ! empty( $candidate['build_fingerprint'] )
			&& ! empty( $candidate['site_uuid'] )
			&& ! empty( $candidate['site_profile_revision'] )
			&& ! empty( $candidate['site_profile_digest'] )
			&& ! empty( $candidate['environment'] )
			&& ! empty( $candidate['host'] )
			&& 1 === preg_match( '/^[a-f0-9]{40}$/', (string) $candidate['source_commit_sha'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $candidate['build_fingerprint'] )
			&& 1 === preg_match( '/^[a-f0-9-]{36}$/', (string) $candidate['site_uuid'] )
			&& (int) $candidate['site_profile_revision'] > 0
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $candidate['site_profile_digest'] );
	}
}
