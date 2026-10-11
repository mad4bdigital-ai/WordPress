<?php
/** Offline PHP 7.4+ regression: exact missing Staging evidence gates stay unapproved. */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-certification.php';
function assert_acceptance( $yes, $reason ) {
	if ( ! $yes ) { fwrite( STDERR, 'FAIL: ' . $reason . PHP_EOL ); exit( 1 ); }
}
$gate_ids = array( 'context_authority', 'browser_runtime', 'performance_budget', 'wp_import_export_exact_artifact' );
$gates = array();
foreach ( $gate_ids as $id ) $gates[$id] = array( 'ready' => false, 'blockers' => array( 'missing_external_evidence' ) );
$actions = MAD4B_SCP_Staging_Certification::acceptance_evidence_actions( $gates );
assert_acceptance( count( $actions ) === 4, 'All four blocked domains get explicit steps.' );
$by_id = array();
foreach ( $actions as $action ) {
	$by_id[$action['action_id']] = $action;
	assert_acceptance( empty( $action['automatic_execution_allowed'] ) && ! empty( $action['human_decision_required'] ), 'No automatic external execution.' );
	assert_acceptance( ! empty( $action['readback_ability'] ) && ! empty( $action['required_evidence'] ), 'Every step needs exact readback and independent evidence.' );
	assert_acceptance( empty( $action['grant_created'] ) && empty( $action['certificate_issued'] ), 'Planning cannot mint authority or evidence.' );
	assert_acceptance( in_array( 'missing_external_evidence', $action['gate_blockers'], true ), 'Preserve actual gate blocker.' );
}
assert_acceptance( 'wp-import-export/execution-readiness' === $by_id['import_export_disposable_acceptance']['readback_ability'], 'Import/export uses native independent readback.' );
assert_acceptance( 'mad4b/browser-acceptance-capabilities' === $by_id['browser_attestation_trust_review']['readback_ability'], 'Browser trust must be verified, not imagined.' );
assert_acceptance( 'context/review-queue' === $by_id['context_owner_evidence_review']['readback_ability'], 'Context requires individual owner review.' );
assert_acceptance( 'mad4b/frontend-performance-status' === $by_id['frontend_sample_evidence_review']['readback_ability'], 'Frontend evidence must be observed.' );
$ready = array();
foreach ( $gate_ids as $id ) $ready[$id] = array( 'ready' => true );
assert_acceptance( ! MAD4B_SCP_Staging_Certification::acceptance_evidence_actions( $ready ), 'Ready gates do not cause repeated work.' );
$missing = MAD4B_SCP_Staging_Certification::acceptance_evidence_actions( array( 'browser_runtime' => array( 'blockers' => array() ) ) );
assert_acceptance( count( $missing ) === 1 && ! $missing[0]['certificate_issued'], 'Unknown readiness must fail closed.' );
$noise = MAD4B_SCP_Staging_Certification::acceptance_evidence_actions( array( 'plugin_unfamiliar_gate' => array( 'ready' => false ) ) );
assert_acceptance( ! $noise, 'Unknown gates handled by safe generic coverage path.' );
echo "PASS exact Staging independent evidence actions\n";
