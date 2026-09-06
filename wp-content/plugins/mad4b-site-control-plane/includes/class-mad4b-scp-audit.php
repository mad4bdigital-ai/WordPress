<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MAD4B_SCP_Audit {

	const OPTION = 'mad4b_scp_audit_log';
	const LIMIT  = 200;

	public static function record( $ability, array $summary, $status = 'ok' ) {
		$entry = array(
			'time'    => gmdate( 'c' ),
			'user_id' => get_current_user_id(),
			'ability' => sanitize_text_field( (string) $ability ),
			'status'  => sanitize_key( (string) $status ),
			'summary' => self::sanitize_summary( $summary ),
		);

		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = $entry;
		if ( count( $log ) > self::LIMIT ) {
			$log = array_slice( $log, -1 * self::LIMIT );
		}

		update_option( self::OPTION, $log, false );

		do_action( 'mad4b_scp_audit_recorded', $entry );

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[MAD4B SCP] ' . wp_json_encode( $entry ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public static function tail( $limit = 50 ) {
		$limit = max( 1, min( 200, absint( $limit ) ) );
		$log   = get_option( self::OPTION, array() );

		if ( ! is_array( $log ) ) {
			return array();
		}

		return array_values( array_slice( $log, -1 * $limit ) );
	}

	private static function sanitize_summary( array $summary ) {
		$clean = array();
		foreach ( $summary as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( is_scalar( $value ) || null === $value ) {
				$string        = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
				$clean[ $key ] = substr( sanitize_text_field( $string ), 0, 500 );
			}
		}
		return $clean;
	}
}
