<?php
/** Exact cross-environment read-only guidance regression; no WordPress, DB or network. */
if ( PHP_SAPI !== 'cli' ) exit( 2 );
define( 'ABSPATH', '/hermetic/' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-environment-journey.php';
function check( $ok, $reason ) { if ( ! $ok ) { fwrite( STDERR, $reason . "\n" ); exit( 1 ); } }
$valid = array( 'configured'=>true, 'enrolled'=>true, 'origin_match'=>true,
    'site_uuid'=>'d745d81f-6fc4-5c6a-99dd-d953c92137bf' );
$kinds = MAD4B_SCP_Environment_Journey::KINDS;
check( count( $kinds ) === 7, 'Expected all seven operation families' );
$cases = 0;
foreach ( array( 'local', 'development', 'staging', 'production' ) as $environment ) {
    $resolver = array( 'effective_environment'=>$environment,
        'wordpress_profile_mismatch'=>false, 'profile_environment_authoritative'=>true );
    foreach ( $kinds as $kind ) {
        $out = MAD4B_SCP_Environment_Journey::model( $resolver, $valid, $kind, true );
        check( $out['contract'] === MAD4B_SCP_Environment_Journey::CONTRACT, 'Contract mismatch' );
        check( $out['environment'] === $environment && $out['operation_kind'] === $kind,
            'Environment or operation switched' );
        check( $out['read_only'] === true && $out['mutation_performed'] === false
            && $out['automatic_execution'] === false && $out['authority_granted'] === false
            && $out['production_mutation_allowed'] === false && $out['developer_included'] === false
            && $out['breakglass_included'] === false && $out['blind_retry_allowed'] === false,
            'Unauthorized execution or elevation in ' . $environment . '/' . $kind );
        check( $out['owner_decision_recorded'] === false && $out['prepared_request'] === null,
            'An ordinary environment summary may not fabricate owner consent or a write request' );
        if ( $environment === 'production' && $kind !== 'diagnostics'
            && $kind !== 'derived_state' && $kind !== 'operational_repair' ) {
            check( $out['state'] === 'exact_batch_scope_check_required'
                && $out['approval_policy'] === 'production_exact_owner_batch_and_signed_test_certificate',
                'Production must retain exact owner batch + signed test gates' );
        }
        if ( $environment === 'staging' && $kind === 'write_authority' )
            check( $out['state'] === 'staging_owner_review_path'
                && $out['next_read_ability'] === 'mad4b/enrollment-discover',
                'Staging owner path must route through Enrollment, not Write' );
        if ( in_array( $environment, array( 'local', 'development' ), true ) && $kind === 'write_authority' )
            check( $out['state'] === 'nonstaging_authority_policy_required',
                'Local/development cannot borrow Staging write approval' );
        $cases++;
    }
    $resolver['wordpress_profile_mismatch'] = true;
    $resolver['profile_environment_authoritative'] = false;
    $blocked = MAD4B_SCP_Environment_Journey::model( $resolver, $valid, 'write_authority' );
    check( $blocked['state'] === 'blocked' && $blocked['prepared_request'] === null,
        'WordPress/Profile mismatch must refuse permission hints' );
    $no_origin = array_replace( $valid, array( 'origin_match'=>false ) );
    $blocked = MAD4B_SCP_Environment_Journey::model( $resolver, $no_origin, 'diagnostics' );
    check( $blocked['state'] === 'blocked', 'Invalid site origin cannot be authorized' );
}
$staging = array( 'effective_environment'=>'staging', 'wordpress_profile_mismatch'=>false );
$guided = array(
    'state'=>'ready_for_exact_owner_decision', 'owner_decision_required'=>true,
    'next_action'=>'present_exact_site_owner_consent_then_step_up_once',
    'prepared_request'=>array(
        'operation_id'=>'staging_write_authority_convergence',
        'expected_registration_digest'=>str_repeat( '1', 64 ),
        'expected_dispatch_policy_digest'=>str_repeat( '2', 64 ),
        'expected_input_schema_sha256'=>str_repeat( '3', 64 ),
        'input'=>array(
            'expected_site_uuid'=>$valid['site_uuid'],
            'expected_plan_sha256'=>str_repeat( 'a', 64 ),
            'expected_profile_digest'=>str_repeat( 'b', 64 ),
            'owner_review_digest'=>str_repeat( 'c', 64 ),
            'expected_source_commit_sha'=>str_repeat( 'd', 40 ),
            'confirmation'=>'ENABLE GOVERNED STAGING WRITE AUTHORITY',
        ),
    ),
);
$out = MAD4B_SCP_Environment_Journey::model( $staging, $valid, 'write_authority', false, $guided );
check( $out['state'] === 'ready_for_exact_owner_decision'
    && $out['prepared_request'] === $guided['prepared_request']
    && $out['automatic_execution'] === false && $out['owner_decision_recorded'] === false,
    'Prepared Staging proof may be presented but never auto-applied' );
$invalid_guided = $guided;
$invalid_guided['prepared_request']['input']['expected_site_uuid']
    = '8de35ca2-fb87-4a28-bc9c-33593d92fdad';
$out = MAD4B_SCP_Environment_Journey::model( $staging, $valid, 'write_authority', false, $invalid_guided );
check( $out['state'] === 'invalid_staging_handoff' && $out['prepared_request'] === null,
    'Wrong-site signed plan must never be proposed to this site's owner' );
$invalid_guided = $guided;
$invalid_guided['prepared_request']['expected_dispatch_policy_digest'] = 'invalid';
$out = MAD4B_SCP_Environment_Journey::model( $staging, $valid, 'write_authority', false, $invalid_guided );
check( $out['state'] === 'invalid_staging_handoff' && $out['prepared_request'] === null,
    'Tampered broker dispatch policy must not be proposed' );
$invalid_guided = $guided;
$invalid_guided['prepared_request']['input']['confirmation'] = 'APPROVE ANY ENVIRONMENT';
$out = MAD4B_SCP_Environment_Journey::model( $staging, $valid, 'write_authority', false, $invalid_guided );
check( $out['state'] === 'invalid_staging_handoff' && $out['prepared_request'] === null,
    'Altered owner scope must not be proposed' );
foreach ( array( 'prior_operation_requires_reconciliation', 'already_current',
    'prerequisites_blocked' ) as $state ) {
    $guided = array( 'state'=>$state, 'owner_decision_required'=>false,
        'prepared_request'=>array( 'operation_id'=>'staging_write_authority_convergence' ) );
    $out = MAD4B_SCP_Environment_Journey::model( $staging, $valid, 'write_authority', false, $guided );
    check( $out['prepared_request'] === null && $out['blind_retry_allowed'] === false,
        'Blocked/current Staging plan leaked executable request' );
}
foreach ( array( 'production', 'local', 'development' ) as $env ) {
    $out = MAD4B_SCP_Environment_Journey::model( array( 'effective_environment'=>$env ),
        $valid, 'write_authority', true, array( 'state'=>'ready_for_exact_owner_decision',
        'owner_decision_required'=>true, 'prepared_request'=>array( 'operation_id'=>'staging_write_authority_convergence' ) ) );
    check( $out['prepared_request'] === null, 'Staging consent leaked across environments' );
}
foreach ( array( '', 'unknown', 'production-preview', 'test' ) as $env ) {
    $out = MAD4B_SCP_Environment_Journey::model( array( 'effective_environment'=>$env ), $valid, 'diagnostics' );
    check( $out['state'] === 'blocked', 'Unknown environment accepted' );
}
$out = MAD4B_SCP_Environment_Journey::model( array( 'effective_environment'=>'production' ),
    $valid, 'plugin_update', false );
check( $out['state'] === 'production_owner_batch_required',
    'Missing Production owner batch must remain blocked' );
$out = MAD4B_SCP_Environment_Journey::model( array( 'effective_environment'=>'staging' ),
    $valid, 'global_root_shell', false );
check( $out['state'] === 'blocked', 'Unknown operation family accepted' );
echo "MAD4B_ENVIRONMENT_JOURNEY: PASS " . $cases . " environment / operation combinations\n";
