<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Mutation_Manager {
	const MAX_ROLLBACK_BYTES = 262144;
	const DEFAULT_UNDO_TTL = 259200; // 72 hours; policy may narrow.
	const MAX_UNDO_TTL = 604800;

	public static function execute_post_update( array $input ) {
		global $wpdb;
		$id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) return new WP_Error( 'mad4b_post_missing', 'Post not found.' );
		if ( ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'mad4b_post_edit_denied', 'Current user cannot edit this post.' );
		$expected = isset( $input['expected_modified_gmt'] ) ? trim( (string) $input['expected_modified_gmt'] ) : '';
		if ( '' === $expected || ! hash_equals( (string) $post->post_modified_gmt, $expected ) ) return new WP_Error( 'mad4b_stale_post', 'Post has changed since it was read.', array( 'current_modified_gmt' => $post->post_modified_gmt ) );

		$update = array( 'ID' => $id );
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status' ) as $field ) if ( array_key_exists( $field, $input ) ) $update[ $field ] = $input[ $field ];
		if ( count( $update ) < 2 ) return new WP_Error( 'mad4b_post_no_changes', 'No mutable post fields were supplied.' );
		if ( isset( $update['post_status'] ) && 'publish' === $update['post_status'] ) {
			$type = get_post_type_object( $post->post_type );
			$cap = $type && isset( $type->cap->publish_posts ) ? $type->cap->publish_posts : 'publish_posts';
			if ( ! current_user_can( $cap ) ) return new WP_Error( 'mad4b_cannot_publish', 'Current user cannot publish this post type.' );
		}

		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;

		$before = self::post_state( $post );
		$rollback_json = wp_json_encode( array( 'contract' => 'mad4b.rollback.post.v1', 'post_id' => $id, 'state' => $before ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $rollback_json ) return new WP_Error( 'mad4b_rollback_encode_failed', 'Post rollback state could not be encoded.' );
		if ( strlen( $rollback_json ) > self::MAX_ROLLBACK_BYTES ) return new WP_Error( 'mad4b_rollback_payload_too_large', 'Post state exceeds the certified in-database rollback size limit; mutation is denied until an external snapshot contract exists.' );

		$mutation_id = wp_generate_uuid4();
		$request_id = isset( $identity['request_id'] ) ? (string) $identity['request_id'] : MAD4B_SCP_Identity_Context::request_id();
		$before_hash = self::state_hash( $before );
		$undo_ttl = (int) apply_filters( 'mad4b_scp_post_undo_ttl', self::DEFAULT_UNDO_TTL, $id, $agent, $input );
		$undo_ttl = max( 60, min( self::MAX_UNDO_TTL, $undo_ttl ) );
		$now = gmdate( 'Y-m-d H:i:s' );
		$t = MAD4B_SCP_Schema::tables();
		$impact = MAD4B_SCP_Impact_Policy::impact_for( 'mad4b/content-update-post', 'core', $input );
		$approval_ticket_id = isset( $identity['approval_ticket_id'] ) && '' !== $identity['approval_ticket_id'] ? $identity['approval_ticket_id'] : null;
		$inserted = $wpdb->insert( $t['mutations'], array(
			'mutation_id' => $mutation_id,
			'request_id' => $request_id,
			'parent_mutation_id' => null,
			'agent_id' => (int) $agent['id'],
			'subject_type' => (string) $identity['subject_type'],
			'subject_fingerprint' => (string) $identity['subject_fingerprint'],
			'wp_user_id' => get_current_user_id(),
			'server_id' => 'mad4b-content',
			'ability_name' => 'mad4b/content-update-post',
			'provider' => 'core',
			'provider_version' => MAD4B_SCP_VERSION,
			'target_type' => 'post',
			'target_id' => (string) $id,
			'approval_ticket_id' => $approval_ticket_id,
			'impact' => $impact,
			'status' => 'planned',
			'reversible' => 1,
			'before_sha256' => $before_hash,
			'after_sha256' => '',
			'rollback_payload' => $rollback_json,
			'rollback_payload_sha256' => hash( 'sha256', $rollback_json ),
			'undo_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $undo_ttl ),
			'verification_code' => '',
			'error_code' => '',
			'created_at' => $now,
			'updated_at' => $now,
		), array( '%s','%s','%s','%d','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $inserted ) return new WP_Error( 'mad4b_mutation_record_failed', 'Unable to persist the mutation envelope before execution.', array( 'db_error' => $wpdb->last_error ) );
		self::update_record( $mutation_id, array( 'status' => 'executing' ) );

		$result = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $result ) ) {
			self::update_record( $mutation_id, array( 'status' => 'failed', 'error_code' => $result->get_error_code() ) );
			return $result;
		}
		$updated = get_post( $id );
		if ( ! $updated ) {
			self::update_record( $mutation_id, array( 'status' => 'verification_failed', 'error_code' => 'post_missing_after_update' ) );
			return new WP_Error( 'mad4b_mutation_verification_failed', 'Post disappeared before read-after-write verification.' );
		}
		$after = self::post_state( $updated );
		$verify = self::verify_post_update( $after, $update );
		$after_hash = self::state_hash( $after );
		if ( is_wp_error( $verify ) ) {
			self::update_record( $mutation_id, array( 'status' => 'verification_failed', 'after_sha256' => $after_hash, 'verification_code' => 'readback_mismatch', 'error_code' => $verify->get_error_code() ) );
			MAD4B_SCP_Audit::record( 'mad4b/content-update-post', array( 'mutation_id' => $mutation_id, 'post_id' => $id, 'verified' => false, 'error_code' => $verify->get_error_code() ), 'failed' );
			return $verify;
		}

		self::update_record( $mutation_id, array( 'status' => 'verified', 'after_sha256' => $after_hash, 'verification_code' => 'readback_match', 'error_code' => '' ) );
		MAD4B_SCP_Audit::record( 'mad4b/content-update-post', array( 'mutation_id' => $mutation_id, 'post_id' => $id, 'before_sha256' => $before_hash, 'after_sha256' => $after_hash, 'verified' => true ) );
		$record = self::get( $mutation_id );
		return array(
			'post_id' => $id,
			'updated' => true,
			'modified_gmt' => $updated->post_modified_gmt,
			'mutation_id' => $mutation_id,
			'before_sha256' => $before_hash,
			'after_sha256' => $after_hash,
			'verified' => true,
			'reversible' => true,
			'undo_expires_at' => $record ? $record['undo_expires_at'] : '',
		);
	}

	public static function undo_post_mutation( $mutation_id ) {
		global $wpdb;
		$record = self::get( $mutation_id );
		if ( ! $record ) return new WP_Error( 'mad4b_mutation_missing', 'Mutation record was not found.' );
		if ( 'mad4b/content-update-post' !== $record['ability_name'] || 'post' !== $record['target_type'] ) return new WP_Error( 'mad4b_undo_not_supported', 'This mutation does not use the certified post rollback contract.' );
		if ( empty( $record['reversible'] ) || 'verified' !== $record['status'] ) return new WP_Error( 'mad4b_undo_not_reversible', 'Mutation is not a verified reversible operation.' );
		if ( empty( $record['undo_expires_at'] ) || strtotime( $record['undo_expires_at'] . ' UTC' ) < time() ) return new WP_Error( 'mad4b_undo_expired', 'Undo window has expired.' );
		$id = absint( $record['target_id'] );
		if ( ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'mad4b_undo_capability_denied', 'Current user cannot restore this post.' );
		if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'Mutation/NHI authority is required for undo.' );
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;
		if ( (int) $agent['id'] !== (int) $record['agent_id'] && ! apply_filters( 'mad4b_scp_allow_cross_agent_undo', false, $record, $agent ) ) return new WP_Error( 'mad4b_undo_agent_denied', 'A different agent cannot undo this mutation by default.' );

		$current = get_post( $id );
		if ( ! $current ) return new WP_Error( 'mad4b_post_missing', 'Post no longer exists.' );
		$current_hash = self::state_hash( self::post_state( $current ) );
		if ( empty( $record['after_sha256'] ) || ! hash_equals( $record['after_sha256'], $current_hash ) ) return new WP_Error( 'mad4b_undo_state_drift', 'Post changed after the recorded mutation; automatic undo refuses to overwrite newer work.', array( 'current_sha256' => $current_hash ) );
		$rollback_json = (string) $record['rollback_payload'];
		if ( '' === $rollback_json || ! hash_equals( (string) $record['rollback_payload_sha256'], hash( 'sha256', $rollback_json ) ) ) return new WP_Error( 'mad4b_undo_payload_integrity_failed', 'Stored rollback payload failed integrity verification.' );
		$rollback = json_decode( $rollback_json, true );
		if ( ! is_array( $rollback ) || 'mad4b.rollback.post.v1' !== ( isset( $rollback['contract'] ) ? $rollback['contract'] : '' ) || (int) $rollback['post_id'] !== $id || ! isset( $rollback['state'] ) || ! is_array( $rollback['state'] ) ) return new WP_Error( 'mad4b_undo_payload_invalid', 'Stored rollback payload is invalid.' );
		$state = $rollback['state'];
		$restore = array( 'ID' => $id );
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status' ) as $field ) {
			if ( ! array_key_exists( $field, $state ) ) return new WP_Error( 'mad4b_undo_payload_invalid', 'Rollback payload is missing a required post field.' );
			$restore[ $field ] = $state[ $field ];
		}
		$restored = wp_update_post( wp_slash( $restore ), true );
		if ( is_wp_error( $restored ) ) return $restored;
		$readback = get_post( $id );
		$restored_hash = $readback ? self::state_hash( self::post_state( $readback ) ) : '';
		if ( '' === $restored_hash || ! hash_equals( (string) $record['before_sha256'], $restored_hash ) ) return new WP_Error( 'mad4b_undo_verification_failed', 'Undo write completed but read-after-write verification did not match the recorded before-state.' );

		$recovery_id = wp_generate_uuid4();
		$now = gmdate( 'Y-m-d H:i:s' );
		$t = MAD4B_SCP_Schema::tables();
		$inserted = $wpdb->insert( $t['mutations'], array(
			'mutation_id' => $recovery_id, 'request_id' => isset( $identity['request_id'] ) ? $identity['request_id'] : MAD4B_SCP_Identity_Context::request_id(), 'parent_mutation_id' => $mutation_id,
			'agent_id' => (int) $agent['id'], 'subject_type' => (string) $identity['subject_type'], 'subject_fingerprint' => (string) $identity['subject_fingerprint'], 'wp_user_id' => get_current_user_id(),
			'server_id' => 'mad4b-admin', 'ability_name' => 'mad4b/mutation-undo', 'provider' => 'core', 'provider_version' => MAD4B_SCP_VERSION, 'target_type' => 'post', 'target_id' => (string) $id,
			'approval_ticket_id' => null, 'impact' => 'high', 'status' => 'undone', 'reversible' => 0, 'before_sha256' => $current_hash, 'after_sha256' => $restored_hash,
			'rollback_payload' => null, 'rollback_payload_sha256' => '', 'undo_expires_at' => null, 'verification_code' => 'restore_readback_match', 'error_code' => '', 'created_at' => $now, 'updated_at' => $now,
		), array( '%s','%s','%s','%d','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $inserted ) return new WP_Error( 'mad4b_undo_record_failed', 'Post was restored but recovery evidence could not be persisted.', array( 'db_error' => $wpdb->last_error ) );
		self::update_record( $mutation_id, array( 'status' => 'undone' ) );
		MAD4B_SCP_Audit::record( 'mad4b/mutation-undo', array( 'mutation_id' => $mutation_id, 'recovery_mutation_id' => $recovery_id, 'post_id' => $id, 'restored_sha256' => $restored_hash ) );
		return array( 'status' => 'undone', 'original_mutation_id' => $mutation_id, 'recovery_mutation_id' => $recovery_id, 'restored_sha256' => $restored_hash, 'verified' => true );
	}

	public static function get( $mutation_id ) {
		global $wpdb; $t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['mutations']} WHERE mutation_id = %s LIMIT 1", (string) $mutation_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $row ? $row : null;
	}

	private static function post_state( $post ) {
		return array(
			'post_title' => (string) $post->post_title,
			'post_content' => (string) $post->post_content,
			'post_excerpt' => (string) $post->post_excerpt,
			'post_status' => (string) $post->post_status,
		);
	}

	private static function state_hash( array $state ) { return hash( 'sha256', wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); }

	private static function verify_post_update( array $after, array $update ) {
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status' ) as $field ) {
			if ( array_key_exists( $field, $update ) && (string) $after[ $field ] !== (string) $update[ $field ] ) return new WP_Error( 'mad4b_mutation_verification_failed', 'Read-after-write did not match requested post state.', array( 'field' => $field ) );
		}
		return true;
	}

	private static function update_record( $mutation_id, array $changes ) {
		global $wpdb; $t = MAD4B_SCP_Schema::tables();
		$allowed = array( 'status' => '%s', 'after_sha256' => '%s', 'verification_code' => '%s', 'error_code' => '%s' );
		$data = array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		$formats = array( '%s' );
		foreach ( $changes as $key => $value ) if ( isset( $allowed[ $key ] ) ) { $data[ $key ] = $value; $formats[] = $allowed[ $key ]; }
		return $wpdb->update( $t['mutations'], $data, array( 'mutation_id' => (string) $mutation_id ), $formats, array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}
}
