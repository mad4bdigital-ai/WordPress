<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only operator diagnostics for Feature 007 durable work.
 *
 * Doctor emits evidence and non-executable RepairPlans only. It never repairs,
 * retries, requeues, cancels, or mutates runtime state.
 */
final class MAD4B_SCP_Operator_Doctor {
	const CONTRACT = 'mad4b.operator-doctor.v1';
	const REPAIR_CONTRACT = 'mad4b.repair-plan.v1';
	const DLQ_CONTRACT = 'mad4b.dead-letter-status.v1';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register_read(
			'mad4b/operator-doctor',
			'Operator Doctor',
			array( __CLASS__, 'doctor' ),
			array(
				'type' => 'object',
				'properties' => array(
					'stale_seconds' => array( 'type' => 'integer', 'minimum' => 60, 'maximum' => 86400, 'default' => 900 ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25 ),
				),
				'additionalProperties' => false,
			)
		);
		self::register_read(
			'mad4b/operator-dead-letter-status',
			'Operator Dead Letter Status',
			array( __CLASS__, 'dead_letter_status' ),
			array(
				'type' => 'object',
				'properties' => array(
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25 ),
				),
				'additionalProperties' => false,
			)
		);
	}

	private static function register_read( $name, $label, $callback, array $schema ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' from read-only durable execution evidence. Suggested RepairPlans never execute implicitly.',
				'category' => 'mad4b-read',
				'execute_callback' => $callback,
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => $schema,
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);
	}

	private static function site_uuid() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		return 1 === preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ? $uuid : '';
	}

	public static function doctor( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$stale = isset( $input['stale_seconds'] ) ? max( 60, min( 86400, absint( $input['stale_seconds'] ) ) ) : 900;
		$limit = isset( $input['limit'] ) ? max( 1, min( 100, absint( $input['limit'] ) ) ) : 25;
		$snapshot = self::snapshot( $stale, $limit );
		if ( is_wp_error( $snapshot ) ) return $snapshot;
		return self::classify_snapshot( $snapshot );
	}

	public static function dead_letter_status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$limit = isset( $input['limit'] ) ? max( 1, min( 100, absint( $input['limit'] ) ) ) : 25;
		$snapshot = self::snapshot( 900, $limit );
		if ( is_wp_error( $snapshot ) ) return $snapshot;
		return array(
			'contract' => self::DLQ_CONTRACT,
			'site_uuid' => $snapshot['site_uuid'],
			'dead_lettered_count' => (int) $snapshot['dead_lettered_outbox_count'] + (int) ( $snapshot['host_runner_dead_lettered_count'] ?? 0 ),
			'items' => array(
				'provider_outbox' => $snapshot['dead_lettered_outbox'],
				'host_runner' => $snapshot['host_runner_dead_lettered'] ?? array(),
			),
			'replay_available' => false,
			'replay_reason' => 'governed_replay_plan_not_implemented',
			'original_items_preserved' => true,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}

	public static function classify_snapshot( array $snapshot ) {
		$findings = array();

		if ( ! empty( $snapshot['expired_active_lease_count'] ) ) {
			$findings[] = self::finding(
				'expired-active-leases',
				'high',
				'expired_active_lease',
				array( 'count' => (int) $snapshot['expired_active_lease_count'] ),
				array( 'durable.lease.reconcile', 'durable.lease.reclaim' )
			);
		}
		if ( ! empty( $snapshot['stuck_job_count'] ) ) {
			$findings[] = self::finding(
				'stuck-content-jobs',
				'medium',
				'content_job_stale_progress',
				array( 'count' => (int) $snapshot['stuck_job_count'] ),
				array( 'content_job.inspect', 'content_job.repair-plan' )
			);
		}
		if ( ! empty( $snapshot['overdue_outbox_count'] ) ) {
			$findings[] = self::finding(
				'overdue-outbox',
				'high',
				'provider_delivery_overdue',
				array( 'count' => (int) $snapshot['overdue_outbox_count'] ),
				array( 'execution.outbox.reconcile', 'provider.execution.readback' )
			);
		}
		if ( ! empty( $snapshot['stale_inbox_count'] ) ) {
			$findings[] = self::finding(
				'stale-inbox',
				'medium',
				'provider_event_processing_stale',
				array( 'count' => (int) $snapshot['stale_inbox_count'] ),
				array( 'execution.inbox.inspect', 'execution.inbox.reconcile' )
			);
		}
		if ( ! empty( $snapshot['dead_lettered_outbox_count'] ) ) {
			$findings[] = self::finding(
				'dead-letter-growth',
				'high',
				'dead_lettered_work_present',
				array( 'count' => (int) $snapshot['dead_lettered_outbox_count'] ),
				array( 'dead_letter.inspect', 'dead_letter.replay-plan' )
			);
		}

		if ( ! empty( $snapshot['orphan_job_count'] ) ) {
			$findings[] = self::finding(
				'orphan-content-jobs',
				'high',
				'content_job_without_event_history',
				array( 'count' => (int) $snapshot['orphan_job_count'] ),
				array( 'content_job.inspect', 'content_job.repair-plan' )
			);
		}
		if ( ! empty( $snapshot['host_runner_uncertain_count'] ) ) {
			$findings[] = self::finding(
				'host-runner-uncertain-mutations',
				'critical',
				'mutated_but_evidence_uncertain',
				array( 'count' => (int) $snapshot['host_runner_uncertain_count'] ),
				array( 'host_runner.reconcile', 'recovery.inspect' )
			);
		}
		if ( ! empty( $snapshot['host_runner_recovery_required_count'] ) ) {
			$findings[] = self::finding(
				'host-runner-recovery-required',
				'high',
				'host_runner_recovery_required',
				array( 'count' => (int) $snapshot['host_runner_recovery_required_count'] ),
				array( 'host_runner.reconcile', 'recovery.plan' )
			);
		}
		if ( ! empty( $snapshot['host_runner_dead_lettered_count'] ) ) {
			$findings[] = self::finding(
				'host-runner-dead-letter',
				'high',
				'host_runner_dead_lettered_work_present',
				array( 'count' => (int) $snapshot['host_runner_dead_lettered_count'] ),
				array( 'dead_letter.inspect', 'host_runner.reconcile' )
			);
		}

		$severity_order = array( 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4 );
		usort( $findings, static function ( $a, $b ) use ( $severity_order ) {
			$left = isset( $severity_order[ $a['severity'] ] ) ? $severity_order[ $a['severity'] ] : 99;
			$right = isset( $severity_order[ $b['severity'] ] ) ? $severity_order[ $b['severity'] ] : 99;
			return $left === $right ? strcmp( $a['finding_id'], $b['finding_id'] ) : $left - $right;
		} );

		return array(
			'contract' => self::CONTRACT,
			'site_uuid' => isset( $snapshot['site_uuid'] ) ? (string) $snapshot['site_uuid'] : '',
			'observed_at' => isset( $snapshot['observed_at'] ) ? (string) $snapshot['observed_at'] : '',
			'healthy' => empty( $findings ),
			'finding_count' => count( $findings ),
			'findings' => $findings,
			'snapshot' => $snapshot,
			'doctor_executes_repairs' => false,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}

	private static function finding( $id, $severity, $reason, array $evidence, array $operations ) {
		sort( $operations, SORT_STRING );
		return array(
			'finding_id' => sanitize_key( (string) $id ),
			'severity' => sanitize_key( (string) $severity ),
			'reason_code' => sanitize_key( (string) $reason ),
			'evidence' => $evidence,
			'repair_plan' => array(
				'contract' => self::REPAIR_CONTRACT,
				'executable' => false,
				'preview_only' => true,
				'semantic_operations' => $operations,
				'requires_fresh_readback' => true,
				'requires_current_policy' => true,
				'requires_authority_if_applied' => true,
				'arbitrary_shell' => false,
			),
		);
	}

	private static function host_runner_incidents( $limit ) {
		$content_root = defined( 'WP_CONTENT_DIR' )
			? rtrim( (string) WP_CONTENT_DIR, '/\\' )
			: rtrim( (string) ABSPATH, '/\\' ) . DIRECTORY_SEPARATOR . 'wp-content';
		$runner_root = $content_root . DIRECTORY_SEPARATOR . 'mad4b-runner';
		if ( ! is_dir( $runner_root ) || is_link( $runner_root ) ) {
			return array( 'available' => false, 'journal_root' => $runner_root . DIRECTORY_SEPARATOR . 'journals', 'counts' => array(), 'incidents' => array() );
		}
		$resolved_runner = realpath( $runner_root );
		if ( false === $resolved_runner ) return array( 'available' => false, 'journal_root' => '', 'counts' => array(), 'incidents' => array() );
		$journal_root = $resolved_runner . DIRECTORY_SEPARATOR . 'journals';
		if ( ! is_dir( $journal_root ) || is_link( $journal_root ) ) return array( 'available' => false, 'journal_root' => $journal_root, 'counts' => array(), 'incidents' => array() );
		$resolved_journal = realpath( $journal_root );
		$normalized_runner = rtrim( str_replace( '\\', '/', $resolved_runner ), '/' );
		$normalized_journal = false === $resolved_journal ? '' : str_replace( '\\', '/', $resolved_journal );
		if ( '' === $normalized_journal || 0 !== strpos( $normalized_journal, $normalized_runner . '/' ) ) {
			return array( 'available' => false, 'journal_root' => $journal_root, 'counts' => array(), 'incidents' => array() );
		}

		$paths = glob( $resolved_journal . DIRECTORY_SEPARATOR . '*.json' );
		$paths = is_array( $paths ) ? $paths : array();
		usort( $paths, static function ( $a, $b ) { return (int) filemtime( $b ) - (int) filemtime( $a ); } );
		$paths = array_slice( $paths, 0, max( 1, min( 100, absint( $limit ) ) ) );
		$counts = array();
		$incidents = array();
		$interesting = array( 'MUTATED_BUT_EVIDENCE_UNCERTAIN', 'RECOVERY_REQUIRED', 'DEAD_LETTERED', 'ROLLED_BACK_AFTER_FAILURE' );
		foreach ( $paths as $path ) {
			if ( is_link( $path ) || ! is_file( $path ) ) continue;
			$size = filesize( $path );
			if ( false === $size || $size < 2 || $size > 262144 ) continue;
			$raw = file_get_contents( $path );
			$row = false === $raw ? null : json_decode( $raw, true );
			if ( ! is_array( $row ) || 'mad4b.host-runner-mutation-journal.v1' !== (string) ( $row['contract'] ?? '' ) ) continue;
			$state = strtoupper( str_replace( '-', '_', sanitize_key( (string) ( $row['state'] ?? '' ) ) ) );
			if ( '' === $state ) continue;
			$counts[ $state ] = isset( $counts[ $state ] ) ? (int) $counts[ $state ] + 1 : 1;
			if ( ! in_array( $state, $interesting, true ) ) continue;
			$incidents[] = array(
				'job_id' => isset( $row['job_id'] ) ? substr( sanitize_text_field( (string) $row['job_id'] ), 0, 64 ) : '',
				'operation_id' => isset( $row['operation_id'] ) ? substr( sanitize_text_field( (string) $row['operation_id'] ), 0, 160 ) : '',
				'state' => $state,
				'plan_sha256' => isset( $row['plan_sha256'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $row['plan_sha256'] ) ? (string) $row['plan_sha256'] : '',
				'terminal' => ! empty( $row['terminal'] ),
				'blind_retry_allowed' => ! empty( $row['blind_retry_allowed'] ),
				'completed_at' => isset( $row['completed_at'] ) ? substr( sanitize_text_field( (string) $row['completed_at'] ), 0, 64 ) : '',
			);
		}
		ksort( $counts, SORT_STRING );
		return array( 'available' => true, 'journal_root' => $resolved_journal, 'counts' => $counts, 'incidents' => $incidents );
	}

	private static function snapshot( $stale_seconds, $limit ) {
		global $wpdb;
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) {
			return new WP_Error( 'mad4b_operator_doctor_schema_unavailable', 'Durable execution schema is not ready.' );
		}
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_operator_doctor_site_identity_unavailable', 'Site identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 60, min( 86400, absint( $stale_seconds ) ) ) );
		$limit = max( 1, min( 100, absint( $limit ) ) );

		$job_states = $wpdb->get_results(
			$wpdb->prepare( "SELECT state,COUNT(*) AS n FROM {$t['content_jobs']} WHERE site_uuid=%s GROUP BY state", $site_uuid ),
			ARRAY_A
		);
		$stuck_jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job_id,state,stage,job_revision,updated_at FROM {$t['content_jobs']} WHERE site_uuid=%s AND state IN ('QUEUED','RUNNING','WAITING_REVIEW','BLOCKED') AND updated_at<%s ORDER BY updated_at ASC LIMIT %d",
				$site_uuid,
				$cutoff,
				$limit
			),
			ARRAY_A
		);
		$orphan_jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT j.job_id,j.state,j.stage,j.job_revision,j.created_at FROM {$t['content_jobs']} j LEFT JOIN {$t['content_job_events']} e ON e.job_id=j.job_id WHERE j.site_uuid=%s GROUP BY j.id,j.job_id,j.state,j.stage,j.job_revision,j.created_at HAVING COUNT(e.id)=0 ORDER BY j.created_at ASC LIMIT %d",
				$site_uuid,
				$limit
			),
			ARRAY_A
		);
		$expired_leases = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.work_id,l.aggregate_id,l.worker_id,l.lease_epoch,l.expected_aggregate_revision,l.expires_at FROM {$t['work_leases']} l INNER JOIN {$t['content_jobs']} j ON l.aggregate_type='content_job' AND l.aggregate_id=j.job_id WHERE j.site_uuid=%s AND l.status='active' AND l.expires_at<=%s ORDER BY l.expires_at ASC LIMIT %d",
				$site_uuid,
				$now,
				$limit
			),
			ARRAY_A
		);
		$overdue_outbox = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.outbox_id,o.job_id,o.provider_id,o.capability_id,o.attempts,o.status,o.available_at,o.last_error_class FROM {$t['outbox']} o INNER JOIN {$t['content_jobs']} j ON o.job_id=j.job_id WHERE j.site_uuid=%s AND o.status IN ('pending','retrying') AND o.available_at<=%s ORDER BY o.available_at ASC LIMIT %d",
				$site_uuid,
				$cutoff,
				$limit
			),
			ARRAY_A
		);
		$stale_inbox = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.provider_id,i.provider_event_id,i.job_id,i.status,i.provider_execution_ref,i.received_at FROM {$t['inbox']} i INNER JOIN {$t['content_jobs']} j ON i.job_id=j.job_id WHERE j.site_uuid=%s AND i.processed_at IS NULL AND i.received_at<=%s ORDER BY i.received_at ASC LIMIT %d",
				$site_uuid,
				$cutoff,
				$limit
			),
			ARRAY_A
		);
		$dead = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.outbox_id,o.job_id,o.provider_id,o.capability_id,o.attempts,o.last_error_class,o.updated_at FROM {$t['outbox']} o INNER JOIN {$t['content_jobs']} j ON o.job_id=j.job_id WHERE j.site_uuid=%s AND o.status='dead_lettered' ORDER BY o.updated_at DESC LIMIT %d",
				$site_uuid,
				$limit
			),
			ARRAY_A
		);

		$host_runner = self::host_runner_incidents( $limit );

		$state_counts = array();
		foreach ( is_array( $job_states ) ? $job_states : array() as $row ) {
			$state = isset( $row['state'] ) ? sanitize_key( (string) $row['state'] ) : '';
			if ( '' !== $state ) $state_counts[ $state ] = isset( $row['n'] ) ? (int) $row['n'] : 0;
		}
		ksort( $state_counts, SORT_STRING );

		return array(
			'contract' => 'mad4b.operator-doctor-snapshot.v1',
			'site_uuid' => $site_uuid,
			'observed_at' => gmdate( 'c' ),
			'stale_seconds' => (int) $stale_seconds,
			'job_state_counts' => $state_counts,
			'stuck_job_count' => is_array( $stuck_jobs ) ? count( $stuck_jobs ) : 0,
			'stuck_jobs' => is_array( $stuck_jobs ) ? $stuck_jobs : array(),
			'orphan_job_count' => is_array( $orphan_jobs ) ? count( $orphan_jobs ) : 0,
			'orphan_jobs' => is_array( $orphan_jobs ) ? $orphan_jobs : array(),
			'expired_active_lease_count' => is_array( $expired_leases ) ? count( $expired_leases ) : 0,
			'expired_active_leases' => is_array( $expired_leases ) ? $expired_leases : array(),
			'overdue_outbox_count' => is_array( $overdue_outbox ) ? count( $overdue_outbox ) : 0,
			'overdue_outbox' => is_array( $overdue_outbox ) ? $overdue_outbox : array(),
			'stale_inbox_count' => is_array( $stale_inbox ) ? count( $stale_inbox ) : 0,
			'stale_inbox' => is_array( $stale_inbox ) ? $stale_inbox : array(),
			'dead_lettered_outbox_count' => is_array( $dead ) ? count( $dead ) : 0,
			'dead_lettered_outbox' => is_array( $dead ) ? $dead : array(),
			'host_runner_evidence_available' => ! empty( $host_runner['available'] ),
			'host_runner_state_counts' => isset( $host_runner['counts'] ) ? $host_runner['counts'] : array(),
			'host_runner_uncertain_count' => isset( $host_runner['counts']['MUTATED_BUT_EVIDENCE_UNCERTAIN'] ) ? (int) $host_runner['counts']['MUTATED_BUT_EVIDENCE_UNCERTAIN'] : 0,
			'host_runner_recovery_required_count' => isset( $host_runner['counts']['RECOVERY_REQUIRED'] ) ? (int) $host_runner['counts']['RECOVERY_REQUIRED'] : 0,
			'host_runner_dead_lettered_count' => isset( $host_runner['counts']['DEAD_LETTERED'] ) ? (int) $host_runner['counts']['DEAD_LETTERED'] : 0,
			'host_runner_incidents' => isset( $host_runner['incidents'] ) ? $host_runner['incidents'] : array(),
			'host_runner_dead_lettered' => isset( $host_runner['incidents'] ) ? array_values( array_filter( $host_runner['incidents'], static function ( $row ) { return 'DEAD_LETTERED' === (string) $row['state']; } ) ) : array(),
			'query_scope' => 'site_uuid_via_content_job_join_and_fixed_host_runner_evidence_root',
			'cross_site_rows_exposed' => false,
			'mutation_performed' => false,
		);
	}
}

MAD4B_SCP_Operator_Doctor::boot();
