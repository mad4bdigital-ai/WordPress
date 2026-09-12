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
	require dirname( __DIR__ ) . '/includes/class-mad4b-scp-live-acceptance-finalizer.php';

	function mad4b_assert( $condition, $message ) {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
	}
	function mad4b_has_blocker( array $gate, $blocker ) {
		return in_array( $blocker, isset( $gate['blockers'] ) ? $gate['blockers'] : array(), true );
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

	$wpml_classes = array(
		'route_not_registered' => MAD4B_SCP_Live_Acceptance_Finalizer::classify_wpml_response( 404, array( 'code' => 'rest_no_route' ), 'rest_no_route', false ),
		'rest_no_route' => MAD4B_SCP_Live_Acceptance_Finalizer::classify_wpml_response( 404, array( 'code' => 'rest_no_route' ), 'rest_no_route', true ),
		'wp_error' => MAD4B_SCP_Live_Acceptance_Finalizer::classify_wpml_response( 500, null, 'internal_error', true ),
		'contract_mismatch' => MAD4B_SCP_Live_Acceptance_Finalizer::classify_wpml_response( 200, array( 'status' => 'valid', 'get_parameters' => 'invalid' ), '', true ),
		'non_json_response' => MAD4B_SCP_Live_Acceptance_Finalizer::classify_wpml_response( 200, '<html>oops</html>', '', true ),
		'success' => MAD4B_SCP_Live_Acceptance_Finalizer::classify_wpml_response( 200, array( 'status' => 'valid', 'get_parameters' => 'valid' ), '', true ),
	);
	foreach ( $wpml_classes as $expected => $result ) mad4b_assert( $expected === $result['classification'], 'WPML normalized classification failed for ' . $expected );

	$match = MAD4B_SCP_Live_Acceptance_Observer::snapshot_verify( array( 'client_snapshot_token' => 'sha256:' . str_repeat( 'a', 64 ) ) );
	mad4b_assert( ! empty( $match['exact_match'] ), 'Matching snapshot token must compare true.' );
	mad4b_assert( empty( MAD4B_SCP_Live_Acceptance_Observer::snapshot_verify( array( 'client_snapshot_token' => 'sha256:' . str_repeat( 'b', 64 ) ) )['exact_match'] ), 'Different snapshot token must compare false.' );
	mad4b_assert( empty( MAD4B_SCP_Live_Acceptance_Observer::snapshot_verify( array( 'client_snapshot_token' => '' ) )['exact_match'] ), 'Empty snapshot token must compare false.' );

	// Positive reachability + fail-closed matrix for authoritative finalizer receipts.
	$now = 1789130000;
	$candidate = array( 'ready' => true, 'source_commit_sha' => str_repeat( 'a', 40 ), 'build_fingerprint' => str_repeat( 'b', 64 ) );
	$before = str_repeat( '1', 64 );
	$after = str_repeat( '2', 64 );
	$mutation = array(
		'contract' => MAD4B_SCP_Live_Acceptance_Finalizer::MUTATION_CONTRACT,
		'candidate_sha' => $candidate['source_commit_sha'], 'build_fingerprint' => $candidate['build_fingerprint'],
		'environment' => 'staging', 'origin' => MAD4B_SCP_Live_Acceptance_Finalizer::STAGING_ORIGIN,
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

	$bad = $mutation; $bad['candidate_sha'] = str_repeat( 'c', 40 );
	$r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now );
	mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'candidate_mismatch' ), 'Wrong mutation SHA must fail closed.' );
	$bad = $mutation; $bad['build_fingerprint'] = str_repeat( 'd', 64 );
	$r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now );
	mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'build_fingerprint_mismatch' ), 'Wrong mutation fingerprint must fail closed.' );
	$bad = $mutation; $bad['observed_at'] = gmdate( 'c', $now - MAD4B_SCP_Live_Acceptance_Finalizer::MUTATION_TTL - 1 );
	$r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now );
	mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'stale_evidence' ), 'Stale mutation receipt must fail closed.' );
	$bad = $mutation; unset( $bad['execution_event_hash'] );
	$r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now );
	mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'partial_mutation_evidence' ), 'Partial mutation evidence must fail closed.' );
	$bad = $mutation; $bad['replay_denied'] = false;
	$r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now );
	mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'replay_denial_unverified' ), 'Replay not denied must fail closed.' );
	$bad = $mutation; $bad['undo_verified'] = false;
	$r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now );
	mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'undo_unverified' ), 'Missing undo proof must fail closed.' );
	$bad = $mutation; $bad['restored_sha256'] = str_repeat( '6', 64 );
	$r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_mutation_receipt( $bad, $candidate, $now );
	mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'restored_state_mismatch' ), 'Undo state drift must fail closed.' );

	$production = array(
		'contract' => MAD4B_SCP_Live_Acceptance_Finalizer::PRODUCTION_CONTRACT,
		'candidate_sha' => $candidate['source_commit_sha'], 'build_fingerprint' => $candidate['build_fingerprint'],
		'target' => 'production', 'origin' => MAD4B_SCP_Live_Acceptance_Finalizer::PRODUCTION_ORIGIN, 'environment' => 'production',
		'production_runtime_identity' => 'production:egypttourgates.com:stable',
		'baseline_snapshot_digest' => str_repeat( '7', 64 ), 'observed_snapshot_digest' => str_repeat( '7', 64 ),
		'baseline_plugin_snapshot_digest' => str_repeat( '8', 64 ), 'observed_plugin_snapshot_digest' => str_repeat( '8', 64 ),
		'checked_at' => gmdate( 'c', $now - 30 ), 'issued_at' => gmdate( 'c', $now - 20 ),
		'issuer' => MAD4B_SCP_Live_Acceptance_Finalizer::FINALIZER_ISSUER,
		'provenance' => MAD4B_SCP_Live_Acceptance_Finalizer::FINALIZER_PROVENANCE,
	);
	$production['evidence_digest'] = MAD4B_SCP_Live_Acceptance_Finalizer::production_receipt_digest( $production );
	$production_gate = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_production_receipt( $production, $candidate, true, $now );
	mad4b_assert( ! empty( $production_gate['ready'] ), 'Valid trusted Production receipt must become ready.' );
	$bad = $production; $bad['candidate_sha'] = str_repeat( 'c', 40 ); $bad['evidence_digest'] = MAD4B_SCP_Live_Acceptance_Finalizer::production_receipt_digest( $bad );
	$r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_production_receipt( $bad, $candidate, true, $now );
	mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'candidate_mismatch' ), 'Production wrong SHA must fail closed.' );
	$bad = $production; $bad['build_fingerprint'] = str_repeat( 'd', 64 ); $bad['evidence_digest'] = MAD4B_SCP_Live_Acceptance_Finalizer::production_receipt_digest( $bad );
	$r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_production_receipt( $bad, $candidate, true, $now );
	mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'build_fingerprint_mismatch' ), 'Production wrong fingerprint must fail closed.' );
	$bad = $production; $bad['checked_at'] = gmdate( 'c', $now - MAD4B_SCP_Live_Acceptance_Finalizer::PRODUCTION_TTL - 1 ); $bad['issued_at'] = gmdate( 'c', $now - MAD4B_SCP_Live_Acceptance_Finalizer::PRODUCTION_TTL ); $bad['evidence_digest'] = MAD4B_SCP_Live_Acceptance_Finalizer::production_receipt_digest( $bad );
	$r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_production_receipt( $bad, $candidate, true, $now );
	mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'stale_evidence' ), 'Stale Production receipt must fail closed.' );
	$r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_production_receipt( $production, $candidate, false, $now );
	mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'untrusted_finalizer_context' ), 'Untrusted Production finalizer must fail closed.' );
	$bad = $production; $bad['observed_snapshot_digest'] = str_repeat( '9', 64 ); $bad['evidence_digest'] = MAD4B_SCP_Live_Acceptance_Finalizer::production_receipt_digest( $bad );
	$r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_production_receipt( $bad, $candidate, true, $now );
	mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'production_snapshot_changed' ), 'Production snapshot drift must fail closed.' );
	$bad = $production; $bad['evidence_digest'] = str_repeat( '0', 64 );
	$r = MAD4B_SCP_Live_Acceptance_Finalizer::evaluate_production_receipt( $bad, $candidate, true, $now );
	mad4b_assert( empty( $r['ready'] ) && mad4b_has_blocker( $r, 'evidence_digest_mismatch' ), 'Tampered Production receipt must fail closed.' );

	$reachability = array(
		'external_wpml' => array( 'ready' => true ), 'external_handshake' => array( 'ready' => true ),
		'skills_runtime' => array( 'ready' => true ), 'write_authority' => array( 'ready' => true ),
		'write_runtime_certification' => array( 'ready' => true ), 'mutation_acceptance' => $mutation_gate,
		'production_unchanged' => $production_gate,
	);
	mad4b_assert( MAD4B_SCP_Live_Acceptance_Finalizer::aggregate_ready( $reachability ), 'All valid mandatory gates must make ready=true reachable.' );
	$reachability['production_unchanged']['ready'] = false;
	mad4b_assert( ! MAD4B_SCP_Live_Acceptance_Finalizer::aggregate_ready( $reachability ), 'A failed mandatory gate must keep ready=false.' );

	$GLOBALS['mad4b_test_env'] = 'production';
	$GLOBALS['mad4b_test_home'] = 'https://egypttourgates.com';
	mad4b_assert( false === MAD4B_SCP_Live_Acceptance_Observer::staging_capture_allowed(), 'Production must never enable Staging observation persistence.' );
	$GLOBALS['mad4b_test_env'] = 'staging';
	$GLOBALS['mad4b_test_home'] = 'https://other-staging.example';
	mad4b_assert( false === MAD4B_SCP_Live_Acceptance_Observer::staging_capture_allowed(), 'Non-exact Staging origin must remain fail-closed.' );

	echo "mad4b.live-acceptance-observer.runtime.v2: PASS\n";
}