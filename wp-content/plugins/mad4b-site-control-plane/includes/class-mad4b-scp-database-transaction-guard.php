<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'MAD4B_SCP_Database_Topology' ) ) require_once __DIR__ . '/class-mad4b-scp-database-topology.php';
if ( ! class_exists( 'MAD4B_SCP_Database_Failure_Semantics' ) ) require_once __DIR__ . '/class-mad4b-scp-database-failure-semantics.php';
if ( ! class_exists( 'MAD4B_SCP_Entropy' ) ) require_once __DIR__ . '/class-mad4b-scp-entropy.php';

/**
 * Owns MAD4B database transactions without nesting into or implicitly committing
 * a caller-owned transaction. This class never grants authority.
 */
final class MAD4B_SCP_Database_Transaction_Guard {
	const CONTRACT = 'mad4b.database-transaction-guard.v1';
	private static $owned_token = '';
	private static $owned_scope = '';

	public static function transaction_state() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return new WP_Error( 'mad4b_database_transaction_state_unavailable', 'Database transaction state is unavailable.' );
		}
		$probe_entropy = MAD4B_SCP_Entropy::hex( 'database_transaction_probe', 8 );
		if ( is_wp_error( $probe_entropy ) ) {
			return new WP_Error( 'mad4b_database_transaction_probe_entropy_unavailable', 'Database transaction probe entropy is unavailable.' );
		}
		$probe = 'mad4b_tx_probe_' . $probe_entropy;

		// SAVEPOINT itself does not commit or roll back a caller transaction.
		// With no durable current transaction the savepoint does not survive to
		// RELEASE; with an active transaction RELEASE succeeds. This gives us a
		// portable MySQL/MariaDB nesting probe without PROCESS/performance_schema
		// privileges or MariaDB-only system variables.
		$previous = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$wpdb->last_error = '';
		$created = $wpdb->query( 'SAVEPOINT ' . $probe );
		$create_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
		if ( false === $created ) {
			if ( method_exists( $wpdb, 'suppress_errors' ) ) $wpdb->suppress_errors( (bool) $previous );
			return new WP_Error( 'mad4b_database_transaction_state_unavailable', 'Database savepoint transaction probe could not be created.', array( 'db_error' => substr( $create_error, 0, 191 ) ) );
		}

		$wpdb->last_error = '';
		$released = $wpdb->query( 'RELEASE SAVEPOINT ' . $probe );
		$release_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
		if ( method_exists( $wpdb, 'suppress_errors' ) ) $wpdb->suppress_errors( (bool) $previous );
		if ( false !== $released ) return 1;

		if ( preg_match( '/savepoint.+does not exist/i', $release_error ) ) return 0;
		return new WP_Error(
			'mad4b_database_transaction_state_unavailable',
			'Database savepoint transaction probe could not distinguish transaction state.',
			array( 'db_error' => substr( $release_error, 0, 191 ) )
		);
	}

	public static function assert_no_external_transaction() {
		if ( '' !== self::$owned_token ) return new WP_Error( 'mad4b_database_transaction_reentrant_denied', 'A MAD4B-owned transaction is already active in this process.' );
		$state = self::transaction_state();
		if ( is_wp_error( $state ) ) return $state;
		if ( 0 !== $state ) return new WP_Error( 'mad4b_database_nested_transaction_denied', 'A caller-owned database transaction is already active; MAD4B will not implicitly commit it.' );
		return true;
	}

	public static function assert_storage( array $required_table_keys = array(), $refresh = false ) {
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! method_exists( 'MAD4B_SCP_Schema', 'transactional_storage_status' ) ) {
			return new WP_Error( 'mad4b_database_transactional_storage_unavailable', 'Transactional storage status is unavailable.' );
		}
		$status = MAD4B_SCP_Schema::transactional_storage_status( $required_table_keys, $refresh );
		if ( ! is_array( $status ) || empty( $status['ready'] ) ) {
			return new WP_Error( 'mad4b_database_transactional_storage_not_ready', 'Required governance tables are not on certified transactional storage.' );
		}
		return $status;
	}

	public static function preflight( array $required_table_keys = array(), $refresh = false ) {
		$ownership = self::assert_no_external_transaction();
		if ( is_wp_error( $ownership ) ) return $ownership;
		if ( class_exists( 'MAD4B_SCP_Runtime_Generation_Fence' ) ) {
			$runtime_generation = MAD4B_SCP_Runtime_Generation_Fence::assert_current();
			if ( is_wp_error( $runtime_generation ) ) return $runtime_generation;
		}
		$topology = MAD4B_SCP_Database_Topology::assert_write_ready( true );
		if ( is_wp_error( $topology ) ) return $topology;
		$storage = self::assert_storage( $required_table_keys, $refresh );
		if ( is_wp_error( $storage ) ) return $storage;
		return array(
			'contract' => self::CONTRACT,
			'storage_contract' => isset( $storage['contract'] ) ? (string) $storage['contract'] : '',
			'connection_fingerprint' => isset( $storage['connection_fingerprint'] ) ? (string) $storage['connection_fingerprint'] : '',
			'database_topology' => $topology,
			'nested_transaction' => false,
			'authorizing' => false,
		);
	}

	public static function begin( $scope, array $required_table_keys = array(), $refresh = false ) {
		global $wpdb;
		$preflight = self::preflight( $required_table_keys, $refresh );
		if ( is_wp_error( $preflight ) ) return $preflight;
		$scope = sanitize_key( (string) $scope );
		if ( '' === $scope ) return new WP_Error( 'mad4b_database_transaction_scope_invalid', 'Database transaction scope is required.' );
		$token = MAD4B_SCP_Entropy::hex( 'database_transaction_ownership', 16 );
		if ( is_wp_error( $token ) ) {
			return new WP_Error( 'mad4b_database_transaction_token_unavailable', 'Database transaction ownership token could not be generated.' );
		}
		$wpdb->last_error = '';
		$started = $wpdb->query( 'START TRANSACTION' );
		if ( false === $started ) {
			return MAD4B_SCP_Database_Failure_Semantics::error(
				'mad4b_database_transaction_begin_failed',
				'Database transaction could not be started.',
				'transaction_begin',
				isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '',
				null
			);
		}
		$state = self::transaction_state();
		if ( is_wp_error( $state ) || 1 !== $state ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'mad4b_database_transaction_begin_unverified', 'Database transaction start could not be verified.' );
		}
		$writer = MAD4B_SCP_Database_Topology::assert_same_writer( isset( $preflight['database_topology'] ) && is_array( $preflight['database_topology'] ) ? $preflight['database_topology'] : array() );
		if ( is_wp_error( $writer ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $writer;
		}
		self::$owned_token = $token;
		self::$owned_scope = $scope;
		return array(
			'contract' => self::CONTRACT,
			'token' => $token,
			'scope' => $scope,
			'connection_fingerprint' => isset( $preflight['connection_fingerprint'] ) ? (string) $preflight['connection_fingerprint'] : '',
			'database_topology' => isset( $preflight['database_topology'] ) && is_array( $preflight['database_topology'] ) ? $preflight['database_topology'] : array(),
			'authorizing' => false,
		);
	}

	public static function commit( array $lease ) {
		global $wpdb;
		$valid = self::validate_lease( $lease );
		if ( is_wp_error( $valid ) ) return $valid;
		$writer = MAD4B_SCP_Database_Topology::assert_same_writer( isset( $lease['database_topology'] ) && is_array( $lease['database_topology'] ) ? $lease['database_topology'] : array() );
		if ( is_wp_error( $writer ) ) {
			self::clear_owned();
			return $writer;
		}
		$state = self::transaction_state();
		if ( is_wp_error( $state ) || 1 !== $state ) {
			self::clear_owned();
			return new WP_Error( 'mad4b_database_transaction_commit_state_lost', 'Owned transaction state was lost before commit; outcome requires reconciliation.' );
		}
		$wpdb->last_error = '';
		$committed = $wpdb->query( 'COMMIT' );
		$commit_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
		self::clear_owned();
		if ( false === $committed ) {
			return MAD4B_SCP_Database_Failure_Semantics::error(
				'mad4b_database_transaction_commit_uncertain',
				'Database commit result is uncertain and requires reconciliation.',
				'transaction_commit',
				$commit_error,
				false,
				array( 'reconciliation_required' => true, 'persistence_state' => 'unknown' )
			);
		}
		$after = self::transaction_state();
		if ( is_wp_error( $after ) || 0 !== $after ) return new WP_Error( 'mad4b_database_transaction_commit_unverified', 'Database commit could not be verified.' );
		return true;
	}

	public static function rollback( array $lease ) {
		global $wpdb;
		$valid = self::validate_lease( $lease );
		if ( is_wp_error( $valid ) ) return $valid;
		$writer = MAD4B_SCP_Database_Topology::assert_same_writer( isset( $lease['database_topology'] ) && is_array( $lease['database_topology'] ) ? $lease['database_topology'] : array() );
		if ( is_wp_error( $writer ) ) {
			self::clear_owned();
			return $writer;
		}
		$state = self::transaction_state();
		if ( is_wp_error( $state ) ) {
			self::clear_owned();
			return $state;
		}
		if ( 1 !== $state ) {
			self::clear_owned();
			return new WP_Error( 'mad4b_database_transaction_rollback_state_lost', 'Owned transaction disappeared before rollback verification.' );
		}
		$wpdb->last_error = '';
		$rolled_back = $wpdb->query( 'ROLLBACK' );
		$rollback_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
		self::clear_owned();
		if ( false === $rolled_back ) {
			return MAD4B_SCP_Database_Failure_Semantics::error(
				'mad4b_database_transaction_rollback_failed',
				'Database rollback failed.',
				'transaction_rollback',
				$rollback_error,
				false,
				array( 'reconciliation_required' => true, 'persistence_state' => 'unknown' )
			);
		}
		$after = self::transaction_state();
		if ( is_wp_error( $after ) || 0 !== $after ) return new WP_Error( 'mad4b_database_transaction_rollback_unverified', 'Database rollback could not be verified.' );
		return true;
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'owned_transaction_active' => '' !== self::$owned_token,
			'owned_scope' => self::$owned_scope,
			'nested_transaction_policy' => 'deny',
			'implicit_commit_allowed' => false,
			'authorizing' => false,
		);
	}

	private static function validate_lease( array $lease ) {
		if ( '' === self::$owned_token
			|| self::CONTRACT !== ( isset( $lease['contract'] ) ? (string) $lease['contract'] : '' )
			|| empty( $lease['token'] )
			|| ! hash_equals( self::$owned_token, (string) $lease['token'] )
			|| ! isset( $lease['scope'] )
			|| ! hash_equals( self::$owned_scope, (string) $lease['scope'] ) ) {
			return new WP_Error( 'mad4b_database_transaction_ownership_invalid', 'Database transaction ownership proof is invalid.' );
		}
		return true;
	}

	private static function clear_owned() {
		self::$owned_token = '';
		self::$owned_scope = '';
	}
}
