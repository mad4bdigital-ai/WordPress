<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed decommission state machine.
 *
 * Quiesce is enforced through the Feature 007 commit-time kill switch so late
 * callbacks and already-approved writes cannot enter mutation callbacks after
 * retirement begins. The class never deletes public content, revokes external
 * credentials, or removes plugins by itself; those actions require certified
 * domain adapters and remain explicit blockers when unavailable.
 */
final class MAD4B_SCP_Decommission_Governance {
	const CONTRACT = 'mad4b.decommission-governance.v1';
	const PLAN_CONTRACT = 'mad4b.decommission-quiesce-plan.v1';
	const OPTION = 'mad4b_scp_decommission_governance_v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 40 );
		add_filter( 'mad4b_scp_execution_kill_switch_state', array( __CLASS__, 'kill_switch_state' ), 50, 5 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register( 'mad4b/decommission-live-inventory', 'Decommission Live Inventory', 'live_inventory', true );
		self::register( 'mad4b/decommission-governance-status', 'Decommission Governance Status', 'status', true );
		self::register( 'mad4b/decommission-quiesce-plan', 'Plan Governed Decommission Quiesce', 'quiesce_plan', true );
		self::register( 'mad4b/decommission-quiesce-apply', 'Apply Governed Decommission Quiesce', 'quiesce_apply', false );
		self::register( 'mad4b/decommission-resume-plan', 'Plan Governed Decommission Resume', 'resume_plan', true );
		self::register( 'mad4b/decommission-resume-apply', 'Apply Governed Decommission Resume', 'resume_apply', false );
		self::register( 'mad4b/decommission-finalize-plan', 'Plan Governed Decommission Finalization', 'finalize_plan', true );
		self::register( 'mad4b/decommission-finalize-apply', 'Apply Governed Decommission Finalization', 'finalize_apply', false );
	}

	private static function register( $name, $label, $method, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . '; fail-closed decommission state only, never creates Production or Breakglass authority.',
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

	private static function default_state() {
		return array(
			'contract' => self::CONTRACT,
			'state' => 'ACTIVE',
			'generation' => 0,
			'scope' => '',
			'plan_sha256' => '',
			'inventory_fingerprint' => '',
			'changed_at' => '',
			'changed_by' => '',
			'retirement_receipt_sha256' => '',
		);
	}

	private static function state() {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) || self::CONTRACT !== (string) ( isset( $state['contract'] ) ? $state['contract'] : '' ) ) return self::default_state();
		return array_merge( self::default_state(), $state );
	}

	private static function safe_while_quiesced() {
		return array(
			'mad4b/decommission-quiesce-apply',
			'mad4b/decommission-resume-apply',
			'mad4b/decommission-finalize-apply',
			'mad4b/content-job-cancel',
			'mad4b/scheduler-backlog-heartbeat',
			'mad4b/scheduler-backlog-complete',
			'mad4b/scheduler-backlog-reconcile',
			'mad4b/mutation-undo',
		);
	}

	public static function kill_switch_state( $current, $ability, $provider, $claim, $input ) {
		if ( ! is_array( $current ) ) return $current;
		if ( ! empty( $current['active'] ) ) return $current;
		$state = self::state();
		if ( ! in_array( (string) $state['state'], array( 'QUIESCED', 'RETIRED' ), true ) ) return $current;
		if ( in_array( (string) $ability, self::safe_while_quiesced(), true ) ) return $current;
		return array(
			'active' => true,
			'revision' => 'decommission:' . strtolower( (string) $state['state'] ) . ':g' . (int) $state['generation'] . ':' . substr( (string) $state['inventory_fingerprint'], 0, 24 ),
			'source' => 'decommission_governance',
		);
	}

	public static function status( $input = array() ) {
		$state = self::state();
		return array(
			'contract' => self::CONTRACT,
			'state' => (string) $state['state'],
			'generation' => (int) $state['generation'],
			'scope' => (string) $state['scope'],
			'inventory_fingerprint' => (string) $state['inventory_fingerprint'],
			'changed_at' => (string) $state['changed_at'],
			'retirement_receipt_sha256' => (string) $state['retirement_receipt_sha256'],
			'ordinary_mutations_quiesced' => in_array( (string) $state['state'], array( 'QUIESCED', 'RETIRED' ), true ),
			'safe_drain_abilities' => self::safe_while_quiesced(),
			'production_authorized' => false,
			'breakglass_enabled' => false,
			'mutation_performed' => false,
		);
	}

	public static function live_inventory( $input = array() ) {
		$dimensions = array(
			'active_jobs' => self::unknown( 'content_jobs_unavailable' ),
			'unresolved_writes' => self::unknown( 'write_reconciliation_inventory_unavailable' ),
			'active_credentials' => self::unknown( 'credential_inventory_unavailable' ),
			'active_webhooks' => self::unknown( 'webhook_inventory_unavailable' ),
			'scheduled_work' => self::unknown( 'scheduler_inventory_unavailable' ),
			'runner_leases' => self::unknown( 'runner_lease_inventory_unavailable' ),
			'pending_dlq' => self::unknown( 'dead_letter_inventory_unavailable' ),
		);

		if ( class_exists( 'MAD4B_SCP_Content_Jobs' ) ) {
			$jobs = MAD4B_SCP_Content_Jobs::list_jobs( array( 'limit' => 200 ) );
			if ( is_array( $jobs ) && isset( $jobs['items'] ) && is_array( $jobs['items'] ) ) {
				$count = 0;
				foreach ( $jobs['items'] as $job ) {
					$state = isset( $job['state'] ) ? strtoupper( (string) $job['state'] ) : '';
					if ( ! in_array( $state, array( 'COMPLETED', 'CANCELLED' ), true ) ) ++$count;
				}
				$dimensions['active_jobs'] = self::known( $count, 'content_job_registry', count( $jobs['items'] ) < 200 );
			}
		}

		$scheduled = 0;
		$unresolved = 0;
		if ( class_exists( 'MAD4B_SCP_Scheduler_Backlog' ) ) {
			$backlog = MAD4B_SCP_Scheduler_Backlog::status( array() );
			if ( is_array( $backlog ) && isset( $backlog['items'] ) && is_array( $backlog['items'] ) ) {
				foreach ( $backlog['items'] as $row ) {
					$status = isset( $row['effective_status'] ) ? (string) $row['effective_status'] : (string) ( isset( $row['status'] ) ? $row['status'] : '' );
					if ( in_array( $status, array( 'queued', 'leased' ), true ) ) ++$scheduled;
					if ( 'reconciliation_required' === $status ) ++$unresolved;
				}
				$dimensions['scheduled_work'] = self::known( $scheduled, 'scheduler_backlog', true );
			}
		}
		$runner_leases = 0;
		if ( class_exists( 'MAD4B_SCP_Remote_Work_Queue' ) ) {
			$remote = MAD4B_SCP_Remote_Work_Queue::list_jobs();
			if ( is_array( $remote ) && isset( $remote['items'] ) && is_array( $remote['items'] ) ) {
				foreach ( $remote['items'] as $row ) {
					$status = isset( $row['effective_status'] ) ? (string) $row['effective_status'] : (string) ( isset( $row['status'] ) ? $row['status'] : '' );
					if ( 'claimed' === $status ) ++$runner_leases;
					if ( 'reconciling' === $status ) ++$unresolved;
				}
				$dimensions['runner_leases'] = self::known( $runner_leases, 'remote_work_queue', true );
			}
		}
		if ( ! empty( $dimensions['scheduled_work']['known'] ) || ! empty( $dimensions['runner_leases']['known'] ) ) {
			$dimensions['unresolved_writes'] = self::known( $unresolved, 'scheduler_and_remote_reconciliation', true );
		}

		if ( class_exists( 'MAD4B_SCP_Local_OAuth_Store' ) && MAD4B_SCP_Local_OAuth_Store::is_ready() ) {
			global $wpdb;
			$tables = MAD4B_SCP_Local_OAuth_Store::tables();
			if ( isset( $tables['refresh_tokens'] ) && isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
				$table = $tables['refresh_tokens'];
				$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( null !== $count ) $dimensions['active_credentials'] = self::known( max( 0, (int) $count ), 'local_oauth_refresh_token_store', true );
			}
		}

		$filtered = apply_filters( 'mad4b_scp_decommission_live_inventory_dimensions', $dimensions );
		if ( is_array( $filtered ) ) $dimensions = self::validated_dimensions( $dimensions, $filtered );

		$blockers = array();
		$inventory = array();
		$complete = true;
		foreach ( $dimensions as $name => $row ) {
			if ( empty( $row['known'] ) ) {
				$complete = false;
				$blockers[] = 'inventory_dimension_unknown:' . sanitize_key( (string) $name );
				$inventory[ $name ] = null;
			} else {
				$inventory[ $name ] = max( 0, (int) $row['count'] );
				if ( empty( $row['complete'] ) ) {
					$complete = false;
					$blockers[] = 'inventory_dimension_truncated:' . sanitize_key( (string) $name );
				}
			}
		}

		$evidence_ready = class_exists( 'MAD4B_SCP_Artifacts' ) && class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::is_ready();
		$inventory['evidence_retained_or_exportable'] = (bool) $evidence_ready;
		$inventory['external_refs_missing'] = ! $complete;
		$material = array(
			'inventory' => $inventory,
			'dimensions' => $dimensions,
			'inventory_complete' => $complete,
			'evidence_retained_or_exportable' => (bool) $evidence_ready,
		);
		return array(
			'contract' => 'mad4b.decommission-live-inventory.v1',
			'inventory' => $inventory,
			'dimensions' => $dimensions,
			'inventory_complete' => $complete,
			'blockers' => array_values( array_unique( $blockers ) ),
			'inventory_fingerprint' => self::digest( $material ),
			'caller_inventory_accepted' => false,
			'production_authorized' => false,
			'mutation_performed' => false,
		);
	}

	public static function quiesce_plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$scope = sanitize_key( isset( $input['scope'] ) ? $input['scope'] : '' );
		$allowed = array( 'provider_adapter', 'workflow_provider', 'ai_provider', 'site', 'tenant', 'module', 'host_connector', 'authority', 'installation' );
		if ( ! in_array( $scope, $allowed, true ) ) return new WP_Error( 'mad4b_decommission_scope_invalid', 'Decommission scope is invalid.' );
		$inventory = self::live_inventory();
		if ( is_wp_error( $inventory ) ) return $inventory;
		$current = self::state();
		$plan = array(
			'contract' => self::PLAN_CONTRACT,
			'action' => 'quiesce',
			'scope' => $scope,
			'expected_generation' => (int) $current['generation'],
			'expected_state' => (string) $current['state'],
			'inventory_fingerprint' => (string) $inventory['inventory_fingerprint'],
			'inventory_complete' => ! empty( $inventory['inventory_complete'] ),
			'inventory_blockers' => isset( $inventory['blockers'] ) ? $inventory['blockers'] : array(),
			'ordinary_mutations_after_apply' => 'denied_at_commit_guard',
			'safe_drain_abilities' => self::safe_while_quiesced(),
			'provider_revocation_performed' => false,
			'content_deletion_performed' => false,
			'production_authorized' => false,
			'mutation_performed' => false,
		);
		$plan['plan_sha256'] = self::digest_without( $plan, array( 'plan_sha256', 'mutation_performed' ) );
		return $plan;
	}

	public static function quiesce_apply( $input = array() ) {
		$plan = isset( $input['plan'] ) && is_array( $input['plan'] ) ? $input['plan'] : array();
		$valid = self::validate_plan( $plan, self::PLAN_CONTRACT, 'quiesce' );
		if ( is_wp_error( $valid ) ) return $valid;
		$current = self::state();
		if ( (int) $plan['expected_generation'] !== (int) $current['generation'] || ! hash_equals( (string) $plan['expected_state'], (string) $current['state'] ) ) {
			return new WP_Error( 'mad4b_decommission_state_drift', 'Decommission state changed after planning.' );
		}
		$inventory = self::live_inventory();
		if ( is_wp_error( $inventory ) ) return $inventory;
		if ( ! hash_equals( (string) $plan['inventory_fingerprint'], (string) $inventory['inventory_fingerprint'] ) ) {
			return new WP_Error( 'mad4b_decommission_inventory_drift', 'Live inventory changed after quiesce planning.' );
		}
		$state = array(
			'contract' => self::CONTRACT,
			'state' => 'QUIESCED',
			'generation' => (int) $current['generation'] + 1,
			'scope' => (string) $plan['scope'],
			'plan_sha256' => (string) $plan['plan_sha256'],
			'inventory_fingerprint' => (string) $inventory['inventory_fingerprint'],
			'changed_at' => gmdate( 'c' ),
			'changed_by' => 'governed_decommission',
			'retirement_receipt_sha256' => '',
		);
		update_option( self::OPTION, $state, false );
		$stored = self::state();
		if ( 'QUIESCED' !== (string) $stored['state'] || (int) $stored['generation'] !== (int) $state['generation'] ) return new WP_Error( 'mad4b_decommission_state_persist_failed', 'Quiesce state could not be durably read back.' );
		return array( 'contract' => self::CONTRACT, 'state' => $stored, 'ordinary_mutations_quiesced' => true, 'production_authorized' => false, 'mutation_performed' => true );
	}

	public static function resume_plan( $input = array() ) {
		$current = self::state();
		if ( 'ACTIVE' === (string) $current['state'] ) return new WP_Error( 'mad4b_decommission_not_quiesced', 'Decommission governance is already active/not quiesced.' );
		$plan = array(
			'contract' => 'mad4b.decommission-resume-plan.v1',
			'action' => 'resume',
			'expected_generation' => (int) $current['generation'],
			'expected_state' => (string) $current['state'],
			'state_fingerprint' => self::digest( $current ),
			'requires_fresh_governed_approval' => true,
			'production_authorized' => false,
			'mutation_performed' => false,
		);
		$plan['plan_sha256'] = self::digest_without( $plan, array( 'plan_sha256', 'mutation_performed' ) );
		return $plan;
	}

	public static function resume_apply( $input = array() ) {
		$plan = isset( $input['plan'] ) && is_array( $input['plan'] ) ? $input['plan'] : array();
		$valid = self::validate_plan( $plan, 'mad4b.decommission-resume-plan.v1', 'resume' );
		if ( is_wp_error( $valid ) ) return $valid;
		$current = self::state();
		if ( (int) $plan['expected_generation'] !== (int) $current['generation']
			|| ! hash_equals( (string) $plan['expected_state'], (string) $current['state'] )
			|| ! hash_equals( (string) $plan['state_fingerprint'], self::digest( $current ) ) ) {
			return new WP_Error( 'mad4b_decommission_resume_state_drift', 'Decommission state changed after resume planning.' );
		}
		$next = self::default_state();
		$next['generation'] = (int) $current['generation'] + 1;
		$next['changed_at'] = gmdate( 'c' );
		$next['changed_by'] = 'governed_decommission_resume';
		update_option( self::OPTION, $next, false );
		return array( 'contract' => self::CONTRACT, 'state' => self::state(), 'ordinary_mutations_quiesced' => false, 'production_authorized' => false, 'mutation_performed' => true );
	}

	public static function finalize_plan( $input = array() ) {
		$current = self::state();
		if ( 'QUIESCED' !== (string) $current['state'] ) return new WP_Error( 'mad4b_decommission_finalize_requires_quiesce', 'Finalization requires an exact QUIESCED state.' );
		$inventory = self::live_inventory();
		if ( is_wp_error( $inventory ) ) return $inventory;
		$preflight = class_exists( 'MAD4B_SCP_Decommission_Portability' )
			? MAD4B_SCP_Decommission_Portability::preflight( array( 'scope' => (string) $current['scope'], 'inventory' => (array) $inventory['inventory'], 'policy' => array() ) )
			: array( 'decision' => 'BLOCKED', 'blockers' => array( 'decommission_preflight_unavailable' ) );
		$blockers = isset( $inventory['blockers'] ) ? (array) $inventory['blockers'] : array();
		if ( ! isset( $preflight['decision'] ) || 'READY_FOR_GOVERNED_QUIESCE' !== (string) $preflight['decision'] ) {
			$blockers = array_merge( $blockers, isset( $preflight['blockers'] ) ? (array) $preflight['blockers'] : array( 'preflight_not_ready' ) );
		}
		if ( empty( $input['external_revocation_receipt_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $input['external_revocation_receipt_sha256'] ) ) ) {
			$blockers[] = 'external_revocation_receipt_required';
		}
		if ( empty( $input['portable_export_bundle_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $input['portable_export_bundle_sha256'] ) ) ) {
			$blockers[] = 'portable_export_bundle_receipt_required';
		}
		$plan = array(
			'contract' => 'mad4b.decommission-finalize-plan.v1',
			'action' => 'finalize',
			'expected_generation' => (int) $current['generation'],
			'state_fingerprint' => self::digest( $current ),
			'inventory_fingerprint' => (string) $inventory['inventory_fingerprint'],
			'external_revocation_receipt_sha256' => isset( $input['external_revocation_receipt_sha256'] ) ? strtolower( (string) $input['external_revocation_receipt_sha256'] ) : '',
			'portable_export_bundle_sha256' => isset( $input['portable_export_bundle_sha256'] ) ? strtolower( (string) $input['portable_export_bundle_sha256'] ) : '',
			'blockers' => array_values( array_unique( $blockers ) ),
			'ready' => empty( $blockers ),
			'content_delete_performed' => false,
			'production_authorized' => false,
			'mutation_performed' => false,
		);
		$plan['plan_sha256'] = self::digest_without( $plan, array( 'plan_sha256', 'mutation_performed' ) );
		return $plan;
	}

	public static function finalize_apply( $input = array() ) {
		$plan = isset( $input['plan'] ) && is_array( $input['plan'] ) ? $input['plan'] : array();
		$valid = self::validate_plan( $plan, 'mad4b.decommission-finalize-plan.v1', 'finalize' );
		if ( is_wp_error( $valid ) ) return $valid;
		if ( empty( $plan['ready'] ) || ! empty( $plan['blockers'] ) ) return new WP_Error( 'mad4b_decommission_finalize_blocked', 'Decommission finalization has unresolved blockers.', array( 'blockers' => isset( $plan['blockers'] ) ? $plan['blockers'] : array() ) );
		$current = self::state();
		if ( 'QUIESCED' !== (string) $current['state']
			|| (int) $plan['expected_generation'] !== (int) $current['generation']
			|| ! hash_equals( (string) $plan['state_fingerprint'], self::digest( $current ) ) ) {
			return new WP_Error( 'mad4b_decommission_finalize_state_drift', 'Decommission state changed after finalization planning.' );
		}
		$inventory = self::live_inventory();
		if ( is_wp_error( $inventory ) ) return $inventory;
		if ( ! hash_equals( (string) $plan['inventory_fingerprint'], (string) $inventory['inventory_fingerprint'] ) ) return new WP_Error( 'mad4b_decommission_finalize_inventory_drift', 'Live inventory changed after finalization planning.' );
		$receipt = self::digest( array(
			'state_fingerprint' => (string) $plan['state_fingerprint'],
			'inventory_fingerprint' => (string) $plan['inventory_fingerprint'],
			'external_revocation_receipt_sha256' => (string) $plan['external_revocation_receipt_sha256'],
			'portable_export_bundle_sha256' => (string) $plan['portable_export_bundle_sha256'],
		) );
		$current['state'] = 'RETIRED';
		$current['generation'] = (int) $current['generation'] + 1;
		$current['changed_at'] = gmdate( 'c' );
		$current['changed_by'] = 'governed_decommission_finalize';
		$current['retirement_receipt_sha256'] = $receipt;
		update_option( self::OPTION, $current, false );
		return array( 'contract' => self::CONTRACT, 'state' => self::state(), 'retirement_receipt_sha256' => $receipt, 'ordinary_mutations_quiesced' => true, 'production_authorized' => false, 'mutation_performed' => true );
	}

	private static function validate_plan( array $plan, $contract, $action ) {
		if ( $contract !== (string) ( isset( $plan['contract'] ) ? $plan['contract'] : '' ) || $action !== (string) ( isset( $plan['action'] ) ? $plan['action'] : '' ) ) {
			return new WP_Error( 'mad4b_decommission_plan_contract_invalid', 'Decommission plan contract/action is invalid.' );
		}
		$sha = strtolower( trim( (string) ( isset( $plan['plan_sha256'] ) ? $plan['plan_sha256'] : '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha ) || ! hash_equals( $sha, self::digest_without( $plan, array( 'plan_sha256', 'mutation_performed' ) ) ) ) {
			return new WP_Error( 'mad4b_decommission_plan_digest_invalid', 'Decommission plan digest mismatch.' );
		}
		return true;
	}

	private static function known( $count, $source, $complete ) {
		return array( 'known' => true, 'count' => max( 0, (int) $count ), 'source' => sanitize_key( (string) $source ), 'complete' => (bool) $complete, 'certified' => true );
	}

	private static function unknown( $reason ) {
		return array( 'known' => false, 'count' => null, 'source' => '', 'complete' => false, 'certified' => false, 'reason' => sanitize_key( (string) $reason ) );
	}

	private static function validated_dimensions( array $base, array $candidate ) {
		foreach ( $base as $name => $current ) {
			if ( ! isset( $candidate[ $name ] ) || ! is_array( $candidate[ $name ] ) ) continue;
			$row = $candidate[ $name ];
			if ( empty( $row['certified'] ) || empty( $row['known'] ) || ! isset( $row['count'] ) || ! is_numeric( $row['count'] ) || empty( $row['source'] ) ) continue;
			$base[ $name ] = self::known( (int) $row['count'], (string) $row['source'], ! empty( $row['complete'] ) );
		}
		return $base;
	}

	private static function digest_without( $value, array $keys ) {
		if ( is_array( $value ) ) foreach ( $keys as $key ) unset( $value[ $key ] );
		return self::digest( $value );
	}

	private static function digest( $value ) {
		$json = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
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

MAD4B_SCP_Decommission_Governance::boot();
