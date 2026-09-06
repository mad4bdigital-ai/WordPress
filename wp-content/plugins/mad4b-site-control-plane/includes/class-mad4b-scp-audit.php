<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-audit-integrity.php';

final class MAD4B_SCP_Audit {
	const LEGACY_OPTION = 'mad4b_scp_audit_log';
	const CHAIN = 'primary';
	const CONTRACT = 'mad4b.audit.v2';
	const SUMMARY_MAX_DEPTH = 3;
	const SUMMARY_MAX_ITEMS = 50;
	const SUMMARY_MAX_BYTES = 65536;
	const VERIFY_BATCH = 500;

	private static $request_id = '';
	private static $pending_dispatch = array();
	private static $shutdown_registered = false;

	public static function record( $ability, array $summary, $status = 'ok', $join_transaction = false ) {
		global $wpdb;

		$storage = self::storage_status();
		if ( empty( $storage['ready'] ) ) {
			return new WP_Error(
				'mad4b_audit_storage_unavailable',
				'Append-only audit storage is unavailable or inconsistent.',
				array( 'audit' => $storage )
			);
		}

		$summary_payload = self::summary_payload( $summary );
		if ( is_wp_error( $summary_payload ) ) return $summary_payload;

		$join_transaction = (bool) $join_transaction || self::database_transaction_open();

		$owns_transaction = ! $join_transaction;
		if ( $owns_transaction ) {
			$started = $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( false === $started ) {
				return new WP_Error( 'mad4b_audit_transaction_start_failed', 'Unable to start append-only audit transaction.', array( 'db_error' => $wpdb->last_error ) );
			}
		}

		$entry = self::append_locked(
			sanitize_text_field( (string) $ability ),
			$summary_payload['summary'],
			$summary_payload['json'],
			sanitize_key( (string) $status )
		);
		if ( is_wp_error( $entry ) ) {
			if ( $owns_transaction ) $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return $entry;
		}

		if ( $owns_transaction ) {
			$committed = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( false === $committed ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				return new WP_Error( 'mad4b_audit_commit_failed', 'Unable to commit append-only audit event.', array( 'db_error' => $wpdb->last_error ) );
			}
			self::dispatch_committed( $entry );
		} else {
			self::$pending_dispatch[] = $entry;
			self::register_shutdown_dispatch();
		}

		return $entry;
	}

	public static function transaction_committed() {
		$entries = self::$pending_dispatch;
		self::$pending_dispatch = array();
		foreach ( $entries as $entry ) self::dispatch_committed( $entry );
	}

	public static function transaction_rolled_back() {
		self::$pending_dispatch = array();
	}

	public static function dispatch_pending_after_transaction() {
		global $wpdb;
		if ( self::database_transaction_open() ) return;
		$entries = self::$pending_dispatch;
		self::$pending_dispatch = array();
		if ( ! $entries || ! class_exists( 'MAD4B_SCP_Schema' ) ) return;
		$t = MAD4B_SCP_Schema::tables();
		foreach ( $entries as $entry ) {
			$event_id = isset( $entry['event_id'] ) ? (string) $entry['event_id'] : '';
			if ( '' === $event_id ) continue;
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$t['audit_events']} WHERE event_id = %s LIMIT 1", $event_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( $exists === $event_id ) self::dispatch_committed( $entry );
		}
	}

	private static function register_shutdown_dispatch() {
		if ( self::$shutdown_registered ) return;
		self::$shutdown_registered = true;
		register_shutdown_function( array( __CLASS__, 'dispatch_pending_after_transaction' ) );
	}

	private static function database_transaction_open() {
		global $wpdb;
		$state = $wpdb->get_var( 'SELECT @@session.in_transaction' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( null === $state && $wpdb->last_error ) {
			$wpdb->last_error = '';
			$state = $wpdb->get_var( 'SELECT @@in_transaction' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}
		return 1 === (int) $state;
	}

	public static function storage_status() {
		return MAD4B_SCP_Audit_Integrity::storage_status();
	}

	public static function verify_chain() {
		return MAD4B_SCP_Audit_Integrity::verify_chain();
	}

	public static function tail( $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( 200, absint( $limit ) ) );
		$status = self::storage_status();
		if ( empty( $status['tables_ready'] ) ) return array();
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t['audit_events']} WHERE chain_name = %s ORDER BY sequence DESC LIMIT %d",
				self::CHAIN,
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! is_array( $rows ) ) return array();
		$rows = array_reverse( $rows );
		$out = array();
		foreach ( $rows as $row ) {
			$entry = MAD4B_SCP_Audit_Integrity::row_to_entry( $row );
			if ( is_array( $entry ) ) $out[] = $entry;
		}
		return $out;
	}

	private static function append_locked( $ability, array $summary, $summary_json, $status ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$head = self::lock_or_create_head();
		if ( is_wp_error( $head ) ) return $head;

		$sequence = (int) $head['sequence'] + 1;
		$previous_hash = (string) $head['entry_hash'];
		$event_id = wp_generate_uuid4();
		$occurred_at = gmdate( 'c' );
		$created_at = gmdate( 'Y-m-d H:i:s' );
		$entry = array(
			'contract' => self::CONTRACT,
			'chain' => self::CHAIN,
			'sequence' => $sequence,
			'event_id' => $event_id,
			'time' => $occurred_at,
			'request_id' => self::request_id(),
			'user_id' => get_current_user_id(),
			'ability' => $ability,
			'status' => $status,
			'summary' => $summary,
			'previous_hash' => $previous_hash,
		);
		$entry['entry_hash'] = MAD4B_SCP_Audit_Integrity::entry_hash( $entry );

		$inserted = $wpdb->insert(
			$t['audit_events'],
			array(
				'chain_name' => self::CHAIN,
				'sequence' => $sequence,
				'event_id' => $event_id,
				'occurred_at' => $occurred_at,
				'request_id' => $entry['request_id'],
				'user_id' => $entry['user_id'],
				'ability' => $ability,
				'status' => $status,
				'summary_json' => $summary_json,
				'previous_hash' => $previous_hash,
				'entry_hash' => $entry['entry_hash'],
				'created_at' => $created_at,
			),
			array( '%s','%d','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $inserted ) {
			return new WP_Error( 'mad4b_audit_append_failed', 'Unable to append audit event.', array( 'db_error' => $wpdb->last_error ) );
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t['audit_heads']} SET sequence = %d, entry_hash = %s, updated_at = %s WHERE chain_name = %s AND sequence = %d AND entry_hash = %s",
				$sequence,
				$entry['entry_hash'],
				$created_at,
				self::CHAIN,
				(int) $head['sequence'],
				$previous_hash
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'mad4b_audit_head_conflict', 'Audit chain head changed unexpectedly during append.', array( 'db_error' => $wpdb->last_error ) );
		}

		return $entry;
	}

	private static function lock_or_create_head() {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$legacy = MAD4B_SCP_Audit_Integrity::legacy_snapshot();
		if ( empty( $legacy['chain_valid'] ) ) {
			return new WP_Error( 'mad4b_audit_legacy_chain_invalid', 'Legacy audit history failed integrity verification.' );
		}
		$now = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$t['audit_heads']} (chain_name,sequence,entry_hash,legacy_anchor_sha256,legacy_chain_valid,legacy_entry_count,created_at,updated_at) VALUES (%s,0,%s,%s,%d,%d,%s,%s)",
				self::CHAIN,
				$legacy['anchor_sha256'],
				$legacy['anchor_sha256'],
				$legacy['chain_valid'] ? 1 : 0,
				(int) $legacy['entry_count'],
				$now,
				$now
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $inserted ) {
			return new WP_Error( 'mad4b_audit_head_create_failed', 'Unable to initialize audit chain head.', array( 'db_error' => $wpdb->last_error ) );
		}

		$head = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t['audit_heads']} WHERE chain_name = %s LIMIT 1 FOR UPDATE",
				self::CHAIN
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! $head ) {
			return new WP_Error( 'mad4b_audit_head_lock_failed', 'Unable to lock audit chain head.', array( 'db_error' => $wpdb->last_error ) );
		}
		if ( empty( $head['legacy_chain_valid'] ) ) {
			return new WP_Error( 'mad4b_audit_legacy_chain_invalid', 'Stored legacy audit anchor is marked invalid.' );
		}
		if ( ! hash_equals( (string) $head['legacy_anchor_sha256'], (string) $legacy['anchor_sha256'] ) ) {
			return new WP_Error( 'mad4b_audit_legacy_anchor_drift', 'Legacy audit history changed after append-only migration.' );
		}
		return $head;
	}

	private static function summary_payload( array $summary ) {
		$clean = self::sanitize_summary_array( $summary, 0 );
		$json = wp_json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) return new WP_Error( 'mad4b_audit_summary_invalid', 'Audit summary could not be encoded.' );
		if ( strlen( $json ) > self::SUMMARY_MAX_BYTES ) {
			$clean = array(
				'_truncated' => true,
				'_original_bytes' => strlen( $json ),
				'_sha256' => hash( 'sha256', $json ),
			);
			$json = wp_json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		return array( 'summary' => $clean, 'json' => $json );
	}

	private static function request_id() {
		if ( '' !== self::$request_id ) return self::$request_id;
		$header = isset( $_SERVER['HTTP_X_REQUEST_ID'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUEST_ID'] ) ) : '';
		self::$request_id = $header && preg_match( '/^[A-Za-z0-9._:-]{8,100}$/', $header ) ? $header : wp_generate_uuid4();
		return self::$request_id;
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

	private static function dispatch_committed( array $entry ) {
		try {
			do_action( 'mad4b_scp_audit_recorded', $entry );
			do_action( 'mad4b_scp_audit_committed', $entry );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[MAD4B SCP] Audit sink error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[MAD4B SCP] ' . wp_json_encode( $entry ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
