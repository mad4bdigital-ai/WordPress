<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Budgets {
	const MIN_WINDOW_SECONDS = 60;
	const MAX_WINDOW_SECONDS = 604800; // 7 days.
	const MAX_COUNT = 1000000;
	const MAX_COST = 100000;
	const CLEANUP_LIMIT = 200;

	private static $transaction_open = false;

	public static function types() {
		return array( 'requests', 'mutations', 'affected_objects', 'external_actions' );
	}

	public static function set_budget( $agent_public_id, $budget_type, $window_seconds, $max_count, $enabled = true ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_budget_admin_required', 'Administrator capability is required to configure NHI budgets.' );
		$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( (string) $agent_public_id );
		if ( ! $agent ) return new WP_Error( 'mad4b_agent_missing', 'Agent not found.' );
		$type = sanitize_key( (string) $budget_type );
		if ( ! in_array( $type, self::types(), true ) ) return new WP_Error( 'mad4b_budget_type_invalid', 'Unknown NHI budget type.' );
		$window_seconds = absint( $window_seconds );
		$max_count = absint( $max_count );
		if ( $window_seconds < self::MIN_WINDOW_SECONDS || $window_seconds > self::MAX_WINDOW_SECONDS ) return new WP_Error( 'mad4b_budget_window_invalid', 'Budget window must be between 60 seconds and 7 days.' );
		if ( $max_count < 1 || $max_count > self::MAX_COUNT ) return new WP_Error( 'mad4b_budget_count_invalid', 'Budget maximum must be between 1 and 1,000,000.' );
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$sql = $wpdb->prepare(
			"INSERT INTO {$t['budgets']} (agent_id,budget_type,window_seconds,max_count,enabled,updated_by,updated_at) VALUES (%d,%s,%d,%d,%d,%d,%s)
			 ON DUPLICATE KEY UPDATE window_seconds = VALUES(window_seconds), max_count = VALUES(max_count), enabled = VALUES(enabled), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
			(int) $agent['id'], $type, $window_seconds, $max_count, $enabled ? 1 : 0, get_current_user_id(), $now
		);
		$written = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $written ) return new WP_Error( 'mad4b_budget_write_failed', 'Budget configuration could not be persisted.', array( 'db_error' => $wpdb->last_error ) );
		MAD4B_SCP_Audit::record( 'mad4b/budget-configured', array( 'agent_public_id' => $agent['public_id'], 'budget_type' => $type, 'window_seconds' => $window_seconds, 'max_count' => $max_count, 'enabled' => (bool) $enabled ) );
		return self::get_budget( (int) $agent['id'], $type );
	}

	public static function get_budget( $agent_id, $budget_type ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['budgets']} WHERE agent_id = %d AND budget_type = %s LIMIT 1", absint( $agent_id ), sanitize_key( (string) $budget_type ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $row ? $row : null;
	}

	public static function list_for_agent( $agent_id ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT budget_type,window_seconds,max_count,enabled,updated_at FROM {$t['budgets']} WHERE agent_id = %d ORDER BY budget_type ASC", absint( $agent_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return is_array( $rows ) ? $rows : array();
	}

	public static function costs_for( $ability_name, $provider, $input = null ) {
		$affected = 1;
		if ( is_array( $input ) && isset( $input['max_affected'] ) ) $affected = max( 1, min( self::MAX_COST, absint( $input['max_affected'] ) ) );
		$external = ( 'bit_pi' === sanitize_key( (string) $provider ) || false !== strpos( (string) $ability_name, 'run-flow' ) ) ? 1 : 0;
		$costs = array(
			'requests' => 1,
			'mutations' => 1,
			'affected_objects' => $affected,
			'external_actions' => $external,
		);
		$costs = apply_filters( 'mad4b_scp_budget_costs', $costs, (string) $ability_name, sanitize_key( (string) $provider ), $input );
		if ( ! is_array( $costs ) ) return new WP_Error( 'mad4b_budget_costs_invalid', 'Budget cost policy returned an invalid value.' );
		$normalized = array();
		foreach ( self::types() as $type ) {
			$value = isset( $costs[ $type ] ) ? $costs[ $type ] : 0;
			if ( ! is_int( $value ) && ! ctype_digit( (string) $value ) ) return new WP_Error( 'mad4b_budget_cost_invalid', 'Budget costs must be non-negative integers.', array( 'budget_type' => $type ) );
			$value = (int) $value;
			if ( $value < 0 || $value > self::MAX_COST ) return new WP_Error( 'mad4b_budget_cost_invalid', 'Budget cost is outside the allowed bound.', array( 'budget_type' => $type ) );
			$normalized[ $type ] = $value;
		}
		return $normalized;
	}

	/**
	 * Atomically reserves configured budget units and leaves the DB transaction open.
	 * Caller MUST commit() after all pre-side-effect gates (including approval consume) pass,
	 * or rollback() on any denial. When no enabled budgets exist, no transaction is opened.
	 */
	public static function reserve( array $agent, $ability_name, $provider, $input = null, $approval_will_be_consumed = false ) {
		global $wpdb;
		if ( self::$transaction_open ) return new WP_Error( 'mad4b_budget_nested_transaction_denied', 'Nested NHI budget reservation is not supported.' );
		$agent_id = isset( $agent['id'] ) ? absint( $agent['id'] ) : 0;
		if ( ! $agent_id ) return new WP_Error( 'mad4b_budget_agent_invalid', 'Budget reservation requires a resolved agent.' );
		$costs = self::costs_for( $ability_name, $provider, $input );
		if ( is_wp_error( $costs ) ) return $costs;
		$t = MAD4B_SCP_Schema::tables();

		if ( ! self::transactional_table( $t['budgets'] ) || ! self::transactional_table( $t['budget_windows'] ) ) {
			return new WP_Error( 'mad4b_budget_transaction_required', 'NHI budget enforcement requires transactional budget tables.' );
		}
		if ( $approval_will_be_consumed && ! self::transactional_table( $t['approvals'] ) ) {
			return new WP_Error( 'mad4b_budget_approval_transaction_required', 'Atomic budget + approval enforcement requires transactional approval storage.' );
		}

		$started = $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $started ) return new WP_Error( 'mad4b_budget_transaction_start_failed', 'Unable to start NHI budget reservation transaction.', array( 'db_error' => $wpdb->last_error ) );
		self::$transaction_open = true;

		$configs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['budgets']} WHERE agent_id = %d AND enabled = 1 ORDER BY budget_type ASC FOR UPDATE", $agent_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! is_array( $configs ) ) return self::fail_transaction( 'mad4b_budget_read_failed', 'Unable to read NHI budget configuration.', $wpdb->last_error );
		if ( empty( $configs ) ) {
			$committed = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::$transaction_open = false;
			if ( false === $committed ) return new WP_Error( 'mad4b_budget_transaction_commit_failed', 'Unable to close empty NHI budget transaction.', array( 'db_error' => $wpdb->last_error ) );
			return array( 'active' => false, 'agent_id' => $agent_id, 'costs' => $costs, 'reservations' => array() );
		}

		$now_epoch = time();
		$now = gmdate( 'Y-m-d H:i:s', $now_epoch );
		$reservations = array();
		foreach ( $configs as $config ) {
			$type = isset( $config['budget_type'] ) ? (string) $config['budget_type'] : '';
			if ( ! in_array( $type, self::types(), true ) ) return self::fail_transaction( 'mad4b_budget_config_invalid', 'Stored NHI budget type is invalid.' );
			$window_seconds = isset( $config['window_seconds'] ) ? (int) $config['window_seconds'] : 0;
			$max_count = isset( $config['max_count'] ) ? (int) $config['max_count'] : 0;
			if ( $window_seconds < self::MIN_WINDOW_SECONDS || $window_seconds > self::MAX_WINDOW_SECONDS || $max_count < 1 || $max_count > self::MAX_COUNT ) {
				return self::fail_transaction( 'mad4b_budget_config_invalid', 'Stored NHI budget configuration is outside allowed bounds.' );
			}
			$cost = isset( $costs[ $type ] ) ? (int) $costs[ $type ] : 0;
			if ( $cost <= 0 ) continue;
			$window_start = (int) ( floor( $now_epoch / $window_seconds ) * $window_seconds );
			$insert = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$t['budget_windows']} (agent_id,budget_type,window_start,window_seconds,used_count,created_at,updated_at) VALUES (%d,%s,%d,%d,0,%s,%s)",
				$agent_id, $type, $window_start, $window_seconds, $now, $now
			) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( false === $insert ) return self::fail_transaction( 'mad4b_budget_window_create_failed', 'Unable to create NHI budget window.', $wpdb->last_error );
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$t['budget_windows']} WHERE agent_id = %d AND budget_type = %s AND window_start = %d LIMIT 1 FOR UPDATE",
				$agent_id, $type, $window_start
			), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( ! $row ) return self::fail_transaction( 'mad4b_budget_window_read_failed', 'Unable to lock NHI budget window.', $wpdb->last_error );
			$used = (int) $row['used_count'];
			if ( $cost > $max_count || $used > $max_count - $cost ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::$transaction_open = false;
				return new WP_Error( 'mad4b_budget_exhausted', 'NHI budget would be exceeded by this operation.', array(
					'budget_type' => $type,
					'window_start' => $window_start,
					'window_seconds' => $window_seconds,
					'max_count' => $max_count,
					'used_count' => $used,
					'requested_cost' => $cost,
					'remaining' => max( 0, $max_count - $used ),
				) );
			}
			$reservations[] = array(
				'id' => (int) $row['id'], 'budget_type' => $type, 'window_start' => $window_start,
				'window_seconds' => $window_seconds, 'max_count' => $max_count, 'used_before' => $used,
				'cost' => $cost, 'used_after' => $used + $cost,
			);
		}

		foreach ( $reservations as $reservation ) {
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$t['budget_windows']} SET used_count = %d, window_seconds = %d, updated_at = %s WHERE id = %d AND used_count = %d",
				(int) $reservation['used_after'], (int) $reservation['window_seconds'], $now, (int) $reservation['id'], (int) $reservation['used_before']
			) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( 1 !== (int) $updated ) return self::fail_transaction( 'mad4b_budget_concurrency_conflict', 'NHI budget counter changed unexpectedly during reservation.', $wpdb->last_error );
		}

		return array( 'active' => true, 'agent_id' => $agent_id, 'costs' => $costs, 'reservations' => $reservations );
	}

	public static function commit( array $reservation ) {
		global $wpdb;
		if ( empty( $reservation['active'] ) ) return true;
		if ( ! self::$transaction_open ) return new WP_Error( 'mad4b_budget_transaction_missing', 'NHI budget reservation transaction is not open.' );
		$committed = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $committed ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::$transaction_open = false;
			return new WP_Error( 'mad4b_budget_transaction_commit_failed', 'Unable to commit NHI budget reservation.', array( 'db_error' => $wpdb->last_error ) );
		}
		self::$transaction_open = false;
		self::cleanup_agent( isset( $reservation['agent_id'] ) ? (int) $reservation['agent_id'] : 0 );
		return true;
	}

	public static function rollback( array $reservation ) {
		global $wpdb;
		if ( empty( $reservation['active'] ) ) return true;
		if ( self::$transaction_open ) $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		self::$transaction_open = false;
		return true;
	}

	private static function transactional_table( $table ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$engine = $row && isset( $row['Engine'] ) ? strtolower( (string) $row['Engine'] ) : '';
		return in_array( $engine, array( 'innodb', 'xtradb' ), true );
	}

	private static function fail_transaction( $code, $message, $db_error = '' ) {
		global $wpdb;
		if ( self::$transaction_open ) $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		self::$transaction_open = false;
		$data = '' !== (string) $db_error ? array( 'db_error' => (string) $db_error ) : array();
		return new WP_Error( $code, $message, $data );
	}

	private static function cleanup_agent( $agent_id ) {
		global $wpdb;
		$agent_id = absint( $agent_id );
		if ( ! $agent_id ) return;
		$t = MAD4B_SCP_Schema::tables();
		$cutoff = time() - ( self::MAX_WINDOW_SECONDS * 2 );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$t['budget_windows']} WHERE agent_id = %d AND window_start < %d ORDER BY id ASC LIMIT %d",
			$agent_id, $cutoff, self::CLEANUP_LIMIT
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}
}
