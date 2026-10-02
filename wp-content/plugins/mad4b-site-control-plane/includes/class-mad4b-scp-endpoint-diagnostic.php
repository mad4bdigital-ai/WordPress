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
		$files = array( 'mad4b-site-control-plane.php', 'includes/class-mad4b-scp-endpoint-diagnostic.php', 'includes/class-mad4b-scp-connection-status.php', 'includes/class-mad4b-scp-connection-admin-ui.php', 'includes/class-mad4b-scp-plugin.php', 'includes/class-mad4b-scp-servers.php', 'includes/class-mad4b-scp-mcp-registration-bridge.php', 'includes/class-mad4b-scp-mcp-catalog-diagnostics.php', 'includes/class-mad4b-scp-mcp-class-provenance.php', 'config/certified-providers.json', 'includes/class-mad4b-scp-mcp-request-scope.php', 'includes/class-mad4b-scp-provider-diagnostic-policy.php', 'assets/connection-endpoint-diagnostics.js' );
		$hashes = array();
		foreach ( $files as $file ) {
			$hash = is_readable( MAD4B_SCP_DIR . $file ) ? @hash_file( 'sha256', MAD4B_SCP_DIR . $file ) : false; // A concurrent update must return a bounded build error, not corrupt JSON.
			if ( ! is_string( $hash ) || 64 !== strlen( $hash ) ) return '';
			$hashes[] = $file . ':' . $hash;
		}
		return hash( 'sha256', implode( "\n", $hashes ) );
	}

	public static function ajax() {
		$result = self::run();
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			wp_send_json_error( array( 'code' => sanitize_key( $result->get_error_code() ) ), $status );
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

		$started = microtime( true );
		self::$authorized = true;
		try {
			$armed = MAD4B_SCP_MCP_Request_Scope::begin_endpoint_diagnostic( $input['server_id'] );
			if ( is_wp_error( $armed ) ) return $armed;
			// No route dispatch, HTTP self-call, tool execution or certification.
			// Governed jobs match transport bootstrap; preserved callbacks may still stall.
			rest_get_server();
			$result = MAD4B_SCP_Connection_Status::endpoint_diagnostic( $input['server_id'] );
			if ( ! hash_equals( $build, self::build_fingerprint() ) ) return new WP_Error( 'mad4b_endpoint_diagnostic_build_changed', 'Plugin files changed during the diagnostic.', array( 'status' => 409 ) );
			return array( 'contract' => self::CONTRACT, 'build' => $build, 'server' => $result, 'elapsed_ms' => max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) ), 'connection_certified' => false, 'certification_performed' => false, 'foreign_transport_inventory_deferred' => true, 'tool_execution_performed' => false, 'outbound_discovery_performed' => false );
		} catch ( Throwable $error ) {
			// Never expose provider exception messages or filesystem paths.
			return new WP_Error( 'mad4b_endpoint_diagnostic_exception', 'Endpoint inspection failed.', array( 'status' => 500 ) );
		} finally {
			self::$authorized = false;
		}
	}
}
