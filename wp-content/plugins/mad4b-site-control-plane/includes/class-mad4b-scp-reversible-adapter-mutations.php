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
	const DEFAULT_UNDO_TTL = 259200;
	const MAX_UNDO_TTL = 604800;
	const MAX_ROLLBACK_BYTES = 262144;

	public static function execute( $adapter, $ability_name, $method, $input ) {
		global $wpdb;
		if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base ) return new WP_Error( 'mad4b_reversible_adapter_invalid', 'Reversible adapter execution requires a registered MAD4B adapter.' );
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_reversible_input_invalid', 'Reversible adapter mutation input must be an object.' );
		$ability_name = (string) $ability_name;
		$contract = $adapter->reversible_contract_for( $ability_name );
		if ( '' === $contract ) return new WP_Error( 'mad4b_reversible_contract_missing', 'Adapter write has no certified reversible contract.' );
		if ( ! is_callable( array( $adapter, $method ) ) ) return new WP_Error( 'mad4b_reversible_execute_missing', 'Adapter mutation implementation is unavailable.' );

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
		self::update_record( $mutation_id, array( 'status' => 'executing' ) );

		try {
			$result = call_user_func( array( $adapter, $method ), $input );
		} catch ( Throwable $e ) {
			self::update_record( $mutation_id, array( 'status' => 'failed', 'error_code' => 'provider_exception' ) );
			MAD4B_SCP_Audit::record( 'mad4b/reversible-adapter-failed', array( 'mutation_id' => $mutation_id, 'ability' => $ability_name, 'adapter' => $adapter->id(), 'error_type' => get_class( $e ) ), 'failure' );
			return new WP_Error( 'mad4b_reversible_provider_exception', 'Adapter mutation failed before verification.' );
		}
		if ( is_wp_error( $result ) ) {
			self::update_record( $mutation_id, array( 'status' => 'failed', 'error_code' => $result->get_error_code() ) );
			return $result;
		}

		$after_state = $adapter->read_reversible_state( $ability_name, $before['target'] );
		if ( is_wp_error( $after_state ) ) {
			self::update_record( $mutation_id, array( 'status' => 'verification_failed', 'error_code' => $after_state->get_error_code(), 'verification_code' => 'readback_failed' ) );
			return new WP_Error( 'mad4b_reversible_readback_failed', 'Provider write completed but reversible readback failed.', array( 'mutation_id' => $mutation_id, 'provider_error' => $after_state->get_error_code() ) );
		}
		if ( ! is_array( $after_state ) ) {
			self::update_record( $mutation_id, array( 'status' => 'verification_failed', 'error_code' => 'invalid_readback_state', 'verification_code' => 'readback_failed' ) );
			return new WP_Error( 'mad4b_reversible_readback_invalid', 'Provider reversible readback did not return a bounded state object.' );
		}
		$after_hash = self::state_hash( $after_state );
		self::update_record( $mutation_id, array( 'status' => 'verified', 'after_sha256' => $after_hash, 'verification_code' => 'provider_readback_recorded', 'error_code' => '' ) );
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
	}

	public static function can_undo_record( array $record ) {
		if ( empty( $record['reversible'] ) || 'verified' !== $record['status'] ) return false;
		$payload = self::decode_payload( $record );
		return ! is_wp_error( $payload );
	}

	public static function undo( $mutation_id ) {
		global $wpdb;
		$record = MAD4B_SCP_Mutation_Manager::get( $mutation_id );
		if ( ! $record ) return new WP_Error( 'mad4b_mutation_missing', 'Mutation record was not found.' );
		if ( empty( $record['reversible'] ) || 'verified' !== $record['status'] ) return new WP_Error( 'mad4b_undo_not_reversible', 'Mutation is not a verified reversible operation.' );
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

		$current = $adapter->read_reversible_state( $record['ability_name'], $payload['target'] );
		if ( is_wp_error( $current ) ) return $current;
		$current_hash = self::state_hash( $current );
		if ( empty( $record['after_sha256'] ) || ! hash_equals( (string) $record['after_sha256'], $current_hash ) ) return new WP_Error( 'mad4b_undo_state_drift', 'Provider state changed after the recorded mutation; automatic undo refuses to overwrite newer work.', array( 'current_sha256' => $current_hash ) );

		$restored = $adapter->restore_reversible_state( $record['ability_name'], $payload['target'], $payload['state'], $record );
		if ( is_wp_error( $restored ) ) return $restored;
		$readback = $adapter->read_reversible_state( $record['ability_name'], $payload['target'] );
		if ( is_wp_error( $readback ) ) return $readback;
		$restored_hash = self::state_hash( $readback );
		if ( empty( $record['before_sha256'] ) || ! hash_equals( (string) $record['before_sha256'], $restored_hash ) ) return new WP_Error( 'mad4b_undo_verification_failed', 'Adapter restore completed but read-after-write did not match the recorded before-state.' );

		$recovery_id = wp_generate_uuid4();
		$now = gmdate( 'Y-m-d H:i:s' );
		$t = MAD4B_SCP_Schema::tables();
		$undo_approval_ticket_id = isset( $identity['approval_ticket_id'] ) && '' !== $identity['approval_ticket_id'] ? $identity['approval_ticket_id'] : null;
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
			'status' => 'undone',
			'reversible' => 0,
			'before_sha256' => $current_hash,
			'after_sha256' => $restored_hash,
			'rollback_payload' => null,
			'rollback_payload_sha256' => '',
			'undo_expires_at' => null,
			'verification_code' => 'adapter_restore_readback_match',
			'error_code' => '',
			'created_at' => $now,
			'updated_at' => $now,
		), array( '%s','%s','%s','%d','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $inserted ) return new WP_Error( 'mad4b_undo_record_failed', 'Provider state was restored but recovery evidence could not be persisted.', array( 'db_error' => $wpdb->last_error ) );
		self::update_record( $mutation_id, array( 'status' => 'undone' ) );
		MAD4B_SCP_Audit::record( 'mad4b/mutation-undo', array( 'mutation_id' => $mutation_id, 'recovery_mutation_id' => $recovery_id, 'provider' => $record['provider'], 'target_type' => $record['target_type'], 'target_id' => $record['target_id'], 'restored_sha256' => $restored_hash, 'approval_ticket_id' => $undo_approval_ticket_id ) );
		return array( 'status' => 'undone', 'original_mutation_id' => $mutation_id, 'recovery_mutation_id' => $recovery_id, 'restored_sha256' => $restored_hash, 'verified' => true, 'restore_contract' => $payload['restore_contract'] );
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
