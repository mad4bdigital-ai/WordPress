<?php
/** Pure native PHP 7.4+ fixtures for guided operator decisions; no WordPress. */
define( 'ABSPATH', __DIR__ . '/' );
function __( $text, $domain = '' ) { return $text; }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-guided-operator-experience.php';
function verify_guide( $ok, $message ) {
	if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
function guide_operator( $reasons = array(), $write = true, $providers = 0 ) {
	return array( 'contract' => 'mad4b.operator-control-center.v1', 'reasons' => $reasons,
		'mutation_performed' => false, 'production_authorized' => false,
		'operational' => array(
			'write_authority' => array( 'ready' => $write ),
			'provider_closure' => array( 'action_required_count' => $providers, 'state' => 'observed' ),
		) );
}
function guide_site( $enabled = true ) {
	return array( 'authority_ready' => true, 'skills_enabled' => $enabled );
}
function guide_skills( $ready, $current, $live = null ) {
	return array( 'ready' => $ready, 'recorded_ready' => $ready,
		'build_identity_current' => $current, 'live_skill_ready' => $live );
}
function guide_step( $model, $id ) {
	foreach ( $model['steps'] as $row ) if ( $id === $row['id'] ) return $row;
	return null;
}
$browser = array( 'preference_valid' => true );
$model = MAD4B_SCP_Guided_Operator_Experience::model( guide_operator(), guide_site(), guide_skills( false, false ), $browser );
verify_guide( 'skills' === $model['next_step'], 'stale Skills are a first-class priority' );
verify_guide( 'NEEDS_ACTION' === guide_step( $model, 'skills' )['state'], 'Skills remediation is not labelled certified' );
verify_guide( 'NOT_CHECKED' === guide_step( $model, 'browser' )['state'], 'saved browser preference cannot certify test' );
verify_guide( ! $model['authorizing'] && ! $model['mutation_performed'] && ! $model['release_certified'], 'guided model cannot grant authority' );
$model = MAD4B_SCP_Guided_Operator_Experience::model( guide_operator( array( 'mutation_state_uncertain' ) ), guide_site(), guide_skills( false, false ), $browser );
verify_guide( 'recovery' === $model['next_step'], 'unknown prior write dominates Skills repair' );
$model = MAD4B_SCP_Guided_Operator_Experience::model( guide_operator(), guide_site( false ), guide_skills( false, false ), $browser );
verify_guide( 'NOT_APPLICABLE' === guide_step( $model, 'skills' )['state'], 'disabled Skills not treated as failed' );
$model = MAD4B_SCP_Guided_Operator_Experience::model( guide_operator(), array( 'authority_ready' => true ), array(), array() );
verify_guide( 'NOT_CHECKED' === guide_step( $model, 'skills' )['state'], 'missing Skills evidence not treated as disabled' );
$model = MAD4B_SCP_Guided_Operator_Experience::model( guide_operator(), array(), guide_skills( true, true ), array() );
verify_guide( 'NEEDS_ACTION' === guide_step( $model, 'site' )['state'] || 'NOT_CHECKED' === guide_step( $model, 'site' )['state'], 'missing site evidence cannot pass' );
$model = MAD4B_SCP_Guided_Operator_Experience::model( guide_operator(), guide_site(), guide_skills( true, true, true ), array( 'preference_valid' => false ) );
verify_guide( 'browser' === $model['next_step'], 'invalid browser settings get explicit correction' );
$model = MAD4B_SCP_Guided_Operator_Experience::model( guide_operator( array(), false, 2 ), guide_site(), guide_skills( true, true, true ), $browser );
verify_guide( 'NEEDS_ACTION' === guide_step( $model, 'providers' )['state'], 'provider pending distinct' );
verify_guide( 'NEEDS_ACTION' === guide_step( $model, 'write' )['state'], 'write current authority gets dedicated diagnostic' );
$enrolled = guide_site(); $enrolled['deployment_binding_configured'] = false;
$model = MAD4B_SCP_Guided_Operator_Experience::model( guide_operator(), $enrolled, guide_skills( true, true, true ), $browser );
verify_guide( 'EXTERNAL_ACTION' === guide_step( $model, 'deployment' )['state'], 'independent missing deployment binding assigned to host operator' );
$model = MAD4B_SCP_Guided_Operator_Experience::model( guide_operator( array(), false ), guide_site(), guide_skills( true, true, true ), $browser );
verify_guide( 'NOT_CHECKED' === guide_step( $model, 'approvals' )['state'], 'write blocker must not fabricate pending approval' );
$host_requested = guide_operator( array( 'developer_lane_not_ready' ) );
$host_requested['operational']['lanes'] = array( 'client_action' => 'resolve_developer_host_execution_prerequisites' );
$model = MAD4B_SCP_Guided_Operator_Experience::model( $host_requested, guide_site(), guide_skills( true, true, true ), $browser );
verify_guide( 'EXTERNAL_ACTION' === guide_step( $model, 'host' )['state'], 'host sandbox is external' );
$model = MAD4B_SCP_Guided_Operator_Experience::model( guide_operator( array( 'developer_lane_not_ready' ) ), guide_site(), guide_skills( true, true, true ), $browser );
verify_guide( null === guide_step( $model, 'host' ), 'optional disabled developer lane must not demand Host action' );
verify_guide( 'OBSERVED_READY' === guide_step( $model, 'skills' )['state'], 'local Skills only observed ready' );
// Matching saved certificate alone must not claim live verification.
$model = MAD4B_SCP_Guided_Operator_Experience::model( guide_operator(), guide_site(), guide_skills( true, true ), $browser );
verify_guide( 'NEEDS_ACTION' === guide_step( $model, 'skills' )['state'], 'saved checkpoint alone is not live Skills validation' );
verify_guide( 'skills' === $model['next_step'], 'live verification remains the next safe action' );
$drift = guide_operator( array( 'runtime_authority_candidate_not_reconciled' ), false );
$drift['operational']['write_authority']['candidate_binding_match'] = false;
$drift['operational']['write_authority']['blockers'] = array( 'runtime_authority_candidate_not_reconciled', 'stale_write_grants_present' );
$model = MAD4B_SCP_Guided_Operator_Experience::model( $drift, guide_site(), guide_skills( true, true, true ), $browser );
verify_guide( 'drift' === $model['next_step'], 'drift leads to single exact owner review before Skills or Write' );
verify_guide( 'NEEDS_ACTION' === guide_step( $model, 'drift' )['state'], 'drift not silently repaired' );
verify_guide( false === guide_step( $model, 'drift' )['execution_performed'] && false === guide_step( $model, 'drift' )['authority_granted'], 'drift triage never bypasses approvals' );
$drift['operational']['write_authority']['candidate_binding_match'] = true;
$drift['operational']['write_authority']['blockers'] = array();
$model = MAD4B_SCP_Guided_Operator_Experience::model( $drift, guide_site(), guide_skills( true, true, true ), $browser );
verify_guide( null === guide_step( $model, 'drift' ), 'no duplicate drift warning once blockers clear' );
$untrusted = guide_operator(); $untrusted['production_authorized'] = true;
$model = MAD4B_SCP_Guided_Operator_Experience::model( $untrusted, guide_site(), guide_skills( true, true ), $browser );
verify_guide( 'EVIDENCE_UNTRUSTED' === $model['state'] && empty( $model['steps'] ), 'forged authorization blocks journey' );
$untrusted = guide_operator(); $untrusted['contract'] = 'forged';
$model = MAD4B_SCP_Guided_Operator_Experience::model( $untrusted, guide_site(), guide_skills( true, true ), $browser );
verify_guide( 'EVIDENCE_UNTRUSTED' === $model['state'], 'unknown contract cannot drive navigation' );
fwrite( STDOUT, "PASS guided operator native behavior: original and drift/Skills verification assertions\n" );
