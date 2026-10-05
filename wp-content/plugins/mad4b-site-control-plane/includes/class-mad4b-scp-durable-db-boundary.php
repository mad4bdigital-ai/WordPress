<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Durable_DB_Boundary {
	public static function restore_epoch_preflight( $surface ) {
		if ( class_exists( 'MAD4B_SCP_Database_Transaction_Guard' ) && method_exists( 'MAD4B_SCP_Database_Transaction_Guard', 'assert_no_external_transaction' ) ) {
			$ownership = MAD4B_SCP_Database_Transaction_Guard::assert_no_external_transaction();
			if ( is_wp_error( $ownership ) ) return $ownership;
		}
		if ( ! class_exists( 'MAD4B_SCP_Restore_Epoch' ) ) return new WP_Error( 'mad4b_durable_restore_epoch_unavailable', 'Durable execution requires the restore/authority epoch contract.', array( 'surface' => sanitize_key( (string) $surface ), 'blind_retry_allowed' => false ) );
		$status = MAD4B_SCP_Restore_Epoch::ensure_bound();
		if ( is_wp_error( $status ) ) return new WP_Error( 'mad4b_durable_restore_epoch_quarantined', 'Durable state is quarantined because the database restore/authority epoch is not current.', array( 'surface' => sanitize_key( (string) $surface ), 'cause' => $status->get_error_code(), 'reconciliation_required' => true, 'blind_retry_allowed' => false ) );
		return $status;
	}
	public static function write_topology_preflight() {
		if ( ! class_exists( 'MAD4B_SCP_Database_Topology' ) ) return new WP_Error( 'mad4b_database_topology_unavailable', 'Database topology service is unavailable.' );
		return MAD4B_SCP_Database_Topology::assert_write_ready( true );
	}
	public static function same_writer_after_write( array $topology, $phase ) {
		$same = MAD4B_SCP_Database_Topology::assert_same_writer( $topology );
		if ( ! is_wp_error( $same ) ) return $same;
		return new WP_Error( 'mad4b_durable_persistence_uncertain', 'Durable write completed but same-writer readback could not be proven.', array( 'phase' => sanitize_key( (string) $phase ), 'cause' => $same->get_error_code(), 'persistence_state' => 'unknown', 'reconciliation_required' => true, 'blind_retry_allowed' => false, 'client_action' => 'reconcile_database_state_before_any_retry' ) );
	}
	public static function database_write_failure( $code, $message, $phase, $db_error, array $extra = array() ) {
		$failure = MAD4B_SCP_Database_Failure_Semantics::classify( $phase, $db_error, null );
		$failure = array_merge( $failure, $extra );
		$final_code = ! empty( $failure['reconciliation_required'] ) ? 'mad4b_durable_persistence_uncertain' : sanitize_key( (string) $code );
		$failure['original_error_code'] = sanitize_key( (string) $code );
		if ( '' !== trim( (string) $db_error ) ) $failure['db_error'] = substr( trim( (string) $db_error ), 0, 191 );
		return new WP_Error( $final_code, (string) $message, $failure );
	}
	public static function authoritative_read_failure( $phase, $db_error ) {
		return new WP_Error( 'mad4b_durable_authoritative_read_unavailable', 'Authoritative durable state could not be read from the certified writer.', array( 'phase' => sanitize_key( (string) $phase ), 'db_error' => substr( trim( (string) $db_error ), 0, 191 ), 'reconciliation_required' => false, 'blind_retry_allowed' => false, 'fresh_plan_required' => true, 'client_action' => 'restore_authoritative_database_read_then_replan' ) );
	}
	public static function begin_owned_transaction( $scope, array $table_keys ) {
		if ( ! class_exists( 'MAD4B_SCP_Database_Transaction_Guard' ) ) return new WP_Error( 'mad4b_durable_transaction_guard_unavailable', 'Durable execution transaction guard is unavailable.' );
		return MAD4B_SCP_Database_Transaction_Guard::begin( $scope, $table_keys, true );
	}
	public static function commit_owned_transaction( array $transaction, $phase ) {
		$result = MAD4B_SCP_Database_Transaction_Guard::commit( $transaction );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data(); $data = is_array( $data ) ? $data : array();
			throw new MAD4B_SCP_Durable_DB_Boundary_Exception( new WP_Error( 'mad4b_durable_persistence_uncertain', 'Durable database commit could not be proven; reconcile authoritative state before any retry.', array_merge( $data, array( 'phase' => sanitize_key( (string) $phase ), 'cause' => $result->get_error_code(), 'persistence_state' => 'unknown', 'reconciliation_required' => true, 'blind_retry_allowed' => false, 'client_action' => 'reconcile_database_state_before_any_retry' ) ) ) );
		}
		return true;
	}
	public static function rollback_owned_transaction( array $transaction, $phase ) {
		$result = MAD4B_SCP_Database_Transaction_Guard::rollback( $transaction );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data(); $data = is_array( $data ) ? $data : array();
			throw new MAD4B_SCP_Durable_DB_Boundary_Exception( new WP_Error( 'mad4b_durable_rollback_uncertain', 'Durable database rollback could not be proven; reconcile authoritative state before any retry.', array_merge( $data, array( 'phase' => sanitize_key( (string) $phase ), 'cause' => $result->get_error_code(), 'persistence_state' => 'unknown', 'reconciliation_required' => true, 'blind_retry_allowed' => false, 'client_action' => 'reconcile_database_state_before_any_retry' ) ) ) );
		}
		return true;
	}
	public static function transaction_failure( array $transaction, $phase, $error_code, $message, $throwable ) {
		global $wpdb;
		$db_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
		$rollback = MAD4B_SCP_Database_Transaction_Guard::rollback( $transaction );
		$rollback_verified = true === $rollback;
		$failure = MAD4B_SCP_Database_Failure_Semantics::classify( $phase, trim( $db_error . ' ' . ( $throwable instanceof Throwable ? $throwable->getMessage() : '' ) ), $rollback_verified );
		if ( is_wp_error( $rollback ) ) {
			$failure['rollback_error'] = $rollback->get_error_code(); $failure['rollback_verified'] = false;
			if ( empty( $failure['server_transaction_rollback_guaranteed'] ) ) { $failure['persistence_state'] = 'unknown'; $failure['reconciliation_required'] = true; $failure['client_action'] = 'reconcile_database_state_before_any_retry'; }
		}
		$failure['cause'] = $throwable instanceof Throwable ? substr( $throwable->getMessage(), 0, 160 ) : 'database_failure';
		$failure['original_error_code'] = sanitize_key( (string) $error_code );
		$final_code = ! empty( $failure['reconciliation_required'] ) ? 'mad4b_durable_persistence_uncertain' : sanitize_key( (string) $error_code );
		return new WP_Error( $final_code, (string) $message, $failure );
	}
}
