<?php
namespace WP\MCP\Domain\Utils {
	final class McpNameSanitizer {
		public static function sanitize_name( $name ) { return str_replace( '/', '-', (string) $name ); }
	}
}
namespace {
	define( 'ABSPATH', '/srv/wordpress/' );
	define( 'MAD4B_SCP_DIR', '/srv/wordpress/wp-content/plugins/mad4b-site-control-plane/' );
	define( 'MAD4B_SCP_VERSION', '0.4.0-rc.22' );

	$GLOBALS['mad4b_test_env'] = 'staging';
	$GLOBALS['mad4b_test_home'] = 'https://staging.egypttourgates.com';
	$GLOBALS['mad4b_test_init'] = 0;
	$GLOBALS['mad4b_test_options'] = array();

	class WP_Error {
		private $code;
		public function __construct( $code = '' ) { $this->code = (string) $code; }
		public function get_error_code() { return $this->code; }
	}
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( (string) $value ); }
	function wp_normalize_path( $value ) { return str_replace( '\\', '/', (string) $value ); }
	function did_action( $name ) { return 'init' === $name ? (int) $GLOBALS['mad4b_test_init'] : 0; }
	function wp_get_environment_type() { return $GLOBALS['mad4b_test_env']; }
	function home_url( $path = '/' ) { return rtrim( $GLOBALS['mad4b_test_home'], '/' ) . '/' . ltrim( $path, '/' ); }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function is_admin() { return false; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function untrailingslashit( $value ) { return rtrim( (string) $value, '/' ); }
	function absint( $value ) { return abs( (int) $value ); }
	function add_action() {}
	function add_filter() {}
	function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['mad4b_test_options'] ) ? $GLOBALS['mad4b_test_options'][ $key ] : $default; }
	function update_option( $key, $value, $autoload = false ) { $GLOBALS['mad4b_test_options'][ $key ] = $value; return true; }
	function set_transient() { return true; }
	function get_transient() { return false; }
	function delete_transient() { return true; }

	class MAD4B_SCP_Site_Profile {
		public static function current_environment() { return $GLOBALS['mad4b_test_env']; }
		public static function site_origin() { return rtrim( $GLOBALS['mad4b_test_home'], '/' ); }
		public static function site_host() { return (string) parse_url( self::site_origin(), PHP_URL_HOST ); }
		public static function related_origin( $environment ) { return 'production' === (string) $environment ? 'https://production.test' : self::site_origin(); }
		public static function nonproduction_governed( $feature = '' ) { return in_array( self::current_environment(), array( 'local', 'development', 'staging' ), true ) && ( '' === $feature || 'acceptance' === $feature ); }
		public static function acceptance_enabled() { return true; }
		public static function site_urls_match_enrollment() { return ! isset( $GLOBALS['mad4b_test_urls_match'] ) || ! empty( $GLOBALS['mad4b_test_urls_match'] ); }
	}
	class MAD4B_SCP_Servers {
		public static function chatgpt_tools() { return array( 'mad4b/site-info', 'mad4b/write-discover', 'mad4b/write-info', 'mad4b/write-execute' ); }
		public static function external_write_tools() { return array( 'mad4b/content-update-post', 'elementor/update-widget-settings' ); }
		public static function write_tools() { return array( 'mad4b/content-update-post' ); }
		public static function blocked_write_tools() { return array( array( 'ability' => 'elementor/update-widget-settings' ) ); }
		public static function core_tools( $server ) { return 'mad4b-breakglass' === $server ? array( 'mad4b/database-raw-query' ) : array(); }
	}
	class MAD4B_SCP_Skill_Snapshot_Identity {
		public static function build() { return array( 'identity_token' => 'sha256:' . str_repeat( 'a', 64 ), 'skill_count' => 6, 'app_id' => 'plugin_asdk_app_test', 'ready' => true ); }
	}

	require dirname( __DIR__ ) . '/includes/class-mad4b-scp-live-acceptance-observer.php';
	require dirname( __DIR__ ) . '/includes/class-mad4b-scp-live-acceptance-finalizer.php';
	require dirname( __DIR__ ) . '/includes/class-mad4b-scp-production-unchanged-attestation.php';

	function mad4b_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
	function mad4b_has_blocker( array $gate, $blocker ) { return in_array( $blocker, isset( $gate['blockers'] ) ? $gate['blockers'] : array(), true ); }

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

	$stable_inventory = array( 'mad4b-site-info', 'mad4b-write-discover', 'mad4b-write-info', 'mad4b-write-execute' );
	$current_build_test = MAD4B_SCP_Live_Acceptance_Observer::inventory_attestation_from_names( $stable_inventory, str_repeat( '0', 64 ) );
	mad4b_assert( ! empty( $current_build_test['inventory_match'] ), 'Same exact minimal external transport must match independently of build freshness.' );
	mad4b_assert( empty( $current_build_test['verified'] ), 'Pure evaluator must never self-certify an external session.' );
	mad4b_assert( ! empty( $current_build_test['write_transport_ready'] ), 'Exact write transport trio must be recognized.' );
	mad4b_assert( empty( $current_build_test['direct_write_schema_leaks'] ), 'Logical write schemas must not leak into direct tools/list.' );
	mad4b_assert( ! empty( $current_build_test['write_inventory_fingerprint_match'] ), 'Logical write catalog must remain fingerprint-bound behind the transport.' );
	mad4b_assert( 2 === (int) $current_build_test['external_write_tool_count'], 'Logical external write count must remain complete.' );
	mad4b_assert( ! empty( $current_build_test['runtime_projection_deferred'] ), 'Pure tools/list evaluator must defer provider eligibility projection.' );
	mad4b_assert( 0 === (int) $current_build_test['eligible_write_tool_count'], 'Pure tools/list evaluator must not run provider eligibility projection.' );
	mad4b_assert( empty( $current_build_test['provider_gated_write_tools'] ), 'Pure tools/list evaluator must not run provider gate projection.' );
	mad4b_assert( empty( $current_build_test['provider_execution_mount_leaks'] ), 'Deferred provider projection must not invent execution leaks.' );

	$different = MAD4B_SCP_Live_Acceptance_Observer::inventory_attestation_from_names( array( 'mad4b-site-info', 'mad4b-write-discover', 'mad4b-write-execute', 'foreign-unexpected-tool' ), str_repeat( '0', 64 ) );
	mad4b_assert( empty( $different['inventory_match'] ), 'Same count/different transport names must fail.' );
	mad4b_assert( in_array( 'mad4b-write-info', $different['missing_expected_tools'], true ), 'Missing write-info transport must be diffed.' );
	mad4b_assert( in_array( 'foreign-unexpected-tool', $different['unexpected_tools'], true ), 'Unexpected tool must be diffed.' );
	mad4b_assert( empty( $different['write_transport_ready'] ), 'Missing write transport member must fail transport readiness.' );

	$direct_write_leak = MAD4B_SCP_Live_Acceptance_Observer::inventory_attestation_from_names( array_merge( $stable_inventory, array( 'mad4b-content-update-post' ) ), str_repeat( '0', 64 ) );
	mad4b_assert( ! empty( $direct_write_leak['direct_write_schema_leaks'] ), 'Direct underlying write schema exposure must be detected.' );
	mad4b_assert( in_array( 'mad4b-content-update-post', $direct_write_leak['direct_write_schema_leaks'], true ), 'Leaked underlying write tool must be named.' );
	mad4b_assert( empty( $direct_write_leak['write_inventory_fingerprint_match'] ), 'Direct write schema leakage must fail logical write transport acceptance.' );

	$missing_write_transport = MAD4B_SCP_Live_Acceptance_Observer::inventory_attestation_from_names( array( 'mad4b-site-info', 'mad4b-write-discover', 'mad4b-write-execute' ), str_repeat( '0', 64 ) );
	mad4b_assert( empty( $missing_write_transport['write_transport_ready'] ), 'Incomplete write transport must fail closed.' );
	mad4b_assert( empty( $missing_write_transport['write_inventory_fingerprint_match'] ), 'Incomplete write transport must fail write inventory acceptance.' );

	$raw_sql = MAD4B_SCP_Live_Acceptance_Observer::inventory_attestation_from_names( array_merge( $stable_inventory, array( 'mad4b-database-raw-query' ) ), str_repeat( '0', 64 ) );
	mad4b_assert( ! empty( $raw_sql['raw_sql_exposed'] ), 'Raw SQL exposure must fail explicit assertion.' );
	mad4b_assert( ! empty( $raw_sql['breakglass_exposed'] ), 'Breakglass exposure must fail explicit assertion.' );
	mad4b_assert( empty( $raw_sql['build_fingerprint_match'] ), 'Old/foreign build fingerprint must remain stale.' );

	$wpml = MAD4B_SCP_Live_Acceptance_Observer::evaluate_wpml_receipt( true, array( 'status' => 'valid', 'get_parameters' => 'valid' ), str_repeat( 'a', 64 ) );
	mad4b_assert( ! empty( $wpml['success'] ), 'Genuine valid incoming WPML response shape must pass the receipt evaluator.' );
	$wpml_bad = MAD4B_SCP_Live_Acceptance_Observer::evaluate_wpml_receipt( false, array( 'status' => 'valid', 'get_parameters' => 'valid' ), str_repeat( 'a', 64 ) );
	mad4b_assert( empty( $wpml_bad['success'] ), 'Missing test_get_parameter=1 must fail WPML receipt.' );

	$wpml_classes = array(
		'route_not_registered' => MAD4B_SCP_Live_Acceptance_Finalizer::classify_wpml_response( 404, array( 'code' => 'rest_no_route' ), 'rest_no_route', false ),
		'rest_no_route' => MAD4B_SCP_Live_Acceptance_Finalizer::classify_wpml_response( 404, array( 'code' => 'rest_no_route' ), 'rest_no_route', true ),
		'wp_error' => MAD4B_SCP_Live_Acceptance_Finalizer::classify_wpml_response( 500, null, 'internal_error', true ),
		'contract_mismatch' => MAD4B_SCP_Live_Acceptance_Finalizer::classify_wpml_response( 200, array( 'status' => 'valid', 'get_parameters' => 'invalid' ), '', true ),
		'non_json_response' => MAD4B_SCP_Live_Acceptance_Finalizer::classify_wpml_response( 200, '<html>oops</html>', '', true ),
		'success' => MAD4B_SCP_Live_Acceptance_Finalizer::classify_wpml_response( 200, array( 'status' => 'valid', 'get_parameters' => 'valid' ), '', true ),
	);
	foreach ( $wpml_classes as $expected => $result ) mad4b_assert( $expected === $result['classification'], 'WPML normalized classification failed for ' . $expected );

	$observer_reflection = new ReflectionClass( 'MAD4B_SCP_Live_Acceptance_Observer' );
	$current_build_method = $observer_reflection->getMethod( 'current_build_fingerprint' );
	$current_build_method->setAccessible( true );
	$current_build = (string) $current_build_method->invoke( null );
	mad4b_assert( 1 === preg_match( '/^[a-f0-9]{64}$/', $current_build ), 'Runtime fixture must resolve a current build fingerprint for performance evidence.' );
	$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_OPTION ] = array(
		'contract' => MAD4B_SCP_Live_Acceptance_Observer::QUERY_MONITOR_CONTRACT,
		'control_plane_version' => MAD4B_SCP_VERSION,
		'build_fingerprint' => $current_build,
		'capture_started_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		'last_observed_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ),
		'observed_request_count' => 1,
		'request_coverage' => array( 'mcp' => 0, 'rest' => 0, 'wp_admin' => 0, 'frontend' => 1 ),
		'counters' => array(
			'mad4b' => array(
				'doing_it_wrong' => 0, 'deprecated_function' => 0, 'deprecated_argument' => 0,
				'deprecated_hook' => 0, 'deprecated_class' => 0, 'ability_not_found' => 0,
				'wp_get_ability_missing' => 0, 'pre_init_abilities_violation' => 0,
			),
			'third_party' => array( 'fluentform_action_scheduler' => 0 ),
			'wordpress_core' => array(),
			'unknown' => array(),
		),
		'events' => array(),
		'performance' => array(
			'contract' => 'mad4b.frontend-performance-evidence.v1',
			'frontend_observed' => true,
			'rest_observed' => false,
			'samples' => array(
				array( 'request_class' => 'frontend', 'server_elapsed_ms' => 120.0, 'db_queries' => 35, 'peak_memory_bytes' => 15728640, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 3 ) ),
				array( 'request_class' => 'frontend', 'server_elapsed_ms' => 125.0, 'db_queries' => 37, 'peak_memory_bytes' => 16777216, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 2 ) ),
				array( 'request_class' => 'frontend', 'server_elapsed_ms' => 130.0, 'db_queries' => 36, 'peak_memory_bytes' => 16000000, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
			),
			'last_by_class' => array(
				'frontend' => array(
					'request_class' => 'frontend',
					'server_elapsed_ms' => 130.0,
					'db_queries' => 36,
					'peak_memory_bytes' => 16000000,
					'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ),
				),
			),
		),
	);
	$performance = MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status();
	mad4b_assert( ! empty( $performance['ready'] ), 'Current-build front-end performance window must become ready.' );
	mad4b_assert( 'ready' === (string) $performance['state'], 'Current-build front-end performance state must be ready.' );
	mad4b_assert( empty( $performance['baseline_only'] ) && ! empty( $performance['budget_evaluated'] ) && ! empty( $performance['budget_pass'] ), 'Front-end performance evidence must evaluate and pass the bounded regression budget.' );
	mad4b_assert( empty( $performance['ttfb_claimed'] ), 'Server elapsed evidence must not self-claim TTFB.' );
	mad4b_assert( 3 === (int) $performance['evaluation_window']['sample_count'], 'Front-end performance must require a bounded multi-sample window.' );
	mad4b_assert( 'median' === (string) $performance['evaluation_window']['server_elapsed_strategy'], 'Front-end elapsed evaluation must use the median.' );
	mad4b_assert( 'max' === (string) $performance['evaluation_window']['db_queries_strategy'], 'DB query evaluation must preserve worst-case pressure.' );
	mad4b_assert( 125.0 === (float) $performance['metrics']['server_elapsed_ms'], 'Front-end median server elapsed drifted.' );
	mad4b_assert( 37 === (int) $performance['metrics']['db_queries'], 'Front-end max DB query sample drifted.' );
	mad4b_assert( 16777216 === (int) $performance['metrics']['peak_memory_bytes'], 'Front-end max peak memory sample drifted.' );

	$telemetry_property = $observer_reflection->getProperty( 'telemetry' );
	$telemetry_property->setAccessible( true );

	// A single elapsed-time outlier must be visible but must not fail a healthy
	// bounded window. This prevents one noisy Staging request from flapping the
	// release gate while keeping the exact 2000 ms budget unchanged.
	$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_OPTION ]['performance']['samples'] = array(
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 125.0, 'db_queries' => 37, 'peak_memory_bytes' => 16777216, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 3 ) ),
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 140.0, 'db_queries' => 38, 'peak_memory_bytes' => 17000000, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 2 ) ),
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 5000.0, 'db_queries' => 39, 'peak_memory_bytes' => 17500000, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
	);
	$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_OPTION ]['performance']['last_by_class']['frontend']['server_elapsed_ms'] = 5000.0;
	$telemetry_property->setValue( null, null );
	$isolated_spike = MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status();
	mad4b_assert( ! empty( $isolated_spike['ready'] ), 'A single elapsed-time outlier must not flap an otherwise healthy performance window.' );
	mad4b_assert( 140.0 === (float) $isolated_spike['metrics']['server_elapsed_ms'], 'Elapsed-time median must remain robust to a single outlier.' );

	// Persistent slowdown must still fail closed.
	$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_OPTION ]['performance']['samples'] = array(
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 5000.0, 'db_queries' => 37, 'peak_memory_bytes' => 16777216, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 3 ) ),
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 5100.0, 'db_queries' => 38, 'peak_memory_bytes' => 17000000, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 2 ) ),
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 5200.0, 'db_queries' => 39, 'peak_memory_bytes' => 17500000, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
	);
	$telemetry_property->setValue( null, null );
	$over_budget = MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status();
	mad4b_assert( empty( $over_budget['ready'] ) && 'performance_budget_exceeded' === (string) $over_budget['state'], 'Persistent over-budget front-end performance must fail closed.' );
	mad4b_assert( in_array( 'server_elapsed_ms_budget_exceeded', (array) $over_budget['budget_failures'], true ), 'Performance budget failure reason must identify persistent server elapsed regression.' );

	// Query/memory pressure remains worst-case sensitive even when elapsed median
	// is healthy.
	$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_OPTION ]['performance']['samples'] = array(
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 125.0, 'db_queries' => 37, 'peak_memory_bytes' => 16777216, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 3 ) ),
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 130.0, 'db_queries' => 101, 'peak_memory_bytes' => 17000000, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 2 ) ),
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 135.0, 'db_queries' => 39, 'peak_memory_bytes' => 17500000, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
	);
	$telemetry_property->setValue( null, null );
	$query_spike = MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status();
	mad4b_assert( empty( $query_spike['ready'] ) && in_array( 'db_queries_budget_exceeded', (array) $query_spike['budget_failures'], true ), 'A DB query budget breach inside the bounded window must fail closed.' );

	// Fewer than the minimum number of current-build frontend samples may not
	// certify performance.
	$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_OPTION ]['performance']['samples'] = array(
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 125.0, 'db_queries' => 37, 'peak_memory_bytes' => 16777216, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 2 ) ),
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 130.0, 'db_queries' => 38, 'peak_memory_bytes' => 17000000, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
	);
	$telemetry_property->setValue( null, null );
	$insufficient = MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status();
	mad4b_assert( empty( $insufficient['ready'] ) && 'insufficient_frontend_samples' === (string) $insufficient['state'], 'Performance certification must require the minimum current-build frontend sample count.' );

	// Restore a healthy window for the remaining aggregate tests.
	$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_OPTION ]['performance']['samples'] = array(
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 120.0, 'db_queries' => 35, 'peak_memory_bytes' => 15728640, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 3 ) ),
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 125.0, 'db_queries' => 37, 'peak_memory_bytes' => 16777216, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 2 ) ),
		array( 'request_class' => 'frontend', 'server_elapsed_ms' => 130.0, 'db_queries' => 36, 'peak_memory_bytes' => 16000000, 'observed_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
	);
	$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_OPTION ]['performance']['last_by_class']['frontend']['server_elapsed_ms'] = 130.0;
	$telemetry_property->setValue( null, null );

	$match = MAD4B_SCP_Live_Acceptance_Observer::snapshot_verify( array( 'client_snapshot_token' => 'sha256:' . str_repeat( 'a', 64 ) ) );
	mad4b_assert( ! empty( $match['exact_match'] ), 'Matching snapshot token must compare true.' );
	mad4b_assert( empty( MAD4B_SCP_Live_Acceptance_Observer::snapshot_verify( array( 'client_snapshot_token' => 'sha256:' . str_repeat( 'b', 64 ) ) )['exact_match'] ), 'Different snapshot token must compare false.' );
	mad4b_assert( empty( MAD4B_SCP_Live_Acceptance_Observer::snapshot_verify( array( 'client_snapshot_token' => '' ) )['exact_match'] ), 'Empty snapshot token must compare false.' );

	$now = 1789130000;
	$candidate = array( 'ready' => true, 'source_commit_sha' => str_repeat( 'a', 40 ), 'build_fingerprint' => str_repeat( 'b', 64 ) );
	$before = str_repeat( '1', 64 ); $after = str_repeat( '2', 64 );
	$mutation = array(
		'contract' => MAD4B_SCP_Live_Acceptance_Finalizer::MUTATION_CONTRACT,
		'candidate_sha' => $candidate['source_commit_sha'], 'build_fingerprint' => $candidate['build_fingerprint'],
		'environment' => 'staging', 'origin' => MAD4B_SCP_Site_Profile::site_origin(),
		'mutation_id' => '11111111-1111-4111-8111-111111111111', 'approval_ticket_id' => '22222222-2222-4222-8222-222222222222',
		'ability' => 'mad4b/content-update-post', 'provider' => 'core', 'target_type' => 'post', 'target_id' => '123',
		'before_sha256' => $before, 'after_sha256' => $after, 'mutation_status' => 'undone',
		'approval_status' => 'used', 'approved_by' => 1, 'approved_at' => gmdate( 'c', $now - 180 ), 'used_at' => gmdate( 'c', $now - 160 ),
		'execution_verified' => true, 'execution_event_hash' => str_repeat( '3', 64 ), 'execution_sequence' => 10,
		'replay_denied' => true, 'replay_event_hash' => str_repeat( '4', 64 ), 'replay_sequence' => 20,
		'undo_verified' => true, 'undo_event_hash' => str_repeat( '5', 64 ), 'undo_sequence' => 30,
		'recovery_mutation_id' => '33333333-3333-4333-8333-333333333333',
		'restored_sha256' => $before, 'recovery_before_sha256' => $after, 'recovery_after_sha256' => $before,
		'recovery_verification_code' => 'restore_readback_match', 'audit_chain_valid' => true,
		'observed_at' => gmdate( 'c', $now - 60 ),
	);
	$mutation_gate = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $mutation, $candidate, $now );
	mad4b_assert( ! empty( $mutation_gate['ready'] ), 'Valid authoritative mutation receipt must become ready.' );
	$bad = $mutation; $bad['candidate_sha'] = str_repeat( 'c', 40 ); $r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'candidate_mismatch' ), 'Wrong mutation SHA must fail closed.' );
	$bad = $mutation; $bad['build_fingerprint'] = str_repeat( 'd', 64 ); $r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'build_fingerprint_mismatch' ), 'Wrong mutation fingerprint must fail closed.' );
	$bad = $mutation; $bad['observed_at'] = gmdate( 'c', $now - MAD4B_SCP_Live_Acceptance_Finalizer::MUTATION_TTL - 1 ); $r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'stale_evidence' ), 'Stale mutation receipt must fail closed.' );
	$bad = $mutation; unset( $bad['execution_event_hash'] ); $r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'partial_mutation_evidence' ), 'Partial mutation evidence must fail closed.' );
	$bad = $mutation; $bad['replay_denied'] = false; $r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'replay_denial_unverified' ), 'Replay not denied must fail closed.' );
	$bad = $mutation; $bad['undo_verified'] = false; $r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'undo_unverified' ), 'Missing undo proof must fail closed.' );
	$bad = $mutation; $bad['restored_sha256'] = str_repeat( '6', 64 ); $r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'restored_state_mismatch' ), 'Undo state drift must fail closed.' );

	$runtime = array(
		'contract' => MAD4B_SCP_Production_Unchanged_Attestation::RUNTIME_CONTRACT,
		'environment' => 'production', 'site_url' => MAD4B_SCP_Site_Profile::related_origin( 'production' ),
		'wordpress_version' => '7.1', 'php_version' => '8.3.33', 'db_server_info' => '11.8.9-MariaDB-log',
		'active_theme_stylesheet' => 'astra-child', 'active_theme_version' => '1.0.0',
	);
	$plugins = array(
		array( 'plugin_file' => 'mcp-adapter/mcp-adapter.php', 'version' => '0.6.1', 'active' => true ),
		array( 'plugin_file' => 'mad4b-site-control-plane/mad4b-site-control-plane.php', 'version' => '0.4.0-rc.30', 'active' => true ),
		array( 'plugin_file' => 'etg-dynamic-filter-seo-bridge/etg-dynamic-filter-seo-bridge.php', 'version' => '0.4.0-alpha.13', 'active' => true ),
	);
	$producer = array(
		'contract' => MAD4B_SCP_Production_Unchanged_Attestation::PRODUCER_CONTRACT, 'read_only' => true,
		'environment_ability' => MAD4B_SCP_Production_Unchanged_Attestation::ENVIRONMENT_ABILITY,
		'site_ability' => MAD4B_SCP_Production_Unchanged_Attestation::SITE_ABILITY,
		'plugin_inventory_ability' => MAD4B_SCP_Production_Unchanged_Attestation::PLUGIN_ABILITY,
	);
	$production = array(
		'contract' => MAD4B_SCP_Production_Unchanged_Attestation::CONTRACT,
		'candidate_sha' => $candidate['source_commit_sha'], 'build_fingerprint' => $candidate['build_fingerprint'],
		'target' => 'production', 'origin' => MAD4B_SCP_Site_Profile::related_origin( 'production' ), 'environment' => 'production',
		'producer' => $producer,
		'baseline' => array( 'observed_at' => gmdate( 'c', $now - 120 ), 'runtime' => $runtime, 'plugin_inventory_contract' => MAD4B_SCP_Production_Unchanged_Attestation::PLUGIN_CONTRACT, 'plugins' => $plugins ),
		'observed' => array( 'observed_at' => gmdate( 'c', $now - 60 ), 'runtime' => $runtime, 'plugin_inventory_contract' => MAD4B_SCP_Production_Unchanged_Attestation::PLUGIN_CONTRACT, 'plugins' => array_reverse( $plugins ) ),
		'issued_at' => gmdate( 'c', $now - 30 ), 'issuer' => MAD4B_SCP_Production_Unchanged_Attestation::FINALIZER_ISSUER,
		'provenance' => MAD4B_SCP_Production_Unchanged_Attestation::FINALIZER_PROVENANCE,
	);
	$production['evidence_digest'] = MAD4B_SCP_Production_Unchanged_Attestation::receipt_digest( $production );
	$production_gate = MAD4B_SCP_Production_Unchanged_Attestation::evaluate_receipt( $production, $candidate, true, $now );
	mad4b_assert( ! empty( $production_gate['ready'] ), 'Valid reproducible Production v2 receipt must become ready.' );
	mad4b_assert( 3 === $production_gate['plugin_count'], 'Production v2 must report normalized plugin count.' );
	mad4b_assert( ! empty( $production_gate['producer_verified'] ), 'Production v2 producer must be verified.' );
	mad4b_assert( MAD4B_SCP_Production_Unchanged_Attestation::plugin_digest( MAD4B_SCP_Production_Unchanged_Attestation::normalize_plugins( $plugins ) ) === MAD4B_SCP_Production_Unchanged_Attestation::plugin_digest( MAD4B_SCP_Production_Unchanged_Attestation::normalize_plugins( array_reverse( $plugins ) ) ), 'Plugin snapshot must be order-invariant after normalization.' );

	$bad = $production; $bad['candidate_sha'] = str_repeat( 'c', 40 ); $bad['evidence_digest'] = MAD4B_SCP_Production_Unchanged_Attestation::receipt_digest( $bad ); $r = MAD4B_SCP_Production_Unchanged_Attestation::evaluate_receipt( $bad, $candidate, true, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'candidate_mismatch' ), 'Production v2 wrong SHA must fail closed.' );
	$bad = $production; $bad['producer']['read_only'] = false; $bad['evidence_digest'] = MAD4B_SCP_Production_Unchanged_Attestation::receipt_digest( $bad ); $r = MAD4B_SCP_Production_Unchanged_Attestation::evaluate_receipt( $bad, $candidate, true, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'untrusted_production_observation_producer' ), 'Writable Production producer must fail closed.' );
	$bad = $production; $bad['observed']['runtime']['php_version'] = '8.4.0'; $bad['evidence_digest'] = MAD4B_SCP_Production_Unchanged_Attestation::receipt_digest( $bad ); $r = MAD4B_SCP_Production_Unchanged_Attestation::evaluate_receipt( $bad, $candidate, true, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'production_snapshot_changed' ), 'Production runtime drift must fail closed.' );
	$bad = $production; $bad['observed']['plugins'][0]['version'] = '9.9.9'; $bad['evidence_digest'] = MAD4B_SCP_Production_Unchanged_Attestation::receipt_digest( $bad ); $r = MAD4B_SCP_Production_Unchanged_Attestation::evaluate_receipt( $bad, $candidate, true, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'production_plugin_snapshot_changed' ), 'Production plugin drift must fail closed.' );
	$bad = $production; $bad['observed']['plugins'][] = $bad['observed']['plugins'][0]; $bad['evidence_digest'] = MAD4B_SCP_Production_Unchanged_Attestation::receipt_digest( $bad ); $r = MAD4B_SCP_Production_Unchanged_Attestation::evaluate_receipt( $bad, $candidate, true, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'production_observation_invalid' ), 'Duplicate Production plugin path must fail closed.' );
	$bad = $production; $bad['baseline']['observed_at'] = gmdate( 'c', $now - MAD4B_SCP_Production_Unchanged_Attestation::TTL - 1 ); $bad['evidence_digest'] = MAD4B_SCP_Production_Unchanged_Attestation::receipt_digest( $bad ); $r = MAD4B_SCP_Production_Unchanged_Attestation::evaluate_receipt( $bad, $candidate, true, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'stale_evidence' ), 'Stale Production v2 receipt must fail closed.' );
	$bad = $production; $bad['contract'] = MAD4B_SCP_Live_Acceptance_Finalizer::PRODUCTION_CONTRACT; $bad['evidence_digest'] = MAD4B_SCP_Production_Unchanged_Attestation::receipt_digest( $bad ); $r = MAD4B_SCP_Production_Unchanged_Attestation::evaluate_receipt( $bad, $candidate, true, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'production_receipt_v2_required' ), 'Opaque Production v1 contract must not close the v2 gate.' );
	$bad = $production; $bad['evidence_digest'] = str_repeat( '0', 64 ); $r = MAD4B_SCP_Production_Unchanged_Attestation::evaluate_receipt( $bad, $candidate, true, $now ); mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'evidence_digest_mismatch' ), 'Tampered Production v2 receipt must fail closed.' );

	$reachability = array( 'external_wpml' => array( 'ready' => true ), 'external_handshake' => array( 'ready' => true ), 'skills_runtime' => array( 'ready' => true ), 'write_authority' => array( 'ready' => true ), 'write_runtime_certification' => array( 'ready' => true ), 'mutation_acceptance' => $mutation_gate, 'production_unchanged' => $production_gate );
	mad4b_assert( MAD4B_SCP_Live_Acceptance_Finalizer::aggregate_ready( $reachability ), 'All valid mandatory gates must make ready=true reachable.' );
	$reachability['production_unchanged']['ready'] = false;
	mad4b_assert( ! MAD4B_SCP_Live_Acceptance_Finalizer::aggregate_ready( $reachability ), 'A failed mandatory gate must keep ready=false.' );

	$GLOBALS['mad4b_test_env'] = 'production'; $GLOBALS['mad4b_test_home'] = 'https://production.test';
	mad4b_assert( false === MAD4B_SCP_Live_Acceptance_Observer::staging_capture_allowed(), 'Production must never enable governed non-production observation persistence.' );
	$GLOBALS['mad4b_test_env'] = 'staging'; $GLOBALS['mad4b_test_home'] = 'https://other-staging.example'; $GLOBALS['mad4b_test_urls_match'] = false;
	mad4b_assert( false === MAD4B_SCP_Live_Acceptance_Observer::staging_capture_allowed(), 'Site Profile URL mismatch must remain fail-closed.' );

	echo "mad4b.live-acceptance-observer.runtime.v4: PASS\n";
}
