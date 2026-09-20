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
	'mad4b_scp_schema_integrity_v5',
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
if ( ! hash_equals( (string) $baseline['hash'], $hash ) || $baseline['data'] !== $data ) {
	$fail( 'Authority-bearing data changed across plugin lifecycle boundary. before=' . (string) $baseline['hash'] . ' after=' . $hash );
}

echo "mad4b.plugin-lifecycle-data-continuity.v1: VERIFIED {$hash}\n";
