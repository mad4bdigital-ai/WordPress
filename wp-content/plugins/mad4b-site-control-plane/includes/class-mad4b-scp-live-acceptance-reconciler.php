<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Repairs Live Acceptance finalization without weakening the external gates.
 *
 * Mutation acceptance is reconstructed from durable append-only audit events,
 * mutation records and exact candidate-bound approval tickets. The observer
 * ledger remains an optimization only. External snapshot acceptance is persisted
 * only after a verified ChatGPT OAuth/MCP request presents the snapshot token
 * carried by the portable Plugin package.
 */
final class MAD4B_SCP_Live_Acceptance_Reconciler {
	const CONTRACT = 'mad4b.live-acceptance-reconciler.v1';
	const RECONSTRUCTION_DIAGNOSTICS_CONTRACT = 'mad4b.mutation-reconstruction-diagnostics.v1';
	const SNAPSHOT_CONTRACT = 'mad4b.external-snapshot-attestation.v1';
	const SNAPSHOT_OPTION = 'mad4b_scp_external_snapshot_attestation_v1';
	const SNAPSHOT_TTL = 1800;
	const AUDIT_LIMIT = 200;
	const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
	const SERVER_ID = 'mad4b-chatgpt';
	const STAGING_ORIGIN = 'https://staging.egypttourgates.com';

	private static $booted = false;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'bind_callbacks' ), 190, 2 );
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_evidence_adapter' ), 5, 1 );
	}

	public static function bind_callbacks( $args, $name ) {
		if ( ! is_array( $args ) ) return $args;
		if ( 'mad4b/snapshot-verify' === (string) $name ) {
			$args['execute_callback'] = array( __CLASS__, 'snapshot_verify' );
		}
		if ( 'mad4b/live-acceptance-status' === (string) $name ) {
			$args['execute_callback'] = array( __CLASS__, 'live_acceptance_status' );
		}
		return $args;
	}

	/**
	 * Make the already-bounded mutation evidence ability part of the ChatGPT read
	 * projection without turning it into a writer or registering a second ability.
	 */
	public static function register_evidence_adapter( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) || ! class_exists( 'MAD4B_SCP_Adapter_Base' ) ) return;
		$registry->register( new class extends MAD4B_SCP_Adapter_Base {
			public function id() { return 'acceptance-evidence'; }
			public function label() { return 'Acceptance Evidence'; }
			public function is_available() { return true; }
			public function ability_names() { return array( 'read' => array( 'mad4b/mutation-get' ), 'content' => array(), 'admin' => array() ); }
			public function register_abilities() { /* Existing governed ability; projection only. */ }
			protected function mutation_requires_certification() { return false; }
			protected function provider_certification( $available ) { return null; }
			public function status() {
				return array(
					'id' => $this->id(), 'label' => $this->label(), 'available' => true,
					'abilities' => $this->ability_names(), 'version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '',
					'mutation_master_enabled' => class_exists( 'MAD4B_SCP_Policy' ) ? MAD4B_SCP_Policy::can_mutate() : false,
					'reversible_contracts' => array(), 'mutation_requires_certification' => false,
				);
			}
		} );
	}

	public static function live_acceptance_status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$base_input = $input;
		unset( $base_input['client_snapshot_token'] );
		$base = class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' )
			? MAD4B_SCP_Live_Acceptance_Finalizer::live_acceptance_status( $base_input )
			: array( 'contract' => 'mad4b.live-acceptance-status.v1', 'gates' => array() );
		if ( ! is_array( $base ) ) $base = array( 'contract' => 'mad4b.live-acceptance-status.v1', 'gates' => array() );
		if ( ! isset( $base['gates'] ) || ! is_array( $base['gates'] ) ) $base['gates'] = array();

		$base['gates']['mutation_acceptance'] = self::mutation_acceptance_status();
		if ( ! empty( $input['client_snapshot_token'] ) ) {
			self::snapshot_verify( array( 'client_snapshot_token' => (string) $input['client_snapshot_token'] ) );
		}
		$base['gates']['snapshot_external'] = self::external_snapshot_status();
		$base['ready'] = class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' )
			? MAD4B_SCP_Live_Acceptance_Finalizer::aggregate_ready( $base['gates'] )
			: self::all_ready( $base['gates'] );
		$base['state'] = $base['ready'] ? 'ready' : 'pending_or_blocked';
		$base['reconciler_contract'] = self::CONTRACT;
		$base['external_facts_self_certified'] = false;
		return $base;
	}

	public static function mutation_acceptance_status() {
		$candidate = self::current_candidate();
		if ( empty( $candidate['ready'] ) ) return self::gate( false, 'candidate_unavailable', false, 'mad4b.mutation-acceptance-receipt.v1', array( 'candidate_provenance_unavailable' ) );

		$cached = class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' ) ? MAD4B_SCP_Live_Acceptance_Finalizer::mutation_acceptance_status() : array();
		if ( is_array( $cached ) && ! empty( $cached['ready'] ) ) return $cached;

		$diagnostics = array();
		$receipt = self::reconstruct_mutation_receipt( $candidate, $diagnostics );
		if ( empty( $receipt ) ) {
			$gate = is_array( $cached ) && ! empty( $cached ) ? $cached : self::gate( false, 'pending_external_evidence', false, 'mad4b.mutation-acceptance-receipt.v1', array( 'complete_execute_replay_undo_receipt_required' ) );
			$gate['evidence_source'] = 'durable_reconstruction_unavailable';
			$gate['reconstruction'] = $diagnostics;
			$gate['first_reconstruction_failure'] = isset( $diagnostics['first_reconstruction_failure'] ) ? (string) $diagnostics['first_reconstruction_failure'] : 'durable_reconstruction_unavailable';
			return $gate;
		}

		$result = class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' )
			? MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $receipt, $candidate )
			: self::gate( false, 'finalizer_unavailable', false, 'mad4b.mutation-acceptance-receipt.v1', array( 'finalizer_unavailable' ) );
		$result['evidence_source'] = 'durable_authoritative_reconstruction';
		$result['mutation_id'] = isset( $receipt['mutation_id'] ) ? (string) $receipt['mutation_id'] : '';
		$result['recovery_mutation_id'] = isset( $receipt['recovery_mutation_id'] ) ? (string) $receipt['recovery_mutation_id'] : '';
		$result['approval_ticket_id'] = isset( $receipt['approval_ticket_id'] ) ? (string) $receipt['approval_ticket_id'] : '';
		$result['undo_approval_ticket_id'] = isset( $receipt['undo_approval_ticket_id'] ) ? (string) $receipt['undo_approval_ticket_id'] : '';
		return $result;
	}

	private static function reconstruct_mutation_receipt( array $candidate, array &$diagnostics ) {
		$diagnostics = self::reconstruction_diagnostics_base( $candidate );

		if ( ! class_exists( 'MAD4B_SCP_Audit' ) || ! class_exists( 'MAD4B_SCP_Mutation_Manager' ) || ! class_exists( 'MAD4B_SCP_Approval_Tickets' ) ) {
			self::remember_reconstruction_failure( $diagnostics, array(), 'reconstruction_dependencies_unavailable' );
			return array();
		}

		$audit_valid = true === MAD4B_SCP_Audit::verify_chain();
		$diagnostics['audit_chain_valid'] = $audit_valid;
		if ( ! $audit_valid ) {
			self::remember_reconstruction_failure( $diagnostics, array(), 'audit_chain_invalid' );
			return array();
		}

		$events = MAD4B_SCP_Audit::tail( self::AUDIT_LIMIT );
		$diagnostics['audit_tail_event_available'] = is_array( $events ) && ! empty( $events );
		if ( ! is_array( $events ) || empty( $events ) ) {
			self::remember_reconstruction_failure( $diagnostics, array(), 'audit_tail_empty' );
			return array();
		}

		$undos = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || 'mad4b/mutation-undo' !== ( isset( $event['ability'] ) ? (string) $event['ability'] : '' ) || 'ok' !== ( isset( $event['status'] ) ? (string) $event['status'] : '' ) ) continue;
			$summary = isset( $event['summary'] ) && is_array( $event['summary'] ) ? $event['summary'] : array();
			if ( empty( $summary['mutation_id'] ) || empty( $summary['recovery_mutation_id'] ) ) continue;
			$undos[] = $event;
		}
		$undos = array_reverse( $undos );
		$diagnostics['undo_candidates_considered'] = count( $undos );
		if ( empty( $undos ) ) {
			self::remember_reconstruction_failure( $diagnostics, array(), 'undo_event_not_found_within_audit_tail' );
			return array();
		}

		foreach ( $undos as $undo ) {
			$summary = $undo['summary'];
			$mutation_id = strtolower( trim( (string) $summary['mutation_id'] ) );
			$recovery_id = strtolower( trim( (string) $summary['recovery_mutation_id'] ) );
			$record = MAD4B_SCP_Mutation_Manager::get( $mutation_id );
			$recovery = MAD4B_SCP_Mutation_Manager::get( $recovery_id );
			$attempt = array(
				'mutation_id' => $mutation_id,
				'recovery_mutation_id' => $recovery_id,
				'original_mutation_found' => is_array( $record ),
				'recovery_mutation_found' => is_array( $recovery ),
				'mutation_pair_valid' => false,
				'undo_event_found' => true,
				'execution_ticket_found' => false,
				'undo_ticket_found' => false,
				'execution_ticket_candidate_binding_exact' => null,
				'undo_ticket_candidate_binding_exact' => null,
				'execution_event_found' => false,
				'replay_denial_event_found' => false,
			);

			if ( ! is_array( $record ) || ! is_array( $recovery ) ) {
				self::remember_reconstruction_failure( $diagnostics, $attempt, 'mutation_record_missing' );
				continue;
			}
			if ( ! self::valid_mutation_pair( $record, $recovery, $mutation_id, $summary ) ) {
				self::remember_reconstruction_failure( $diagnostics, $attempt, 'mutation_pair_invalid' );
				continue;
			}
			$attempt['mutation_pair_valid'] = true;

			$ticket_id = isset( $record['approval_ticket_id'] ) ? strtolower( trim( (string) $record['approval_ticket_id'] ) ) : '';
			$undo_ticket_id = isset( $recovery['approval_ticket_id'] ) ? strtolower( trim( (string) $recovery['approval_ticket_id'] ) ) : '';
			$ticket_diagnostics = array();
			$undo_ticket_diagnostics = array();
			$ticket = self::validated_used_ticket( $ticket_id, $candidate, isset( $record['ability_name'] ) ? (string) $record['ability_name'] : '', $ticket_diagnostics );
			$undo_ticket = self::validated_used_ticket( $undo_ticket_id, $candidate, 'mad4b/mutation-undo', $undo_ticket_diagnostics );

			$attempt['execution_ticket_found'] = ! empty( $ticket_diagnostics['ticket_found'] );
			$attempt['undo_ticket_found'] = ! empty( $undo_ticket_diagnostics['ticket_found'] );
			$attempt['execution_ticket_candidate_binding_exact'] = isset( $ticket_diagnostics['candidate_binding_exact'] ) ? (bool) $ticket_diagnostics['candidate_binding_exact'] : null;
			$attempt['undo_ticket_candidate_binding_exact'] = isset( $undo_ticket_diagnostics['candidate_binding_exact'] ) ? (bool) $undo_ticket_diagnostics['candidate_binding_exact'] : null;

			if ( empty( $ticket ) || empty( $undo_ticket ) ) {
				$ticket_failure = isset( $ticket_diagnostics['failure'] ) ? (string) $ticket_diagnostics['failure'] : '';
				$undo_ticket_failure = isset( $undo_ticket_diagnostics['failure'] ) ? (string) $undo_ticket_diagnostics['failure'] : '';
				$failure = in_array( 'candidate_binding_missing_or_stale', array( $ticket_failure, $undo_ticket_failure ), true )
					? 'candidate_binding_missing_or_stale'
					: ( $ticket_failure ? $ticket_failure : ( $undo_ticket_failure ? $undo_ticket_failure : 'approval_ticket_validation_failed' ) );
				self::remember_reconstruction_failure( $diagnostics, $attempt, $failure );
				continue;
			}

			$undo_sequence = isset( $undo['sequence'] ) ? (int) $undo['sequence'] : 0;
			$execution = self::find_execution_event( $events, $record, $mutation_id, $undo_sequence );
			if ( empty( $execution ) ) {
				self::remember_reconstruction_failure( $diagnostics, $attempt, 'execution_event_not_found' );
				continue;
			}
			$attempt['execution_event_found'] = true;

			$replay = self::find_replay_event(
				$events,
				$ticket_id,
				isset( $record['ability_name'] ) ? (string) $record['ability_name'] : '',
				isset( $record['wp_user_id'] ) ? (int) $record['wp_user_id'] : 0,
				(int) $execution['sequence'],
				$undo_sequence
			);
			if ( empty( $replay ) ) {
				self::remember_reconstruction_failure( $diagnostics, $attempt, 'replay_denial_event_not_found' );
				continue;
			}
			$attempt['replay_denial_event_found'] = true;
			$replay_summary = isset( $replay['summary'] ) && is_array( $replay['summary'] ) ? $replay['summary'] : array();
			$replay_ticket = isset( $replay_summary['approval_ticket_id'] ) ? strtolower( trim( (string) $replay_summary['approval_ticket_id'] ) ) : '';

			return array(
				'contract' => 'mad4b.mutation-acceptance-receipt.v1',
				'candidate_sha' => $candidate['source_commit_sha'],
				'build_fingerprint' => $candidate['build_fingerprint'],
				'environment' => 'staging', 'origin' => self::STAGING_ORIGIN,
				'mutation_id' => $mutation_id,
				'approval_ticket_id' => $ticket_id,
				'undo_approval_ticket_id' => $undo_ticket_id,
				'ability' => isset( $record['ability_name'] ) ? (string) $record['ability_name'] : '',
				'provider' => isset( $record['provider'] ) ? (string) $record['provider'] : '',
				'target_type' => isset( $record['target_type'] ) ? (string) $record['target_type'] : '',
				'target_id' => isset( $record['target_id'] ) ? (string) $record['target_id'] : '',
				'before_sha256' => isset( $record['before_sha256'] ) ? (string) $record['before_sha256'] : '',
				'after_sha256' => isset( $record['after_sha256'] ) ? (string) $record['after_sha256'] : '',
				'mutation_status' => isset( $record['status'] ) ? (string) $record['status'] : '',
				'approval_status' => (string) $ticket['status'],
				'approved_by' => isset( $ticket['approved_by'] ) ? (int) $ticket['approved_by'] : 0,
				'approved_at' => isset( $ticket['approved_at'] ) ? (string) $ticket['approved_at'] : '',
				'used_at' => isset( $ticket['used_at'] ) ? (string) $ticket['used_at'] : '',
				'execution_verified' => true,
				'execution_event_hash' => isset( $execution['entry_hash'] ) ? (string) $execution['entry_hash'] : '',
				'execution_sequence' => (int) $execution['sequence'],
				'replay_denied' => true,
				'replay_binding' => '' !== $replay_ticket ? 'ticket_exact' : 'legacy_unique_sequence_ability_user',
				'replay_event_hash' => isset( $replay['entry_hash'] ) ? (string) $replay['entry_hash'] : '',
				'replay_sequence' => (int) $replay['sequence'],
				'undo_verified' => true,
				'undo_event_hash' => isset( $undo['entry_hash'] ) ? (string) $undo['entry_hash'] : '',
				'undo_sequence' => $undo_sequence,
				'recovery_mutation_id' => $recovery_id,
				'restored_sha256' => isset( $summary['restored_sha256'] ) ? (string) $summary['restored_sha256'] : '',
				'recovery_before_sha256' => isset( $recovery['before_sha256'] ) ? (string) $recovery['before_sha256'] : '',
				'recovery_after_sha256' => isset( $recovery['after_sha256'] ) ? (string) $recovery['after_sha256'] : '',
				'recovery_verification_code' => isset( $recovery['verification_code'] ) ? (string) $recovery['verification_code'] : '',
				'audit_chain_valid' => true,
				'observed_at' => isset( $undo['time'] ) ? (string) $undo['time'] : '',
			);
		}

		if ( empty( $diagnostics['first_reconstruction_failure'] ) ) {
			self::remember_reconstruction_failure( $diagnostics, array(), 'no_complete_execute_replay_undo_chain_found' );
		}
		return array();
	}

	private static function reconstruction_diagnostics_base( array $candidate ) {
		return array(
			'contract' => self::RECONSTRUCTION_DIAGNOSTICS_CONTRACT,
			'first_reconstruction_failure' => '',
			'current_candidate_sha' => isset( $candidate['source_commit_sha'] ) ? (string) $candidate['source_commit_sha'] : '',
			'current_build_fingerprint' => isset( $candidate['build_fingerprint'] ) ? (string) $candidate['build_fingerprint'] : '',
			'audit_limit' => self::AUDIT_LIMIT,
			'audit_chain_valid' => null,
			'audit_tail_event_available' => null,
			'undo_candidates_considered' => 0,
		);
	}

	private static function remember_reconstruction_failure( array &$diagnostics, array $attempt, $failure ) {
		if ( ! empty( $diagnostics['first_reconstruction_failure'] ) ) return;
		foreach ( $attempt as $key => $value ) $diagnostics[ $key ] = $value;
		$diagnostics['first_reconstruction_failure'] = (string) $failure;
	}

	private static function valid_mutation_pair( $record, $recovery, $mutation_id, array $undo_summary ) {
		if ( ! is_array( $record ) || ! is_array( $recovery ) || empty( $record['reversible'] ) || 'undone' !== (string) $record['status'] || 'undone' !== (string) $recovery['status'] ) return false;
		if ( empty( $record['before_sha256'] ) || empty( $record['after_sha256'] ) || hash_equals( (string) $record['before_sha256'], (string) $record['after_sha256'] ) ) return false;
		if ( ! isset( $recovery['parent_mutation_id'] ) || ! hash_equals( $mutation_id, (string) $recovery['parent_mutation_id'] ) ) return false;
		$restored = isset( $undo_summary['restored_sha256'] ) ? (string) $undo_summary['restored_sha256'] : '';
		return self::valid_hash( $restored )
			&& hash_equals( (string) $record['before_sha256'], $restored )
			&& hash_equals( (string) $record['after_sha256'], (string) $recovery['before_sha256'] )
			&& hash_equals( (string) $record['before_sha256'], (string) $recovery['after_sha256'] )
			&& in_array( isset( $recovery['verification_code'] ) ? (string) $recovery['verification_code'] : '', array( 'restore_readback_match', 'adapter_restore_readback_match' ), true );
	}

	private static function validated_used_ticket( $ticket_id, array $candidate, $expected_ability, array &$diagnostics ) {
		$diagnostics = array(
			'ticket_found' => false,
			'ticket_used' => false,
			'ability_match' => false,
			'candidate_binding_present' => false,
			'candidate_binding_exact' => false,
			'environment_binding_exact' => false,
			'failure' => '',
		);

		if ( ! preg_match( '/^[a-f0-9-]{36}$/', (string) $ticket_id ) ) {
			$diagnostics['failure'] = 'approval_ticket_id_invalid';
			return array();
		}

		$ticket = MAD4B_SCP_Approval_Tickets::get( $ticket_id );
		$binding = MAD4B_SCP_Approval_Tickets::candidate_binding( $ticket_id );
		$diagnostics['ticket_found'] = is_array( $ticket );
		if ( ! is_array( $ticket ) ) {
			$diagnostics['failure'] = 'approval_ticket_not_found';
			return array();
		}

		$used = 'used' === ( isset( $ticket['status'] ) ? (string) $ticket['status'] : '' )
			&& ! empty( $ticket['approved_by'] )
			&& ! empty( $ticket['approved_at'] )
			&& ! empty( $ticket['used_at'] );
		$diagnostics['ticket_used'] = $used;
		if ( ! $used ) {
			$diagnostics['failure'] = 'approval_ticket_not_used_or_incomplete';
			return array();
		}

		$ability_match = ! $expected_ability || (string) $expected_ability === ( isset( $ticket['ability_name'] ) ? (string) $ticket['ability_name'] : '' );
		$diagnostics['ability_match'] = $ability_match;
		if ( ! $ability_match ) {
			$diagnostics['failure'] = 'approval_ticket_ability_mismatch';
			return array();
		}

		$binding_present = is_array( $binding ) && ! empty( $binding['candidate_sha'] ) && ! empty( $binding['build_fingerprint'] );
		$diagnostics['candidate_binding_present'] = $binding_present;
		if ( ! $binding_present ) {
			$diagnostics['failure'] = 'candidate_binding_missing_or_stale';
			return array();
		}

		$binding_exact = hash_equals( (string) $candidate['source_commit_sha'], (string) $binding['candidate_sha'] )
			&& hash_equals( (string) $candidate['build_fingerprint'], (string) $binding['build_fingerprint'] );
		$diagnostics['candidate_binding_exact'] = $binding_exact;
		if ( ! $binding_exact ) {
			$diagnostics['failure'] = 'candidate_binding_missing_or_stale';
			return array();
		}

		$environment_exact = 'staging' === ( isset( $binding['environment'] ) ? (string) $binding['environment'] : '' )
			&& 'staging.egypttourgates.com' === ( isset( $binding['host'] ) ? (string) $binding['host'] : '' );
		$diagnostics['environment_binding_exact'] = $environment_exact;
		if ( ! $environment_exact ) {
			$diagnostics['failure'] = 'candidate_binding_environment_mismatch';
			return array();
		}

		return $ticket;
	}

	private static function find_execution_event( array $events, array $record, $mutation_id, $before_sequence ) {
		$best = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || 'ok' !== ( isset( $event['status'] ) ? (string) $event['status'] : '' ) ) continue;
			$sequence = isset( $event['sequence'] ) ? (int) $event['sequence'] : 0;
			if ( $sequence < 1 || $sequence >= $before_sequence ) continue;
			$ability = isset( $event['ability'] ) ? (string) $event['ability'] : '';
			if ( 'mad4b/mutation-undo' === $ability || 0 === strpos( $ability, 'mad4b/live-acceptance-' ) || 0 === strpos( $ability, 'mad4b/authorization:' ) ) continue;
			$summary = isset( $event['summary'] ) && is_array( $event['summary'] ) ? $event['summary'] : array();
			if ( empty( $summary['mutation_id'] ) || ! hash_equals( (string) $mutation_id, strtolower( (string) $summary['mutation_id'] ) ) ) continue;
			if ( empty( $summary['before_sha256'] ) || empty( $summary['after_sha256'] ) ) continue;
			if ( ! hash_equals( (string) $record['before_sha256'], (string) $summary['before_sha256'] ) || ! hash_equals( (string) $record['after_sha256'], (string) $summary['after_sha256'] ) ) continue;
			$best = $event;
		}
		return $best;
	}

	private static function find_replay_event( array $events, $ticket_id, $expected_ability, $expected_user_id, $after_sequence, $before_sequence ) {
		$ticket_id = strtolower( trim( (string) $ticket_id ) );
		$expected_audit_ability = 'mad4b/authorization:' . (string) $expected_ability;
		$legacy = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || 'denied' !== ( isset( $event['status'] ) ? (string) $event['status'] : '' ) ) continue;
			$sequence = isset( $event['sequence'] ) ? (int) $event['sequence'] : 0;
			if ( $sequence <= $after_sequence || $sequence >= $before_sequence ) continue;
			if ( $expected_audit_ability !== ( isset( $event['ability'] ) ? (string) $event['ability'] : '' ) ) continue;
			if ( $expected_user_id > 0 && isset( $event['user_id'] ) && (int) $event['user_id'] > 0 && (int) $event['user_id'] !== (int) $expected_user_id ) continue;

			$summary = isset( $event['summary'] ) && is_array( $event['summary'] ) ? $event['summary'] : array();
			if ( 'mad4b_approval_replay_denied' !== ( isset( $summary['reason_code'] ) ? (string) $summary['reason_code'] : '' ) ) continue;
			$event_ticket = isset( $summary['approval_ticket_id'] ) ? strtolower( trim( (string) $summary['approval_ticket_id'] ) ) : '';

			// Current evidence must bind directly to the exact ticket. A non-empty
			// mismatching ticket is never eligible for legacy recovery.
			if ( '' !== $event_ticket ) {
				if ( hash_equals( $ticket_id, $event_ticket ) ) return $event;
				continue;
			}

			// rc.27 wrote real replay-denial events before the governance-input
			// ticket could be copied into Identity Context. Recover only a unique
			// ticket-less replay denial bounded by the exact execution + undo, the
			// original ability, and (when available) the WordPress subject. Multiple
			// candidates remain ambiguous and fail closed.
			$legacy[] = $event;
		}

		return 1 === count( $legacy ) ? $legacy[0] : array();
	}

	public static function snapshot_verify( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$base = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::snapshot_verify( $input ) : array();
		$candidate = self::current_candidate();
		$external = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::external_handshake_attestation_status() : array();
		$trusted = self::trusted_external_context( $external );
		$recorded = false;
		if ( $trusted && ! empty( $candidate['ready'] ) && ! empty( $base['exact_match'] ) && ! empty( $base['client_snapshot_token'] ) ) {
			$attestation = array(
				'contract' => self::SNAPSHOT_CONTRACT,
				'candidate_sha' => $candidate['source_commit_sha'],
				'build_fingerprint' => $candidate['build_fingerprint'],
				'snapshot_identity_token' => (string) $base['client_snapshot_token'],
				'client_id' => isset( $external['client_id'] ) && $external['client_id'] ? (string) $external['client_id'] : self::CHATGPT_CLIENT_ID,
				'server_id' => isset( $external['server_id'] ) && $external['server_id'] ? (string) $external['server_id'] : self::SERVER_ID,
				'external_tool_inventory_fingerprint' => isset( $external['external_tool_inventory_fingerprint'] ) ? (string) $external['external_tool_inventory_fingerprint'] : '',
				'external_write_inventory_fingerprint' => isset( $external['external_write_inventory_fingerprint'] ) ? (string) $external['external_write_inventory_fingerprint'] : '',
				'session_fingerprint_present' => ! empty( $external['session_fingerprint_present'] ),
				'observed_at' => gmdate( 'c' ),
			);
			update_option( self::SNAPSHOT_OPTION, $attestation, false );
			$recorded = true;
		}
		$base['external_attestation_contract'] = self::SNAPSHOT_CONTRACT;
		$base['trusted_external_context'] = (bool) $trusted;
		$base['attestation_recorded'] = $recorded;
		return $base;
	}

	public static function external_snapshot_status() {
		$candidate = self::current_candidate();
		$stored = get_option( self::SNAPSHOT_OPTION, array() );
		$external = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::external_handshake_attestation_status() : array();
		$live = class_exists( 'MAD4B_SCP_Skill_Snapshot_Identity' ) ? MAD4B_SCP_Skill_Snapshot_Identity::build() : array();
		$blockers = array();
		if ( ! is_array( $stored ) || self::SNAPSHOT_CONTRACT !== ( isset( $stored['contract'] ) ? (string) $stored['contract'] : '' ) ) $blockers[] = 'trusted_external_snapshot_attestation_required';
		if ( empty( $candidate['ready'] ) || empty( $stored['candidate_sha'] ) || ! hash_equals( (string) $candidate['source_commit_sha'], (string) $stored['candidate_sha'] ) ) $blockers[] = 'candidate_mismatch';
		if ( empty( $candidate['build_fingerprint'] ) || empty( $stored['build_fingerprint'] ) || ! hash_equals( (string) $candidate['build_fingerprint'], (string) $stored['build_fingerprint'] ) ) $blockers[] = 'build_fingerprint_mismatch';
		$token = isset( $stored['snapshot_identity_token'] ) ? (string) $stored['snapshot_identity_token'] : '';
		$live_token = isset( $live['identity_token'] ) ? (string) $live['identity_token'] : '';
		if ( ! preg_match( '/^sha256:[a-f0-9]{64}$/', $token ) || '' === $live_token || ! hash_equals( $live_token, $token ) ) $blockers[] = 'snapshot_token_mismatch';
		if ( empty( $external['verified'] ) || empty( $external['real_external_session'] ) ) $blockers[] = 'external_session_not_verified';
		if ( ! empty( $stored['external_tool_inventory_fingerprint'] ) && isset( $external['external_tool_inventory_fingerprint'] ) && ! hash_equals( (string) $stored['external_tool_inventory_fingerprint'], (string) $external['external_tool_inventory_fingerprint'] ) ) $blockers[] = 'external_inventory_changed';
		$observed_at = isset( $stored['observed_at'] ) ? (string) $stored['observed_at'] : '';
		$ts = $observed_at ? strtotime( $observed_at ) : false;
		$fresh = false !== $ts && $ts <= time() + 60 && ( time() - $ts ) <= self::SNAPSHOT_TTL;
		if ( ! $fresh ) $blockers[] = 'stale_external_snapshot_attestation';
		$blockers = array_values( array_unique( $blockers ) );
		return self::gate( empty( $blockers ), empty( $blockers ) ? 'ready' : 'pending_external_evidence', $fresh && empty( $blockers ), self::SNAPSHOT_CONTRACT, $blockers, $observed_at );
	}

	private static function trusted_external_context( array $external ) {
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return false;
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : null;
		if ( is_wp_error( $identity ) || ! is_array( $identity ) || empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return false;
		$scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? $identity['token_scopes'] : array();
		if ( ! in_array( 'mad4b:read', $scopes, true ) ) return false;
		if ( empty( $external['verified'] ) || empty( $external['real_external_session'] ) ) return false;
		if ( ! empty( $external['client_id'] ) && self::CHATGPT_CLIENT_ID !== (string) $external['client_id'] ) return false;
		if ( ! empty( $external['server_id'] ) && self::SERVER_ID !== (string) $external['server_id'] ) return false;
		return true;
	}

	private static function current_candidate() {
		$provenance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
		$sha = isset( $provenance['source_commit_sha'] ) ? strtolower( (string) $provenance['source_commit_sha'] ) : '';
		$fingerprint = isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
		$ready = ! empty( $provenance['runtime_manifest_match'] ) && preg_match( '/^[a-f0-9]{40}$/', $sha ) && self::valid_hash( $fingerprint );
		return array( 'ready' => (bool) $ready, 'source_commit_sha' => $sha, 'build_fingerprint' => $fingerprint );
	}

	private static function valid_hash( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value ); }
	private static function all_ready( array $gates ) { foreach ( $gates as $gate ) if ( ! is_array( $gate ) || empty( $gate['ready'] ) ) return false; return ! empty( $gates ); }
	private static function gate( $ready, $state, $fresh, $contract, array $blockers, $observed_at = '' ) {
		return array(
			'ready' => (bool) $ready, 'state' => (string) $state, 'fresh' => (bool) $fresh,
			'evidence_contract' => (string) $contract, 'observed_at' => (string) $observed_at,
			'blockers' => array_values( array_unique( array_filter( array_map( 'strval', $blockers ) ) ) ),
		);
	}
}

MAD4B_SCP_Live_Acceptance_Reconciler::boot_early();
