<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MAD4B_SCP_Audit {

	const OPTION = 'mad4b_scp_audit_log';
	const LIMIT  = 200;
	const SUMMARY_MAX_DEPTH = 3;
	const SUMMARY_MAX_ITEMS = 50;
	private static $request_id = '';

	public static function record( $ability, array $summary, $status = 'ok' ) {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) $log = array();
		$previous_hash = '';
		if ( $log ) {
			$previous = end( $log );
			if ( is_array( $previous ) && isset( $previous['entry_hash'] ) ) $previous_hash = (string) $previous['entry_hash'];
		}

		$entry = array(
			'time'          => gmdate( 'c' ),
			'request_id'    => self::request_id(),
			'user_id'       => get_current_user_id(),
			'ability'       => sanitize_text_field( (string) $ability ),
			'status'        => sanitize_key( (string) $status ),
			'summary'       => self::sanitize_summary( $summary ),
			'previous_hash' => $previous_hash,
		);
		$entry['entry_hash'] = hash( 'sha256', $previous_hash . '|' . wp_json_encode( $entry ) );

		$log[] = $entry;
		if ( count( $log ) > self::LIMIT ) {
			$log = array_slice( $log, -1 * self::LIMIT );
			if ( isset( $log[0] ) && is_array( $log[0] ) ) $log[0]['chain_truncated'] = true;
		}

		update_option( self::OPTION, $log, false );
		do_action( 'mad4b_scp_audit_recorded', $entry );

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[MAD4B SCP] ' . wp_json_encode( $entry ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public static function tail( $limit = 50 ) {
		$limit = max( 1, min( 200, absint( $limit ) ) );
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) return array();
		return array_values( array_slice( $log, -1 * $limit ) );
	}

	public static function verify_chain() {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) return false;
		$previous_hash = '';
		foreach ( $log as $index => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['entry_hash'] ) ) return false;
			if ( 0 === $index && ! empty( $entry['chain_truncated'] ) ) {
				$previous_hash = isset( $entry['previous_hash'] ) ? (string) $entry['previous_hash'] : '';
			} elseif ( ( isset( $entry['previous_hash'] ) ? (string) $entry['previous_hash'] : '' ) !== $previous_hash ) {
				return false;
			}
			$expected = $entry;
			$hash = (string) $expected['entry_hash'];
			unset( $expected['entry_hash'], $expected['chain_truncated'] );
			$calculated = hash( 'sha256', ( isset( $expected['previous_hash'] ) ? (string) $expected['previous_hash'] : '' ) . '|' . wp_json_encode( $expected ) );
			if ( ! hash_equals( $hash, $calculated ) ) return false;
			$previous_hash = $hash;
		}
		return true;
	}

	private static function request_id() {
		if ( '' !== self::$request_id ) return self::$request_id;
		$header = isset( $_SERVER['HTTP_X_REQUEST_ID'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUEST_ID'] ) ) : '';
		self::$request_id = $header && preg_match( '/^[A-Za-z0-9._:-]{8,100}$/', $header ) ? $header : wp_generate_uuid4();
		return self::$request_id;
	}

	private static function sanitize_summary( array $summary ) {
		return self::sanitize_summary_array( $summary, 0 );
	}

	private static function sanitize_summary_array( array $summary, $depth ) {
		if ( $depth > self::SUMMARY_MAX_DEPTH ) return array( '_truncated' => true );
		$clean = array();
		$count = 0;
		foreach ( $summary as $key => $value ) {
			if ( $count >= self::SUMMARY_MAX_ITEMS ) {
				$clean['_truncated'] = true;
				break;
			}
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) continue;
			if ( self::is_sensitive_summary_key( $key ) ) {
				$clean[ $key ] = '[REDACTED]';
				++$count;
				continue;
			}
			if ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitize_summary_array( $value, $depth + 1 );
				++$count;
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$string = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
				$clean[ $key ] = substr( sanitize_text_field( $string ), 0, 500 );
				++$count;
			}
		}
		return $clean;
	}

	private static function is_sensitive_summary_key( $key ) {
		return 1 === preg_match( '/(?:pass(?:word)?|secret|token|api[_-]?key|consumer[_-]?key|access[_-]?key|client[_-]?key|auth|credential|private[_-]?key|cookie|refresh[_-]?token|jwt|authorization)/i', (string) $key );
	}
}
