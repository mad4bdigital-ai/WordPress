<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit, audited next-request recovery; no MCP/OAuth authority changes. */
final class MAD4B_SCP_MCP_Runtime_Recovery {
	const ACTION = 'mad4b_repair_mcp_runtime';
	const HOOK = 'mad4b_scp_mcp_runtime_recovery';
	const OPTION = 'mad4b_scp_mcp_runtime_recovery_v1';
	private static $active = false;

	public static function boot() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 20, 2 );
		add_action( self::HOOK, array( __CLASS__, 'run_cron' ) );
		add_action( 'mad4b_scp_site_profile_saved', array( __CLASS__, 'profile_saved' ) );
		register_activation_hook( MAD4B_SCP_FILE, array( __CLASS__, 'schedule' ) );
	}

	public static function active() { return self::$active; }

	public static function run_cron() {
		$result = self::run();
		if ( is_wp_error( $result ) ) update_option( self::OPTION, array( 'state' => 'blocked', 'blocker' => sanitize_key( $result->get_error_code() ), 'connection_certified' => false ), false );
	}

	public static function after_upgrade( $upgrader, $extra ) {
		if ( ! is_array( $extra ) || 'plugin' !== ( $extra['type'] ?? '' ) ) return;
		$plugins = isset( $extra['plugins'] ) && is_array( $extra['plugins'] ) ? $extra['plugins'] : array( $extra['plugin'] ?? '' );
		if ( ! array_intersect( array( plugin_basename( MAD4B_SCP_FILE ), 'mcp-adapter/mcp-adapter.php' ), $plugins ) ) return;
		// This request still runs the old PHP classes. Schedule new-code recovery.
		self::schedule();
	}

	public static function profile_saved() {
		if ( ! MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}
		self::schedule();
	}

	public static function schedule() {
		if ( ! MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' ) ) return;
		if ( MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) return;
		if ( ! wp_next_scheduled( self::HOOK ) ) wp_schedule_single_event( time() + 30, self::HOOK );
	}

	public static function authorize() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_admin() ) return new WP_Error( 'mad4b_mcp_repair_post_required', 'Use the repair form.' );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) ) return new WP_Error( 'mad4b_mcp_repair_capability_denied', 'Plugin update capability is required.' );
		foreach ( array( 'action', 'nonce', 'build' ) as $key ) {
			if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) return new WP_Error( 'mad4b_mcp_repair_input_invalid', 'Reload the repair form.' );
		}
		if ( self::ACTION !== $_POST['action'] || ! wp_verify_nonce( wp_unslash( $_POST['nonce'] ), self::ACTION ) ) return new WP_Error( 'mad4b_mcp_repair_nonce_invalid', 'Reload the repair form.' );
		$build = MAD4B_SCP_Endpoint_Diagnostic::build_fingerprint();
		if ( '' === $build || ! hash_equals( $build, wp_unslash( $_POST['build'] ) ) ) return new WP_Error( 'mad4b_mcp_repair_build_changed', 'Reload after the plugin update.' );
		return true;
	}

	public static function handle() {
		$allowed = self::authorize();
		if ( is_wp_error( $allowed ) ) wp_die( esc_html( $allowed->get_error_message() ), '', array( 'response' => 403 ) );
		$result = self::run( '', true );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_code() ), '', array( 'response' => 409 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=mad4b-control-plane-connection&tab=endpoints' ) );
		exit;
	}

	/** A convergence caller may pass its already-owned shared lease. */
	public static function run( $convergence_lease = '', $authorized_admin = false ) {
		if ( MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()
			|| MAD4B_SCP_MCP_Request_Scope::current_request_is_endpoint_diagnostic_job() ) return new WP_Error( 'mad4b_mcp_repair_hotpath_denied', 'Repair requires a separate lifecycle request.' );
		$cli = defined( 'WP_CLI' ) && WP_CLI;
		$cron = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
		if ( ! $cli && ! $cron && '' === $convergence_lease && ( ! $authorized_admin || is_wp_error( self::authorize() ) ) ) return new WP_Error( 'mad4b_mcp_repair_lifecycle_required', 'An authorized repair lifecycle is required.' );
		if ( ! MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' ) ) return new WP_Error( 'mad4b_mcp_repair_profile_ineligible', 'Exact non-production managed-runtime enrollment is required.' );
		$integrity = MAD4B_SCP_Dependency_Manager::mcp_adapter_disk_integrity();
		if ( empty( $integrity['ready'] ) ) return new WP_Error( 'mcp_adapter_integrity_mismatch', 'Restore the certified MCP Adapter before recovery.' );
		$own_lease = '' === $convergence_lease;
		$lease = $own_lease ? MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'mcp_runtime_recovery' ) : $convergence_lease;
		if ( is_wp_error( $lease ) ) return $lease;
		try {
			$fence = MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lease, $own_lease ? 'mcp_runtime_recovery' : 'runtime_convergence' );
			if ( is_wp_error( $fence ) ) return $fence;
			self::$active = true;
			$refresh = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::bootstrap();
			if ( ! empty( $refresh['blocker'] ) ) return new WP_Error( $refresh['blocker'], 'Managed bootstrap refresh is blocked.' );
			$guard = MAD4B_SCP_MCP_Runtime_Conflict_Guard::bootstrap( true );
			if ( empty( $guard['mu_bootstrap_present'] ) || empty( $guard['mu_bootstrap_integrity'] )
				|| ! in_array( $guard['blocker'] ?? '', array( '', 'mcp_adapter_class_provenance_mismatch' ), true ) ) return new WP_Error( $guard['blocker'] ?: 'mad4b_mcp_repair_readback_failed', 'Managed bootstrap was not verified.' );
			$result = array( 'contract' => 'mad4b.mcp-runtime-recovery.v1', 'state' => 'armed_for_next_request', 'next_request_required' => true, 'connection_certified' => false, 'runtime_node_scope' => 'current_node', 'shared_filesystem_certified' => false, 'cluster_convergence_required' => true, 'production_mutation' => false );
			// A slow filesystem/audit phase may outlive its fence. Do not publish an
			// armed result after another worker takes ownership or storage drops it.
			$fence = MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lease, $own_lease ? 'mcp_runtime_recovery' : 'runtime_convergence' );
			if ( is_wp_error( $fence ) ) return $fence;
			update_option( self::OPTION, $result, false );
			if ( $result !== get_option( self::OPTION, array() ) ) return new WP_Error( 'mad4b_mcp_repair_status_readback_failed', 'Recovery result could not be persisted and verified.' );
			return $result;
		} finally {
			self::$active = false;
			if ( $own_lease ) MAD4B_SCP_Runtime_Maintenance_Lease::release( $lease, 'mcp_runtime_recovery' );
		}
	}
}
