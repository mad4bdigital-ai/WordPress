<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Agent_Registry {
	private static function now() { return current_time( 'mysql', true ); }

	public static function create_agent( array $args ) {
		global $wpdb;
		if ( ! MAD4B_SCP_Schema::is_ready() ) return new WP_Error( 'mad4b_governance_schema_unavailable', 'Governance schema is unavailable.' );
		$slug = isset( $args['slug'] ) ? sanitize_title( $args['slug'] ) : '';
		$label = isset( $args['label'] ) ? sanitize_text_field( $args['label'] ) : '';
		$status = isset( $args['status'] ) ? (string) $args['status'] : 'disabled';
		$environment = isset( $args['environment'] ) ? sanitize_key( $args['environment'] ) : 'unknown';
		if ( '' === $slug || '' === $label ) return new WP_Error( 'mad4b_agent_invalid', 'Agent slug and label are required.' );
		if ( ! in_array( $status, array( 'disabled', 'enabled' ), true ) ) return new WP_Error( 'mad4b_agent_status_invalid', 'Unknown agent status.' );
		if ( ! in_array( $environment, array( 'production', 'staging', 'development', 'local', 'unknown', 'all' ), true ) ) return new WP_Error( 'mad4b_agent_environment_invalid', 'Unknown agent environment.' );
		$t = MAD4B_SCP_Schema::tables();
		$now = self::now();
		$ok = $wpdb->insert( $t['agents'], array(
			'public_id' => wp_generate_uuid4(), 'slug' => $slug, 'label' => $label, 'status' => $status,
			'wp_user_id' => isset( $args['wp_user_id'] ) ? absint( $args['wp_user_id'] ) : null,
			'environment' => $environment, 'revision' => 1, 'created_by' => get_current_user_id(),
			'created_at' => $now, 'updated_at' => $now,
		), array( '%s','%s','%s','%s','%d','%s','%d','%d','%s','%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $ok ) return new WP_Error( 'mad4b_agent_create_failed', 'Unable to create agent.', array( 'db_error' => $wpdb->last_error ) );
		return self::get_agent_by_id( (int) $wpdb->insert_id );
	}

	public static function get_agent_by_id( $id ) {
		global $wpdb; $t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['agents']} WHERE id = %d LIMIT 1", absint( $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $row ? $row : null;
	}

	public static function get_agent_by_public_id( $public_id ) {
		global $wpdb; $t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['agents']} WHERE public_id = %s LIMIT 1", (string) $public_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $row ? $row : null;
	}

	public static function update_agent( $public_id, array $changes, $expected_revision ) {
		global $wpdb; $t = MAD4B_SCP_Schema::tables();
		$agent = self::get_agent_by_public_id( $public_id );
		if ( ! $agent ) return new WP_Error( 'mad4b_agent_missing', 'Agent not found.' );
		if ( (int) $agent['revision'] !== absint( $expected_revision ) ) return new WP_Error( 'mad4b_agent_stale', 'Agent configuration changed since it was read.' );
		$data = array( 'revision' => (int) $agent['revision'] + 1, 'updated_at' => self::now() );
		$formats = array( '%d', '%s' );
		if ( isset( $changes['label'] ) ) { $data['label'] = sanitize_text_field( $changes['label'] ); $formats[] = '%s'; }
		if ( isset( $changes['status'] ) ) {
			if ( ! in_array( $changes['status'], array( 'disabled', 'enabled' ), true ) ) return new WP_Error( 'mad4b_agent_status_invalid', 'Unknown agent status.' );
			$data['status'] = $changes['status']; $formats[] = '%s';
		}
		if ( isset( $changes['environment'] ) ) {
			$env = sanitize_key( $changes['environment'] );
			if ( ! in_array( $env, array( 'production', 'staging', 'development', 'local', 'unknown', 'all' ), true ) ) return new WP_Error( 'mad4b_agent_environment_invalid', 'Unknown agent environment.' );
			$data['environment'] = $env; $formats[] = '%s';
		}
		if ( array_key_exists( 'wp_user_id', $changes ) ) { $data['wp_user_id'] = absint( $changes['wp_user_id'] ); $formats[] = '%d'; }
		$updated = $wpdb->update( $t['agents'], $data, array( 'id' => (int) $agent['id'], 'revision' => (int) $agent['revision'] ), $formats, array( '%d','%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $updated || 0 === $updated ) return new WP_Error( 'mad4b_agent_update_failed', 'Agent update failed or became stale.' );
		return self::get_agent_by_id( $agent['id'] );
	}

	public static function disable_agent( $public_id, $expected_revision ) { return self::update_agent( $public_id, array( 'status' => 'disabled' ), $expected_revision ); }

	public static function bind_subject( $agent_public_id, $subject_type, $subject_fingerprint, $label = '' ) {
		global $wpdb; $t = MAD4B_SCP_Schema::tables();
		$agent = self::get_agent_by_public_id( $agent_public_id );
		if ( ! $agent ) return new WP_Error( 'mad4b_agent_missing', 'Agent not found.' );
		$type = sanitize_key( (string) $subject_type );
		$fingerprint = strtolower( trim( (string) $subject_fingerprint ) );
		if ( '' === $type || ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) return new WP_Error( 'mad4b_subject_invalid', 'Subject type and SHA-256 fingerprint are required.' );
		$now = self::now();
		$ok = $wpdb->insert( $t['subjects'], array( 'agent_id' => (int) $agent['id'], 'subject_type' => $type, 'subject_fingerprint' => $fingerprint, 'label' => sanitize_text_field( $label ), 'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now ), array( '%d','%s','%s','%s','%s','%s','%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $ok ) return new WP_Error( 'mad4b_subject_bind_failed', 'Subject binding failed; the subject may already be bound.', array( 'db_error' => $wpdb->last_error ) );
		return true;
	}

	public static function grant_ability( $agent_public_id, $server_id, $ability_name, $provider = 'core', array $constraints = array(), $effect = 'allow', $environment = 'all' ) {
		global $wpdb; $t = MAD4B_SCP_Schema::tables();
		$agent = self::get_agent_by_public_id( $agent_public_id );
		if ( ! $agent ) return new WP_Error( 'mad4b_agent_missing', 'Agent not found.' );
		$server_id = sanitize_key( $server_id );
		$ability_name = trim( (string) $ability_name );
		$provider = sanitize_key( $provider );
		$environment = sanitize_key( $environment );
		if ( '' === $server_id || '' === $ability_name || strlen( $ability_name ) > 191 ) return new WP_Error( 'mad4b_grant_invalid', 'Exact server and ability are required.' );
		if ( preg_match( '/[*?\[\]]/', $ability_name ) ) return new WP_Error( 'mad4b_wildcard_grant_denied', 'Wildcard ability grants are forbidden.' );
		if ( ! in_array( $effect, array( 'allow', 'deny' ), true ) ) return new WP_Error( 'mad4b_grant_effect_invalid', 'Grant effect must be allow or deny.' );
		if ( ! in_array( $environment, array( 'all', 'production', 'staging', 'development', 'local', 'unknown' ), true ) ) return new WP_Error( 'mad4b_grant_environment_invalid', 'Unknown grant environment.' );
		if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $ability_name ) ) return new WP_Error( 'mad4b_grant_ability_unknown', 'Cannot grant an unknown WordPress Ability.' );
		if ( class_exists( 'MAD4B_SCP_Servers' ) ) {
			if ( ! in_array( $server_id, MAD4B_SCP_Servers::expected_server_ids(), true ) ) return new WP_Error( 'mad4b_grant_server_unknown', 'Cannot grant an unknown MAD4B MCP server.' );
			if ( ! MAD4B_SCP_Servers::ability_is_mounted( $server_id, $ability_name ) ) return new WP_Error( 'mad4b_grant_server_ability_mismatch', 'Cannot grant an ability on a MAD4B MCP server that does not mount it.' );
		}
		if ( 'mad4b-breakglass' === $server_id && ! apply_filters( 'mad4b_scp_allow_breakglass_grant_creation', false, $agent, $ability_name, $provider, $environment ) ) {
			return new WP_Error( 'mad4b_breakglass_grant_creation_denied', 'Breakglass grants require an explicit exceptional administration path.' );
		}
		$encoded = wp_json_encode( $constraints );
		if ( false === $encoded || strlen( $encoded ) > 16384 ) return new WP_Error( 'mad4b_grant_constraints_invalid', 'Resource constraints are invalid or too large.' );
		$now = self::now();
		$ok = $wpdb->insert( $t['grants'], array(
			'agent_id' => (int) $agent['id'], 'effect' => $effect, 'server_id' => $server_id, 'ability_name' => $ability_name,
			'provider' => $provider ?: 'core', 'resource_schema_version' => 'v1', 'resource_constraints' => $encoded,
			'environment' => $environment, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
		), array( '%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $ok ) return new WP_Error( 'mad4b_grant_create_failed', 'Exact grant could not be created.', array( 'db_error' => $wpdb->last_error ) );
		return true;
	}

	public static function resolve_agent( array $identity ) {
		global $wpdb; $t = MAD4B_SCP_Schema::tables();
		if ( empty( $identity['authenticated'] ) || empty( $identity['subject_type'] ) || empty( $identity['subject_fingerprint'] ) ) return new WP_Error( 'mad4b_nhi_identity_required', 'An authenticated MAD4B agent identity is required for mutation.' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT a.* FROM {$t['subjects']} s INNER JOIN {$t['agents']} a ON a.id = s.agent_id WHERE s.subject_type = %s AND s.subject_fingerprint = %s AND s.status = 'enabled' LIMIT 1",
			$identity['subject_type'], $identity['subject_fingerprint']
		), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! $row ) return new WP_Error( 'mad4b_nhi_subject_unbound', 'Authenticated subject is not bound to an enabled MAD4B agent.' );
		if ( 'enabled' !== $row['status'] ) return new WP_Error( 'mad4b_nhi_agent_disabled', 'MAD4B agent is disabled.' );
		$current = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
		if ( ! in_array( $row['environment'], array( 'all', 'unknown', $current ), true ) ) return new WP_Error( 'mad4b_nhi_environment_denied', 'MAD4B agent is not enabled for this environment.' );
		return $row;
	}

	public static function exact_grant( $agent_id, $server_id, $ability_name, $provider = 'core' ) {
		global $wpdb; $t = MAD4B_SCP_Schema::tables();
		$current = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t['grants']} WHERE agent_id = %d AND server_id = %s AND ability_name = %s AND provider = %s AND environment IN ('all', %s) ORDER BY CASE effect WHEN 'deny' THEN 0 ELSE 1 END, id ASC",
			absint( $agent_id ), (string) $server_id, (string) $ability_name, (string) $provider, $current
		), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		foreach ( $rows as $row ) if ( 'deny' === $row['effect'] ) return new WP_Error( 'mad4b_nhi_grant_denied', 'Agent grant explicitly denies this ability.' );
		foreach ( $rows as $row ) if ( 'allow' === $row['effect'] ) return $row;
		return new WP_Error( 'mad4b_nhi_grant_missing', 'Agent does not have an exact grant for this ability.' );
	}

	public static function counts() {
		global $wpdb; $t = MAD4B_SCP_Schema::tables();
		return array(
			'enabled_agents' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['agents']} WHERE status = 'enabled'" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			'enabled_subjects' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['subjects']} WHERE status = 'enabled'" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			'grants' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['grants']}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			'wildcard_grants' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['grants']} WHERE ability_name LIKE '%*%' OR ability_name LIKE '%?%'" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		);
	}
}
