<?php
/* QA32C hermetic admission policy, no WordPress network, writes or remote auth. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-orphan-source-recovery.php';
function verify_qa32c( $ok, $what ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $what . PHP_EOL ); exit( 1 ); }
}
$class = 'MAD4B_SCP_Orphan_Source_Recovery';
$valid = array(
    'environment' => 'staging',
    'wordpress_environment_explicit' => true,
    'profile_authority_ready' => true,
    'profile_binding_state' => 'exact',
    'origin_match' => true,
    'deployment_binding_match' => true,
    'site_uuid' => '49c562d1-8f2f-456f-b454-26816c6ba4cb',
    'site_origin' => 'https://staging.example.test',
    'installed_runtime_manifest_match' => true,
    'lineage' => 'installed_source_unresolvable',
    'installed_sha' => str_repeat( 'a', 40 ),
    'installed_version' => '0.4.0-rc.104',
    'installed_build_fingerprint' => str_repeat( 'b', 64 ),
    'installed_manifest_digest' => str_repeat( 'c', 64 ),
    'target_sha' => str_repeat( 'd', 40 ),
    'target_version' => '0.4.0-rc.104',
    'target_archive_sha256' => str_repeat( 'e', 64 ),
    'target_build_fingerprint' => str_repeat( 'f', 64 ),
    'target_manifest_digest' => str_repeat( '0', 64 ),
    'release_verdict_success' => true,
    'release_root_trust_verified' => true,
    'published_from_master' => true,
);
$test = $class::evaluate( $valid );
verify_qa32c( $test['preflight_ready'] === true, 'exact intact orphan must be plan-eligible' );
verify_qa32c( $test['execution_allowed'] === false && $test['auto_update_allowed'] === false, 'preflight is not replacement authority' );
verify_qa32c( $test['same_site_one_use_approval_required'] === true && $test['owner_oauth_step_up_required'] === true, 'owner step-up and one-use approval required' );
$cases = array(
    array( 'environment', 'production', 'exact_staging_required' ),
    array( 'environment', 'local', 'exact_staging_required' ),
    array( 'wordpress_environment_explicit', false, 'explicit_wordpress_environment_required' ),
    array( 'profile_binding_state', 'unconfigured', 'exact_enrolled_site_identity_required' ),
    array( 'origin_match', false, 'exact_enrolled_site_identity_required' ),
    array( 'deployment_binding_match', false, 'exact_enrolled_site_identity_required' ),
    array( 'site_origin', 'http://staging.example.test', 'canonical_https_origin_required' ),
    array( 'installed_runtime_manifest_match', false, 'installed_package_integrity_unverified' ),
    array( 'lineage', 'unknown', 'orphan_lineage_not_independently_proven' ),
    array( 'lineage', 'target_ahead', 'orphan_lineage_not_independently_proven' ),
    array( 'lineage', 'diverged', 'orphan_lineage_not_independently_proven' ),
    array( 'installed_sha', 'deadbeef', 'installed_sha_invalid' ),
    array( 'target_archive_sha256', 'bad', 'target_archive_sha256_invalid' ),
    array( 'release_verdict_success', false, 'immutable_release_authority_missing' ),
    array( 'release_root_trust_verified', false, 'immutable_release_authority_missing' ),
    array( 'published_from_master', false, 'immutable_release_authority_missing' ),
    array( 'target_version', '0.4.0-rc.101', 'release_version_behind_or_unknown' ),
);
foreach ( $cases as $case ) {
    $signals = $valid;
    $signals[ $case[0] ] = $case[1];
    $result = $class::evaluate( $signals );
    verify_qa32c( $result['preflight_ready'] === false
        && in_array( $case[2], $result['blockers'], true ), 'reject ' . $case[0] );
    verify_qa32c( $result['execution_allowed'] === false, 'never apply ' . $case[0] );
}
$signals = $valid;
$signals['target_sha'] = $signals['installed_sha'];
verify_qa32c( in_array( 'already_on_target', $class::evaluate( $signals )['blockers'], true ), 'same SHA cannot become orphan recovery' );
verify_qa32c( ! method_exists( $class, 'apply' ), 'no effectful orphan apply is registered' );
echo 'QA32C_ORPHAN_SOURCE_RECOVERY_ADMISSION: PASS ' . ( count( $cases ) + 4 ) . ' assertions' . PHP_EOL;
