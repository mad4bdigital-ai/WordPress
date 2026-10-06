<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Admin_Query_Performance_UI {
	const PAGE_SLUG = 'mad4b-control-plane-performance';
	public static function boot() {
		MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'register_menu' ), 31 );
	}

	public static function register_menu() {
		add_submenu_page(
			'mad4b-control-plane',
			__( 'MAD4B Performance', 'mad4b-site-control-plane' ),
			__( 'Performance', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to manage MAD4B performance maintenance.', 'mad4b-site-control-plane' ) );
		$status = MAD4B_SCP_Admin_Query_Performance::status();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'MAD4B Performance Maintenance', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p>' . esc_html__( 'Performance indexes are never created during plugin upload, update, activation, or ordinary admin requests. Apply them only here during a maintenance window.', 'mad4b-site-control-plane' ) . '</p>';
		$job = isset( $status['maintenance_job'] ) && is_array( $status['maintenance_job'] ) ? $status['maintenance_job'] : array();
		$feedback = sanitize_key( MAD4B_SCP_Admin_Experience::query_string( 'mad4b_performance_apply', '', 128 ) );
		if ( $feedback && MAD4B_SCP_Admin_Experience::notice_verified( self::PAGE_SLUG, $feedback, hash( 'sha256', wp_json_encode( $job ) ) ) ) echo '<div class="notice notice-info"><p>' . esc_html( 'Maintenance request: ' . $feedback . '. Check the persisted worker and index status below.' ) . '</p></div>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		self::row( 'Environment', isset( $status['environment'] ) ? $status['environment'] : '' );
		self::row( 'Ready', ! empty( $status['ready'] ) ? 'yes' : 'no' );
		self::row( 'Automatic apply', ! empty( $status['automatic_apply'] ) ? 'yes' : 'no' );
		self::row( 'Index version', isset( $status['index_version'] ) ? $status['index_version'] : '' );
		self::row( 'Maintenance worker', isset( $job['status'] ) ? $job['status'] : 'Not requested' );
		self::row( 'Maintenance executor ready', ! empty( $status['maintenance_executor_ready'] ) ? 'yes' : 'no' );
		if ( isset( $job['result']['state'] ) ) self::row( 'Worker result', $job['result']['state'] );
		if ( isset( $job['result']['error_code'] ) ) self::row( 'Worker error', $job['result']['error_code'] );
		echo '</tbody></table>';
		if ( ! empty( $status['maintenance_job_health']['reconciliation_required'] ) ) echo '<div class="notice notice-warning"><p>' . esc_html__( 'The maintenance worker exceeded its lease. Reconciliation is required before another attempt; refreshing this page does not retry the operation.', 'mad4b-site-control-plane' ) . '</p></div>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Refresh persisted status', 'mad4b-site-control-plane' ) . '</a></p>';

		$indexes = isset( $status['indexes'] ) && is_array( $status['indexes'] ) ? $status['indexes'] : array();
		if ( $indexes ) {
			echo '<h2>' . esc_html__( 'Index status', 'mad4b-site-control-plane' ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Index</th><th>Table</th><th>Present</th></tr></thead><tbody>';
			foreach ( $indexes as $key => $row ) {
				echo '<tr><td><code>' . esc_html( (string) $key ) . '</code></td><td><code>' . esc_html( isset( $row['table'] ) ? (string) $row['table'] : '' ) . '</code></td><td>' . esc_html( ! empty( $row['present'] ) ? 'yes' : 'no' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( empty( $status['ready'] ) && 'staging' === ( isset( $status['environment'] ) ? (string) $status['environment'] : '' ) ) {
			echo '<h2>' . esc_html__( 'Explicit maintenance action', 'mad4b-site-control-plane' ) . '</h2>';
			echo '<p><strong>' . esc_html__( 'This may run ALTER TABLE on WordPress meta tables. Run only during a maintenance window.', 'mad4b-site-control-plane' ) . '</strong></p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="mad4b_apply_admin_query_indexes">';
			wp_nonce_field( 'mad4b_apply_admin_query_indexes', 'mad4b_admin_query_performance_nonce' );
			submit_button( __( 'Apply performance indexes', 'mad4b-site-control-plane' ), 'primary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}

	private static function row( $label, $value ) {
		echo '<tr><th style="width:260px">' . esc_html( $label ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
	}
}

MAD4B_SCP_Admin_Query_Performance_UI::boot();

// Routes are declared without booting menus or provider lifecycle on frontend requests.
if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry', false ) ) MAD4B_SCP_Admin_Route_Registry::register( MAD4B_SCP_Admin_Query_Performance_UI::PAGE_SLUG, 'manage_options' );
