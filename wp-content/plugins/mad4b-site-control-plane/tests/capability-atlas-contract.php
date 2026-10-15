<?php
/** Pure, non-authorizing capability graph tests. No actual site writes. */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-capability-atlas.php';
function atlas_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
$browser = array(
    'contract' => 'mad4b.browser-acceptance-capabilities.v1', 'read_only' => true,
    'authorizing' => false, 'provider_count' => 2,
    'site_discovery' => array(
        'discovery_complete' => true,
        'provider_matches' => array(
            array( 'provider_id' => 'vendor-one', 'recognized' => true ),
            array( 'provider_id' => 'vendor-two', 'recognized' => true ),
        ),
        'unmapped_plugins' => array( 'unknown-plugin' ),
    ),
    'providers' => array(
        array( 'provider_id' => 'vendor-one', 'contract' => 'vendor.one.v1',
            'capabilities' => array( 'read_only' => true, 'authorizing' => false,
                'capabilities' => array( 'browser.dom_result_count', 'browser.url_state' ) ) ),
        array( 'provider_id' => 'vendor-two', 'contract' => 'vendor.two.v1',
            'capabilities' => array( 'read_only' => true, 'authorizing' => false,
                'capabilities' => array( 'browser.dom_result_count', 'browser.reset_behavior' ) ) ),
    ),
);
$plugin = array(
    'contract' => 'mad4b.provider-functional-coverage.v1',
    'read_only' => true, 'authority_created' => false,
    'count' => 2, 'items' => array(
        array( 'plugin_file' => 'my-shop/main.php', 'functional_family_key' => 'commerce',
            'adapter_id' => 'woocommerce', 'functional_coverage' => array( 'state' => 'contract_discovery_required' ) ),
        array( 'plugin_file' => 'my-seo/main.php', 'functional_family_key' => 'seo',
            'adapter_id' => 'seo', 'functional_coverage' => array( 'state' => 'status_only' ) ),
    ),
);
$result = MAD4B_SCP_Capability_Atlas::compose( $browser, $plugin );
atlas_assert( $result['complete'] && $result['count'] === 5, 'independent capabilities preserved' );
atlas_assert( ! $result['authorizing'] && ! $result['execution_allowed'], 'capability claims are not authority' );
atlas_assert( ! $result['release_ready'] && $result['functional_certification_count'] === 0 &&
    $result['inventory_complete'], 'inventory completeness never becomes release readiness' );
atlas_assert( $result['unmapped_plugin_count'] === 1, 'unmapped plugin retained' );
$by_id = array();
foreach ( $result['capabilities'] as $item ) {
    $by_id[ $item['capability_id'] ] = $item;
    atlas_assert( ! $item['execution_allowed'] && ! $item['certification_issued'], 'no synthesized PASS' );
    foreach ( array( 'semantic_oracle', 'browser_execution_attestation',
        'independent_reduction', 'replay_and_freshness', 'cross_site_parity',
        'release_approval' ) as $proof ) {
        atlas_assert( $item['evidence_gates'][ $proof ] === 'NOT_PROVEN',
            'unobserved evidence cannot become certified: ' . $proof );
    }
}
atlas_assert( $by_id['browser.dom_result_count']['candidate_count'] === 2 &&
    $by_id['browser.dom_result_count']['candidate_ambiguous'], 'shared capability stays ambiguous' );
atlas_assert( $by_id['family.commerce']['candidate_count'] === 1, 'commerce family discovered independent of site/brand' );
atlas_assert( $by_id['family.seo']['candidates'][0]['status'] === 'inventory_only', 'SEO plugin remains unverified' );
$reordered = $browser;
$reordered['providers'] = array_reverse( $reordered['providers'] );
atlas_assert( MAD4B_SCP_Capability_Atlas::compose( $reordered, $plugin )['capabilities'] ===
    $result['capabilities'], 'deterministic candidate sort' );
$invalid = $browser;
$invalid['providers'][1]['provider_id'] = 'vendor-one';
atlas_assert( ! MAD4B_SCP_Capability_Atlas::compose( $invalid, $plugin )['complete'], 'duplicate provider rejected' );
$invalid = $browser;
$invalid['providers'][0]['capabilities']['capabilities'][] = 'write.production_grant';
$declared = MAD4B_SCP_Capability_Atlas::compose( $invalid, $plugin );
atlas_assert( $declared['complete'] && ! $declared['execution_allowed'], 'mutating-looking claim never executes' );
$invalid = $browser; $invalid['providers'][0]['capabilities']['authorizing'] = true;
atlas_assert( ! MAD4B_SCP_Capability_Atlas::compose( $invalid, $plugin )['complete'], 'provider authority rejected' );
$invalid = $plugin; $invalid['count'] = 3;
atlas_assert( ! MAD4B_SCP_Capability_Atlas::compose( $browser, $invalid )['complete'], 'partial plugin report rejected' );
$invalid = $plugin; $invalid['items'][1]['plugin_file'] = 'my-shop/main.php';
atlas_assert( ! MAD4B_SCP_Capability_Atlas::compose( $browser, $invalid )['complete'], 'duplicate plugin identity rejected' );
$partial = $plugin; $partial['items'][1]['functional_family_key'] = '';
$degraded = MAD4B_SCP_Capability_Atlas::compose( $browser, $partial );
atlas_assert( ! $degraded['complete'] && $degraded['partial'] === true &&
    $degraded['count'] > 0 && ! $degraded['execution_allowed'],
    'unmapped plugin family keeps safe observed candidates visible without certification' );
echo "MAD4B_CAPABILITY_ATLAS_CONTRACT: PASS\n";
