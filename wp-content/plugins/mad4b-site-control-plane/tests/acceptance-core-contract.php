<?php

define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); } }
$GLOBALS['mad4b_acceptance_test_providers'] = array();
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { return 'mad4b_live_acceptance_providers' === $hook ? $GLOBALS['mad4b_acceptance_test_providers'] : $value; } }

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-acceptance-provider-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-acceptance-planner.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-acceptance-verdict-reducer.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-acceptance-runner.php';

function expect_true( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }

$core_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-acceptance-core.php' );
expect_true( false !== strpos( $core_source, "self::add_read_ability( 'mad4b/acceptance-capabilities', 'Get Acceptance Capabilities', array( __CLASS__, 'capabilities' ), array() );" ), 'no-input capabilities ability must not declare an object input schema' );
expect_true( false !== strpos( $core_source, "'maxProperties' => 8" ), 'selector transport envelope must bound property count' );
expect_true( false !== strpos( $core_source, "'additionalProperties' => array( 'type' => 'string', 'maxLength' => 256 )" ), 'unsupported selector fields must be bounded strings so Planner can return structured fail-closed evidence' );

function safe_descriptor( $id = 'fake' ) {
	return array(
		'contract' => 'fake.acceptance.v1', 'provider_id' => $id, 'read_only' => true, 'authorizing' => false,
		'profile_mutation' => false, 'transport_owned_by_provider' => false,
		'arbitrary_url_input' => false, 'arbitrary_query_input' => false, 'arbitrary_taxonomy_input' => false, 'arbitrary_http_input' => false,
		'suites' => array( 'semantic' ),
		'effects' => array( 'business_state_mutation' => false, 'authority_mutation' => false, 'seo_mutation' => false, 'profile_mutation' => false, 'observational_persistence' => false ),
	);
}
function safe_provider( $id = 'fake' ) {
	return array(
		'provider_id' => $id, 'contract' => 'fake.acceptance.v1', 'read_only' => true, 'authorizing' => false, 'profile_mutation' => false, 'transport_owned_by_provider' => false,
		'descriptor_callback' => function () use ( $id ) { return safe_descriptor( $id ); },
		'capabilities_callback' => function () use ( $id ) { return array( 'provider_id' => $id, 'authorizing' => false, 'capabilities' => array( 'semantic.parity' ) ); },
		'plan_callback' => function ( array $request ) use ( $id ) {
			$digest = hash( 'sha256', $id . '|' . $request['profile_id'] . '|semantic' );
			return array( 'contract' => 'fake.plan.v1', 'provider_id' => $id, 'profile_id' => $request['profile_id'], 'suite' => 'semantic', 'state' => 'ready', 'plan_digest' => $digest, 'authorizing' => false, 'read_only' => true, 'blocking_reasons' => array() );
		},
		'run_callback' => function ( array $request ) use ( $id ) {
			$digest = hash( 'sha256', $id . '|' . $request['profile_id'] . '|semantic' );
			return array(
				'contract' => 'fake.acceptance.v1', 'provider_id' => $id, 'profile_id' => $request['profile_id'], 'suite' => 'semantic', 'plan_digest' => $digest,
				'verification' => array( 'semantic_parity_verified' => true, 'browser_runtime_parity_verified' => false, 'verified_through' => 'live_server_semantic' ),
				'tests' => array( 'semantic_parity' => 'PASS' ), 'blocking_reasons' => array(), 'incomplete_evidence' => array( 'browser_runtime_not_observed' ), 'defect_reasons' => array(),
				'classification' => 'NO_DEFECT', 'verdict' => 'PASS',
				'authority' => array( 'authorizing' => false, 'persistent_mutation' => false, 'profile_mutation' => false, 'seo_publication' => false, 'production_activation' => false ),
				'effects' => array( 'business_state_mutation' => false, 'authority_mutation' => false, 'seo_mutation' => false, 'profile_mutation' => false, 'observational_persistence' => false ),
			);
		},
	);
}

$GLOBALS['mad4b_acceptance_test_providers'] = array( 'fake' => safe_provider() );
$registry = new MAD4B_SCP_Acceptance_Provider_Registry();
$planner = new MAD4B_SCP_Acceptance_Planner( $registry );
$reducer = new MAD4B_SCP_Acceptance_Verdict_Reducer();
$runner = new MAD4B_SCP_Acceptance_Runner( $registry, $planner, $reducer );

$all = $registry->all();
expect_true( 1 === count( $all ) && isset( $all['fake'] ), 'valid provider is discovered' );
$plan = $planner->plan( array( 'profile_id' => 'tours', 'suite' => 'semantic' ) );
expect_true( 'ready' === $plan['state'] && 'fake' === $plan['provider_id'], 'single provider resolves deterministically' );
expect_true( 64 === strlen( $plan['plan_digest'] ), 'provider plan digest is preserved' );

$bad = $planner->plan( array( 'profile_id' => 'tours', 'url' => 'https://example.invalid' ) );
expect_true( 'blocked' === $bad['state'] && in_array( 'unsupported_request_fields', $bad['blocking_reasons'], true ), 'arbitrary request fields fail closed before provider execution' );
$bad_provider = $planner->plan( array( 'provider_id' => '../../fake', 'profile_id' => 'tours' ) );
expect_true( 'blocked' === $bad_provider['state'] && in_array( 'provider_id_invalid', $bad_provider['blocking_reasons'], true ), 'malformed explicit provider id cannot fall through to implicit selection' );

$run = $runner->run( array( 'provider_id' => 'fake', 'profile_id' => 'tours' ) );
expect_true( 'PASS' === $run['result']['verdict'], 'semantic PASS is preserved' );
expect_true( true === $run['result']['verification']['semantic_parity_verified'], 'semantic verification is explicit' );
expect_true( false === $run['result']['verification']['browser_runtime_parity_verified'], 'PHP never claims browser verification' );
expect_true( 'INCOMPLETE_EVIDENCE' === $run['result']['overall_evidence_status'], 'browser observation gap remains explicit without downgrading semantic PASS to product failure' );

$unsafe = safe_provider( 'unsafe' );
$unsafe['run_callback'] = function ( array $request ) {
	$digest = hash( 'sha256', 'unsafe|' . $request['profile_id'] . '|semantic' );
	return array(
		'contract' => 'fake.acceptance.v1', 'provider_id' => 'unsafe', 'profile_id' => $request['profile_id'], 'plan_digest' => $digest,
		'verdict' => 'PASS', 'verification' => array( 'semantic_parity_verified' => true, 'browser_runtime_parity_verified' => false ),
		'authority' => array( 'authorizing' => false, 'persistent_mutation' => false, 'profile_mutation' => false, 'seo_publication' => false, 'production_activation' => false ),
		'effects' => array( 'business_state_mutation' => false, 'authority_mutation' => true, 'seo_mutation' => false, 'profile_mutation' => false, 'observational_persistence' => false ),
	);
};
$GLOBALS['mad4b_acceptance_test_providers'] = array( 'unsafe' => $unsafe );
$registry2 = new MAD4B_SCP_Acceptance_Provider_Registry();
$planner2 = new MAD4B_SCP_Acceptance_Planner( $registry2 );
$runner2 = new MAD4B_SCP_Acceptance_Runner( $registry2, $planner2, new MAD4B_SCP_Acceptance_Verdict_Reducer() );
$unsafe_run = $runner2->run( array( 'provider_id' => 'unsafe', 'profile_id' => 'tours' ) );
expect_true( 'BLOCKED' === $unsafe_run['result']['verdict'], 'unsafe provider runtime evidence fails closed' );

$malicious = safe_provider( 'malicious' );
$malicious['descriptor_callback'] = function () { $d = safe_descriptor( 'malicious' ); $d['arbitrary_url_input'] = true; return $d; };
$GLOBALS['mad4b_acceptance_test_providers'] = array( 'malicious' => $malicious );
expect_true( 0 === count( ( new MAD4B_SCP_Acceptance_Provider_Registry() )->all() ), 'provider descriptor enabling arbitrary input is rejected' );

$GLOBALS['mad4b_acceptance_test_providers'] = array( 'one' => safe_provider( 'one' ), 'two' => safe_provider( 'two' ) );
$multi = new MAD4B_SCP_Acceptance_Planner( new MAD4B_SCP_Acceptance_Provider_Registry() );
$multi_plan = $multi->plan( array( 'profile_id' => 'tours' ) );
expect_true( 'blocked' === $multi_plan['state'] && in_array( 'provider_id_required', $multi_plan['blocking_reasons'], true ), 'multiple providers require explicit provider selection' );

echo "MAD4B Acceptance Core contract smoke passed.\n";
