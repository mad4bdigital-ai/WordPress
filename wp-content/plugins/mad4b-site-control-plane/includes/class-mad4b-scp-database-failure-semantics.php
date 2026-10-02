<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Normalizes database failure semantics without automatically retrying writes.
 */
final class MAD4B_SCP_Database_Failure_Semantics {
	const CONTRACT = 'mad4b.database-failure-semantics.v1';

	public static function classify( $phase, $message, $rollback_verified = null ) {
		$phase = sanitize_key( (string) $phase );
		$message = trim( (string) $message );
		$lower = strtolower( $message );
		$kind = 'database_error';
		if ( preg_match( '/deadlock found|deadlock victim|try restarting transaction/i', $message ) ) $kind = 'deadlock';
		elseif ( preg_match( '/lock wait timeout|lock timeout exceeded/i', $message ) ) $kind = 'lock_wait_timeout';
		elseif ( preg_match( '/server has gone away|lost connection|connection.*closed|not connected|connection was killed|error while sending query packet/i', $message ) ) $kind = 'connection_loss';
		elseif ( preg_match( '/transaction.*aborted|transaction.*rolled back|no active transaction|savepoint.+does not exist/i', $message ) ) $kind = 'transaction_aborted';

		$rollback_known = true === $rollback_verified || false === $rollback_verified;
		$rolled_back = true === $rollback_verified;
		$connection_uncertain = 'connection_loss' === $kind || 'transaction_aborted' === $kind;
		$reconciliation_required = $connection_uncertain || ! $rollback_known || ! $rolled_back;
		if ( in_array( $kind, array( 'deadlock', 'lock_wait_timeout' ), true ) && $rolled_back ) $reconciliation_required = false;

		return array(
			'contract' => self::CONTRACT,
			'phase' => $phase,
			'failure_class' => $kind,
			'failure_fingerprint' => hash( 'sha256', $phase . "\n" . $kind . "\n" . $lower ),
			'persistence_state' => $reconciliation_required ? 'unknown' : 'rolled_back_or_not_applied',
			'rollback_verified' => $rollback_known ? $rolled_back : null,
			'reconciliation_required' => $reconciliation_required,
			'blind_retry_allowed' => false,
			'automatic_retry_allowed' => false,
			'fresh_plan_required' => in_array( $kind, array( 'deadlock', 'lock_wait_timeout' ), true ),
			'client_action' => $reconciliation_required ? 'reconcile_database_state_before_any_retry' : 'replan_then_retry_if_still_authorized',
			'authorizing' => false,
		);
	}

	public static function from_wpdb( $phase, $rollback_verified = null ) {
		global $wpdb;
		$message = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
		return self::classify( $phase, $message, $rollback_verified );
	}

	public static function error( $code, $message, $phase, $db_error = '', $rollback_verified = null, array $extra = array() ) {
		$data = array_merge( self::classify( $phase, $db_error, $rollback_verified ), $extra );
		if ( '' !== trim( (string) $db_error ) ) $data['db_error'] = substr( trim( (string) $db_error ), 0, 191 );
		return new WP_Error( sanitize_key( (string) $code ), (string) $message, $data );
	}
}
