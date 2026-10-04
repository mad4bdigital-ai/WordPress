<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit read-only endpoint jobs. Never bootstrap REST while rendering HTML. */
final class MAD4B_SCP_Endpoint_Diagnostic {
	const ACTION = 'mad4b_connection_endpoint_diagnostic';
	const CONTRACT = 'mad4b.endpoint-diagnostic.v1';
	private static $authorized = false;

	public static function boot() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
	}

	public static function is_authorized_request() { return self::$authorized; }

	/** Bind the displayed page and every job to the same bounded set of code files. */
	public static function build_fingerprint() {
		$files = array( 'mad4b-site-control-plane.php', 'includes/class-mad4b-scp-endpoint-diagnostic.php', 'includes/class-mad4b-scp-connection-status.php', 'includes/class-mad4b-scp-connection-admin-ui.php', 'includes/class-mad4b-scp-plugin.php', 'includes/class-mad4b-scp-servers.php', 'includes/class-mad4b-scp-mcp-registration-bridge.php', 'includes/class-mad4b-scp-mcp-catalog-diagnostics.php', 'includes/class-mad4b-scp-mcp-class-provenance.php', 'config/certified-providers.json', 'includes/class-mad4b-scp-mcp-request-scope.php', 'includes/class-mad4b-scp-provider-diagnostic-policy.php', 'assets/connection-endpoint-diagnostics.js', 'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php', 'includes/class-mad4b-scp-mcp-runtime-recovery.php', 'includes/class-mad4b-scp-mcp-runtime-conflict-guard.php', 'includes/class-mad4b-scp-mcp-mu-bootstrap-refresh.php', 'includes/class-mad4b-scp-site-profile.php' );
		$hashes = array();
		foreach ( $files as $file ) {
			$hash = is_readable( MAD4B_SCP_DIR . $file ) ? @hash_file( 'sha256', MAD4B_SCP_DIR . $file ) : false; // A concurrent update must return a bounded build error, not corrupt JSON.
			if ( ! is_string( $hash ) || 64 !== strlen( $hash ) ) return '';
			$hashes[] = $file . ':' . $hash;
		}
		return hash( 'sha256', implode( "\n", $hashes ) );
	}

	private static function bounded_mu_bootstrap_evidence() {
		$runtime = isset( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] ) && is_array( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] )
			? $GLOBALS['mad4b_scp_mcp_mu_bootstrap']
			: array();
		$out = array(
			'contract' => isset( $runtime['contract'] ) ? sanitize_text_field( (string) $runtime['contract'] ) : '',
			'state' => isset( $runtime['state'] ) ? sanitize_key( (string) $runtime['state'] ) : 'not_executed',
			'executed' => ! empty( $runtime['executed'] ),
			'eligible' => ! empty( $runtime['eligible'] ),
			'request_requires_mcp_runtime' => ! empty( $runtime['request_requires_mcp_runtime'] ),
			'diagnostic_mu_proof_valid' => ! empty( $runtime['diagnostic_mu_proof_valid'] ),
			'canonical_symbols_pinned' => ! empty( $runtime['canonical_symbols_pinned'] ),
			'critical_class_baseline_ready' => ! empty( $runtime['critical_class_baseline_ready'] ),
			'critical_class_set_pinned' => ! empty( $runtime['critical_class_set_pinned'] ),
			'critical_class_pin_count' => isset( $runtime['critical_class_pin_count'] ) ? max( 0, (int) $runtime['critical_class_pin_count'] ) : 0,
			'runtime_from_official_plugin' => ! empty( $runtime['runtime_from_official_plugin'] ),
			'runtime_source' => isset( $runtime['runtime_source'] ) ? sanitize_text_field( (string) $runtime['runtime_source'] ) : '',
			'runtime_preclaimed' => ! empty( $runtime['runtime_preclaimed'] ),
			'preclaimed_symbol' => isset( $runtime['preclaimed_symbol'] ) ? sanitize_text_field( (string) $runtime['preclaimed_symbol'] ) : '',
			'transaction_pending' => ! empty( $runtime['transaction_pending'] ),
			'adapter_instance_armed' => ! empty( $runtime['adapter_instance_armed'] ),
			'adapter_init_hook_bound' => ! empty( $runtime['adapter_init_hook_bound'] ),
		);
		return $out;
	}

	public static function ajax() {
		$result = self::run();
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			$payload = array( 'code' => sanitize_key( $result->get_error_code() ) );
			if ( is_array( $data ) && isset( $data['runtime_bootstrap'] ) && is_array( $data['runtime_bootstrap'] ) ) $payload['runtime_bootstrap'] = $data['runtime_bootstrap'];
			if ( is_array( $data ) && isset( $data['runtime_recovery'] ) && is_array( $data['runtime_recovery'] ) ) $payload['runtime_recovery'] = $data['runtime_recovery'];
			wp_send_json_error( $payload, $status );
		}
		wp_send_json_success( $result );
	}

	/** Also used by the offline security fixture; inputs still come from HTTP POST. */
	public static function run() {
		if ( ! is_admin() || ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_endpoint_diagnostic_forbidden', 'Administrator access is required.', array( 'status' => 403 ) );
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) return new WP_Error( 'mad4b_endpoint_diagnostic_post_required', 'Use an explicit POST job.', array( 'status' => 405 ) );
		// Reject array-valued or altered identifiers rather than sanitizing them into an allowlisted target.
		$input = array();
		foreach ( array( 'action', 'nonce', 'server_id', 'build' ) as $key ) {
			if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) return new WP_Error( 'mad4b_endpoint_diagnostic_input_invalid', 'Invalid diagnostic input.', array( 'status' => 400 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below before any lifecycle work.
			$input[ $key ] = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- exact comparisons below; never echoed.
		}
		if ( self::ACTION !== $input['action'] || false === wp_verify_nonce( $input['nonce'], 'mad4b_connection_deep_endpoints' ) ) return new WP_Error( 'mad4b_endpoint_diagnostic_nonce_invalid', 'Reload the page and start a new diagnostic.', array( 'status' => 403 ) );
		if ( ! in_array( $input['server_id'], MAD4B_SCP_Servers::expected_server_ids(), true ) ) return new WP_Error( 'mad4b_endpoint_diagnostic_server_invalid', 'Unknown endpoint.', array( 'status' => 400 ) );
		$build = self::build_fingerprint();
		if ( '' === $build || ! hash_equals( $build, $input['build'] ) ) return new WP_Error( 'mad4b_endpoint_diagnostic_build_changed', 'Reload after the plugin update.', array( 'status' => 409 ) );

		$runtime_bootstrap = self::bounded_mu_bootstrap_evidence();
		$managed_nonproduction = class_exists( 'MAD4B_SCP_Site_Profile', false )
			&& method_exists( 'MAD4B_SCP_Site_Profile', 'nonproduction_governed' )
			&& MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' );
		if ( $managed_nonproduction ) {
			$proof = isset( $_POST['mu_proof'] ) && is_string( $_POST['mu_proof'] )
				? strtolower( trim( wp_unslash( $_POST['mu_proof'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
				: '';
			$expected_proof = method_exists( 'MAD4B_SCP_Site_Profile', 'diagnostic_mu_proof' )
				? strtolower( trim( (string) MAD4B_SCP_Site_Profile::diagnostic_mu_proof() ) )
				: '';
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $proof ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_proof ) || ! hash_equals( $expected_proof, $proof ) ) {
				return new WP_Error( 'mad4b_endpoint_diagnostic_mu_proof_invalid', 'Reload the page after enrollment or plugin changes and start a new diagnostic.', array( 'status' => 409 ) );
			}
			$bootstrap_ready = ! empty( $runtime_bootstrap['executed'] )
				&& ! empty( $runtime_bootstrap['diagnostic_mu_proof_valid'] )
				&& ! empty( $runtime_bootstrap['canonical_symbols_pinned'] )
				&& ! empty( $runtime_bootstrap['critical_class_set_pinned'] )
				&& ! empty( $runtime_bootstrap['runtime_from_official_plugin'] );
			if ( ! $bootstrap_ready ) {
				$recovery = class_exists( 'MAD4B_SCP_MCP_MU_Bootstrap_Refresh', false ) ? MAD4B_SCP_MCP_MU_Bootstrap_Refresh::conflict_status() : array();
				$runtime_recovery = array(
					'contract' => isset( $recovery['contract'] ) ? sanitize_text_field( (string) $recovery['contract'] ) : '',
					'state' => isset( $recovery['state'] ) ? sanitize_key( (string) $recovery['state'] ) : '',
					'blocker' => isset( $recovery['blocker'] ) ? sanitize_key( (string) $recovery['blocker'] ) : '',
					'source_sha256' => isset( $recovery['source_sha256'] ) ? strtolower( trim( (string) $recovery['source_sha256'] ) ) : '',
					'destination_sha256_before' => isset( $recovery['destination_sha256_before'] ) ? strtolower( trim( (string) $recovery['destination_sha256_before'] ) ) : '',
					'ownership_receipt_present' => ! empty( $recovery['ownership_receipt_present'] ),
					'ownership_receipt_valid' => ! empty( $recovery['ownership_receipt_valid'] ),
					'ownership_source' => isset( $recovery['ownership_source'] ) ? sanitize_key( (string) $recovery['ownership_source'] ) : '',
					'manual_conflict_recovery_available' => ! empty( $recovery['manual_conflict_recovery_available'] ),
				);
				return new WP_Error(
					'mad4b_endpoint_diagnostic_mu_bootstrap_not_ready',
					'Managed MCP early bootstrap did not pin the certified runtime for this diagnostic request.',
					array( 'status' => 409, 'runtime_bootstrap' => $runtime_bootstrap, 'runtime_recovery' => $runtime_recovery )
				);
			}
		}

		$started = microtime( true );
		self::$authorized = true;
		try {
			$armed = MAD4B_SCP_MCP_Request_Scope::begin_endpoint_diagnostic( $input['server_id'] );
			if ( is_wp_error( $armed ) ) return $armed;
			// No route dispatch, HTTP self-call, tool execution or certification.
			// Governed jobs match transport bootstrap; preserved callbacks may still stall.
			rest_get_server();
			$result = MAD4B_SCP_Connection_Status::endpoint_diagnostic( $input['server_id'] );
			if ( is_array( $result ) ) $result['runtime_bootstrap'] = $runtime_bootstrap;
			if ( ! hash_equals( $build, self::build_fingerprint() ) ) return new WP_Error( 'mad4b_endpoint_diagnostic_build_changed', 'Plugin files changed during the diagnostic.', array( 'status' => 409 ) );
			return array( 'contract' => self::CONTRACT, 'build' => $build, 'server' => $result, 'runtime_bootstrap' => $runtime_bootstrap, 'elapsed_ms' => max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) ), 'connection_certified' => false, 'certification_performed' => false, 'foreign_transport_inventory_deferred' => true, 'tool_execution_performed' => false, 'outbound_discovery_performed' => false );
		} catch ( Throwable $error ) {
			// Never expose provider exception messages or filesystem paths.
			return new WP_Error( 'mad4b_endpoint_diagnostic_exception', 'Endpoint inspection failed.', array( 'status' => 500 ) );
		} finally {
			self::$authorized = false;
		}
	}
}
