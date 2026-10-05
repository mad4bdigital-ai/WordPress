<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Conservative writer/readback topology profile for governed database state.
 *
 * Generic WordPress database routers are intentionally not guessed. A db.php
 * drop-in can route reads and writes to different connections, so governed
 * mutation fails closed until a provider-specific topology profile is
 * separately certified.
 */
final class MAD4B_SCP_Database_Topology {
	const CONTRACT = 'mad4b.database-topology.v1';
	private static $cache = null;

	public static function reset_request_cache() {
		self::$cache = null;
		return true;
	}

	public static function status( $refresh = false ) {
		global $wpdb;
		if ( ! $refresh && is_array( self::$cache ) ) return self::$cache;
		$blockers = array();
		$dropin = defined( 'WP_CONTENT_DIR' ) ? trailingslashit( WP_CONTENT_DIR ) . 'db.php' : '';
		$dropin_present = '' !== $dropin && ( file_exists( $dropin ) || is_link( $dropin ) );
		$dropin_certification = self::observer_dropin_certification( $dropin );
		$observer_dropin_certified = $dropin_present && ! empty( $dropin_certification['certified'] );
		if ( $dropin_present && ! $observer_dropin_certified ) $blockers[] = 'uncertified_database_router_dropin';

		$row = null;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_row' ) ) {
			$previous = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			$wpdb->last_error = '';
			$row = $wpdb->get_row(
				'SELECT CONNECTION_ID() AS connection_id, DATABASE() AS database_name, @@hostname AS server_hostname, @@port AS server_port, @@read_only AS server_read_only',
				ARRAY_A
			);
			$db_error = isset( $wpdb->last_error ) ? trim( (string) $wpdb->last_error ) : '';
			if ( method_exists( $wpdb, 'suppress_errors' ) ) $wpdb->suppress_errors( (bool) $previous );
			if ( ! is_array( $row ) ) $blockers[] = 'database_topology_probe_unavailable';
			if ( '' !== $db_error ) $blockers[] = 'database_topology_probe_error';
		} else {
			$blockers[] = 'database_connection_unavailable';
		}

		$connection_id = is_array( $row ) && isset( $row['connection_id'] ) ? (int) $row['connection_id'] : 0;
		$database_name = is_array( $row ) && isset( $row['database_name'] ) ? (string) $row['database_name'] : '';
		$server_hostname = is_array( $row ) && isset( $row['server_hostname'] ) ? (string) $row['server_hostname'] : '';
		$server_port = is_array( $row ) && isset( $row['server_port'] ) ? (int) $row['server_port'] : 0;
		$server_read_only = is_array( $row ) && isset( $row['server_read_only'] ) ? (int) $row['server_read_only'] : 1;
		if ( $connection_id <= 0 ) $blockers[] = 'database_connection_identity_missing';
		if ( '' === $database_name ) $blockers[] = 'database_name_missing';
		if ( 0 !== $server_read_only ) $blockers[] = 'database_writer_read_only';

		$server_basis = array(
			'database' => $database_name,
			'server_hostname' => $server_hostname,
			'server_port' => $server_port,
			'wpdb_dbhost' => isset( $wpdb->dbhost ) ? (string) $wpdb->dbhost : '',
			'server_version' => isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '',
		);
		$server_fingerprint = self::digest( $server_basis );
		$connection_fingerprint = self::digest( array(
			'server_fingerprint' => $server_fingerprint,
			'connection_id' => $connection_id,
		) );

		$status = array(
			'contract' => self::CONTRACT,
			'mode' => $dropin_present ? ( $observer_dropin_certified ? 'certified_observer_dropin_single_wpdb_writer_session' : 'uncertified_router' ) : 'single_wpdb_writer_session',
			'database_dropin_present' => $dropin_present,
			'database_dropin_observer_certified' => $observer_dropin_certified,
			'database_dropin_ownership' => isset( $dropin_certification['ownership'] ) ? (string) $dropin_certification['ownership'] : '',
			'database_dropin_path_sha256' => $dropin_present ? hash( 'sha256', (string) $dropin ) : '',
			'router_certified' => false,
			'observer_dropin_certified' => $observer_dropin_certified,
			'connection_id' => $connection_id,
			'database_name' => $database_name,
			'server_hostname' => $server_hostname,
			'server_port' => $server_port,
			'server_read_only' => 0 !== $server_read_only,
			'server_fingerprint' => $server_fingerprint,
			'connection_fingerprint' => $connection_fingerprint,
			'read_your_writes' => ( ! $dropin_present || $observer_dropin_certified ) && 0 === $server_read_only && $connection_id > 0,
			'readback_policy' => 'same_connection_id_and_server_fingerprint',
			'blockers' => array_values( array_unique( $blockers ) ),
			'ready' => empty( $blockers ),
			'authorizing' => false,
			'mutation_performed' => false,
		);
		self::$cache = $status;
		return $status;
	}

	public static function assert_write_ready( $refresh = true ) {
		$status = self::status( $refresh );
		if ( ! is_array( $status ) || empty( $status['ready'] ) || empty( $status['read_your_writes'] ) ) {
			return new WP_Error(
				'mad4b_database_topology_not_write_safe',
				'Governed database mutation requires a certified writer/readback topology.',
				array(
					'blockers' => is_array( $status ) && isset( $status['blockers'] ) ? $status['blockers'] : array( 'database_topology_unavailable' ),
					'blind_retry_allowed' => false,
					'authorizing' => false,
				)
			);
		}
		return $status;
	}

	public static function assert_same_writer( array $expected ) {
		$current = self::assert_write_ready( true );
		if ( is_wp_error( $current ) ) return $current;
		foreach ( array( 'server_fingerprint', 'connection_fingerprint' ) as $field ) {
			$before = isset( $expected[ $field ] ) ? strtolower( trim( (string) $expected[ $field ] ) ) : '';
			$after = isset( $current[ $field ] ) ? strtolower( trim( (string) $current[ $field ] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $before ) || ! preg_match( '/^[a-f0-9]{64}$/', $after ) || ! hash_equals( $before, $after ) ) {
				return new WP_Error(
					'mad4b_database_topology_writer_changed',
					'Database writer connection changed during a governed persistence boundary.',
					array(
						'field' => $field,
						'reconciliation_required' => true,
						'blind_retry_allowed' => false,
						'authorizing' => false,
					)
				);
			}
		}
		return $current;
	}

	private static function observer_dropin_certification( $dropin ) {
		$out = array(
			'certified' => false,
			'ownership' => 'none',
		);
		if ( '' === (string) $dropin || ( ! file_exists( $dropin ) && ! is_link( $dropin ) ) ) return $out;
		$out['ownership'] = 'untrusted';
		if ( ! class_exists( 'MAD4B_SCP_Query_Monitor_Evidence_Bridge' )
			|| ! method_exists( 'MAD4B_SCP_Query_Monitor_Evidence_Bridge', 'db_attribution_status' ) ) return $out;
		$status = MAD4B_SCP_Query_Monitor_Evidence_Bridge::db_attribution_status();
		if ( ! is_array( $status ) || empty( $status['dropin_exists'] ) || empty( $status['dropin_owned_by_query_monitor'] ) || ! empty( $status['dropin_conflict'] ) ) return $out;
		$ownership = isset( $status['dropin_ownership'] ) ? sanitize_key( (string) $status['dropin_ownership'] ) : '';
		if ( ! in_array( $ownership, array( 'query_monitor_symlink', 'query_monitor_exact_copy', 'mad4b_bounded_loader' ), true ) ) return $out;
		$out['certified'] = true;
		$out['ownership'] = $ownership;
		return $out;
	}

	private static function digest( $value ) {
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}
}
