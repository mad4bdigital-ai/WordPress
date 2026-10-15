<?php
/**
 * Pure WordPress-native selected-HEAD admission fixture.
 * No WordPress bootstrap, filesystem or GitHub network activity.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-wordpress-native-opt-in.php';
$ready = array(
    'configured_environment' => 'staging', 'environment' => 'staging',
    'configured' => true, 'authority_ready' => true, 'origin_match' => true,
    'environment_match' => true, 'profile_environment_authoritative' => true,
    'site_uuid' => 'b072872b-a695-4d09-b13b-e6d498c18171',
    'profile_digest' => str_repeat( 'a', 64 ), 'canonical_origin' => 'https://example.test',
    'current_origin' => 'https://example.test',
    'wordpress_environment' => 'staging', 'wordpress_environment_explicit' => true,
    'implicit_nonproduction_override_confirmed' => true, 'mutation_pending_audit' => false,
);
$cases = array(
    array( $ready, true, '' ),
    array( array_merge( $ready, array( 'wordpress_environment' => 'production' ) ), false, 'explicit_nonstaging_host_denied' ),
    array( array_merge( $ready, array( 'wordpress_environment' => 'production', 'wordpress_environment_explicit' => false ) ), true, '' ),
    array( array_merge( $ready, array( 'wordpress_environment' => 'production', 'wordpress_environment_explicit' => false, 'implicit_nonproduction_override_confirmed' => false ) ), false, 'staging_attestation_required' ),
    array( array_merge( $ready, array( 'environment' => 'production' ) ), false, 'exact_staging_profile_required' ),
    array( array_merge( $ready, array( 'configured_environment' => 'production' ) ), false, 'exact_staging_profile_required' ),
    array( array_merge( $ready, array( 'origin_match' => false ) ), false, 'exact_site_profile_identity_required' ),
    array( array_merge( $ready, array( 'current_origin' => 'https://foreign.example' ) ), false, 'exact_site_profile_identity_required' ),
    array( array_merge( $ready, array( 'authority_ready' => false ) ), false, 'exact_site_profile_identity_required' ),
    array( array_merge( $ready, array( 'mutation_pending_audit' => true ) ), false, 'exact_site_profile_identity_required' ),
    array( array_merge( $ready, array( 'reenrollment_required' => true ) ), false, 'exact_site_profile_identity_required' ),
    array( array_merge( $ready, array( 'foreign_profile_detected' => true ) ), false, 'exact_site_profile_identity_required' ),
);
foreach ( $cases as $index => $case ) {
    $result = MAD4B_SCP_WordPress_Native_Opt_In::site_policy( $case[0] );
    if ( $result['eligible'] !== $case[1] || ! empty( $result['host_runner_required'] ) ||
        ! empty( $result['production_allowed'] ) ||
        ( $case[2] && ! in_array( $case[2], $result['blockers'], true ) ) ) {
        fwrite( STDERR, "WordPress-native admission fixture failure: $index\n" ); exit( 1 );
    }
}
echo "PASS: 12 WordPress-native Staging admission/denial cases\n";
