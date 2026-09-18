<?php
/**
 * Runtime regression: read/status surfaces must not mutate governance authority.
 *
 * This test intentionally snapshots only authority-bearing persistence:
 * agents/subjects/grants, approval tickets, Site Profile and write-runtime
 * authority/certification options. Read paths may emit separate observational
 * evidence, but they must never create/revoke authority or rewrite approvals.
 */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fail = static function ( $message ) {
	fwrite( STDERR, '[MAD4B governance readonly immutability] ' . $message . PHP_EOL );
	exit( 1 );
};

if ( ! current_user_can( 'manage_options' ) ) $fail( 'Administrator context is required.' );
if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) $fail( 'Governance schema is not ready.' );

global $wpdb;
$tables = MAD4B_SCP_Schema::tables();

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

$snapshot = static function () use ( $tables, $canonical_rows ) {
	$table_keys = array( 'agents', 'subjects', 'grants', 'approvals' );
	$data = array( 'tables' => array(), 'options' => array() );
	foreach ( $table_keys as $key ) {
		$data['tables'][ $key ] = $canonical_rows( $tables[ $key ] );
	}
	$option_keys = array(
		MAD4B_SCP_Site_Profile::OPTION,
		MAD4B_SCP_Staging_Write_Authority::OPTION,
		MAD4B_SCP_Write_Runtime_Certification::OPTION,
		MAD4B_SCP_Approval_Tickets::CANDIDATE_BINDINGS_OPTION,
	);
	foreach ( $option_keys as $key ) {
		$data['options'][ $key ] = get_option( $key, null );
	}
	$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	return array(
		'hash' => hash( 'sha256', (string) $json ),
		'data' => $data,
	);
};

$before = $snapshot();
$persisted = MAD4B_SCP_Staging_Write_Authority::status();
if ( empty( $persisted['ready'] ) ) $fail( 'Persisted write authority must be ready before readonly immutability proof.' );

$reads = array(
	'persisted_authority' => static function () { return MAD4B_SCP_Staging_Write_Authority::status(); },
	'live_authority' => static function () { return MAD4B_SCP_Live_Truth::current_authority_status(); },
	'live_write_certification' => static function () { return MAD4B_SCP_Live_Truth::current_write_certification(); },
	'persisted_write_certification' => static function () { return MAD4B_SCP_Write_Runtime_Certification::status(); },
	'connection_status' => static function () { return MAD4B_SCP_Connection_Status::status(); },
	'skills_export_status' => static function () { return MAD4B_SCP_Skill_Abilities::skills_export_status(); },
	'approval_candidate_readiness' => static function () { return MAD4B_SCP_Approval_Decision_Admin::current_candidate(); },
);

foreach ( $reads as $name => $reader ) {
	$result = $reader();
	if ( is_wp_error( $result ) ) $fail( $name . ' returned WP_Error: ' . $result->get_error_code() );
	$after = $snapshot();
	if ( ! hash_equals( $before['hash'], $after['hash'] ) || $before['data'] !== $after['data'] ) {
		$fail( $name . ' mutated authority-bearing persistence. before=' . $before['hash'] . ' after=' . $after['hash'] );
	}
}

$live = MAD4B_SCP_Live_Truth::current_authority_status();
if ( empty( $live['ready'] ) || empty( $live['runtime_reconciled'] ) ) $fail( 'Live authority is not ready/reconciled after readonly proof.' );

echo "mad4b.site-control-plane.governance-readonly-immutability.v1: PASS\n";
