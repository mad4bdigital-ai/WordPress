<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-optimized Approval Console repository.
 *
 * This class is intentionally read-only. It never grants authority and never
 * decides or consumes a ticket. POST/execution paths must revalidate against the
 * authoritative approval model before any state transition.
 */
final class MAD4B_SCP_Approval_Repository {
	const CONTRACT = 'mad4b.approval-read-model.v2';
	const ACTIONABLE_LIMIT = 20;
	const HISTORY_LIMIT = 25;
	const MAX_HISTORY_PAGE = 1000;

	public static function actionable( array $candidate, $limit = self::ACTIONABLE_LIMIT ) {
		global $wpdb;
		if ( ! MAD4B_SCP_Schema::is_ready() ) return new WP_Error( 'mad4b_approval_read_model_schema_unavailable', 'Approval read model requires the current governance schema.' );
		if ( ! self::candidate_ready( $candidate ) ) return new WP_Error( 'mad4b_approval_read_model_candidate_unavailable', 'Approval read model requires exact current build provenance.' );
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
			   AND binding_environment=%s
			   AND binding_host=%s
			 ORDER BY id DESC
			 LIMIT %d",
			$now,
			MAD4B_SCP_Approval_Tickets::CANDIDATE_BINDING_CONTRACT,
			(string) $candidate['source_commit_sha'],
			(string) $candidate['build_fingerprint'],
			'staging',
			MAD4B_SCP_Approval_Tickets::STAGING_HOST,
			$limit
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! is_array( $rows ) ) return new WP_Error( 'mad4b_approval_read_model_query_failed', 'Approval read model could not load actionable tickets.' );
		return array_map( static function ( $row ) use ( $candidate ) { return self::normalize( $row, $candidate, time() ); }, $rows );
	}

	public static function history( array $candidate, $page = 1, $per_page = self::HISTORY_LIMIT ) {
		global $wpdb;
		if ( ! MAD4B_SCP_Schema::is_ready() ) return new WP_Error( 'mad4b_approval_read_model_schema_unavailable', 'Approval read model requires the current governance schema.' );
		if ( ! self::candidate_ready( $candidate ) ) return new WP_Error( 'mad4b_approval_read_model_candidate_unavailable', 'Approval read model requires exact current build provenance.' );
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

	/** @internal Pure evaluator used by CI/runtime tests. */
	public static function normalize_for_test( array $row, array $candidate, $now ) {
		return self::normalize( $row, $candidate, (int) $now );
	}

	private static function normalize( array $row, array $candidate, $now ) {
		$expires = ! empty( $row['expires_at'] ) ? strtotime( (string) $row['expires_at'] . ' UTC' ) : false;
		$binding_exact = self::binding_exact( $row, $candidate );
		$status = isset( $row['status'] ) ? (string) $row['status'] : '';
		$effective = $status;
		if ( 'pending' === $status && ( false === $expires || $expires < $now ) ) {
			$effective = 'expired';
		} elseif ( 'pending' === $status && ! $binding_exact ) {
			$effective = 'stale';
		}
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
		return self::candidate_ready( $candidate )
			&& isset( $row['candidate_binding_contract'] )
			&& MAD4B_SCP_Approval_Tickets::CANDIDATE_BINDING_CONTRACT === (string) $row['candidate_binding_contract']
			&& ! empty( $row['candidate_sha'] )
			&& hash_equals( (string) $candidate['source_commit_sha'], (string) $row['candidate_sha'] )
			&& ! empty( $row['build_fingerprint'] )
			&& hash_equals( (string) $candidate['build_fingerprint'], (string) $row['build_fingerprint'] )
			&& 'staging' === ( isset( $row['binding_environment'] ) ? sanitize_key( (string) $row['binding_environment'] ) : '' )
			&& MAD4B_SCP_Approval_Tickets::STAGING_HOST === strtolower( rtrim( isset( $row['binding_host'] ) ? (string) $row['binding_host'] : '', '.' ) );
	}

	private static function candidate_ready( array $candidate ) {
		return ! empty( $candidate['ready'] )
			&& ! empty( $candidate['source_commit_sha'] )
			&& ! empty( $candidate['build_fingerprint'] )
			&& 1 === preg_match( '/^[a-f0-9]{40}$/', (string) $candidate['source_commit_sha'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $candidate['build_fingerprint'] );
	}
}
