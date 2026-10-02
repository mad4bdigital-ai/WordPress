<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

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
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return new WP_Error( 'mad4b_database_transaction_state_unavailable', 'Database transaction state is unavailable.' );
		}
		$value = $wpdb->get_var( 'SELECT @@session.in_transaction' );
		if ( null === $value || false === $value || ! is_numeric( $value ) ) {
			return new WP_Error( 'mad4b_database_transaction_state_unavailable', 'Database transaction state could not be verified.' );
		}
		return 0 === (int) $value ? 0 : 1;
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
		$storage = self::assert_storage( $required_table_keys, $refresh );
		if ( is_wp_error( $storage ) ) return $storage;
		if ( '' !== self::$owned_token ) return new WP_Error( 'mad4b_database_transaction_reentrant_denied', 'A MAD4B-owned transaction is already active in this process.' );
		$state = self::transaction_state();
		if ( is_wp_error( $state ) ) return $state;
		if ( 0 !== $state ) return new WP_Error( 'mad4b_database_nested_transaction_denied', 'A caller-owned database transaction is already active; MAD4B will not implicitly commit it.' );
		return array(
			'contract' => self::CONTRACT,
			'storage_contract' => isset( $storage['contract'] ) ? (string) $storage['contract'] : '',
			'connection_fingerprint' => isset( $storage['connection_fingerprint'] ) ? (string) $storage['connection_fingerprint'] : '',
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
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $error ) {
			return new WP_Error( 'mad4b_database_transaction_token_unavailable', 'Database transaction ownership token could not be generated.' );
		}
		$started = $wpdb->query( 'START TRANSACTION' );
		if ( false === $started ) return new WP_Error( 'mad4b_database_transaction_begin_failed', 'Database transaction could not be started.' );
		$state = self::transaction_state();
		if ( is_wp_error( $state ) || 1 !== $state ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'mad4b_database_transaction_begin_unverified', 'Database transaction start could not be verified.' );
		}
		self::$owned_token = $token;
		self::$owned_scope = $scope;
		return array(
			'contract' => self::CONTRACT,
			'token' => $token,
			'scope' => $scope,
			'connection_fingerprint' => isset( $preflight['connection_fingerprint'] ) ? (string) $preflight['connection_fingerprint'] : '',
			'authorizing' => false,
		);
	}

	public static function commit( array $lease ) {
		global $wpdb;
		$valid = self::validate_lease( $lease );
		if ( is_wp_error( $valid ) ) return $valid;
		$state = self::transaction_state();
		if ( is_wp_error( $state ) || 1 !== $state ) {
			self::clear_owned();
			return new WP_Error( 'mad4b_database_transaction_commit_state_lost', 'Owned transaction state was lost before commit; outcome requires reconciliation.' );
		}
		$committed = $wpdb->query( 'COMMIT' );
		self::clear_owned();
		if ( false === $committed ) return new WP_Error( 'mad4b_database_transaction_commit_uncertain', 'Database commit result is uncertain and requires reconciliation.' );
		$after = self::transaction_state();
		if ( is_wp_error( $after ) || 0 !== $after ) return new WP_Error( 'mad4b_database_transaction_commit_unverified', 'Database commit could not be verified.' );
		return true;
	}

	public static function rollback( array $lease ) {
		global $wpdb;
		$valid = self::validate_lease( $lease );
		if ( is_wp_error( $valid ) ) return $valid;
		$state = self::transaction_state();
		if ( is_wp_error( $state ) ) {
			self::clear_owned();
			return $state;
		}
		if ( 1 !== $state ) {
			self::clear_owned();
			return new WP_Error( 'mad4b_database_transaction_rollback_state_lost', 'Owned transaction disappeared before rollback verification.' );
		}
		$rolled_back = $wpdb->query( 'ROLLBACK' );
		self::clear_owned();
		if ( false === $rolled_back ) return new WP_Error( 'mad4b_database_transaction_rollback_failed', 'Database rollback failed.' );
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
