<?php
/** Exact runtime proof for the generic MAD4B Browser Acceptance Core with ETG provider discovery. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );

$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$check( current_user_can( 'manage_options' ), 'Browser Acceptance runtime smoke requires an administrator.' );
$check( class_exists( 'MAD4B_SCP_Browser_Acceptance_Core' ), 'MAD4B Browser Acceptance Core class is unavailable.' );
$check( class_exists( 'MAD4B_SCP_Browser_Acceptance_Provider_Registry' ), 'MAD4B Browser Acceptance Provider Registry is unavailable.' );
$check( class_exists( '\\ETG\\DynamicFilterSEOBridge\\Acceptance\\BrowserAcceptanceProvider' ), 'Exact ETG Browser Acceptance Provider class is unavailable.' );
$check( class_exists( '\\ETG\\DynamicFilterSEOBridge\\Acceptance\\BrowserObserverAsset' ), 'Exact ETG Browser Observer Asset descriptor is unavailable.' );
$check( class_exists( '\\ETG\\DynamicFilterSEOBridge\\Acceptance\\BrowserAcceptanceFreshnessGuard' ), 'Exact ETG Browser Acceptance freshness guard is unavailable.' );
$check( class_exists( '\\ETG\\DynamicFilterSEOBridge\\Diagnostics\\BuildIdentity' ), 'ETG exact build identity source is unavailable.' );

$full_chatgpt_candidates = MAD4B_SCP_Servers::chatgpt_full_catalog_candidates();
$direct_chatgpt_tools = MAD4B_SCP_Servers::chatgpt_tools();
$read_dispatch = wp_get_ability( 'mad4b/read-execute' );
$check( is_object( $read_dispatch ) && method_exists( $read_dispatch, 'execute' ), 'Governed ChatGPT readonly dispatcher is unavailable.' );
$dispatch_read = static function ( $ability_name, array $input = array() ) use ( $check, $read_dispatch ) {
	$result = $read_dispatch->execute( array( 'ability_name' => (string) $ability_name, 'input' => $input ) );
	$check( ! is_wp_error( $result ), 'Readonly dispatcher failed for ' . $ability_name . ( is_wp_error( $result ) ? ': ' . $result->get_error_code() : '' ) );
	$check( 'mad4b.chatgpt-read-execute.v1' === (string) ( $result['contract'] ?? '' ), 'Unexpected readonly dispatcher contract for ' . $ability_name );
	$check( ! empty( $result['read_only'] ) && empty( $result['mutation_performed'] ), 'Readonly dispatcher authority boundary drifted for ' . $ability_name );
	$check( isset( $result['result'] ) && is_array( $result['result'] ), 'Readonly dispatcher returned no structured target result for ' . $ability_name );
	return $result['result'];
};

$ability_names = array(
	'mad4b/browser-acceptance-capabilities',
	'mad4b/browser-acceptance-plan',
	'mad4b/browser-acceptance-result',
);
$write_tools = MAD4B_SCP_Servers::write_tools();
foreach ( $ability_names as $name ) {
	$check( wp_has_ability( $name ), 'Missing Browser Acceptance ability: ' . $name );
	$ability = wp_get_ability( $name );
	$check( is_object( $ability ) && method_exists( $ability, 'execute' ), 'Browser Acceptance ability is not callable: ' . $name );
	$meta = $ability->get_meta();
	$check( true === ( $meta['annotations']['readonly'] ?? null ), 'Browser Acceptance ability is not readonly: ' . $name );
	$check( false === ( $meta['annotations']['destructive'] ?? null ), 'Browser Acceptance ability is destructive: ' . $name );
	$check( empty( $meta['public'] ) && empty( $meta['mcp']['public'] ), 'Browser Acceptance ability leaked to default/public MCP: ' . $name );
	$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', $name ), 'Browser Acceptance ability is not mounted on mad4b-read: ' . $name );
	$check( in_array( $name, $full_chatgpt_candidates, true ), 'Browser Acceptance ability was lost from governed ChatGPT discovery: ' . $name );
	$check( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $name ), 'Browser Acceptance heavy read schema leaked directly into ChatGPT tools/list: ' . $name );
	$check( ! in_array( $name, $direct_chatgpt_tools, true ), 'Direct ChatGPT projection leaked Browser Acceptance schema: ' . $name );
	$check( ! in_array( $name, $write_tools, true ), 'Browser Acceptance ability leaked into write_tools(): ' . $name );
	foreach ( array( 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' ) as $server ) {
		$check( ! MAD4B_SCP_Servers::ability_is_mounted( $server, $name ), 'Browser Acceptance read ability leaked to ' . $server . ': ' . $name );
	}
}
$check( wp_has_ability( 'mad4b/browser-acceptance-run' ), 'Governed Browser Acceptance orchestration ability is missing.' );
$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-enrollment', 'mad4b/browser-acceptance-run' ), 'Browser Acceptance orchestration must be mounted only through the bounded enrollment authority.' );
$check( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', 'mad4b/browser-acceptance-run' ), 'Browser Acceptance orchestration leaked to read authority.' );
$check( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', 'mad4b/browser-acceptance-run' ), 'Browser Acceptance orchestration leaked to normal governed write authority.' );
$check( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-admin', 'mad4b/browser-acceptance-run' ), 'Browser Acceptance orchestration leaked to admin mutation authority.' );
$check( ! in_array( 'mad4b/browser-acceptance-run', $write_tools, true ), 'Browser Acceptance orchestration entered write_tools().' );

$parity_status = $dispatch_read( 'mad4b/remote-operation-parity-status' );
$check( ! is_wp_error( $parity_status ), 'Remote Operation Parity status is unavailable to Browser Acceptance runtime smoke.' );
$browser_operation = null;
foreach ( (array) ( $parity_status['operations'] ?? array() ) as $row ) {
	if ( 'browser_acceptance_execution' === (string) ( $row['operation_id'] ?? '' ) ) { $browser_operation = $row; break; }
}
$check( is_array( $browser_operation ), 'Browser Acceptance execution is missing from the governed remote operation catalog.' );
$check( 'mad4b/browser-acceptance-run' === (string) ( $browser_operation['remote_ability'] ?? '' ), 'Browser Acceptance remote ability drifted.' );
$check( 'operator' === (string) ( $browser_operation['remote_caller_role'] ?? '' ), 'Browser Acceptance request must remain operator-scoped.' );
$check( 'external_browser_agent' === (string) ( $browser_operation['executor'] ?? '' ), 'Browser Acceptance executor identity drifted.' );
$check( 'mad4b-enrollment' === (string) ( $browser_operation['authority_surface'] ?? '' ), 'Browser Acceptance authority surface drifted.' );
$check( 'deny' === (string) ( $browser_operation['production_policy'] ?? '' ), 'Browser Acceptance Production policy must remain deny.' );
$check( empty( $browser_operation['human_decision_required'] ), 'Browser Acceptance transport must not invent a human decision gate.' );
$check( ! empty( $browser_operation['remote_parity_ready'] ), 'Browser Acceptance remote parity is not ready.' );

$capabilities = $dispatch_read( 'mad4b/browser-acceptance-capabilities' );
$check( ! is_wp_error( $capabilities ), 'Browser Acceptance capabilities execution failed.' );
$check( 'mad4b.browser-acceptance-capabilities.v1' === (string) $capabilities['contract'], 'Unexpected Browser Acceptance capabilities contract.' );
$check( ! empty( $capabilities['read_only'] ) && empty( $capabilities['authorizing'] ), 'Browser Acceptance Core authority boundary drifted.' );
$check( empty( $capabilities['transport_authority'] ) && empty( $capabilities['browser_engine_authority'] ), 'Browser Acceptance Core claimed transport/browser authority.' );
$check( 'external_browser_agent' === (string) $capabilities['execution_mode'], 'Browser Acceptance execution ownership drifted.' );

$etg = null;
foreach ( (array) $capabilities['providers'] as $provider ) {
	if ( 'etg-dfsb' === (string) ( $provider['provider_id'] ?? '' ) ) {
		$etg = $provider;
		break;
	}
}
$check( is_array( $etg ), 'ETG Browser Acceptance Provider was not discovered through the generic browser registry.' );
$check( 'etg.dfsb.browser-acceptance-provider.v2' === (string) $etg['contract'], 'ETG Browser Acceptance Provider contract drifted.' );
$descriptor = (array) $etg['descriptor'];
$check( ! empty( $descriptor['read_only'] ) && empty( $descriptor['authorizing'] ), 'ETG Browser provider opened authority.' );
$check( 'external_browser_agent' === (string) ( $descriptor['execution_mode'] ?? '' ), 'ETG Browser provider execution mode drifted.' );
$check( empty( $descriptor['transport_owned_by_provider'] ) && empty( $descriptor['browser_engine_owned_by_provider'] ), 'ETG Browser provider claimed transport/browser engine authority.' );
foreach ( array( 'arbitrary_url_input', 'arbitrary_javascript_input', 'business_state_mutation', 'profile_mutation', 'seo_mutation', 'production_activation' ) as $key ) {
	$check( array_key_exists( $key, $descriptor ) && false === $descriptor[ $key ], 'ETG Browser provider opened unsafe boundary: ' . $key );
}

$provider_capabilities = (array) ( $etg['capabilities'] ?? array() );
$asset = (array) ( $provider_capabilities['observer_asset'] ?? array() );
$check( 'etg.dfsb.browser-observer-asset.v1' === (string) ( $asset['contract'] ?? '' ), 'ETG observer asset contract is missing.' );
$check( 'etg.dfsb.browser-acceptance-observer.v1' === (string) ( $asset['observer_contract'] ?? '' ), 'ETG observer runtime contract is not bound to asset metadata.' );
$check( ! empty( $asset['available'] ) && ! empty( $asset['same_origin'] ), 'ETG observer asset must be available from the exact same origin.' );
$check( empty( $asset['authorizing'] ) && empty( $asset['arbitrary_javascript'] ) && empty( $asset['auto_enqueued'] ), 'ETG observer asset delivery opened authority or visitor-side auto injection.' );
$check( 'external_browser_agent_same_origin_asset' === (string) ( $asset['load_mode'] ?? '' ), 'ETG observer asset load mode drifted.' );
$check( 'snapshot' === (string) ( $asset['snapshot_method'] ?? '' ), 'ETG observer default snapshot method drifted.' );
$check( 'snapshotAsync' === (string) ( $asset['full_digest_snapshot_method'] ?? '' ), 'ETG observer full-digest snapshot method drifted.' );
$check( 5000 === (int) ( $asset['max_digest_ids'] ?? 0 ), 'ETG observer digest coverage ceiling drifted.' );
$check( ! empty( $asset['web_crypto_required_for_full_digest'] ), 'ETG observer must declare Web Crypto for full-digest evidence.' );
$check( preg_match( '/^[a-f0-9]{64}$/', (string) ( $asset['sha256'] ?? '' ) ), 'ETG observer asset lacks exact SHA-256.' );
$check( (int) ( $asset['bytes'] ?? 0 ) > 0, 'ETG observer asset size is unavailable.' );
$check( false !== strpos( (string) ( $asset['url'] ?? '' ), '/etg-dynamic-filter-seo-bridge/assets/js/browser-acceptance-observer.js' ), 'ETG observer asset URL is not package-owned.' );
$check( empty( $asset['blocking_reasons'] ), 'ETG observer asset discovery is blocked: ' . wp_json_encode( $asset['blocking_reasons'] ?? array() ) );

$freshness = (array) ( $provider_capabilities['freshness_challenge'] ?? array() );
$check( 'etg.dfsb.browser-acceptance-challenge.v1' === (string) ( $freshness['contract'] ?? '' ), 'ETG freshness challenge contract is missing.' );
$check( ! empty( $freshness['required_for_observed_evidence'] ), 'Observed ETG browser evidence does not require freshness challenge.' );
$check( ! empty( $freshness['stateless'] ) && ! empty( $freshness['server_signed'] ), 'ETG freshness challenge lost stateless/server-signed semantics.' );
$check( 900 === (int) ( $freshness['ttl_seconds'] ?? 0 ), 'ETG freshness challenge TTL drifted.' );
$check( 16 === (int) ( $freshness['nonce_bytes'] ?? 0 ), 'ETG freshness challenge nonce width drifted.' );
$check( empty( $freshness['authorizing'] ) && empty( $freshness['persistent_mutation'] ), 'ETG freshness challenge opened authority or persistence.' );

$identity = \ETG\DynamicFilterSEOBridge\Diagnostics\BuildIdentity::collect();
$check( ! empty( $identity['valid'] ), 'Exact ETG embedded build identity is invalid.' );
$expected_head = getenv( 'ETG_BROWSER_ACCEPTANCE_HEAD' );
$expected_tree = getenv( 'ETG_BROWSER_ACCEPTANCE_TREE' );
if ( is_string( $expected_head ) && '' !== $expected_head ) {
	$check( $expected_head === (string) ( $identity['git_sha'] ?? '' ), 'ETG browser runtime exact HEAD identity drifted.' );
	$check( $expected_head === (string) ( $asset['build_identity']['git_sha'] ?? '' ), 'ETG observer asset exact HEAD identity drifted.' );
}
if ( is_string( $expected_tree ) && '' !== $expected_tree ) {
	$check( $expected_tree === (string) ( $identity['tree_sha'] ?? '' ), 'ETG browser runtime exact tree identity drifted.' );
	$check( $expected_tree === (string) ( $asset['build_identity']['tree_sha'] ?? '' ), 'ETG observer asset exact tree identity drifted.' );
}

$blocked = $dispatch_read( 'mad4b/browser-acceptance-plan', array(
	'provider_id' => 'etg-dfsb',
	'profile_id' => 'tours',
	'url' => 'https://example.invalid',
) );
$check( ! is_wp_error( $blocked ), 'Browser Acceptance plan converted bounded rejection into a transport error.' );
$check( 'blocked' === (string) $blocked['state'], 'Arbitrary browser URL input did not fail closed.' );
$check( in_array( 'unsupported_request_fields', (array) $blocked['blocking_reasons'], true ), 'Arbitrary browser URL rejection reason was not preserved.' );

$plan = $dispatch_read( 'mad4b/browser-acceptance-plan', array(
	'provider_id' => 'etg-dfsb',
	'profile_id' => 'tours',
	'suite' => 'browser_runtime',
) );
$check( ! is_wp_error( $plan ), 'Governed ETG Browser Acceptance plan execution failed.' );
$check( 'mad4b.browser-acceptance-plan.v1' === (string) $plan['contract'], 'Unexpected MAD4B browser plan contract.' );
$check( 'etg-dfsb' === (string) $plan['provider_id'], 'Browser Acceptance plan provider identity drifted.' );
$check( empty( $plan['authorizing'] ) && ! empty( $plan['read_only'] ), 'Browser Acceptance plan authority boundary drifted.' );
$check( in_array( (string) $plan['state'], array( 'ready', 'blocked' ), true ), 'Browser Acceptance plan returned an invalid state.' );

$check( class_exists( 'MAD4B_SCP_Remote_Operation_Parity' ) && method_exists( 'MAD4B_SCP_Remote_Operation_Parity', 'browser_acceptance_receipt_status' ), 'Browser Acceptance durable receipt status helper is unavailable.' );
$receipt_status = MAD4B_SCP_Remote_Operation_Parity::browser_acceptance_receipt_status( is_array( $plan ) ? $plan : array() );
$check( is_array( $receipt_status ) && 'mad4b.remote-browser-acceptance-receipt-status.v1' === (string) ( $receipt_status['contract'] ?? '' ), 'Browser Acceptance receipt status contract drifted.' );
if ( ! empty( $receipt_status['ready'] ) ) {
	$receipt_result = (array) ( $receipt_status['receipt']['browser_result'] ?? array() );
	$check( 'PASS' === (string) ( $receipt_result['verdict'] ?? '' ), 'Ready Browser Acceptance receipt does not contain PASS result.' );
	$check( ! empty( $receipt_result['verification']['browser_runtime_parity_verified'] ), 'Ready Browser Acceptance receipt does not verify browser runtime parity.' );
} else {
	$check( ! empty( $receipt_status['blockers'] ), 'Non-ready Browser Acceptance receipt status must fail closed with blockers.' );
}

if ( 'ready' === (string) $plan['state'] ) {
	$check( preg_match( '/^[a-f0-9]{64}$/', (string) $plan['plan_digest'] ), 'Ready Browser Acceptance plan lacks exact digest.' );
	$check( preg_match( '/^[a-f0-9]{64}$/', (string) $plan['plan_signature'] ), 'Ready Browser Acceptance plan lacks server signature.' );
	$check( $expected_head === (string) ( $plan['build_identity']['git_sha'] ?? '' ), 'Ready Browser Acceptance plan is not exact-HEAD bound.' );
	$check( $expected_tree === (string) ( $plan['build_identity']['tree_sha'] ?? '' ), 'Ready Browser Acceptance plan is not exact-tree bound.' );
	$challenge = (array) ( $plan['challenge'] ?? array() );
	$check( 'etg.dfsb.browser-acceptance-challenge.v1' === (string) ( $challenge['contract'] ?? '' ), 'Ready Browser Acceptance plan lacks freshness challenge.' );
	$check( preg_match( '/^[a-f0-9]{32}$/', (string) ( $challenge['nonce'] ?? '' ) ), 'Ready Browser Acceptance challenge lacks 128-bit nonce.' );
	$check( preg_match( '/^[a-f0-9]{64}$/', (string) ( $challenge['signature'] ?? '' ) ), 'Ready Browser Acceptance challenge lacks server HMAC.' );
	$issued_at = (int) ( $challenge['issued_at'] ?? 0 );
	$expires_at = (int) ( $challenge['expires_at'] ?? 0 );
	$check( $issued_at > 0 && $expires_at > $issued_at && ( $expires_at - $issued_at ) <= 900, 'Ready Browser Acceptance challenge freshness window is invalid.' );

	$missing = $dispatch_read( 'mad4b/browser-acceptance-result', array(
		'provider_id' => 'etg-dfsb',
		'profile_id' => 'tours',
		'suite' => 'browser_runtime',
		'plan_digest' => $plan['plan_digest'],
		'plan_signature' => $plan['plan_signature'],
	) );
	$check( ! is_wp_error( $missing ), 'Missing browser evidence became a transport error.' );
	$check( false === (bool) ( $missing['verification']['browser_runtime_parity_verified'] ?? true ), 'Missing evidence falsely verified Browser Runtime parity.' );
	$check( in_array( 'browser_runtime_not_observed', (array) ( $missing['incomplete_evidence'] ?? array() ), true ), 'Missing Browser Runtime evidence reason was not preserved.' );
	$check( 'INCOMPLETE_EVIDENCE' === (string) ( $missing['verdict'] ?? '' ), 'Missing Browser Runtime evidence must remain incomplete.' );
}

$status_ability = wp_get_ability( 'mad4b/live-acceptance-status' );
$check( is_object( $status_ability ) && method_exists( $status_ability, 'execute' ), 'Existing live-acceptance-status ability is missing.' );
$status = $status_ability->execute( array() );
$check( ! is_wp_error( $status ), 'Existing aggregate live-acceptance status became unavailable.' );
$check( 'mad4b.live-acceptance-status.v1' === (string) $status['contract'], 'Browser Acceptance Core redefined the aggregate live-acceptance contract.' );

echo "mad4b.site-control-plane.browser-acceptance-core-etg-runtime.v1: PASS\n";
