<?php

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mad4b_browser_acceptance_test_providers'] = array();
$GLOBALS['mad4b_browser_acceptance_registered_actions'] = array();
$GLOBALS['mad4b_browser_acceptance_registered_abilities'] = array();
$GLOBALS['mad4b_browser_acceptance_registered_adapters'] = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10 ) {
		$GLOBALS['mad4b_browser_acceptance_registered_actions'][] = array( $hook, $callback, $priority );
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return 'mad4b_browser_acceptance_providers' === $hook ? $GLOBALS['mad4b_browser_acceptance_test_providers'] : $value;
	}
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		$GLOBALS['mad4b_browser_acceptance_registered_abilities'][ $name ] = $args;
		return true;
	}
}

class MAD4B_SCP_Policy {
	public static function can_read() { return true; }
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-browser-acceptance-provider-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-browser-acceptance-core.php';

$remote_parity_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-remote-operation-parity.php' );
$remote_queue_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-remote-work-queue.php' );
$staging_cert_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-certification.php' );
if ( ! is_string( $remote_parity_source ) || ! is_string( $remote_queue_source ) || ! is_string( $staging_cert_source ) ) {
	fwrite( STDERR, "FAIL: browser durable remote-work source files are unreadable\n" );
	exit( 1 );
}
foreach ( array(
	'final class MAD4B_SCP_Remote_Operation_Parity',
	'private static function work_queue_schema()',
	'public static function remote_work_queue(',
	'public static function claim_remote_work(',
	'public static function complete_remote_work(',
	'MAD4B_SCP_Remote_Operation_Parity::boot();',
) as $singleton ) {
	if ( 1 !== substr_count( $remote_parity_source, $singleton ) ) {
		fwrite( STDERR, "FAIL: Remote Operation Parity implementation is duplicated or missing: {$singleton}\n" );
		exit( 1 );
	}
}
foreach ( array(
	"const BROWSER_ACCEPTANCE_ABILITY = 'mad4b/browser-acceptance-run';",
	"'browser_acceptance_execution' => array(",
	'private static function browser_acceptance_schema()',
	'public static function queue_browser_acceptance(',
	'private static function complete_browser_acceptance_work(',
	'MAD4B_SCP_Browser_Acceptance_Core::result(',
	'mad4b_remote_browser_acceptance_not_verified',
) as $marker ) {
	if ( false === strpos( $remote_parity_source, $marker ) ) {
		fwrite( STDERR, "FAIL: Browser Acceptance remote parity invariant missing: {$marker}\n" );
		exit( 1 );
	}
}
if ( false === strpos( $remote_queue_source, "'browser_acceptance_execution' => array(" ) ) {
	fwrite( STDERR, "FAIL: Remote Work Queue does not admit Browser Acceptance semantic work\n" );
	exit( 1 );
}
foreach ( array(
	"mad4b.staging-browser-certification-view.v2",
	"MAD4B_SCP_Remote_Work_Queue::list_jobs( 'browser_acceptance_execution' )",
	"browser_runtime_parity_verified",
	"durable_receipt_used",
	"durable_job_id",
) as $marker ) {
	if ( false === strpos( $staging_cert_source, $marker ) ) {
		fwrite( STDERR, "FAIL: durable Browser Acceptance certification invariant missing: {$marker}\n" );
		exit( 1 );
	}
}


function browser_expect( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function browser_safe_descriptor( $id = 'fake-browser' ) {
	return array(
		'contract' => 'fake.browser-acceptance.v1',
		'provider_id' => $id,
		'read_only' => true,
		'authorizing' => false,
		'execution_mode' => 'external_browser_agent',
		'transport_owned_by_provider' => false,
		'browser_engine_owned_by_provider' => false,
		'external_browser_agent_required' => true,
		'arbitrary_url_input' => false,
		'arbitrary_javascript_input' => false,
		'business_state_mutation' => false,
		'profile_mutation' => false,
		'seo_mutation' => false,
		'production_activation' => false,
	);
}

function browser_safe_provider( $id = 'fake-browser' ) {
	return array(
		'provider_id' => $id,
		'contract' => 'fake.browser-acceptance.v1',
		'read_only' => true,
		'authorizing' => false,
		'execution_mode' => 'external_browser_agent',
		'transport_owned_by_provider' => false,
		'descriptor_callback' => function () use ( $id ) { return browser_safe_descriptor( $id ); },
		'capabilities_callback' => function () use ( $id ) {
			return array(
				'contract' => 'mad4b.browser-acceptance-capabilities.v1',
				'provider_id' => $id,
				'read_only' => true,
				'authorizing' => false,
				'execution_mode' => 'external_browser_agent',
				'capabilities' => array( 'browser.ajax_round_trip', 'browser.dataset_id_parity' ),
			);
		},
		'plan_callback' => function ( array $request ) use ( $id ) {
			$digest = hash( 'sha256', $id . '|' . $request['profile_id'] . '|browser_runtime' );
			$signature = hash( 'sha256', 'sig|' . $digest );
			return array(
				'contract' => 'mad4b.browser-acceptance-plan.v1',
				'provider_contract' => 'fake.browser-acceptance.v1',
				'provider_id' => $id,
				'profile_id' => $request['profile_id'],
				'suite' => 'browser_runtime',
				'state' => 'ready',
				'plan_digest' => $digest,
				'plan_signature' => $signature,
				'authorizing' => false,
				'read_only' => true,
				'blocking_reasons' => array(),
			);
		},
		'result_callback' => function ( array $request ) use ( $id ) {
			$digest = hash( 'sha256', $id . '|' . $request['profile_id'] . '|browser_runtime' );
			$signature = hash( 'sha256', 'sig|' . $digest );
			if ( $digest !== (string) $request['plan_digest'] || $signature !== (string) $request['plan_signature'] ) {
				return array(
					'contract' => 'mad4b.browser-acceptance-result.v1',
					'provider_id' => $id,
					'profile_id' => $request['profile_id'],
					'verification' => array( 'browser_runtime_parity_verified' => false ),
					'infrastructure_failures' => array( 'browser_plan_binding_mismatch' ),
					'defect_reasons' => array(),
					'incomplete_evidence' => array(),
					'classification' => 'TEST_INFRASTRUCTURE_FAILURE',
					'verdict' => 'BLOCKED',
				);
			}
			if ( empty( $request['evidence'] ) ) {
				return array(
					'contract' => 'mad4b.browser-acceptance-result.v1',
					'provider_id' => $id,
					'profile_id' => $request['profile_id'],
					'verification' => array( 'browser_runtime_parity_verified' => false ),
					'infrastructure_failures' => array(),
					'defect_reasons' => array(),
					'incomplete_evidence' => array( 'browser_runtime_not_observed' ),
					'classification' => 'INCOMPLETE_EVIDENCE',
					'verdict' => 'INCOMPLETE_EVIDENCE',
				);
			}
			$diverged = ! empty( $request['evidence']['force_divergence'] );
			return array(
				'contract' => 'mad4b.browser-acceptance-result.v1',
				'provider_id' => $id,
				'profile_id' => $request['profile_id'],
				'verification' => array( 'browser_runtime_parity_verified' => ! $diverged ),
				'infrastructure_failures' => array(),
				'defect_reasons' => $diverged ? array( 'browser_dataset_ids_mismatch' ) : array(),
				'incomplete_evidence' => array(),
				'classification' => $diverged ? 'PRODUCT_DEFECT' : 'NO_CONFIRMED_DEFECT',
				'verdict' => $diverged ? 'FAIL' : 'PASS',
			);
		},
	);
}

$GLOBALS['mad4b_browser_acceptance_test_providers'] = array( 'fake-browser' => browser_safe_provider() );
$registry = new MAD4B_SCP_Browser_Acceptance_Provider_Registry();
$all = $registry->all();
browser_expect( 1 === count( $all ) && isset( $all['fake-browser'] ), 'safe browser provider must be discovered' );
$inventory = $registry->inventory();
browser_expect( 1 === (int) $inventory['provider_count'], 'browser inventory must expose one safe provider' );
browser_expect( ! empty( $inventory['read_only'] ) && empty( $inventory['authorizing'] ), 'browser registry authority boundary drifted' );

MAD4B_SCP_Browser_Acceptance_Core::register_abilities();
$expected_abilities = array(
	'mad4b/browser-acceptance-capabilities',
	'mad4b/browser-acceptance-plan',
	'mad4b/browser-acceptance-result',
);
foreach ( $expected_abilities as $name ) {
	browser_expect( isset( $GLOBALS['mad4b_browser_acceptance_registered_abilities'][ $name ] ), 'missing browser acceptance ability: ' . $name );
	$args = $GLOBALS['mad4b_browser_acceptance_registered_abilities'][ $name ];
	browser_expect( true === ( $args['meta']['annotations']['readonly'] ?? null ), 'browser ability must be readonly: ' . $name );
	browser_expect( false === ( $args['meta']['annotations']['destructive'] ?? null ), 'browser ability must be non-destructive: ' . $name );
	browser_expect( empty( $args['meta']['public'] ) && empty( $args['meta']['mcp']['public'] ), 'browser ability must not leak to default/public MCP: ' . $name );
}
browser_expect( ! isset( $GLOBALS['mad4b_browser_acceptance_registered_abilities']['mad4b/browser-acceptance-run'] ), 'Browser Acceptance Core must not itself register the external execution orchestration ability' );

$result_schema = $GLOBALS['mad4b_browser_acceptance_registered_abilities']['mad4b/browser-acceptance-result']['input_schema'];
$evidence_schema = $result_schema['properties']['evidence'];
$case_schema = $evidence_schema['properties']['cases']['items'];
browser_expect( 8 === (int) $evidence_schema['properties']['cases']['maxItems'], 'browser evidence case limit must match provider MAX_CASES' );
browser_expect( 15 === (int) $case_schema['maxProperties'], 'browser case schema must include bounded performance evidence without opening arbitrary properties' );
$network_schema = $case_schema['properties']['network'];
browser_expect( isset( $network_schema['properties']['latency_ms'] ), 'browser network schema must expose bounded AJAX latency' );
$observer_schema = $evidence_schema['properties']['observer'];
browser_expect( isset( $observer_schema['properties']['execution_mode'] ), 'browser observer schema must expose bounded execution mode' );
browser_expect( 4 === (int) $observer_schema['maxProperties'], 'browser observer schema property budget drifted' );
$performance_schema = $case_schema['properties']['performance'];
foreach ( array( 'ttfb_ms', 'ajax_endpoint_latency_ms', 'filter_to_presentation_ms' ) as $metric ) {
	browser_expect( isset( $performance_schema['properties'][ $metric ] ), 'browser performance metric missing: ' . $metric );
}
$rendered_schema = $case_schema['properties']['rendered'];
foreach ( array( 'result_count_authoritative', 'result_count_source', 'ids_complete', 'digest_authoritative', 'proof_item_count', 'identity_digest', 'order_digest' ) as $field ) {
	browser_expect( isset( $rendered_schema['properties'][ $field ] ), 'browser rendered proof field missing: ' . $field );
}
foreach ( array( 'runtime', 'events', 'network', 'performance', 'rendered', 'url_state', 'seo', 'reset' ) as $section ) {
	browser_expect( false === $case_schema['properties'][ $section ]['additionalProperties'], 'browser evidence section must reject arbitrary properties: ' . $section );
}

$capabilities = MAD4B_SCP_Browser_Acceptance_Core::capabilities();
browser_expect( 'mad4b.browser-acceptance-capabilities.v1' === (string) $capabilities['contract'], 'unexpected browser capabilities contract' );
browser_expect( 'external_browser_agent' === (string) $capabilities['execution_mode'], 'browser execution ownership drifted' );
browser_expect( empty( $capabilities['authorizing'] ) && empty( $capabilities['browser_engine_authority'] ), 'browser core opened authority' );

$bad_plan = MAD4B_SCP_Browser_Acceptance_Core::plan( array( 'provider_id' => 'fake-browser', 'profile_id' => 'tours', 'url' => 'https://example.invalid' ) );
browser_expect( 'blocked' === (string) $bad_plan['state'], 'arbitrary browser URL input must fail closed' );
browser_expect( in_array( 'unsupported_request_fields', (array) $bad_plan['blocking_reasons'], true ), 'arbitrary browser URL rejection reason missing' );

$plan = MAD4B_SCP_Browser_Acceptance_Core::plan( array( 'provider_id' => 'fake-browser', 'profile_id' => 'tours', 'suite' => 'browser_runtime' ) );
browser_expect( 'ready' === (string) $plan['state'], 'safe browser plan should be ready' );
browser_expect( 64 === strlen( (string) $plan['plan_digest'] ) && 64 === strlen( (string) $plan['plan_signature'] ), 'browser plan binding must be explicit' );
browser_expect( empty( $plan['authorizing'] ) && ! empty( $plan['read_only'] ), 'browser plan authority boundary drifted' );

$missing = MAD4B_SCP_Browser_Acceptance_Core::result( array(
	'provider_id' => 'fake-browser',
	'profile_id' => 'tours',
	'suite' => 'browser_runtime',
	'plan_digest' => $plan['plan_digest'],
	'plan_signature' => $plan['plan_signature'],
) );
browser_expect( 'INCOMPLETE_EVIDENCE' === (string) $missing['verdict'], 'missing browser evidence must remain incomplete, not PASS' );
browser_expect( false === (bool) $missing['verification']['browser_runtime_parity_verified'], 'missing evidence must never verify browser parity' );

$stale = MAD4B_SCP_Browser_Acceptance_Core::result( array(
	'provider_id' => 'fake-browser',
	'profile_id' => 'tours',
	'suite' => 'browser_runtime',
	'plan_digest' => str_repeat( 'a', 64 ),
	'plan_signature' => $plan['plan_signature'],
	'evidence' => array( 'force_divergence' => false ),
) );
browser_expect( 'BLOCKED' === (string) $stale['verdict'], 'stale browser plan binding must block' );
browser_expect( 'TEST_INFRASTRUCTURE_FAILURE' === (string) $stale['classification'], 'stale browser plan must be infrastructure failure' );

$diverged = MAD4B_SCP_Browser_Acceptance_Core::result( array(
	'provider_id' => 'fake-browser',
	'profile_id' => 'tours',
	'suite' => 'browser_runtime',
	'plan_digest' => $plan['plan_digest'],
	'plan_signature' => $plan['plan_signature'],
	'evidence' => array( 'force_divergence' => true ),
) );
browser_expect( 'FAIL' === (string) $diverged['verdict'], 'trusted browser divergence must fail' );
browser_expect( 'PRODUCT_DEFECT' === (string) $diverged['classification'], 'trusted browser divergence must classify as product defect' );
browser_expect( false === (bool) $diverged['verification']['browser_runtime_parity_verified'], 'browser divergence must not verify parity' );

$passed = MAD4B_SCP_Browser_Acceptance_Core::result( array(
	'provider_id' => 'fake-browser',
	'profile_id' => 'tours',
	'suite' => 'browser_runtime',
	'plan_digest' => $plan['plan_digest'],
	'plan_signature' => $plan['plan_signature'],
	'evidence' => array( 'force_divergence' => false ),
) );
browser_expect( 'PASS' === (string) $passed['verdict'] && true === (bool) $passed['verification']['browser_runtime_parity_verified'], 'complete matching browser evidence must verify parity' );

$oversized = MAD4B_SCP_Browser_Acceptance_Core::result( array(
	'provider_id' => 'fake-browser',
	'profile_id' => 'tours',
	'suite' => 'browser_runtime',
	'plan_digest' => $plan['plan_digest'],
	'plan_signature' => $plan['plan_signature'],
	'evidence' => array( 'blob' => str_repeat( 'x', 131073 ) ),
) );
browser_expect( 'BLOCKED' === (string) $oversized['verdict'], 'oversized browser evidence must fail closed before provider execution' );
browser_expect( in_array( 'evidence_size_limit_exceeded', (array) $oversized['blocking_reasons'], true ), 'oversized evidence rejection reason missing' );

$deep_evidence = array( 'leaf' => true );
for ( $depth = 0; $depth < 9; $depth++ ) $deep_evidence = array( 'nested' => $deep_evidence );
$deep = MAD4B_SCP_Browser_Acceptance_Core::result( array(
	'provider_id' => 'fake-browser',
	'profile_id' => 'tours',
	'suite' => 'browser_runtime',
	'plan_digest' => $plan['plan_digest'],
	'plan_signature' => $plan['plan_signature'],
	'evidence' => $deep_evidence,
) );
browser_expect( 'BLOCKED' === (string) $deep['verdict'], 'deep browser evidence must fail closed before provider execution' );
browser_expect( in_array( 'evidence_depth_limit_exceeded', (array) $deep['blocking_reasons'], true ), 'evidence depth rejection reason missing' );

$wide = MAD4B_SCP_Browser_Acceptance_Core::result( array(
	'provider_id' => 'fake-browser',
	'profile_id' => 'tours',
	'suite' => 'browser_runtime',
	'plan_digest' => $plan['plan_digest'],
	'plan_signature' => $plan['plan_signature'],
	'evidence' => array( 'nodes' => array_fill( 0, 1100, 'x' ) ),
) );
browser_expect( 'BLOCKED' === (string) $wide['verdict'], 'wide browser evidence must fail closed before provider execution' );
browser_expect( in_array( 'evidence_node_limit_exceeded', (array) $wide['blocking_reasons'], true ), 'evidence node rejection reason missing' );

$unsafe = browser_safe_provider( 'unsafe-browser' );
$unsafe['descriptor_callback'] = function () { $d = browser_safe_descriptor( 'unsafe-browser' ); $d['arbitrary_javascript_input'] = true; return $d; };
$GLOBALS['mad4b_browser_acceptance_test_providers'] = array( 'unsafe-browser' => $unsafe );
browser_expect( 0 === count( ( new MAD4B_SCP_Browser_Acceptance_Provider_Registry() )->all() ), 'browser provider enabling arbitrary JavaScript must be rejected' );

$unsafe_effect = browser_safe_provider( 'unsafe-effect' );
$unsafe_effect['descriptor_callback'] = function () { $d = browser_safe_descriptor( 'unsafe-effect' ); $d['seo_mutation'] = true; return $d; };
$GLOBALS['mad4b_browser_acceptance_test_providers'] = array( 'unsafe-effect' => $unsafe_effect );
browser_expect( 0 === count( ( new MAD4B_SCP_Browser_Acceptance_Provider_Registry() )->all() ), 'browser provider opening SEO mutation must be rejected' );

echo "MAD4B Browser Acceptance Core contract smoke passed.\n";
