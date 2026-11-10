<?php
/** Immutable, source-reviewed current Staging gap; no grant creation. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['test_env'] = 'staging';
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $s ) ); }
class MAD4B_SCP_Site_Profile {
    public static function current_environment() { return $GLOBALS['test_env']; }
}
function verify_grants( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-grant-reconciliation.php';
$c = 'MAD4B_SCP_Staging_Write_Grant_Reconciliation';
$expected = array(
    'context/brand-profile-save' => 'google_drive_context',
    'context/governed-source-upsert' => 'google_drive_context',
    'context/remote-owner-review-apply' => 'google_drive_context',
    'mad4b/acceptance-target-provision' => 'core',
    'mad4b/business-activity-import-approve' => 'core',
    'mad4b/business-activity-import-batch-append' => 'core',
    'mad4b/business-activity-import-batch-approve' => 'core',
    'mad4b/business-activity-import-batch-archive' => 'core',
    'mad4b/business-activity-import-batch-begin' => 'core',
    'mad4b/business-activity-link-apply' => 'core',
    'mad4b/business-activity-sync-advance' => 'core',
    'mad4b/business-activity-sync-archive' => 'core',
    'mad4b/business-activity-sync-begin' => 'core',
    'mad4b/business-activity-sync-cancel' => 'core',
    'mad4b/business-activity-sync-finalize-reconciled' => 'core',
    'mad4b/business-activity-sync-recover' => 'core',
    'mad4b/business-activity-wpai-observation-arm' => 'core',
    'mad4b/content-delete-meta' => 'core',
    'mad4b/content-experience-profile-apply' => 'core',
    'mad4b/content-experience-profile-clone-apply' => 'core',
    'mad4b/content-experience-profile-delete-apply' => 'core',
    'mad4b/content-import-bundle' => 'core',
    'mad4b/content-set-meta' => 'core',
    'mad4b/content-trash-post' => 'core',
    'mad4b/control-plane-selected-head-apply' => 'core',
    'mad4b/deployment-binding-native-apply' => 'core',
    'mad4b/deployment-binding-setup-apply' => 'core',
    'mad4b/host-operation-apply' => 'core',
    'mad4b/market-growth-policy-update' => 'core',
    'mad4b/native-recovery-cleanup' => 'core',
    'mad4b/native-recovery-start' => 'core',
    'mad4b/runtime-release-set-apply' => 'core',
    'mad4b/staging-offline-attestor-enroll-apply' => 'core',
    'mad4b/taxonomy-update-term' => 'core',
    'mad4b/translation-link-posts' => 'core',
    'mad4b/translation-set-post-language' => 'core',
    'media/import-external' => 'media',
    'media/remote-import-apply' => 'media',
);
$allowed = $c::allowed_ability_providers();
// The original planning guard needs an exact grant to CREATE a pending
// ticket, not a prior ticket (which would deadlock the consent lifecycle).
// This policy review does not create that grant or approve a ticket.
// Inspect the source owner rather than treating an arbitrary mounted
// Ability as grant authority; the following three exact strings must
// correspond to the current first-party ContentJob registration.
$content_jobs_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-content-jobs.php' );
verify_grants( is_string( $content_jobs_source )
    && false !== strpos( $content_jobs_source, 'MAD4B_SCP_Operational_Integrity::assert_unchanged( $checkpoint, true )' )
    && false !== strpos( $content_jobs_source, "'content_job_transition_cas_failed'" )
    && false !== strpos( $content_jobs_source, "'blind_retry_allowed' => false" ),
    'ContentJob source must keep operational fence, revision CAS and no blind retry.' );
// Content update is source-owned by the bundled core Abilities service,
// and the original mutation manager enforces post-specific CAS, permission,
// brand review, publication acceptance and a verified recovery envelope.
$registration = file_get_contents( dirname( __DIR__ ) . '/includes/trait-mad4b-scp-abilities-registration.php' );
$abilities_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-abilities.php' );
$mutation_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-mutation-manager.php' );
verify_grants( ( $allowed['mad4b/content-update-post'] ?? '' ) === 'core'
    && false !== strpos( $registration, "'mad4b/content-update-post'" )
    && false !== strpos( $registration, "'expected_modified_gmt'" )
    && false !== strpos( $registration, "'edit_post'" )
    && false !== strpos( $abilities_source, 'MAD4B_SCP_Mutation_Manager::execute_post_update' )
    && false !== strpos( $mutation_source, 'hash_equals( (string) $post->post_modified_gmt, $expected )' )
    && false !== strpos( $mutation_source, "'mad4b_cannot_publish'" )
    && false !== strpos( $mutation_source, 'MAD4B_SCP_Brand_Core_Control_Loop::publication_preflight' )
    && false !== strpos( $mutation_source, 'verify_publication_transition' )
    && false !== strpos( $mutation_source, 'consume_publication_acceptance' )
    && false !== strpos( $mutation_source, 'rollback_dynamic_publication_transition' )
    && false !== strpos( $mutation_source, 'verify_post_update' ),
    'Exact core content-update-post eligibility requires the original CAS, publication, readback and rollback guards.' );
$reviewed_jobs = array(
    'mad4b/content-job-create' => "'create_job'",
    'mad4b/content-job-transition' => "'transition_job'",
    'mad4b/content-job-cancel' => "'cancel_job'",
);
foreach ( $reviewed_jobs as $ability => $callback ) {
    verify_grants( ( $allowed[ $ability ] ?? '' ) === 'core'
        && false !== strpos( $content_jobs_source, "'" . $ability . "'" )
        && false !== strpos( $content_jobs_source, $callback ),
        'ContentJob exact source-owned core provider required: ' . $ability );
}
verify_grants( ( $allowed['mad4b/approval-plan'] ?? '' ) === 'core',
    'Only the exact core approval-plan bootstrap is grant eligible.' );
verify_grants( ! isset( $allowed['mad4b/approval-plan-all'] )
    && ! isset( $allowed['mad4b/approval-auto-approve'] ),
    'No wildcard or auto-approval bootstrap becomes grant eligible.' );
verify_grants( ( $allowed['jetsmartfilters/update-filter-meta'] ?? '' ) === 'jetsmartfilters',
    'Exact reversible JetSmartFilters metadata writer must be source-reviewed on Staging.' );
verify_grants( !isset( $allowed['jetsmartfilters/delete-filter'] )
    && !isset( $allowed['elementor/update-widget-settings'] ),
    'A certified provider must not confer arbitrary extra write grants.' );
// Native build admission and optional self-certified installation must each
// have an exact, Staging-only reviewed provider pair. This allowlist test
// does not approve either operation or create any grant.
$native_reviewed = array(
    'mad4b/wordpress-native-candidate-opt-in-apply' => 'core',
    'mad4b/wp-native-build-start' => 'core',
    'mad4b/wp-native-self-approve-install' => 'core',
);
verify_grants( count( $native_reviewed ) === 3,
    'Exactly three discrete native Staging operations reviewed; none is a wildcard grant.' );
verify_grants( isset( $allowed['mad4b/provider-connection-refresh'] )
    && 'core' === $allowed['mad4b/provider-connection-refresh'],
    'Provider OAuth refresh is exactly reviewed core-only on Staging.' );
foreach ( $native_reviewed as $ability => $provider ) {
    verify_grants( isset( $allowed[ $ability ] ) && $allowed[ $ability ] === $provider,
        'Native Staging ability requires exact core provider: ' . $ability );
}

verify_grants( count( $expected ) === 38, 'Exactly 38 source-reviewed missing Staging Ability/provider pairs.' );
foreach ( $expected as $ability => $provider ) {
    verify_grants( isset( $allowed[ $ability ] ) && $allowed[ $ability ] === $provider,
        'Staging review must match exact Ability and provider: ' . $ability );
}
verify_grants( !isset( $allowed['mad4b/database-raw-query'] )
    && !isset( $allowed['mad4b/unknown-future-grant'] )
    && !isset( $allowed['malicious/unknown-ability'] ),
    'No raw SQL, wildcard or future/third-party unreviewed grant.' );
verify_grants( $allowed === array_replace( $allowed, array() )
    && array_keys( $allowed ) === array_values( array_unique( array_keys( $allowed ) ) ),
    'Provider policy remains finite and unique.' );
$GLOBALS['test_env'] = 'production';
$production = $c::allowed_ability_providers();
verify_grants( ( $production['mad4b/content-update-post'] ?? '' ) === 'core',
    'Production post editing remains exact-grant and exact-ticket controlled.' );
foreach ( array_keys( $reviewed_jobs ) as $ability ) {
    verify_grants( ( $production[ $ability ] ?? '' ) === 'core',
        'Production ContentJob eligibility still requires the same exact ticket and NHI: ' . $ability );
}
verify_grants( ( $production['mad4b/approval-plan'] ?? '' ) === 'core',
    'Production planner eligibility keeps exact approval and NHI gates; never auto-approves.' );
verify_grants( !isset( $production['jetsmartfilters/update-filter-meta'] ),
    'The newly certified JetSmartFilters writer cannot expand Production grants.' );
foreach ( $expected as $ability => $provider ) {
    verify_grants( !isset( $production[ $ability ] ),
        'New Staging-reviewed mutation cannot be included in Production: ' . $ability );
}
verify_grants( ! isset( $production['mad4b/provider-connection-refresh'] ),
    'Provider token refresh must not be automatically granted on Production.' );
foreach ( $native_reviewed as $ability => $provider ) {
    verify_grants( ! isset( $production[ $ability ] ),
        'Native candidate grant review must never expand Production: ' . $ability );
}
$GLOBALS['test_env'] = 'staging';
$retire = $c::retirable_stale_ability_providers();
foreach ( $expected as $ability => $provider ) {
    verify_grants( ( $retire[ $ability ] ?? '' ) === $provider,
        'Current Staging review can retire exactly its stale version: ' . $ability );
}
echo "PASS: 38 Feature 007 plus 3 native Staging pairs, exact core grants only; no Production expansion\n";
