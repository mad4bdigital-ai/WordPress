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
		$base = array(
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
		$registered = class_exists( 'MAD4B_SCP_Search_Work_Operations' ) ? MAD4B_SCP_Search_Work_Operations::definitions( $base ) : $base;
		return class_exists( 'MAD4B_SCP_Assistant_Read_Work_Operations', false )
			? MAD4B_SCP_Assistant_Read_Work_Operations::definitions( $registered ) : $registered;
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
			$reclaimable_terminal = in_array( $status, array( 'completed', 'cancelled_no_effect' ), true );
			$expired_never_claimed = 'pending' === $status && $expired;
			if ( $reclaimable_terminal || $expired_never_claimed ) {
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
		if ( 'claimed' === $status && self::now() > (int) ( isset( $job['lease_expires_at_epoch'] ) ? $job['lease_expires_at_epoch'] : 0 ) ) {
			$job['effective_status'] = 'reconciling';
			$job['lease_expired'] = true;
			$job['reconciliation_required'] = true;
			$job['blind_retry_allowed'] = false;
			$job['client_action'] = 'reconcile_provider_state_before_any_retry';
		} elseif ( 'pending' === $status && self::now() > (int) ( isset( $job['expires_at_epoch'] ) ? $job['expires_at_epoch'] : 0 ) ) {
			$job['effective_status'] = 'expired';
		} else {
			$job['effective_status'] = $status;
		}
		return $job;
	}

	public static function enqueue( $operation_id, array $payload, array $expected_identity, $ttl_seconds = 3600 ) {
		$operation_id = (string) $operation_id;
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,95}$/D', $operation_id ) ) return new WP_Error( 'mad4b_remote_work_operation_not_allowed', 'Remote work operation is not canonical.' );
		if ( ! isset( self::allowed_operations()[ $operation_id ] ) ) return new WP_Error( 'mad4b_remote_work_operation_not_allowed', 'Remote work operation is not registered.' );
		$definition = self::allowed_operations()[ $operation_id ];
		if ( isset( $definition['validate_payload'] ) && ! call_user_func( $definition['validate_payload'], $payload ) ) return new WP_Error( 'mad4b_remote_work_payload_invalid', 'Semantic work payload is outside its registered contract.' );
		// Assistant jobs may request bounded discovery in Staging only, never
		// issue grants, mutate providers or cross exact Site/Origin/Restore identity.
		if ( 0 === strpos( $operation_id, 'assistant_' )
			&& ( ! class_exists( 'MAD4B_SCP_Assistant_Read_Work_Operations', false )
				|| ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_for_operation( $operation_id, $payload )
				|| ! MAD4B_SCP_Assistant_Read_Work_Operations::runtime_binding_matches( $payload, $expected_identity ) ) ) {
			return new WP_Error( 'mad4b_remote_work_assistant_binding_invalid',
				'Assistant read work requires matching Staging-only runtime, operation and restore identity.' );
		}
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
					&& in_array( (string) ( isset( $existing['status'] ) ? $existing['status'] : '' ), array( 'pending', 'claimed', 'reconciling' ), true )
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
				'cancel_requested_at' => '',
				'cancel_reason_code' => '',
				'cancel_generation' => 0,
				'cancel_acknowledged_at' => '',
				'cancel_acknowledged_generation' => 0,
				'provider_checkpoint' => 'not_entered',
				'provider_entry_at' => '',
				'provider_returned_at' => '',
				'provider_side_effect_possible' => false,
				'provider_cancel_required' => false,
				'reconciliation_required' => false,
				'blind_retry_allowed' => false,
				'client_action' => '',
				'result' => array(),
			);
			$jobs[ $job_id ] = $job;
			if ( ! self::save_jobs( $jobs ) ) return new WP_Error( 'mad4b_remote_work_queue_persist_failed', 'Remote work job could not be durably read back.' );
			return array( 'state' => 'queued', 'job' => self::public_job( $job ) );
		} );
	}

	public static function list_jobs( $operation_id = '' ) {
		$operation_id = (string) $operation_id;
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

	private static function assistant_job_binding_matches( array $job ) {
		return class_exists( 'MAD4B_SCP_Assistant_Read_Work_Operations', false )
			&& is_array( $job['payload'] ?? null ) && is_array( $job['expected_identity'] ?? null )
			&& MAD4B_SCP_Assistant_Read_Work_Operations::validate_for_operation( $job['operation_id'], $job['payload'] )
			&& MAD4B_SCP_Assistant_Read_Work_Operations::runtime_binding_matches( $job['payload'], $job['expected_identity'] );
	}

	private static function assistant_binding_error() {
		return new WP_Error( 'mad4b_remote_work_assistant_binding_invalid',
			'Assistant read work changed its exact Staging identity; cancel or reconcile before further execution.',
			array( 'provider_entry_allowed' => false, 'reconciliation_required' => true, 'blind_retry_allowed' => false ) );
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
			if ( 'claimed' === $status ) {
				if ( self::now() <= (int) ( isset( $job['lease_expires_at_epoch'] ) ? $job['lease_expires_at_epoch'] : 0 ) ) {
					return new WP_Error( 'mad4b_remote_work_already_claimed', 'Remote work job currently has an active lease.' );
				}
				$job['status'] = 'reconciling';
				$job['reconciliation_required'] = true;
				$job['blind_retry_allowed'] = false;
				$job['client_action'] = 'reconcile_provider_state_before_any_retry';
				$jobs[ $job_id ] = $job;
				if ( ! self::save_jobs( $jobs ) ) return new WP_Error( 'mad4b_remote_work_reconciliation_persist_failed', 'Expired claimed work could not be quarantined for reconciliation.' );
				return new WP_Error( 'mad4b_remote_work_reconciliation_required', 'Expired claimed work may have entered the provider and cannot be replayed before reconciliation.', array(
					'reconciliation_required' => true,
					'blind_retry_allowed' => false,
					'client_action' => 'reconcile_provider_state_before_any_retry',
				) );
			}
			if ( 'pending' !== $status ) return new WP_Error( 'mad4b_remote_work_job_not_claimable', 'Remote work job is not claimable in its current state.' );
			if ( 0 === strpos( (string) ( $job['operation_id'] ?? '' ), 'assistant_' )
				&& ! self::assistant_job_binding_matches( $job ) ) return self::assistant_binding_error();

			$token = self::fresh_lease_token();
			$job['status'] = 'claimed';
			$job['executor_id'] = $executor_id;
			$job['claim_generation'] = max( 0, (int) ( isset( $job['claim_generation'] ) ? $job['claim_generation'] : 0 ) ) + 1;
			$job['claimed_at'] = gmdate( 'c' );
			$job['lease_expires_at_epoch'] = self::now() + $lease_seconds;
			$job['lease_expires_at'] = gmdate( 'c', $job['lease_expires_at_epoch'] );
			$job['lease_token_sha256'] = hash( 'sha256', $token );
			$job['provider_checkpoint'] = 'not_entered';
			$job['provider_entry_at'] = '';
			$job['provider_returned_at'] = '';
			$job['provider_side_effect_possible'] = false;
			$job['provider_cancel_required'] = false;
			$jobs[ $job_id ] = $job;
			if ( ! self::save_jobs( $jobs ) ) return new WP_Error( 'mad4b_remote_work_claim_persist_failed', 'Remote work lease could not be durably read back.' );
			return array( 'contract' => self::CONTRACT, 'state' => 'claimed', 'lease_token' => $token, 'job' => self::public_job( $job ) );
		} );
	}

	public static function cancel( $job_id, $reason_code = 'cancel_requested' ) {
		$job_id = strtolower( trim( (string) $job_id ) );
		$reason_code = sanitize_key( (string) $reason_code );
		if ( '' === $reason_code ) $reason_code = 'cancel_requested';
		if ( strlen( $reason_code ) > 64 ) return new WP_Error( 'mad4b_remote_work_cancel_reason_invalid', 'Remote work cancellation reason code is too long.' );

		return self::with_lock( 'cancel', static function () use ( $job_id, $reason_code ) {
			$jobs = self::jobs();
			if ( ! isset( $jobs[ $job_id ] ) || ! is_array( $jobs[ $job_id ] ) ) return new WP_Error( 'mad4b_remote_work_job_missing', 'Remote work job was not found.' );
			$job = $jobs[ $job_id ];
			$status = isset( $job['status'] ) ? (string) $job['status'] : '';
			if ( 'completed' === $status ) return new WP_Error( 'mad4b_remote_work_cancel_completed_denied', 'Completed remote work cannot be retroactively cancelled.' );
			if ( 'cancelled_no_effect' === $status ) return array( 'contract' => self::CONTRACT, 'state' => 'cancelled_no_effect', 'job' => self::public_job( $job ) );
			if ( 'reconciling' === $status ) return array( 'contract' => self::CONTRACT, 'state' => 'reconciling', 'job' => self::public_job( $job ) );
			$job['cancel_requested_at'] = gmdate( 'c' );
			$job['cancel_reason_code'] = $reason_code;
			$job['cancel_generation'] = max( 0, (int) ( isset( $job['cancel_generation'] ) ? $job['cancel_generation'] : 0 ) ) + 1;
			$job['blind_retry_allowed'] = false;
			if ( 'pending' === $status ) {
				$job['status'] = 'cancelled_no_effect';
				$job['reconciliation_required'] = false;
				$job['provider_cancel_required'] = false;
				$job['cancel_acknowledged_at'] = gmdate( 'c' );
				$job['cancel_acknowledged_generation'] = (int) $job['cancel_generation'];
				$job['client_action'] = 'no_retry_required';
				$job['lease_token_sha256'] = '';
				$job['lease_expires_at_epoch'] = 0;
			} elseif ( 'claimed' === $status ) {
				$job['status'] = 'reconciling';
				$job['reconciliation_required'] = true;
				$job['provider_cancel_required'] = true;
				$job['client_action'] = 'propagate_cancel_then_reconcile_provider_state';
			} else {
				return new WP_Error( 'mad4b_remote_work_cancel_state_denied', 'Remote work cannot be cancelled from its current state.' );
			}
			$jobs[ $job_id ] = $job;
			if ( ! self::save_jobs( $jobs ) ) return new WP_Error( 'mad4b_remote_work_cancel_persist_failed', 'Remote work cancellation state could not be durably read back.' );
			return array( 'contract' => self::CONTRACT, 'state' => (string) $job['status'], 'job' => self::public_job( $job ) );
		} );
	}

	private static function validate_active_lease( array $job, $executor_id, $lease_token ) {
		$executor_id = sanitize_key( (string) $executor_id );
		$lease_token = strtolower( trim( (string) $lease_token ) );
		if ( '' === $executor_id || 1 !== preg_match( '/^[a-f0-9]{64}$/', $lease_token ) ) {
			return new WP_Error( 'mad4b_remote_work_lease_invalid', 'Remote work executor and lease token are required.' );
		}
		if ( ! hash_equals( (string) ( isset( $job['executor_id'] ) ? $job['executor_id'] : '' ), $executor_id ) ) {
			return new WP_Error( 'mad4b_remote_work_executor_mismatch', 'Remote work executor does not own the active lease.' );
		}
		if ( ! hash_equals( (string) ( isset( $job['lease_token_sha256'] ) ? $job['lease_token_sha256'] : '' ), hash( 'sha256', $lease_token ) ) ) {
			return new WP_Error( 'mad4b_remote_work_lease_mismatch', 'Remote work lease token does not match the active claim.' );
		}
		return true;
	}

	public static function cancellation_signal( $job_id, $executor_id, $lease_token ) {
		$job_id = strtolower( trim( (string) $job_id ) );
		$jobs = self::jobs();
		if ( ! isset( $jobs[ $job_id ] ) || ! is_array( $jobs[ $job_id ] ) ) return new WP_Error( 'mad4b_remote_work_job_missing', 'Remote work job was not found.' );
		$job = $jobs[ $job_id ];
		$lease = self::validate_active_lease( $job, $executor_id, $lease_token );
		if ( is_wp_error( $lease ) ) return $lease;
		$cancel_requested = '' !== (string) ( isset( $job['cancel_requested_at'] ) ? $job['cancel_requested_at'] : '' );
		return array(
			'contract' => 'mad4b.remote-work-cancellation-signal.v1',
			'job_id' => $job_id,
			'cancel_requested' => $cancel_requested,
			'cancel_generation' => (int) ( isset( $job['cancel_generation'] ) ? $job['cancel_generation'] : 0 ),
			'provider_checkpoint' => isset( $job['provider_checkpoint'] ) ? (string) $job['provider_checkpoint'] : 'not_entered',
			'provider_side_effect_possible' => ! empty( $job['provider_side_effect_possible'] ),
			'provider_cancel_required' => $cancel_requested && ! empty( $job['provider_cancel_required'] ),
			'reconciliation_required' => ! empty( $job['reconciliation_required'] ),
			'blind_retry_allowed' => false,
			'authorizing' => false,
		);
	}

	public static function provider_checkpoint( $job_id, $executor_id, $lease_token, $checkpoint ) {
		$job_id = strtolower( trim( (string) $job_id ) );
		$checkpoint = sanitize_key( (string) $checkpoint );
		if ( ! in_array( $checkpoint, array( 'provider_entered', 'provider_returned' ), true ) ) {
			return new WP_Error( 'mad4b_remote_work_provider_checkpoint_invalid', 'Remote work provider checkpoint is not registered.' );
		}
		return self::with_lock( 'provider_checkpoint', static function () use ( $job_id, $executor_id, $lease_token, $checkpoint ) {
			$jobs = self::jobs();
			if ( ! isset( $jobs[ $job_id ] ) || ! is_array( $jobs[ $job_id ] ) ) return new WP_Error( 'mad4b_remote_work_job_missing', 'Remote work job was not found.' );
			$job = $jobs[ $job_id ];
			if ( ! in_array( (string) ( isset( $job['status'] ) ? $job['status'] : '' ), array( 'claimed', 'reconciling' ), true ) ) {
				return new WP_Error( 'mad4b_remote_work_provider_checkpoint_state_denied', 'Remote work provider checkpoint requires an active or reconciling claim.' );
			}
			$lease = self::validate_active_lease( $job, $executor_id, $lease_token );
			if ( is_wp_error( $lease ) ) return $lease;
			$current = isset( $job['provider_checkpoint'] ) ? (string) $job['provider_checkpoint'] : 'not_entered';
			$cancel_requested = '' !== (string) ( isset( $job['cancel_requested_at'] ) ? $job['cancel_requested_at'] : '' );
			if ( 'provider_entered' === $checkpoint ) {
				if ( $cancel_requested && 'not_entered' === $current ) {
					return new WP_Error( 'mad4b_remote_work_cancel_before_provider_entry', 'Cancellation was observed before provider entry; provider execution must not start.', array(
						'cancel_generation' => (int) ( isset( $job['cancel_generation'] ) ? $job['cancel_generation'] : 0 ),
						'provider_entry_allowed' => false,
						'reconciliation_required' => true,
						'blind_retry_allowed' => false,
					) );
				}
				if ( ! in_array( $current, array( 'not_entered', 'provider_entered' ), true ) ) return new WP_Error( 'mad4b_remote_work_provider_checkpoint_regression', 'Provider checkpoint cannot move backward.' );
				if ( 0 === strpos( (string) ( $job['operation_id'] ?? '' ), 'assistant_' )
					&& ! self::assistant_job_binding_matches( $job ) ) return self::assistant_binding_error();
				$job['provider_checkpoint'] = 'provider_entered';
				$job['provider_entry_at'] = '' !== (string) $job['provider_entry_at'] ? (string) $job['provider_entry_at'] : gmdate( 'c' );
				$job['provider_side_effect_possible'] = true;
			} else {
				if ( ! in_array( $current, array( 'provider_entered', 'provider_returned' ), true ) ) return new WP_Error( 'mad4b_remote_work_provider_return_without_entry', 'Provider return cannot be recorded before provider entry.' );
				$job['provider_checkpoint'] = 'provider_returned';
				$job['provider_returned_at'] = gmdate( 'c' );
				$job['provider_side_effect_possible'] = true;
			}
			if ( $cancel_requested ) {
				$job['status'] = 'reconciling';
				$job['reconciliation_required'] = true;
				$job['provider_cancel_required'] = true;
				$job['client_action'] = 'propagate_cancel_then_reconcile_provider_state';
			}
			$jobs[ $job_id ] = $job;
			if ( ! self::save_jobs( $jobs ) ) return new WP_Error( 'mad4b_remote_work_provider_checkpoint_persist_failed', 'Remote work provider checkpoint could not be durably read back.' );
			return array( 'contract' => self::CONTRACT, 'state' => (string) $job['status'], 'job' => self::public_job( $job ) );
		} );
	}

	public static function acknowledge_cancellation( $job_id, $executor_id, $lease_token, $cancel_generation ) {
		$job_id = strtolower( trim( (string) $job_id ) );
		$cancel_generation = (int) $cancel_generation;
		return self::with_lock( 'acknowledge_cancellation', static function () use ( $job_id, $executor_id, $lease_token, $cancel_generation ) {
			$jobs = self::jobs();
			if ( ! isset( $jobs[ $job_id ] ) || ! is_array( $jobs[ $job_id ] ) ) return new WP_Error( 'mad4b_remote_work_job_missing', 'Remote work job was not found.' );
			$job = $jobs[ $job_id ];
			$lease = self::validate_active_lease( $job, $executor_id, $lease_token );
			if ( is_wp_error( $lease ) ) return $lease;
			$current_generation = (int) ( isset( $job['cancel_generation'] ) ? $job['cancel_generation'] : 0 );
			if ( $cancel_generation < 1 || $cancel_generation !== $current_generation || '' === (string) ( isset( $job['cancel_requested_at'] ) ? $job['cancel_requested_at'] : '' ) ) {
				return new WP_Error( 'mad4b_remote_work_cancel_ack_generation_mismatch', 'Cancellation acknowledgement does not match the current durable cancel generation.' );
			}
			$checkpoint = isset( $job['provider_checkpoint'] ) ? (string) $job['provider_checkpoint'] : 'not_entered';
			if ( 'not_entered' !== $checkpoint || ! empty( $job['provider_side_effect_possible'] ) ) {
				return new WP_Error( 'mad4b_remote_work_reconciliation_required', 'Provider entry is possible or recorded; cancellation cannot be acknowledged as no-effect.', array(
					'reconciliation_required' => true,
					'blind_retry_allowed' => false,
					'client_action' => 'reconcile_provider_state_before_any_retry',
				) );
			}
			$job['status'] = 'cancelled_no_effect';
			$job['reconciliation_required'] = false;
			$job['provider_cancel_required'] = false;
			$job['cancel_acknowledged_at'] = gmdate( 'c' );
			$job['cancel_acknowledged_generation'] = $cancel_generation;
			$job['client_action'] = 'no_retry_required';
			$job['lease_token_sha256'] = '';
			$job['lease_expires_at_epoch'] = 0;
			$jobs[ $job_id ] = $job;
			if ( ! self::save_jobs( $jobs ) ) return new WP_Error( 'mad4b_remote_work_cancel_ack_persist_failed', 'Remote work cancellation acknowledgement could not be durably read back.' );
			return array( 'contract' => self::CONTRACT, 'state' => 'cancelled_no_effect', 'job' => self::public_job( $job ) );
		} );
	}

	private static function reconciliation_completion_valid( array $verified_result, array $job ) {
		$effect = isset( $verified_result['provider_effect_state'] ) ? sanitize_key( (string) $verified_result['provider_effect_state'] ) : '';
		$ref = isset( $verified_result['provider_execution_ref'] ) ? trim( (string) $verified_result['provider_execution_ref'] ) : '';
		$sha = isset( $verified_result['evidence_sha256'] ) ? strtolower( trim( (string) $verified_result['evidence_sha256'] ) ) : '';
		$checkpoint = isset( $job['provider_checkpoint'] ) ? (string) $job['provider_checkpoint'] : 'not_entered';
		$provider_entry_proven = in_array( $checkpoint, array( 'provider_entered', 'provider_returned' ), true ) && ! empty( $job['provider_side_effect_possible'] );
		if ( 'applied' === $effect && ! $provider_entry_proven ) return false;
		return ! empty( $verified_result['postcondition_verified'] )
			&& in_array( $effect, array( 'applied', 'no_effect' ), true )
			&& '' !== $ref && strlen( $ref ) <= 191
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $sha );
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
			$status = isset( $job['status'] ) ? (string) $job['status'] : '';
			$reconciling = 'reconciling' === $status;
			if ( ! in_array( $status, array( 'claimed', 'reconciling' ), true ) ) return new WP_Error( 'mad4b_remote_work_job_not_claimed', 'Remote work job has no active claim.' );
			if ( $reconciling && ! self::reconciliation_completion_valid( $verified_result, $job ) ) {
				return new WP_Error( 'mad4b_remote_work_reconciliation_required', 'Cancelled or lease-expired claimed work requires explicit provider postcondition evidence before terminalization.', array(
					'reconciliation_required' => true,
					'blind_retry_allowed' => false,
					'client_action' => 'reconcile_provider_state_before_any_retry',
				) );
			}
			if ( ! $reconciling && self::now() > (int) ( isset( $job['lease_expires_at_epoch'] ) ? $job['lease_expires_at_epoch'] : 0 ) ) {
				return new WP_Error( 'mad4b_remote_work_reconciliation_required', 'Remote work lease expired after claim; provider state must be reconciled before completion or retry.', array(
					'reconciliation_required' => true,
					'blind_retry_allowed' => false,
					'client_action' => 'reconcile_provider_state_before_any_retry',
				) );
			}
			if ( ! hash_equals( (string) ( isset( $job['executor_id'] ) ? $job['executor_id'] : '' ), $executor_id ) ) return new WP_Error( 'mad4b_remote_work_executor_mismatch', 'Remote work executor does not own the active lease.' );
			if ( ! hash_equals( (string) ( isset( $job['lease_token_sha256'] ) ? $job['lease_token_sha256'] : '' ), hash( 'sha256', $lease_token ) ) ) return new WP_Error( 'mad4b_remote_work_lease_mismatch', 'Remote work lease token does not match the active claim.' );
			// An in-flight assistant result cannot be positively completed after
			// Site/Origin/Restore drift. Preserve the existing verified no-effect
			// reconciliation path so stale cancelled work can safely terminate.
			if ( 0 === strpos( (string) ( $job['operation_id'] ?? '' ), 'assistant_' )
				&& ! self::assistant_job_binding_matches( $job ) ) {
				$verified_no_effect = $reconciling
					&& 'no_effect' === ( $verified_result['provider_effect_state'] ?? null )
					&& self::reconciliation_completion_valid( $verified_result, $job );
				if ( ! $verified_no_effect ) return self::assistant_binding_error();
			}
			$resolved_no_effect = $reconciling && 'no_effect' === sanitize_key( isset( $verified_result['provider_effect_state'] ) ? (string) $verified_result['provider_effect_state'] : '' );
			$job['status'] = $resolved_no_effect ? 'cancelled_no_effect' : 'completed';
			$job['completed_at'] = gmdate( 'c' );
			$job['reconciliation_required'] = false;
			$job['blind_retry_allowed'] = false;
			$job['client_action'] = $resolved_no_effect ? 'no_retry_required' : 'consume_verified_result';
			$job['result'] = $verified_result;
			$job['lease_token_sha256'] = '';
			$job['lease_expires_at_epoch'] = 0;
			$jobs[ $job_id ] = $job;
			if ( ! self::save_jobs( $jobs ) ) return new WP_Error( 'mad4b_remote_work_completion_persist_failed', 'Remote work completion could not be durably read back.' );
			return array( 'contract' => self::CONTRACT, 'state' => (string) $job['status'], 'job' => self::public_job( $job ) );
		} );
	}
}
