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
$check( class_exists( 'MAD4B_SCP_Context_Admin_UI' ), 'Context settings admin UI class is unavailable.' );
$check(
	has_action( 'wp_ajax_mad4b_context_update_source_policy', array( 'MAD4B_SCP_Context_Admin_UI', 'handle_update_source_policy' ) ) !== false,
	'Context Source Policy AJAX action is not registered.'
);
$check(
	has_action( 'wp_ajax_mad4b_context_review_policy_save', array( 'MAD4B_SCP_Context_Admin_UI', 'handle_save_review_policy' ) ) !== false,
	'Context Approval Mode AJAX action is not registered.'
);
$check( class_exists( 'MAD4B_SCP_Site_Profile_Admin' ), 'Site Profile admin class is unavailable.' );
$check(
	has_action( 'wp_ajax_mad4b_site_profile_save', array( 'MAD4B_SCP_Site_Profile_Admin', 'handle_save' ) ) !== false,
	'Site Profile settings AJAX action is not registered.'
);
$check(
	has_action( 'wp_ajax_mad4b_enable_production_readonly_oauth', array( 'MAD4B_SCP_Staging_OAuth_Autoconfig', 'handle_enable_production_readonly' ) ) !== false,
	'Production read-only OAuth enable AJAX action is not registered.'
);
$check(
	has_action( 'wp_ajax_mad4b_disable_production_readonly_oauth', array( 'MAD4B_SCP_Staging_OAuth_Autoconfig', 'handle_disable_production_readonly' ) ) !== false,
	'Production read-only OAuth disable AJAX action is not registered.'
);

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

// Overview is the hotpath used by ordinary navigation. It must remain bounded
// and must not trigger deep provider/MCP diagnostics.
$overview_queries_before = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
$overview_started = microtime( true );
$overview_peak_before = memory_get_peak_usage( true );
$snapshot = MAD4B_SCP_Admin_UI::snapshot( '', 'overview' );
$overview_elapsed_ms = ( microtime( true ) - $overview_started ) * 1000;
$overview_query_delta = max( 0, ( isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0 ) - $overview_queries_before );
$overview_peak_delta = max( 0, memory_get_peak_usage( true ) - $overview_peak_before );

$check( ! is_wp_error( $snapshot ), 'Admin governance overview snapshot failed.' );
foreach ( array( 'authority', 'agents', 'effective_access', 'approvals', 'mutations', 'audit_storage', 'audit_tail', 'runtime_self_test', 'mcp_peer_governance' ) as $key ) {
	$check( array_key_exists( $key, $snapshot ), 'Admin governance overview snapshot missing key: ' . $key );
}
$check( 'deferred' === ( isset( $snapshot['runtime_self_test']['status'] ) ? (string) $snapshot['runtime_self_test']['status'] : '' ), 'Overview unexpectedly ran deep adapter runtime self-test.' );
$check( empty( $snapshot['mcp_peer_governance']['inventory_ready'] ), 'Overview unexpectedly ran MCP peer inventory.' );
$check( $overview_query_delta <= 40, 'Overview snapshot exceeded the 40-query CI budget: ' . $overview_query_delta );
$check( $overview_elapsed_ms <= 1500, 'Overview snapshot exceeded the 1500ms CI budget: ' . round( $overview_elapsed_ms, 2 ) );
$check( $overview_peak_delta <= 16 * 1024 * 1024, 'Overview snapshot exceeded the 16MiB incremental peak-memory CI budget.' );

// Each heavy evidence family is loaded only by its own active tab.
$agents_snapshot = MAD4B_SCP_Admin_UI::snapshot( '', 'agents' );
$check( ! is_wp_error( $agents_snapshot ), 'Agents snapshot failed.' );
$check( isset( $agents_snapshot['agents']['agents'] ) && is_array( $agents_snapshot['agents']['agents'] ), 'Agents snapshot did not return bounded agent rows.' );
$check( count( $agents_snapshot['agents']['agents'] ) <= MAD4B_SCP_Admin_UI::AGENT_LIMIT, 'Admin governance agent list exceeded its bound.' );

$approval_snapshot = MAD4B_SCP_Admin_UI::snapshot( '', 'approvals' );
$check( ! is_wp_error( $approval_snapshot ), 'Approvals snapshot failed.' );
$check( is_array( $approval_snapshot['approvals'] ) && count( $approval_snapshot['approvals'] ) <= MAD4B_SCP_Admin_UI::EVIDENCE_LIMIT, 'Approval evidence exceeded its bound.' );
foreach ( $approval_snapshot['approvals'] as $row ) {
	$check( ! array_key_exists( 'payload_sha256', $row ), 'Admin approval evidence exposed exact approval payload hash.' );
}

$mutation_snapshot = MAD4B_SCP_Admin_UI::snapshot( '', 'mutations' );
$check( ! is_wp_error( $mutation_snapshot ), 'Mutations snapshot failed.' );
$check( is_array( $mutation_snapshot['mutations'] ) && count( $mutation_snapshot['mutations'] ) <= MAD4B_SCP_Admin_UI::EVIDENCE_LIMIT, 'Mutation evidence exceeded its bound.' );
foreach ( $mutation_snapshot['mutations'] as $row ) {
	$check( ! array_key_exists( 'rollback_payload', $row ), 'Admin mutation evidence exposed rollback payload.' );
	$check( ! array_key_exists( 'rollback_payload_sha256', $row ), 'Admin mutation evidence exposed rollback payload hash.' );
	$check( ! array_key_exists( 'subject_fingerprint', $row ), 'Admin mutation evidence exposed subject fingerprint.' );
}

$audit_snapshot = MAD4B_SCP_Admin_UI::snapshot( '', 'audit' );
$check( ! is_wp_error( $audit_snapshot ), 'Audit snapshot failed.' );
$check( is_array( $audit_snapshot['audit_tail'] ) && count( $audit_snapshot['audit_tail'] ) <= MAD4B_SCP_Admin_UI::EVIDENCE_LIMIT, 'Audit evidence exceeded its bound.' );

if ( ! empty( $agents_snapshot['agents']['agents'][0]['public_id'] ) ) {
	$selected = MAD4B_SCP_Admin_UI::snapshot( $agents_snapshot['agents']['agents'][0]['public_id'], 'agents' );
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

echo "mad4b.site-control-plane.runtime-admin-governance-ui.v4: PASS overview_ms=" . round( $overview_elapsed_ms, 2 ) . " overview_queries=" . $overview_query_delta . " overview_peak_delta=" . $overview_peak_delta . "\n";
