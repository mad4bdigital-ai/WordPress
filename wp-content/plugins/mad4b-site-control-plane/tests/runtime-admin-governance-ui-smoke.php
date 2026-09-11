<?php
/**
 * Runtime acceptance for the read-only MAD4B governance administrator console.
 */
if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$check( current_user_can( 'manage_options' ), 'Runtime admin UI smoke requires an administrator.' );
$check( class_exists( 'MAD4B_SCP_Admin_UI' ), 'Admin governance UI class is unavailable.' );
$check( has_action( 'admin_menu', array( 'MAD4B_SCP_Admin_UI', 'register_menu' ) ) !== false, 'Admin governance menu hook is not registered.' );

$tables = MAD4B_SCP_Schema::tables();
global $wpdb;
$counts = static function () use ( $wpdb, $tables ) {
	return array(
		'agents' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['agents']}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		'grants' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['grants']}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		'approvals' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['approvals']}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		'mutations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['mutations']}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	);
};

// Seed audit-only evidence containing a marker that must never be rendered by the UI.
$secret_marker = 'MAD4B_ADMIN_UI_MUST_NOT_RENDER_SUBJECT';
$recorded = MAD4B_SCP_Audit::record(
	'mad4b/ci-admin-ui-redaction',
	array(
		'subject_fingerprint' => $secret_marker,
		'api_token' => 'MAD4B_ADMIN_UI_MUST_NOT_RENDER_TOKEN',
	),
	'ok'
);
$check( ! is_wp_error( $recorded ), 'Unable to seed audit evidence for UI redaction proof.' );

$before = $counts();
$snapshot = MAD4B_SCP_Admin_UI::snapshot();
$check( ! is_wp_error( $snapshot ), 'Admin governance snapshot failed.' );
foreach ( array( 'authority', 'agents', 'effective_access', 'approvals', 'mutations', 'audit_storage', 'audit_tail', 'runtime_self_test', 'mcp_peer_governance' ) as $key ) {
	$check( array_key_exists( $key, $snapshot ), 'Admin governance snapshot missing key: ' . $key );
}
$check( isset( $snapshot['agents']['agents'] ) && is_array( $snapshot['agents']['agents'] ), 'Admin governance snapshot did not return bounded agent rows.' );
$check( count( $snapshot['agents']['agents'] ) <= MAD4B_SCP_Admin_UI::AGENT_LIMIT, 'Admin governance agent list exceeded its bound.' );
$check( is_array( $snapshot['approvals'] ) && count( $snapshot['approvals'] ) <= MAD4B_SCP_Admin_UI::EVIDENCE_LIMIT, 'Approval evidence exceeded its bound.' );
$check( is_array( $snapshot['mutations'] ) && count( $snapshot['mutations'] ) <= MAD4B_SCP_Admin_UI::EVIDENCE_LIMIT, 'Mutation evidence exceeded its bound.' );
$check( is_array( $snapshot['audit_tail'] ) && count( $snapshot['audit_tail'] ) <= MAD4B_SCP_Admin_UI::EVIDENCE_LIMIT, 'Audit evidence exceeded its bound.' );

foreach ( $snapshot['mutations'] as $row ) {
	$check( ! array_key_exists( 'rollback_payload', $row ), 'Admin mutation evidence exposed rollback payload.' );
	$check( ! array_key_exists( 'rollback_payload_sha256', $row ), 'Admin mutation evidence exposed rollback payload hash.' );
	$check( ! array_key_exists( 'subject_fingerprint', $row ), 'Admin mutation evidence exposed subject fingerprint.' );
}
foreach ( $snapshot['approvals'] as $row ) {
	$check( ! array_key_exists( 'payload_sha256', $row ), 'Admin approval evidence exposed exact approval payload hash.' );
}

if ( ! empty( $snapshot['agents']['agents'][0]['public_id'] ) ) {
	$selected = MAD4B_SCP_Admin_UI::snapshot( $snapshot['agents']['agents'][0]['public_id'] );
	$check( ! is_wp_error( $selected ), 'Agent-specific admin governance snapshot failed.' );
	$check( is_array( $selected['effective_access'] ), 'Agent-specific effective access preview was not produced.' );
}

$_GET['page'] = MAD4B_SCP_Admin_UI::PAGE_SLUG;
$_GET['tab'] = 'audit';
unset( $_GET['agent'] );
ob_start();
MAD4B_SCP_Admin_UI::render_page();
$html = ob_get_clean();
$check( is_string( $html ) && false !== strpos( $html, 'Append-only audit integrity' ), 'Audit admin tab did not render.' );
$check( false === strpos( $html, $secret_marker ), 'Admin audit tab rendered a subject fingerprint value from structured audit summary.' );
$check( false === strpos( $html, 'MAD4B_ADMIN_UI_MUST_NOT_RENDER_TOKEN' ), 'Admin audit tab rendered sensitive token material.' );
$check( false === strpos( $html, 'rollback_payload' ), 'Admin governance HTML exposed rollback payload metadata.' );

$after = $counts();
$check( $before === $after, 'Read-only admin governance inspection changed authority/approval/mutation state.' );

echo "mad4b.site-control-plane.runtime-admin-governance-ui.v1: PASS\n";
