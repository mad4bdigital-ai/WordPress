<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-agnostic durable envelope for explicitly certified reversible adapter writes.
 *
 * Adapters contribute named contracts plus bounded target/state capture and provider-safe
 * restore methods. No executable callback, class name or arbitrary code is persisted in DB.
 */
final class MAD4B_SCP_Reversible_Adapter_Mutations {
	const ROLLBACK_CONTRACT = 'mad4b.rollback.adapter.v1';
	const FAILURE_EVIDENCE_CONTRACT = 'mad4b.mutation-failure-evidence.v1';
	const DEFAULT_UNDO_TTL = 259200;
	const MAX_UNDO_TTL = 604800;
	const MAX_ROLLBACK_BYTES = 262144;
	private static $execution_context_stack = array();

	public static function current_mutation_context() {
		if ( empty( self::$execution_context_stack ) ) return array();
		$current = end( self::$execution_context_stack );
		return is_array( $current ) ? $current : array();
	}

	public static function execute( $adapter, $ability_name, $method, $input ) {
		global $wpdb;
		if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base ) return new WP_Error( 'mad4b_reversible_adapter_invalid', 'Reversible adapter execution requires a registered MAD4B adapter.' );
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_reversible_input_invalid', 'Reversible adapter mutation input must be an object.' );
		$ability_name = (string) $ability_name;
		$contract = $adapter->reversible_contract_for( $ability_name );
		if ( '' === $contract ) return new WP_Error( 'mad4b_reversible_contract_missing', 'Adapter write has no certified reversible contract.' );
		$callable = is_string( $method ) ? array( $adapter, $method ) : $method;
		if ( ! is_callable( $callable ) ) return new WP_Error( 'mad4b_reversible_execute_missing', 'Adapter mutation implementation is unavailable.' );


		$scope = null;
		$journal_scope_uncertain = false;
		if ( method_exists( $adapter, 'acquire_reversible_scope' ) && method_exists( $adapter, 'release_reversible_scope' ) ) {
			$scope = $adapter->acquire_reversible_scope( $ability_name, $input );
			if ( is_wp_error( $scope ) ) return $scope;
		}
		try {
		$before = $adapter->capture_reversible_state( $ability_name, $input );
		if ( is_wp_error( $before ) ) return $before;
		$validated = self::validate_snapshot( $before );
		if ( is_wp_error( $validated ) ) return $validated;
		$before = $validated;

		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;

		$server_id = class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::current_server_id() : '';
		if ( '' === $server_id ) $server_id = $adapter->declared_server_for_ability( $ability_name );
		if ( '' === $server_id ) return new WP_Error( 'mad4b_reversible_server_unknown', 'Unable to resolve the governed server for reversible mutation evidence.' );
		$provider = $adapter->provider_key();
		$provider = '' !== $provider ? $provider : $adapter->id();
		$status = $adapter->status();
		$provider_version = isset( $status['version'] ) ? (string) $status['version'] : '';
		$rollback = array(
			'contract' => self::ROLLBACK_CONTRACT,
			'adapter_id' => $adapter->id(),
			'ability' => $ability_name,
			'restore_contract' => $contract,
			'target' => $before['target'],
			'state' => $before['state'],
		);
		$rollback_json = wp_json_encode( $rollback, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $rollback_json ) return new WP_Error( 'mad4b_reversible_rollback_encode_failed', 'Adapter rollback state could not be encoded.' );
		if ( strlen( $rollback_json ) > self::MAX_ROLLBACK_BYTES ) return new WP_Error( 'mad4b_reversible_rollback_too_large', 'Adapter rollback state exceeds the certified in-database limit.' );

		$mutation_id = wp_generate_uuid4();
		$request_id = isset( $identity['request_id'] ) ? (string) $identity['request_id'] : MAD4B_SCP_Identity_Context::request_id();
		$before_hash = self::state_hash( $before['state'] );
		$ttl = (int) apply_filters( 'mad4b_scp_adapter_undo_ttl', self::DEFAULT_UNDO_TTL, $adapter->id(), $ability_name, $before['target'], $agent, $input );
		$ttl = max( 60, min( self::MAX_UNDO_TTL, $ttl ) );
		$impact = MAD4B_SCP_Impact_Policy::impact_for( $ability_name, $provider, $input );
		$approval_ticket_id = isset( $identity['approval_ticket_id'] ) && '' !== $identity['approval_ticket_id'] ? $identity['approval_ticket_id'] : null;
		$now = gmdate( 'Y-m-d H:i:s' );
		$t = MAD4B_SCP_Schema::tables();
		$inserted = $wpdb->insert( $t['mutations'], array(
			'mutation_id' => $mutation_id,
			'request_id' => $request_id,
			'parent_mutation_id' => null,
			'agent_id' => (int) $agent['id'],
			'subject_type' => (string) $identity['subject_type'],
			'subject_fingerprint' => (string) $identity['subject_fingerprint'],
			'wp_user_id' => get_current_user_id(),
			'server_id' => $server_id,
			'ability_name' => $ability_name,
			'provider' => $provider,
			'provider_version' => $provider_version,
			'target_type' => (string) $before['target_type'],
			'target_id' => (string) $before['target_id'],
			'approval_ticket_id' => $approval_ticket_id,
			'impact' => $impact,
			'status' => 'planned',
			'reversible' => 1,
			'before_sha256' => $before_hash,
			'after_sha256' => '',
			'rollback_payload' => $rollback_json,
			'rollback_payload_sha256' => hash( 'sha256', $rollback_json ),
			'undo_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
			'verification_code' => '',
			'error_code' => '',
			'created_at' => $now,
			'updated_at' => $now,
		), array( '%s','%s','%s','%d','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $inserted ) return new WP_Error( 'mad4b_mutation_record_failed', 'Unable to persist reversible adapter mutation evidence before provider execution.', array( 'db_error' => $wpdb->last_error ) );
		if ( 1 !== self::update_record( $mutation_id, array( 'status' => 'executing' ) ) ) {
			return new WP_Error( 'mad4b_mutation_execution_journal_failed', 'Journal failed before provider execution; do not retry before reconciliation.', array( 'mutation_id' => $mutation_id, 'recovery_required' => true, 'blind_retry_allowed' => false ) );
		}

		self::$execution_context_stack[] = array(
			'contract' => 'mad4b.reversible-execution-context.v1',
			'mutation_id' => $mutation_id,
			'ability' => $ability_name,
			'adapter_id' => $adapter->id(),
			'provider' => $provider,
			'target_type' => (string) $before['target_type'],
			'target_id' => (string) $before['target_id'],
			'before_sha256' => $before_hash,
			'undo_expires_at' => gmdate( 'c', time() + $ttl ),
		);
		try {
			$result = call_user_func( $callable, $input );
		} catch ( Throwable $e ) {
			array_pop( self::$execution_context_stack );
			$error = new WP_Error( 'mad4b_reversible_provider_exception', 'Adapter mutation threw before successful verification.' );
			$failure = self::finalize_provider_failure( $adapter, $ability_name, $provider, $mutation_id, $before, $before_hash, $error, 'provider_exception' );
			MAD4B_SCP_Audit::record( 'mad4b/reversible-adapter-failed', array( 'mutation_id' => $mutation_id, 'ability' => $ability_name, 'adapter' => $adapter->id(), 'error_type' => get_class( $e ), 'mutation_status' => self::failure_status_from_error( $failure ) ), 'failure' );
			if ( is_wp_error( $failure ) && 'mad4b_mutation_journal_recovery_required' === $failure->get_error_code() ) $journal_scope_uncertain = true;
			return $failure;
		}
		array_pop( self::$execution_context_stack );
		if ( is_wp_error( $result ) ) {
			$failure = self::finalize_provider_failure( $adapter, $ability_name, $provider, $mutation_id, $before, $before_hash, $result, $result->get_error_code() );
			if ( is_wp_error( $failure ) && 'mad4b_mutation_journal_recovery_required' === $failure->get_error_code() ) $journal_scope_uncertain = true;
			return $failure;
		}

		$readback_call = static function() use ( $adapter, $ability_name, $before ) {
			return $adapter->read_reversible_state( $ability_name, $before['target'] );
		};
		$after_state = class_exists( 'MAD4B_SCP_Observability' )
			? MAD4B_SCP_Observability::run_stage( 'readback', $readback_call, '', array(
				'ability'=>(string)$ability_name,
				'provider_id'=>sanitize_key((string)$provider),
				'target_sha256'=>hash('sha256',(string)$before['target_id']),
			) )
			: $readback_call();
		if ( is_wp_error( $after_state ) ) {
			if ( 1 !== self::update_record( $mutation_id, array( 'status' => 'verification_failed', 'error_code' => $after_state->get_error_code(), 'verification_code' => 'readback_failed' ) ) ) { $journal_scope_uncertain = true; return self::journal_recovery_error( $mutation_id, 'Provider readback could not be finalized.' ); }
			$error = new WP_Error( 'mad4b_reversible_readback_failed', 'Provider write completed but reversible readback failed.', array( 'provider_error' => $after_state->get_error_code() ) );
			return self::attach_failure_evidence( $error, self::failure_evidence( $mutation_id, $ability_name, $provider, $before, $before_hash, '', 'verification_failed', false ) );
		}
		if ( ! is_array( $after_state ) ) {
			if ( 1 !== self::update_record( $mutation_id, array( 'status' => 'verification_failed', 'error_code' => 'invalid_readback_state', 'verification_code' => 'readback_failed' ) ) ) { $journal_scope_uncertain = true; return self::journal_recovery_error( $mutation_id, 'Provider readback could not be finalized.' ); }
			$error = new WP_Error( 'mad4b_reversible_readback_invalid', 'Provider reversible readback did not return a bounded state object.' );
			return self::attach_failure_evidence( $error, self::failure_evidence( $mutation_id, $ability_name, $provider, $before, $before_hash, '', 'verification_failed', false ) );
		}
		$after_hash = self::state_hash( $after_state );
		if ( 1 !== self::update_record( $mutation_id, array( 'status' => 'verified', 'after_sha256' => $after_hash, 'verification_code' => 'provider_readback_recorded', 'error_code' => '' ) ) ) {
			$journal_scope_uncertain = true;
			MAD4B_SCP_Audit::record( 'mad4b/reversible-adapter-journal-recovery', array( 'mutation_id' => $mutation_id, 'ability' => $ability_name, 'adapter' => $adapter->id(), 'after_sha256' => $after_hash ), 'failure' );
			return new WP_Error( 'mad4b_mutation_journal_recovery_required', 'Provider write was observed, but its verification journal could not be persisted. Manual reconciliation required; blind retry denied.', array( 'mutation_id' => $mutation_id, 'observed_after_sha256' => $after_hash, 'recovery_required' => true, 'blind_retry_allowed' => false ) );
		}
		MAD4B_SCP_Audit::record( 'mad4b/reversible-adapter-verified', array( 'mutation_id' => $mutation_id, 'ability' => $ability_name, 'adapter' => $adapter->id(), 'provider' => $provider, 'target_type' => $before['target_type'], 'target_id' => $before['target_id'], 'before_sha256' => $before_hash, 'after_sha256' => $after_hash ) );
		$record = MAD4B_SCP_Mutation_Manager::get( $mutation_id );
		$evidence = array(
			'mutation_id' => $mutation_id,
			'before_sha256' => $before_hash,
			'after_sha256' => $after_hash,
			'verified' => true,
			'reversible' => true,
			'restore_contract' => $contract,
			'undo_expires_at' => $record ? $record['undo_expires_at'] : '',
		);
		if ( is_array( $result ) ) return array_merge( $result, $evidence );
		return array_merge( array( 'result' => $result ), $evidence );
		} finally {
			// Provider side effects with an uncommitted journal must remain fenced.
			if ( $journal_scope_uncertain && null !== $scope && method_exists( $adapter, 'retain_reversible_scope' ) ) {
				$adapter->retain_reversible_scope( $scope );
			}
			if ( null !== $scope ) $adapter->release_reversible_scope( $scope );
		}
	}

	public static function can_undo_record( array $record ) {
		if ( empty( $record['reversible'] ) ) return false;
		$status = isset( $record['status'] ) ? (string) $record['status'] : '';
		$verified = 'verified' === $status;
		$recoverable_failure = 'verification_failed' === $status && ! empty( $record['after_sha256'] );
		if ( ! $verified && ! $recoverable_failure ) return false;
		$payload = self::decode_payload( $record );
		return ! is_wp_error( $payload );
	}

	public static function undo( $mutation_id ) {
		global $wpdb;
		$record = MAD4B_SCP_Mutation_Manager::get( $mutation_id );
		if ( ! $record ) return new WP_Error( 'mad4b_mutation_missing', 'Mutation record was not found.' );
		if ( ! self::can_undo_record( $record ) ) return new WP_Error( 'mad4b_undo_not_reversible', 'Mutation is not a verified or safely recoverable reversible operation.' );
		if ( empty( $record['undo_expires_at'] ) || strtotime( $record['undo_expires_at'] . ' UTC' ) < time() ) return new WP_Error( 'mad4b_undo_expired', 'Undo window has expired.' );
		$payload = self::decode_payload( $record );
		if ( is_wp_error( $payload ) ) return $payload;
		$adapter = MAD4B_SCP_Adapter_Registry::instance()->get( $payload['adapter_id'] );
		if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base ) return new WP_Error( 'mad4b_undo_adapter_missing', 'The adapter required to restore this mutation is not registered.' );
		$expected_contract = $adapter->reversible_contract_for( $record['ability_name'] );
		if ( '' === $expected_contract || ! hash_equals( (string) $payload['restore_contract'], $expected_contract ) ) return new WP_Error( 'mad4b_undo_contract_drift', 'The registered adapter restore contract no longer matches the recorded mutation.' );
		$provider_guard = self::provider_restore_guard( $adapter, $record );
		if ( is_wp_error( $provider_guard ) ) return $provider_guard;

		if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'Mutation/NHI authority is required for undo.' );
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;
		if ( (int) $agent['id'] !== (int) $record['agent_id'] && ! apply_filters( 'mad4b_scp_allow_cross_agent_undo', false, $record, $agent ) ) return new WP_Error( 'mad4b_undo_agent_denied', 'A different agent cannot undo this mutation by default.' );

		$undo_scope = null;
		$undo_intent_incomplete = false;
		if ( method_exists( $adapter, 'acquire_reversible_undo_scope' ) && method_exists( $adapter, 'release_reversible_scope' ) ) {
			$undo_scope = $adapter->acquire_reversible_undo_scope( $record['ability_name'], $payload['target'] );
			if ( is_wp_error( $undo_scope ) ) return $undo_scope;
		}
		try {
		$fresh = MAD4B_SCP_Mutation_Manager::get( $mutation_id );
		if ( ! $fresh || ! self::can_undo_record( $fresh ) ) return new WP_Error( 'mad4b_undo_journal_changed', 'Undo journal changed before provider lock was acquired.' );
		$record = $fresh;
		$current = $adapter->read_reversible_state( $record['ability_name'], $payload['target'] );
		if ( is_wp_error( $current ) ) return $current;
		$current_hash = self::state_hash( $current );
		if ( empty( $record['after_sha256'] ) || ! hash_equals( (string) $record['after_sha256'], $current_hash ) ) return new WP_Error( 'mad4b_undo_state_drift', 'Provider state changed after the recorded mutation; automatic undo refuses to overwrite newer work.', array( 'current_sha256' => $current_hash ) );


		// Claim the original journal row BEFORE any provider side effect.
		// A CAS transition is the durable undo intent and prevents a second
		// Admin/MCP request from repeating restoration after interruption.
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$recovery_id = wp_generate_uuid4();
		$undo_approval_ticket_id = isset( $identity['approval_ticket_id'] ) && '' !== $identity['approval_ticket_id'] ? $identity['approval_ticket_id'] : null;
		$claimed = $wpdb->update( $t['mutations'],
			array( 'status' => 'undo_pending', 'verification_code' => 'undo_intent_claimed', 'updated_at' => $now ),
			array( 'mutation_id' => (string) $mutation_id, 'status' => (string) $record['status'] ),
			array( '%s', '%s', '%s' ), array( '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 === $claimed ) $undo_intent_incomplete = true;
		if ( 1 !== $claimed ) return new WP_Error(
			'mad4b_undo_intent_claim_failed',
			'Undo intent could not be claimed atomically; no restore was attempted.',
			array( 'mutation_id' => $mutation_id, 'recovery_required' => true, 'blind_retry_allowed' => false )
		);

		// A separate child journal records the exact parent, actor and observed
		// after-state BEFORE restoration. If this insert fails, the parent stays
		// undo_pending: reconciliation, never an untracked retry.
		$inserted = $wpdb->insert( $t['mutations'], array(
			'mutation_id' => $recovery_id,
			'request_id' => isset( $identity['request_id'] ) ? $identity['request_id'] : MAD4B_SCP_Identity_Context::request_id(),
			'parent_mutation_id' => $mutation_id,
			'agent_id' => (int) $agent['id'],
			'subject_type' => (string) $identity['subject_type'],
			'subject_fingerprint' => (string) $identity['subject_fingerprint'],
			'wp_user_id' => get_current_user_id(),
			'server_id' => 'mad4b-admin',
			'ability_name' => 'mad4b/mutation-undo',
			'provider' => (string) $record['provider'],
			'provider_version' => (string) $record['provider_version'],
			'target_type' => (string) $record['target_type'],
			'target_id' => (string) $record['target_id'],
			'approval_ticket_id' => $undo_approval_ticket_id,
			'impact' => 'high',
			'status' => 'planned',
			'reversible' => 0,
			'before_sha256' => $current_hash,
			'after_sha256' => '',
			'rollback_payload' => null,
			'rollback_payload_sha256' => '',
			'undo_expires_at' => null,
			'verification_code' => 'undo_intent_reserved',
			'error_code' => '',
			'created_at' => $now,
			'updated_at' => $now,
		), array( '%s','%s','%s','%d','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 !== $inserted ) return self::undo_recovery_required( $mutation_id, $recovery_id, 'Undo child intent could not be persisted before restore.' );
		if ( 1 !== self::update_record( $recovery_id, array( 'status' => 'executing', 'verification_code' => 'undo_restore_started' ) ) ) {
			return self::undo_recovery_required( $mutation_id, $recovery_id, 'Undo child intent could not enter executing state.' );
		}

		try {
			$restored = $adapter->restore_reversible_state( $record['ability_name'], $payload['target'], $payload['state'], $record );
		} catch ( Throwable $e ) {
			return self::undo_recovery_required( $mutation_id, $recovery_id, 'Provider restore threw; state is uncertain and must be reconciled.' );
		}
		if ( is_wp_error( $restored ) ) {
			return self::undo_recovery_required( $mutation_id, $recovery_id, 'Provider restore reported failure; any side effect requires reconciliation.' );
		}
		$readback = $adapter->read_reversible_state( $record['ability_name'], $payload['target'] );
		if ( is_wp_error( $readback ) || ! is_array( $readback ) ) {
			return self::undo_recovery_required( $mutation_id, $recovery_id, 'Post-restore provider readback failed.' );
		}
		$restored_hash = self::state_hash( $readback );
		if ( empty( $record['before_sha256'] ) || ! hash_equals( (string) $record['before_sha256'], $restored_hash ) ) {
			return self::undo_recovery_required( $mutation_id, $recovery_id, 'Restore readback does not match the recorded before-state.', 'mad4b_undo_verification_failed' );
		}

		if ( 1 !== self::update_record( $recovery_id, array(
			'status' => 'undone', 'after_sha256' => $restored_hash,
			'verification_code' => 'adapter_restore_readback_match', 'error_code' => ''
		) ) ) return self::undo_recovery_required( $mutation_id, $recovery_id, 'Undo readback could not be committed to its child journal.' );
		$original_done = $wpdb->update( $t['mutations'],
			array( 'status' => 'undone', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'mutation_id' => (string) $mutation_id, 'status' => 'undo_pending' ),
			array( '%s', '%s' ), array( '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 !== $original_done ) return self::undo_recovery_required( $mutation_id, $recovery_id, 'Undo child was verified but the original mutation could not be finalized.' );
		$undo_intent_incomplete = false;
		MAD4B_SCP_Audit::record( 'mad4b/mutation-undo', array( 'mutation_id' => $mutation_id, 'recovery_mutation_id' => $recovery_id, 'provider' => $record['provider'], 'target_type' => $record['target_type'], 'target_id' => $record['target_id'], 'restored_sha256' => $restored_hash, 'approval_ticket_id' => $undo_approval_ticket_id ) );
		return array( 'status' => 'undone', 'original_mutation_id' => $mutation_id, 'recovery_mutation_id' => $recovery_id, 'restored_sha256' => $restored_hash, 'verified' => true, 'restore_contract' => $payload['restore_contract'] );
		} finally {
			if ( $undo_intent_incomplete && null !== $undo_scope && method_exists( $adapter, 'retain_reversible_scope' ) ) {
				$adapter->retain_reversible_scope( $undo_scope );
			}
			if ( null !== $undo_scope ) $adapter->release_reversible_scope( $undo_scope );
		}
	}

	private static function finalize_provider_failure( $adapter, $ability_name, $provider, $mutation_id, array $before, $before_hash, $error, $error_code ) {
		$declared_not_started = self::provider_declared_not_started( $error );
		$reconcile_call = static function() use ( $adapter, $ability_name, $before ) {
			return $adapter->read_reversible_state( $ability_name, $before['target'] );
		};
		$observed = class_exists( 'MAD4B_SCP_Observability' )
			? MAD4B_SCP_Observability::run_stage( 'reconciliation', $reconcile_call, '', array(
				'ability'=>(string)$ability_name,
				'provider_id'=>sanitize_key((string)$provider),
				'target_sha256'=>hash('sha256',(string)$before['target_id']),
				'failure_code'=>sanitize_key((string)$error_code),
			) )
			: $reconcile_call();
		$after_hash = is_array( $observed ) ? self::state_hash( $observed ) : '';
		$declared_not_started_verified = $declared_not_started
			&& is_array( $observed )
			&& hash_equals( (string) $before_hash, (string) $after_hash );
		if ( $declared_not_started_verified ) {
			if ( 1 !== self::update_record( $mutation_id, array(
				'status' => 'failed',
				'after_sha256' => $after_hash,
				'error_code' => (string) $error_code,
				'verification_code' => 'provider_declared_not_started_verified',
			) ) )  return self::journal_recovery_error( $mutation_id, 'Provider failure evidence could not be finalized.' );
			$evidence = self::failure_evidence( $mutation_id, $ability_name, $provider, $before, $before_hash, $after_hash, 'failed', false );
			return self::attach_failure_evidence( $error, $evidence );
		}
		$changed = false;
		if ( is_array( $observed ) ) {
			$changed = ! hash_equals( (string) $before_hash, (string) $after_hash );
		}

		if ( $changed ) {
			$mutation_status = 'verification_failed';
			if ( 1 !== self::update_record( $mutation_id, array(
				'status' => $mutation_status,
				'after_sha256' => $after_hash,
				'error_code' => (string) $error_code,
				'verification_code' => 'provider_error_after_side_effect',
			) ) )  return self::journal_recovery_error( $mutation_id, 'Provider failure evidence could not be finalized.' );
			$restore_available = true;
		} else {
			$mutation_status = is_wp_error( $observed ) ? 'verification_failed' : 'failed';
			$verification_code = is_wp_error( $observed )
				? ( $declared_not_started ? 'provider_declared_not_started_readback_unverified' : 'provider_error_readback_failed' )
				: 'provider_error_no_observed_side_effect';
			if ( 1 !== self::update_record( $mutation_id, array(
				'status' => $mutation_status,
				'after_sha256' => $after_hash,
				'error_code' => (string) $error_code,
				'verification_code' => $verification_code,
			) ) )  return self::journal_recovery_error( $mutation_id, 'Provider failure evidence could not be finalized.' );
			$restore_available = $declared_not_started && is_wp_error( $observed );
		}

		$evidence = self::failure_evidence( $mutation_id, $ability_name, $provider, $before, $before_hash, $after_hash, $mutation_status, $restore_available );
		return self::attach_failure_evidence( $error, $evidence );
	}

	private static function failure_evidence( $mutation_id, $ability_name, $provider, array $before, $before_hash, $after_hash, $mutation_status, $restore_available ) {
		$postcondition_profile = class_exists( 'MAD4B_SCP_Provider_Postcondition_Profile' ) ? MAD4B_SCP_Provider_Postcondition_Profile::profile( $ability_name ) : array();
		$postcondition_observation = is_array( $postcondition_profile )
			? MAD4B_SCP_Provider_Postcondition_Profile::decision_from_hashes( $postcondition_profile, $before_hash, '', $after_hash, time() )
			: array();
		$postcondition_recovery = is_array( $postcondition_observation ) && class_exists( 'MAD4B_SCP_Provider_Postcondition_Profile' )
			? MAD4B_SCP_Provider_Postcondition_Profile::recovery_decision( $postcondition_observation )
			: array( 'retry_reclaim_eligible' => false, 'blind_retry_allowed' => false, 'reconciliation_required' => true, 'authorizing' => false );
		return array(
			'contract' => self::FAILURE_EVIDENCE_CONTRACT,
			'mutation_id' => (string) $mutation_id,
			'ability' => (string) $ability_name,
			'provider' => sanitize_key( (string) $provider ),
			'target' => array(
				'type' => isset( $before['target_type'] ) ? (string) $before['target_type'] : '',
				'id' => isset( $before['target_id'] ) ? (string) $before['target_id'] : '',
			),
			'before_sha256' => (string) $before_hash,
			'observed_after_sha256' => (string) $after_hash,
			'mutation_status' => (string) $mutation_status,
			'restore_available' => (bool) $restore_available,
			'postcondition_profile' => $postcondition_profile,
			'postcondition_observation' => $postcondition_observation,
			'postcondition_recovery' => $postcondition_recovery,
			'blind_retry_allowed' => false,
		);
	}

	private static function attach_failure_evidence( $error, array $evidence ) {
		if ( ! is_wp_error( $error ) ) $error = new WP_Error( 'mad4b_reversible_provider_failed', 'Adapter mutation failed.' );
		$code = $error->get_error_code();
		$data = $error->get_error_data( $code );
		if ( ! is_array( $data ) ) $data = array();
		$data['mad4b_mutation_evidence'] = $evidence;
		$error->add_data( $data, $code );
		return $error;
	}

	private static function provider_declared_not_started( $error ) {
		if ( ! is_wp_error( $error ) ) return false;
		$code = $error->get_error_code();
		$data = $error->get_error_data( $code );
		if ( ! is_array( $data ) || ! isset( $data['mad4b_execution_state'] ) || ! is_array( $data['mad4b_execution_state'] ) ) return false;
		$state = $data['mad4b_execution_state'];
		return isset( $state['contract'], $state['started'] )
			&& 'mad4b.execution-state.v1' === (string) $state['contract']
			&& false === $state['started'];
	}

	private static function failure_status_from_error( $error ) {
		if ( ! is_wp_error( $error ) ) return 'failed';
		$data = $error->get_error_data( $error->get_error_code() );
		if ( is_array( $data ) && isset( $data['mad4b_mutation_evidence']['mutation_status'] ) ) return (string) $data['mad4b_mutation_evidence']['mutation_status'];
		return 'failed';
	}

	private static function provider_restore_guard( $adapter, array $record ) {
		$status = $adapter->status();
		if ( empty( $status['mutation_requires_certification'] ) ) return true;
		if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) return new WP_Error( 'mad4b_provider_contracts_unavailable', 'Adapter undo is denied because the provider certification authority is unavailable.' );
		$provider = $adapter->provider_key();
		if ( '' === $provider || ! hash_equals( (string) $record['provider'], $provider ) ) return new WP_Error( 'mad4b_undo_provider_mismatch', 'Recorded provider no longer matches the registered adapter provider contract.' );
		$guard = MAD4B_SCP_Provider_Contracts::mutation_guard( $provider, (bool) $adapter->is_available() );
		if ( is_wp_error( $guard ) || true !== $guard ) return is_wp_error( $guard ) ? $guard : new WP_Error( 'mad4b_provider_mutation_not_certified', 'Adapter undo is denied until the exact provider runtime contract is certified.' );
		return true;
	}

	private static function validate_snapshot( $snapshot ) {
		if ( ! is_array( $snapshot ) || empty( $snapshot['target_type'] ) || ! isset( $snapshot['target_id'], $snapshot['target'], $snapshot['state'] ) || ! is_array( $snapshot['target'] ) || ! is_array( $snapshot['state'] ) ) return new WP_Error( 'mad4b_reversible_snapshot_invalid', 'Adapter reversible snapshot must contain bounded target and state objects.' );
		$target_type = sanitize_key( (string) $snapshot['target_type'] );
		$target_id = sanitize_text_field( (string) $snapshot['target_id'] );
		if ( '' === $target_type || '' === $target_id || strlen( $target_id ) > 191 ) return new WP_Error( 'mad4b_reversible_target_invalid', 'Adapter reversible target identity is invalid.' );
		$encoded = wp_json_encode( array( 'target' => $snapshot['target'], 'state' => $snapshot['state'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_ROLLBACK_BYTES ) return new WP_Error( 'mad4b_reversible_snapshot_too_large', 'Adapter reversible snapshot exceeds the bounded rollback limit.' );
		return array( 'target_type' => $target_type, 'target_id' => $target_id, 'target' => $snapshot['target'], 'state' => $snapshot['state'] );
	}

	private static function decode_payload( array $record ) {
		$raw = isset( $record['rollback_payload'] ) ? (string) $record['rollback_payload'] : '';
		$expected = isset( $record['rollback_payload_sha256'] ) ? (string) $record['rollback_payload_sha256'] : '';
		if ( '' === $raw || '' === $expected || ! hash_equals( $expected, hash( 'sha256', $raw ) ) ) return new WP_Error( 'mad4b_undo_payload_integrity_failed', 'Stored rollback payload failed integrity verification.' );
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) || self::ROLLBACK_CONTRACT !== ( isset( $payload['contract'] ) ? $payload['contract'] : '' ) || empty( $payload['adapter_id'] ) || empty( $payload['ability'] ) || empty( $payload['restore_contract'] ) || ! isset( $payload['target'], $payload['state'] ) || ! is_array( $payload['target'] ) || ! is_array( $payload['state'] ) ) return new WP_Error( 'mad4b_undo_payload_invalid', 'Stored adapter rollback payload is invalid.' );
		if ( ! hash_equals( (string) $record['ability_name'], (string) $payload['ability'] ) ) return new WP_Error( 'mad4b_undo_payload_mismatch', 'Rollback payload ability does not match mutation evidence.' );
		return $payload;
	}

	private static function state_hash( array $state ) {
		return hash( 'sha256', wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function undo_recovery_required( $mutation_id, $recovery_id, $message, $error_code = 'mad4b_undo_recovery_required' ) {
		MAD4B_SCP_Audit::record( 'mad4b/undo-intent-reconciliation-required', array(
			'mutation_id' => (string) $mutation_id,
			'recovery_mutation_id' => (string) $recovery_id,
		), 'failure' );
		return new WP_Error( (string) $error_code, (string) $message,
			array( 'mutation_id' => (string) $mutation_id,
				'recovery_mutation_id' => (string) $recovery_id,
				'recovery_required' => true, 'blind_retry_allowed' => false )
		);
	}

	private static function journal_recovery_error( $mutation_id, $message ) {
		MAD4B_SCP_Audit::record( 'mad4b/reversible-adapter-journal-recovery', array(
			'mutation_id' => (string) $mutation_id,
			'error_code' => 'mutation_status_persistence_failed',
		), 'failure' );
		return new WP_Error(
			'mad4b_mutation_journal_recovery_required',
			(string) $message . ' Reconciliation is required; blind retry is prohibited.',
			array( 'mutation_id' => (string) $mutation_id, 'recovery_required' => true, 'blind_retry_allowed' => false )
		);
	}

	private static function update_record( $mutation_id, array $changes ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$allowed = array( 'status' => '%s', 'after_sha256' => '%s', 'verification_code' => '%s', 'error_code' => '%s' );
		$data = array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		$formats = array( '%s' );
		foreach ( $changes as $key => $value ) if ( isset( $allowed[ $key ] ) ) { $data[ $key ] = $value; $formats[] = $allowed[ $key ]; }
		return $wpdb->update( $t['mutations'], $data, array( 'mutation_id' => (string) $mutation_id ), $formats, array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}
}
