<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable, provider-neutral scheduler backlog.
 *
 * This class persists admission evidence and leases only. It never dispatches a
 * provider, cron process, shell command, URL, PHP callback, SQL payload or content
 * mutation. Every worker claim is fenced by exact adapter, budget and authority
 * fingerprints plus a monotonic lease generation.
 */
final class MAD4B_SCP_Scheduler_Backlog {
	const CONTRACT = 'mad4b.scheduler-backlog.v1';
	const ADAPTER_BINDING = 'mad4b.scheduler-adapter-binding.v1';
	const OPTION = 'mad4b_scp_scheduler_backlog_v1';
	const LOCK_OPTION = 'mad4b_scp_scheduler_backlog_lock_v1';
	const LOCK_TTL = 20;
	const MIN_LEASE = 30;
	const MAX_LEASE = 900;
	const MAX_ITEMS = 500;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 40 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register( 'mad4b/scheduler-backlog-status', 'Scheduler Backlog Status', 'status', true );
		self::register( 'mad4b/scheduler-backlog-enqueue', 'Scheduler Backlog Enqueue', 'enqueue', false );
		self::register( 'mad4b/scheduler-backlog-claim-next', 'Scheduler Backlog Claim Next', 'claim_next', false );
		self::register( 'mad4b/scheduler-backlog-heartbeat', 'Scheduler Backlog Heartbeat', 'heartbeat', false );
		self::register( 'mad4b/scheduler-backlog-complete', 'Scheduler Backlog Complete', 'complete', false );
		self::register( 'mad4b/scheduler-backlog-reconcile', 'Scheduler Backlog Reconcile', 'reconcile', false );
	}

	private static function register( $name, $label, $method, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . '; durable scheduling state only, never dispatches work or widens authority.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'write' ),
					'annotations' => array( 'readonly' => (bool) $readonly, 'destructive' => ! $readonly, 'idempotent' => (bool) $readonly ),
				),
			)
		);
	}

	private static function now() { return time(); }

	private static function items() {
		$items = get_option( self::OPTION, array() );
		return is_array( $items ) ? $items : array();
	}

	private static function save( array $items ) {
		if ( count( $items ) > self::MAX_ITEMS ) return false;
		update_option( self::OPTION, $items, false );
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) && hash_equals( self::digest( $items ), self::digest( $stored ) );
	}

	private static function delete_option_if_unchanged( $name, $expected ) {
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'delete' ) ) {
			$serialized = function_exists( 'maybe_serialize' ) ? maybe_serialize( $expected ) : serialize( $expected );
			$deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => (string) $name, 'option_value' => $serialized ), array( '%s', '%s' ) );
			if ( 1 === (int) $deleted ) {
				if ( function_exists( 'wp_cache_delete' ) ) wp_cache_delete( (string) $name, 'options' );
				return true;
			}
			return false;
		}
		$current = get_option( $name, null );
		if ( self::digest( $current ) !== self::digest( $expected ) ) return false;
		return delete_option( $name );
	}

	private static function acquire_lock( $operation ) {
		$owner = function_exists( 'wp_generate_uuid4' ) ? strtolower( wp_generate_uuid4() ) : hash( 'sha256', uniqid( 'mad4b-scheduler-', true ) );
		$record = array( 'owner' => $owner, 'operation' => sanitize_key( (string) $operation ), 'expires_at_epoch' => self::now() + self::LOCK_TTL );
		if ( add_option( self::LOCK_OPTION, $record, '', false ) ) return $owner;
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && self::now() > (int) ( isset( $current['expires_at_epoch'] ) ? $current['expires_at_epoch'] : 0 ) ) {
			if ( ! self::delete_option_if_unchanged( self::LOCK_OPTION, $current ) ) return new WP_Error( 'mad4b_scheduler_lock_reclaim_raced', 'Scheduler lock changed while reclaiming an expired lock.' );
			if ( add_option( self::LOCK_OPTION, $record, '', false ) ) return $owner;
		}
		return new WP_Error( 'mad4b_scheduler_backlog_busy', 'Scheduler backlog is locked by another worker.' );
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

	private static function adapter_binding( $input ) {
		$input = is_array( $input ) ? $input : array();
		$kind = sanitize_key( isset( $input['adapter_kind'] ) ? $input['adapter_kind'] : '' );
		if ( ! in_array( $kind, array( 'wp_cron', 'host_cron', 'external_scheduler' ), true ) ) {
			return new WP_Error( 'mad4b_scheduler_adapter_kind_invalid', 'Scheduler adapter kind is invalid.' );
		}
		$out = array(
			'contract' => self::ADAPTER_BINDING,
			'adapter_kind' => $kind,
			'adapter_id' => sanitize_key( isset( $input['adapter_id'] ) ? $input['adapter_id'] : '' ),
			'desired_state_sha256' => self::sha( isset( $input['desired_state_sha256'] ) ? $input['desired_state_sha256'] : '' ),
			'expected_state_sha256' => self::sha( isset( $input['expected_state_sha256'] ) ? $input['expected_state_sha256'] : '' ),
			'budget_fingerprint' => self::sha( isset( $input['budget_fingerprint'] ) ? $input['budget_fingerprint'] : '' ),
			'authority_fingerprint' => self::sha( isset( $input['authority_fingerprint'] ) ? $input['authority_fingerprint'] : '' ),
		);
		foreach ( array( 'adapter_id', 'desired_state_sha256', 'expected_state_sha256', 'budget_fingerprint', 'authority_fingerprint' ) as $field ) {
			if ( '' === (string) $out[ $field ] ) return new WP_Error( 'mad4b_scheduler_adapter_binding_incomplete', 'Scheduler adapter binding is incomplete.', array( 'field' => $field ) );
		}
		$out['binding_sha256'] = self::digest( $out );
		return $out;
	}

	private static function binding_matches_observation( array $binding, array $observation ) {
		$observed_kind = sanitize_key( isset( $observation['adapter_kind'] ) ? $observation['adapter_kind'] : '' );
		$observed_id = sanitize_key( isset( $observation['adapter_id'] ) ? $observation['adapter_id'] : '' );
		$observed_state = self::sha( isset( $observation['observed_state_sha256'] ) ? $observation['observed_state_sha256'] : '' );
		$desired = self::sha( isset( $observation['desired_state_sha256'] ) ? $observation['desired_state_sha256'] : '' );
		$budget = self::sha( isset( $observation['budget_fingerprint'] ) ? $observation['budget_fingerprint'] : '' );
		$authority = self::sha( isset( $observation['authority_fingerprint'] ) ? $observation['authority_fingerprint'] : '' );
		return hash_equals( (string) $binding['adapter_kind'], $observed_kind )
			&& hash_equals( (string) $binding['adapter_id'], $observed_id )
			&& hash_equals( (string) $binding['expected_state_sha256'], $observed_state )
			&& hash_equals( (string) $binding['desired_state_sha256'], $desired )
			&& hash_equals( (string) $binding['budget_fingerprint'], $budget )
			&& hash_equals( (string) $binding['authority_fingerprint'], $authority );
	}

	private static function tenant_state( array $items, $tenant_id ) {
		$queued = 0; $running = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || (string) ( isset( $item['tenant_id'] ) ? $item['tenant_id'] : '' ) !== $tenant_id ) continue;
			$status = isset( $item['status'] ) ? (string) $item['status'] : '';
			if ( 'queued' === $status ) ++$queued;
			if ( 'leased' === $status && self::now() <= (int) ( isset( $item['lease_expires_at_epoch'] ) ? $item['lease_expires_at_epoch'] : 0 ) ) ++$running;
		}
		return array( $queued, $running );
	}

	public static function enqueue( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! class_exists( 'MAD4B_SCP_Scheduler_Admission' ) ) return new WP_Error( 'mad4b_scheduler_admission_unavailable', 'Scheduler admission evaluator is unavailable.' );
		$work = isset( $input['work'] ) && is_array( $input['work'] ) ? $input['work'] : array();
		$tenant = sanitize_key( isset( $work['tenant_id'] ) ? $work['tenant_id'] : '' );
		$site = sanitize_key( isset( $work['site_id'] ) ? $work['site_id'] : '' );
		$job_type = sanitize_key( isset( $work['job_type'] ) ? $work['job_type'] : '' );
		if ( '' === $tenant || '' === $site || '' === $job_type ) return new WP_Error( 'mad4b_scheduler_work_identity_required', 'Scheduler work identity is required.' );
		$binding = self::adapter_binding( isset( $input['adapter_binding'] ) ? $input['adapter_binding'] : array() );
		if ( is_wp_error( $binding ) ) return $binding;
		$quotas = isset( $input['quotas'] ) && is_array( $input['quotas'] ) ? $input['quotas'] : array();
		$central = isset( $input['central_state'] ) && is_array( $input['central_state'] ) ? $input['central_state'] : array();

		return self::with_lock( 'enqueue', static function () use ( $input, $work, $tenant, $site, $job_type, $binding, $quotas, $central ) {
			$items = self::items();
			if ( count( $items ) >= self::MAX_ITEMS ) return new WP_Error( 'mad4b_scheduler_backlog_capacity_exhausted', 'Scheduler backlog capacity is exhausted.' );
			list( $tenant_queued, $tenant_running ) = self::tenant_state( $items, $tenant );
			$queue_state = isset( $input['queue_state'] ) && is_array( $input['queue_state'] ) ? $input['queue_state'] : array();
			$queue_state['tenant_queued'] = $tenant_queued;
			$queue_state['tenant_running'] = $tenant_running;
			$admission = MAD4B_SCP_Scheduler_Admission::evaluate( array( 'work' => $work, 'queue_state' => $queue_state, 'quotas' => $quotas, 'central_state' => $central ) );
			if ( ! is_array( $admission ) || ! isset( $admission['decision_fingerprint'] ) ) return new WP_Error( 'mad4b_scheduler_admission_invalid', 'Scheduler admission evidence is invalid.' );
			if ( in_array( (string) $admission['decision'], array( 'DENY', 'FAIL_CLOSED' ), true ) ) {
				return new WP_Error( 'mad4b_scheduler_admission_denied', 'Scheduler admission denied work.', array( 'admission' => $admission ) );
			}
			$semantic = array(
				'work' => self::canonicalize( $work ),
				'adapter_binding_sha256' => (string) $binding['binding_sha256'],
				'admission_fingerprint' => (string) $admission['decision_fingerprint'],
			);
			$semantic_sha = self::digest( $semantic );
			foreach ( $items as $existing ) {
				if ( is_array( $existing )
					&& isset( $existing['semantic_sha256'] )
					&& hash_equals( $semantic_sha, (string) $existing['semantic_sha256'] )
					&& in_array( (string) $existing['status'], array( 'queued', 'leased', 'reconciliation_required' ), true ) ) {
					return array( 'contract' => self::CONTRACT, 'state' => 'already_queued', 'item' => self::public_item( $existing ), 'mutation_performed' => false );
				}
			}
			$id = strtolower( wp_generate_uuid4() );
			$item = array(
				'contract' => self::CONTRACT,
				'queue_id' => $id,
				'tenant_id' => $tenant,
				'site_id' => $site,
				'job_type' => $job_type,
				'provider_id' => sanitize_key( isset( $work['provider_id'] ) ? $work['provider_id'] : '' ),
				'priority_class' => sanitize_key( isset( $work['priority_class'] ) ? $work['priority_class'] : 'normal' ),
				'fairness_weight' => max( 1, (int) ( isset( $work['fairness_weight'] ) ? $work['fairness_weight'] : 1 ) ),
				'work_payload' => isset( $input['work_payload'] ) && is_array( $input['work_payload'] ) ? self::canonicalize( $input['work_payload'] ) : array(),
				'admission' => $admission,
				'adapter_binding' => $binding,
				'semantic_sha256' => $semantic_sha,
				'status' => 'queued',
				'enqueued_at_epoch' => self::now(),
				'enqueued_at' => gmdate( 'c' ),
				'lease_generation' => 0,
				'lease_token_sha256' => '',
				'lease_expires_at_epoch' => 0,
				'worker_id' => '',
				'reconciliation_reason' => '',
				'completion_evidence' => array(),
			);
			$items[ $id ] = $item;
			if ( ! self::save( $items ) ) return new WP_Error( 'mad4b_scheduler_backlog_persist_failed', 'Scheduler backlog item could not be durably read back.' );
			return array( 'contract' => self::CONTRACT, 'state' => 'queued', 'item' => self::public_item( $item ), 'dispatch_performed' => false, 'authorizing' => false, 'mutation_performed' => true );
		} );
	}

	public static function status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$tenant = sanitize_key( isset( $input['tenant_id'] ) ? $input['tenant_id'] : '' );
		$items = array();
		foreach ( self::items() as $item ) {
			if ( ! is_array( $item ) ) continue;
			if ( '' !== $tenant && (string) $item['tenant_id'] !== $tenant ) continue;
			$items[] = self::public_item( self::effective( $item ) );
		}
		usort( $items, static function ( $a, $b ) {
			return (int) $a['enqueued_at_epoch'] <=> (int) $b['enqueued_at_epoch'];
		} );
		return array( 'contract' => self::CONTRACT, 'count' => count( $items ), 'items' => $items, 'read_only' => true, 'dispatch_performed' => false, 'authorizing' => false, 'mutation_performed' => false );
	}

	public static function claim_next( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$worker = sanitize_key( isset( $input['worker_id'] ) ? $input['worker_id'] : '' );
		$lease_seconds = max( self::MIN_LEASE, min( self::MAX_LEASE, (int) ( isset( $input['lease_seconds'] ) ? $input['lease_seconds'] : 120 ) ) );
		$observation = isset( $input['adapter_observation'] ) && is_array( $input['adapter_observation'] ) ? $input['adapter_observation'] : array();
		if ( '' === $worker ) return new WP_Error( 'mad4b_scheduler_worker_required', 'Worker identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Scheduler_Admission' ) ) return new WP_Error( 'mad4b_scheduler_admission_unavailable', 'Scheduler admission evaluator is unavailable.' );

		return self::with_lock( 'claim_next', static function () use ( $worker, $lease_seconds, $observation ) {
			$items = self::items();
			$rank_items = array();
			foreach ( $items as $id => &$item ) {
				if ( ! is_array( $item ) ) continue;
				if ( 'leased' === (string) $item['status'] && self::now() > (int) $item['lease_expires_at_epoch'] ) {
					$item['status'] = 'reconciliation_required';
					$item['reconciliation_reason'] = 'lease_expired_after_claim';
					$item['lease_token_sha256'] = '';
				}
				if ( 'queued' !== (string) $item['status'] ) continue;
				$rank_items[] = array(
					'job_id' => (string) $id,
					'tenant_id' => (string) $item['tenant_id'],
					'site_id' => (string) $item['site_id'],
					'admitted' => true,
					'authority_current' => true,
					'quota_current' => true,
					'enqueued_at_epoch' => (int) $item['enqueued_at_epoch'],
					'fairness_weight' => (int) $item['fairness_weight'],
					'priority_class' => (string) $item['priority_class'],
				);
			}
			unset( $item );
			$rank = MAD4B_SCP_Scheduler_Admission::fair_rank( array( 'now_epoch' => self::now(), 'items' => $rank_items ) );
			$id = isset( $rank['next_job_id'] ) ? (string) $rank['next_job_id'] : '';
			if ( '' === $id || ! isset( $items[ $id ] ) ) {
				if ( ! self::save( $items ) ) return new WP_Error( 'mad4b_scheduler_backlog_reconcile_persist_failed', 'Expired leases could not be persisted.' );
				return array( 'contract' => self::CONTRACT, 'state' => 'empty', 'dispatch_performed' => false, 'mutation_performed' => false );
			}
			$item = $items[ $id ];
			if ( ! self::binding_matches_observation( (array) $item['adapter_binding'], $observation ) ) {
				$item['status'] = 'reconciliation_required';
				$item['reconciliation_reason'] = 'adapter_budget_or_authority_drift';
				$items[ $id ] = $item;
				if ( ! self::save( $items ) ) return new WP_Error( 'mad4b_scheduler_backlog_drift_persist_failed', 'Scheduler drift state could not be persisted.' );
				return new WP_Error( 'mad4b_scheduler_adapter_drift', 'Scheduler adapter/budget/authority state drifted before claim.', array( 'queue_id' => $id ) );
			}
			$token = self::token();
			$item['status'] = 'leased';
			$item['worker_id'] = $worker;
			$item['lease_generation'] = (int) $item['lease_generation'] + 1;
			$item['lease_token_sha256'] = hash( 'sha256', $token );
			$item['lease_expires_at_epoch'] = self::now() + $lease_seconds;
			$item['leased_at'] = gmdate( 'c' );
			$items[ $id ] = $item;
			if ( ! self::save( $items ) ) return new WP_Error( 'mad4b_scheduler_claim_persist_failed', 'Scheduler lease could not be durably read back.' );
			return array(
				'contract' => self::CONTRACT,
				'state' => 'leased',
				'lease_token' => $token,
				'lease_generation' => (int) $item['lease_generation'],
				'item' => self::public_item( $item ),
				'dispatch_performed' => false,
				'authorizing' => false,
				'mutation_performed' => true,
			);
		} );
	}

	public static function heartbeat( $input = array() ) {
		return self::lease_mutation( 'heartbeat', $input, static function ( array $item, array $input ) {
			$extension = max( self::MIN_LEASE, min( self::MAX_LEASE, (int) ( isset( $input['lease_seconds'] ) ? $input['lease_seconds'] : 120 ) ) );
			$item['lease_expires_at_epoch'] = self::now() + $extension;
			$item['heartbeat_at'] = gmdate( 'c' );
			return $item;
		} );
	}

	public static function complete( $input = array() ) {
		return self::lease_mutation( 'complete', $input, static function ( array $item, array $input ) {
			if ( ! self::binding_matches_observation( (array) $item['adapter_binding'], isset( $input['adapter_observation'] ) && is_array( $input['adapter_observation'] ) ? $input['adapter_observation'] : array() ) ) {
				return new WP_Error( 'mad4b_scheduler_completion_state_drift', 'Scheduler adapter/budget/authority state drifted before completion.' );
			}
			$item['status'] = 'completed';
			$item['completed_at'] = gmdate( 'c' );
			$item['completion_evidence'] = isset( $input['completion_evidence'] ) && is_array( $input['completion_evidence'] ) ? self::canonicalize( $input['completion_evidence'] ) : array();
			$item['lease_token_sha256'] = '';
			$item['lease_expires_at_epoch'] = 0;
			return $item;
		} );
	}

	private static function lease_mutation( $operation, $input, $mutator ) {
		$input = is_array( $input ) ? $input : array();
		$id = strtolower( trim( (string) ( isset( $input['queue_id'] ) ? $input['queue_id'] : '' ) ) );
		$worker = sanitize_key( isset( $input['worker_id'] ) ? $input['worker_id'] : '' );
		$token = strtolower( trim( (string) ( isset( $input['lease_token'] ) ? $input['lease_token'] : '' ) ) );
		$generation = (int) ( isset( $input['lease_generation'] ) ? $input['lease_generation'] : 0 );
		if ( '' === $id || '' === $worker || 1 !== preg_match( '/^[a-f0-9]{64}$/', $token ) || $generation < 1 ) {
			return new WP_Error( 'mad4b_scheduler_lease_identity_invalid', 'Exact scheduler lease identity is required.' );
		}
		return self::with_lock( $operation, static function () use ( $id, $worker, $token, $generation, $input, $mutator, $operation ) {
			$items = self::items();
			if ( ! isset( $items[ $id ] ) || ! is_array( $items[ $id ] ) ) return new WP_Error( 'mad4b_scheduler_item_missing', 'Scheduler backlog item was not found.' );
			$item = $items[ $id ];
			if ( 'leased' !== (string) $item['status'] ) return new WP_Error( 'mad4b_scheduler_item_not_leased', 'Scheduler item does not have an active lease.' );
			if ( self::now() > (int) $item['lease_expires_at_epoch'] ) {
				$item['status'] = 'reconciliation_required';
				$item['reconciliation_reason'] = 'late_worker_after_lease_expiry';
				$item['lease_token_sha256'] = '';
				$items[ $id ] = $item;
				self::save( $items );
				return new WP_Error( 'mad4b_scheduler_lease_expired', 'Late worker completion/heartbeat rejected; reconciliation is required.' );
			}
			if ( ! hash_equals( (string) $item['worker_id'], $worker )
				|| (int) $item['lease_generation'] !== $generation
				|| ! hash_equals( (string) $item['lease_token_sha256'], hash( 'sha256', $token ) ) ) {
				return new WP_Error( 'mad4b_scheduler_fencing_mismatch', 'Worker lease/fencing identity no longer matches.' );
			}
			$updated = call_user_func( $mutator, $item, $input );
			if ( is_wp_error( $updated ) ) {
				$item['status'] = 'reconciliation_required';
				$item['reconciliation_reason'] = $updated->get_error_code();
				$item['lease_token_sha256'] = '';
				$items[ $id ] = $item;
				self::save( $items );
				return $updated;
			}
			$items[ $id ] = $updated;
			if ( ! self::save( $items ) ) return new WP_Error( 'mad4b_scheduler_lease_persist_failed', 'Scheduler lease mutation could not be durably read back.' );
			return array( 'contract' => self::CONTRACT, 'state' => (string) $updated['status'], 'item' => self::public_item( $updated ), 'dispatch_performed' => false, 'authorizing' => false, 'mutation_performed' => true );
		} );
	}

	public static function reconcile( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id = strtolower( trim( (string) ( isset( $input['queue_id'] ) ? $input['queue_id'] : '' ) ) );
		$disposition = sanitize_key( isset( $input['disposition'] ) ? $input['disposition'] : '' );
		if ( ! in_array( $disposition, array( 'requeue_no_effect', 'complete_observed', 'cancel_no_effect' ), true ) ) return new WP_Error( 'mad4b_scheduler_reconcile_disposition_invalid', 'Reconciliation disposition is invalid.' );
		return self::with_lock( 'reconcile', static function () use ( $id, $disposition, $input ) {
			$items = self::items();
			if ( ! isset( $items[ $id ] ) || ! is_array( $items[ $id ] ) ) return new WP_Error( 'mad4b_scheduler_item_missing', 'Scheduler backlog item was not found.' );
			$item = $items[ $id ];
			if ( 'reconciliation_required' !== (string) $item['status'] ) return new WP_Error( 'mad4b_scheduler_reconcile_not_required', 'Scheduler item is not awaiting reconciliation.' );
			$evidence = isset( $input['reconciliation_evidence'] ) && is_array( $input['reconciliation_evidence'] ) ? self::canonicalize( $input['reconciliation_evidence'] ) : array();
			$evidence_sha = self::digest( $evidence );
			if ( empty( $evidence ) || '' === $evidence_sha ) return new WP_Error( 'mad4b_scheduler_reconcile_evidence_required', 'Reconciliation evidence is required.' );
			if ( 'requeue_no_effect' === $disposition ) {
				$item['status'] = 'queued';
				$item['worker_id'] = '';
				$item['lease_expires_at_epoch'] = 0;
			} elseif ( 'complete_observed' === $disposition ) {
				$item['status'] = 'completed';
				$item['completed_at'] = gmdate( 'c' );
				$item['completion_evidence'] = $evidence;
			} else {
				$item['status'] = 'cancelled_no_effect';
			}
			$item['reconciliation_reason'] = '';
			$item['reconciliation_evidence_sha256'] = $evidence_sha;
			$items[ $id ] = $item;
			if ( ! self::save( $items ) ) return new WP_Error( 'mad4b_scheduler_reconcile_persist_failed', 'Reconciled scheduler state could not be durably read back.' );
			return array( 'contract' => self::CONTRACT, 'state' => (string) $item['status'], 'item' => self::public_item( $item ), 'dispatch_performed' => false, 'authorizing' => false, 'mutation_performed' => true );
		} );
	}

	private static function effective( array $item ) {
		if ( 'leased' === (string) $item['status'] && self::now() > (int) $item['lease_expires_at_epoch'] ) {
			$item['effective_status'] = 'reconciliation_required';
			$item['lease_expired'] = true;
		} else {
			$item['effective_status'] = (string) $item['status'];
		}
		return $item;
	}

	private static function public_item( array $item ) {
		unset( $item['lease_token_sha256'] );
		return $item;
	}

	private static function token() {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( Throwable $e ) {
			return hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) . '|' . wp_rand() );
		}
	}

	private static function sha( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function digest( $value ) {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : hash( 'sha256', $json );
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}

MAD4B_SCP_Scheduler_Backlog::boot();
