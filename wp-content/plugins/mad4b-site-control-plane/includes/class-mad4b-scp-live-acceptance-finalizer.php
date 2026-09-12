<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Candidate-bound Live Acceptance finalization.
 *
 * The legacy observer owns passive base evidence. This class owns the two facts
 * that must never be caller booleans: governed mutation acceptance and the
 * external Production-unchanged attestation. Mutation evidence is reconstructed
 * from the append-only audit chain + mutation/approval records. Production proof
 * is accepted only from the already-authenticated external read-only finalizer
 * and is candidate/fingerprint/freshness/digest bound.
 */
final class MAD4B_SCP_Live_Acceptance_Finalizer {
	const CONTRACT = 'mad4b.live-acceptance-finalizer.v1';
	const MUTATION_CONTRACT = 'mad4b.mutation-acceptance-receipt.v1';
	const PRODUCTION_CONTRACT = 'mad4b.production-unchanged-receipt.v1';
	const WPML_DIAGNOSTIC_CONTRACT = 'mad4b.external-wpml-diagnostic.v2';
	const LEDGER_OPTION = 'mad4b_scp_live_acceptance_mutation_ledger_v1';
	const WPML_DIAGNOSTIC_OPTION = 'mad4b_scp_external_wpml_diagnostic_v2';
	const STAGING_ORIGIN = 'https://staging.egypttourgates.com';
	const PRODUCTION_ORIGIN = 'https://egypttourgates.com';
	const FINALIZER_ISSUER = 'chatgpt_external_read_only_finalizer';
	const FINALIZER_PROVENANCE = 'verified_oauth_readonly_session';
	const MUTATION_TTL = 21600;
	const PRODUCTION_TTL = 1800;
	const MAX_LEDGER_ENTRIES = 12;

	private static $booted = false;
	private static $recording_acceptance_event = false;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'bind_finalizer_callbacks' ), 160, 2 );
		add_action( 'mad4b_scp_audit_committed', array( __CLASS__, 'observe_authoritative_audit' ), 40, 1 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'observe_wpml_response' ), PHP_INT_MAX - 10, 3 );
	}

	public static function bind_finalizer_callbacks( $args, $name ) {
		if ( ! is_array( $args ) ) return $args;
		$name = (string) $name;
		if ( 'mad4b/live-acceptance-status' === $name ) {
			$args['execute_callback'] = array( __CLASS__, 'live_acceptance_status' );
			$args['input_schema'] = array(
				'type' => 'object',
				'properties' => array(
					'client_snapshot_token' => array( 'type' => 'string', 'maxLength' => 80 ),
					'production_receipt' => self::production_receipt_schema(),
				),
				'additionalProperties' => false,
			);
		}
		if ( 'mad4b/external-wpml-receipt-status' === $name ) {
			$args['execute_callback'] = array( __CLASS__, 'external_wpml_receipt_status' );
		}
		return $args;
	}

	private static function production_receipt_schema() {
		$hash = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		return array(
			'type' => 'object',
			'properties' => array(
				'contract' => array( 'type' => 'string', 'enum' => array( self::PRODUCTION_CONTRACT ) ),
				'candidate_sha' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}$' ),
				'build_fingerprint' => $hash,
				'target' => array( 'type' => 'string', 'enum' => array( 'production' ) ),
				'origin' => array( 'type' => 'string', 'enum' => array( self::PRODUCTION_ORIGIN ) ),
				'environment' => array( 'type' => 'string', 'enum' => array( 'production' ) ),
				'production_runtime_identity' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 240 ),
				'baseline_snapshot_digest' => $hash,
				'observed_snapshot_digest' => $hash,
				'baseline_plugin_snapshot_digest' => $hash,
				'observed_plugin_snapshot_digest' => $hash,
				'checked_at' => array( 'type' => 'string', 'minLength' => 20, 'maxLength' => 40 ),
				'issued_at' => array( 'type' => 'string', 'minLength' => 20, 'maxLength' => 40 ),
				'issuer' => array( 'type' => 'string', 'enum' => array( self::FINALIZER_ISSUER ) ),
				'provenance' => array( 'type' => 'string', 'enum' => array( self::FINALIZER_PROVENANCE ) ),
				'evidence_digest' => $hash,
			),
			'required' => array(
				'contract','candidate_sha','build_fingerprint','target','origin','environment',
				'production_runtime_identity','baseline_snapshot_digest','observed_snapshot_digest',
				'baseline_plugin_snapshot_digest','observed_plugin_snapshot_digest','checked_at',
				'issued_at','issuer','provenance','evidence_digest',
			),
			'additionalProperties' => false,
		);
	}

	public static function live_acceptance_status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$base = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' )
			? MAD4B_SCP_Live_Acceptance_Observer::live_acceptance_status( $input )
			: array( 'contract' => 'mad4b.live-acceptance-status.v1', 'gates' => array() );
		if ( ! is_array( $base ) ) $base = array( 'contract' => 'mad4b.live-acceptance-status.v1', 'gates' => array() );
		if ( ! isset( $base['gates'] ) || ! is_array( $base['gates'] ) ) $base['gates'] = array();

		$wpml = self::external_wpml_receipt_status();
		$base['gates']['external_wpml'] = self::gate(
			! empty( $wpml['verified'] ),
			isset( $wpml['state'] ) ? $wpml['state'] : 'pending_external_evidence',
			empty( $wpml['stale'] ),
			self::WPML_DIAGNOSTIC_CONTRACT,
			! empty( $wpml['verified'] ) ? array() : array( isset( $wpml['classification'] ) ? $wpml['classification'] : 'external_wpml_evidence_required' )
		);

		$mutation = self::mutation_acceptance_status();
		$base['gates']['mutation_acceptance'] = $mutation;

		$candidate = self::current_candidate();
		$receipt = isset( $input['production_receipt'] ) && is_array( $input['production_receipt'] ) ? $input['production_receipt'] : array();
		$production = self::evaluate_production_receipt( $receipt, $candidate, self::trusted_external_finalizer_context() );
		$base['gates']['production_unchanged'] = $production;

		$base['ready'] = self::aggregate_ready( $base['gates'] );
		$base['state'] = $base['ready'] ? 'ready' : 'pending_or_blocked';
		$base['finalizer_contract'] = self::CONTRACT;
		$base['external_facts_self_certified'] = false;
		$base['production_receipt_accepted_from_caller_boolean'] = false;
		return $base;
	}

	public static function aggregate_ready( array $gates ) {
		if ( empty( $gates ) ) return false;
		foreach ( $gates as $gate ) if ( ! is_array( $gate ) || empty( $gate['ready'] ) ) return false;
		return true;
	}

	public static function observe_authoritative_audit( $entry ) {
		if ( self::$recording_acceptance_event || ! self::staging_allowed() || ! is_array( $entry ) ) return;
		$ability = isset( $entry['ability'] ) ? (string) $entry['ability'] : '';
		if ( 0 === strpos( $ability, 'mad4b/live-acceptance-' ) ) return;
		$summary = isset( $entry['summary'] ) && is_array( $entry['summary'] ) ? $entry['summary'] : array();
		$candidate = self::current_candidate();
		if ( empty( $candidate['ready'] ) ) return;

		if ( 'mad4b/mutation-undo' === $ability && ! empty( $summary['mutation_id'] ) ) {
			self::record_undo_observation( $entry, $summary, $candidate );
			return;
		}

		if ( isset( $summary['reason_code'] ) && 'mad4b_approval_replay_denied' === (string) $summary['reason_code'] ) {
			self::record_replay_observation( $entry, $candidate );
			return;
		}

		if ( ! empty( $summary['mutation_id'] ) ) self::record_execution_observation( $entry, $summary, $candidate );
	}

	private static function record_execution_observation( array $entry, array $summary, array $candidate ) {
		$mutation_id = strtolower( trim( (string) $summary['mutation_id'] ) );
		$record = class_exists( 'MAD4B_SCP_Mutation_Manager' ) ? MAD4B_SCP_Mutation_Manager::get( $mutation_id ) : null;
		if ( ! is_array( $record ) || empty( $record['reversible'] ) || 'verified' !== (string) $record['status'] ) return;
		$ticket = isset( $record['approval_ticket_id'] ) ? strtolower( trim( (string) $record['approval_ticket_id'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $ticket ) ) return;
		if ( empty( $record['before_sha256'] ) || empty( $record['after_sha256'] ) ) return;
		$acceptance = self::append_acceptance_audit( 'mad4b/live-acceptance-execution-observed', array(
			'candidate_sha' => $candidate['source_commit_sha'],
			'build_fingerprint' => $candidate['build_fingerprint'],
			'mutation_id' => $mutation_id,
			'approval_ticket_id' => $ticket,
			'ability' => isset( $record['ability_name'] ) ? (string) $record['ability_name'] : '',
			'provider' => isset( $record['provider'] ) ? (string) $record['provider'] : '',
			'target_type' => isset( $record['target_type'] ) ? (string) $record['target_type'] : '',
			'target_id' => isset( $record['target_id'] ) ? (string) $record['target_id'] : '',
			'before_sha256' => (string) $record['before_sha256'],
			'after_sha256' => (string) $record['after_sha256'],
			'source_event_id' => isset( $entry['event_id'] ) ? (string) $entry['event_id'] : '',
			'source_event_hash' => isset( $entry['entry_hash'] ) ? (string) $entry['entry_hash'] : '',
		) );
		if ( ! is_array( $acceptance ) ) return;
		$ledger = self::ledger();
		$ledger[ $mutation_id ] = array(
			'mutation_id' => $mutation_id,
			'approval_ticket_id' => $ticket,
			'candidate_sha' => $candidate['source_commit_sha'],
			'build_fingerprint' => $candidate['build_fingerprint'],
			'execution_event_id' => (string) $acceptance['event_id'],
			'execution_event_hash' => (string) $acceptance['entry_hash'],
			'execution_sequence' => (int) $acceptance['sequence'],
			'replay_event_id' => '', 'replay_event_hash' => '', 'replay_sequence' => 0,
			'undo_event_id' => '', 'undo_event_hash' => '', 'undo_sequence' => 0,
			'updated_at' => gmdate( 'c' ),
		);
		self::save_ledger( $ledger );
	}

	private static function record_replay_observation( array $entry, array $candidate ) {
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : null;
		if ( is_wp_error( $identity ) || ! is_array( $identity ) ) return;
		$ticket = isset( $identity['approval_ticket_id'] ) ? strtolower( trim( (string) $identity['approval_ticket_id'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $ticket ) ) return;
		$ledger = self::ledger();
		foreach ( array_reverse( $ledger, true ) as $mutation_id => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['approval_ticket_id'], $item['candidate_sha'], $item['build_fingerprint'] ) ) continue;
			if ( ! hash_equals( $ticket, (string) $item['approval_ticket_id'] ) ) continue;
			if ( ! hash_equals( (string) $candidate['source_commit_sha'], (string) $item['candidate_sha'] ) || ! hash_equals( (string) $candidate['build_fingerprint'], (string) $item['build_fingerprint'] ) ) continue;
			$acceptance = self::append_acceptance_audit( 'mad4b/live-acceptance-replay-denied-observed', array(
				'candidate_sha' => $candidate['source_commit_sha'],
				'build_fingerprint' => $candidate['build_fingerprint'],
				'mutation_id' => (string) $mutation_id,
				'approval_ticket_id' => $ticket,
				'denial_code' => 'mad4b_approval_replay_denied',
				'source_event_id' => isset( $entry['event_id'] ) ? (string) $entry['event_id'] : '',
				'source_event_hash' => isset( $entry['entry_hash'] ) ? (string) $entry['entry_hash'] : '',
			) );
			if ( is_array( $acceptance ) ) {
				$ledger[ $mutation_id ]['replay_event_id'] = (string) $acceptance['event_id'];
				$ledger[ $mutation_id ]['replay_event_hash'] = (string) $acceptance['entry_hash'];
				$ledger[ $mutation_id ]['replay_sequence'] = (int) $acceptance['sequence'];
				$ledger[ $mutation_id ]['updated_at'] = gmdate( 'c' );
				self::save_ledger( $ledger );
			}
			return;
		}
	}

	private static function record_undo_observation( array $entry, array $summary, array $candidate ) {
		$mutation_id = strtolower( trim( (string) $summary['mutation_id'] ) );
		$ledger = self::ledger();
		if ( empty( $ledger[ $mutation_id ] ) || ! is_array( $ledger[ $mutation_id ] ) ) return;
		$item = $ledger[ $mutation_id ];
		if ( empty( $item['replay_event_id'] ) ) return;
		if ( ! hash_equals( (string) $candidate['source_commit_sha'], (string) $item['candidate_sha'] ) || ! hash_equals( (string) $candidate['build_fingerprint'], (string) $item['build_fingerprint'] ) ) return;
		$record = class_exists( 'MAD4B_SCP_Mutation_Manager' ) ? MAD4B_SCP_Mutation_Manager::get( $mutation_id ) : null;
		$recovery_id = isset( $summary['recovery_mutation_id'] ) ? strtolower( trim( (string) $summary['recovery_mutation_id'] ) ) : '';
		$recovery = $recovery_id && class_exists( 'MAD4B_SCP_Mutation_Manager' ) ? MAD4B_SCP_Mutation_Manager::get( $recovery_id ) : null;
		if ( ! is_array( $record ) || ! is_array( $recovery ) ) return;
		$restored = isset( $summary['restored_sha256'] ) ? strtolower( (string) $summary['restored_sha256'] ) : '';
		if ( empty( $record['before_sha256'] ) || ! hash_equals( (string) $record['before_sha256'], $restored ) ) return;
		if ( ! isset( $recovery['parent_mutation_id'] ) || ! hash_equals( $mutation_id, (string) $recovery['parent_mutation_id'] ) ) return;
		if ( 'undone' !== (string) $recovery['status'] || empty( $recovery['after_sha256'] ) || ! hash_equals( (string) $record['before_sha256'], (string) $recovery['after_sha256'] ) ) return;
		$acceptance = self::append_acceptance_audit( 'mad4b/live-acceptance-undo-observed', array(
			'candidate_sha' => $candidate['source_commit_sha'],
			'build_fingerprint' => $candidate['build_fingerprint'],
			'mutation_id' => $mutation_id,
			'approval_ticket_id' => isset( $record['approval_ticket_id'] ) ? (string) $record['approval_ticket_id'] : '',
			'recovery_mutation_id' => $recovery_id,
			'restored_sha256' => $restored,
			'source_event_id' => isset( $entry['event_id'] ) ? (string) $entry['event_id'] : '',
			'source_event_hash' => isset( $entry['entry_hash'] ) ? (string) $entry['entry_hash'] : '',
		) );
		if ( ! is_array( $acceptance ) ) return;
		$ledger[ $mutation_id ]['undo_event_id'] = (string) $acceptance['event_id'];
		$ledger[ $mutation_id ]['undo_event_hash'] = (string) $acceptance['entry_hash'];
		$ledger[ $mutation_id ]['undo_sequence'] = (int) $acceptance['sequence'];
		$ledger[ $mutation_id ]['recovery_mutation_id'] = $recovery_id;
		$ledger[ $mutation_id ]['updated_at'] = gmdate( 'c' );
		self::save_ledger( $ledger );
	}

	private static function append_acceptance_audit( $ability, array $summary ) {
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) return null;
		self::$recording_acceptance_event = true;
		$entry = MAD4B_SCP_Audit::record( (string) $ability, $summary, 'ok' );
		self::$recording_acceptance_event = false;
		return is_array( $entry ) ? $entry : null;
	}

	public static function mutation_acceptance_status() {
		$candidate = self::current_candidate();
		if ( empty( $candidate['ready'] ) ) return self::gate( false, 'candidate_unavailable', false, self::MUTATION_CONTRACT, array( 'candidate_provenance_unavailable' ) );
		$ledger = self::ledger();
		if ( empty( $ledger ) ) return self::gate( false, 'pending_external_evidence', false, self::MUTATION_CONTRACT, array( 'governed_mutation_receipt_required' ) );
		$items = array_reverse( $ledger, true );
		foreach ( $items as $mutation_id => $item ) {
			if ( ! is_array( $item ) || empty( $item['undo_event_id'] ) ) continue;
			$receipt = self::authoritative_mutation_receipt( (string) $mutation_id, $item );
			$result = self::evaluate_mutation_receipt( $receipt, $candidate );
			if ( ! empty( $result['ready'] ) ) return $result;
			if ( in_array( $result['state'], array( 'candidate_mismatch', 'build_fingerprint_mismatch' ), true ) ) continue;
			return $result;
		}
		return self::gate( false, 'pending_external_evidence', false, self::MUTATION_CONTRACT, array( 'complete_execute_replay_undo_receipt_required' ) );
	}

	private static function authoritative_mutation_receipt( $mutation_id, array $item ) {
		$record = class_exists( 'MAD4B_SCP_Mutation_Manager' ) ? MAD4B_SCP_Mutation_Manager::get( $mutation_id ) : null;
		$ticket = is_array( $record ) && ! empty( $record['approval_ticket_id'] ) && class_exists( 'MAD4B_SCP_Approval_Tickets' ) ? MAD4B_SCP_Approval_Tickets::get( $record['approval_ticket_id'] ) : null;
		$recovery_id = isset( $item['recovery_mutation_id'] ) ? (string) $item['recovery_mutation_id'] : '';
		$recovery = $recovery_id && class_exists( 'MAD4B_SCP_Mutation_Manager' ) ? MAD4B_SCP_Mutation_Manager::get( $recovery_id ) : null;
		$execution = ! empty( $item['execution_event_id'] ) ? self::audit_event( $item['execution_event_id'] ) : null;
		$replay = ! empty( $item['replay_event_id'] ) ? self::audit_event( $item['replay_event_id'] ) : null;
		$undo = ! empty( $item['undo_event_id'] ) ? self::audit_event( $item['undo_event_id'] ) : null;
		$undo_summary = is_array( $undo ) && isset( $undo['summary'] ) && is_array( $undo['summary'] ) ? $undo['summary'] : array();
		$observed_at = is_array( $undo ) && ! empty( $undo['time'] ) ? (string) $undo['time'] : '';
		return array(
			'contract' => self::MUTATION_CONTRACT,
			'candidate_sha' => isset( $item['candidate_sha'] ) ? (string) $item['candidate_sha'] : '',
			'build_fingerprint' => isset( $item['build_fingerprint'] ) ? (string) $item['build_fingerprint'] : '',
			'environment' => 'staging', 'origin' => self::STAGING_ORIGIN,
			'mutation_id' => $mutation_id,
			'approval_ticket_id' => is_array( $record ) && isset( $record['approval_ticket_id'] ) ? (string) $record['approval_ticket_id'] : '',
			'ability' => is_array( $record ) && isset( $record['ability_name'] ) ? (string) $record['ability_name'] : '',
			'provider' => is_array( $record ) && isset( $record['provider'] ) ? (string) $record['provider'] : '',
			'target_type' => is_array( $record ) && isset( $record['target_type'] ) ? (string) $record['target_type'] : '',
			'target_id' => is_array( $record ) && isset( $record['target_id'] ) ? (string) $record['target_id'] : '',
			'before_sha256' => is_array( $record ) && isset( $record['before_sha256'] ) ? (string) $record['before_sha256'] : '',
			'after_sha256' => is_array( $record ) && isset( $record['after_sha256'] ) ? (string) $record['after_sha256'] : '',
			'mutation_status' => is_array( $record ) && isset( $record['status'] ) ? (string) $record['status'] : '',
			'approval_status' => is_array( $ticket ) && isset( $ticket['status'] ) ? (string) $ticket['status'] : '',
			'approved_by' => is_array( $ticket ) && isset( $ticket['approved_by'] ) ? (int) $ticket['approved_by'] : 0,
			'approved_at' => is_array( $ticket ) && isset( $ticket['approved_at'] ) ? (string) $ticket['approved_at'] : '',
			'used_at' => is_array( $ticket ) && isset( $ticket['used_at'] ) ? (string) $ticket['used_at'] : '',
			'execution_verified' => self::acceptance_event_valid( $execution, 'mad4b/live-acceptance-execution-observed', $item, $mutation_id ),
			'execution_event_hash' => is_array( $execution ) && isset( $execution['entry_hash'] ) ? (string) $execution['entry_hash'] : '',
			'execution_sequence' => is_array( $execution ) && isset( $execution['sequence'] ) ? (int) $execution['sequence'] : 0,
			'replay_denied' => self::acceptance_event_valid( $replay, 'mad4b/live-acceptance-replay-denied-observed', $item, $mutation_id ) && isset( $replay['summary']['denial_code'] ) && 'mad4b_approval_replay_denied' === (string) $replay['summary']['denial_code'],
			'replay_event_hash' => is_array( $replay ) && isset( $replay['entry_hash'] ) ? (string) $replay['entry_hash'] : '',
			'replay_sequence' => is_array( $replay ) && isset( $replay['sequence'] ) ? (int) $replay['sequence'] : 0,
			'undo_verified' => self::acceptance_event_valid( $undo, 'mad4b/live-acceptance-undo-observed', $item, $mutation_id ) && is_array( $recovery ) && 'undone' === (string) $recovery['status'],
			'undo_event_hash' => is_array( $undo ) && isset( $undo['entry_hash'] ) ? (string) $undo['entry_hash'] : '',
			'undo_sequence' => is_array( $undo ) && isset( $undo['sequence'] ) ? (int) $undo['sequence'] : 0,
			'recovery_mutation_id' => $recovery_id,
			'restored_sha256' => isset( $undo_summary['restored_sha256'] ) ? (string) $undo_summary['restored_sha256'] : '',
			'recovery_before_sha256' => is_array( $recovery ) && isset( $recovery['before_sha256'] ) ? (string) $recovery['before_sha256'] : '',
			'recovery_after_sha256' => is_array( $recovery ) && isset( $recovery['after_sha256'] ) ? (string) $recovery['after_sha256'] : '',
			'recovery_verification_code' => is_array( $recovery ) && isset( $recovery['verification_code'] ) ? (string) $recovery['verification_code'] : '',
			'audit_chain_valid' => class_exists( 'MAD4B_SCP_Audit' ) ? true === MAD4B_SCP_Audit::verify_chain() : false,
			'observed_at' => $observed_at,
		);
	}

	private static function acceptance_event_valid( $event, $ability, array $item, $mutation_id ) {
		if ( ! is_array( $event ) || $ability !== ( isset( $event['ability'] ) ? (string) $event['ability'] : '' ) || 'ok' !== (string) $event['status'] ) return false;
		$summary = isset( $event['summary'] ) && is_array( $event['summary'] ) ? $event['summary'] : array();
		foreach ( array( 'candidate_sha', 'build_fingerprint', 'mutation_id' ) as $key ) if ( empty( $summary[ $key ] ) ) return false;
		return hash_equals( (string) $item['candidate_sha'], (string) $summary['candidate_sha'] )
			&& hash_equals( (string) $item['build_fingerprint'], (string) $summary['build_fingerprint'] )
			&& hash_equals( (string) $mutation_id, (string) $summary['mutation_id'] );
	}

	public static function evaluate_mutation_receipt( array $receipt, array $candidate, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$blockers = array();
		$state = 'invalid_evidence';
		if ( self::MUTATION_CONTRACT !== ( isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '' ) ) $blockers[] = 'invalid_evidence';
		if ( empty( $candidate['source_commit_sha'] ) || empty( $receipt['candidate_sha'] ) || ! hash_equals( (string) $candidate['source_commit_sha'], (string) $receipt['candidate_sha'] ) ) { $blockers[] = 'candidate_mismatch'; $state = 'candidate_mismatch'; }
		if ( empty( $candidate['build_fingerprint'] ) || empty( $receipt['build_fingerprint'] ) || ! hash_equals( (string) $candidate['build_fingerprint'], (string) $receipt['build_fingerprint'] ) ) { $blockers[] = 'build_fingerprint_mismatch'; if ( 'candidate_mismatch' !== $state ) $state = 'build_fingerprint_mismatch'; }
		if ( 'staging' !== ( isset( $receipt['environment'] ) ? (string) $receipt['environment'] : '' ) || self::STAGING_ORIGIN !== ( isset( $receipt['origin'] ) ? rtrim( (string) $receipt['origin'], '/' ) : '' ) ) $blockers[] = 'wrong_target';
		$observed = self::parse_time( isset( $receipt['observed_at'] ) ? $receipt['observed_at'] : '' );
		$fresh = false !== $observed && $observed <= $now + 60 && ( $now - $observed ) <= self::MUTATION_TTL;
		if ( ! $fresh ) { $blockers[] = 'stale_evidence'; $state = 'stale_evidence'; }
		foreach ( array( 'mutation_id','approval_ticket_id','ability','target_type','target_id','before_sha256','after_sha256','execution_event_hash','replay_event_hash','undo_event_hash' ) as $key ) if ( empty( $receipt[ $key ] ) ) $blockers[] = 'partial_mutation_evidence';
		if ( 'used' !== ( isset( $receipt['approval_status'] ) ? (string) $receipt['approval_status'] : '' ) || empty( $receipt['approved_by'] ) || empty( $receipt['approved_at'] ) || empty( $receipt['used_at'] ) ) $blockers[] = 'approval_unverified';
		if ( empty( $receipt['execution_verified'] ) || 'undone' !== ( isset( $receipt['mutation_status'] ) ? (string) $receipt['mutation_status'] : '' ) ) $blockers[] = 'mutation_execution_unverified';
		if ( empty( $receipt['replay_denied'] ) ) $blockers[] = 'replay_denial_unverified';
		if ( empty( $receipt['undo_verified'] ) ) $blockers[] = 'undo_unverified';
		$sequence_ok = ! empty( $receipt['execution_sequence'] ) && ! empty( $receipt['replay_sequence'] ) && ! empty( $receipt['undo_sequence'] ) && (int) $receipt['execution_sequence'] < (int) $receipt['replay_sequence'] && (int) $receipt['replay_sequence'] < (int) $receipt['undo_sequence'];
		if ( ! $sequence_ok ) $blockers[] = 'acceptance_sequence_invalid';
		$before = isset( $receipt['before_sha256'] ) ? (string) $receipt['before_sha256'] : '';
		$after = isset( $receipt['after_sha256'] ) ? (string) $receipt['after_sha256'] : '';
		$restored = isset( $receipt['restored_sha256'] ) ? (string) $receipt['restored_sha256'] : '';
		$recovery_before = isset( $receipt['recovery_before_sha256'] ) ? (string) $receipt['recovery_before_sha256'] : '';
		$recovery_after = isset( $receipt['recovery_after_sha256'] ) ? (string) $receipt['recovery_after_sha256'] : '';
		$restore_code = isset( $receipt['recovery_verification_code'] ) ? (string) $receipt['recovery_verification_code'] : '';
		$restored_ok = self::valid_hash( $before ) && self::valid_hash( $after ) && ! hash_equals( $before, $after ) && self::valid_hash( $restored ) && hash_equals( $before, $restored ) && hash_equals( $after, $recovery_before ) && hash_equals( $before, $recovery_after ) && in_array( $restore_code, array( 'restore_readback_match', 'adapter_restore_readback_match' ), true );
		if ( ! $restored_ok ) $blockers[] = 'restored_state_mismatch';
		if ( empty( $receipt['audit_chain_valid'] ) ) $blockers[] = 'audit_chain_invalid';
		$blockers = array_values( array_unique( $blockers ) );
		$ready = empty( $blockers );
		if ( $ready ) $state = 'ready';
		elseif ( 'invalid_evidence' === $state ) {
			$priority = array( 'partial_mutation_evidence','approval_unverified','mutation_execution_unverified','replay_denial_unverified','undo_unverified','restored_state_mismatch','audit_chain_invalid','wrong_target' );
			foreach ( $priority as $candidate_state ) if ( in_array( $candidate_state, $blockers, true ) ) { $state = $candidate_state; break; }
		}
		return self::gate( $ready, $state, $fresh, self::MUTATION_CONTRACT, $blockers, isset( $receipt['observed_at'] ) ? (string) $receipt['observed_at'] : '' );
	}

	public static function evaluate_production_receipt( array $receipt, array $candidate, $trusted_context, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( empty( $receipt ) ) return self::gate( false, 'pending_external_evidence', false, self::PRODUCTION_CONTRACT, array( 'trusted_external_production_receipt_required' ) );
		$blockers = array();
		$state = 'invalid_evidence';
		if ( ! $trusted_context ) { $blockers[] = 'untrusted_finalizer_context'; $state = 'untrusted_finalizer_context'; }
		if ( self::PRODUCTION_CONTRACT !== ( isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '' ) ) $blockers[] = 'invalid_evidence';
		if ( empty( $candidate['source_commit_sha'] ) || empty( $receipt['candidate_sha'] ) || ! hash_equals( (string) $candidate['source_commit_sha'], (string) $receipt['candidate_sha'] ) ) { $blockers[] = 'candidate_mismatch'; $state = 'candidate_mismatch'; }
		if ( empty( $candidate['build_fingerprint'] ) || empty( $receipt['build_fingerprint'] ) || ! hash_equals( (string) $candidate['build_fingerprint'], (string) $receipt['build_fingerprint'] ) ) { $blockers[] = 'build_fingerprint_mismatch'; if ( 'candidate_mismatch' !== $state ) $state = 'build_fingerprint_mismatch'; }
		if ( 'production' !== ( isset( $receipt['target'] ) ? (string) $receipt['target'] : '' ) || 'production' !== ( isset( $receipt['environment'] ) ? (string) $receipt['environment'] : '' ) || self::PRODUCTION_ORIGIN !== ( isset( $receipt['origin'] ) ? rtrim( (string) $receipt['origin'], '/' ) : '' ) ) $blockers[] = 'wrong_production_identity';
		if ( self::FINALIZER_ISSUER !== ( isset( $receipt['issuer'] ) ? (string) $receipt['issuer'] : '' ) || self::FINALIZER_PROVENANCE !== ( isset( $receipt['provenance'] ) ? (string) $receipt['provenance'] : '' ) ) $blockers[] = 'untrusted_receipt_provenance';
		if ( empty( $receipt['production_runtime_identity'] ) || strlen( (string) $receipt['production_runtime_identity'] ) < 8 ) $blockers[] = 'production_runtime_identity_missing';
		$checked = self::parse_time( isset( $receipt['checked_at'] ) ? $receipt['checked_at'] : '' );
		$issued = self::parse_time( isset( $receipt['issued_at'] ) ? $receipt['issued_at'] : '' );
		$fresh = false !== $checked && false !== $issued && $checked <= $now + 60 && $issued <= $now + 60 && $issued >= $checked && ( $now - $checked ) <= self::PRODUCTION_TTL && ( $issued - $checked ) <= 300;
		if ( ! $fresh ) { $blockers[] = 'stale_evidence'; $state = 'stale_evidence'; }
		$baseline = isset( $receipt['baseline_snapshot_digest'] ) ? strtolower( (string) $receipt['baseline_snapshot_digest'] ) : '';
		$observed = isset( $receipt['observed_snapshot_digest'] ) ? strtolower( (string) $receipt['observed_snapshot_digest'] ) : '';
		$plugin_baseline = isset( $receipt['baseline_plugin_snapshot_digest'] ) ? strtolower( (string) $receipt['baseline_plugin_snapshot_digest'] ) : '';
		$plugin_observed = isset( $receipt['observed_plugin_snapshot_digest'] ) ? strtolower( (string) $receipt['observed_plugin_snapshot_digest'] ) : '';
		if ( ! self::valid_hash( $baseline ) || ! self::valid_hash( $observed ) || ! hash_equals( $baseline, $observed ) ) $blockers[] = 'production_snapshot_changed';
		if ( ! self::valid_hash( $plugin_baseline ) || ! self::valid_hash( $plugin_observed ) || ! hash_equals( $plugin_baseline, $plugin_observed ) ) $blockers[] = 'production_plugin_snapshot_changed';
		$provided_digest = isset( $receipt['evidence_digest'] ) ? strtolower( (string) $receipt['evidence_digest'] ) : '';
		$payload = $receipt; unset( $payload['evidence_digest'] );
		$computed_digest = hash( 'sha256', self::canonical_json( $payload ) );
		if ( ! self::valid_hash( $provided_digest ) || ! hash_equals( $computed_digest, $provided_digest ) ) $blockers[] = 'evidence_digest_mismatch';
		$blockers = array_values( array_unique( $blockers ) );
		$ready = empty( $blockers );
		if ( $ready ) $state = 'ready';
		elseif ( 'invalid_evidence' === $state ) $state = $blockers ? $blockers[0] : 'invalid_evidence';
		return self::gate( $ready, $state, $fresh, self::PRODUCTION_CONTRACT, $blockers, isset( $receipt['checked_at'] ) ? (string) $receipt['checked_at'] : '' );
	}

	public static function production_receipt_digest( array $receipt ) {
		unset( $receipt['evidence_digest'] );
		return hash( 'sha256', self::canonical_json( $receipt ) );
	}

	private static function trusted_external_finalizer_context() {
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return false;
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : null;
		if ( is_wp_error( $identity ) || ! is_array( $identity ) || empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return false;
		$scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? $identity['token_scopes'] : array();
		if ( ! in_array( 'mad4b:read', $scopes, true ) ) return false;
		$external = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::external_handshake_attestation_status() : array();
		return ! empty( $external['verified'] ) && ( empty( $external['client_id'] ) || 'https://chatgpt.com/oauth/client.json' === (string) $external['client_id'] );
	}

	public static function observe_wpml_response( $response, $server, $request ) {
		if ( ! self::staging_allowed() || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return $response;
		$route = '/' . ltrim( rtrim( (string) $request->get_route(), '/' ), '/' );
		if ( '/wpml/v1/rest/status' !== $route ) return $response;
		$status_code = 0; $data = null; $error_code = ''; $safe_message = ''; $content_type = '';
		if ( is_wp_error( $response ) ) {
			$error_code = sanitize_key( (string) $response->get_error_code() );
			$safe_message = self::safe_message( $response->get_error_message() );
			$error_data = $response->get_error_data();
			$status_code = is_array( $error_data ) && isset( $error_data['status'] ) ? absint( $error_data['status'] ) : 500;
		} else {
			$rest = rest_ensure_response( $response );
			if ( $rest instanceof WP_REST_Response ) {
				$status_code = (int) $rest->get_status();
				$data = $rest->get_data();
				$headers = $rest->get_headers();
				foreach ( $headers as $name => $value ) if ( 'content-type' === strtolower( (string) $name ) ) { $content_type = is_array( $value ) ? (string) reset( $value ) : (string) $value; break; }
				if ( is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ) $error_code = sanitize_key( $data['code'] );
				if ( is_array( $data ) && isset( $data['message'] ) ) $safe_message = self::safe_message( $data['message'] );
			}
		}
		$classification = self::classify_wpml_response( $status_code, $data, $error_code );
		$candidate = self::current_candidate();
		$receipt = array(
			'contract' => self::WPML_DIAGNOSTIC_CONTRACT,
			'observed_at' => gmdate( 'c' ),
			'build_fingerprint' => isset( $candidate['build_fingerprint'] ) ? $candidate['build_fingerprint'] : '',
			'request_route' => '/wpml/v1/rest/status',
			'response_status' => $status_code,
			'response_content_type' => substr( sanitize_text_field( $content_type ), 0, 120 ),
			'error_code' => $error_code,
			'body_classification' => $classification['body_classification'],
			'classification' => $classification['classification'],
			'safe_message' => $safe_message,
		);
		update_option( self::WPML_DIAGNOSTIC_OPTION, $receipt, false );
		return $response;
	}

	public static function classify_wpml_response( $status_code, $data, $error_code = '', $route_registered = null ) {
		$status_code = (int) $status_code;
		$error_code = sanitize_key( (string) $error_code );
		$body_classification = is_array( $data ) ? 'json_object' : ( null === $data ? 'empty_or_error' : 'non_json' );
		if ( false === $route_registered ) return array( 'classification' => 'route_not_registered', 'body_classification' => $body_classification );
		if ( 'rest_no_route' === $error_code ) return array( 'classification' => 'rest_no_route', 'body_classification' => $body_classification );
		if ( '' !== $error_code ) return array( 'classification' => 'wp_error', 'body_classification' => $body_classification );
		if ( $status_code >= 300 && $status_code < 400 ) return array( 'classification' => 'redirect_response', 'body_classification' => $body_classification );
		if ( ! is_array( $data ) ) return array( 'classification' => 'non_json_response', 'body_classification' => $body_classification );
		if ( $status_code < 200 || $status_code >= 300 ) return array( 'classification' => 'unexpected_status', 'body_classification' => $body_classification );
		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : '';
		$params = isset( $data['get_parameters'] ) ? sanitize_key( (string) $data['get_parameters'] ) : '';
		if ( 'valid' === $status && 'valid' === $params ) return array( 'classification' => 'success', 'body_classification' => $body_classification );
		return array( 'classification' => 'contract_mismatch', 'body_classification' => $body_classification );
	}

	public static function external_wpml_receipt_status() {
		$base = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::external_wpml_receipt_status() : array();
		$diag = self::staging_allowed() ? get_option( self::WPML_DIAGNOSTIC_OPTION, array() ) : array();
		$rest = class_exists( 'MAD4B_SCP_REST_Compatibility' ) ? MAD4B_SCP_REST_Compatibility::status() : array();
		$route_registered = isset( $rest['wpml']['route_registered'] ) ? (bool) $rest['wpml']['route_registered'] : null;
		$current = self::current_candidate();
		if ( ! is_array( $diag ) ) $diag = array();
		$classification = isset( $diag['classification'] ) ? (string) $diag['classification'] : 'pending_external_evidence';
		if ( false === $route_registered ) $classification = 'route_not_registered';
		$build_match = ! empty( $diag['build_fingerprint'] ) && ! empty( $current['build_fingerprint'] ) && hash_equals( (string) $current['build_fingerprint'], (string) $diag['build_fingerprint'] );
		$verified = ! empty( $base['verified'] ) && $build_match && 'success' === $classification;
		$out = is_array( $base ) ? $base : array();
		$out['contract'] = self::WPML_DIAGNOSTIC_CONTRACT;
		$out['verified'] = $verified;
		$out['stale'] = ! $build_match;
		$out['classification'] = $classification;
		$out['state'] = $verified ? 'verified_external_wpml' : ( ! $build_match && ! empty( $diag ) ? 'stale_build_evidence' : $classification );
		foreach ( array( 'response_status','response_content_type','error_code','body_classification','safe_message','observed_at' ) as $key ) if ( array_key_exists( $key, $diag ) ) $out[ $key ] = $diag[ $key ];
		$out['route_registered'] = $route_registered;
		return $out;
	}

	private static function current_candidate() {
		$provenance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
		$sha = isset( $provenance['source_commit_sha'] ) ? strtolower( (string) $provenance['source_commit_sha'] ) : '';
		$fingerprint = isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
		$ready = ! empty( $provenance['runtime_manifest_match'] ) && preg_match( '/^[a-f0-9]{40}$/', $sha ) && self::valid_hash( $fingerprint );
		return array( 'ready' => (bool) $ready, 'source_commit_sha' => $sha, 'build_fingerprint' => $fingerprint );
	}

	private static function staging_allowed() {
		return class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) && MAD4B_SCP_Live_Acceptance_Observer::staging_capture_allowed();
	}

	private static function ledger() {
		$ledger = self::staging_allowed() ? get_option( self::LEDGER_OPTION, array() ) : array();
		return is_array( $ledger ) ? $ledger : array();
	}

	private static function save_ledger( array $ledger ) {
		if ( ! self::staging_allowed() ) return;
		while ( count( $ledger ) > self::MAX_LEDGER_ENTRIES ) array_shift( $ledger );
		update_option( self::LEDGER_OPTION, $ledger, false );
	}

	private static function audit_event( $event_id ) {
		global $wpdb;
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! class_exists( 'MAD4B_SCP_Audit_Integrity' ) ) return null;
		$t = MAD4B_SCP_Schema::tables();
		if ( empty( $t['audit_events'] ) ) return null;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['audit_events']} WHERE chain_name = %s AND event_id = %s LIMIT 1", MAD4B_SCP_Audit::CHAIN, (string) $event_id ), ARRAY_A );
		return is_array( $row ) ? MAD4B_SCP_Audit_Integrity::row_to_entry( $row ) : null;
	}

	private static function gate( $ready, $state, $fresh, $contract, array $blockers, $observed_at = '' ) {
		return array(
			'state' => (string) $state,
			'ready' => (bool) $ready,
			'fresh' => (bool) $fresh,
			'source_contract' => (string) $contract,
			'blockers' => array_values( array_unique( array_filter( array_map( 'strval', $blockers ) ) ) ),
			'observed_at' => '' !== (string) $observed_at ? (string) $observed_at : gmdate( 'c' ),
		);
	}

	private static function parse_time( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) return false;
		$ts = strtotime( $value );
		return false === $ts ? false : $ts;
	}

	private static function valid_hash( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', strtolower( $value ) ); }

	private static function safe_message( $message ) {
		$message = preg_replace( '/[\r\n\t]+/', ' ', (string) $message );
		$message = preg_replace( '/\bBearer\s+[^\s]+/i', 'Bearer [redacted]', $message );
		$message = preg_replace( '/\b(authorization|cookie|password|token|secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $message );
		$message = trim( (string) $message );
		return strlen( $message ) > 240 ? substr( $message, 0, 240 ) : $message;
	}

	private static function canonical_json( $value ) {
		$normalized = self::canonicalize( $value );
		$json = json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) return array_map( array( __CLASS__, 'canonicalize' ), $value );
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}
