<?php
/**
 * Runtime lifecycle persistence proof.
 *
 * This script deliberately does not depend on any MAD4B class so it can run
 * while the plugin is deactivated. It captures or verifies authority-bearing
 * database state across deactivate/reactivate boundaries.
 */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fail = static function ( $message ) {
	fwrite( STDERR, '[MAD4B plugin lifecycle continuity] ' . $message . PHP_EOL );
	exit( 1 );
};

$phase = getenv( 'MAD4B_LIFECYCLE_PHASE' );
$phase = is_string( $phase ) ? strtolower( trim( $phase ) ) : '';
if ( ! in_array( $phase, array( 'capture', 'verify' ), true ) ) $fail( 'MAD4B_LIFECYCLE_PHASE must be capture or verify.' );

$file = getenv( 'MAD4B_LIFECYCLE_SNAPSHOT_FILE' );
$file = is_string( $file ) && '' !== trim( $file ) ? trim( $file ) : '/tmp/mad4b-scp-plugin-lifecycle-snapshot.json';

global $wpdb;
$tables = array(
	'agents' => $wpdb->prefix . 'mad4b_scp_agents',
	'subjects' => $wpdb->prefix . 'mad4b_scp_agent_subjects',
	'grants' => $wpdb->prefix . 'mad4b_scp_agent_grants',
	'approvals' => $wpdb->prefix . 'mad4b_scp_approval_tickets',
	'mutations' => $wpdb->prefix . 'mad4b_scp_mutations',
	'budgets' => $wpdb->prefix . 'mad4b_scp_agent_budgets',
	'budget_windows' => $wpdb->prefix . 'mad4b_scp_agent_budget_windows',
	'audit_events' => $wpdb->prefix . 'mad4b_scp_audit_events',
	'audit_heads' => $wpdb->prefix . 'mad4b_scp_audit_heads',
	'catalog_objects' => $wpdb->prefix . 'mad4b_catalog_objects',
	'catalog_generations' => $wpdb->prefix . 'mad4b_catalog_generations',
	'catalog_heads' => $wpdb->prefix . 'mad4b_catalog_heads',
	'network_operations' => $wpdb->prefix . 'mad4b_network_operations',
	'network_operation_targets' => $wpdb->prefix . 'mad4b_network_operation_targets',
	'network_operation_events' => $wpdb->prefix . 'mad4b_network_operation_events',
	'provider_breakers' => $wpdb->prefix . 'mad4b_provider_breakers',
	'provider_breaker_events' => $wpdb->prefix . 'mad4b_provider_breaker_events',
);

$canonical_rows = static function ( $table ) use ( $wpdb, $fail ) {
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $found !== $table ) $fail( 'Required governance table is missing: ' . $table );
	$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	if ( ! is_array( $rows ) ) $fail( 'Unable to read governance table: ' . $table );
	foreach ( $rows as &$row ) {
		if ( is_array( $row ) ) ksort( $row, SORT_STRING );
	}
	unset( $row );
	usort( $rows, static function ( $a, $b ) {
		return strcmp(
			wp_json_encode( $a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			wp_json_encode( $b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	} );
	return $rows;
};

$data = array( 'tables' => array(), 'options' => array() );
foreach ( $tables as $key => $table ) $data['tables'][ $key ] = $canonical_rows( $table );

$option_keys = array(
	'mad4b_scp_site_profile_v2',
	'mad4b_scp_staging_write_authority_v1',
	'mad4b_scp_write_runtime_certification_v1',
	'mad4b_scp_approval_candidate_bindings_v1',
	'mad4b_scp_schema_version',
	'mad4b_scp_schema_integrity_v13',
	'mad4b_scp_catalog_backend_state_v1',
	'mad4b_scp_post_update_continuation_v1',
);
foreach ( $option_keys as $key ) {
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$data['options'][ $key ] = is_array( $row ) && array_key_exists( 'option_value', $row ) ? (string) $row['option_value'] : null;
}

$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$hash = hash( 'sha256', (string) $json );

if ( 'capture' === $phase ) {
	$payload = wp_json_encode( array( 'contract' => 'mad4b.plugin-lifecycle-data-continuity.v1', 'hash' => $hash, 'data' => $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === file_put_contents( $file, $payload ) ) $fail( 'Unable to persist lifecycle snapshot.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	echo "mad4b.plugin-lifecycle-data-continuity.v1: CAPTURED {$hash}\n";
	return;
}

if ( ! is_file( $file ) ) $fail( 'Lifecycle baseline file is missing.' );
$baseline_raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$baseline = is_string( $baseline_raw ) ? json_decode( $baseline_raw, true ) : null;
if ( ! is_array( $baseline ) || 'mad4b.plugin-lifecycle-data-continuity.v1' !== ( $baseline['contract'] ?? '' ) || empty( $baseline['hash'] ) || ! isset( $baseline['data'] ) ) {
	$fail( 'Lifecycle baseline is invalid.' );
}
$audit_event_key = static function ( array $row ) {
	return (string) ( $row['chain_name'] ?? '' ) . ':' . (string) ( $row['sequence'] ?? '' );
};
$audit_event_map = static function ( array $rows ) use ( $audit_event_key ) {
	$out = array();
	foreach ( $rows as $row ) if ( is_array( $row ) ) $out[ $audit_event_key( $row ) ] = $row;
	return $out;
};
$audit_head_map = static function ( array $rows ) {
	$out = array();
	foreach ( $rows as $row ) if ( is_array( $row ) && isset( $row['chain_name'] ) ) $out[ (string) $row['chain_name'] ] = $row;
	return $out;
};

$before_events = $baseline['data']['tables']['audit_events'] ?? array();
$after_events = $data['tables']['audit_events'] ?? array();
$before_event_map = $audit_event_map( is_array( $before_events ) ? $before_events : array() );
$after_event_map = $audit_event_map( is_array( $after_events ) ? $after_events : array() );
foreach ( $before_event_map as $event_key => $before_event ) {
	if ( ! isset( $after_event_map[ $event_key ] ) || $after_event_map[ $event_key ] !== $before_event ) {
		$fail( 'Append-only audit history changed across plugin lifecycle boundary at ' . $event_key . '.' );
	}
}

$before_heads = $audit_head_map( is_array( $baseline['data']['tables']['audit_heads'] ?? null ) ? $baseline['data']['tables']['audit_heads'] : array() );
$after_heads = $audit_head_map( is_array( $data['tables']['audit_heads'] ?? null ) ? $data['tables']['audit_heads'] : array() );
foreach ( $before_heads as $chain_name => $before_head ) {
	if ( ! isset( $after_heads[ $chain_name ] ) ) $fail( 'Audit chain head disappeared across lifecycle boundary: ' . $chain_name );
	$after_head = $after_heads[ $chain_name ];
	$before_sequence = (int) ( $before_head['sequence'] ?? -1 );
	$after_sequence = (int) ( $after_head['sequence'] ?? -1 );
	if ( $after_sequence < $before_sequence ) $fail( 'Audit chain sequence regressed across lifecycle boundary: ' . $chain_name );
	foreach ( array( 'legacy_anchor_sha256', 'legacy_chain_valid', 'legacy_entry_count', 'created_at' ) as $stable_field ) {
		if ( ( $before_head[ $stable_field ] ?? null ) !== ( $after_head[ $stable_field ] ?? null ) ) {
			$fail( 'Audit chain stable head metadata changed across lifecycle boundary: ' . $chain_name . ':' . $stable_field );
		}
	}
	if ( $after_sequence === $before_sequence ) {
		if ( (string) ( $after_head['entry_hash'] ?? '' ) !== (string) ( $before_head['entry_hash'] ?? '' ) ) {
			$fail( 'Audit chain head hash changed without sequence advance: ' . $chain_name );
		}
	} elseif ( $before_sequence > 0 ) {
		$historical_key = $chain_name . ':' . $before_sequence;
		if ( ! isset( $after_event_map[ $historical_key ] )
			|| (string) ( $after_event_map[ $historical_key ]['entry_hash'] ?? '' ) !== (string) ( $before_head['entry_hash'] ?? '' ) ) {
			$fail( 'Advanced audit head no longer anchors the captured historical head: ' . $chain_name );
		}
	}
}

$before_strict = $baseline['data'];
$after_strict = $data;
unset( $before_strict['tables']['audit_events'], $before_strict['tables']['audit_heads'] );
unset( $after_strict['tables']['audit_events'], $after_strict['tables']['audit_heads'] );

if ( $before_strict !== $after_strict ) {
	$diffs = array();
	foreach ( array( 'tables', 'options' ) as $section ) {
		$before_section = isset( $before_strict[ $section ] ) && is_array( $before_strict[ $section ] ) ? $before_strict[ $section ] : array();
		$after_section = isset( $after_strict[ $section ] ) && is_array( $after_strict[ $section ] ) ? $after_strict[ $section ] : array();
		$keys = array_values( array_unique( array_merge( array_keys( $before_section ), array_keys( $after_section ) ) ) );
		sort( $keys, SORT_STRING );
		foreach ( $keys as $key ) {
			$before_value = array_key_exists( $key, $before_section ) ? $before_section[ $key ] : '__missing__';
			$after_value = array_key_exists( $key, $after_section ) ? $after_section[ $key ] : '__missing__';
			if ( $before_value === $after_value ) continue;
			$diffs[] = $section . ':' . $key;
			if ( count( $diffs ) >= 12 ) break 2;
		}
	}
	$fail( 'Authority-bearing data changed across plugin lifecycle boundary. before=' . (string) $baseline['hash'] . ' after=' . $hash . ' changed=' . implode( ',', $diffs ) );
}

echo "mad4b.plugin-lifecycle-data-continuity.v1: VERIFIED {$hash} audit_append_only=PASS\n";
