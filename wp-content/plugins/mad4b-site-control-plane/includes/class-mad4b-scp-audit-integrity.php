<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MAD4B_SCP_Audit_Integrity {
	public static function storage_status() {
		global $wpdb;

		if ( ! class_exists( 'MAD4B_SCP_Schema' ) ) {
			return array(
				'ready' => false,
				'tables_ready' => false,
				'transactional' => false,
				'legacy_chain_valid' => false,
				'legacy_anchor_match' => false,
				'head_consistent' => false,
			);
		}

		$t = MAD4B_SCP_Schema::tables();
		if ( empty( $t['audit_events'] ) || empty( $t['audit_heads'] ) ) {
			return array(
				'ready' => false,
				'tables_ready' => false,
				'transactional' => false,
				'legacy_chain_valid' => false,
				'legacy_anchor_match' => false,
				'head_consistent' => false,
			);
		}

		$events_exists = self::table_exists( $t['audit_events'] );
		$heads_exists = self::table_exists( $t['audit_heads'] );
		$tables_ready = $events_exists && $heads_exists;
		$transactional = $tables_ready && self::transactional_table( $t['audit_events'] ) && self::transactional_table( $t['audit_heads'] );
		$legacy = self::legacy_snapshot();
		$snapshot = $tables_ready ? self::committed_snapshot( $t['audit_heads'], $t['audit_events'] ) : array();

		$event_count = $snapshot ? (int) $snapshot['event_count'] : 0;
		$head_initialized = $snapshot && null !== $snapshot['head_sequence'];
		$head_consistent = true;
		$legacy_anchor_match = true;
		$head_sequence = $head_initialized ? (int) $snapshot['head_sequence'] : 0;
		$head_entry_hash = $head_initialized ? (string) $snapshot['head_entry_hash'] : (string) $legacy['anchor_sha256'];

		if ( $head_initialized ) {
			$legacy_anchor_match = isset( $snapshot['legacy_anchor_sha256'] )
				&& hash_equals( (string) $snapshot['legacy_anchor_sha256'], (string) $legacy['anchor_sha256'] );
			$stored_legacy_valid = ! empty( $snapshot['legacy_chain_valid'] );
			if ( $stored_legacy_valid !== (bool) $legacy['chain_valid'] ) $legacy_anchor_match = false;

			if ( 0 === $head_sequence ) {
				$head_consistent = 0 === $event_count
					&& null === $snapshot['last_sequence']
					&& hash_equals( (string) $legacy['anchor_sha256'], $head_entry_hash );
			} else {
				$head_consistent = null !== $snapshot['last_sequence']
					&& (int) $snapshot['last_sequence'] === $head_sequence
					&& hash_equals( (string) $snapshot['last_entry_hash'], $head_entry_hash )
					&& $event_count === $head_sequence;
			}
		} elseif ( $event_count > 0 ) {
			$head_consistent = false;
		}

		$ready = $tables_ready && $transactional && ! empty( $legacy['chain_valid'] ) && $legacy_anchor_match && $head_consistent;

		return array(
			'contract' => MAD4B_SCP_Audit::CONTRACT,
			'chain' => MAD4B_SCP_Audit::CHAIN,
			'ready' => $ready,
			'append_only' => true,
			'tables_ready' => $tables_ready,
			'transactional' => $transactional,
			'legacy_chain_valid' => (bool) $legacy['chain_valid'],
			'legacy_entry_count' => (int) $legacy['entry_count'],
			'legacy_anchor_sha256' => (string) $legacy['anchor_sha256'],
			'legacy_anchor_match' => $legacy_anchor_match,
			'head_initialized' => (bool) $head_initialized,
			'head_consistent' => $head_consistent,
			'head_sequence' => $head_sequence,
			'head_entry_hash' => $head_entry_hash,
			'event_count' => $event_count,
		);
	}

	/**
	 * Read head/count/tail in one SQL statement so an append commit cannot be
	 * observed half-before and half-after across independent autocommit reads.
	 */
	private static function committed_snapshot( $heads_table, $events_table ) {
		global $wpdb;
		$sql = "SELECT
			(SELECT sequence FROM {$heads_table} WHERE chain_name = %s LIMIT 1) AS head_sequence,
			(SELECT entry_hash FROM {$heads_table} WHERE chain_name = %s LIMIT 1) AS head_entry_hash,
			(SELECT legacy_anchor_sha256 FROM {$heads_table} WHERE chain_name = %s LIMIT 1) AS legacy_anchor_sha256,
			(SELECT legacy_chain_valid FROM {$heads_table} WHERE chain_name = %s LIMIT 1) AS legacy_chain_valid,
			(SELECT COUNT(*) FROM {$events_table} WHERE chain_name = %s) AS event_count,
			(SELECT sequence FROM {$events_table} WHERE chain_name = %s ORDER BY sequence DESC LIMIT 1) AS last_sequence,
			(SELECT entry_hash FROM {$events_table} WHERE chain_name = %s ORDER BY sequence DESC LIMIT 1) AS last_entry_hash";
		$row = $wpdb->get_row(
			$wpdb->prepare(
				$sql,
				MAD4B_SCP_Audit::CHAIN,
				MAD4B_SCP_Audit::CHAIN,
				MAD4B_SCP_Audit::CHAIN,
				MAD4B_SCP_Audit::CHAIN,
				MAD4B_SCP_Audit::CHAIN,
				MAD4B_SCP_Audit::CHAIN,
				MAD4B_SCP_Audit::CHAIN
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return is_array( $row ) ? $row : array();
	}

	public static function verify_chain() {
		global $wpdb;

		$status = self::storage_status();
		if ( empty( $status['ready'] ) ) return false;
		if ( empty( $status['head_initialized'] ) ) return 0 === (int) $status['event_count'];

		$t = MAD4B_SCP_Schema::tables();
		$target_sequence = (int) $status['head_sequence'];
		$target_hash = (string) $status['head_entry_hash'];
		$expected_sequence = 1;
		$previous_hash = (string) $status['legacy_anchor_sha256'];

		while ( $expected_sequence <= $target_sequence ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$t['audit_events']} WHERE chain_name = %s AND sequence >= %d AND sequence <= %d ORDER BY sequence ASC LIMIT %d",
					MAD4B_SCP_Audit::CHAIN,
					$expected_sequence,
					$target_sequence,
					MAD4B_SCP_Audit::VERIFY_BATCH
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( ! is_array( $rows ) || empty( $rows ) ) return false;

			foreach ( $rows as $row ) {
				if ( (int) $row['sequence'] !== $expected_sequence ) return false;
				if ( ! hash_equals( $previous_hash, (string) $row['previous_hash'] ) ) return false;
				$entry = self::row_to_entry( $row );
				if ( ! is_array( $entry ) ) return false;
				$calculated = self::entry_hash( $entry );
				if ( ! hash_equals( (string) $row['entry_hash'], $calculated ) ) return false;
				$previous_hash = (string) $row['entry_hash'];
				++$expected_sequence;
			}
		}

		if ( $expected_sequence - 1 !== $target_sequence ) return false;
		if ( ! hash_equals( $previous_hash, $target_hash ) ) return false;
		return true;
	}

	public static function entry_hash( array $entry ) {
		$payload = $entry;
		unset( $payload['entry_hash'] );
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', (string) $json );
	}

	public static function row_to_entry( array $row ) {
		$summary = json_decode( (string) $row['summary_json'], true );
		if ( ! is_array( $summary ) ) return null;
		return array(
			'contract' => MAD4B_SCP_Audit::CONTRACT,
			'chain' => (string) $row['chain_name'],
			'sequence' => (int) $row['sequence'],
			'event_id' => (string) $row['event_id'],
			'time' => (string) $row['occurred_at'],
			'request_id' => (string) $row['request_id'],
			'user_id' => (int) $row['user_id'],
			'ability' => (string) $row['ability'],
			'status' => (string) $row['status'],
			'summary' => $summary,
			'previous_hash' => (string) $row['previous_hash'],
			'entry_hash' => (string) $row['entry_hash'],
		);
	}

	public static function legacy_snapshot() {
		$raw = get_option( MAD4B_SCP_Audit::LEGACY_OPTION, false );
		$exists = false !== $raw;
		$log = $exists && is_array( $raw ) ? $raw : array();
		$valid = ! $exists || is_array( $raw );
		if ( $valid ) $valid = self::verify_legacy_log( $log );
		$json = wp_json_encode( $log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			$json = '[]';
			$valid = false;
		}
		$first_hash = '';
		$tail_hash = '';
		if ( $log ) {
			$first = reset( $log );
			$last = end( $log );
			$first_hash = is_array( $first ) && isset( $first['entry_hash'] ) ? (string) $first['entry_hash'] : '';
			$tail_hash = is_array( $last ) && isset( $last['entry_hash'] ) ? (string) $last['entry_hash'] : '';
		}
		$metadata = array(
			'contract' => 'mad4b.audit.legacy-anchor.v1',
			'exists' => $exists,
			'entry_count' => count( $log ),
			'chain_valid' => (bool) $valid,
			'first_hash' => $first_hash,
			'tail_hash' => $tail_hash,
			'log_sha256' => hash( 'sha256', $json ),
		);
		$anchor_json = wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$metadata['anchor_sha256'] = hash( 'sha256', (string) $anchor_json );
		return $metadata;
	}

	public static function verify_legacy_log( array $log ) {
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
			$calculated = hash(
				'sha256',
				( isset( $expected['previous_hash'] ) ? (string) $expected['previous_hash'] : '' ) . '|' . wp_json_encode( $expected )
			);
			if ( ! hash_equals( $hash, $calculated ) ) return false;
			$previous_hash = $hash;
		}
		return true;
	}

	public static function table_exists( $table ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $found === $table;
	}

	public static function transactional_table( $table ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
		$engine = $row && isset( $row['Engine'] ) ? strtolower( (string) $row['Engine'] ) : '';
		return in_array( $engine, array( 'innodb', 'xtradb' ), true );
	}
}
