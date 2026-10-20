<?php
/** Hermetic, zero-mutation Staging Owner UX contract. */
if ( PHP_SAPI !== 'cli' ) exit( 2 );
define( 'ABSPATH', '/mad4b-test/' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-enrollment-dispatch.php';
function ensure( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
$method = new ReflectionMethod( 'MAD4B_SCP_Enrollment_Dispatch', 'guided_write_handoff_model' );
$method->setAccessible( true );
$h = static function ( $row, $handshake, $prior, $latest ) use ( $method ) {
    return $method->invoke( null, $row, $handshake, $prior, $latest );
};
$site_uuid = 'd745d81f-6fc4-5c6a-99dd-d953c92137bf';
$row = array(
    'remote_ability' => 'mad4b/staging-write-authority-convergence-apply',
    'authority_surface' => 'mad4b-enrollment',
    'remote_caller_role' => 'owner', 'production_policy' => 'deny',
    'human_decision_required' => true, 'execution_eligible' => true, 'remote_parity_ready' => true,
    'registration_digest' => str_repeat( '1', 64 ),
    'dispatch_policy_digest' => str_repeat( '2', 64 ),
    'input_schema_sha256' => str_repeat( '3', 64 ),
);
$input = array(
    'expected_plan_sha256' => str_repeat( 'a', 64 ),
    'expected_source_commit_sha' => str_repeat( 'b', 40 ),
    'expected_build_fingerprint' => str_repeat( 'c', 64 ),
    'expected_package_manifest_digest' => str_repeat( 'd', 64 ),
    'expected_artifact_identity' => 'mad4b-site-control-plane-0.4.0-rc.102-' . str_repeat( 'b', 40 ),
    'expected_site_uuid' => $site_uuid, 'expected_profile_revision' => 7,
    'expected_profile_digest' => str_repeat( 'e', 64 ),
    'confirmation' => 'ENABLE GOVERNED STAGING WRITE AUTHORITY',
    'owner_review_digest' => str_repeat( 'f', 64 ),
);
$handshake = array( 'ready_to_apply' => true, 'already_current' => false,
    'hard_blockers' => array(), 'exact_apply' => $input );
$prior = array( 'state' => 'UNVERIFIED_OR_UNAVAILABLE' );
$latest = array( 'state' => 'NO_CURRENT_OPERATION_POINTER' );
$ready = $h( $row, $handshake, $prior, $latest );
ensure( $ready['state'] === 'ready_for_exact_owner_decision', 'Expected exact owner decision state' );
ensure( $ready['owner_decision_required'] === true, 'Owner decision must not be skipped' );
ensure( $ready['automatic_execution'] === false && $ready['read_only'] === true
    && $ready['mutation_performed'] === false && $ready['blind_retry_allowed'] === false,
    'Guidance must not execute or allow replay' );
ensure( $ready['authority_scope'] === 'write_only'
    && $ready['production_mutation_allowed'] === false && $ready['developer_included'] === false
    && $ready['breakglass_included'] === false, 'Higher authority leaked' );
ensure( $ready['prepared_request']['operation_id'] === 'staging_write_authority_convergence'
    && $ready['method'] === 'mad4b/enrollment-execute', 'Expected guided Enrollment routing' );
ensure( $ready['prepared_request']['input'] === $input
    && $ready['prepared_request']['expected_registration_digest'] === $row['registration_digest']
    && $ready['prepared_request']['expected_dispatch_policy_digest'] === $row['dispatch_policy_digest']
    && $ready['prepared_request']['expected_input_schema_sha256'] === $row['input_schema_sha256'],
    'Exact pinned source/dispatch inputs were not retained' );
$cases = array(
    array( 'name' => 'current', 'handshake' => array_replace( $handshake,
        array( 'already_current' => true ) ), 'prior' => $prior, 'latest' => $latest,
        'state' => 'already_current' ),
    array( 'name' => 'current with uncertain previous execution', 'handshake' => array_replace( $handshake,
        array( 'already_current' => true ) ), 'prior' => $prior,
        'latest' => array( 'state' => 'EXECUTION_UNCERTAIN' ),
        'state' => 'prior_operation_requires_reconciliation' ),
    array( 'name' => 'current with held finalization lock', 'handshake' => array_replace( $handshake,
        array( 'already_current' => true ) ), 'prior' => $prior,
        'latest' => array( 'state' => 'VERIFIED_LOCK_HELD' ),
        'state' => 'prior_operation_requires_reconciliation' ),
    array( 'name' => 'current with unreadable journal', 'handshake' => array_replace( $handshake,
        array( 'already_current' => true ) ), 'prior' => $prior, 'latest' => null,
        'state' => 'prior_operation_requires_reconciliation' ),
    array( 'name' => 'current with verified previous execution', 'handshake' => array_replace( $handshake,
        array( 'already_current' => true ) ), 'prior' => $prior,
        'latest' => array( 'state' => 'VERIFIED' ), 'state' => 'already_current' ),
    array( 'name' => 'blocked', 'handshake' => array_replace( $handshake,
        array( 'hard_blockers' => array( 'site_profile_drift' ) ) ),
        'prior' => $prior, 'latest' => $latest, 'state' => 'prerequisites_blocked' ),
    array( 'name' => 'outcome uncertain', 'handshake' => $handshake,
        'prior' => array( 'state' => 'EXECUTION_UNCERTAIN' ), 'latest' => $latest,
        'state' => 'prior_operation_requires_reconciliation' ),
    array( 'name' => 'site lock', 'handshake' => $handshake,
        'prior' => $prior, 'latest' => array( 'state' => 'RECOVERY_REQUIRED' ),
        'state' => 'prior_operation_requires_reconciliation' ),
    array( 'name' => 'missing handshake', 'handshake' => null,
        'prior' => $prior, 'latest' => $latest, 'state' => 'handshake_unavailable' ),
    array( 'name' => 'stale sha', 'handshake' => array_replace( $handshake, array(
        'exact_apply' => array_replace( $input, array( 'expected_source_commit_sha' => 'stale' ) ) ) ),
        'prior' => $prior, 'latest' => $latest, 'state' => 'invalid_plan_evidence' ),
    array( 'name' => 'no review', 'handshake' => array_replace( $handshake, array(
        'exact_apply' => array_diff_key( $input, array( 'owner_review_digest' => true ) ) ) ),
        'prior' => $prior, 'latest' => $latest, 'state' => 'invalid_plan_evidence' ),
    array( 'name' => 'rejected confirmation', 'handshake' => array_replace( $handshake, array(
        'exact_apply' => array_replace( $input, array( 'confirmation' => 'APPROVE ALL' ) ) ) ),
        'prior' => $prior, 'latest' => $latest, 'state' => 'invalid_plan_evidence' ),
);
foreach ( $cases as $case ) {
    $out = $h( $row, $case['handshake'], $case['prior'], $case['latest'] );
    ensure( $out['state'] === $case['state'], 'Fail-closed state mismatch: ' . $case['name'] );
    ensure( ! isset( $out['prepared_request'] ) && $out['owner_decision_required'] === false
        && $out['mutation_performed'] === false && $out['blind_retry_allowed'] === false,
        'Blocked or current state leaked an executable request: ' . $case['name'] );
}
foreach ( array(
    array_replace( $row, array( 'production_policy' => 'allow' ) ),
    array_replace( $row, array( 'remote_caller_role' => 'operator' ) ),
    array_replace( $row, array( 'human_decision_required' => false ) ),
    array_replace( $row, array( 'remote_ability' => 'mad4b/full-staging-authority-apply' ) ),
    array_replace( $row, array( 'input_schema_sha256' => 'stale' ) ),
    array_replace( $row, array( 'execution_eligible' => false ) ),
) as $other_row ) {
    $out = $h( $other_row, $handshake, $prior, $latest );
    ensure( ! isset( $out['prepared_request'] ) && $out['owner_decision_required'] === false,
        'Unreviewed route generated an executable request' );
}
// Exercise the actual discovery path too: current authority still inspects
// the durable journal, without a second expensive plan calculation.
function is_wp_error( $value ) { return false; }
class MAD4B_SCP_Staging_Write_Authority_Convergence {
    public static $handshake; public static $plan_calls = 0;
    public static function handshake() { return self::$handshake; }
    public static function plan() { ++self::$plan_calls; throw new RuntimeException( 'Unexpected duplicate plan' ); }
}
class MAD4B_SCP_Staging_Operation_Journal {
    public static $site;
    public static function latest( $site ) { self::$site = $site; return array( 'state' => 'EXECUTION_UNCERTAIN' ); }
}
MAD4B_SCP_Staging_Write_Authority_Convergence::$handshake = array_replace(
    $handshake, array( 'already_current' => true, 'ready_to_apply' => false ) );
$live_path = new ReflectionMethod( 'MAD4B_SCP_Enrollment_Dispatch', 'guided_write_handoff' );
$live_path->setAccessible( true );
$out = $live_path->invoke( null, $row );
ensure( $out['state'] === 'prior_operation_requires_reconciliation'
    && $out['authority_current'] === true && ! isset( $out['prepared_request'] ),
    'Current authority hid an unresolved operation in the real guided path' );
ensure( MAD4B_SCP_Staging_Operation_Journal::$site === $site_uuid
    && MAD4B_SCP_Staging_Write_Authority_Convergence::$plan_calls === 0,
    'Journal observation lost exact site binding or duplicated the plan' );
echo "MAD4B_ENROLLMENT_GUIDED_WRITE_HANDOFF: PASS\n";
