<?php
namespace WP\MCP\Domain\Utils {
	final class McpNameSanitizer {
		public static function sanitize_name( $name ) { return str_replace( '/', '-', (string) $name ); }
	}
}
namespace {
	define( 'ABSPATH', '/srv/wordpress/' );
	define( 'MAD4B_SCP_DIR', '/srv/wordpress/wp-content/plugins/mad4b-site-control-plane/' );
	define( 'MAD4B_SCP_VERSION', '0.4.0-rc.21' );

	$GLOBALS['mad4b_test_env'] = 'staging';
	$GLOBALS['mad4b_test_home'] = 'https://staging.egypttourgates.com';
	$GLOBALS['mad4b_test_init'] = 0;

	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( (string) $value ); }
	function wp_normalize_path( $value ) { return str_replace( '\\', '/', (string) $value ); }
	function did_action( $name ) { return 'init' === $name ? (int) $GLOBALS['mad4b_test_init'] : 0; }
	function wp_get_environment_type() { return $GLOBALS['mad4b_test_env']; }
	function home_url( $path = '/' ) { return rtrim( $GLOBALS['mad4b_test_home'], '/' ) . '/' . ltrim( $path, '/' ); }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function is_admin() { return false; }
	function is_wp_error( $value ) { return false; }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function untrailingslashit( $value ) { return rtrim( (string) $value, '/' ); }
	function absint( $value ) { return abs( (int) $value ); }
	function add_action() {}
	function add_filter() {}
	function get_option( $key, $default = false ) { return $default; }
	function update_option() { return true; }
	function set_transient() { return true; }
	function get_transient() { return false; }
	function delete_transient() { return true; }

	class MAD4B_SCP_Servers {
		public static function chatgpt_tools() { return array( 'mad4b/site-info', 'mad4b/content-update-post' ); }
		public static function write_tools() { return array( 'mad4b/content-update-post' ); }
		public static function blocked_write_tools() { return array( array( 'ability' => 'elementor/update-widget-settings' ) ); }
		public static function core_tools( $server ) { return 'mad4b-breakglass' === $server ? array( 'mad4b/database-raw-query' ) : array(); }
	}
	class MAD4B_SCP_Skill_Snapshot_Identity {
		public static function build() { return array( 'identity_token' => 'sha256:' . str_repeat( 'a', 64 ), 'skill_count' => 6, 'app_id' => 'plugin_asdk_app_test', 'ready' => true ); }
	}

	require dirname( __DIR__ ) . '/includes/class-mad4b-scp-live-acceptance-observer.php';

	function mad4b_assert( $condition, $message ) {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
	}

	$mad4b_trace = array( array( 'file' => MAD4B_SCP_DIR . 'includes/example.php', 'class' => 'MAD4B_Test', 'function' => 'run' ) );
	$classification = MAD4B_SCP_Live_Acceptance_Observer::classify_warning_for_test( 'doing_it_wrong', 'wp_get_ability', 'Ability "mad4b/test" not found before Abilities init', $mad4b_trace );
	mad4b_assert( 'mad4b' === $classification['bucket'], 'MAD4B caller must be attributed to MAD4B.' );
	mad4b_assert( ! empty( $classification['ability_not_found'] ), 'Ability-not-found must be classified.' );
	mad4b_assert( ! empty( $classification['wp_get_ability_missing'] ), 'wp_get_ability warning must be classified.' );
	mad4b_assert( ! empty( $classification['pre_init_abilities_violation'] ), 'Pre-init Abilities misuse must be classified.' );

	$deprecated = MAD4B_SCP_Live_Acceptance_Observer::classify_warning_for_test( 'deprecated_function', 'seems_utf8', 'Deprecated function seems_utf8', $mad4b_trace );
	mad4b_assert( 'mad4b' === $deprecated['bucket'], 'Synthetic seems_utf8 event must classify without invoking the deprecated function.' );

	$fluent = MAD4B_SCP_Live_Acceptance_Observer::classify_warning_for_test( 'doing_it_wrong', 'as_next_scheduled_action', 'Action Scheduler data store was not initialized', array() );
	mad4b_assert( 'third_party' === $fluent['bucket'], 'Fluent Forms/Action Scheduler-like warning must be third party.' );
	mad4b_assert( 'third_party_non_blocking' === $fluent['severity'], 'Fluent Forms baseline must be non-blocking.' );
	mad4b_assert( 'fluentform' === $fluent['component'], 'Fluent Forms component must be explicit.' );

	$sanitized = MAD4B_SCP_Live_Acceptance_Observer::sanitize_warning_message( 'Authorization: Bearer abc.def.ghi password=hunter2 /home/user/site/wp-content/plugins/example.php' );
	mad4b_assert( false === stripos( $sanitized, 'abc.def.ghi' ), 'Bearer token must be redacted.' );
	mad4b_assert( false === stripos( $sanitized, 'hunter2' ), 'Password must be redacted.' );
	mad4b_assert( false === strpos( $sanitized, '/home/user/site' ), 'Absolute filesystem path must be redacted.' );

	$current_build_test = MAD4B_SCP_Live_Acceptance_Observer::inventory_attestation_from_names( array( 'mad4b-site-info', 'mad4b-content-update-post' ), str_repeat( '0', 64 ) );
	mad4b_assert( ! empty( $current_build_test['inventory_match'] ), 'Same exact external set must match inventory independently of build freshness.' );
	mad4b_assert( empty( $current_build_test['verified'] ), 'Pure evaluator must never self-certify an external session.' );

	$different = MAD4B_SCP_Live_Acceptance_Observer::inventory_attestation_from_names( array( 'mad4b-site-info', 'mad4b-other-write' ), str_repeat( '0', 64 ) );
	mad4b_assert( empty( $different['inventory_match'] ), 'Same count/different names must fail.' );
	mad4b_assert( in_array( 'mad4b-content-update-post', $different['missing_expected_tools'], true ), 'Missing expected tool must be diffed.' );
	mad4b_assert( in_array( 'mad4b-other-write', $different['unexpected_tools'], true ), 'Unexpected tool must be diffed.' );
	mad4b_assert( ! empty( $different['foreign_write_tool_exposed'] ), 'Unexpected external tool must be fail-closed as foreign exposure.' );

	$missing_write = MAD4B_SCP_Live_Acceptance_Observer::inventory_attestation_from_names( array( 'mad4b-site-info' ), str_repeat( '0', 64 ) );
	mad4b_assert( empty( $missing_write['write_inventory_fingerprint_match'] ), 'Missing core write must fail write inventory parity.' );

	$provider_leak = MAD4B_SCP_Live_Acceptance_Observer::inventory_attestation_from_names( array( 'mad4b-site-info', 'mad4b-content-update-post', 'elementor-update-widget-settings' ), str_repeat( '0', 64 ) );
	mad4b_assert( in_array( 'elementor-update-widget-settings', $provider_leak['provider_blocked_tool_leaks'], true ), 'Provider-blocked leak must be explicit.' );

	$raw_sql = MAD4B_SCP_Live_Acceptance_Observer::inventory_attestation_from_names( array( 'mad4b-site-info', 'mad4b-content-update-post', 'mad4b-database-raw-query' ), str_repeat( '0', 64 ) );
	mad4b_assert( ! empty( $raw_sql['raw_sql_exposed'] ), 'Raw SQL exposure must fail explicit assertion.' );
	mad4b_assert( ! empty( $raw_sql['breakglass_exposed'] ), 'Breakglass exposure must fail explicit assertion.' );
	mad4b_assert( empty( $raw_sql['build_fingerprint_match'] ), 'Old/foreign build fingerprint must remain stale.' );

	$wpml = MAD4B_SCP_Live_Acceptance_Observer::evaluate_wpml_receipt( true, array( 'status' => 'valid', 'get_parameters' => 'valid' ), str_repeat( 'a', 64 ) );
	mad4b_assert( ! empty( $wpml['success'] ), 'Genuine valid incoming WPML response shape must pass the receipt evaluator.' );
	$wpml_bad = MAD4B_SCP_Live_Acceptance_Observer::evaluate_wpml_receipt( false, array( 'status' => 'valid', 'get_parameters' => 'valid' ), str_repeat( 'a', 64 ) );
	mad4b_assert( empty( $wpml_bad['success'] ), 'Missing test_get_parameter=1 must fail WPML receipt.' );

	$match = MAD4B_SCP_Live_Acceptance_Observer::snapshot_verify( array( 'client_snapshot_token' => 'sha256:' . str_repeat( 'a', 64 ) ) );
	mad4b_assert( ! empty( $match['exact_match'] ), 'Matching snapshot token must compare true.' );
	mad4b_assert( empty( MAD4B_SCP_Live_Acceptance_Observer::snapshot_verify( array( 'client_snapshot_token' => 'sha256:' . str_repeat( 'b', 64 ) ) )['exact_match'] ), 'Different snapshot token must compare false.' );
	mad4b_assert( empty( MAD4B_SCP_Live_Acceptance_Observer::snapshot_verify( array( 'client_snapshot_token' => '' ) )['exact_match'] ), 'Empty snapshot token must compare false.' );

	$GLOBALS['mad4b_test_env'] = 'production';
	$GLOBALS['mad4b_test_home'] = 'https://egypttourgates.com';
	mad4b_assert( false === MAD4B_SCP_Live_Acceptance_Observer::staging_capture_allowed(), 'Production must never enable Staging observation persistence.' );
	$GLOBALS['mad4b_test_env'] = 'staging';
	$GLOBALS['mad4b_test_home'] = 'https://other-staging.example';
	mad4b_assert( false === MAD4B_SCP_Live_Acceptance_Observer::staging_capture_allowed(), 'Non-exact Staging origin must remain fail-closed.' );

	echo "mad4b.live-acceptance-observer.runtime.v1: PASS\n";
}
