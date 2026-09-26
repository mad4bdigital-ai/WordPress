<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable semantic work queue for governed external executors.
 *
 * No arbitrary command, URL, PHP, SQL or filesystem execution exists here.
 * The queue coordinates only explicitly registered semantic operation kinds.
 */
final class MAD4B_SCP_Remote_Work_Queue {
	const CONTRACT = 'mad4b.remote-work-queue.v1';
	const OPTION = 'mad4b_scp_remote_work_queue_v1';
	const LOCK_OPTION = 'mad4b_scp_remote_work_queue_lock_v1';
	const MAX_JOBS = 100;
	const LOCK_TTL = 20;
	const MIN_LEASE_SECONDS = 60;
	const MAX_LEASE_SECONDS = 900;

	private static function allowed_operations() {
		return array(
			'frontend_performance_sampling' => array(
				'executor' => 'external_browser_agent',
				'authority_surface' => 'mad4b-enrollment',
				'production_policy' => 'deny',
			),
			'browser_acceptance_execution' => array(
				'executor' => 'external_browser_agent',
				'authority_surface' => 'mad4b-enrollment',
				'production_policy' => 'deny',
			),
		);
	}

	private static function now() { return time(); }

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}

	private static function stable_json( $value ) {
		$value = self::canonicalize( $value );
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}


	private static function delete_option_if_unchanged( $name, $expected ) {
		global $wpdb;
		if ( ! isset( $wpdb->options ) ) return false;
		$serialized = maybe_serialize( $expected );
		$deleted = $wpdb->delete(
			$wpdb->options,
			array( 'option_name' => (string) $name, 'option_value' => $serialized ),
			array( '%s', '%s' )
		);
		if ( 1 === (int) $deleted ) {
			wp_cache_delete( (string) $name, 'options' );
			return true;
		}
		return false;
	}

	private static function jobs() {
		$jobs = get_option( self::OPTION, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	private static function prune_reclaimable_jobs( array $jobs ) {
		$reclaimable = array();
		foreach ( $jobs as $job_id => $job ) {
			if ( ! is_array( $job ) ) { $reclaimable[ $job_id ] = 0; continue; }
			$status = isset( $job['status'] ) ? (string) $job['status'] : '';
			$expired = self::now() > (int) ( isset( $job['expires_at_epoch'] ) ? $job['expires_at_epoch'] : 0 );
			if ( 'completed' === $status || $expired ) {
				$reclaimable[ $job_id ] = (int) ( isset( $job['created_at_epoch'] ) ? $job['created_at_epoch'] : 0 );
			}
		}
		asort( $reclaimable, SORT_NUMERIC );
		foreach ( array_keys( $reclaimable ) as $job_id ) {
			if ( count( $jobs ) < self::MAX_JOBS ) break;
			unset( $jobs[ $job_id ] );
		}
		return $jobs;
	}

	private static function save_jobs( array $jobs ) {
		if ( count( $jobs ) > self::MAX_JOBS ) return false;
		update_option( self::OPTION, $jobs, false );
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) return false;
		return hash_equals(
			hash( 'sha256', self::stable_json( $jobs ) ),
			hash( 'sha256', self::stable_json( $stored ) )
		);
	}

	private static function acquire_lock( $operation ) {
		$owner = function_exists( 'wp_generate_uuid4' ) ? strtolower( wp_generate_uuid4() ) : hash( 'sha256', uniqid( 'mad4b-work-', true ) );
		$record = array(
			'owner' => $owner,
			'operation' => sanitize_key( (string) $operation ),
			'expires_at_epoch' => self::now() + self::LOCK_TTL,
		);
		if ( add_option( self::LOCK_OPTION, $record, '', false ) ) return $owner;
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && self::now() > (int) ( isset( $current['expires_at_epoch'] ) ? $current['expires_at_epoch'] : 0 ) ) {
			if ( ! self::delete_option_if_unchanged( self::LOCK_OPTION, $current ) ) {
				return new WP_Error( 'mad4b_remote_work_queue_lock_reclaim_raced', 'Remote work queue lock changed while reclaiming an expired lease.' );
			}
			if ( add_option( self::LOCK_OPTION, $record, '', false ) ) return $owner;
		}
		return new WP_Error( 'mad4b_remote_work_queue_busy', 'Remote work queue is currently locked by another mutation.' );
	}

	private static function release_lock( $owner ) {
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && isset( $current['owner'] ) && hash_equals( (string) $current['owner'], (string) $owner ) ) {
			self::delete_option_if_unchanged( self::LOCK_OPTION, $current );
		}
	}

	private static function with_lock( $operation, $callback ) {
		$owner = self::acquire_lock( $operation );
		if ( is_wp_error( $owner ) ) return $owner;
		try {
			return call_user_func( $callback );
		} finally {
			self::release_lock( $owner );
		}
	}

	private static function identity_valid( array $identity ) {
		return 1 === preg_match( '/^[a-f0-9]{40}$/', (string) ( isset( $identity['source_commit_sha'] ) ? $identity['source_commit_sha'] : '' ) )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( isset( $identity['build_fingerprint'] ) ? $identity['build_fingerprint'] : '' ) )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( isset( $identity['package_manifest_digest'] ) ? $identity['package_manifest_digest'] : '' ) );
	}

	public static function identity_matches( array $left, array $right ) {
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $key ) {
			$a = strtolower( trim( (string) ( isset( $left[ $key ] ) ? $left[ $key ] : '' ) ) );
			$b = strtolower( trim( (string) ( isset( $right[ $key ] ) ? $right[ $key ] : '' ) ) );
			if ( '' === $a || '' === $b || ! hash_equals( $a, $b ) ) return false;
		}
		return true;
	}

	private static function public_job( array $job ) {
		unset( $job['lease_token_sha256'] );
		return $job;
	}

	private static function effective_job( array $job ) {
		$status = isset( $job['status'] ) ? (string) $job['status'] : '';
		if ( in_array( $status, array( 'pending', 'claimed' ), true ) && self::now() > (int) ( isset( $job['expires_at_epoch'] ) ? $job['expires_at_epoch'] : 0 ) ) {
			$job['effective_status'] = 'expired';
		} elseif ( 'claimed' === $status && self::now() > (int) ( isset( $job['lease_expires_at_epoch'] ) ? $job['lease_expires_at_epoch'] : 0 ) ) {
			$job['effective_status'] = 'lease_expired';
		} else {
			$job['effective_status'] = $status;
		}
		return $job;
	}

	public static function enqueue( $operation_id, array $payload, array $expected_identity, $ttl_seconds = 3600 ) {
		$operation_id = sanitize_key( (string) $operation_id );
		if ( ! isset( self::allowed_operations()[ $operation_id ] ) ) return new WP_Error( 'mad4b_remote_work_operation_not_allowed', 'Remote work operation is not registered.' );
		foreach ( $expected_identity as $key => $value ) $expected_identity[ $key ] = strtolower( trim( (string) $value ) );
		if ( ! self::identity_valid( $expected_identity ) ) return new WP_Error( 'mad4b_remote_work_identity_invalid', 'Remote work requires complete exact-build identity.' );
		$ttl_seconds = max( 300, min( DAY_IN_SECONDS, (int) $ttl_seconds ) );
		$semantic_digest = hash( 'sha256', self::stable_json( array(
			'operation_id' => $operation_id,
			'payload' => $payload,
			'expected_identity' => $expected_identity,
		) ) );

		return self::with_lock( 'enqueue', static function () use ( $operation_id, $payload, $expected_identity, $ttl_seconds, $semantic_digest ) {
			$jobs = self::prune_reclaimable_jobs( self::jobs() );
			foreach ( $jobs as $existing ) {
				if ( ! is_array( $existing ) ) continue;
				if ( hash_equals( $semantic_digest, (string) ( isset( $existing['semantic_digest'] ) ? $existing['semantic_digest'] : '' ) )
					&& in_array( (string) ( isset( $existing['status'] ) ? $existing['status'] : '' ), array( 'pending', 'claimed' ), true )
					&& self::now() <= (int) ( isset( $existing['expires_at_epoch'] ) ? $existing['expires_at_epoch'] : 0 ) ) {
					return array( 'state' => 'already_queued', 'job' => self::public_job( $existing ) );
				}
			}
			if ( count( $jobs ) >= self::MAX_JOBS ) {
				return new WP_Error(
					'mad4b_remote_work_queue_capacity_exhausted',
					'Remote work queue is full of active non-reclaimable jobs; no active work was discarded.'
				);
			}
			$job_id = strtolower( wp_generate_uuid4() );
			$job = array(
				'contract' => self::CONTRACT,
				'job_id' => $job_id,
				'operation_id' => $operation_id,
				'status' => 'pending',
				'payload' => $payload,
				'expected_identity' => $expected_identity,
				'semantic_digest' => $semantic_digest,
				'created_at' => gmdate( 'c' ),
				'created_at_epoch' => self::now(),
				'expires_at' => gmdate( 'c', self::now() + $ttl_seconds ),
				'expires_at_epoch' => self::now() + $ttl_seconds,
				'claim_generation' => 0,
				'executor_id' => '',
				'lease_expires_at_epoch' => 0,
				'lease_token_sha256' => '',
				'completed_at' => '',
				'result' => array(),
			);
			$jobs[ $job_id ] = $job;
			if ( ! self::save_jobs( $jobs ) ) return new WP_Error( 'mad4b_remote_work_queue_persist_failed', 'Remote work job could not be durably read back.' );
			return array( 'state' => 'queued', 'job' => self::public_job( $job ) );
		} );
	}

	public static function list_jobs( $operation_id = '' ) {
		$operation_id = sanitize_key( (string) $operation_id );
		$items = array();
		foreach ( self::jobs() as $job ) {
			if ( ! is_array( $job ) ) continue;
			if ( '' !== $operation_id && $operation_id !== (string) ( isset( $job['operation_id'] ) ? $job['operation_id'] : '' ) ) continue;
			$items[] = self::public_job( self::effective_job( $job ) );
		}
		usort( $items, static function ( $a, $b ) {
			return (int) ( isset( $b['created_at_epoch'] ) ? $b['created_at_epoch'] : 0 )
				<=> (int) ( isset( $a['created_at_epoch'] ) ? $a['created_at_epoch'] : 0 );
		} );
		return array( 'contract' => self::CONTRACT, 'read_only' => true, 'mutation_performed' => false, 'count' => count( $items ), 'items' => $items );
	}

	public static function get_job( $job_id ) {
		$job_id = strtolower( trim( (string) $job_id ) );
		$jobs = self::jobs();
		if ( ! isset( $jobs[ $job_id ] ) || ! is_array( $jobs[ $job_id ] ) ) return new WP_Error( 'mad4b_remote_work_job_missing', 'Remote work job was not found.' );
		return self::public_job( self::effective_job( $jobs[ $job_id ] ) );
	}

	private static function fresh_lease_token() {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( Throwable $error ) {
			return hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) . '|' . wp_rand() );
		}
	}

	public static function claim( $job_id, $executor_id, $lease_seconds, array $current_identity ) {
		$job_id = strtolower( trim( (string) $job_id ) );
		$executor_id = sanitize_key( (string) $executor_id );
		if ( '' === $executor_id ) return new WP_Error( 'mad4b_remote_work_executor_invalid', 'Executor identity is required.' );
		$lease_seconds = max( self::MIN_LEASE_SECONDS, min( self::MAX_LEASE_SECONDS, (int) $lease_seconds ) );

		return self::with_lock( 'claim', static function () use ( $job_id, $executor_id, $lease_seconds, $current_identity ) {
			$jobs = self::jobs();
			if ( ! isset( $jobs[ $job_id ] ) || ! is_array( $jobs[ $job_id ] ) ) return new WP_Error( 'mad4b_remote_work_job_missing', 'Remote work job was not found.' );
			$job = $jobs[ $job_id ];
			if ( self::now() > (int) ( isset( $job['expires_at_epoch'] ) ? $job['expires_at_epoch'] : 0 ) ) return new WP_Error( 'mad4b_remote_work_job_expired', 'Remote work job expired before claim.' );
			if ( ! self::identity_matches( (array) $job['expected_identity'], $current_identity ) ) return new WP_Error( 'mad4b_remote_work_build_changed', 'Exact build identity changed before work claim.' );
			$status = isset( $job['status'] ) ? (string) $job['status'] : '';
			if ( 'claimed' === $status && self::now() <= (int) ( isset( $job['lease_expires_at_epoch'] ) ? $job['lease_expires_at_epoch'] : 0 ) ) return new WP_Error( 'mad4b_remote_work_already_claimed', 'Remote work job currently has an active lease.' );
			if ( ! in_array( $status, array( 'pending', 'claimed' ), true ) ) return new WP_Error( 'mad4b_remote_work_job_not_claimable', 'Remote work job is not claimable in its current state.' );

			$token = self::fresh_lease_token();
			$job['status'] = 'claimed';
			$job['executor_id'] = $executor_id;
			$job['claim_generation'] = max( 0, (int) ( isset( $job['claim_generation'] ) ? $job['claim_generation'] : 0 ) ) + 1;
			$job['claimed_at'] = gmdate( 'c' );
			$job['lease_expires_at_epoch'] = self::now() + $lease_seconds;
			$job['lease_expires_at'] = gmdate( 'c', $job['lease_expires_at_epoch'] );
			$job['lease_token_sha256'] = hash( 'sha256', $token );
			$jobs[ $job_id ] = $job;
			if ( ! self::save_jobs( $jobs ) ) return new WP_Error( 'mad4b_remote_work_claim_persist_failed', 'Remote work lease could not be durably read back.' );
			return array( 'contract' => self::CONTRACT, 'state' => 'claimed', 'lease_token' => $token, 'job' => self::public_job( $job ) );
		} );
	}

	public static function complete( $job_id, $executor_id, $lease_token, array $verified_result ) {
		$job_id = strtolower( trim( (string) $job_id ) );
		$executor_id = sanitize_key( (string) $executor_id );
		$lease_token = strtolower( trim( (string) $lease_token ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $lease_token ) ) return new WP_Error( 'mad4b_remote_work_lease_invalid', 'Remote work lease token is invalid.' );

		return self::with_lock( 'complete', static function () use ( $job_id, $executor_id, $lease_token, $verified_result ) {
			$jobs = self::jobs();
			if ( ! isset( $jobs[ $job_id ] ) || ! is_array( $jobs[ $job_id ] ) ) return new WP_Error( 'mad4b_remote_work_job_missing', 'Remote work job was not found.' );
			$job = $jobs[ $job_id ];
			if ( 'claimed' !== ( isset( $job['status'] ) ? (string) $job['status'] : '' ) ) return new WP_Error( 'mad4b_remote_work_job_not_claimed', 'Remote work job has no active claim.' );
			if ( self::now() > (int) ( isset( $job['lease_expires_at_epoch'] ) ? $job['lease_expires_at_epoch'] : 0 ) ) return new WP_Error( 'mad4b_remote_work_lease_expired', 'Remote work lease expired before completion.' );
			if ( ! hash_equals( (string) ( isset( $job['executor_id'] ) ? $job['executor_id'] : '' ), $executor_id ) ) return new WP_Error( 'mad4b_remote_work_executor_mismatch', 'Remote work executor does not own the active lease.' );
			if ( ! hash_equals( (string) ( isset( $job['lease_token_sha256'] ) ? $job['lease_token_sha256'] : '' ), hash( 'sha256', $lease_token ) ) ) return new WP_Error( 'mad4b_remote_work_lease_mismatch', 'Remote work lease token does not match the active claim.' );
			$job['status'] = 'completed';
			$job['completed_at'] = gmdate( 'c' );
			$job['result'] = $verified_result;
			$job['lease_token_sha256'] = '';
			$job['lease_expires_at_epoch'] = 0;
			$jobs[ $job_id ] = $job;
			if ( ! self::save_jobs( $jobs ) ) return new WP_Error( 'mad4b_remote_work_completion_persist_failed', 'Remote work completion could not be durably read back.' );
			return array( 'contract' => self::CONTRACT, 'state' => 'completed', 'job' => self::public_job( $job ) );
		} );
	}
}
