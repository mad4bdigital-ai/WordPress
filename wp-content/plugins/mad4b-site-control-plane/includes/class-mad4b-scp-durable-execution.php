<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable execution primitives for Feature 007.
 *
 * Worker identity is not authority. These methods persist bounded execution
 * intent/state after the Control Plane has already authorized a plan.
 */
final class MAD4B_SCP_Durable_Execution {
	const CONTRACT = 'mad4b.durable-execution.v1';
	const LEASE_CONTRACT = 'mad4b.execution-plane.v1';
	const IDEMPOTENCY_CONTRACT = 'mad4b.idempotency-record.v1';
	const OUTBOX_CONTRACT = 'mad4b.execution-outbox.v1';
	const INBOX_CONTRACT = 'mad4b.execution-inbox.v1';

	public static function begin_idempotency( $scope_key, $idempotency_key, $request_sha256, $ttl_seconds = 86400 ) {
		global $wpdb;
		$scope_key = strtolower( trim( (string) $scope_key ) );
		$idempotency_key = trim( (string) $idempotency_key );
		$request_sha256 = strtolower( trim( (string) $request_sha256 ) );
		$ttl_seconds = max( 3600, min( 2592000, absint( $ttl_seconds ) ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $scope_key ) ) return new WP_Error( 'mad4b_idempotency_scope_invalid', 'Idempotency scope must be a SHA-256 digest.' );
		if ( '' === $idempotency_key || strlen( $idempotency_key ) > 191 ) return new WP_Error( 'mad4b_idempotency_key_invalid', 'Idempotency key is missing or too long.' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $request_sha256 ) ) return new WP_Error( 'mad4b_idempotency_request_hash_invalid', 'Idempotency request hash must be SHA-256.' );
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds );
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$t['idempotency']} (scope_key,idempotency_key,request_sha256,claim_epoch,status,result_json,result_sha256,reconciliation_ref,expires_at,created_at,updated_at) VALUES (%s,%s,%s,1,'pending',NULL,'','',%s,%s,%s)",
			$scope_key, $idempotency_key, $request_sha256, $expires, $now, $now
		) );
		if ( 1 === (int) $inserted ) {
			return array(
				'contract' => self::IDEMPOTENCY_CONTRACT,
				'claimed' => true,
				'replayed' => false,
				'scope_key' => $scope_key,
				'idempotency_key' => $idempotency_key,
				'request_sha256' => $request_sha256,
				'claim_epoch' => 1,
				'expires_at' => $expires,
			);
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t['idempotency']} WHERE scope_key=%s AND idempotency_key=%s LIMIT 1",
			$scope_key, $idempotency_key
		), ARRAY_A );
		if ( ! is_array( $row ) ) return new WP_Error( 'mad4b_idempotency_claim_failed', 'Unable to claim or read idempotency record.' );
		if ( ! hash_equals( (string) $row['request_sha256'], $request_sha256 ) ) {
			return new WP_Error( 'mad4b_idempotency_hash_conflict', 'Same idempotency key was reused with a different request hash.' );
		}
		$expired = empty( $row['expires_at'] ) || strtotime( (string) $row['expires_at'] . ' UTC' ) <= time();
		if ( 'pending' === (string) $row['status'] && $expired ) {
			return new WP_Error(
				'mad4b_idempotency_reconciliation_required',
				'Expired pending idempotency record requires durable provider/state reconciliation before reclaim.',
				array(
					'scope_key' => $scope_key,
					'idempotency_key' => $idempotency_key,
					'expires_at' => isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '',
				)
			);
		}
		if ( 'completed' === (string) $row['status'] ) {
			$result = null;
			if ( ! empty( $row['result_json'] ) ) {
				$result = json_decode( (string) $row['result_json'], true );
				if ( JSON_ERROR_NONE !== json_last_error() ) return new WP_Error( 'mad4b_idempotency_result_corrupt', 'Stored idempotency result is corrupt.' );
			}
			return array(
				'contract' => self::IDEMPOTENCY_CONTRACT,
				'claimed' => false,
				'replayed' => true,
				'scope_key' => $scope_key,
				'idempotency_key' => $idempotency_key,
				'request_sha256' => $request_sha256,
				'claim_epoch' => isset( $row['claim_epoch'] ) ? (int) $row['claim_epoch'] : 0,
				'result' => $result,
				'result_sha256' => isset( $row['result_sha256'] ) ? (string) $row['result_sha256'] : '',
			);
		}
		return new WP_Error( 'mad4b_idempotency_in_progress', 'The same idempotent operation is already pending.' );
	}

	public static function complete_idempotency( array $claim, $result ) {
		global $wpdb;
		if ( empty( $claim['claimed'] ) || empty( $claim['scope_key'] ) || empty( $claim['idempotency_key'] ) || empty( $claim['request_sha256'] ) || empty( $claim['claim_epoch'] ) ) {
			return new WP_Error( 'mad4b_idempotency_claim_invalid', 'Idempotency completion requires the exact pending claim and claim epoch.' );
		}
		$json = wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) return new WP_Error( 'mad4b_idempotency_result_invalid', 'Idempotency result is not serializable.' );
		if ( strlen( $json ) > 262144 ) return new WP_Error( 'mad4b_idempotency_result_too_large', 'Idempotency result exceeds the bounded storage limit.' );
		$sha = hash( 'sha256', $json );
		$t = MAD4B_SCP_Schema::tables();
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$t['idempotency']} SET status='completed',result_json=%s,result_sha256=%s,updated_at=%s WHERE scope_key=%s AND idempotency_key=%s AND request_sha256=%s AND claim_epoch=%d AND status='pending' AND expires_at>%s",
			$json, $sha, gmdate( 'Y-m-d H:i:s' ), (string) $claim['scope_key'], (string) $claim['idempotency_key'], (string) $claim['request_sha256'], absint( $claim['claim_epoch'] ), gmdate( 'Y-m-d H:i:s' )
		) );
		return 1 === (int) $updated
			? array( 'contract' => self::IDEMPOTENCY_CONTRACT, 'completed' => true, 'result_sha256' => $sha )
			: new WP_Error( 'mad4b_idempotency_complete_conflict', 'Idempotency record is no longer pending for this request.' );
	}

	public static function complete_idempotency_from_reconciliation( $scope_key, $idempotency_key, $request_sha256, $reconciliation_ref, $result ) {
		global $wpdb;
		$scope_key = strtolower( trim( (string) $scope_key ) );
		$idempotency_key = trim( (string) $idempotency_key );
		$request_sha256 = strtolower( trim( (string) $request_sha256 ) );
		$reconciliation_ref = trim( (string) $reconciliation_ref );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $scope_key ) || ! preg_match( '/^[a-f0-9]{64}$/', $request_sha256 ) ) return new WP_Error( 'mad4b_idempotency_reconcile_identity_invalid', 'Idempotency reconciliation identity is invalid.' );
		if ( '' === $idempotency_key || strlen( $idempotency_key ) > 191 ) return new WP_Error( 'mad4b_idempotency_key_invalid', 'Idempotency key is missing or too long.' );
		if ( '' === $reconciliation_ref || strlen( $reconciliation_ref ) > 191 ) return new WP_Error( 'mad4b_idempotency_reconciliation_evidence_required', 'Idempotency reconciliation requires bounded provider readback evidence.' );
		$json = wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) return new WP_Error( 'mad4b_idempotency_result_invalid', 'Reconciled idempotency result is not serializable.' );
		if ( strlen( $json ) > 262144 ) return new WP_Error( 'mad4b_idempotency_result_too_large', 'Reconciled idempotency result exceeds bounded storage.' );
		$result_sha256 = hash( 'sha256', $json );
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$t['idempotency']} WHERE scope_key=%s AND idempotency_key=%s FOR UPDATE",
				$scope_key, $idempotency_key
			), ARRAY_A );
			if ( ! is_array( $row ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'mad4b_idempotency_missing', 'Cannot reconcile an unknown idempotency record.' );
			}
			if ( ! hash_equals( (string) $row['request_sha256'], $request_sha256 ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'mad4b_idempotency_hash_conflict', 'Same idempotency key was reused with a different request hash.' );
			}
			if ( 'completed' === (string) $row['status'] ) {
				$stored = null;
				if ( ! empty( $row['result_json'] ) ) {
					$stored = json_decode( (string) $row['result_json'], true );
					if ( JSON_ERROR_NONE !== json_last_error() ) {
						$wpdb->query( 'ROLLBACK' );
						return new WP_Error( 'mad4b_idempotency_result_corrupt', 'Stored idempotency result is corrupt.' );
					}
				}
				$wpdb->query( 'COMMIT' );
				return array(
					'contract' => self::IDEMPOTENCY_CONTRACT,
					'completed' => true,
					'reconciled' => false,
					'replayed' => true,
					'claim_epoch' => isset( $row['claim_epoch'] ) ? (int) $row['claim_epoch'] : 0,
					'result' => $stored,
					'result_sha256' => isset( $row['result_sha256'] ) ? (string) $row['result_sha256'] : '',
				);
			}
			if ( 'pending' !== (string) $row['status'] ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'mad4b_idempotency_reconcile_state_denied', 'Only a pending idempotency record can be completed from provider readback.' );
			}
			$context = array(
				'scope_key' => $scope_key,
				'idempotency_key' => $idempotency_key,
				'request_sha256' => $request_sha256,
				'claim_epoch' => isset( $row['claim_epoch'] ) ? (int) $row['claim_epoch'] : 0,
				'reconciliation_ref' => $reconciliation_ref,
				'result_sha256' => $result_sha256,
				'result' => $result,
			);
			$verified = self::reconciliation_verified( 'idempotency_completion', $context );
			if ( is_wp_error( $verified ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $verified;
			}
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$t['idempotency']} SET status='completed',result_json=%s,result_sha256=%s,reconciliation_ref=%s,updated_at=%s WHERE id=%d AND request_sha256=%s AND claim_epoch=%d AND status='pending'",
				$json, $result_sha256, $reconciliation_ref, $now, (int) $row['id'], $request_sha256, isset( $row['claim_epoch'] ) ? (int) $row['claim_epoch'] : 0
			) );
			if ( 1 !== (int) $updated ) throw new RuntimeException( 'idempotency_reconcile_cas_failed' );
			$wpdb->query( 'COMMIT' );
			return array(
				'contract' => self::IDEMPOTENCY_CONTRACT,
				'completed' => true,
				'reconciled' => true,
				'replayed' => false,
				'claim_epoch' => isset( $row['claim_epoch'] ) ? (int) $row['claim_epoch'] : 0,
				'reconciliation_ref' => $reconciliation_ref,
				'result' => $result,
				'result_sha256' => $result_sha256,
			);
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'mad4b_idempotency_reconcile_failed', 'Unable to complete idempotency from verified provider readback.', array( 'cause' => $e->getMessage() ) );
		}
	}

	public static function reclaim_idempotency( $scope_key, $idempotency_key, $request_sha256, $reconciliation_ref, $ttl_seconds = 86400 ) {
		global $wpdb;
		$scope_key = strtolower( trim( (string) $scope_key ) );
		$idempotency_key = trim( (string) $idempotency_key );
		$request_sha256 = strtolower( trim( (string) $request_sha256 ) );
		$reconciliation_ref = trim( (string) $reconciliation_ref );
		$ttl_seconds = max( 3600, min( 2592000, absint( $ttl_seconds ) ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $scope_key ) || ! preg_match( '/^[a-f0-9]{64}$/', $request_sha256 ) ) return new WP_Error( 'mad4b_idempotency_reclaim_identity_invalid', 'Idempotency reclaim identity is invalid.' );
		if ( '' === $idempotency_key || strlen( $idempotency_key ) > 191 ) return new WP_Error( 'mad4b_idempotency_key_invalid', 'Idempotency key is missing or too long.' );
		if ( '' === $reconciliation_ref || strlen( $reconciliation_ref ) > 191 ) return new WP_Error( 'mad4b_idempotency_reconciliation_evidence_required', 'Idempotency reclaim requires bounded reconciliation evidence.' );
		$t = MAD4B_SCP_Schema::tables();
		$now_ts = time();
		$now = gmdate( 'Y-m-d H:i:s', $now_ts );
		$expires = gmdate( 'Y-m-d H:i:s', $now_ts + $ttl_seconds );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$t['idempotency']} WHERE scope_key=%s AND idempotency_key=%s FOR UPDATE",
				$scope_key, $idempotency_key
			), ARRAY_A );
			if ( ! is_array( $row ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'mad4b_idempotency_missing', 'Cannot reclaim unknown idempotency record.' );
			}
			if ( ! hash_equals( (string) $row['request_sha256'], $request_sha256 ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'mad4b_idempotency_hash_conflict', 'Same idempotency key was reused with a different request hash.' );
			}
			if ( 'pending' !== (string) $row['status'] ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'mad4b_idempotency_reclaim_state_denied', 'Only expired pending idempotency records may be reclaimed.' );
			}
			$expired = empty( $row['expires_at'] ) || strtotime( (string) $row['expires_at'] . ' UTC' ) <= $now_ts;
			if ( ! $expired ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'mad4b_idempotency_still_active', 'Pending idempotency record has not expired.' );
			}
			$reconciliation = self::reconciliation_verified(
				'idempotency_reclaim',
				array(
					'scope_key' => $scope_key,
					'idempotency_key' => $idempotency_key,
					'request_sha256' => $request_sha256,
					'claim_epoch' => isset( $row['claim_epoch'] ) ? (int) $row['claim_epoch'] : 0,
					'expires_at' => isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '',
					'reconciliation_ref' => $reconciliation_ref,
				)
			);
			if ( is_wp_error( $reconciliation ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $reconciliation;
			}
			$current_epoch = isset( $row['claim_epoch'] ) ? max( 1, (int) $row['claim_epoch'] ) : 1;
			$next_epoch = $current_epoch + 1;
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$t['idempotency']} SET claim_epoch=%d,expires_at=%s,reconciliation_ref=%s,updated_at=%s WHERE id=%d AND request_sha256=%s AND claim_epoch=%d AND status='pending' AND expires_at<=%s",
				$next_epoch, $expires, $reconciliation_ref, $now, (int) $row['id'], $request_sha256, $current_epoch, $now
			) );
			if ( 1 !== (int) $updated ) throw new RuntimeException( 'idempotency_reclaim_cas_failed' );
			$wpdb->query( 'COMMIT' );
			return array(
				'contract' => self::IDEMPOTENCY_CONTRACT,
				'claimed' => true,
				'reclaimed' => true,
				'replayed' => false,
				'scope_key' => $scope_key,
				'idempotency_key' => $idempotency_key,
				'request_sha256' => $request_sha256,
				'claim_epoch' => $next_epoch,
				'reconciliation_ref' => $reconciliation_ref,
				'expires_at' => $expires,
			);
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'mad4b_idempotency_reclaim_failed', 'Unable to reclaim idempotency record.', array( 'cause' => $e->getMessage() ) );
		}
	}

	public static function scope_key( $site_uuid, $capability, $operation, $target_identity ) {
		$material = array(
			'site_uuid' => strtolower( trim( (string) $site_uuid ) ),
			'capability' => trim( (string) $capability ),
			'operation' => trim( (string) $operation ),
			'target_identity' => trim( (string) $target_identity ),
		);
		return hash( 'sha256', wp_json_encode( $material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public static function acquire_lease( $work_id, $aggregate_type, $aggregate_id, $worker_id, $expected_revision, $ttl_seconds = 120 ) {
		global $wpdb;
		$valid = self::validate_lease_identity( $work_id, $aggregate_type, $aggregate_id, $worker_id, $expected_revision );
		if ( is_wp_error( $valid ) ) return $valid;
		$ttl_seconds = max( 30, min( 3600, absint( $ttl_seconds ) ) );
		$t = MAD4B_SCP_Schema::tables();
		$now_ts = time();
		$now = gmdate( 'Y-m-d H:i:s', $now_ts );
		$expires = gmdate( 'Y-m-d H:i:s', $now_ts + $ttl_seconds );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['work_leases']} WHERE work_id=%s FOR UPDATE", $work_id ), ARRAY_A );
			if ( ! $row ) {
				$ok = $wpdb->insert( $t['work_leases'], array(
					'work_id' => $work_id,
					'aggregate_type' => $aggregate_type,
					'aggregate_id' => $aggregate_id,
					'worker_id' => $worker_id,
					'lease_epoch' => 1,
					'expected_aggregate_revision' => absint( $expected_revision ),
					'status' => 'active',
					'acquired_at' => $now,
					'heartbeat_at' => $now,
					'expires_at' => $expires,
					'reconciliation_ref' => '',
					'created_at' => $now,
					'updated_at' => $now,
				), array( '%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s','%s' ) );
				if ( false === $ok ) throw new RuntimeException( 'lease_insert_failed' );
				$wpdb->query( 'COMMIT' );
				return self::lease_receipt( $work_id, $aggregate_type, $aggregate_id, $worker_id, 1, $expected_revision, $now, $now, $expires, 'active', '' );
			}
			if ( (string) $row['aggregate_type'] !== $aggregate_type || (string) $row['aggregate_id'] !== $aggregate_id ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'mad4b_lease_work_identity_conflict', 'Work ID is already bound to a different aggregate.' );
			}
			$expired = empty( $row['expires_at'] ) || strtotime( (string) $row['expires_at'] . ' UTC' ) <= $now_ts;
			if ( 'active' === (string) $row['status'] && ! $expired ) {
				if ( hash_equals( (string) $row['worker_id'], $worker_id ) && (int) $row['expected_aggregate_revision'] === absint( $expected_revision ) ) {
					$wpdb->query( 'COMMIT' );
					return self::lease_receipt_from_row( $row );
				}
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'mad4b_lease_held', 'Work is already held by another active worker.' );
			}
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'mad4b_lease_reconciliation_required', 'Expired or non-active work requires provider/state reconciliation before reclaim.', array(
				'work_id' => $work_id,
				'current_epoch' => (int) $row['lease_epoch'],
				'status' => (string) $row['status'],
				'expires_at' => (string) $row['expires_at'],
			) );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'mad4b_lease_acquire_failed', 'Unable to acquire durable work lease.', array( 'cause' => $e->getMessage() ) );
		}
	}

	public static function reclaim_lease( $work_id, $worker_id, $expected_revision, $reconciliation_ref, $ttl_seconds = 120 ) {
		global $wpdb;
		$work_id = strtolower( trim( (string) $work_id ) );
		$worker_id = trim( (string) $worker_id );
		$reconciliation_ref = trim( (string) $reconciliation_ref );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $work_id ) || '' === $worker_id || strlen( $worker_id ) > 191 ) return new WP_Error( 'mad4b_lease_identity_invalid', 'Lease reclaim identity is invalid.' );
		if ( '' === $reconciliation_ref || strlen( $reconciliation_ref ) > 191 ) return new WP_Error( 'mad4b_lease_reconciliation_evidence_required', 'Lease reclaim requires bounded reconciliation evidence.' );
		$ttl_seconds = max( 30, min( 3600, absint( $ttl_seconds ) ) );
		$t = MAD4B_SCP_Schema::tables();
		$now_ts = time();
		$now = gmdate( 'Y-m-d H:i:s', $now_ts );
		$expires = gmdate( 'Y-m-d H:i:s', $now_ts + $ttl_seconds );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['work_leases']} WHERE work_id=%s FOR UPDATE", $work_id ), ARRAY_A );
			if ( ! is_array( $row ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'mad4b_lease_missing', 'Cannot reclaim unknown work.' );
			}
			$expired = empty( $row['expires_at'] ) || strtotime( (string) $row['expires_at'] . ' UTC' ) <= $now_ts;
			if ( 'active' !== (string) $row['status'] ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'mad4b_lease_terminal_reclaim_denied', 'Only an expired active lease may be reclaimed; terminal work requires a new work identity.' );
			}
			if ( ! $expired ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'mad4b_lease_still_active', 'Active unexpired work cannot be reclaimed.' );
			}
			$reconciliation = self::reconciliation_verified(
				'lease_reclaim',
				array(
					'work_id' => $work_id,
					'aggregate_type' => isset( $row['aggregate_type'] ) ? (string) $row['aggregate_type'] : '',
					'aggregate_id' => isset( $row['aggregate_id'] ) ? (string) $row['aggregate_id'] : '',
					'previous_worker_id' => isset( $row['worker_id'] ) ? (string) $row['worker_id'] : '',
					'previous_lease_epoch' => isset( $row['lease_epoch'] ) ? (int) $row['lease_epoch'] : 0,
					'previous_expected_revision' => isset( $row['expected_aggregate_revision'] ) ? (int) $row['expected_aggregate_revision'] : 0,
					'previous_expires_at' => isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '',
					'requested_worker_id' => $worker_id,
					'requested_expected_revision' => absint( $expected_revision ),
					'reconciliation_ref' => $reconciliation_ref,
				)
			);
			if ( is_wp_error( $reconciliation ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $reconciliation;
			}
			$next_epoch = (int) $row['lease_epoch'] + 1;
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$t['work_leases']} SET worker_id=%s,lease_epoch=%d,expected_aggregate_revision=%d,status='active',acquired_at=%s,heartbeat_at=%s,expires_at=%s,reconciliation_ref=%s,updated_at=%s WHERE id=%d AND lease_epoch=%d",
				$worker_id, $next_epoch, absint( $expected_revision ), $now, $now, $expires, $reconciliation_ref, $now, (int) $row['id'], (int) $row['lease_epoch']
			) );
			if ( 1 !== (int) $updated ) throw new RuntimeException( 'lease_reclaim_cas_failed' );
			$wpdb->query( 'COMMIT' );
			return self::lease_receipt( $work_id, (string) $row['aggregate_type'], (string) $row['aggregate_id'], $worker_id, $next_epoch, $expected_revision, $now, $now, $expires, 'active', $reconciliation_ref );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'mad4b_lease_reclaim_failed', 'Unable to reclaim durable work lease.', array( 'cause' => $e->getMessage() ) );
		}
	}

	public static function heartbeat( $work_id, $worker_id, $lease_epoch, $ttl_seconds = 120 ) {
		global $wpdb;
		$work_id = strtolower( trim( (string) $work_id ) );
		$worker_id = trim( (string) $worker_id );
		$lease_epoch = absint( $lease_epoch );
		$ttl_seconds = max( 30, min( 3600, absint( $ttl_seconds ) ) );
		$t = MAD4B_SCP_Schema::tables();
		$now_ts = time();
		$now = gmdate( 'Y-m-d H:i:s', $now_ts );
		$expires = gmdate( 'Y-m-d H:i:s', $now_ts + $ttl_seconds );
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$t['work_leases']} SET heartbeat_at=%s,expires_at=%s,updated_at=%s WHERE work_id=%s AND worker_id=%s AND lease_epoch=%d AND status='active' AND expires_at>%s",
			$now, $expires, $now, $work_id, $worker_id, $lease_epoch, $now
		) );
		if ( 1 !== (int) $updated ) return new WP_Error( 'mad4b_lease_heartbeat_fenced', 'Heartbeat rejected because worker/epoch is stale, expired or not active.' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['work_leases']} WHERE work_id=%s LIMIT 1", $work_id ), ARRAY_A );
		return self::lease_receipt_from_row( $row );
	}

	public static function assert_fencing_token( $work_id, $worker_id, $lease_epoch, $expected_revision ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['work_leases']} WHERE work_id=%s LIMIT 1", strtolower( trim( (string) $work_id ) ) ), ARRAY_A );
		if ( ! is_array( $row ) ) return new WP_Error( 'mad4b_fence_missing', 'Durable work lease is missing.' );
		if ( (int) $lease_epoch < (int) $row['lease_epoch'] ) return new WP_Error( 'mad4b_fence_epoch_stale', 'Zombie worker fencing epoch is stale.' );
		if ( (int) $lease_epoch > (int) $row['lease_epoch'] ) return new WP_Error( 'mad4b_fence_epoch_unknown', 'Provided fencing epoch is ahead of the authoritative lease.' );
		if ( ! hash_equals( (string) $row['worker_id'], trim( (string) $worker_id ) ) ) return new WP_Error( 'mad4b_fence_worker_mismatch', 'Worker does not own the authoritative lease epoch.' );
		if ( 'active' !== (string) $row['status'] ) return new WP_Error( 'mad4b_fence_lease_inactive', 'Authoritative lease is not active.' );
		if ( empty( $row['expires_at'] ) || strtotime( (string) $row['expires_at'] . ' UTC' ) <= time() ) return new WP_Error( 'mad4b_fence_lease_expired', 'Authoritative lease expired before commit.' );
		if ( (int) $row['expected_aggregate_revision'] !== absint( $expected_revision ) ) return new WP_Error( 'mad4b_fence_revision_mismatch', 'Lease expected aggregate revision changed.' );
		return self::lease_receipt_from_row( $row );
	}

	public static function complete_lease( $work_id, $worker_id, $lease_epoch, $status = 'completed' ) {
		global $wpdb;
		$status = sanitize_key( (string) $status );
		if ( ! in_array( $status, array( 'completed', 'blocked', 'failed', 'cancelled' ), true ) ) return new WP_Error( 'mad4b_lease_terminal_status_invalid', 'Lease terminal status is invalid.' );
		$t = MAD4B_SCP_Schema::tables();
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$t['work_leases']} SET status=%s,updated_at=%s WHERE work_id=%s AND worker_id=%s AND lease_epoch=%d AND status='active'",
			$status, gmdate( 'Y-m-d H:i:s' ), strtolower( trim( (string) $work_id ) ), trim( (string) $worker_id ), absint( $lease_epoch )
		) );
		return 1 === (int) $updated ? true : new WP_Error( 'mad4b_lease_complete_fenced', 'Lease completion was rejected by current ownership/fencing state.' );
	}

	public static function enqueue_outbox( array $record ) {
		global $wpdb;
		$required = array( 'job_id', 'expected_job_revision', 'provider_id', 'capability_id', 'workflow_plan_sha256', 'idempotency_key', 'request_sha256' );
		foreach ( $required as $field ) if ( ! isset( $record[ $field ] ) || '' === trim( (string) $record[ $field ] ) ) return new WP_Error( 'mad4b_outbox_field_missing', 'Outbox record is missing ' . $field . '.' );
		$job_id = strtolower( trim( (string) $record['job_id'] ) );
		$expected_job_revision = absint( $record['expected_job_revision'] );
		$provider_id = sanitize_key( (string) $record['provider_id'] );
		$capability_id = trim( sanitize_text_field( (string) $record['capability_id'] ) );
		$idempotency_key = trim( sanitize_text_field( (string) $record['idempotency_key'] ) );
		$workflow_plan_sha256 = strtolower( trim( (string) $record['workflow_plan_sha256'] ) );
		$request_sha256 = strtolower( trim( (string) $record['request_sha256'] ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $job_id ) ) return new WP_Error( 'mad4b_outbox_job_invalid', 'Outbox job ID is invalid.' );
		if ( $expected_job_revision < 1 ) return new WP_Error( 'mad4b_outbox_revision_invalid', 'Outbox expected job revision must be positive.' );
		if ( '' === $provider_id ) return new WP_Error( 'mad4b_outbox_provider_invalid', 'Outbox provider ID is invalid.' );
		if ( '' === $capability_id || strlen( $capability_id ) > 191 ) return new WP_Error( 'mad4b_outbox_capability_invalid', 'Outbox capability ID is invalid.' );
		if ( '' === $idempotency_key || strlen( $idempotency_key ) > 191 ) return new WP_Error( 'mad4b_outbox_idempotency_key_invalid', 'Outbox idempotency key is invalid.' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $workflow_plan_sha256 ) || ! preg_match( '/^[a-f0-9]{64}$/', $request_sha256 ) ) return new WP_Error( 'mad4b_outbox_hash_invalid', 'Outbox workflow/request hash is invalid.' );
		$payload = isset( $record['payload'] ) ? $record['payload'] : array();
		$payload_json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $payload_json ) || strlen( $payload_json ) > 262144 ) return new WP_Error( 'mad4b_outbox_payload_invalid', 'Outbox payload is invalid or exceeds bounded storage.' );
		$t = MAD4B_SCP_Schema::tables();
		$outbox_id = wp_generate_uuid4();
		$now = gmdate( 'Y-m-d H:i:s' );
		$available = $now;
		if ( array_key_exists( 'available_at', $record ) && null !== $record['available_at'] && '' !== trim( (string) $record['available_at'] ) ) {
			$available_ts = is_string( $record['available_at'] ) ? strtotime( $record['available_at'] ) : false;
			if ( false === $available_ts ) return new WP_Error( 'mad4b_outbox_available_at_invalid', 'Outbox available_at is invalid.' );
			$available = gmdate( 'Y-m-d H:i:s', $available_ts );
		}
		$ok = $wpdb->insert( $t['outbox'], array(
			'outbox_id' => $outbox_id,
			'job_id' => $job_id,
			'expected_job_revision' => $expected_job_revision,
			'provider_id' => $provider_id,
			'capability_id' => $capability_id,
			'workflow_plan_sha256' => $workflow_plan_sha256,
			'idempotency_key' => $idempotency_key,
			'request_sha256' => $request_sha256,
			'payload_json' => $payload_json,
			'status' => 'pending',
			'attempts' => 0,
			'provider_execution_ref' => '',
			'last_error_class' => '',
			'available_at' => $available,
			'created_at' => $now,
			'updated_at' => $now,
		) );
		if ( false === $ok ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['outbox']} WHERE provider_id=%s AND idempotency_key=%s LIMIT 1", $provider_id, $idempotency_key ), ARRAY_A );
			if ( is_array( $existing )
				&& hash_equals( (string) $existing['request_sha256'], $request_sha256 )
				&& hash_equals( (string) $existing['job_id'], $job_id )
				&& (int) $existing['expected_job_revision'] === $expected_job_revision
				&& hash_equals( (string) $existing['capability_id'], $capability_id )
				&& hash_equals( (string) $existing['workflow_plan_sha256'], $workflow_plan_sha256 ) ) return $existing;
			return new WP_Error( 'mad4b_outbox_idempotency_conflict', 'Provider outbox idempotency key conflicts with a different logical request.' );
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['outbox']} WHERE outbox_id=%s LIMIT 1", $outbox_id ), ARRAY_A );
	}

	public static function accept_inbox( $provider_id, $provider_event_id, $job_id, $payload_sha256, $provider_execution_ref = '' ) {
		global $wpdb;
		$provider_id = sanitize_key( (string) $provider_id );
		$provider_event_id = trim( (string) $provider_event_id );
		$job_id = strtolower( trim( (string) $job_id ) );
		$provider_execution_ref = trim( (string) $provider_execution_ref );
		$payload_sha256 = strtolower( trim( (string) $payload_sha256 ) );
		if ( '' === $provider_id || '' === $provider_event_id || strlen( $provider_event_id ) > 191 || ! preg_match( '/^[a-f0-9-]{36}$/', $job_id ) || ! preg_match( '/^[a-f0-9]{64}$/', $payload_sha256 ) ) return new WP_Error( 'mad4b_inbox_identity_invalid', 'Provider inbox identity is invalid.' );
		if ( strlen( $provider_execution_ref ) > 191 ) return new WP_Error( 'mad4b_inbox_execution_ref_invalid', 'Provider execution reference is too long.' );
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert( $t['inbox'], array(
			'provider_id' => $provider_id,
			'provider_event_id' => $provider_event_id,
			'job_id' => $job_id,
			'payload_sha256' => $payload_sha256,
			'status' => 'accepted',
			'provider_execution_ref' => $provider_execution_ref,
			'result_ref' => '',
			'received_at' => $now,
			'processed_at' => null,
		) );
		if ( false !== $inserted ) return array( 'contract' => self::INBOX_CONTRACT, 'duplicate' => false, 'provider_id' => $provider_id, 'provider_event_id' => $provider_event_id );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['inbox']} WHERE provider_id=%s AND provider_event_id=%s LIMIT 1", $provider_id, $provider_event_id ), ARRAY_A );
		if ( ! is_array( $row ) ) return new WP_Error( 'mad4b_inbox_accept_failed', 'Unable to accept or read provider event.' );
		if ( ! hash_equals( (string) $row['payload_sha256'], $payload_sha256 ) ) return new WP_Error( 'mad4b_inbox_event_conflict', 'Duplicate provider event ID carries a different payload hash.' );
		if ( ! hash_equals( (string) $row['job_id'], $job_id ) ) return new WP_Error( 'mad4b_inbox_job_conflict', 'Duplicate provider event ID is already bound to a different job.' );
		$stored_execution_ref = isset( $row['provider_execution_ref'] ) ? (string) $row['provider_execution_ref'] : '';
		if ( '' !== $provider_execution_ref && '' !== $stored_execution_ref && ! hash_equals( $stored_execution_ref, $provider_execution_ref ) ) return new WP_Error( 'mad4b_inbox_execution_ref_conflict', 'Duplicate provider event ID is already bound to a different provider execution reference.' );
		return array( 'contract' => self::INBOX_CONTRACT, 'duplicate' => true, 'provider_id' => $provider_id, 'provider_event_id' => $provider_event_id, 'status' => (string) $row['status'], 'result_ref' => (string) $row['result_ref'] );
	}

	private static function reconciliation_verified( $kind, array $context ) {
		$kind = sanitize_key( (string) $kind );
		$ref = isset( $context['reconciliation_ref'] ) ? trim( (string) $context['reconciliation_ref'] ) : '';
		if ( '' === $kind || '' === $ref ) return new WP_Error( 'mad4b_reconciliation_evidence_required', 'Durable reclaim requires reconciliation evidence.' );
		$verified = apply_filters( 'mad4b_scp_durable_reconciliation_verified', false, $kind, $context );
		if ( true !== $verified ) return new WP_Error(
			'mad4b_reconciliation_unverified',
			'Durable reclaim is blocked until provider/state reconciliation is independently verified.',
			array( 'kind' => $kind, 'reconciliation_ref' => substr( $ref, 0, 191 ) )
		);
		return true;
	}

	private static function validate_lease_identity( &$work_id, &$aggregate_type, &$aggregate_id, &$worker_id, $expected_revision ) {
		$work_id = strtolower( trim( (string) $work_id ) );
		$aggregate_type = sanitize_key( (string) $aggregate_type );
		$aggregate_id = trim( (string) $aggregate_id );
		$worker_id = trim( (string) $worker_id );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $work_id ) || '' === $aggregate_type || '' === $aggregate_id || strlen( $aggregate_id ) > 191 || '' === $worker_id || strlen( $worker_id ) > 191 || absint( $expected_revision ) < 1 ) {
			return new WP_Error( 'mad4b_lease_identity_invalid', 'Durable work lease identity is invalid.' );
		}
		return true;
	}

	private static function lease_receipt_from_row( $row ) {
		if ( ! is_array( $row ) ) return new WP_Error( 'mad4b_lease_missing', 'Durable work lease is missing.' );
		return self::lease_receipt(
			(string) $row['work_id'], (string) $row['aggregate_type'], (string) $row['aggregate_id'], (string) $row['worker_id'],
			(int) $row['lease_epoch'], (int) $row['expected_aggregate_revision'], (string) $row['acquired_at'], (string) $row['heartbeat_at'],
			(string) $row['expires_at'], (string) $row['status'], (string) $row['reconciliation_ref']
		);
	}

	private static function lease_receipt( $work_id, $aggregate_type, $aggregate_id, $worker_id, $lease_epoch, $expected_revision, $acquired_at, $heartbeat_at, $expires_at, $status, $reconciliation_ref ) {
		return array(
			'contract' => self::LEASE_CONTRACT,
			'work_id' => (string) $work_id,
			'aggregate_type' => (string) $aggregate_type,
			'aggregate_id' => (string) $aggregate_id,
			'worker_id' => (string) $worker_id,
			'lease_epoch' => (int) $lease_epoch,
			'expected_aggregate_revision' => (int) $expected_revision,
			'acquired_at' => (string) $acquired_at,
			'heartbeat_at' => (string) $heartbeat_at,
			'expires_at' => (string) $expires_at,
			'status' => (string) $status,
			'reconciliation_ref' => (string) $reconciliation_ref,
		);
	}
}
