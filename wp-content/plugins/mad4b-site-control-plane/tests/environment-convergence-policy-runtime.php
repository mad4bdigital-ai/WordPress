<?php
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $s ) ) ); }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-environment-convergence-policy.php';
$cases = array(
 array( 'staging', 'staging', true, true, true, true, true ),
 array( 'local', 'local', true, true, true, true, true ),
 array( 'development', 'development', true, true, true, true, true ),
 array( 'production', 'production', true, true, true, true, false ),
 array( 'production', 'staging', false, true, true, true, false ),
 array( 'staging', 'staging', true, false, true, true, false ),
 array( 'staging', 'staging', true, true, false, true, false ),
 array( 'staging', 'staging', true, true, true, false, false ),
 array( 'staging', 'staging', false, true, true, true, false ),
);
foreach ( $cases as $case ) {
 $r = MAD4B_SCP_Environment_Convergence_Policy::resolve(
    array( 'wordpress_environment'=>$case[0], 'effective_environment'=>$case[1],
      'profile_environment_authoritative'=>$case[2], 'wordpress_profile_mismatch'=>$case[0]!==$case[1] ),
    $case[3],$case[4],$case[5]
 );
 if ( $r['auto_convergence_eligible'] !== $case[6] || $r['automatic_grant_mutation_authorized'] !== false
    || $r['mutation_performed'] !== false || ( $r['production_strict'] === $case[6] ) ) {
    throw new RuntimeException( 'Policy case failed: ' . json_encode( $case ) );
 }
}
echo "ENVIRONMENT_CONVERGENCE_POLICY: PASS (9 cases)\n";
