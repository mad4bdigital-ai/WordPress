<?php
/* Native policy reducer: no WP DB, network, approval or content mutations. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-brand-core-control-loop.php';
function check_loop( $truth, $message ) {
	if ( ! $truth ) { fwrite( STDERR, "FAIL: " . $message . "\n" ); exit( 1 ); }
}
$approved = array( 'ready' => true, 'conflict' => false );
$missing = array( 'ready' => false, 'conflict' => false );
$cover = array(
	'ready' => false, 'registry_revision' => 6,
	'authority_manifest_fingerprint' => str_repeat( 'a', 64 ),
	'coverage' => array( 'brand_strategy' => $missing, 'tone_of_voice' => $missing, 'editorial_guidelines' => $missing ),
);
$context = array( 'brand_id' => str_repeat( 'a', 32 ), 'profile_revision' => 4,
	'review_policy' => array( 'ready' => true ),
	'quarantined_source_record_count' => 1, 'quarantined_asset_record_count' => 12 );
$convergence = array( 'registry_revision' => 6, 'authority_manifest_fingerprint' => str_repeat( 'a', 64 ),
	'writable_sources' => array(),
	'actions' => array( array( 'category' => 'tone_of_voice', 'state' => 'ready_to_create' ),
		array( 'category' => 'editorial_guidelines', 'state' => 'ready_to_create' ) ) );
$census = array( 'source_quarantine_count' => 1, 'asset_quarantine_count' => 12 );
$provider = array( 'connection' => array( 'connected' => true, 'write_available' => true, 'refresh_required' => true ) );
$r = MAD4B_SCP_Brand_Core_Control_Loop::decide( $cover, $context, $convergence, $census, $provider );
check_loop( 'BLOCKED_OWNERSHIP_QUARANTINE' === $r['state'], 'quarantined root incorrectly selected new creation' );
check_loop( 'context/legacy-reconciliation-census' === $r['next_ability'], 'wrong first safe ability on quarantined inventory' );
check_loop( 12 === $r['quarantine']['asset_count'] && 1 === $r['quarantine']['source_count'], 'legacy counts lost' );
check_loop( empty( $r['ready'] ) && empty( $r['authorizing'] ) && empty( $r['mutation_performed'] ), 'read only recovery falsely became authorizing' );
check_loop( ! empty( $r['delegated_ai_review_available'] ) && empty( $r['quarantine']['migration_automatically_authorized'] ),
	'AI delegation incorrectly adopted unowned Drive files' );
foreach ( $r['categories'] as $c ) {
	check_loop( 'OWNERSHIP_REVIEW_REQUIRED' === $c['state'], 'individual category bypassed ownership quarantine' );
	check_loop( empty( $c['candidate_recreation_authorized'] ), 'alternative clone creation incorrectly authorized' );
}
$census = array( 'source_quarantine_count' => 0, 'asset_quarantine_count' => 0 );
$context['quarantined_source_record_count'] = 0;
$context['quarantined_asset_record_count'] = 0;
$r = MAD4B_SCP_Brand_Core_Control_Loop::decide( $cover, $context, $convergence, $census, $provider );
check_loop( 'OWNER_STRATEGY_REQUIRED' === $r['categories'][0]['state'], 'Brand Strategy silently synthesized as authority' );
check_loop( 'WAIT_UPSTREAM_AUTHORITY' === $r['categories'][1]['state'], 'Tone of voice skipped approved brand strategy prerequisite' );
$cover['coverage']['brand_strategy'] = $approved;
$cover['coverage']['tone_of_voice'] = $approved;
$convergence['writable_sources'] = array( array( 'source_id' => str_repeat( 'b', 64 ) ) );
$r = MAD4B_SCP_Brand_Core_Control_Loop::decide( $cover, $context, $convergence, $census, $provider );
check_loop( 'DRAFT_PREFLIGHT' === $r['categories'][2]['state'], 'evidence-backed eligible draft did not reach governed preflight' );
$cover['coverage']['editorial_guidelines'] = $approved;
$cover['ready'] = true;
$r = MAD4B_SCP_Brand_Core_Control_Loop::decide( $cover, $context, $convergence, $census, $provider );
check_loop( 'READY' === $r['state'] && $r['ready'], 'all approved exact sources were not reported ready' );
$cover['registry_revision'] = 7;
$r = MAD4B_SCP_Brand_Core_Control_Loop::decide( $cover, $context, $convergence, $census, $provider );
check_loop( 'BLOCKED_SNAPSHOT_DRIFT' === $r['state'], 'stale registry snapshot bypassed' );
$cover['registry_revision'] = 6;
$cover['coverage']['editorial_guidelines'] = $missing;
$cover['ready'] = false;
$provider['connection']['connected'] = false;
$r = MAD4B_SCP_Brand_Core_Control_Loop::decide( $cover, $context, $convergence, $census, $provider );
check_loop( 'PROVIDER_RECOVERY' === $r['categories'][2]['state'], 'provider outage wrongly encouraged new file' );
check_loop( empty( $r['production_mutation_allowed'] ), 'non-Staging mutation implicitly authorized' );
echo "PASS: Brand Core one-source lifecycle and fail-closed recovery matrix\n";
