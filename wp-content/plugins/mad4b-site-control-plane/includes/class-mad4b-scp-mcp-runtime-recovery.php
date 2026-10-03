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

	private static function configured_topology_id( $constant_name ) {
		if ( ! defined( $constant_name ) ) return '';
		$value = trim( (string) constant( $constant_name ) );
		return 1 === preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', $value ) ? $value : '';
	}

	/**
	 * Current-node evidence is deliberately separate from cluster certification.
	 * A node without explicit cluster/node IDs can be healthy but cannot be
	 * aggregated into a multi-webhead production certificate.
	 */
	public static function node_evidence() {
		$cluster_id = self::configured_topology_id( 'MAD4B_SCP_CLUSTER_ID' );
		$node_id = self::configured_topology_id( 'MAD4B_SCP_NODE_ID' );
		$site_status = class_exists( 'MAD4B_SCP_Site_Profile', false ) && method_exists( 'MAD4B_SCP_Site_Profile', 'status' )
			? MAD4B_SCP_Site_Profile::status()
			: array();
		$site_uuid = isset( $site_status['site_uuid'] ) ? strtolower( trim( (string) $site_status['site_uuid'] ) ) : '';
		$site_profile_revision = isset( $site_status['revision'] ) ? max( 0, (int) $site_status['revision'] ) : 0;
		$site_profile_digest = isset( $site_status['profile_digest'] ) ? strtolower( trim( (string) $site_status['profile_digest'] ) ) : '';
		$environment = isset( $site_status['environment'] ) ? sanitize_key( (string) $site_status['environment'] ) : '';
		$canonical_origin = isset( $site_status['canonical_origin'] ) ? trim( (string) $site_status['canonical_origin'] ) : '';
		$site_binding_ready = 1 === preg_match( '/^[a-f0-9-]{36}$/D', $site_uuid )
			&& $site_profile_revision > 0
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $site_profile_digest )
			&& '' !== $canonical_origin
			&& ! empty( $site_status['authority_ready'] )
			&& MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' );
		$target_build = class_exists( 'MAD4B_SCP_Endpoint_Diagnostic', false )
			? (string) MAD4B_SCP_Endpoint_Diagnostic::build_fingerprint()
			: '';
		$source = defined( 'MAD4B_SCP_DIR' ) ? trailingslashit( MAD4B_SCP_DIR ) . 'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php' : '';
		$destination = defined( 'WPMU_PLUGIN_DIR' ) ? trailingslashit( WPMU_PLUGIN_DIR ) . '000-mad4b-mcp-adapter-bootstrap.php' : '';
		$expected_mu_sha256 = $source && is_readable( $source ) ? (string) hash_file( 'sha256', $source ) : '';
		$observed_mu_sha256 = $destination && is_readable( $destination ) ? (string) hash_file( 'sha256', $destination ) : '';
		$guard = class_exists( 'MAD4B_SCP_MCP_Runtime_Conflict_Guard', false ) ? MAD4B_SCP_MCP_Runtime_Conflict_Guard::status() : array();
		$runtime_execution_verified = 'canonical_runtime' === ( $guard['state'] ?? '' )
			&& ! empty( $guard['runtime_from_official_plugin'] )
			&& ! empty( $guard['mu_bootstrap_executed'] )
			&& ! empty( $guard['mu_bootstrap_runtime_from_official_plugin'] )
			&& '' !== $expected_mu_sha256
			&& '' !== $observed_mu_sha256
			&& hash_equals( $expected_mu_sha256, $observed_mu_sha256 );
		$identity_configured = '' !== $cluster_id && '' !== $node_id;
		$evidence = array(
			'contract' => 'mad4b.mcp-runtime-node-evidence.v2',
			'cluster_id' => $cluster_id,
			'node_id' => $node_id,
			'cluster_identity_configured' => $identity_configured,
			'site_uuid' => $site_uuid,
			'site_profile_revision' => $site_profile_revision,
			'site_profile_digest' => $site_profile_digest,
			'environment' => $environment,
			'origin_sha256' => '' !== $canonical_origin ? hash( 'sha256', $canonical_origin ) : '',
			'site_binding_ready' => $site_binding_ready,
			'target_build' => $target_build,
			'expected_mu_sha256' => $expected_mu_sha256,
			'observed_mu_sha256' => $observed_mu_sha256,
			'executed_runtime_provenance' => array(
				'state' => isset( $guard['state'] ) ? sanitize_key( (string) $guard['state'] ) : '',
				'runtime_source' => isset( $guard['runtime_source'] ) ? sanitize_text_field( (string) $guard['runtime_source'] ) : '',
				'runtime_version' => isset( $guard['runtime_version'] ) ? sanitize_text_field( (string) $guard['runtime_version'] ) : '',
				'runtime_from_official_plugin' => ! empty( $guard['runtime_from_official_plugin'] ),
				'mu_bootstrap_executed' => ! empty( $guard['mu_bootstrap_executed'] ),
				'mu_runtime_source' => isset( $guard['mu_bootstrap_runtime_source'] ) ? sanitize_text_field( (string) $guard['mu_bootstrap_runtime_source'] ) : '',
				'mu_runtime_from_official_plugin' => ! empty( $guard['mu_bootstrap_runtime_from_official_plugin'] ),
			),
			'runtime_execution_verified' => $runtime_execution_verified,
			'verified_at' => $runtime_execution_verified ? gmdate( 'c' ) : '',
			'eligible_for_cluster_aggregation' => $runtime_execution_verified && $identity_configured && $site_binding_ready && '' !== $target_build,
			'runtime_node_scope' => 'current_node',
			'shared_filesystem_certified' => false,
		);
		$fingerprint = $evidence;
		unset( $fingerprint['verified_at'] );
		$encoded = function_exists( 'wp_json_encode' )
			? wp_json_encode( $fingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $fingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$evidence['evidence_sha256'] = hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
		return $evidence;
	}

	public static function run_cron() {
		$result = self::run();
		if ( is_wp_error( $result ) ) update_option( self::OPTION, array( 'state' => 'blocked', 'blocker' => sanitize_key( $result->get_error_code() ), 'connection_certified' => false ), false );
	}

	public static function after_upgrade( $upgrader, $extra ) {
		if ( ! is_array( $extra ) || 'plugin' !== ( $extra['type'] ?? '' ) ) return;
		$plugins = isset( $extra['plugins'] ) && is_array( $extra['plugins'] ) ? $extra['plugins'] : array( $extra['plugin'] ?? '' );
		$adapter_file = '';
		if ( class_exists( 'MAD4B_SCP_Dependency_Manager' ) && method_exists( 'MAD4B_SCP_Dependency_Manager', 'mcp_adapter_plugin_identity' ) ) {
			$identity = MAD4B_SCP_Dependency_Manager::mcp_adapter_plugin_identity( true );
			if ( is_array( $identity ) && empty( $identity['ambiguous'] ) && ! empty( $identity['plugin_file'] ) ) $adapter_file = (string) $identity['plugin_file'];
		}
		$targets = array( plugin_basename( MAD4B_SCP_FILE ) );
		if ( '' !== $adapter_file ) $targets[] = $adapter_file;
		if ( ! array_intersect( $targets, array_values( array_filter( array_map( 'strval', $plugins ) ) ) ) ) return;
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
		$cli = defined( 'WP_CLI' ) && WP_CLI
			&& defined( 'MAD4B_SCP_MCP_CLI_REQUEST' ) && true === constant( 'MAD4B_SCP_MCP_CLI_REQUEST' );
		$cron = function_exists( 'wp_doing_cron' ) && wp_doing_cron()
			&& function_exists( 'current_filter' ) && self::HOOK === current_filter();
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
			$runtime_restart_required = ! empty( $refresh['runtime_restart_required'] ) || ! empty( $guard['runtime_restart_required'] );
			$node_evidence = self::node_evidence();
			$result = array(
				'contract' => 'mad4b.mcp-runtime-recovery.v1',
				'state' => 'armed_for_next_request',
				'next_request_required' => true,
				'runtime_execution_verified' => false,
				'runtime_restart_required' => $runtime_restart_required,
				'opcache_invalidation' => isset( $refresh['opcache_invalidation'] ) ? $refresh['opcache_invalidation'] : ( isset( $guard['opcache_invalidation'] ) ? $guard['opcache_invalidation'] : array( 'available' => false, 'verified' => false ) ),
				'connection_certified' => false,
				'runtime_node_scope' => 'current_node',
				'shared_filesystem_certified' => false,
				'cluster_convergence_required' => true,
				'cluster_id' => $node_evidence['cluster_id'],
				'node_id' => $node_evidence['node_id'],
				'site_uuid' => $node_evidence['site_uuid'],
				'site_profile_revision' => $node_evidence['site_profile_revision'],
				'site_profile_digest' => $node_evidence['site_profile_digest'],
				'environment' => $node_evidence['environment'],
				'origin_sha256' => $node_evidence['origin_sha256'],
				'site_binding_ready' => $node_evidence['site_binding_ready'],
				'target_build' => $node_evidence['target_build'],
				'expected_mu_sha256' => $node_evidence['expected_mu_sha256'],
				'node_evidence_sha256' => $node_evidence['evidence_sha256'],
				'executed_runtime_provenance' => $node_evidence['executed_runtime_provenance'],
				'node_verified_at' => $node_evidence['verified_at'],
				'node_runtime_execution_verified' => $node_evidence['runtime_execution_verified'],
				'cluster_identity_configured' => $node_evidence['cluster_identity_configured'],
				'eligible_for_cluster_aggregation' => $node_evidence['eligible_for_cluster_aggregation'],
				'production_mutation' => false,
			);
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
